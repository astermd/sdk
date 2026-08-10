<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * Encrypts and decrypts URL query-param tokens using AES-128-CBC (a random
 * 16-byte IV prepended to the ciphertext, base64url-encoded).
 *
 * Params are encrypted so browser extensions cannot sniff or rewrite them in
 * transit. The codec is param-agnostic: it carries no knowledge of which params
 * it is transporting. Decryption is intentionally total — any malformed,
 * truncated, or tampered token yields an empty list rather than an exception, so
 * callers can safely fall back to plain (unencrypted) params.
 *
 * The AES key is **never** stored in this SDK. Supply it at every call site from
 * your own configuration (environment variable, secrets manager, etc.), and keep
 * it in sync with whichever service produces the tokens. Contact
 * info@astermd.com to obtain or rotate the key for your organisation.
 *
 * Usage:
 * ```php
 * $params = QueryParamCipher::decrypt($_GET['_amd'] ?? '', $_ENV['ASTERMD_PARAM_KEY']);
 * // => [['key' => 'aff_id', 'value' => '123'], ...]  (or [] on any failure)
 * ```
 *
 * Note on integrity: AES-CBC provides confidentiality but not authentication, so
 * a token cannot be proven unmodified. Treat decrypted params as untrusted input
 * and validate them before use.
 */
final class QueryParamCipher
{
    private const ALGO = 'aes-128-cbc';

    /**
     * Decrypts a query-param token into a list of tracking params.
     *
     * Call this at the storefront entry point with the raw token from the URL and
     * the AES key from your own configuration. The token is base64url-decoded, the
     * leading 16 bytes are taken as the IV, and the remainder is decrypted with
     * AES-128-CBC. The plaintext is expected to be the JSON array
     * `[{key, value}, ...]`, which is re-shaped defensively; malformed entries are
     * dropped. Any failure along the way — bad encoding, wrong/short key,
     * decryption error, non-array JSON — returns an empty list so the caller can
     * fall back to plain params.
     *
     * @param string $token  base64url-encoded `iv(16) . ciphertext`
     * @param string $keyHex 32 hex chars (a 16-byte AES-128 key), supplied by the caller.
     *                       The SDK ships no default key.
     *
     * @return list<array{key: string, value: string}> the decrypted tracking params, or `[]` on any failure
     */
    public static function decrypt(string $token, string $keyHex): array
    {
        if ($token === '' || $keyHex === '') {
            return [];
        }

        $blob = self::base64UrlDecode($token);
        if (strlen($blob) <= 16) {
            return [];
        }

        $key = @hex2bin($keyHex);
        if ($key === false || strlen($key) !== 16) {
            return [];
        }

        $iv = substr($blob, 0, 16);
        $ciphertext = substr($blob, 16);

        $json = openssl_decrypt($ciphertext, self::ALGO, $key, OPENSSL_RAW_DATA, $iv);
        if ($json === false) {
            return [];
        }

        $params = json_decode($json, true);
        if (!is_array($params)) {
            return [];
        }

        // Payload is [{key, value}, ...]; re-shape defensively and drop malformed entries.
        $out = [];
        foreach ($params as $param) {
            if (!is_array($param) || !isset($param['key']) || !is_scalar($param['key'])) {
                continue;
            }

            $value = $param['value'] ?? '';
            $out[] = [
                'key' => (string) $param['key'],
                'value' => is_scalar($value) ? (string) $value : '',
            ];
        }

        return $out;
    }

    /**
     * Encrypts a list of tracking params back into a token.
     *
     * Provided mainly for round-trip tests and local tooling. Generates a fresh
     * random IV on every call and prepends it to the ciphertext before
     * base64url-encoding. Unlike {@see self::decrypt()} this is strict: it raises
     * rather than swallowing key/encoding errors, since a failed encrypt is a
     * programming error, not untrusted input.
     *
     * Keys are discarded and the array is re-indexed before encoding, so the
     * payload is always a JSON array even if the caller supplied an associative
     * array.
     *
     * @param array<array-key, array{key: string, value: string}> $params the tracking params to encrypt
     * @param string                                              $keyHex 32 hex chars (a 16-byte AES-128 key)
     *
     * @return string base64url-encoded `iv(16) . ciphertext`
     *
     * @throws \InvalidArgumentException if `$keyHex` is not a valid 16-byte hex key
     * @throws \RuntimeException         if JSON encoding or encryption fails
     */
    public static function encrypt(array $params, string $keyHex): string
    {
        $key = @hex2bin($keyHex);
        if ($key === false || strlen($key) !== 16) {
            throw new InvalidArgumentException('keyHex must be 32 hex chars (a 16-byte AES-128 key).');
        }

        $json = json_encode(array_values($params));
        if ($json === false) {
            throw new RuntimeException('Failed to JSON-encode query params.');
        }

        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($json, self::ALGO, $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new RuntimeException('Failed to encrypt query params.');
        }

        return self::base64UrlEncode($iv . $ciphertext);
    }

    /**
     * Decodes a base64url string (RFC 4648 §5) into raw bytes.
     *
     * Restores the standard base64 alphabet and re-adds the stripped `=` padding
     * before decoding. Returns an empty string on invalid input so callers can
     * treat it as a decode failure without branching on `false`.
     */
    private static function base64UrlDecode(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad !== 0) {
            $s .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode($s, true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * Encodes raw bytes as a base64url string (RFC 4648 §5) without padding.
     */
    private static function base64UrlEncode(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}
