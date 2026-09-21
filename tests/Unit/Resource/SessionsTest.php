<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Resource;

use AsterMD\Sdk\Http\Transport;
use AsterMD\Sdk\Http\UrlBuilder;
use AsterMD\Sdk\Resource\Sessions;
use AsterMD\Sdk\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class SessionsTest extends TestCase
{
    private MockHttpClient $http;
    private Sessions $sessions;

    protected function setUp(): void
    {
        $factory = new Psr17Factory();
        $this->http = new MockHttpClient();
        $transport = new Transport(
            httpClient: $this->http,
            requestFactory: $factory,
            streamFactory: $factory,
            urlBuilder: new UrlBuilder('api.astermd.com'),
            tokenProvider: static fn (): string => 'jwt',
            onUnauthorized: static fn (): bool => false,
        );
        $this->sessions = new Sessions($transport);
    }

    public function testCreateWithoutPayloadSendsEmptyJsonObject(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"session":"uuid"},"meta":{}}');

        $response = $this->sessions->create();

        self::assertSame(['session' => 'uuid'], $response->data());
        $req = $this->http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame('https://api.astermd.com/v1/sales/sessions/create', (string) $req->getUri());

        // The server requires at least `{}`. No body returns 500 on dev; the JSON
        // array literal `[]` (what `json_encode([])` produces) would also fail.
        self::assertSame('{}', (string) $req->getBody());
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));
    }

    public function testCreateWithPayloadSendsJsonObject(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"session":"uuid"},"meta":{}}');

        $this->sessions->create(['test' => true]);

        $req = $this->http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame('https://api.astermd.com/v1/sales/sessions/create', (string) $req->getUri());
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));
        self::assertJsonStringEqualsJsonString('{"test":true}', (string) $req->getBody());
    }

    public function testCreateForwardsUserAgentHeaderWhenProvided(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"session":"uuid"},"meta":{}}');

        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15';
        $this->sessions->create(userAgent: $ua);

        self::assertSame($ua, $this->http->lastRequest()->getHeaderLine('User-Agent'));
    }

    public function testCreateOmitsUserAgentHeaderWhenNullOrEmpty(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"session":"uuid"},"meta":{}}');
        $this->sessions->create();
        self::assertFalse($this->http->lastRequest()->hasHeader('User-Agent'));

        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"session":"uuid"},"meta":{}}');
        $this->sessions->create(data: ['test' => true], userAgent: '');
        self::assertFalse($this->http->lastRequest()->hasHeader('User-Agent'));
    }

    public function testCreateForwardsClientIpHeaderWhenProvided(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"session":"uuid"},"meta":{}}');

        $this->sessions->create(clientIp: '122.176.25.124');

        self::assertSame('122.176.25.124', $this->http->lastRequest()->getHeaderLine('X-Original-Client-Ip'));
    }

    public function testCreateForwardsUserAgentAndClientIpTogether(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"session":"uuid"},"meta":{}}');

        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X)';
        $this->sessions->create(userAgent: $ua, clientIp: '122.176.25.124');

        $req = $this->http->lastRequest();
        self::assertSame($ua, $req->getHeaderLine('User-Agent'));
        self::assertSame('122.176.25.124', $req->getHeaderLine('X-Original-Client-Ip'));
    }

    public function testCreateOmitsClientIpHeaderWhenNullOrEmpty(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"session":"uuid"},"meta":{}}');
        $this->sessions->create();
        self::assertFalse($this->http->lastRequest()->hasHeader('X-Original-Client-Ip'));

        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"session":"uuid"},"meta":{}}');
        $this->sessions->create(clientIp: '');
        self::assertFalse($this->http->lastRequest()->hasHeader('X-Original-Client-Ip'));
    }

    public function testCreateForwardsVerificationWhenProvided(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"session":"uuid"},"meta":{}}');

        $verification = [
            'email' => true,
            'id' => ['verified' => true, 'method' => 'dob', 'value' => '1990-01-01'],
        ];
        $this->sessions->create(['verification' => $verification]);

        self::assertJsonStringEqualsJsonString(
            '{"verification":{"email":true,"id":{"verified":true,"method":"dob","value":"1990-01-01"}}}',
            (string) $this->http->lastRequest()->getBody(),
        );
    }

    public function testViewSingleSessionUsesQueryString(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->sessions->view(['abc-123']);

        $req = $this->http->lastRequest();
        self::assertSame('GET', $req->getMethod());
        // Real route is /sessions/view?session_ids=... (NOT /sessions/view/{id}).
        self::assertSame(
            'https://api.astermd.com/v1/sales/sessions/view?session_ids=abc-123',
            (string) $req->getUri(),
        );
    }

    public function testViewMultipleSessionsJoinsWithComma(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->sessions->view(['uuid-a', 'uuid-b']);

        self::assertSame(
            'https://api.astermd.com/v1/sales/sessions/view?session_ids=uuid-a%2Cuuid-b',
            (string) $this->http->lastRequest()->getUri(),
        );
    }

    public function testViewAppendsTimezoneWhenProvided(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->sessions->view(['abc-123'], 'Asia/Kolkata');

        self::assertSame(
            'https://api.astermd.com/v1/sales/sessions/view?session_ids=abc-123&tz=Asia%2FKolkata',
            (string) $this->http->lastRequest()->getUri(),
        );
    }

    public function testViewOmitsTimezoneWhenNull(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->sessions->view(['abc-123']);

        self::assertStringNotContainsString('tz=', (string) $this->http->lastRequest()->getUri());
    }

    public function testUpdatePutsSession(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->sessions->update('abc-123', ['session' => 'abc-123']);

        $req = $this->http->lastRequest();
        self::assertSame('PUT', $req->getMethod());
        self::assertSame('https://api.astermd.com/v1/sales/sessions/update/abc-123', (string) $req->getUri());
        self::assertJsonStringEqualsJsonString('{"session":"abc-123"}', (string) $req->getBody());
    }

    public function testUpdateForwardsVerificationWhenProvided(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $this->sessions->update('abc-123', ['verification' => ['address' => true]]);

        self::assertJsonStringEqualsJsonString(
            '{"verification":{"address":true}}',
            (string) $this->http->lastRequest()->getBody(),
        );
    }

    public function testDeleteRemovesSession(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->sessions->delete('abc-123');

        $req = $this->http->lastRequest();
        self::assertSame('DELETE', $req->getMethod());
        self::assertSame('https://api.astermd.com/v1/sales/sessions/delete/abc-123', (string) $req->getUri());
    }
}
