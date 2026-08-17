<?php

namespace App\CodeAnalysis\Services;

/**
 * Relays analyzer progress to whoever started the scan.
 *
 * A full scan of a large project keeps external tools busy for minutes, so
 * callers need a sign of life to tell a slow scan from a dead one.
 */
class ScanProgress
{
    /** @var (callable(string, int, int): void)|null */
    private $reporter = null;

    private float $lastReportAt = 0.0;

    private float $interval = 1.0;

    private string $lastReport = '';

    /**
     * @param  (callable(string, int, int): void)|null  $reporter
     */
    public function using(?callable $reporter, float $interval = 1.0): void
    {
        $this->reporter = $reporter;
        $this->interval = $interval;
        $this->lastReportAt = 0.0;
        $this->lastReport = '';
    }

    public function report(string $tool, int $done, int $total, bool $force = false): void
    {
        if ($this->reporter === null) {
            return;
        }

        $signature = $tool.' '.$done.'/'.$total;

        if ($signature === $this->lastReport) {
            return;
        }

        $now = microtime(true);

        if (! $force && $now - $this->lastReportAt < $this->interval) {
            return;
        }

        $this->lastReportAt = $now;
        $this->lastReport = $signature;

        ($this->reporter)($tool, $done, $total);
    }
}
