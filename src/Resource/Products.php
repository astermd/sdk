<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Resource;

use AsterMD\Sdk\Response;

/**
 * Read-only access to the product catalog on the `sales` service.
 *
 * Products are the purchasable items available within the AsterMD platform.
 * Use `list()` to enumerate products for display in a storefront (optionally
 * filtered by category or other criteria) and `view()` to retrieve the full
 * details of a specific product when the prospect selects it.
 */
final class Products extends AbstractResource
{
    /**
     * Lists products from the catalog with optional filtering and pagination.
     *
     * Returns a paginated collection of products. Inspect `$response->meta()` for
     * pagination metadata (e.g. `total`, `per_page`, `current_page`). Use `$query`
     * to filter by category or to request a specific page.
     *
     * @param array<string, scalar> $query filter and pagination parameters (e.g. `page`, `limit`, `category_id`)
     *
     * @return Response the paginated product list; `data()` contains the product records and `meta()` holds pagination info
     *
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function list(array $query = []): Response
    {
        return $this->transport->send('sales', 'GET', '/products/list', query: $query);
    }

    /**
     * Fetches a single product by its ID.
     *
     * Use this to retrieve full product details — name, description, pricing,
     * variants, and associated metadata — for display on a product detail page
     * or at checkout. Inspect `$response->data()` for the product record.
     *
     * @param string $id the ID of the product
     *
     * @return Response the product envelope; `data()` contains the full product record
     *
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if no product exists for the given ID
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function view(string $id): Response
    {
        return $this->transport->send('sales', 'GET', '/products/view/{id}', pathParams: ['id' => $id]);
    }
}
