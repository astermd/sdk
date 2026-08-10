<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Resource;

use AsterMD\Sdk\Response;

/**
 * Read-only access to the lab-test catalog on the `sales` service.
 *
 * Lab tests are diagnostic products available for ordering through the AsterMD
 * platform. Use `list()` to present available tests to a prospect and `view()`
 * to fetch the full specification of a specific test when it is selected for
 * inclusion in a treatment or product bundle.
 */
final class LabTests extends AbstractResource
{
    /**
     * Lists available lab tests with optional filtering and pagination.
     *
     * Returns a paginated collection of lab test records. Inspect
     * `$response->meta()` for pagination metadata (e.g. `total`, `per_page`,
     * `current_page`). Use `$query` to filter or paginate the results.
     *
     * @param array<string, scalar> $query filter and pagination parameters (e.g. `page`, `limit`)
     *
     * @return Response the paginated lab-test list; `data()` contains the test records and `meta()` holds pagination info
     *
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function list(array $query = []): Response
    {
        return $this->transport->send('sales', 'GET', '/lab-tests/list', query: $query);
    }

    /**
     * Fetches a single lab test by its ID.
     *
     * Use this to retrieve the full lab test record — name, description, panel
     * details, and associated metadata — for display when a prospect selects a
     * specific test. Inspect `$response->data()` for the test record fields.
     *
     * @param string $id the ID of the lab test
     *
     * @return Response the lab-test envelope; `data()` contains the full record
     *
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if no lab test exists for the given ID
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function view(string $id): Response
    {
        return $this->transport->send('sales', 'GET', '/lab-tests/view/{id}', pathParams: ['id' => $id]);
    }
}
