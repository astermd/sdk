<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Exception;

/**
 * Thrown when the API returns an HTTP error response that is not mapped to a more specific subclass.
 *
 * This is the base class for all API-level error exceptions. It is thrown directly
 * by {@see \AsterMD\Sdk\Http\Transport} for any non-2xx HTTP status code that is
 * not 401, 404, 422, or 429 (each of which has its own subclass). Catching this
 * class covers all API-level failures; catching a subclass narrows to a specific
 * error category.
 *
 * The `$statusCode` matches the HTTP response status. The `$envelope` contains the
 * decoded JSON response body, which may include a `message` field with additional
 * context from the server.
 *
 * @phpstan-type Envelope array<string, mixed>
 */
class ApiException extends AsterMDException
{
    /**
     * @param string   $message  the error message from the response envelope, or a generic `"HTTP {status}"` fallback
     * @param int      $statusCode the HTTP status code of the error response
     * @param Envelope $envelope   the decoded JSON response body; may be an empty array if the body was absent or unparseable
     */
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly array $envelope,
    ) {
        parent::__construct($message, $statusCode);
    }

    /**
     * Returns the HTTP status code of the error response.
     *
     * @return int the HTTP status code (e.g. 400, 403, 500)
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Returns the decoded JSON response body from the error response.
     *
     * May contain a `message` key with a human-readable error description from the
     * server, as well as other envelope fields. Returns an empty array when the
     * response body was absent, empty, or could not be decoded as JSON.
     *
     * @return Envelope the decoded response envelope; may be empty
     */
    public function envelope(): array
    {
        return $this->envelope;
    }
}
