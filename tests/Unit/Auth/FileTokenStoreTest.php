<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Auth;

use AsterMD\Sdk\Auth\FileTokenStore;
use AsterMD\Sdk\Auth\Token;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;

final class FileTokenStoreTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir  = sys_get_temp_dir() . '/astermd_test_' . uniqid();
        $this->path = $this->dir . '/token.json';
        mkdir($this->dir, 0o755, recursive: true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    // -------------------------------------------------------------------------
    // get()
    // -------------------------------------------------------------------------

    public function testGetReturnsNullWhenFileAbsent(): void
    {
        $store = new FileTokenStore($this->path);

        self::assertNull($store->get());
    }

    public function testGetReturnsNullForEmptyFile(): void
    {
        file_put_contents($this->path, '');
        $store = new FileTokenStore($this->path);

        self::assertNull($store->get());
    }

    public function testGetReturnsNullForInvalidJson(): void
    {
        file_put_contents($this->path, 'not-json');
        $store = new FileTokenStore($this->path);

        self::assertNull($store->get());
    }

    public function testGetReturnsNullWhenValueFieldMissing(): void
    {
        file_put_contents($this->path, json_encode(['expires_at' => '2099-01-01T00:00:00+00:00']));
        $store = new FileTokenStore($this->path);

        self::assertNull($store->get());
    }

    public function testGetReturnsNullWhenExpiresAtFieldMissing(): void
    {
        file_put_contents($this->path, json_encode(['value' => 'jwt']));
        $store = new FileTokenStore($this->path);

        self::assertNull($store->get());
    }

    public function testGetReturnsNullForMalformedDate(): void
    {
        file_put_contents($this->path, json_encode([
            'value'      => 'jwt',
            'expires_at' => 'not-a-date',
        ]));
        $store = new FileTokenStore($this->path);

        self::assertNull($store->get());
    }

    public function testGetDeserializesValidToken(): void
    {
        $expiry = new DateTimeImmutable('2099-06-01T12:00:00+00:00');
        file_put_contents($this->path, json_encode([
            'value'      => 'test-jwt',
            'expires_at' => $expiry->format(DateTimeInterface::ATOM),
        ]));

        $store = new FileTokenStore($this->path);
        $token = $store->get();

        self::assertNotNull($token);
        self::assertSame('test-jwt', $token->value());
        self::assertSame(
            $expiry->format(DateTimeInterface::ATOM),
            $token->expiresAt()->format(DateTimeInterface::ATOM),
        );
    }

    // -------------------------------------------------------------------------
    // put()
    // -------------------------------------------------------------------------

    public function testPutCreatesFile(): void
    {
        $store = new FileTokenStore($this->path);
        $store->put(new Token('jwt-val', new DateTimeImmutable('+1 hour')));

        self::assertFileExists($this->path);
    }

    public function testPutCreatesParentDirectoryIfAbsent(): void
    {
        $nested = $this->dir . '/deep/nested/token.json';
        $store  = new FileTokenStore($nested);
        $store->put(new Token('jwt-val', new DateTimeImmutable('+1 hour')));

        self::assertFileExists($nested);

        // cleanup nested dirs
        unlink($nested);
        rmdir($this->dir . '/deep/nested');
        rmdir($this->dir . '/deep');
    }

    public function testPutWritesValidJson(): void
    {
        $expiry = new DateTimeImmutable('+1 hour');
        $store  = new FileTokenStore($this->path);
        $store->put(new Token('my-jwt', $expiry));

        $raw  = (string) file_get_contents($this->path);
        /** @var array<string, string> $data */
        $data = json_decode($raw, true);

        self::assertSame('my-jwt', $data['value']);
        self::assertSame($expiry->format(DateTimeInterface::ATOM), $data['expires_at']);
    }

    public function testPutOverwritesPreviousToken(): void
    {
        $store = new FileTokenStore($this->path);
        $store->put(new Token('first', new DateTimeImmutable('+1 hour')));
        $store->put(new Token('second', new DateTimeImmutable('+2 hours')));

        $token = $store->get();
        self::assertNotNull($token);
        self::assertSame('second', $token->value());
    }

    // -------------------------------------------------------------------------
    // clear()
    // -------------------------------------------------------------------------

    public function testClearDeletesFile(): void
    {
        $store = new FileTokenStore($this->path);
        $store->put(new Token('jwt', new DateTimeImmutable('+1 hour')));

        $store->clear();

        self::assertFileDoesNotExist($this->path);
        self::assertNull($store->get());
    }

    public function testClearIsNoOpWhenFileAbsent(): void
    {
        $store = new FileTokenStore($this->path);

        // must not throw
        $store->clear();

        self::assertFileDoesNotExist($this->path);
    }

    // -------------------------------------------------------------------------
    // round-trip
    // -------------------------------------------------------------------------

    public function testRoundTripPreservesTokenExactly(): void
    {
        $expiry   = new DateTimeImmutable('2099-12-31T23:59:59+00:00');
        $original = new Token('round-trip-jwt', $expiry);

        $store = new FileTokenStore($this->path);
        $store->put($original);

        $loaded = $store->get();

        self::assertNotNull($loaded);
        self::assertSame($original->value(), $loaded->value());
        self::assertSame(
            $original->expiresAt()->format(DateTimeInterface::ATOM),
            $loaded->expiresAt()->format(DateTimeInterface::ATOM),
        );
    }

    public function testSeparateInstancesShareTheSameFile(): void
    {
        $writer = new FileTokenStore($this->path);
        $reader = new FileTokenStore($this->path);

        $writer->put(new Token('shared-jwt', new DateTimeImmutable('+1 hour')));

        $token = $reader->get();
        self::assertNotNull($token);
        self::assertSame('shared-jwt', $token->value());
    }
}
