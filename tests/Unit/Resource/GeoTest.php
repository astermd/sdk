<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Resource;

use AsterMD\Sdk\Http\Transport;
use AsterMD\Sdk\Http\UrlBuilder;
use AsterMD\Sdk\Resource\Geo;
use AsterMD\Sdk\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class GeoTest extends TestCase
{
    private MockHttpClient $http;
    private Geo $resource;

    protected function setUp(): void
    {
        $factory = new Psr17Factory();
        $this->http = new MockHttpClient();
        $this->resource = new Geo(new Transport(
            httpClient: $this->http,
            requestFactory: $factory,
            streamFactory: $factory,
            urlBuilder: new UrlBuilder('api.astermd.com'),
            tokenProvider: static fn (): string => 'jwt',
            onUnauthorized: static fn (): bool => false,
        ));
    }

    public function testInfo(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"valid":true,"region-code":"CA"},"meta":{}}');
        $response = $this->resource->info('8.8.8.8');

        $req = $this->http->lastRequest();
        self::assertSame('GET', $req->getMethod());
        self::assertSame(
            'https://api.astermd.com/v1/platform/extensions/geo-info?ip=8.8.8.8',
            (string) $req->getUri(),
        );
        self::assertSame('Bearer jwt', $req->getHeaderLine('Authorization'));
        self::assertSame('CA', $response->data()['region-code']);
    }

    public function testBlocklist(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"ip":"8.8.8.8","is-listed":false},"meta":{}}');
        $response = $this->resource->blocklist('8.8.8.8');

        $req = $this->http->lastRequest();
        self::assertSame('GET', $req->getMethod());
        self::assertSame(
            'https://api.astermd.com/v1/platform/extensions/geo-blocklist?ip=8.8.8.8',
            (string) $req->getUri(),
        );
        self::assertSame('Bearer jwt', $req->getHeaderLine('Authorization'));
        self::assertFalse($response->data()['is-listed']);
    }

    public function testIpv6AddressIsEncodedInQuery(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->resource->info('2001:4860:4860::8888');

        self::assertSame(
            'ip=2001%3A4860%3A4860%3A%3A8888',
            $this->http->lastRequest()->getUri()->getQuery(),
        );
    }
}
