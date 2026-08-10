<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Resource;

use AsterMD\Sdk\Http\Transport;
use AsterMD\Sdk\Http\UrlBuilder;
use AsterMD\Sdk\Resource\Opportunities;
use AsterMD\Sdk\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class OpportunitiesTest extends TestCase
{
    private MockHttpClient $http;
    private Opportunities $resource;

    protected function setUp(): void
    {
        $factory = new Psr17Factory();
        $this->http = new MockHttpClient();
        $this->resource = new Opportunities(new Transport(
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
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{"id":"o1"},"meta":{}}');
        $this->resource->create(['sessions' => ['s-uuid'], 'email' => 'a@b.c']);

        $req = $this->http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame('https://api.astermd.com/v1/sales/opportunities/create', (string) $req->getUri());
    }

    public function testView(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->resource->view('o1');
        self::assertSame('https://api.astermd.com/v1/sales/opportunities/view/o1', (string) $this->http->lastRequest()->getUri());
    }

    public function testUpdate(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->resource->update('o1', ['email' => 'x@y.z']);
        $req = $this->http->lastRequest();
        self::assertSame('PUT', $req->getMethod());
        self::assertSame('https://api.astermd.com/v1/sales/opportunities/update/o1', (string) $req->getUri());
    }

    public function testStatus(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->resource->status('o1', 'qualified');
        $req = $this->http->lastRequest();
        self::assertSame('PATCH', $req->getMethod());
        self::assertJsonStringEqualsJsonString('{"opportunity_status":"qualified"}', (string) $req->getBody());
    }
}
