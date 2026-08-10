<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Support;

use AsterMD\Sdk\Support\QueryParamCipher;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class QueryParamCipherTest extends TestCase
{
    /** Obviously fake 16-byte AES-128 key (32 hex chars) — never a real key. */
    private const FAKE_KEY = '00112233445566778899aabbccddeeff';

    public function testRoundTripWithExplicitKey(): void
    {
        $params = [
            ['key' => 'aff_id', 'value' => '123'],
            ['key' => 'utm_source', 'value' => 'newsletter'],
        ];

        $token = QueryParamCipher::encrypt($params, self::FAKE_KEY);

        self::assertSame($params, QueryParamCipher::decrypt($token, self::FAKE_KEY));
    }

    public function testEncryptUsesRandomIvSoTokensDiffer(): void
    {
        $params = [['key' => 'aff_id', 'value' => '123']];

        $a = QueryParamCipher::encrypt($params, self::FAKE_KEY);
        $b = QueryParamCipher::encrypt($params, self::FAKE_KEY);

        self::assertNotSame($a, $b, 'A fresh IV per call should yield different tokens.');
        self::assertSame($params, QueryParamCipher::decrypt($a, self::FAKE_KEY));
        self::assertSame($params, QueryParamCipher::decrypt($b, self::FAKE_KEY));
    }

    public function testDecryptEmptyTokenReturnsEmptyList(): void
    {
        self::assertSame([], QueryParamCipher::decrypt('', self::FAKE_KEY));
    }

    public function testDecryptGarbageTokenReturnsEmptyList(): void
    {
        self::assertSame([], QueryParamCipher::decrypt('not-a-real-token!!!', self::FAKE_KEY));
    }

    public function testDecryptTruncatedTokenReturnsEmptyList(): void
    {
        // Fewer than the 16 IV bytes once decoded — cannot carry ciphertext.
        $shortBlob = rtrim(strtr(base64_encode('too-short'), '+/', '-_'), '=');

        self::assertSame([], QueryParamCipher::decrypt($shortBlob, self::FAKE_KEY));
    }

    public function testDecryptWithWrongKeyReturnsEmptyList(): void
    {
        $token = QueryParamCipher::encrypt([['key' => 'aff_id', 'value' => '123']], self::FAKE_KEY);

        self::assertSame([], QueryParamCipher::decrypt($token, 'ffffffffffffffffffffffffffffffff'));
    }

    public function testDecryptDropsMalformedEntriesAndStringifiesValues(): void
    {
        // A valid JSON array, but with mixed entry shapes the defensive re-shape
        // must handle. Built from raw JSON (not encrypt()) so production typing
        // stays strict while we still exercise the untrusted-input path.
        $messyJson = '['
            . '{"key":"good","value":"yes"},'
            . '{"noKey":"dropped"},'
            . '{"key":["nested"],"value":"dropped"},'
            . '{"key":"numeric","value":123},'
            . '{"key":"missing_value"}'
            . ']';

        $token = self::encryptRawJson($messyJson, self::FAKE_KEY);

        self::assertSame(
            [
                ['key' => 'good', 'value' => 'yes'],
                ['key' => 'numeric', 'value' => '123'],
                ['key' => 'missing_value', 'value' => ''],
            ],
            QueryParamCipher::decrypt($token, self::FAKE_KEY),
        );
    }

    public function testDecryptNonArrayJsonReturnsEmptyList(): void
    {
        $token = self::encryptRawJson('"just a string"', self::FAKE_KEY);

        self::assertSame([], QueryParamCipher::decrypt($token, self::FAKE_KEY));
    }

    public function testEncryptRejectsInvalidKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QueryParamCipher::encrypt([['key' => 'a', 'value' => 'b']], 'too-short');
    }

    /**
     * Produces a token from arbitrary plaintext JSON, mirroring the cipher's wire
     * format (aes-128-cbc, random IV prepended, base64url) so tests can feed
     * decrypt() payloads that the strictly-typed encrypt() would reject.
     */
    private static function encryptRawJson(string $json, string $keyHex): string
    {
        $key = (string) hex2bin($keyHex);
        $iv = random_bytes(16);
        $ciphertext = (string) openssl_encrypt($json, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return rtrim(strtr(base64_encode($iv . $ciphertext), '+/', '-_'), '=');
    }
}
