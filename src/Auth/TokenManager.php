<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Auth;

use AsterMD\Sdk\Config;
use AsterMD\Sdk\Exception\AuthenticationException;
use AsterMD\Sdk\Http\Transport;
use AsterMD\Sdk\Http\UrlBuilder;
use DateTimeImmutable;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Manages the JWT bearer token lifecycle: lazy acquisition, in-memory caching, expiry checks, and refresh.
 *
 * The manager is the single point of contact for bearer-token logic. It uses a
 * {@see Transport} configured without a token provider (so the credential exchange
 * itself is not authenticated) and without a 401-retry callback (which would cause
 * infinite recursion). The token is stored via the injected {@see TokenStore}, which
 * may be in-memory (default) or persistent (file, APCu, Redis).
 *
 * On each call to `bearerToken()`, the manager checks the store for a valid cached
 * token. A token is considered valid if it exists and will not expire within the
 * next `$expiryPreBufferSeconds` seconds (default 30). This pre-buffer prevents
 * clock-skew races where a token is valid at check-time but expires before the
 * server processes the request. If no valid token is found, a fresh one is acquired
 * via the `POST /v1/auth/api-credentials/token` endpoint and stored.
 *
 * When the downstream {@see Transport} receives a 401 response, it invokes
 * `refresh()` once. If a fresh token still results in 401 on the retry,
 * {@see AuthenticationException} is raised.
 */
final class TokenManager
{
    private readonly Transport $tokenTransport;

    /**
     * @param Config                  $config                  SDK credentials and connection settings.
     * @param ClientInterface         $httpClient              PSR-18 client used for the credential exchange.
     * @param RequestFactoryInterface $requestFactory          PSR-17 request factory for building the token request.
     * @param StreamFactoryInterface  $streamFactory           PSR-17 stream factory for the token request body.
     * @param UrlBuilder              $urlBuilder              URL builder shared with the main transport.
     * @param TokenStore              $store                   Storage backend for the cached JWT. Defaults to `InMemoryTokenStore`.
     * @param int                     $expiryPreBufferSeconds  Seconds before stated expiry to treat the token as expired,
     *                                                         guarding against clock-skew. Defaults to 30.
     */
    public function __construct(
        private readonly Config $config,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        UrlBuilder $urlBuilder,
        private readonly TokenStore $store = new InMemoryTokenStore(),
        private readonly int $expiryPreBufferSeconds = 30,
    ) {
        // Token acquisition itself runs through a Transport with no token
        // provider and no 401-retry — recursion would be a bug here.
        $this->tokenTransport = new Transport(
            httpClient: $httpClient,
            requestFactory: $requestFactory,
            streamFactory: $streamFactory,
            urlBuilder: $urlBuilder,
            tokenProvider: static fn (): ?string => null,
            onUnauthorized: static fn (): bool => false,
        );
    }

    /**
     * Returns a valid bearer token, acquiring a fresh one from the API if none is cached or the cached token is expired.
     *
     * If a non-expired token is present in the store, it is returned immediately
     * without making an HTTP call. Otherwise, `POST /v1/auth/api-credentials/token`
     * is called with the configured credentials, and the resulting token is stored
     * before being returned.
     *
     * @return string the raw JWT string, ready to be used as `Authorization: Bearer <value>`
     *
     * @throws \AsterMD\Sdk\Exception\AuthenticationException if the credential exchange returns an
     *                                                         unexpected response shape
     * @throws \AsterMD\Sdk\Exception\TransportException      if the HTTP call for token acquisition fails at the network layer
     */
    public function bearerToken(): string
    {
        $cached = $this->store->get();
        if ($cached !== null && !$cached->isExpired($this->expiryPreBufferSeconds)) {
            return $cached->value();
        }

        return $this->acquire()->value();
    }

    /**
     * Clears the cached token and acquires a fresh one, then returns `true`.
     *
     * Always returns `true` regardless of outcome so it can be wired directly to
     * the {@see Transport}'s `onUnauthorized` callback — `true` signals to Transport
     * that the token has been refreshed and the original request should be retried
     * once. If the re-acquired token results in another 401, Transport raises
     * {@see AuthenticationException} without calling this method again.
     *
     * @return bool always `true`
     *
     * @throws \AsterMD\Sdk\Exception\AuthenticationException if the fresh token acquisition fails
     * @throws \AsterMD\Sdk\Exception\TransportException      if the HTTP call for token acquisition fails
     */
    public function refresh(): bool
    {
        $this->store->clear();
        $this->acquire();

        return true;
    }

    private function acquire(): Token
    {
        $response = $this->tokenTransport->send(
            service: 'auth',
            method: 'POST',
            path: '/api-credentials/token',
            body: [
                'client_id' => $this->config->clientId(),
                'client_secret' => $this->config->clientSecret(),
            ],
        );

        $data = $response->data();
        $value = $data['access_token'] ?? null;
        $expiry = $data['access_token_expiry'] ?? null;

        if (!is_string($value) || !is_string($expiry)) {
            throw new AuthenticationException(
                'Token response missing access_token / access_token_expiry.',
                $response->statusCode(),
                ['data' => $data],
            );
        }

        $token = new Token($value, new DateTimeImmutable($expiry));
        $this->store->put($token);

        return $token;
    }
}
