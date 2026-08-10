<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Auth;

use AsterMD\Sdk\Auth\Token;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TokenTest extends TestCase
{
    public function testExposesValueAndExpiry(): void
    {
        $expiry = new DateTimeImmutable('2026-12-31T23:59:59Z');
        $token = new Token('jwt-value', $expiry);

        self::assertSame('jwt-value', $token->value());
        self::assertSame($expiry, $token->expiresAt());
    }

    public function testIsExpiredHonoursPreBufferSeconds(): void
    {
        $expiry = new DateTimeImmutable()->modify('+20 seconds');
        $token = new Token('v', $expiry);

        // 30-second pre-buffer: should be considered expired even though wall-clock isn't past it.
        self::assertTrue($token->isExpired(preBufferSeconds: 30));
        self::assertFalse($token->isExpired(preBufferSeconds: 0));
    }
}
