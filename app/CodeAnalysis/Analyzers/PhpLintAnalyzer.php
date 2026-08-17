<?php

namespace App\CodeAnalysis\Analyzers;

use App\CodeAnalysis\Contracts\AnalyzerInterface;
use App\CodeAnalysis\DTO\AnalysisIssue;
use App\CodeAnalysis\DTO\AnalysisResult;
use App\CodeAnalysis\DTO\CommandResult;
use App\CodeAnalysis\DTO\ProjectContext;
use App\CodeAnalysis\Services\CommandRunner;
use App\CodeAnalysis\Services\IssueBudget;
use App\CodeAnalysis\Services\ResultNormalizer;
use App\CodeAnalysis\Services\ScanProgress;

class PhpLintAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly CommandRunner $commandRunner,
        private readonly ResultNormalizer $normalizer,
        private readonly ScanProgress $progress,
        private readonly IssueBudget $budget,
    ) {}

    public function name(): string
    {
        return 'PHP Lint';
    }

    public function supports(ProjectContext $project): bool
    {
        return $project->files !== [];
    }

    public function analyze(ProjectContext $project): AnalysisResult
    {
        $started = microtime(true);
        $php = config('codechecker.binaries.php', PHP_BINARY);
        $timeout = (float) config('codechecker.timeouts.php_lint', 60);
        $concurrency = (int) config('codechecker.lint_concurrency', 8);
        $total = count($project->files);
        $commands = [];

        foreach ($project->files as $index => $file) {
            $commands[$index] = [$php, '-l', $file];
        }

        $issues = [];
        $timedOut = false;
        $completed = 0;

        $this->progress->report($this->name(), 0, $total, true);

        $this->commandRunner->runPool(
            $commands,
            $project->path,
            $timeout,
            $concurrency,
            function (int $index, CommandResult $result) use ($project, $timeout, $total, &$issues, &$timedOut, &$completed): bool {
                $completed++;
                $this->progress->report($this->name(), $completed, $total);

                $file = $project->files[$index];

                if ($result->timedOut) {
                    $timedOut = true;
                    $issues[$index] = new AnalysisIssue(
                        file: $project->relativePath($file),
                        line: null,
                        column: null,
                        severity: 'warning',
                        tool: $this->name(),
                        rule: 'timeout',
                        message: "PHP lint timed out after {$timeout} seconds.",
                    );
                } elseif ($result->exitCode !== 0) {
                    $issues[$index] = $this->parseOutput($result->output(), $project->relativePath($file));
                }

                return ! $this->budget->isExhausted(count($issues));
            }
        );

        $this->progress->report($this->name(), $completed, $total, true);

        // Pool results arrive out of order; findings follow the file order.
        ksort($issues);
        $issues = array_values($issues);
        $truncated = $this->budget->isExhausted(count($issues));

        if ($truncated) {
            $issues[] = $this->budget->truncationIssue($this->name(), count($issues));
        }

        $errorMessage = match (true) {
            $timedOut => 'One or more files timed out during PHP lint.',
            $truncated => $this->budget->truncationMessage($this->name(), count($issues)),
            default => null,
        };

        return new AnalysisResult(
            tool: $this->name(),
            success: ! $timedOut,
            issues: $issues,
            errorMessage: $errorMessage,
            duration: round(microtime(true) - $started, 3),
            meta: $truncated ? ['truncated' => true] : [],
        );
    }

    public function parseOutput(string $output, string $relativeFile): AnalysisIssue
    {
        $line = null;
        $message = trim($output);

        if (preg_match('/on line (\d+)/i', $output, $matches) === 1) {
            $line = (int) $matches[1];
        }

        if (preg_match('/^(?:Parse error|Fatal error|Errors parsing):\s*(.+?)(?:\s+in\s+.+?\s+on line\s+\d+)?$/im', $output, $matches) === 1) {
            $message = trim($matches[1]);
        } elseif (preg_match('/^(?:Parse error|Fatal error):\s*(.+)$/im', $output, $matches) === 1) {
            $message = trim($matches[1]);
            $message = preg_replace('/\s+in\s+.+\s+on line\s+\d+$/i', '', $message) ?? $message;
        }

        return $this->normalizer->fromArray([
            'file' => $relativeFile,
            'line' => $line,
            'column' => null,
            'severity' => 'critical',
            'tool' => $this->name(),
            'rule' => 'syntax',
            'message' => $message !== '' ? $message : 'PHP syntax error detected.',
            'fixable' => false,
        ]);
    }
}
