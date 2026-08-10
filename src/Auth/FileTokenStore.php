<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Auth;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * File-backed {@see TokenStore} that persists the JWT across PHP requests.
 *
 * Unlike {@see InMemoryTokenStore}, this implementation writes the token as JSON
 * to a file on disk so it survives process boundaries. This makes it suitable for
 * traditional PHP-FPM deployments where a new process is spawned per HTTP request
 * and in-memory caching provides no benefit.
 *
 * ```php
 * $client = new AsterMDClient(
 *     clientId:     '...',
 *     clientSecret: '...',
 *     tokenStore:   new FileTokenStore('/var/cache/astermd/token.json'),
 * );
 * ```
 *
 * The parent directory is created automatically (mode `0755`) if it does not
 * exist. After each write the file is `chmod`'d to `0600` (owner read/write only)
 * to prevent the token from being world-readable on shared hosting environments.
 * The file must be writable by the web-server process and should reside outside
 * the document root. One file per set of API credentials is sufficient.
 *
 * Reads and writes use POSIX advisory file locking (`LOCK_SH` / `LOCK_EX`) so
 * concurrent PHP-FPM workers sharing the same file path do not corrupt each
 * other's writes.
 */
final class FileTokenStore implements TokenStore
{
    /**
     * @param string $path absolute path to the JSON file used for token storage;
     *                     the parent directory will be created if absent
     */
    public function __construct(private readonly string $path)
    {
    }

    /**
     * Reads the cached token from the file, or returns `null` if the file does not exist,
     * is unreadable, or contains invalid JSON.
     *
     * Acquires a shared read lock (`LOCK_SH`) before reading so concurrent workers
     * do not observe a partially written file.
     *
     * @return Token|null the stored token, or `null` if unavailable or malformed
     */
    public function get(): ?Token
    {
        if (!is_file($this->path)) {
            return null;
        }

        $fp = @fopen($this->path, 'r');
        if ($fp === false) {
            return null;
        }

        try {
            flock($fp, LOCK_SH);
            $contents = stream_get_contents($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        if ($contents === false || $contents === '') {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!isset($data['value'], $data['expires_at'])
            || !is_string($data['value'])
            || !is_string($data['expires_at'])
        ) {
            return null;
        }

        try {
            return new Token($data['value'], new DateTimeImmutable($data['expires_at']));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Writes the token to the backing file as JSON, replacing any existing content.
     *
     * Acquires an exclusive write lock (`LOCK_EX`), truncates the file, writes the
     * new content, then `chmod`s the result to `0600`. The lock-then-truncate order
     * ensures no reader ever observes a partial file. Silent no-op if the file cannot
     * be opened for writing.
     *
     * @param Token $token the freshly acquired token to persist
     */
    public function put(Token $token): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, recursive: true);
        }

        $contents = json_encode([
            'value'      => $token->value(),
            'expires_at' => $token->expiresAt()->format(DateTimeInterface::ATOM),
        ], JSON_THROW_ON_ERROR);

        // 'c' opens for writing, creates if absent, does NOT truncate on open.
        // We truncate manually after acquiring the lock so no reader ever sees
        // a partial file between fopen and ftruncate.
        $fp = fopen($this->path, 'c');
        if ($fp === false) {
            return;
        }

        try {
            flock($fp, LOCK_EX);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $contents);
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        // Restrict to owner-only so the token is not world-readable on shared hosts.
        chmod($this->path, 0o600);
    }

    /**
     * Deletes the backing file so the next `get()` call returns `null`.
     *
     * Silent no-op if the file does not exist. Uses `@unlink` to suppress
     * warnings on race-condition deletions by concurrent workers.
     */
    public function clear(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
