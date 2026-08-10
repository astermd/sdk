<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Resource;

use AsterMD\Sdk\Response;

/**
 * Read-only access to teleform definitions on the `sales` service.
 *
 * Teleform definitions describe the fields, validation rules, display order, and
 * metadata for a questionnaire or intake form. Fetch a definition before rendering
 * the form in your frontend to ensure you display exactly what the server expects.
 * Submissions are recorded via {@see IntakeSubmissions}, not this resource.
 *
 * Two lookup strategies are available: by ID (`view()`) when you
 * have stored the ID during setup, or by the human-readable `form_json_identifier`
 * slug (`viewByIdentifier()`) which is embedded in teleform embed URLs and is
 * stable across environments.
 */
final class Teleforms extends AbstractResource
{
    /**
     * Fetches a teleform definition by its `form_json_identifier` slug.
     *
     * The `form_json_identifier` is a stable, human-readable slug embedded in
     * teleform embed URLs (e.g. the fragment after `/teleforms/view-url/`). Use
     * this method when your frontend knows the slug from the embed URL rather than
     * the ID. Inspect `$response->data()` for the full form schema.
     *
     * @param string $identifier the `form_json_identifier` slug of the teleform to retrieve
     *
     * @return Response the teleform definition envelope; `data()` contains the form schema
     *
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if no teleform exists for the given identifier
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function viewByIdentifier(string $identifier): Response
    {
        return $this->transport->send(
            'sales',
            'GET',
            '/teleforms/view-url/{form_json_identifier}',
            pathParams: ['form_json_identifier' => $identifier],
        );
    }

    /**
     * Fetches a teleform definition by its ID.
     *
     * Use this method when you have stored the teleform ID (e.g. from an
     * admin setup step) and want to retrieve the full form schema to render in your
     * frontend. Inspect `$response->data()` for the field definitions, validation
     * rules, and metadata.
     *
     * @param string $id the ID of the teleform definition
     *
     * @return Response the teleform definition envelope; `data()` contains the form schema
     *
     * @throws \AsterMD\Sdk\Exception\NotFoundException  if no teleform exists for the given ID
     * @throws \AsterMD\Sdk\Exception\ApiException       on a non-2xx response from the server
     * @throws \AsterMD\Sdk\Exception\TransportException on a network-level failure
     */
    public function view(string $id): Response
    {
        return $this->transport->send(
            'sales',
            'GET',
            '/teleforms/view/{id}',
            pathParams: ['id' => $id],
        );
    }
}
