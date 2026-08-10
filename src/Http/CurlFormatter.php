<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Http;

use Psr\Http\Message\RequestInterface;

/**
 * Converts a PSR-7 request into a copy-pasteable multi-line cURL command.
 *
 * Used exclusively by {@see CurlLoggingClient} to produce human-readable log
 * entries that can be replayed directly in a terminal or imported into tools
 * like Postman. The class has no instance state and exposes a single static
 * method.
 *
 * When a {@see LogRedactor} is supplied the rendered command has its credentials
 * and PHI stripped, so the entry remains structurally faithful but is safe to
 * write to disk or ship to a log aggregator. Without one the command is rendered
 * verbatim and will contain the bearer token.
 */
final class CurlFormatter
{
    /**
     * Renders the given PSR-7 request as a multi-line cURL invocation string.
     *
     * Each header becomes a separate `--header '...'` line. When a request body
     * is present it is appended as `--data '...'` with single-quote characters
     * inside the body shell-escaped via the `'\''` idiom. When there is no body,
     * the trailing backslash continuation is stripped from the last line so the
     * command can be pasted as-is.
     *
     * @param RequestInterface $request  the PSR-7 request to format
     * @param LogRedactor|null $redactor when given, header values and the body are
     *                                   passed through it before rendering; when
     *                                   `null` the request is rendered unredacted
     *
     * @return string the multi-line cURL command, e.g.:
     *                `curl --location --request POST 'https://...' \`
     *                `  --header 'Authorization: Bearer [REDACTED]' \`
     *                `  --data '{"session":"..."}'`
     */
    public static function format(RequestInterface $request, ?LogRedactor $redactor = null): string
    {
        $method = $request->getMethod();
        $url = (string) $request->getUri();

        $lines = ["curl --location --request {$method} '{$url}' \\"];

        $headerLines = [];
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $shown = $redactor?->headerValue($name, $value) ?? $value;
                $headerLines[] = "  --header '{$name}: {$shown}' \\";
            }
        }

        $body = (string) $request->getBody();
        if ($redactor !== null) {
            $body = $redactor->body($body, $redactor->pathOf($request));
        }
        $hasBody = $body !== '';

        if ($hasBody) {
            // Append header lines with continuation backslash
            foreach ($headerLines as $line) {
                $lines[] = $line;
            }
            // Escape single quotes for shell via the '\'' idiom
            $escapedBody = str_replace("'", "'\\''", $body);
            $lines[] = "  --data '{$escapedBody}'";
        } else {
            // Strip trailing backslash from last header line (or the first line if no headers)
            foreach ($headerLines as $i => $line) {
                if ($i === count($headerLines) - 1) {
                    $lines[] = rtrim($line, ' \\');
                } else {
                    $lines[] = $line;
                }
            }

            if ($headerLines === []) {
                // No headers, no body — strip backslash from first line too
                $lines[0] = rtrim($lines[0], ' \\');
            }
        }

        return implode("\n", $lines);
    }
}
