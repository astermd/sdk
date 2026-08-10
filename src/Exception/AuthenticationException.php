<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Exception;

/**
 * Thrown when the API returns a 401 Unauthorized response that the SDK could not recover from.
 *
 * The {@see \AsterMD\Sdk\Http\Transport} attempts automatic recovery from a 401 by
 * calling {@see \AsterMD\Sdk\Auth\TokenManager::refresh()} once to acquire a fresh
 * token and retrying the original request. If the retry also returns 401 — indicating
 * the credentials themselves are invalid or the token has been revoked — this exception
 * is thrown. It is also thrown directly by {@see \AsterMD\Sdk\Auth\TokenManager}
 * when the credential exchange response body is missing the `access_token` or
 * `access_token_expiry` fields.
 */
final class AuthenticationException extends ApiException
{
}
