<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Enum;

use AsterMD\Sdk\Enum\CheckoutEvent;
use PHPUnit\Framework\TestCase;

final class CheckoutEventTest extends TestCase
{
    public function testBackingValuesMatchApiContract(): void
    {
        self::assertSame('checkout_visited', CheckoutEvent::CheckoutVisited->value);
        self::assertSame('upsell_offered', CheckoutEvent::UpsellOffered->value);
        self::assertSame('upsell_accepted', CheckoutEvent::UpsellAccepted->value);
        self::assertSame('upsell_declined', CheckoutEvent::UpsellDeclined->value);
        self::assertSame('order_placed', CheckoutEvent::OrderPlaced->value);
        self::assertSame('order_declined', CheckoutEvent::OrderDeclined->value);
    }
}
