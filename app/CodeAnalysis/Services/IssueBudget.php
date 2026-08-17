<?php

namespace App\CodeAnalysis\Services;

use App\CodeAnalysis\DTO\AnalysisIssue;

/**
 * Caps how many findings one analyzer may collect.
 *
 * A legacy code base can report several hundred thousand formatting findings
 * from a single tool, which exhausts memory before the scan can be stored.
 * Analyzers stop at the limit and report the truncation instead of dying.
 */
class IssueBudget
{
    public function limit(): int
    {
        return max(0, (int) config('codechecker.max_issues_per_analyzer', 40000));
    }

    public function isExhausted(int $collected): bool
    {
        $limit = $this->limit();

        return $limit > 0 && $collected >= $limit;
    }

    public function truncationIssue(string $tool, int $collected): AnalysisIssue
    {
        return new AnalysisIssue(
            file: '.',
            line: null,
            column: null,
            severity: 'warning',
            tool: $tool,
            rule: 'issue-limit',
            message: "{$tool} stopped after {$collected} findings. Exclude generated or legacy folders with a .prismignore file, or raise PRISM_MAX_ISSUES to see the rest.",
        );
    }

    public function truncationMessage(string $tool, int $collected): string
    {
        return "{$tool} reached the {$collected} finding limit; results are partial.";
    }
}
