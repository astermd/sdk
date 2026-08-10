<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Enum;

use AsterMD\Sdk\Enum\IdentityCheck;
use PHPUnit\Framework\TestCase;

final class IdentityCheckTest extends TestCase
{
    public function testBackingValuesMatchApiContract(): void
    {
        self::assertSame('crosscheck', IdentityCheck::Crosscheck->value);
        self::assertSame('dob_verify', IdentityCheck::DobVerify->value);
        self::assertSame('ssn_verify', IdentityCheck::SsnVerify->value);
    }

    public function testCasesAreExhaustive(): void
    {
        self::assertCount(3, IdentityCheck::cases());
    }
}
