<?php

namespace App\CodeAnalysis\Analyzers;

use App\CodeAnalysis\Contracts\AnalyzerInterface;
use App\CodeAnalysis\DTO\AnalysisIssue;
use App\CodeAnalysis\DTO\AnalysisResult;
use App\CodeAnalysis\DTO\ProjectContext;
use App\CodeAnalysis\Services\CommandRunner;
use App\CodeAnalysis\Services\FileBatchProcessor;
use App\CodeAnalysis\Services\IssueBudget;
use App\CodeAnalysis\Services\ResultNormalizer;
use App\CodeAnalysis\Services\ScanMemoryGuard;
use App\CodeAnalysis\Services\ScanProgress;

class WordPressAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly CommandRunner $commandRunner,
        private readonly ResultNormalizer $normalizer,
        private readonly PhpCsAnalyzer $phpCsAnalyzer,
        private readonly FileBatchProcessor $batchProcessor,
        private readonly ScanMemoryGuard $memoryGuard,
        private readonly ScanProgress $progress,
        private readonly IssueBudget $budget,
    ) {}

    public function name(): string
    {
        return 'WordPress';
    }

    public function supports(ProjectContext $project): bool
    {
        return $project->isWordPress() && $project->files !== [];
    }

    public function analyze(ProjectContext $project): AnalysisResult
    {
        $started = microtime(true);
        $binary = $this->resolveBinary();
        $timeout = (float) config('codechecker.timeouts.phpcs', 120);
        $standard = $this->resolveStandard($project);
        $issues = [];
        $timedOut = false;
        $truncated = false;
        $total = count($project->files);
        $completed = 0;

        $this->progress->report($this->name(), 0, $total, true);

        foreach ($this->batchProcessor->chunk($project->files) as $batch) {
            $command = [
                $binary,
                '--report=json',
                '-q',
                '--standard='.$standard,
            ];

            foreach ($batch as $file) {
                $command[] = $file;
            }

            $result = $this->commandRunner->run($command, $project->path, $timeout);

            if ($result->timedOut) {
                $timedOut = true;
                break;
            }

            $batchIssues = $this->phpCsAnalyzer->parseJson(
                $result->stdout !== '' ? $result->stdout : $result->stderr,
                $project
            );

            foreach ($batchIssues as $issue) {
                $issues[] = new AnalysisIssue(
                    file: $issue->file,
                    line: $issue->line,
                    column: $issue->column,
                    severity: $issue->severity,
                    tool: $this->name(),
                    rule: $issue->rule,
                    message: $issue->message,
                    fixable: $issue->fixable,
                    category: $issue->category,
                );
            }

            unset($result, $batchIssues);
            $this->memoryGuard->release();

            $completed += count($batch);
            $this->progress->report($this->name(), $completed, $total);

            if ($this->budget->isExhausted(count($issues))) {
                $truncated = true;
                break;
            }
        }

        $this->progress->report($this->name(), $completed, $total, true);

        if ($timedOut) {
            return new AnalysisResult(
                tool: $this->name(),
                success: false,
                issues: [
                    new AnalysisIssue(
                        file: '.',
                        line: null,
                        column: null,
                        severity: 'warning',
                        tool: $this->name(),
                        rule: 'timeout',
                        message: "WordPress Coding Standards scan timed out after {$timeout} seconds.",
                    ),
                ],
                errorMessage: "WordPress Coding Standards scan timed out after {$timeout} seconds.",
                duration: round(microtime(true) - $started, 3),
                meta: ['standard' => $standard],
            );
        }

        if ($truncated) {
            $issues[] = $this->budget->truncationIssue($this->name(), count($issues));
        }

        return new AnalysisResult(
            tool: $this->name(),
            success: true,
            issues: $issues,
            errorMessage: $truncated
                ? $this->budget->truncationMessage($this->name(), count($issues))
                : null,
            duration: round(microtime(true) - $started, 3),
            meta: ['standard' => $standard, 'truncated' => $truncated],
        );
    }

    private function resolveStandard(ProjectContext $project): string
    {
        foreach (['phpcs.xml', 'phpcs.xml.dist', '.phpcs.xml', '.phpcs.xml.dist'] as $file) {
            if ($project->configurationFiles[$file] ?? false) {
                return $project->path.DIRECTORY_SEPARATOR.$file;
            }
        }

        $wordpress = base_path('tools/phpcs/wordpress.xml');

        return is_file($wordpress) ? $wordpress : 'WordPress';
    }

    private function resolveBinary(): string
    {
        $configured = (string) config('codechecker.binaries.phpcs', base_path('vendor/bin/phpcs'));

        if (is_file($configured)) {
            return $configured;
        }

        if (is_file($configured.'.bat')) {
            return $configured.'.bat';
        }

        return $configured;
    }
}
