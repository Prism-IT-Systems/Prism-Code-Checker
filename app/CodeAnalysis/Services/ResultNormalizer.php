<?php

namespace App\CodeAnalysis\Services;

use App\CodeAnalysis\DTO\AnalysisIssue;

class ResultNormalizer
{
    private const SEVERITIES = ['critical', 'error', 'warning', 'notice', 'info'];

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
        return new AnalysisIssue(
            file: (string) ($data['file'] ?? 'unknown'),
            line: isset($data['line']) ? (int) $data['line'] : null,
            column: isset($data['column']) ? (int) $data['column'] : null,
            severity: $this->normalizeSeverity((string) ($data['severity'] ?? 'warning')),
            tool: (string) ($data['tool'] ?? 'unknown'),
            rule: isset($data['rule']) ? (string) $data['rule'] : null,
            message: (string) ($data['message'] ?? ''),
            fixable: (bool) ($data['fixable'] ?? false),
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
}
