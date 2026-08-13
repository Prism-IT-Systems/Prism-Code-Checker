<?php

namespace App\CodeAnalysis\Analyzers;

use App\CodeAnalysis\Contracts\AnalyzerInterface;
use App\CodeAnalysis\DTO\AnalysisIssue;
use App\CodeAnalysis\DTO\AnalysisResult;
use App\CodeAnalysis\DTO\ProjectContext;
use App\CodeAnalysis\Services\CommandRunner;
use App\CodeAnalysis\Services\FileBatchProcessor;
use App\CodeAnalysis\Services\ResultNormalizer;
use App\CodeAnalysis\Services\ScanMemoryGuard;

class PhpStanAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly CommandRunner $commandRunner,
        private readonly ResultNormalizer $normalizer,
        private readonly FileBatchProcessor $batchProcessor,
        private readonly ScanMemoryGuard $memoryGuard,
    ) {}

    public function name(): string
    {
        return 'PHPStan';
    }

    public function supports(ProjectContext $project): bool
    {
        return $project->files !== [] && $this->binaryExists();
    }

    public function analyze(ProjectContext $project): AnalysisResult
    {
        $started = microtime(true);
        $autoload = $project->path.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

        if ($project->composerAvailable && ! is_file($autoload)) {
            return new AnalysisResult(
                tool: $this->name(),
                success: true,
                issues: [
                    new AnalysisIssue(
                        file: 'composer.json',
                        line: null,
                        column: null,
                        severity: 'warning',
                        tool: $this->name(),
                        rule: 'missing-autoload',
                        message: 'Project Composer dependencies are not installed. Run composer install before performing full static analysis.',
                    ),
                ],
                duration: round(microtime(true) - $started, 3),
            );
        }

        $binary = $this->resolveBinary();
        $timeout = (float) config('codechecker.timeouts.phpstan', 180);
        $config = $this->resolveConfig($project);
        $memoryLimit = (string) config('codechecker.phpstan_memory_limit', '512M');
        $issues = [];
        $timedOut = false;
        $executionError = null;

        foreach ($this->batchProcessor->chunk($project->files) as $batch) {
            $command = [
                $binary,
                'analyse',
                '--error-format=json',
                '--no-progress',
                '--memory-limit='.$memoryLimit,
            ];

            if ($config !== null) {
                $command[] = '--configuration='.$config;
            }

            if (is_file($autoload)) {
                $command[] = '--autoload-file='.$autoload;
            }

            foreach ($batch as $file) {
                $command[] = $file;
            }

            $result = $this->commandRunner->run($command, $project->path, $timeout);

            if ($result->timedOut) {
                $timedOut = true;
                break;
            }

            $json = $this->extractJson($result->stdout !== '' ? $result->stdout : $result->stderr);
            $batchIssues = $this->parseJson($json, $project);

            if ($json !== '' && $batchIssues === []) {
                $decoded = json_decode($json, true);
                if (is_array($decoded) && (($decoded['result'] ?? null) === 'failed' || ($decoded['tool'] ?? null) === 'phpstan')) {
                    $raw = $decoded['raw'] ?? null;
                    $executionError = is_array($raw)
                        ? implode(' ', array_map('strval', $raw))
                        : (string) ($decoded['message'] ?? 'PHPStan reported a failure without parseable file issues.');
                }
            }

            if ($json === '' && $result->exitCode > 1 && $batchIssues === [] && $executionError === null) {
                $executionError = trim($result->stderr) !== '' ? trim($result->stderr) : 'PHPStan failed.';
            }

            array_push($issues, ...$batchIssues);
            unset($result, $json, $batchIssues);
            $this->memoryGuard->release();
        }

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
                        message: "PHPStan timed out after {$timeout} seconds.",
                    ),
                ],
                errorMessage: "PHPStan timed out after {$timeout} seconds.",
                duration: round(microtime(true) - $started, 3),
                meta: ['config' => $config],
            );
        }

        if ($executionError !== null && $issues === []) {
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
                        rule: 'execution',
                        message: $executionError,
                    ),
                ],
                errorMessage: $executionError,
                duration: round(microtime(true) - $started, 3),
                meta: ['config' => $config],
            );
        }

        return new AnalysisResult(
            tool: $this->name(),
            success: true,
            issues: $issues,
            errorMessage: $executionError,
            duration: round(microtime(true) - $started, 3),
            meta: ['config' => $config],
        );
    }

    /**
     * @return array<int, AnalysisIssue>
     */
    public function parseJson(string $json, ProjectContext $project): array
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            return [];
        }

        $issues = [];

        // Standard PHPStan JSON error format.
        $files = $data['files'] ?? null;

        if (is_array($files)) {
            foreach ($files as $filePath => $fileData) {
                $relative = is_string($filePath) ? $project->relativePath($filePath) : 'unknown';
                $messages = is_array($fileData) ? ($fileData['messages'] ?? []) : [];

                foreach ($messages as $message) {
                    if (! is_array($message)) {
                        continue;
                    }

                    $rule = isset($message['identifier'])
                        ? (string) $message['identifier']
                        : (isset($message['tip']) ? (string) $message['tip'] : null);
                    $text = (string) ($message['message'] ?? 'PHPStan issue');

                    $issues[] = $this->normalizer->fromArray([
                        'file' => $relative,
                        'line' => $message['line'] ?? null,
                        'column' => null,
                        'severity' => $this->severityForIssue($rule, $text),
                        'tool' => $this->name(),
                        'rule' => $rule,
                        'message' => $text,
                        'fixable' => false,
                    ]);
                }
            }
        }

        // Cursor/agent-wrapped PHPStan output fallback.
        $errorDetails = $data['error_details'] ?? null;

        if (is_array($errorDetails)) {
            foreach ($errorDetails as $filePath => $messages) {
                $relative = is_string($filePath) ? $project->relativePath($filePath) : 'unknown';

                if (! is_array($messages)) {
                    continue;
                }

                foreach ($messages as $message) {
                    if (! is_array($message)) {
                        continue;
                    }

                    $rule = isset($message['identifier']) ? (string) $message['identifier'] : null;
                    $text = (string) ($message['message'] ?? 'PHPStan issue');

                    $issues[] = $this->normalizer->fromArray([
                        'file' => $relative,
                        'line' => $message['line'] ?? null,
                        'column' => null,
                        'severity' => $this->severityForIssue($rule, $text),
                        'tool' => $this->name(),
                        'rule' => $rule,
                        'message' => $text,
                        'fixable' => false,
                    ]);
                }
            }
        }

        return $issues;
    }

    public function severityForIssue(?string $rule, string $message): string
    {
        $rule = strtolower((string) $rule);
        $message = strtolower($message);

        $criticalRules = [
            'function.notfound',
            'method.notfound',
            'staticmethod.notfound',
            'class.notfound',
            'constructor.missingclass',
            'new.unknownclass',
        ];

        if (in_array($rule, $criticalRules, true)) {
            return 'critical';
        }

        if (
            str_contains($message, 'function ') && str_contains($message, ' not found')
            || str_contains($message, 'call to undefined function')
            || str_contains($message, 'class ') && str_contains($message, ' not found')
            || str_contains($message, 'call to an undefined method')
            || str_contains($message, 'call to undefined method')
        ) {
            return 'critical';
        }

        return 'error';
    }

    private function extractJson(string $output): string
    {
        $output = trim($output);

        if ($output === '') {
            return '';
        }

        if (str_starts_with($output, '{')) {
            return $output;
        }

        $start = strpos($output, '{');
        $end = strrpos($output, '}');

        if ($start === false || $end === false || $end < $start) {
            return '';
        }

        return substr($output, $start, $end - $start + 1);
    }

    private function resolveConfig(ProjectContext $project): ?string
    {
        foreach (['phpstan.neon', 'phpstan.neon.dist'] as $file) {
            if ($project->configurationFiles[$file] ?? false) {
                return $project->path.DIRECTORY_SEPARATOR.$file;
            }
        }

        if ($project->isWordPress()) {
            $wordpress = base_path('tools/phpstan/wordpress.neon');
            if (is_file($wordpress)) {
                return $wordpress;
            }
        }

        if ($project->isLaravel()) {
            $laravel = base_path('tools/phpstan/laravel.neon');
            if (is_file($laravel)) {
                return $laravel;
            }
        }

        $default = base_path('tools/phpstan/default.neon');

        return is_file($default) ? $default : null;
    }

    private function binaryExists(): bool
    {
        $binary = $this->resolveBinary();

        return is_file($binary) || is_file($binary.'.bat') || is_file($binary.'.phar');
    }

    private function resolveBinary(): string
    {
        $configured = (string) config('codechecker.binaries.phpstan', base_path('vendor/bin/phpstan'));

        if (is_file($configured)) {
            return $configured;
        }

        if (is_file($configured.'.bat')) {
            return $configured.'.bat';
        }

        return $configured;
    }
}
