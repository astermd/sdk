<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Resource;

use AsterMD\Sdk\Response;

/**
 * Read-only access to sales channel definitions and their product assignments on the `sales` service.
 *
 * A channel represents a storefront or sales context that controls which products
 * are available to a prospect and how they are priced. Channels are typically
 * configured once in the AsterMD admin and referenced by ID in your
 * integration. Use `view()` to retrieve a channel's configuration and
 * `assignedProducts()` to enumerate the purchasable products for a given
 * storefront context.
 */
final class Channels extends AbstractResource
{
    /**
     * Fetches a sales channel definition by its ID.
     *
     * Use this to retrieve a channel's configuration such as its name, associated
     * integrations, and settings. Inspect `$response->data()` for the full channel
     * record.
     *
     * @param string $id the ID of the channel to retrieve
     *
     * @return Response the channel definition envelope; `data()` contains the channel record
     *
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if no channel exists for the given ID
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function view(string $id): Response
    {
        return $this->transport->send('sales', 'GET', '/channels/view/{id}', pathParams: ['id' => $id]);
    }

    /**
     * Fetches the list of products assigned to a sales channel.
     *
     * Returns the products that are available for purchase within the given channel
     * context. Use this to populate a storefront's product listing. Inspect
     * `$response->data()` for the product records.
     *
     * @param string $id the ID of the channel whose products to retrieve
     *
     * @return Response the assigned-products envelope; `data()` contains the product list
     *
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if no channel exists for the given ID
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function assignedProducts(string $id): Response
    {
        return $this->transport->send(
            'sales',
            'GET',
            '/channels/assigned-products/{id}',
            pathParams: ['id' => $id],
        );
    }

    /**
     * Fetches the full detail view of a sales channel by its ID.
     *
     * Returns the same channel identified by `view()` but with the server's
     * expanded detail payload — typically the channel record alongside its
     * embedded integrations, theming, and any related references the EMR
     * resolves server-side. Use this when you need the storefront's complete
     * configuration in one round-trip rather than `view()` + follow-up calls.
     * Inspect `$response->data()` for the detail record.
     *
     * @param string $id the ID of the channel to retrieve
     *
     * @return Response the channel-detail envelope; `data()` contains the expanded record
     *
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if no channel exists for the given ID
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function details(string $id): Response
    {
        return $this->transport->send(
            'sales',
            'GET',
            '/channels/detail/{id}',
            pathParams: ['id' => $id],
        );
    }
}
