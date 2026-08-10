<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Enum;

/**
 * Journey events recorded against a session's checkout funnel on the `sales` service.
 *
 * Each call to {@see \AsterMD\Sdk\Resource\CheckoutEvents::create()} or
 * {@see \AsterMD\Sdk\Resource\CheckoutEvents::update()} carries one of these
 * events to advance the server-side checkout state machine and attribute the
 * order to the prospect's session.
 *
 * The funnel has three stages:
 *
 *  - **Visit** — `CheckoutVisited` is emitted once when the prospect lands on the
 *    checkout page. It is always recorded via `create()`; the SDK sets it for you,
 *    so you never pass this case explicitly.
 *  - **Upsell** — as post-cart upsell offers are shown and answered, emit
 *    `UpsellOffered`, then `UpsellAccepted` or `UpsellDeclined`, via `update()`.
 *    These events carry the upsell `product_id` and `product_name`.
 *  - **Order** — when the order resolves, emit `OrderPlaced` or `OrderDeclined`
 *    via `update()`. These events carry the order totals, payment method, currency,
 *    and the payment provider's order id(s).
 */
enum CheckoutEvent: string
{
    /** Prospect reached the checkout page. Recorded via `CheckoutEvents::create()` (set automatically by the SDK). */
    case CheckoutVisited = 'checkout_visited';

    /** An upsell offer was shown to the prospect. Pass to `CheckoutEvents::update()` with `product_id` and `product_name`. */
    case UpsellOffered = 'upsell_offered';

    /** The prospect accepted an upsell offer. Pass to `CheckoutEvents::update()` with `product_id` and `product_name`. */
    case UpsellAccepted = 'upsell_accepted';

    /** The prospect declined an upsell offer. Pass to `CheckoutEvents::update()` with `product_id` and `product_name`. */
    case UpsellDeclined = 'upsell_declined';

    /** The order was successfully placed. Pass to `CheckoutEvents::update()` with the order totals and payment details. */
    case OrderPlaced = 'order_placed';

    /** The order was declined by the payment provider. Pass to `CheckoutEvents::update()` with the order totals and payment details. */
    case OrderDeclined = 'order_declined';
}
