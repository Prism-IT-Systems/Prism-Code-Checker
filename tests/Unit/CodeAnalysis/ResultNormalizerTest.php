<?php

namespace Tests\Unit\CodeAnalysis;

use App\CodeAnalysis\DTO\AnalysisIssue;
use App\CodeAnalysis\Services\ResultNormalizer;
use App\Models\Scan;
use Tests\TestCase;

class ResultNormalizerTest extends TestCase
{
    public function test_it_normalizes_severity_aliases(): void
    {
        $normalizer = new ResultNormalizer;

        $this->assertSame('critical', $normalizer->normalizeSeverity('FATAL'));
        $this->assertSame('error', $normalizer->normalizeSeverity('ERR'));
        $this->assertSame('warning', $normalizer->normalizeSeverity('warn'));
        $this->assertSame('notice', $normalizer->normalizeSeverity('note'));
        $this->assertSame('info', $normalizer->normalizeSeverity('message'));
    }

    public function test_it_counts_severities(): void
    {
        $normalizer = new ResultNormalizer;

        $counts = $normalizer->countSeverities([
            new AnalysisIssue('a.php', 1, null, 'critical', 'PHP Lint', 'syntax', 'Broken'),
            new AnalysisIssue('b.php', 2, null, 'error', 'PHPStan', 'x', 'Bad'),
            new AnalysisIssue('c.php', 3, null, 'warning', 'PHPCS', 'y', 'Warn'),
            new AnalysisIssue('d.php', 4, null, 'notice', 'Composer', 'z', 'Note'),
        ]);

        $this->assertSame(1, $counts['critical']);
        $this->assertSame(1, $counts['error']);
        $this->assertSame(1, $counts['warning']);
        $this->assertSame(1, $counts['notice']);
    }

    public function test_scan_blocking_uses_configured_severities(): void
    {
        $scan = new Scan([
            'critical_count' => 0,
            'error_count' => 2,
            'warning_count' => 5,
            'notice_count' => 1,
            'info_count' => 0,
        ]);

        $this->assertTrue($scan->isBlocking());
        $this->assertSame('FIX REQUIRED', $scan->resultLabel());

        $scan->error_count = 0;
        $this->assertFalse($scan->isBlocking());
        $this->assertSame('READY TO PUSH', $scan->resultLabel());
    }
}
