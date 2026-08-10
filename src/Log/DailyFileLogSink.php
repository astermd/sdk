<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Log;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Debug-log sink that writes one file per calendar day and prunes old files.
 *
 * The SDK's debug logger emits one entry per HTTP request. Appending those to a
 * single file grows without bound, which is why this sink dates each file and
 * deletes the ones that have aged out — the same arrangement as a conventional
 * daily log rotation, but self-contained so no external rotation tooling is
 * required.
 *
 * You give it a *base path* and it derives the dated filenames from it, keeping
 * the directory, stem, and extension you chose:
 *
 * ```
 * basePath: /var/log/astermd/sdk.log
 *
 * /var/log/astermd/sdk-2026-08-08.log   ← today, appended to
 * /var/log/astermd/sdk-2026-08-07.log
 * /var/log/astermd/sdk-2026-08-02.log
 * /var/log/astermd/sdk-2026-08-01.log   ← deleted once it is 7 days old
 * ```
 *
 * The instance is a callable, so it can be handed straight to the SDK:
 *
 * ```php
 * $client = new AsterMDClient(
 *     clientId:     'your-client-id',
 *     clientSecret: 'your-client-secret',
 *     debug:        true,
 *     debugFile:    '/var/log/astermd/sdk.log',
 *     debugRetentionDays: 7,
 * );
 * ```
 *
 * Pruning inspects the date encoded in each filename rather than the file's
 * modification time, so a file that was merely touched is still removed on
 * schedule and unrelated files sharing the directory are never matched. It runs
 * at most once per instance — on the first write — rather than on every request,
 * which keeps the cost off the hot path.
 *
 * This sink is only used when you let the SDK manage the log file. Supplying your
 * own `debugSink` closure (to forward entries to a log aggregator, a PSR-3
 * logger, or anything else) bypasses it entirely, and retention then becomes that
 * system's responsibility.
 */
final class DailyFileLogSink
{
    private readonly string $directory;
    private readonly string $stem;
    private readonly string $extension;

    private bool $pruned = false;

    /**
     * @param string       $basePath      Undated path the daily filenames are derived from, e.g.
     *                                    `/var/log/astermd/sdk.log`. The parent directory is created
     *                                    (mode `0755`) if it does not already exist. A path with no
     *                                    extension gets `.log`.
     * @param int          $retentionDays Number of days of logs to keep. A file is removed once its
     *                                    date is more than this many days before today. Use `0` to
     *                                    disable pruning and keep every file forever.
     * @param DateTimeZone $timezone      Timezone determining which calendar day an entry belongs to.
     *                                    Pass the same zone used for log timestamps so the filename
     *                                    and the entries inside it agree. Defaults to UTC.
     *
     * @throws \InvalidArgumentException if `$basePath` is empty, if `$retentionDays` is negative, or if
     *                                   the target directory cannot be created or written to
     */
    public function __construct(
        string $basePath,
        private readonly int $retentionDays = 7,
        private readonly DateTimeZone $timezone = new DateTimeZone('UTC'),
    ) {
        if (trim($basePath) === '') {
            throw new InvalidArgumentException('basePath must be a non-empty file path.');
        }

        if ($retentionDays < 0) {
            throw new InvalidArgumentException('retentionDays must be >= 0 (0 disables pruning).');
        }

        $directory = \dirname($basePath);
        $extension = pathinfo($basePath, PATHINFO_EXTENSION);
        $stem = pathinfo($basePath, PATHINFO_FILENAME);

        if ($stem === '') {
            throw new InvalidArgumentException('basePath must include a file name. Got: ' . $basePath);
        }

        if (!is_dir($directory) && !@mkdir($directory, 0o755, recursive: true) && !is_dir($directory)) {
            throw new InvalidArgumentException('Log directory could not be created: ' . $directory);
        }

        if (!is_writable($directory)) {
            throw new InvalidArgumentException('Log directory is not writable: ' . $directory);
        }

        $this->directory = $directory;
        $this->stem = $stem;
        $this->extension = $extension === '' ? 'log' : $extension;
    }

    /**
     * Appends one entry to today's log file, pruning aged-out files on the first call.
     *
     * Writes are made with `FILE_APPEND | LOCK_EX` so concurrent processes cannot
     * interleave partial entries. Pruning happens after the write and only once per
     * instance, so a long-running worker pays for it a single time.
     *
     * @param string $entry the formatted log entry to append
     */
    public function __invoke(string $entry): void
    {
        file_put_contents($this->currentFile(), $entry, FILE_APPEND | LOCK_EX);

        if (!$this->pruned) {
            $this->pruned = true;
            $this->prune();
        }
    }

    /**
     * Returns the absolute path of the file today's entries are written to.
     *
     * Useful in tests and when surfacing "where are my logs" diagnostics. The path
     * changes at midnight in the configured timezone.
     *
     * @return string e.g. `/var/log/astermd/sdk-2026-08-08.log`
     */
    public function currentFile(): string
    {
        $date = new DateTimeImmutable('now', $this->timezone)->format('Y-m-d');

        return $this->directory . \DIRECTORY_SEPARATOR . $this->stem . '-' . $date . '.' . $this->extension;
    }

    /**
     * Deletes dated log files older than the configured retention window.
     *
     * Only files matching this sink's own `{stem}-YYYY-MM-DD.{ext}` pattern are
     * considered, and the date is taken from the filename rather than filesystem
     * metadata. Files that cannot be deleted (permissions, races with another
     * process) are skipped silently — losing a log file is never worth failing the
     * request that produced it.
     */
    private function prune(): void
    {
        if ($this->retentionDays === 0) {
            return;
        }

        $matches = glob($this->directory . \DIRECTORY_SEPARATOR . $this->stem . '-*.' . $this->extension);
        if ($matches === false) {
            return;
        }

        $today = new DateTimeImmutable('now', $this->timezone)->setTime(0, 0);
        $cutoff = $today->modify('-' . $this->retentionDays . ' days');
        $pattern = '/^' . preg_quote($this->stem, '/') . '-(\d{4}-\d{2}-\d{2})\.'
            . preg_quote($this->extension, '/') . '$/';

        foreach ($matches as $file) {
            if (preg_match($pattern, basename($file), $m) !== 1) {
                continue;
            }

            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $m[1], $this->timezone);
            if ($date === false) {
                continue;
            }

            if ($date < $cutoff) {
                @unlink($file);
            }
        }
    }
}
