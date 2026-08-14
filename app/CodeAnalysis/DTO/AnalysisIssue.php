<?php

namespace App\CodeAnalysis\DTO;

use App\CodeAnalysis\Services\IssueClassifier;

class AnalysisIssue
{
    public function __construct(
        public string $file,
        public ?int $line,
        public ?int $column,
        public string $severity,
        public string $tool,
        public ?string $rule,
        public string $message,
        public bool $fixable = false,
        public string $category = IssueClassifier::BUG,
    ) {}

    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'column' => $this->column,
            'severity' => $this->severity,
            'tool' => $this->tool,
            'rule' => $this->rule,
            'message' => $this->message,
            'fixable' => $this->fixable,
            'category' => $this->category,
        ];
    }
}
