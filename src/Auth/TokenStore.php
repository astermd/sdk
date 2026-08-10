<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Auth;

/**
 * Pluggable contract for caching the JWT bearer token between SDK calls.
 *
 * The {@see TokenManager} uses a `TokenStore` to avoid re-authenticating on
 * every request. The default implementation, {@see InMemoryTokenStore}, holds
 * the token in a PHP property — it survives only for the lifetime of the current
 * process and is not shared across concurrent workers or HTTP requests.
 *
 * Applications that instantiate {@see \AsterMD\Sdk\AsterMDClient} once per
 * HTTP request (typical in frameworks like Laravel or Symfony) will pay the
 * token-exchange cost on every request unless they substitute a persistent
 * store. Implement this interface backed by APCu, Redis, or a local file (see
 * {@see FileTokenStore}) to share the token across requests and workers.
 *
 * All implementations must be safe to call from a single thread; concurrent-write
 * safety (e.g. file locking) is the implementation's responsibility.
 */
interface TokenStore
{
    /**
     * Retrieves the currently cached token, or `null` if no token is stored.
     *
     * The caller ({@see TokenManager}) will check `isExpired()` on the returned
     * token before using it; the store is not expected to perform expiry checks.
     *
     * @return Token|null the cached token, or `null` if the store is empty
     */
    public function get(): ?Token;

    /**
     * Stores a freshly acquired token, replacing any previously cached value.
     *
     * Called by {@see TokenManager} immediately after a successful credential
     * exchange. Implementations should persist the token durably enough to
     * survive the next call to `get()` in the same or a future request.
     *
     * @param Token $token the token to cache
     */
    public function put(Token $token): void;

    /**
     * Removes any cached token, forcing the next call to `bearerToken()` to re-authenticate.
     *
     * Called by {@see TokenManager::refresh()} when a 401 response indicates the
     * stored token has been invalidated server-side.
     */
    public function clear(): void;
}
