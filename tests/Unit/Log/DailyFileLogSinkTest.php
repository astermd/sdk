<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Log;

use AsterMD\Sdk\Log\DailyFileLogSink;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DailyFileLogSinkTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/astermd-log-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    private function basePath(): string
    {
        return $this->dir . '/sdk.log';
    }

    private function today(): string
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d');
    }

    public function testCreatesDirectoryThatDoesNotYetExist(): void
    {
        self::assertDirectoryDoesNotExist($this->dir);

        new DailyFileLogSink($this->basePath());

        self::assertDirectoryExists($this->dir);
    }

    public function testWritesToADatedFileDerivedFromTheBasePath(): void
    {
        $sink = new DailyFileLogSink($this->basePath());
        $sink('entry one');

        $expected = $this->dir . '/sdk-' . $this->today() . '.log';

        self::assertFileExists($expected);
        self::assertSame('entry one', file_get_contents($expected));
        self::assertFileDoesNotExist($this->basePath());
    }

    public function testCurrentFileReportsTheDatedPath(): void
    {
        $sink = new DailyFileLogSink($this->basePath());

        self::assertSame($this->dir . '/sdk-' . $this->today() . '.log', $sink->currentFile());
    }

    public function testAppendsRatherThanOverwrites(): void
    {
        $sink = new DailyFileLogSink($this->basePath());
        $sink("first\n");
        $sink("second\n");

        self::assertSame("first\nsecond\n", file_get_contents($sink->currentFile()));
    }

    public function testPreservesACustomExtension(): void
    {
        $sink = new DailyFileLogSink($this->dir . '/debug.txt');
        $sink('x');

        self::assertFileExists($this->dir . '/debug-' . $this->today() . '.txt');
    }

    public function testDefaultsToLogExtensionWhenBasePathHasNone(): void
    {
        $sink = new DailyFileLogSink($this->dir . '/astermd');
        $sink('x');

        self::assertFileExists($this->dir . '/astermd-' . $this->today() . '.log');
    }

    public function testPrunesFilesOlderThanTheRetentionWindow(): void
    {
        mkdir($this->dir, 0o755, true);

        $stale   = $this->datedFile('-30 days');
        $edge    = $this->datedFile('-8 days');
        $kept    = $this->datedFile('-3 days');

        new DailyFileLogSink($this->basePath(), retentionDays: 7)('now');

        self::assertFileDoesNotExist($stale);
        self::assertFileDoesNotExist($edge);
        self::assertFileExists($kept);
    }

    public function testKeepsTheFileExactlyOnTheRetentionBoundary(): void
    {
        mkdir($this->dir, 0o755, true);
        $boundary = $this->datedFile('-7 days');

        new DailyFileLogSink($this->basePath(), retentionDays: 7)('now');

        self::assertFileExists($boundary);
    }

    public function testRetentionZeroDisablesPruning(): void
    {
        mkdir($this->dir, 0o755, true);
        $ancient = $this->datedFile('-400 days');

        new DailyFileLogSink($this->basePath(), retentionDays: 0)('now');

        self::assertFileExists($ancient);
    }

    public function testPruningIgnoresUnrelatedFilesInTheSameDirectory(): void
    {
        mkdir($this->dir, 0o755, true);

        $otherStem = $this->dir . '/other-2000-01-01.log';
        $notDated  = $this->dir . '/sdk-notadate.log';
        $wrongExt  = $this->dir . '/sdk-2000-01-01.txt';
        foreach ([$otherStem, $notDated, $wrongExt] as $file) {
            file_put_contents($file, 'x');
        }

        new DailyFileLogSink($this->basePath(), retentionDays: 7)('now');

        self::assertFileExists($otherStem);
        self::assertFileExists($notDated);
        self::assertFileExists($wrongExt);
    }

    public function testPrunesOnlyOncePerInstance(): void
    {
        mkdir($this->dir, 0o755, true);
        $sink = new DailyFileLogSink($this->basePath(), retentionDays: 7);
        $sink('first');

        // A stale file appearing after the first write is not swept up again by
        // this instance — pruning is deliberately a once-per-process cost.
        $lateArrival = $this->datedFile('-90 days');
        $sink('second');

        self::assertFileExists($lateArrival);
    }

    public function testHonoursTheConfiguredTimezoneForTheFileDate(): void
    {
        $tz = new DateTimeZone('Pacific/Kiritimati');
        $sink = new DailyFileLogSink($this->basePath(), timezone: $tz);
        $sink('x');

        $expected = new DateTimeImmutable('now', $tz)->format('Y-m-d');

        self::assertSame($this->dir . '/sdk-' . $expected . '.log', $sink->currentFile());
    }

    public function testRejectsEmptyBasePath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DailyFileLogSink('   ');
    }

    public function testRejectsNegativeRetention(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DailyFileLogSink($this->basePath(), retentionDays: -1);
    }

    public function testRejectsUnwritableDirectory(): void
    {
        mkdir($this->dir, 0o500, true);

        if (is_writable($this->dir)) {
            // Running as root: permission bits are not enforced, so there is
            // nothing meaningful to assert here.
            self::markTestSkipped('Filesystem permissions are not enforced for this user.');
        }

        $this->expectException(InvalidArgumentException::class);

        try {
            new DailyFileLogSink($this->basePath());
        } finally {
            chmod($this->dir, 0o755);
        }
    }

    private function datedFile(string $modifier): string
    {
        $date = new DateTimeImmutable('now', new DateTimeZone('UTC'))->modify($modifier)->format('Y-m-d');
        $path = $this->dir . '/sdk-' . $date . '.log';
        file_put_contents($path, 'old entry');

        return $path;
    }
}
