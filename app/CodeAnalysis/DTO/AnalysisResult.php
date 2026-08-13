<?php

namespace App\CodeAnalysis\DTO;

class AnalysisResult
{
    /**
     * @param  array<int, AnalysisIssue>  $issues
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $tool,
        public bool $success,
        public array $issues = [],
        public ?string $errorMessage = null,
        public float $duration = 0.0,
        public array $meta = [],
    ) {}

    public function issueCount(): int
    {
        return count($this->issues);
    }

    public function countBySeverity(string $severity): int
    {
        return count(array_filter(
            $this->issues,
            fn (AnalysisIssue $issue) => $issue->severity === $severity
        ));
    }
}
