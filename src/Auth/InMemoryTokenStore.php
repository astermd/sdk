<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Auth;

/**
 * Default in-memory {@see TokenStore} implementation.
 *
 * Stores the JWT in a private PHP property, meaning the token survives only
 * for the lifetime of the current PHP process. In a long-running CLI script
 * or a shared-memory application (Swoole, RoadRunner, FrankenPHP in worker mode)
 * the token will be reused correctly across requests served by the same worker.
 *
 * In a traditional PHP-FPM or Apache mod_php setup where a new process is spawned
 * per HTTP request, this store provides no caching benefit — the SDK will
 * re-authenticate on every request. To share the token across requests, inject a
 * persistent store such as {@see FileTokenStore} or an APCu/Redis-backed
 * implementation when constructing {@see \AsterMD\Sdk\AsterMDClient}.
 */
final class InMemoryTokenStore implements TokenStore
{
    private ?Token $token = null;

    /**
     * Returns the in-memory cached token, or `null` if no token has been stored in this process.
     *
     * @return Token|null the cached token, or `null` if the store is empty
     */
    public function get(): ?Token
    {
        return $this->token;
    }

    /**
     * Stores the token in memory, replacing any previously held value.
     *
     * @param Token $token the freshly acquired token to cache
     */
    public function put(Token $token): void
    {
        $this->token = $token;
    }

    /**
     * Nullifies the in-memory token so the next `get()` call returns `null`.
     */
    public function clear(): void
    {
        $this->token = null;
    }
}
