<?php

namespace App\CodeAnalysis\Analyzers;

use App\CodeAnalysis\Contracts\AnalyzerInterface;
use App\CodeAnalysis\DTO\AnalysisIssue;
use App\CodeAnalysis\DTO\AnalysisResult;
use App\CodeAnalysis\DTO\ProjectContext;
use App\CodeAnalysis\Services\CommandRunner;
use App\CodeAnalysis\Services\IssueBudget;
use App\CodeAnalysis\Services\PhpCsFixerConfigFactory;
use App\CodeAnalysis\Services\ResultNormalizer;
use App\CodeAnalysis\Services\ScanProgress;

class PhpCsFixerAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly CommandRunner $commandRunner,
        private readonly ResultNormalizer $normalizer,
        private readonly PhpCsFixerConfigFactory $configFactory,
        private readonly ScanProgress $progress,
        private readonly IssueBudget $budget,
    ) {}

    public function name(): string
    {
        return 'PHP-CS-Fixer';
    }

    public function supports(ProjectContext $project): bool
    {
        return $project->isCodeIgniter4()
            && $project->files !== []
            && $this->binaryExists();
    }

    public function analyze(ProjectContext $project): AnalysisResult
    {
        $started = microtime(true);
        $timeout = (float) config('codechecker.timeouts.php_cs_fixer', 180);
        $config = $this->configFactory->make($project, $project->files);
        $total = count($project->files);

        $this->progress->report($this->name(), 0, $total, true);

        try {
            $result = $this->commandRunner->run([
                $this->resolveBinary(),
                'fix',
                '--dry-run',
                '--format=json',
                '-vvv',
                '--config='.$config,
            ], $project->path, $timeout);
        } finally {
            if (is_file($config)) {
                @unlink($config);
            }
        }

        if ($result->timedOut) {
            return $this->failedResult(
                "PHP-CS-Fixer timed out after {$timeout} seconds.",
                'timeout',
                $started
            );
        }

        $issues = $this->parseJson(
            $result->stdout !== '' ? $result->stdout : $result->stderr,
            $project
        );

        // Exit code 8 means that dry-run found files requiring fixes. Bits 16
        // and 32 represent configuration or runtime failures.
        if (($result->exitCode & 48) !== 0) {
            $message = trim($result->stderr);

            return $this->failedResult(
                $message !== '' ? $message : 'PHP-CS-Fixer could not complete the scan.',
                'execution',
                $started
            );
        }

        $truncated = $this->budget->isExhausted(count($issues));

        if ($truncated) {
            $issues = array_slice($issues, 0, $this->budget->limit());
            $issues[] = $this->budget->truncationIssue($this->name(), count($issues));
        }

        $this->progress->report($this->name(), $total, $total, true);

        return new AnalysisResult(
            tool: $this->name(),
            success: true,
            issues: $issues,
            errorMessage: $truncated
                ? $this->budget->truncationMessage($this->name(), count($issues))
                : null,
            duration: round(microtime(true) - $started, 3),
            meta: [
                'standard' => 'CodeIgniter official coding standard',
                'truncated' => $truncated,
            ],
        );
    }

    /**
     * PHP-CS-Fixer reports each affected file and the official fixers that
     * would change it. Prism stores one formatting finding per fixer and file.
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
            if (! is_array($file) || ! is_string($file['name'] ?? null)) {
                continue;
            }

            $relative = $project->relativePath($file['name']);
            $fixers = is_array($file['appliedFixers'] ?? null)
                ? $file['appliedFixers']
                : ['coding-standard'];

            foreach ($fixers as $fixer) {
                $rule = is_string($fixer) ? $fixer : 'coding-standard';
                $issues[] = $this->normalizer->fromArray([
                    'file' => $relative,
                    'line' => null,
                    'column' => null,
                    'severity' => 'notice',
                    'tool' => $this->name(),
                    'rule' => $rule,
                    'message' => "File does not comply with the CodeIgniter coding standard ({$rule}).",
                    'fixable' => true,
                ]);
            }
        }

        return $issues;
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
            meta: ['standard' => 'CodeIgniter official coding standard'],
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
            'codechecker.binaries.php_cs_fixer',
            base_path('vendor/bin/php-cs-fixer')
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
