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

class PintAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly CommandRunner $commandRunner,
        private readonly ResultNormalizer $normalizer,
        private readonly FileBatchProcessor $batchProcessor,
        private readonly ScanMemoryGuard $memoryGuard,
        private readonly ScanProgress $progress,
        private readonly IssueBudget $budget,
    ) {}

    public function name(): string
    {
        return 'Pint';
    }

    public function supports(ProjectContext $project): bool
    {
        return $project->isLaravel()
            && $project->files !== []
            && $this->binaryExists();
    }

    public function analyze(ProjectContext $project): AnalysisResult
    {
        $started = microtime(true);
        $timeout = (float) config('codechecker.timeouts.pint', 180);
        $issues = [];
        $timedOut = false;
        $truncated = false;
        $total = count($project->files);
        $completed = 0;
        $config = $this->projectConfig($project);

        $this->progress->report($this->name(), 0, $total, true);

        foreach ($this->batchProcessor->chunk($project->files) as $batch) {
            $command = [
                $this->resolveBinary(),
                '--test',
                '--format=json',
            ];

            if ($config !== null) {
                $command[] = '--config='.$config;
            } else {
                $command[] = '--preset=laravel';
            }

            foreach ($batch as $file) {
                $command[] = $file;
            }

            $result = $this->commandRunner->run($command, $project->path, $timeout);

            if ($result->timedOut) {
                $timedOut = true;
                break;
            }

            if ($result->exitCode >= 2 && $this->extractJson($result->stdout.$result->stderr) === '') {
                return $this->failedResult(
                    trim($result->stderr) !== ''
                        ? trim($result->stderr)
                        : 'Pint could not complete the scan.',
                    'execution',
                    $started
                );
            }

            array_push(
                $issues,
                ...$this->parseJson(
                    $result->stdout !== '' ? $result->stdout : $result->stderr,
                    $project
                )
            );

            unset($result);
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
            return $this->failedResult(
                "Pint timed out after {$timeout} seconds.",
                'timeout',
                $started
            );
        }

        if ($truncated) {
            $issues = array_slice($issues, 0, $this->budget->limit());
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
            meta: [
                'standard' => 'Laravel Pint',
                'truncated' => $truncated,
            ],
        );
    }

    /**
     * Pint reports each affected file and the fixers that would change it.
     * Prism stores one formatting finding per fixer and file.
     *
     * @return array<int, AnalysisIssue>
     */
    public function parseJson(string $output, ProjectContext $project): array
    {
        $json = $this->extractJson($output);
        $data = json_decode($json, true);

        if (! is_array($data) || ! is_array($data['files'] ?? null)) {
            return [];
        }

        $issues = [];

        foreach ($data['files'] as $file) {
            if (! is_array($file)) {
                continue;
            }

            $name = $file['name'] ?? $file['path'] ?? null;

            if (! is_string($name)) {
                continue;
            }

            $relative = $project->relativePath($name);
            $fixers = $file['appliedFixers'] ?? $file['fixers'] ?? null;
            $fixers = is_array($fixers) ? $fixers : ['coding-standard'];

            foreach ($fixers as $fixer) {
                $rule = is_string($fixer) ? $fixer : 'coding-standard';
                $issues[] = $this->normalizer->fromArray([
                    'file' => $relative,
                    'line' => null,
                    'column' => null,
                    'severity' => 'notice',
                    'tool' => $this->name(),
                    'rule' => $rule,
                    'message' => "File does not comply with the Laravel coding standard ({$rule}).",
                    'fixable' => true,
                ]);
            }
        }

        return $issues;
    }

    private function projectConfig(ProjectContext $project): ?string
    {
        $candidate = $project->path.DIRECTORY_SEPARATOR.'pint.json';

        return is_file($candidate) ? $candidate : null;
    }

    private function failedResult(
        string $message,
        string $rule,
        float $started,
    ): AnalysisResult {
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
                    rule: $rule,
                    message: $message,
                ),
            ],
            errorMessage: $message,
            duration: round(microtime(true) - $started, 3),
            meta: ['standard' => 'Laravel Pint'],
        );
    }

    private function extractJson(string $output): string
    {
        $start = strpos($output, '{');
        $end = strrpos($output, '}');

        if ($start === false || $end === false || $end < $start) {
            return '';
        }

        return substr($output, $start, $end - $start + 1);
    }

    private function binaryExists(): bool
    {
        $binary = $this->resolveBinary();

        return is_file($binary) || is_file($binary.'.bat');
    }

    private function resolveBinary(): string
    {
        $configured = (string) config(
            'codechecker.binaries.pint',
            base_path('vendor/bin/pint')
        );

        if (is_file($configured)) {
            return $configured;
        }

        if (is_file($configured.'.bat')) {
            return $configured.'.bat';
        }

        return $configured;
    }
}
