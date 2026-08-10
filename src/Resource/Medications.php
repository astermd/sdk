<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Resource;

use AsterMD\Sdk\Response;

/**
 * Read-only access to the medication reference catalog on the `sales` service.
 *
 * Medications are reference records describing pharmaceutical products available
 * through the AsterMD platform. The public API exposes a list endpoint only; there
 * is no per-medication view endpoint in the current API surface. Use `list()` to
 * enumerate available medications for display or for populating a treatment
 * product selection.
 */
final class Medications extends AbstractResource
{
    /**
     * Lists available medications with optional filtering and pagination.
     *
     * Returns a paginated collection of medication reference records. Inspect
     * `$response->meta()` for pagination metadata (e.g. `total`, `per_page`,
     * `current_page`). Use `$query` to filter by name or other server-supported
     * criteria, or to request a specific page.
     *
     * @param array<string, scalar> $query filter and pagination parameters (e.g. `page`, `limit`)
     *
     * @return Response the paginated medication list; `data()` contains the medication records and `meta()` holds pagination info
     *
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function list(array $query = []): Response
    {
        return $this->transport->send('sales', 'GET', '/medications/list', query: $query);
    }
}
