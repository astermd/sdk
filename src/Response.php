<?php

declare(strict_types=1);

namespace AsterMD\Sdk;

/**
 * Immutable value object that represents a successful API response with the envelope already decoded.
 *
 * Every AsterMD API response that returns HTTP 2xx is wrapped in a standard
 * JSON envelope of the form:
 *
 * ```json
 * {
 *   "success": true,
 *   "message": "Record created.",
 *   "data":    { ... },
 *   "meta":    { ... }
 * }
 * ```
 *
 * The {@see \AsterMD\Sdk\Http\Transport} decodes this envelope and constructs a
 * `Response` from its fields. The `data` key holds the primary resource payload
 * (e.g. `{"session": "<uuid>"}` for session creation), while `meta` carries
 * pagination cursors and other out-of-band information where applicable.
 * Non-2xx responses are never wrapped in this object; instead they cause
 * {@see \AsterMD\Sdk\Exception\ApiException} (or a subclass) to be thrown.
 *
 * @phpstan-type Decoded array<string, mixed>
 */
final class Response
{
    /**
     * @param int     $statusCode the HTTP status code of the response (200–299)
     * @param Decoded $data       contents of the `data` key in the success envelope
     * @param Decoded $meta       contents of the `meta` key in the success envelope (may be empty)
     * @param string  $message    human-readable `message` string from the envelope
     * @param string  $raw        the raw response body as a string, for debugging
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly array $data,
        private readonly array $meta,
        private readonly string $message,
        private readonly string $raw,
    ) {
    }

    /**
     * Returns the HTTP status code of the response (always 200–299 for a `Response` instance).
     *
     * @return int HTTP status code, e.g. 200 or 201
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Returns the primary resource payload from the `data` key of the success envelope.
     *
     * The shape varies by endpoint. For example, `sessions/create` returns
     * `['session' => '<uuid>']`, while `patients/view` returns the full patient
     * record. Refer to the endpoint-specific PHPDoc on each resource method for
     * the expected shape.
     *
     * @return Decoded the decoded `data` object; may be an empty array if the server returned no data
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * Returns the `meta` key from the success envelope, typically containing pagination information.
     *
     * Present on paginated list endpoints (e.g. `products/list`, `treatments/list`)
     * and may include keys such as `total`, `per_page`, `current_page`, and `last_page`.
     * Returns an empty array for endpoints that do not emit a `meta` block.
     *
     * @return Decoded the decoded `meta` object; empty array if not present
     */
    public function meta(): array
    {
        return $this->meta;
    }

    /**
     * Returns the human-readable `message` string from the success envelope.
     *
     * Typically a short confirmation such as `"Session created."` or `"Record updated."`.
     * May be an empty string if the server omitted the field.
     *
     * @return string the envelope message, may be empty
     */
    public function message(): string
    {
        return $this->message;
    }

    /**
     * Returns the raw response body as received from the server, before any JSON decoding.
     *
     * Useful for low-level debugging. Do not log this value for endpoints that
     * carry PHI (e.g. `patients/health-information`).
     *
     * @return string the raw JSON response body
     */
    public function raw(): string
    {
        return $this->raw;
    }
}
