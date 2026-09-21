<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Resource;

use AsterMD\Sdk\Http\Transport;
use AsterMD\Sdk\Http\UrlBuilder;
use AsterMD\Sdk\Resource\Treatments;
use AsterMD\Sdk\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class TreatmentsTest extends TestCase
{
    private MockHttpClient $http;
    private Treatments $resource;

    protected function setUp(): void
    {
        $factory = new Psr17Factory();
        $this->http = new MockHttpClient();
        $this->resource = new Treatments(new Transport(
            httpClient: $this->http,
            requestFactory: $factory,
            streamFactory: $factory,
            urlBuilder: new UrlBuilder('api.astermd.com'),
            tokenProvider: static fn (): string => 'jwt',
            onUnauthorized: static fn (): bool => false,
        ));
    }

    public function testCreate(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"id":"t1"},"meta":{}}');
        $this->resource->create(['external_order_id' => 'EXT-1', 'patient' => ['id' => 'p1']]);

        $req = $this->http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame('https://api.astermd.com/v1/sales/treatments/create', (string) $req->getUri());
    }

    public function testView(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->resource->view('t1');
        self::assertSame('https://api.astermd.com/v1/sales/treatments/view/t1', (string) $this->http->lastRequest()->getUri());
    }

    public function testListWithQuery(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":[],"meta":{"page":2}}');
        $this->resource->list(['page' => 2, 'limit' => 50]);
        self::assertSame(
            'https://api.astermd.com/v1/sales/treatments/list?page=2&limit=50',
            (string) $this->http->lastRequest()->getUri(),
        );
    }

    public function testSyncPostsSessionIdAndOrderIds(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
        $this->resource->sync('17de25ac-ba67-4f6e-8067-d752e800ae47', ['28618', '28465'], $ua);

        $req = $this->http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame(
            'https://api.astermd.com/v1/sales/treatments/sync',
            (string) $req->getUri(),
        );
        self::assertJsonStringEqualsJsonString(
            '{"session_id":"17de25ac-ba67-4f6e-8067-d752e800ae47","order_ids":["28618","28465"]}',
            (string) $req->getBody(),
        );
    }

    public function testSyncForwardsUserAgentHeader(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
        $this->resource->sync('17de25ac-ba67-4f6e-8067-d752e800ae47', ['28618'], $ua);

        self::assertSame($ua, $this->http->lastRequest()->getHeaderLine('User-Agent'));
    }

    public function testSyncThrowsWhenUserAgentIsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resource->sync('17de25ac-ba67-4f6e-8067-d752e800ae47', ['28618'], '');
    }

    public function testSyncIncludesUtmSourceWhenProvided(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
        $this->resource->sync('17de25ac-ba67-4f6e-8067-d752e800ae47', ['28618'], $ua, 'google');

        self::assertJsonStringEqualsJsonString(
            '{"session_id":"17de25ac-ba67-4f6e-8067-d752e800ae47","order_ids":["28618"],"utm_source":"google"}',
            (string) $this->http->lastRequest()->getBody(),
        );
    }

    public function testSyncIncludesPaymentWhenProvided(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
        $payment = [
            'type' => 'credit_card',
            'pre_auth' => false,
            'card' => [
                'type' => 'visa',
                'exp' => '12/29',
            ],
        ];
        $this->resource->sync('17de25ac-ba67-4f6e-8067-d752e800ae47', ['28618'], $ua, payment: $payment);

        self::assertJsonStringEqualsJsonString(
            '{"session_id":"17de25ac-ba67-4f6e-8067-d752e800ae47","order_ids":["28618"],"payment":'
                . '{"type":"credit_card","pre_auth":false,"card":{"type":"visa","exp":"12/29"}}}',
            (string) $this->http->lastRequest()->getBody(),
        );
    }

    public function testSyncIncludesVerificationWhenProvided(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
        $verification = [
            'email' => true,
            'address' => false,
            'id' => [
                'verified' => true,
                'method' => 'dob',
                'value' => '1990-01-01',
            ],
        ];
        $this->resource->sync('17de25ac-ba67-4f6e-8067-d752e800ae47', ['28618'], $ua, verification: $verification);

        self::assertJsonStringEqualsJsonString(
            '{"session_id":"17de25ac-ba67-4f6e-8067-d752e800ae47","order_ids":["28618"],"verification":'
                . '{"email":true,"address":false,"id":{"verified":true,"method":"dob","value":"1990-01-01"}}}',
            (string) $this->http->lastRequest()->getBody(),
        );
    }
}
