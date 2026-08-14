<?php

namespace App\CodeAnalysis\Services;

use App\CodeAnalysis\DTO\AnalysisIssue;

class ResultNormalizer
{
    private const SEVERITIES = ['critical', 'error', 'warning', 'notice', 'info'];

    private readonly IssueClassifier $classifier;

    public function __construct(?IssueClassifier $classifier = null)
    {
        $this->classifier = $classifier ?? new IssueClassifier;
    }

    public function normalizeSeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));

        return match ($severity) {
            'crit', 'fatal', 'critical' => 'critical',
            'err', 'error' => 'error',
            'warn', 'warning' => 'warning',
            'note', 'notice' => 'notice',
            'info', 'information', 'message' => 'info',
            default => in_array($severity, self::SEVERITIES, true) ? $severity : 'warning',
        };
    }

    public function fromArray(array $data): AnalysisIssue
    {
        $tool = (string) ($data['tool'] ?? 'unknown');
        $rule = isset($data['rule']) ? (string) $data['rule'] : null;
        $message = (string) ($data['message'] ?? '');

        $category = isset($data['category']) && in_array($data['category'], IssueClassifier::CATEGORIES, true)
            ? (string) $data['category']
            : $this->classifier->categorize($tool, $rule, $message);

        $severity = $this->classifier->severityFor(
            $category,
            $this->normalizeSeverity((string) ($data['severity'] ?? 'warning'))
        );

        return new AnalysisIssue(
            file: (string) ($data['file'] ?? 'unknown'),
            line: isset($data['line']) ? (int) $data['line'] : null,
            column: isset($data['column']) ? (int) $data['column'] : null,
            severity: $severity,
            tool: $tool,
            rule: $rule,
            message: $message,
            fixable: (bool) ($data['fixable'] ?? false),
            category: $category,
        );
    }

    /**
     * @param  array<int, AnalysisIssue>  $issues
     * @return array{critical:int,error:int,warning:int,notice:int,info:int}
     */
    public function countSeverities(array $issues): array
    {
        $counts = [
            'critical' => 0,
            'error' => 0,
            'warning' => 0,
            'notice' => 0,
            'info' => 0,
        ];

        foreach ($issues as $issue) {
            $severity = $this->normalizeSeverity($issue->severity);
            $counts[$severity] = ($counts[$severity] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  array<int, AnalysisIssue>  $issues
     * @return array<string, int>
     */
    public function countCategories(array $issues): array
    {
        $counts = array_fill_keys(IssueClassifier::CATEGORIES, 0);

        foreach ($issues as $issue) {
            $counts[$issue->category] = ($counts[$issue->category] ?? 0) + 1;
        }

        return $counts;
    }
}
