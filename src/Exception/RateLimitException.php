<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Exception;

/**
 * Thrown when the API returns a 429 Too Many Requests response.
 *
 * Indicates that the caller has exceeded the server's rate limit. When the server
 * includes a `Retry-After` header with an integer value, the recommended retry
 * delay in seconds is exposed via `retryAfter()`. Callers should respect this
 * delay before retrying the request. The SDK does not perform automatic back-off
 * or retrying on 429 responses; that is left to the consuming application.
 */
final class RateLimitException extends ApiException
{
    /**
     * @param string               $message     the error message from the response envelope
     * @param int                  $statusCode  the HTTP status code (429)
     * @param array<string, mixed> $envelope    the decoded JSON response body
     * @param int|null             $retryAfter  the value of the `Retry-After` response header in seconds,
     *                                          or `null` if the header was absent or non-numeric
     */
    public function __construct(
        string $message,
        int $statusCode,
        array $envelope,
        private readonly ?int $retryAfter,
    ) {
        parent::__construct($message, $statusCode, $envelope);
    }

    /**
     * Returns the recommended retry delay in seconds as indicated by the server's `Retry-After` header.
     *
     * Returns `null` when the server did not include a `Retry-After` header or when
     * the header value was not a plain integer (the SDK only parses digit-only values).
     * When non-null, callers should wait at least this many seconds before retrying.
     *
     * @return int|null the retry delay in seconds, or `null` if not provided by the server
     */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
