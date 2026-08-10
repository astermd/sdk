<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Resource;

use AsterMD\Sdk\Http\Transport;
use AsterMD\Sdk\Http\UrlBuilder;
use AsterMD\Sdk\Resource\Channels;
use AsterMD\Sdk\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class ChannelsTest extends TestCase
{
    private MockHttpClient $http;
    private Channels $resource;

    protected function setUp(): void
    {
        $factory = new Psr17Factory();
        $this->http = new MockHttpClient();
        $this->resource = new Channels(new Transport(
            httpClient: $this->http,
            requestFactory: $factory,
            streamFactory: $factory,
            urlBuilder: new UrlBuilder('api.astermd.com'),
            tokenProvider: static fn (): string => 'jwt',
            onUnauthorized: static fn (): bool => false,
        ));
    }

    public function testView(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->resource->view('c1');
        self::assertSame('https://api.astermd.com/v1/sales/channels/view/c1', (string) $this->http->lastRequest()->getUri());
    }

    public function testAssignedProducts(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":[],"meta":{}}');
        $this->resource->assignedProducts('c1');
        self::assertSame(
            'https://api.astermd.com/v1/sales/channels/assigned-products/c1',
            (string) $this->http->lastRequest()->getUri(),
        );
    }

    public function testDetailsGetsByObjectId(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->resource->details('6a1c2a565f315cee0e41c395');

        $req = $this->http->lastRequest();
        self::assertSame('GET', $req->getMethod());
        // Server path is `/channels/detail/{id}` (singular) even though the SDK method is `details()`.
        self::assertSame(
            'https://api.astermd.com/v1/sales/channels/detail/6a1c2a565f315cee0e41c395',
            (string) $req->getUri(),
        );
    }
}
