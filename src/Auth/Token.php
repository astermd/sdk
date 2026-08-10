<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Auth;

use DateTimeImmutable;

/**
 * Immutable value object representing a JWT bearer token and its server-stated expiry.
 *
 * The `TokenManager` constructs a `Token` after a successful credential exchange and
 * stores it in a `TokenStore`. On subsequent requests, `isExpired()` is called to
 * decide whether to reuse the cached token or acquire a fresh one. All fields are
 * set at construction time and never mutated.
 */
final class Token
{
    /**
     * @param string            $value     the raw JWT string to send as `Authorization: Bearer <value>`
     * @param DateTimeImmutable $expiresAt the expiry timestamp as declared in the token response's
     *                                     `access_token_expiry` field
     */
    public function __construct(
        private readonly string $value,
        private readonly DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * Returns the raw JWT string, suitable for use as a bearer token header value.
     *
     * @return string the JWT, e.g. to use in `Authorization: Bearer <value>`
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Returns the server-stated expiry timestamp of this token.
     *
     * Note that the SDK considers the token expired `$preBufferSeconds` before
     * this time via {@see isExpired()} to guard against clock-skew races.
     *
     * @return DateTimeImmutable the expiry as declared in the token response
     */
    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Determines whether this token should be considered expired and must be replaced.
     *
     * The pre-buffer exists to avoid a race condition where the token is valid when
     * checked but expires in transit before the server processes the request. The
     * default buffer of 30 seconds ensures the token has at least 30 seconds of
     * remaining validity before the SDK trusts it for a new request. Pass a custom
     * value only when you have specific knowledge of clock-skew in your environment.
     *
     * @param int $preBufferSeconds seconds to subtract from the stated expiry when computing
     *                              the effective cutoff; defaults to 30
     *
     * @return bool `true` if the token is at or past its effective expiry and must be refreshed
     */
    public function isExpired(int $preBufferSeconds = 30): bool
    {
        $now = new DateTimeImmutable();
        $cutoff = $this->expiresAt->getTimestamp() - $preBufferSeconds;

        return $now->getTimestamp() >= $cutoff;
    }
}
