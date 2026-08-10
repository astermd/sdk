<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Http;

/**
 * Builds fully-qualified HTTPS request URLs following the `/v1/{service}/{path}` convention.
 *
 * All AsterMD API endpoints live under `https://{host}/v1/{service}/{path}`, where
 * `{service}` is one of `auth` or `sales`. This class encapsulates that
 * convention so resources never construct URLs directly.
 *
 * Path parameters (e.g. `{id}`) are substituted via `rawurlencode()` and query
 * parameters are appended with `http_build_query()`.
 */
final class UrlBuilder
{
    /**
     * @param string $baseHost bare hostname to prepend (e.g. `api.astermd.com`)
     */
    public function __construct(private readonly string $baseHost)
    {
    }

    /**
     * Constructs the fully-qualified URL for a given service and path.
     *
     * Placeholder tokens in `$path` (e.g. `{id}`, `{form_json_identifier}`) are
     * replaced with the corresponding values from `$pathParams`, URL-encoded via
     * `rawurlencode()`. Any `$query` entries are serialised with `http_build_query()`
     * and appended as a query string.
     *
     * @param string                    $service    Service name (e.g. `sales`, `auth`).
     * @param string                    $path       Path relative to `/v1/{service}` with optional `{placeholder}` tokens.
     * @param array<string, string|int> $pathParams Map of placeholder name → replacement value.
     * @param array<string, scalar>     $query      Query string parameters; non-empty arrays are appended after `?`.
     *
     * @return string fully-qualified URL, e.g. `https://api.astermd.com/v1/sales/sessions/view?session_ids=abc`
     */
    public function build(string $service, string $path, array $pathParams = [], array $query = []): string
    {
        $resolved = $path;
        foreach ($pathParams as $key => $value) {
            $resolved = str_replace('{' . $key . '}', rawurlencode((string) $value), $resolved);
        }

        $url = sprintf('https://%s/v1/%s%s', $this->baseHost, $service, $resolved);

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }
}
