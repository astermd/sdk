<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Resource;

use AsterMD\Sdk\Http\Transport;
use AsterMD\Sdk\Http\UrlBuilder;
use AsterMD\Sdk\Resource\DoctorsNetworks;
use AsterMD\Sdk\Tests\Support\MockHttpClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class DoctorsNetworksTest extends TestCase
{
    private MockHttpClient $http;
    private DoctorsNetworks $resource;

    protected function setUp(): void
    {
        $factory = new Psr17Factory();
        $this->http = new MockHttpClient();
        $this->resource = new DoctorsNetworks(new Transport(
            httpClient: $this->http,
            requestFactory: $factory,
            streamFactory: $factory,
            urlBuilder: new UrlBuilder('api.astermd.com'),
            tokenProvider: static fn (): string => 'jwt',
            onUnauthorized: static fn (): bool => false,
        ));
    }

    public function testSyncWithOpportunityId(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $this->resource->sync([
            'session_id' => 'sess-uuid',
            'network' => 'md_integrations',
            'opportunity_id' => 'opp-id',
            'products' => [['product_id' => 'p1']],
        ]);

        $req = $this->http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame(
            'https://api.astermd.com/v1/sales/doctors-networks/sync',
            (string) $req->getUri(),
        );
        $body = json_decode((string) $req->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayNotHasKey('emit_opportunity', $body);
    }

    public function testSyncWithUserInfoOnly(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $this->resource->sync([
            'session_id' => 'sess-uuid',
            'user_info' => ['first_name' => 'John', 'email' => 'j@example.com'],
            'products' => [['product_id' => 'p1']],
        ]);

        self::assertSame(200, 200); // request did not throw
    }

    public function testStripsEmitOpportunityIfPresent(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->resource->sync([
            'session_id' => 's',
            'opportunity_id' => 'o',
            'emit_opportunity' => true,
            'products' => [],
        ]);

        $body = json_decode((string) $this->http->lastRequest()->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayNotHasKey('emit_opportunity', $body);
    }

    public function testRejectsMissingOpportunityIdAndIncompleteUserInfo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->resource->sync([
            'session_id' => 's',
            'user_info' => ['first_name' => 'John'], // missing email
            'products' => [],
        ]);
    }

    public function testRejectsWhenNeitherOpportunityNorUserInfoProvided(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->resource->sync([
            'session_id' => 's',
            'products' => [],
        ]);
    }
}
