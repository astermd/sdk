<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit;

use AsterMD\Sdk\AsterMDClient;
use AsterMD\Sdk\Tests\Support\MockHttpClient;
use PHPUnit\Framework\TestCase;

final class AsterMDClientTest extends TestCase
{
    public function testConstructsWithDefaultsAndExposesConfigHost(): void
    {
        $client = new AsterMDClient(clientId: 'id', clientSecret: 'sec');
        self::assertSame('api.astermd.com', $client->config()->baseHost());
    }

    public function testAcceptsCustomHostAndInjectedHttpClient(): void
    {
        $http = new MockHttpClient();
        $client = new AsterMDClient(
            clientId: 'id',
            clientSecret: 'sec',
            baseHost: 'api.astermd.com',
            httpClient: $http,
        );

        self::assertSame('api.astermd.com', $client->config()->baseHost());
    }

    public function testDebugRequiresFileOrSink(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('debug mode requires debugFile or debugSink.');

        new AsterMDClient(
            clientId: 'id',
            clientSecret: 'sec',
            debug: true,
        );
    }

    public function testDebugWiresLoggingClient(): void
    {
        $http = new MockHttpClient();

        // Token-exchange response
        $http->enqueue(200, json_encode([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'access_token' => 'tok',
                'access_token_expiry' => date('Y-m-d\TH:i:s\Z', time() + 3600),
            ],
            'meta' => [],
        ], JSON_THROW_ON_ERROR));

        // Actual API response
        $http->enqueue(200, json_encode([
            'success' => true,
            'message' => 'ok',
            'data' => ['session' => 'sess-uuid'],
            'meta' => [],
        ], JSON_THROW_ON_ERROR));

        $captured = [];
        $sink = static function (string $entry) use (&$captured): void {
            $captured[] = $entry;
        };

        $client = new AsterMDClient(
            clientId: 'id',
            clientSecret: 'sec',
            httpClient: $http,
            debug: true,
            debugSink: $sink,
        );

        $client->sessions()->create();

        // Token exchange + actual call = at least 2 log entries
        self::assertGreaterThanOrEqual(2, count($captured));
    }

    public function testDebugInvalidTimezoneThrows(): void
    {
        $this->expectException(\Exception::class);

        new AsterMDClient(
            clientId: 'id',
            clientSecret: 'sec',
            debug: true,
            debugFile: '/tmp/x',
            debugTimezone: 'Mars/Olympus',
        );
    }

    public function testDebugLogsRedactTheBearerTokenByDefault(): void
    {
        $captured = $this->captureDebugEntries();

        $joined = implode("\n", $captured);

        self::assertStringContainsString('Bearer [REDACTED]', $joined);
        self::assertStringNotContainsString('jwt-abc123', $joined);
        // The credential exchange is logged too, so the secret must be masked there.
        self::assertStringNotContainsString('super-secret', $joined);
    }

    public function testDebugRedactFalseLogsCredentialsVerbatim(): void
    {
        $captured = $this->captureDebugEntries(redact: false);

        $joined = implode("\n", $captured);

        self::assertStringContainsString('jwt-abc123', $joined);
        self::assertStringContainsString('super-secret', $joined);
    }

    public function testDebugFileWritesToADatedFileAndNotTheBasePath(): void
    {
        $dir = sys_get_temp_dir() . '/astermd-client-log-' . bin2hex(random_bytes(6));
        $base = $dir . '/sdk.log';

        $http = new MockHttpClient();
        $this->enqueueTokenAndSession($http);

        $client = new AsterMDClient(
            clientId: 'id',
            clientSecret: 'sec',
            httpClient: $http,
            debug: true,
            debugFile: $base,
        );
        $client->sessions()->create();

        $dated = $dir . '/sdk-' . new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('Y-m-d') . '.log';

        try {
            self::assertFileExists($dated);
            self::assertFileDoesNotExist($base);
            self::assertStringContainsString('curl --location --request', (string) file_get_contents($dated));
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    /**
     * @return list<string>
     */
    private function captureDebugEntries(bool $redact = true): array
    {
        $http = new MockHttpClient();
        $this->enqueueTokenAndSession($http);

        $captured = [];
        $client = new AsterMDClient(
            clientId: 'id',
            clientSecret: 'super-secret',
            httpClient: $http,
            debug: true,
            debugSink: static function (string $entry) use (&$captured): void {
                $captured[] = $entry;
            },
            debugRedact: $redact,
        );

        $client->sessions()->create();

        return $captured;
    }

    private function enqueueTokenAndSession(MockHttpClient $http): void
    {
        $http->enqueue(200, json_encode([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'access_token' => 'jwt-abc123',
                'access_token_expiry' => new \DateTimeImmutable('+1 hour')->format('Y-m-d\TH:i:s\Z'),
            ],
            'meta' => [],
        ], JSON_THROW_ON_ERROR));

        $http->enqueue(200, json_encode([
            'success' => true,
            'message' => 'ok',
            'data' => ['session' => 'sess-uuid'],
            'meta' => [],
        ], JSON_THROW_ON_ERROR));
    }
}
