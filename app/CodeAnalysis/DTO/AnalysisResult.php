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

    /**
     * Same result without its findings, for callers that only need the summary
     * once the findings have been stored.
     */
    public function withoutIssues(): self
    {
        return new self(
            tool: $this->tool,
            success: $this->success,
            issues: [],
            errorMessage: $this->errorMessage,
            duration: $this->duration,
            meta: array_merge($this->meta, ['issue_count' => $this->issueCount()]),
        );
    }

    public function countBySeverity(string $severity): int
    {
        return count(array_filter(
            $this->issues,
            fn (AnalysisIssue $issue) => $issue->severity === $severity
        ));
    }
}
