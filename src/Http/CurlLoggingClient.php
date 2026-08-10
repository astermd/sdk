<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Http;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 decorator that logs every outbound request and its response as a cURL command.
 *
 * Wraps any PSR-18 client and forwards all calls to the inner client unchanged,
 * but captures each request and response for debug logging. Each log entry
 * contains a microsecond-precision timestamp, the request rendered as a
 * copy-pasteable cURL command via {@see CurlFormatter}, and the HTTP status
 * plus response body.
 *
 * The decorator is applied in {@see \AsterMD\Sdk\AsterMDClient} BEFORE the
 * client is handed to {@see TokenManager}, so the credential exchange call is
 * logged as well as all resource API calls.
 *
 * By default a {@see LogRedactor} is attached, so bearer tokens, the OAuth2
 * client secret, PHI verification tokens, and patient-endpoint bodies never
 * reach the sink. Passing `$redactor = null` disables that and logs everything
 * verbatim — useful when replaying a request by hand, but it writes live
 * credentials to your sink, so never do it in production.
 *
 * @see CurlFormatter
 * @see LogRedactor
 */
final class CurlLoggingClient implements ClientInterface
{
    /**
     * @param ClientInterface  $inner    The PSR-18 client to delegate actual HTTP calls to.
     * @param \Closure         $sink     Log sink: `fn(string $entry): void`. Called once per request with
     *                                   the formatted log entry. The entry includes the cURL command, the
     *                                   HTTP status, and the response body (or a transport error message).
     * @param DateTimeZone     $timezone Timezone used for the microsecond-precision timestamp prepended to
     *                                   each log entry. Defaults to UTC in {@see \AsterMD\Sdk\AsterMDClient}.
     * @param LogRedactor|null $redactor Strips credentials and PHI from each entry. Pass `null` to log
     *                                   verbatim, including live bearer tokens — never in production.
     */
    public function __construct(
        private readonly ClientInterface $inner,
        private readonly \Closure $sink,
        private readonly DateTimeZone $timezone,
        private readonly ?LogRedactor $redactor = new LogRedactor(),
    ) {
    }

    /**
     * Forwards the request to the inner PSR-18 client, then logs the request and response.
     *
     * The response body stream is read in full for logging. If the stream is
     * seekable it is rewound afterwards so downstream consumers (e.g. Transport's
     * envelope decoder) can read it again. If the stream is not seekable the body
     * is still logged but a warning comment is prepended to indicate the stream
     * position has advanced.
     *
     * On transport-layer exceptions the error is logged before the exception is
     * re-thrown, preserving the normal exception propagation path.
     *
     * @param RequestInterface $request the PSR-7 request to send and log
     *
     * @return ResponseInterface the unmodified PSR-7 response from the inner client
     *
     * @throws \Psr\Http\Client\ClientExceptionInterface re-thrown from the inner client on failure
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $timestamp = $this->timestamp();
        $curl = CurlFormatter::format($request, $this->redactor);

        try {
            $response = $this->inner->sendRequest($request);
        } catch (\Throwable $e) {
            ($this->sink)("[{$timestamp}]\n{$curl}\n\n# Transport error: {$e->getMessage()}\n\n");

            throw $e;
        }

        $status = $response->getStatusCode();
        $body = $this->readBody($response);

        if ($this->redactor !== null) {
            $body = $this->redactor->body($body, $this->redactor->pathOf($request));
        }

        ($this->sink)("[{$timestamp}]\n{$curl}\n\n# Response: HTTP {$status}\n{$body}\n\n");

        return $response;
    }

    private function timestamp(): string
    {
        // DateTimeImmutable() alone truncates microseconds on most setups.
        // Using microtime(true) with sprintf preserves them.
        $dt = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6f', microtime(true)));

        // createFromFormat may return false in theory; fall back gracefully
        if ($dt === false) {
            $dt = new DateTimeImmutable();
        }

        return $dt->setTimezone($this->timezone)->format('Y-m-d H:i:s.u T');
    }

    private function readBody(ResponseInterface $response): string
    {
        $stream = $response->getBody();

        if (!$stream->isReadable()) {
            return '# Response body not seekable — not shown';
        }

        $body = (string) $stream;

        if ($stream->isSeekable()) {
            $stream->rewind();
        } else {
            return "# Response body not seekable — not shown\n{$body}";
        }

        return $body;
    }
}
