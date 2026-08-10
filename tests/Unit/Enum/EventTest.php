<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Enum;

use AsterMD\Sdk\Enum\Event;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    public function testBackingValuesMatchApiContract(): void
    {
        self::assertSame('pre_qualifying_initiated', Event::PreQualifyingInitiated->value);
        self::assertSame('pre_qualifying_inprogress', Event::PreQualifyingInProgress->value);
        self::assertSame('pre_qualifying_completed', Event::PreQualifyingCompleted->value);
        self::assertSame('intake_initiated', Event::IntakeInitiated->value);
        self::assertSame('intake_inprogress', Event::IntakeInProgress->value);
        self::assertSame('intake_completed', Event::IntakeCompleted->value);
    }
}
