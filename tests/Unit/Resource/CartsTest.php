<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Resource;

use AsterMD\Sdk\Http\Transport;
use AsterMD\Sdk\Http\UrlBuilder;
use AsterMD\Sdk\Resource\Carts;
use AsterMD\Sdk\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class CartsTest extends TestCase
{
    private MockHttpClient $http;
    private Carts $resource;

    protected function setUp(): void
    {
        $factory = new Psr17Factory();
        $this->http = new MockHttpClient();
        $this->resource = new Carts(new Transport(
            httpClient: $this->http,
            requestFactory: $factory,
            streamFactory: $factory,
            urlBuilder: new UrlBuilder('api.astermd.com'),
            tokenProvider: static fn (): string => 'jwt',
            onUnauthorized: static fn (): bool => false,
        ));
    }

    public function testCreateSendsSessionAndItems(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $this->resource->create('sess-uuid', [
            ['product_id' => 'p1', 'name' => 'Semaglutide', 'qty' => 2],
            ['product_id' => 'p2', 'name' => 'Tirzepatide', 'qty' => 3],
        ]);

        $req = $this->http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame(
            'https://api.astermd.com/v1/sales/carts/create',
            (string) $req->getUri(),
        );
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));
        self::assertJsonStringEqualsJsonString(
            '{"session":"sess-uuid","items":[{"product_id":"p1","name":"Semaglutide","qty":2},{"product_id":"p2","name":"Tirzepatide","qty":3}]}',
            (string) $req->getBody(),
        );

        // Event and channel_id are derived server-side — the SDK must not send them.
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $req->getBody(), true);
        self::assertArrayNotHasKey('event', $body);
        self::assertArrayNotHasKey('channel_id', $body);
    }

    public function testCreateForwardsOptionalVariantId(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $this->resource->create('sess-uuid', [
            ['product_id' => 'p1', 'variant_id' => 'v1', 'name' => 'Semaglutide', 'qty' => 2],
        ]);

        self::assertJsonStringEqualsJsonString(
            '{"session":"sess-uuid","items":[{"product_id":"p1","variant_id":"v1","name":"Semaglutide","qty":2}]}',
            (string) $this->http->lastRequest()->getBody(),
        );
    }

    public function testUpdatePutsBySessionWithItemsOnly(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $this->resource->update('sess-uuid', [
            ['product_id' => 'p1', 'name' => 'Semaglutide', 'qty' => 5],
        ]);

        $req = $this->http->lastRequest();
        self::assertSame('PUT', $req->getMethod());
        self::assertSame(
            'https://api.astermd.com/v1/sales/carts/update/sess-uuid',
            (string) $req->getUri(),
        );
        self::assertJsonStringEqualsJsonString(
            '{"items":[{"product_id":"p1","name":"Semaglutide","qty":5}]}',
            (string) $req->getBody(),
        );

        // Body carries items only — no session, event, or channel_id.
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $req->getBody(), true);
        self::assertArrayNotHasKey('session', $body);
        self::assertArrayNotHasKey('event', $body);
    }

    public function testCreateWithEmptyItemsSendsJsonArrayNotObject(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        // items is a list field — PHP's json_encode([]) must produce `[]`, not `{}`.
        // This test locks in the "items is a list, not an object" rule so a future
        // contributor doesn't accidentally coerce it to stdClass.
        $this->resource->create('sess-uuid', []);

        $raw = (string) $this->http->lastRequest()->getBody();
        self::assertStringContainsString('"items":[]', $raw);
        self::assertStringNotContainsString('"items":{}', $raw);
    }
}
