<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Resource;

use AsterMD\Sdk\Response;

/**
 * Read-only access to the product category catalog on the `sales` service.
 *
 * Categories group related products together for browsing and filtering. Use
 * `list()` to enumerate all available categories and `view()` to fetch the full
 * details of a specific category when building navigation trees or filtered
 * product listings.
 */
final class Categories extends AbstractResource
{
    /**
     * Lists product categories with optional filtering and pagination.
     *
     * Returns a paginated collection of categories. Inspect `$response->meta()`
     * for pagination metadata (e.g. `total`, `per_page`, `current_page`). Use
     * `$query` to request a specific page or apply server-side filters.
     *
     * @param array<string, scalar> $query filter and pagination parameters (e.g. `page`, `limit`)
     *
     * @return Response the paginated category list; `data()` contains the category records and `meta()` holds pagination info
     *
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function list(array $query = []): Response
    {
        return $this->transport->send('sales', 'GET', '/categories/list', query: $query);
    }

    /**
     * Fetches a single category by its ID.
     *
     * Use this to retrieve a category's name, description, and associated metadata
     * for use in breadcrumb navigation or filtered product listing pages. Inspect
     * `$response->data()` for the full category record.
     *
     * @param string $id the ID of the category
     *
     * @return Response the category envelope; `data()` contains the full category record
     *
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if no category exists for the given ID
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function view(string $id): Response
    {
        return $this->transport->send('sales', 'GET', '/categories/view/{id}', pathParams: ['id' => $id]);
    }
}
