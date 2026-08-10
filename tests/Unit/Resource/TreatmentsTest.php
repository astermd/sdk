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

        $this->resource->sync('17de25ac-ba67-4f6e-8067-d752e800ae47', ['28618', '28465']);

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

    public function testSyncIncludesUtmSourceWhenProvided(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $this->resource->sync('17de25ac-ba67-4f6e-8067-d752e800ae47', ['28618'], 'google');

        self::assertJsonStringEqualsJsonString(
            '{"session_id":"17de25ac-ba67-4f6e-8067-d752e800ae47","order_ids":["28618"],"utm_source":"google"}',
            (string) $this->http->lastRequest()->getBody(),
        );
    }

    public function testSyncForwardsUserAgentHeaderWhenProvided(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
        $this->resource->sync('17de25ac-ba67-4f6e-8067-d752e800ae47', ['28618'], userAgent: $ua);

        $req = $this->http->lastRequest();
        self::assertSame($ua, $req->getHeaderLine('User-Agent'));
        // utm_source omitted when null even with a user agent present.
        self::assertJsonStringEqualsJsonString(
            '{"session_id":"17de25ac-ba67-4f6e-8067-d752e800ae47","order_ids":["28618"]}',
            (string) $req->getBody(),
        );
    }

    public function testSyncOmitsUserAgentHeaderWhenNotProvided(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $this->resource->sync('17de25ac-ba67-4f6e-8067-d752e800ae47', ['28618']);

        self::assertFalse($this->http->lastRequest()->hasHeader('User-Agent'));
    }
}
