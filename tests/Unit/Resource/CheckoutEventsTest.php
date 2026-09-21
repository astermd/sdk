<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Resource;

use AsterMD\Sdk\Enum\CheckoutEvent;
use AsterMD\Sdk\Http\Transport;
use AsterMD\Sdk\Http\UrlBuilder;
use AsterMD\Sdk\Resource\CheckoutEvents;
use AsterMD\Sdk\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class CheckoutEventsTest extends TestCase
{
    private MockHttpClient $http;
    private CheckoutEvents $resource;

    protected function setUp(): void
    {
        $factory = new Psr17Factory();
        $this->http = new MockHttpClient();
        $this->resource = new CheckoutEvents(new Transport(
            httpClient: $this->http,
            requestFactory: $factory,
            streamFactory: $factory,
            urlBuilder: new UrlBuilder('api.astermd.com'),
            tokenProvider: static fn (): string => 'jwt',
            onUnauthorized: static fn (): bool => false,
        ));
    }

    public function testCreateSendsCheckoutVisitedEventAndDefaultsCurrencyToUsd(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $this->resource->create('sess-uuid', [
            'order_value' => 89.0,
            'order_total' => 99.0,
            'payment_method' => 'card',
        ]);

        $req = $this->http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame(
            'https://api.astermd.com/v1/sales/checkout-events/create',
            (string) $req->getUri(),
        );
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));
        self::assertJsonStringEqualsJsonString(
            '{"session":"sess-uuid","event":"checkout_visited","order_value":89.0,"order_total":99.0,"payment_method":"card","currency":"USD"}',
            (string) $req->getBody(),
        );
    }

    public function testCreateKeepsCallerSuppliedCurrency(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $this->resource->create('sess-uuid', ['currency' => 'GBP', 'discount_code' => 'SAVE10']);

        self::assertJsonStringEqualsJsonString(
            '{"session":"sess-uuid","event":"checkout_visited","currency":"GBP","discount_code":"SAVE10"}',
            (string) $this->http->lastRequest()->getBody(),
        );
    }

    public function testUpdateUpsellOfferedPutsBySessionIdWithoutSessionInBody(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $this->resource->update('sess-uuid', CheckoutEvent::UpsellOffered, [
            'product_id' => '6a1c29ef5f315cee0e41c377',
            'product_name' => 'Syringe',
        ]);

        $req = $this->http->lastRequest();
        self::assertSame('PUT', $req->getMethod());
        self::assertSame(
            'https://api.astermd.com/v1/sales/checkout-events/update/sess-uuid',
            (string) $req->getUri(),
        );
        self::assertJsonStringEqualsJsonString(
            '{"event":"upsell_offered","product_id":"6a1c29ef5f315cee0e41c377","product_name":"Syringe"}',
            (string) $req->getBody(),
        );

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $req->getBody(), true);
        self::assertArrayNotHasKey('session', $body);
    }

    public function testUpdateOrderPlacedSendsEventAndOrderFields(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $this->resource->update('sess-uuid', CheckoutEvent::OrderPlaced, [
            'order_value' => 89.0,
            'order_total' => 99.0,
            'payment_method' => 'apple_pay',
            'currency' => 'USD',
            'provider_order_id' => ['2844', '2845'],
            'opportunity_id' => 'opp-1',
        ]);

        self::assertJsonStringEqualsJsonString(
            '{"event":"order_placed","order_value":89.0,"order_total":99.0,"payment_method":"apple_pay","currency":"USD","provider_order_id":["2844","2845"],"opportunity_id":"opp-1"}',
            (string) $this->http->lastRequest()->getBody(),
        );
    }

    public function testUpdateOrderPlacedForwardsPaymentWhenProvided(): void
    {
        $this->http->enqueue(200, '{"success":true,"message":"ok","data":{},"meta":{}}');

        $this->resource->update('sess-uuid', CheckoutEvent::OrderPlaced, [
            'order_total' => 99.0,
            'payment' => [
                'type' => 'credit_card',
                'pre_auth' => false,
                'card' => [
                    'type' => 'visa',
                    'exp' => '12/29',
                ],
            ],
        ]);

        self::assertJsonStringEqualsJsonString(
            '{"event":"order_placed","order_total":99.0,"payment":'
                . '{"type":"credit_card","pre_auth":false,"card":{"type":"visa","exp":"12/29"}}}',
            (string) $this->http->lastRequest()->getBody(),
        );
    }
}
