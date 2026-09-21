<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Resource;

use AsterMD\Sdk\Enum\CheckoutEvent;
use AsterMD\Sdk\Response;

/**
 * Records a session's checkout-funnel events on the `sales` service.
 *
 * Checkout events track a prospect through the final stage of the order flow —
 * landing on the checkout page, being shown and answering post-cart upsell
 * offers, and the eventual order outcome. Each event is tied to a session UUID
 * and carries a {@see CheckoutEvent} value that advances the server-side
 * checkout state machine and attributes the order to the session.
 *
 * `create()` opens the funnel for a session with the `checkout_visited` event
 * (the SDK sets that event for you); `update()` records every subsequent event
 * for the same session. Typical sequence:
 *
 *  1. `create($session, ['order_total' => 99.0, ...])` — prospect reaches checkout.
 *  2. `update($session, CheckoutEvent::UpsellOffered, ['product_id' => ..., 'product_name' => ...])`.
 *  3. `update($session, CheckoutEvent::UpsellAccepted, [...])` *or* `CheckoutEvent::UpsellDeclined`.
 *  4. `update($session, CheckoutEvent::OrderPlaced, [...])` *or* `CheckoutEvent::OrderDeclined`.
 *
 * `payment_method` is one of `apple_pay`, `google_pay`, `card`, or `paypal`. The
 * full request schema (including the optional `upsell` array on create) is in the
 * API reference in your AsterMD dashboard under `/checkout-events/*`.
 */
final class CheckoutEvents extends AbstractResource
{
    /**
     * Opens the checkout funnel for a session by recording the `checkout_visited` event.
     *
     * Call this once when the prospect reaches the checkout page. The SDK sets
     * `event: checkout_visited` and the session, and defaults `currency` to `USD`
     * when you do not supply one. All other fields are optional and forwarded as
     * given. Inspect `$response->data()` for the created checkout-event record.
     *
     * @param string               $session the session UUID to associate the checkout event with
     * @param array<string, mixed> $data    optional checkout fields, forwarded as-is. Known top-level keys:
     *                                       `order_value` (float — value before tax/shipping),
     *                                       `order_total` (float — total including tax/shipping),
     *                                       `payment_method` (string — `apple_pay`|`google_pay`|`card`|`paypal`),
     *                                       `discount_code` (string),
     *                                       `currency` (string ISO 4217; defaults to `USD` when omitted/empty),
     *                                       `upsell` (array of objects). Full schema in the
     *                                       API reference under `/checkout-events/create`.
     *
     * @return Response the created checkout-event envelope; `data()` contains the new record
     *
     * @throws \AsterMD\Sdk\Exception\ValidationException if required fields are missing or malformed
     * @throws \AsterMD\Sdk\Exception\ApiException        on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException  on a network-level failure
     */
    public function create(string $session, array $data = []): Response
    {
        $body = ['session' => $session, 'event' => CheckoutEvent::CheckoutVisited->value] + $data;

        if (!isset($body['currency']) || $body['currency'] === '') {
            $body['currency'] = 'USD';
        }

        return $this->transport->send('sales', 'POST', '/checkout-events/create', body: $body);
    }

    /**
     * Records a subsequent checkout-funnel event for an existing session.
     *
     * Call this for every event after the initial `create()`: each upsell offer
     * and its outcome, then the final order result. The SDK sends the chosen
     * `$event` value; the conditional fields it requires depend on the event:
     * upsell events (`UpsellOffered`/`UpsellAccepted`/`UpsellDeclined`) expect
     * `product_id` and `product_name`, while order events (`OrderPlaced`/
     * `OrderDeclined`) expect `order_value`, `order_total`, `payment_method`,
     * `currency`, and `provider_order_id`. The SDK forwards `$data` as-is and does
     * not validate which fields a given event needs — the server enforces that.
     *
     * @param string               $session the session UUID whose checkout funnel to advance (sent as the path id)
     * @param CheckoutEvent         $event   the funnel event to record (any value except `CheckoutVisited`, which `create()` owns)
     * @param array<string, mixed> $data    event fields, forwarded as-is. Known top-level keys:
     *                                       `product_id` (string ID — upsell events),
     *                                       `product_name` (string — upsell events),
     *                                       `order_value` (float — order events),
     *                                       `order_total` (float — order events),
     *                                       `payment_method` (string — `apple_pay`|`google_pay`|`card`|`paypal`),
     *                                       `currency` (string ISO 4217 — order events),
     *                                       `provider_order_id` (list<string> — payment provider order ids),
     *                                       `opportunity_id` (string — optional CRM opportunity id),
     *                                       `payment` (array, optional — order events; the settled payment method.
     *                                       Shape: `type` (`paypal`|`apple_pay`|`gpay`|`credit_card`|`pre_paid`),
     *                                       `pre_auth` (bool), `pre_auth_qa` (bool, optional),
     *                                       `pre_auth_amount` (number, optional), `card` (array, optional — `type`
     *                                       one of `amex`|`visa`|`mastercard`|`discover`|`diners_club`|`jcb`, `bin`
     *                                       optional, `exp` required)). Full schema in the
     *                                       API reference under `/checkout-events/update/{session_id}`.
     *
     * @return Response the updated checkout-event envelope
     *
     * @throws \AsterMD\Sdk\Exception\NotFoundException   if no checkout event exists for the given session
     * @throws \AsterMD\Sdk\Exception\ValidationException if required fields for the event are missing or malformed
     * @throws \AsterMD\Sdk\Exception\ApiException        on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException  on a network-level failure
     */
    public function update(string $session, CheckoutEvent $event, array $data = []): Response
    {
        return $this->transport->send(
            'sales',
            'PUT',
            '/checkout-events/update/{session_id}',
            pathParams: ['session_id' => $session],
            body: ['event' => $event->value] + $data,
        );
    }
}
