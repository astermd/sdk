<?php

declare(strict_types=1);

namespace AsterMD\Sdk;

use InvalidArgumentException;

/**
 * Immutable value object that holds SDK configuration.
 *
 * Stores the OAuth2 credentials and connection settings that every outbound
 * request requires. The object is constructed once inside {@see AsterMDClient}
 * and is never mutated; all getters return copies of primitive values. Validation
 * occurs eagerly in the constructor so misconfigured clients fail at boot time
 * rather than at the first network call.
 *
 * The `baseHost` is a bare hostname (e.g. `api.astermd.com`). The SDK
 * always prepends `https://` and appends `/v1/{service}/{path}` — never pass a
 * full URL here.
 */
final class Config
{
    /**
     * Base URL of the AsterMD asset CDN, including the trailing slash.
     *
     * Relative asset paths returned by the API are resolved against this value by
     * {@see self::assetUrl()}.
     */
    private const ASSET_BASE_URL = 'https://cdn.astermd.com/';

    /**
     * @param string $clientId       OAuth2 client ID issued by AsterMD. Must be non-empty.
     * @param string $clientSecret   OAuth2 client secret corresponding to `$clientId`. Must be non-empty.
     * @param string $baseHost       Bare API hostname without scheme or path (e.g. `api.astermd.com`).
     *                               Passing a value that contains `://` or `/` raises an `InvalidArgumentException`.
     * @param int    $timeoutSeconds Per-request connect and read timeout applied to the underlying HTTP client.
     *                               Must be >= 1. Defaults to 10.
     *
     * @throws \InvalidArgumentException if `$clientId` or `$clientSecret` is empty, if `$baseHost` contains
     *                                   a scheme or path, or if `$timeoutSeconds` is less than 1
     */
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $baseHost = 'api.astermd.com',
        private readonly int $timeoutSeconds = 10,
    ) {
        if ($clientId === '' || $clientSecret === '') {
            throw new InvalidArgumentException('clientId and clientSecret must be non-empty.');
        }

        if (str_contains($baseHost, '://') || str_contains($baseHost, '/')) {
            throw new InvalidArgumentException(
                'baseHost must be a bare host (no scheme, no path). Got: ' . $baseHost,
            );
        }

        if ($timeoutSeconds < 1) {
            throw new InvalidArgumentException('timeoutSeconds must be >= 1.');
        }
    }

    /**
     * Returns the OAuth2 client ID used to acquire JWT bearer tokens.
     *
     * @return string the client ID, guaranteed non-empty
     */
    public function clientId(): string
    {
        return $this->clientId;
    }

    /**
     * Returns the OAuth2 client secret used to acquire JWT bearer tokens.
     *
     * @return string the client secret, guaranteed non-empty
     */
    public function clientSecret(): string
    {
        return $this->clientSecret;
    }

    /**
     * Returns the bare API hostname used to build request URLs.
     *
     * The value never contains a scheme or trailing path. The {@see UrlBuilder}
     * prepends `https://` and appends `/v1/{service}/{path}` at request time.
     *
     * @return string e.g. `api.astermd.com`
     */
    public function baseHost(): string
    {
        return $this->baseHost;
    }

    /**
     * Returns the per-request HTTP timeout configured for this client.
     *
     * Applied as both the connect timeout and the read timeout by the default
     * {@see NativeCurlClient}. Custom PSR-18 clients may use this value at their
     * own discretion.
     *
     * @return int timeout in seconds, always >= 1
     */
    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    /**
     * Builds the fully-qualified CDN URL for a media asset path returned by the AsterMD API.
     *
     * API responses carry relative asset paths (e.g. `{org}/{channel}/products/{file}.png`).
     * Pass such a path here to resolve it against the AsterMD asset CDN.
     *
     * Treat the result as a *fetch* URL, not a *serve* URL: download the asset once,
     * store it on your own filesystem or CDN, and serve your copy to end users. Hot-linking
     * the AsterMD CDN directly from a storefront is not supported and is subject to
     * rate limiting.
     *
     * @param string $path relative asset path as returned by the API (leading slash optional)
     * @return string fully-qualified URL, e.g. `https://cdn.astermd.com/{path}`
     */
    public function assetUrl(string $path): string
    {
        return self::ASSET_BASE_URL . ltrim($path, '/');
    }
}
