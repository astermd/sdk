<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Resource;

use AsterMD\Sdk\Response;

/**
 * Opportunity (lead / order-context) record operations on the `sales` service.
 *
 * An opportunity groups one or more tracking sessions under a prospect's declared
 * purchase intent and tracks the lead through its lifecycle. It is typically
 * created after the patient record exists and before the treatment (order) is
 * placed. Opportunities are the primary vehicle for funnel-stage tracking:
 * `new` → `contacted` → `qualified` → `converted` (or `unqualified`/`archived`).
 *
 * Required fields on creation are `first_name` and `email`; the full
 * `OpportunityCreateRequest` definition is in the API reference in your AsterMD
 * dashboard.
 */
final class Opportunities extends AbstractResource
{
    /**
     * Creates a new opportunity record on the sales service.
     *
     * Call this after the prospect has provided basic contact details and you want
     * to capture their intent as a CRM lead. Include a `sessions` key (list of
     * session UUIDs) to link the opportunity to existing tracking sessions for
     * attribution. Inspect `$response->data()` for the new opportunity ID.
     * The full schema is in the API reference in your AsterMD dashboard under
     * `/opportunities/create`.
     *
     * @param array<string, mixed> $data opportunity fields per the `OpportunityCreateRequest` schema.
     *                                   Known top-level keys:
     *                                   `first_name` (string, required),
     *                                   `email` (string email, required),
     *                                   `last_name` (string, optional),
     *                                   `phone` (array{code: string, number: string}, optional),
     *                                   `sessions` (list<string> of session UUIDs, optional),
     *                                   `source` (string, optional),
     *                                   `opportunity_status` (string enum: new|contacted|qualified|unqualified|converted|archived, default new),
     *                                   `marketing_consent` (bool, default false),
     *                                   `communication_consent` (bool, default false)
     *
     * @return Response the created opportunity envelope; `data()` contains the new record including its ID
     *
     * @throws \AsterMD\Sdk\Exception\ValidationException if required fields are missing or malformed
     * @throws \AsterMD\Sdk\Exception\ApiException        on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException  on a network-level failure
     */
    public function create(array $data): Response
    {
        return $this->transport->send('sales', 'POST', '/opportunities/create', body: $data);
    }

    /**
     * Fetches an opportunity record by its ID.
     *
     * Use this to retrieve the full opportunity details including its current
     * lifecycle status, linked sessions, and associated patient information.
     * Inspect `$response->data()` for the record fields.
     *
     * @param string $id the ID of the opportunity
     *
     * @return Response the opportunity envelope; `data()` contains the full record
     *
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if no opportunity exists for the given ID
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function view(string $id): Response
    {
        return $this->transport->send('sales', 'GET', '/opportunities/view/{id}', pathParams: ['id' => $id]);
    }

    /**
     * Updates mutable fields on an existing opportunity record.
     *
     * Partial updates are accepted — only the fields present in `$data` are
     * changed; other fields are left intact. Use the same field names as
     * `create()`. Commonly used to update contact details or attach additional
     * sessions to an opportunity.
     *
     * @param string               $id   the ID of the opportunity to update
     * @param array<string, mixed> $data fields to update; accepts the same keys as `create()` (partial update)
     *
     * @return Response the updated opportunity envelope
     *
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if no opportunity exists for the given ID
     * @throws \AsterMD\Sdk\Exception\ValidationException if the supplied fields are malformed
     * @throws \AsterMD\Sdk\Exception\ApiException        on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException  on a network-level failure
     */
    public function update(string $id, array $data): Response
    {
        return $this->transport->send(
            'sales',
            'PUT',
            '/opportunities/update/{id}',
            pathParams: ['id' => $id],
            body: $data,
        );
    }

    /**
     * Updates the lifecycle status of an opportunity.
     *
     * Sends `{"opportunity_status": "$status"}` as the request body. Note that
     * the wire field name is `opportunity_status` (not `status`), which differs
     * from `Patients::status()` that uses the plain `status` key. Accepted values
     * are: `new`, `contacted`, `qualified`, `unqualified`, `converted`, `archived`.
     *
     * @param string $id     the ID of the opportunity
     * @param string $status the new lifecycle status (e.g. `new`, `qualified`, `converted`)
     *
     * @return Response the updated status envelope
     *
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if no opportunity exists for the given ID
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function status(string $id, string $status): Response
    {
        return $this->transport->send(
            'sales',
            'PATCH',
            '/opportunities/status/{id}',
            pathParams: ['id' => $id],
            body: ['opportunity_status' => $status],
        );
    }
}
