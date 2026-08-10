<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Http;

use Psr\Http\Message\RequestInterface;

/**
 * Strips credentials and protected health information out of debug log entries.
 *
 * Debug logging renders every outbound request as a copy-pasteable cURL command
 * (see {@see CurlFormatter}). Left untouched those entries contain the bearer
 * JWT, the OAuth2 client secret exchanged for it, and — on patient endpoints —
 * PHI. This class is the single place that decides what must never reach a log
 * sink, and it is applied by {@see CurlFormatter} and {@see CurlLoggingClient}
 * whenever redaction is enabled.
 *
 * Redaction is **on by default**: constructing {@see \AsterMD\Sdk\AsterMDClient}
 * with `debug: true` produces safe logs. Pass `debugRedact: false` to opt into
 * fully unredacted output when you genuinely need to replay a request verbatim —
 * never in production.
 *
 * What gets replaced with {@see self::PLACEHOLDER}:
 *
 * - the value of the `Authorization` header (the bearer JWT);
 * - the value of the `x-phi-verification-token` header;
 * - the `client_secret` field in the token-exchange request body;
 * - the `access_token` and `refresh_token` fields in any response body.
 *
 * In addition, request and response bodies are dropped entirely for `/patients/*`
 * paths and for `/extensions/identity-verify`, since those carry PII and PHI —
 * including a Social Security Number in the identity case — that must not appear in
 * logs under any circumstances.
 *
 * Redaction covers headers and bodies only; the request URL is logged verbatim.
 * Endpoints that take their input as a query parameter therefore log that input, so
 * debug entries for `extensions/email-verify`, `extensions/geo-info`,
 * `extensions/geo-blocklist`, and the address endpoints contain the email address or
 * IP address that was checked. Keep that in mind when deciding where debug logs are
 * written and how long they are kept.
 *
 * @see CurlFormatter
 * @see CurlLoggingClient
 */
final class LogRedactor
{
    /** Replacement text substituted for every redacted value. */
    public const PLACEHOLDER = '[REDACTED]';

    /** Marker written in place of a body that was dropped wholesale. */
    public const PHI_PLACEHOLDER = '[REDACTED — PHI endpoint]';

    /**
     * Header names whose values are always replaced, compared case-insensitively.
     *
     * @var list<string>
     */
    private const SENSITIVE_HEADERS = [
        'authorization',
        'x-phi-verification-token',
    ];

    /**
     * JSON field names whose values are always replaced, compared case-insensitively.
     *
     * @var list<string>
     */
    private const SENSITIVE_FIELDS = [
        'client_secret',
        'access_token',
        'refresh_token',
    ];

    /**
     * Returns the loggable form of a single request header value.
     *
     * Sensitive headers keep their name — so the log still shows that the header
     * was sent — but their value is replaced wholesale. For `Authorization` the
     * scheme is preserved (`Bearer [REDACTED]`) because knowing the scheme is
     * useful when debugging and carries no secret.
     *
     * @param string $name  the header name as it appears on the request
     * @param string $value the raw header value
     *
     * @return string the value to write to the log, redacted when the header is sensitive
     */
    public function headerValue(string $name, string $value): string
    {
        if (!in_array(strtolower($name), self::SENSITIVE_HEADERS, true)) {
            return $value;
        }

        // Keep the auth scheme visible ("Bearer"), redact only the credential.
        if (preg_match('/^(\S+)\s+\S/', $value, $m) === 1) {
            return $m[1] . ' ' . self::PLACEHOLDER;
        }

        return self::PLACEHOLDER;
    }

    /**
     * Returns the loggable form of a request or response body.
     *
     * Bodies belonging to a PHI-carrying path are replaced entirely. Every other
     * body has its sensitive JSON fields rewritten in place; the surrounding
     * structure is left intact so the entry stays readable and replayable apart
     * from the redacted values. Non-JSON bodies pass through unchanged except for
     * the same field-level substitution, which is applied textually.
     *
     * @param string $body the raw body as it would otherwise be logged
     * @param string $path the request path, used to detect PHI-carrying endpoints
     *
     * @return string the body to write to the log
     */
    public function body(string $body, string $path): string
    {
        if ($body === '') {
            return $body;
        }

        if ($this->isPhiPath($path)) {
            return self::PHI_PLACEHOLDER;
        }

        return $this->redactFields($body);
    }

    /**
     * Reports whether a request path carries PII/PHI and must have its body dropped.
     *
     * @param string $path the request path (with or without the leading `/v1/{service}` prefix)
     *
     * @return bool true when the path targets a patient endpoint or the identity-verification endpoint
     */
    public function isPhiPath(string $path): bool
    {
        $path = strtolower($path);

        return str_contains($path, '/patients')
            || str_contains($path, '/extensions/identity-verify');
    }

    /**
     * Replaces the value of every sensitive JSON field found in the given text.
     *
     * Operates on the serialised form rather than decoding, so it works for
     * pretty-printed and compact JSON alike and never reorders or reformats a
     * body that is only partially JSON.
     */
    private function redactFields(string $body): string
    {
        foreach (self::SENSITIVE_FIELDS as $field) {
            $pattern = '/("' . preg_quote($field, '/') . '"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i';
            $body = (string) preg_replace($pattern, '$1"' . self::PLACEHOLDER . '"', $body);
        }

        return $body;
    }

    /**
     * Extracts the path from a request for use with {@see self::body()} and {@see self::isPhiPath()}.
     *
     * @param RequestInterface $request the request whose path to inspect
     *
     * @return string the request URI path
     */
    public function pathOf(RequestInterface $request): string
    {
        return $request->getUri()->getPath();
    }
}
