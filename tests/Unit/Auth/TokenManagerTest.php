<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Auth;

use AsterMD\Sdk\Auth\InMemoryTokenStore;
use AsterMD\Sdk\Auth\Token;
use AsterMD\Sdk\Auth\TokenManager;
use AsterMD\Sdk\Config;
use AsterMD\Sdk\Exception\AuthenticationException;
use AsterMD\Sdk\Http\UrlBuilder;
use AsterMD\Sdk\Tests\Support\MockHttpClient;
use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class TokenManagerTest extends TestCase
{
    private MockHttpClient $http;
    private TokenManager $manager;
    private InMemoryTokenStore $store;

    protected function setUp(): void
    {
        $factory = new Psr17Factory();
        $this->http = new MockHttpClient();
        $this->store = new InMemoryTokenStore();

        $this->manager = new TokenManager(
            config: new Config('client-id', 'secret'),
            httpClient: $this->http,
            requestFactory: $factory,
            streamFactory: $factory,
            urlBuilder: new UrlBuilder('api.astermd.com'),
            store: $this->store,
        );
    }

    public function testAcquiresTokenLazilyOnFirstCall(): void
    {
        $expiry = new DateTimeImmutable('+1 hour')->format('Y-m-d\TH:i:s\Z');
        $this->http->enqueue(200, json_encode([
            'success' => true,
            'message' => 'ok',
            'data' => ['access_token' => 'jwt-1', 'access_token_expiry' => $expiry],
            'meta' => [],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('jwt-1', $this->manager->bearerToken());

        $req = $this->http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame(
            'https://api.astermd.com/v1/auth/api-credentials/token',
            (string) $req->getUri(),
        );
        self::assertJsonStringEqualsJsonString(
            '{"client_id":"client-id","client_secret":"secret"}',
            (string) $req->getBody(),
        );
    }

    public function testReusesCachedTokenAcrossCalls(): void
    {
        $expiry = new DateTimeImmutable('+1 hour')->format('Y-m-d\TH:i:s\Z');
        $this->http->enqueue(200, $this->envelope('jwt-cached', $expiry));

        $this->manager->bearerToken();
        $this->manager->bearerToken();

        self::assertCount(1, $this->http->requests, 'only one token call expected');
    }

    public function testReAcquiresWhenStoredTokenIsExpired(): void
    {
        $this->store->put(new Token('stale', new DateTimeImmutable()->modify('-1 minute')));

        $expiry = new DateTimeImmutable('+1 hour')->format('Y-m-d\TH:i:s\Z');
        $this->http->enqueue(200, $this->envelope('jwt-fresh', $expiry));

        self::assertSame('jwt-fresh', $this->manager->bearerToken());
    }

    public function testRefreshClearsCachedTokenAndReAcquires(): void
    {
        $expiry = new DateTimeImmutable('+1 hour')->format('Y-m-d\TH:i:s\Z');
        $this->http->enqueue(200, $this->envelope('jwt-1', $expiry));
        $this->http->enqueue(200, $this->envelope('jwt-2', $expiry));

        self::assertSame('jwt-1', $this->manager->bearerToken());
        self::assertTrue($this->manager->refresh());
        self::assertSame('jwt-2', $this->manager->bearerToken());
    }

    public function testThrowsAuthenticationExceptionOnTokenEndpoint401(): void
    {
        $this->http->enqueue(401, '{"success":false,"message":"bad creds"}');

        $this->expectException(AuthenticationException::class);
        $this->manager->bearerToken();
    }

    private function envelope(string $token, string $expiry): string
    {
        return json_encode([
            'success' => true,
            'message' => 'ok',
            'data' => ['access_token' => $token, 'access_token_expiry' => $expiry],
            'meta' => [],
        ], JSON_THROW_ON_ERROR);
    }
}
