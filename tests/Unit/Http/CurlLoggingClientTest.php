<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Http;

use AsterMD\Sdk\Http\CurlLoggingClient;
use AsterMD\Sdk\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

final class CurlLoggingClientTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    public function testInnerClientCalledOnceAndResponseReturned(): void
    {
        $mock = new MockHttpClient();
        $mock->enqueue(200, '{"ok":true}');

        $entries = [];
        $client = new CurlLoggingClient($mock, static function (string $e) use (&$entries): void {
            $entries[] = $e;
        }, new \DateTimeZone('UTC'));

        $request = $this->factory->createRequest('GET', 'https://api.example.com/v1/test');
        $response = $client->sendRequest($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $mock->requests);
        self::assertCount(1, $entries);
    }

    public function testSinkReceivesCurlAndResponseStatus(): void
    {
        $mock = new MockHttpClient();
        $mock->enqueue(201, '{"created":true}');

        $entries = [];
        $client = new CurlLoggingClient($mock, static function (string $e) use (&$entries): void {
            $entries[] = $e;
        }, new \DateTimeZone('UTC'));

        $request = $this->factory->createRequest('POST', 'https://api.example.com/v1/sessions')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream('{}'));

        $client->sendRequest($request);

        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertStringContainsString('curl --location --request POST', $entry);
        self::assertStringContainsString('# Response: HTTP 201', $entry);
        self::assertStringContainsString('{"created":true}', $entry);
    }

    public function testBodyRemainsReadableAfterLogging(): void
    {
        $mock = new MockHttpClient();
        $mock->enqueue(200, '{"data":"value"}');

        $client = new CurlLoggingClient($mock, static function (string $e): void {
        }, new \DateTimeZone('UTC'));

        $request = $this->factory->createRequest('GET', 'https://api.example.com/v1/test');
        $response = $client->sendRequest($request);

        // Body must still be readable after the logging decorator consumed it
        $body = (string) $response->getBody();
        self::assertSame('{"data":"value"}', $body);
    }

    public function testThrowingInnerClientLogsErrorAndRethrows(): void
    {
        $throwingClient = new class () implements \Psr\Http\Client\ClientInterface {
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                throw new class ('cURL timeout') extends \RuntimeException implements ClientExceptionInterface {};
            }
        };

        $entries = [];
        $client = new CurlLoggingClient($throwingClient, static function (string $e) use (&$entries): void {
            $entries[] = $e;
        }, new \DateTimeZone('UTC'));

        $request = $this->factory->createRequest('POST', 'https://api.example.com/v1/test');

        try {
            $client->sendRequest($request);
            self::fail('Expected exception was not thrown.');
        } catch (\Throwable) {
            // expected
        }

        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertStringContainsString('curl --location --request POST', $entry);
        self::assertStringContainsString('# Transport error: cURL timeout', $entry);
    }

    public function testCustomTimezoneAppearedInEntry(): void
    {
        $mock = new MockHttpClient();
        $mock->enqueue(200, 'ok');

        $entries = [];
        $client = new CurlLoggingClient($mock, static function (string $e) use (&$entries): void {
            $entries[] = $e;
        }, new \DateTimeZone('Asia/Kolkata'));

        $request = $this->factory->createRequest('GET', 'https://api.example.com/v1/test');
        $client->sendRequest($request);

        self::assertCount(1, $entries);
        // IST is the abbreviation for Asia/Kolkata
        self::assertStringContainsString('IST', $entries[0]);
    }

    public function testRedactsBearerTokenByDefault(): void
    {
        $entry = $this->logEntryFor(
            $this->factory->createRequest('GET', 'https://api.example.com/v1/sales/sessions')
                ->withHeader('Authorization', 'Bearer eyJhbGciOiJIUzI1NiJ9.secret.sig'),
            '{"ok":true}',
        );

        self::assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $entry);
        self::assertStringContainsString('Bearer [REDACTED]', $entry);
    }

    public function testRedactsAccessTokenInResponseBodyByDefault(): void
    {
        $entry = $this->logEntryFor(
            $this->factory->createRequest('POST', 'https://api.example.com/v1/auth/token'),
            '{"access_token":"eyJhbGciOi.leaked","expires_in":3600}',
        );

        self::assertStringNotContainsString('eyJhbGciOi.leaked', $entry);
        self::assertStringContainsString('"expires_in":3600', $entry);
    }

    public function testDropsPatientEndpointBodiesByDefault(): void
    {
        $request = $this->factory->createRequest('POST', 'https://api.example.com/v1/sales/patients/create')
            ->withBody($this->factory->createStream('{"first_name":"Jane"}'));

        $entry = $this->logEntryFor($request, '{"last_name":"Doe"}');

        self::assertStringNotContainsString('Jane', $entry);
        self::assertStringNotContainsString('Doe', $entry);
    }

    public function testRedactionCanBeDisabledExplicitly(): void
    {
        $mock = new MockHttpClient();
        $mock->enqueue(200, '{"access_token":"visible-token"}');

        $entries = [];
        $client = new CurlLoggingClient(
            $mock,
            static function (string $e) use (&$entries): void {
                $entries[] = $e;
            },
            new \DateTimeZone('UTC'),
            null,
        );

        $client->sendRequest(
            $this->factory->createRequest('GET', 'https://api.example.com/v1/test')
                ->withHeader('Authorization', 'Bearer raw-jwt-value'),
        );

        self::assertStringContainsString('Bearer raw-jwt-value', $entries[0]);
        self::assertStringContainsString('visible-token', $entries[0]);
    }

    public function testResponseBodyStaysIntactForCallersWhenRedacted(): void
    {
        $mock = new MockHttpClient();
        $mock->enqueue(200, '{"access_token":"real-value"}');

        $client = new CurlLoggingClient($mock, static function (string $e): void {
        }, new \DateTimeZone('UTC'));

        $response = $client->sendRequest(
            $this->factory->createRequest('POST', 'https://api.example.com/v1/auth/token'),
        );

        // Redaction affects the log entry only — the transport must still see the
        // real token, otherwise authentication would break whenever debug is on.
        self::assertSame('{"access_token":"real-value"}', (string) $response->getBody());
    }

    private function logEntryFor(\Psr\Http\Message\RequestInterface $request, string $responseBody): string
    {
        $mock = new MockHttpClient();
        $mock->enqueue(200, $responseBody);

        $entries = [];
        $client = new CurlLoggingClient($mock, static function (string $e) use (&$entries): void {
            $entries[] = $e;
        }, new \DateTimeZone('UTC'));

        $client->sendRequest($request);

        return $entries[0];
    }
}
