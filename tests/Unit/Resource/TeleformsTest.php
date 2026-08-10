<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Resource;

use AsterMD\Sdk\Http\Transport;
use AsterMD\Sdk\Http\UrlBuilder;
use AsterMD\Sdk\Resource\Teleforms;
use AsterMD\Sdk\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class TeleformsTest extends TestCase
{
    private MockHttpClient $http;
    private Teleforms $resource;

    protected function setUp(): void
    {
        $factory = new Psr17Factory();
        $this->http = new MockHttpClient();
        $this->resource = new Teleforms(new Transport(
            httpClient: $this->http,
            requestFactory: $factory,
            streamFactory: $factory,
            urlBuilder: new UrlBuilder('api.astermd.com'),
            tokenProvider: static fn (): string => 'jwt',
            onUnauthorized: static fn (): bool => false,
        ));
    }

    public function testViewByIdentifierFetchesPublicDefinition(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->resource->viewByIdentifier('public-ident');

        $req = $this->http->lastRequest();
        self::assertSame('GET', $req->getMethod());
        self::assertSame(
            'https://api.astermd.com/v1/sales/teleforms/view-url/public-ident',
            (string) $req->getUri(),
        );
    }

    public function testViewFetchesById(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');
        $this->resource->view('abc');

        $req = $this->http->lastRequest();
        self::assertSame('https://api.astermd.com/v1/sales/teleforms/view/abc', (string) $req->getUri());
    }
}
