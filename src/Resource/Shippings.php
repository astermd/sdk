<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Resource;

use AsterMD\Sdk\Response;

/**
 * Read-only access to shipping option definitions on the `sales` service.
 *
 * Shipping options define the available delivery methods and rates for product
 * orders. Use `list()` to present shipping choices to a prospect at checkout
 * and `view()` to retrieve the full specification of a specific shipping option
 * when it is selected.
 */
final class Shippings extends AbstractResource
{
    /**
     * Lists available shipping options with optional filtering and pagination.
     *
     * Returns a paginated collection of shipping option records. Inspect
     * `$response->meta()` for pagination metadata (e.g. `total`, `per_page`,
     * `current_page`). Use `$query` to filter or paginate the results.
     *
     * @param array<string, scalar> $query filter and pagination parameters (e.g. `page`, `limit`)
     *
     * @return Response the paginated shipping options list; `data()` contains the option records and `meta()` holds pagination info
     *
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function list(array $query = []): Response
    {
        return $this->transport->send('sales', 'GET', '/shippings/list', query: $query);
    }

    /**
     * Fetches a single shipping option by its ID.
     *
     * Use this to retrieve the full details of a shipping option — name, carrier,
     * estimated delivery time, and rate — for display when a prospect selects
     * their preferred delivery method at checkout. Inspect `$response->data()`
     * for the shipping option record.
     *
     * @param string $id the ID of the shipping option
     *
     * @return Response the shipping option envelope; `data()` contains the full record
     *
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if no shipping option exists for the given ID
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function view(string $id): Response
    {
        return $this->transport->send('sales', 'GET', '/shippings/view/{id}', pathParams: ['id' => $id]);
    }
}
