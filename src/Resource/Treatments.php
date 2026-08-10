<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Resource;

use AsterMD\Sdk\Response;

/**
 * Treatment (order) operations on the `sales` service.
 *
 * A treatment is an AsterMD record of a confirmed purchase. The SDK does not
 * handle payment processing itself — it only ingests confirmed orders. Use
 * `create()` when your application has captured the order details and payment
 * has been confirmed by your payment provider. Use `sync()` when orders have
 * already been settled in an external CRM and you need to import them into
 * AsterMD retroactively.
 *
 * The full `TreatmentCreateRequest` schema (fields `external_order_id`,
 * `channel_id`, `integration_id`, `provider_name`, `product_id`, `variant_id`,
 * `patient`, `product`, `raw_order`, etc.) is in the API reference in your
 * AsterMD dashboard under `/treatments/create`.
 */
final class Treatments extends AbstractResource
{
    /**
     * Creates a new treatment record after an external payment has been confirmed.
     *
     * Call this endpoint after your payment provider notifies you of a successful
     * charge. The body shape follows the sales `TreatmentCreateRequest`
     * schema; the full schema is in the API reference in your AsterMD dashboard
     * under `/treatments/create`. Required fields include `external_order_id`,
     * `channel_id`, `integration_id`, `provider_name`, `product_id`, `variant_id`,
     * `patient`, `product`, and `raw_order`. Inspect `$response->data()` for the
     * new treatment ID.
     *
     * @param array<string, mixed> $data treatment fields per the `TreatmentCreateRequest` schema.
     *                                   Known top-level keys:
     *                                   `external_order_id` (string, required),
     *                                   `channel_id` (string ID, required),
     *                                   `integration_id` (string ID, required),
     *                                   `provider_name` (string, required),
     *                                   `product_id` (string ID, required),
     *                                   `variant_id` (string ID, required),
     *                                   `patient` (object, required — patient sub-document),
     *                                   `product` (object, required — product sub-document),
     *                                   `raw_order` (object, required — full raw aggregator payload),
     *                                   `total` (float, optional),
     *                                   `order_status` (string, optional)
     *
     * @return Response the created treatment envelope; `data()` contains the new treatment record
     *
     * @throws \AsterMD\Sdk\Exception\ValidationException if required fields are missing or malformed
     * @throws \AsterMD\Sdk\Exception\ApiException        on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException  on a network-level failure
     */
    public function create(array $data): Response
    {
        return $this->transport->send('sales', 'POST', '/treatments/create', body: $data);
    }

    /**
     * Fetches a treatment record by its ID.
     *
     * Use this to retrieve the full treatment details including order status,
     * linked patient, product sub-documents, and payment information. Inspect
     * `$response->data()` for the record fields.
     *
     * @param string $id the ID of the treatment
     *
     * @return Response the treatment envelope; `data()` contains the full record
     *
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if no treatment exists for the given ID
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function view(string $id): Response
    {
        return $this->transport->send('sales', 'GET', '/treatments/view/{id}', pathParams: ['id' => $id]);
    }

    /**
     * Lists treatment records with optional filtering and pagination.
     *
     * Returns a paginated list of treatments. Inspect `$response->meta()` for
     * pagination metadata (e.g. `total`, `per_page`, `current_page`). Supply
     * `$query` parameters to filter by patient, date range, or status.
     *
     * @param array<string, scalar> $query filter and pagination parameters (e.g. `page`, `limit`, `patient_id`, `order_status`)
     *
     * @return Response the paginated treatment list; `data()` contains the treatment records and `meta()` holds pagination info
     *
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function list(array $query = []): Response
    {
        return $this->transport->send('sales', 'GET', '/treatments/list', query: $query);
    }

    /**
     * Imports orders settled in an external CRM into AsterMD as treatment records.
     *
     * Use this endpoint when orders have been processed by a third-party aggregator
     * and you need the AsterMD EMR to reflect them. The `$session` UUID ties the imported orders to the prospect's
     * journey. Each string in `$orderIds` is the external CRM order identifier as
     * issued by the payment aggregator. Inspect `$response->data()` for the created
     * treatment records.
     *
     * Pass `$userAgent` to attribute the import to the visitor's browser: it is
     * forwarded verbatim as the `User-Agent` request header. Because the SDK runs
     * server-to-server, only the consuming application knows the real value — read
     * it from `$_SERVER['HTTP_USER_AGENT']` and pass it here. When `null` or empty,
     * no `User-Agent` header is added and the HTTP client's default applies.
     *
     * @param string       $session   the session UUID to associate with the imported orders
     * @param list<string> $orderIds  list of external CRM order identifiers to import
     * @param string|null  $utmSource optional marketing attribution source (e.g. `google`, `facebook`);
     *                                 sent as `utm_source` only when provided
     * @param string|null  $userAgent the visitor's browser User-Agent to forward as the `User-Agent`
     *                                 header (e.g. `$_SERVER['HTTP_USER_AGENT']`); `null` or empty omits it
     *
     * @return Response the sync result envelope; `data()` contains the created treatment records
     *
     * @throws \AsterMD\Sdk\Exception\ValidationException if `$orderIds` is empty or malformed
     * @throws \AsterMD\Sdk\Exception\ApiException        on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException  on a network-level failure
     */
    public function sync(string $session, array $orderIds, ?string $utmSource = null, ?string $userAgent = null): Response
    {
        $body = [
            'session_id' => $session,
            'order_ids' => $orderIds,
        ];

        if ($utmSource !== null) {
            $body['utm_source'] = $utmSource;
        }

        $headers = ($userAgent !== null && $userAgent !== '')
            ? ['User-Agent' => $userAgent]
            : [];

        return $this->transport->send('sales', 'POST', '/treatments/sync', body: $body, headers: $headers);
    }
}
