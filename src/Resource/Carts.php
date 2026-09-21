<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Resource;

use AsterMD\Sdk\Response;

/**
 * Cart lifecycle operations on the `sales` service.
 *
 * A cart holds the list of products a prospect has selected before proceeding to
 * payment. `create()` initialises the cart for a session; `update()` replaces the
 * cart contents for that same session UUID as the prospect changes their selection.
 * The journey event (`cart_initiated` / `cart_update`) is derived server-side and
 * the `channel_id` comes from the API credential, so callers pass neither.
 *
 * Cart items use the keys `product_id`, `name`, and `qty` (all required), plus an
 * optional `variant_id`. The SDK forwards the item list unmodified, so any
 * additional caller-supplied keys reach the wire unchanged.
 *
 * @phpstan-type CartItem array{product_id: string, variant_id?: string, name: string, qty: int}
 */
final class Carts extends AbstractResource
{
    /**
     * Initialises a new cart for the given session.
     *
     * Call this when the prospect first adds items to their cart. Each item in
     * `$items` must contain `product_id` (ID string), `name`, and
     * `qty` (integer), and may optionally include `variant_id` (ID string) when
     * the product has variants. The server derives the `cart_initiated` journey
     * event and resolves the channel from the API credential, so neither is sent.
     * Inspect `$response->data()` for the created cart record.
     *
     * @param string         $session the session UUID to associate the cart with
     * @param list<CartItem> $items   list of items to add; each item requires `product_id`, `name`, and `qty`,
     *                                plus an optional `variant_id`
     *
     * @return Response the created cart envelope; `data()` contains the new cart record (with `status` and `items`)
     *
     * @throws \AsterMD\Sdk\Exception\ValidationException if `$items` is malformed or required fields are missing
     * @throws \AsterMD\Sdk\Exception\ApiException        on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException  on a network-level failure
     */
    public function create(string $session, array $items): Response
    {
        return $this->transport->send(
            'sales',
            'POST',
            '/carts/create',
            body: [
                'session' => $session,
                'items' => $items,
            ],
        );
    }

    /**
     * Replaces the cart contents for an existing session, discarding the previous item list.
     *
     * Call this whenever the prospect changes their product selection after the cart
     * was initially created. The server replaces the stored `items` array in full; it
     * does not merge or append. Pass the complete desired item list, not just the
     * changed items. The `cart_update` journey event is derived server-side.
     *
     * @param string         $session the UUID of the session whose cart to update
     * @param list<CartItem> $items   the replacement item list; each item requires `product_id`, `name`, and `qty`,
     *                                plus an optional `variant_id`
     *
     * @return Response the updated cart envelope
     *
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if no cart exists for the given session UUID
     * @throws \AsterMD\Sdk\Exception\ValidationException if `$items` is malformed or required fields are missing
     * @throws \AsterMD\Sdk\Exception\ApiException        on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException  on a network-level failure
     */
    public function update(string $session, array $items): Response
    {
        return $this->transport->send(
            'sales',
            'PUT',
            '/carts/update/{session}',
            pathParams: ['session' => $session],
            body: [
                'items' => $items,
            ],
        );
    }
}
