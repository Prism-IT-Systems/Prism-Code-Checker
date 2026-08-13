<?php

namespace App\CodeAnalysis\Analyzers;

use App\CodeAnalysis\Contracts\AnalyzerInterface;
use App\CodeAnalysis\DTO\AnalysisIssue;
use App\CodeAnalysis\DTO\AnalysisResult;
use App\CodeAnalysis\DTO\ProjectContext;
use App\CodeAnalysis\Services\CommandRunner;
use App\CodeAnalysis\Services\ResultNormalizer;

class PhpLintAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly CommandRunner $commandRunner,
        private readonly ResultNormalizer $normalizer,
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
        $issues = [];
        $php = config('codechecker.binaries.php', PHP_BINARY);
        $timeout = (float) config('codechecker.timeouts.php_lint', 60);
        $timedOut = false;

        foreach ($project->files as $file) {
            $result = $this->commandRunner->run([$php, '-l', $file], $project->path, $timeout);

            if ($result->timedOut) {
                $timedOut = true;
                $issues[] = new AnalysisIssue(
                    file: $project->relativePath($file),
                    line: null,
                    column: null,
                    severity: 'warning',
                    tool: $this->name(),
                    rule: 'timeout',
                    message: "PHP lint timed out after {$timeout} seconds.",
                );
                continue;
            }

            if ($result->exitCode === 0) {
                continue;
            }

            $parsed = $this->parseOutput($result->output(), $project->relativePath($file));
            $issues[] = $parsed;
        }

        return new AnalysisResult(
            tool: $this->name(),
            success: ! $timedOut,
            issues: $issues,
            errorMessage: $timedOut ? 'One or more files timed out during PHP lint.' : null,
            duration: round(microtime(true) - $started, 3),
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
