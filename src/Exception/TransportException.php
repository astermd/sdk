<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Exception;

/**
 * Thrown when the HTTP transport layer fails before any API response is received.
 *
 * This exception indicates a network-level failure: a cURL error, DNS resolution
 * failure, TLS handshake error, connection timeout, or read timeout. It is thrown
 * by {@see \AsterMD\Sdk\Http\NativeCurlClient} when `curl_exec()` returns `false`,
 * and by {@see \AsterMD\Sdk\Http\Transport} when the PSR-18 client raises a
 * `ClientExceptionInterface`. Unlike {@see ApiException}, no HTTP response was
 * received — `envelope()` is not available on this exception.
 *
 * The exception message contains the cURL error string and error code (when
 * thrown by `NativeCurlClient`) or the PSR-18 client's message.
 */
final class TransportException extends AsterMDException
{
}
