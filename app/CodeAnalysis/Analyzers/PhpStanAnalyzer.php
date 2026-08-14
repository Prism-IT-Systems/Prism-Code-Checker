<?php

namespace App\CodeAnalysis\Analyzers;

use App\CodeAnalysis\Contracts\AnalyzerInterface;
use App\CodeAnalysis\DTO\AnalysisIssue;
use App\CodeAnalysis\DTO\AnalysisResult;
use App\CodeAnalysis\DTO\ProjectContext;
use App\CodeAnalysis\Services\CommandRunner;
use App\CodeAnalysis\Services\FileBatchProcessor;
use App\CodeAnalysis\Services\PhpStanConfigFactory;
use App\CodeAnalysis\Services\ResultNormalizer;
use App\CodeAnalysis\Services\ScanMemoryGuard;

class PhpStanAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly CommandRunner $commandRunner,
        private readonly ResultNormalizer $normalizer,
        private readonly FileBatchProcessor $batchProcessor,
        private readonly ScanMemoryGuard $memoryGuard,
        private readonly PhpStanConfigFactory $configFactory,
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
        $timeout = (float) config('codechecker.timeouts.phpstan', 300);
        $memoryLimit = (string) config('codechecker.phpstan_memory_limit', '1G');
        $batchSize = max(1, (int) config('codechecker.phpstan_batch_size', 5));
        $issues = [];
        $timedOut = false;
        $executionError = null;

        $batches = $this->batchProcessor->chunk($project->files, $batchSize);

        foreach ($batches as $batch) {
            $batchResult = $this->analyzeBatch($project, $batch, $binary, $autoload, $memoryLimit, $timeout);

            if ($batchResult['timed_out']) {
                $timedOut = true;
                array_push($issues, ...$batchResult['issues']);
                break;
            }

            // Retry file-by-file when a batch crashes or output is truncated.
            if (
                ($batchResult['truncated'] || ($batchResult['error'] !== null && $batchResult['issues'] === []))
                && count($batch) > 1
            ) {
                foreach ($batch as $file) {
                    $fileResult = $this->analyzeBatch($project, [$file], $binary, $autoload, $memoryLimit, $timeout);

                    if ($fileResult['timed_out']) {
                        $timedOut = true;
                        array_push($issues, ...$fileResult['issues']);
                        break 2;
                    }

                    if ($fileResult['error'] !== null && $fileResult['issues'] === []) {
                        $executionError = $fileResult['error'];
                    }

                    array_push($issues, ...$fileResult['issues']);
                }

                continue;
            }

            if ($batchResult['error'] !== null && $batchResult['issues'] === []) {
                $executionError = $batchResult['error'];
            }

            array_push($issues, ...$batchResult['issues']);
        }

        if ($timedOut) {
            return new AnalysisResult(
                tool: $this->name(),
                success: false,
                issues: array_merge($issues, [
                    new AnalysisIssue(
                        file: '.',
                        line: null,
                        column: null,
                        severity: 'warning',
                        tool: $this->name(),
                        rule: 'timeout',
                        message: "PHPStan timed out after {$timeout} seconds.",
                    ),
                ]),
                errorMessage: "PHPStan timed out after {$timeout} seconds.",
                duration: round(microtime(true) - $started, 3),
                meta: ['config' => 'runtime'],
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
                meta: ['config' => 'runtime'],
            );
        }

        return new AnalysisResult(
            tool: $this->name(),
            success: $executionError === null,
            issues: $issues,
            errorMessage: $executionError,
            duration: round(microtime(true) - $started, 3),
            meta: ['config' => 'runtime'],
        );
    }

    /**
     * @param  array<int, string>  $files
     * @return array{issues: array<int, AnalysisIssue>, error: ?string, timed_out: bool, truncated: bool}
     */
    private function analyzeBatch(
        ProjectContext $project,
        array $files,
        string $binary,
        string $autoload,
        string $memoryLimit,
        float $timeout,
    ): array {
        $runtimeConfig = $this->configFactory->make($project, $files);

        try {
            $command = $this->phpstanCommand($binary, $runtimeConfig, $memoryLimit, $autoload);

            $result = $this->commandRunner->runCapturingToFiles($command, $project->path, $timeout);

            if ($result->timedOut) {
                return [
                    'issues' => [],
                    'error' => "PHPStan timed out after {$timeout} seconds.",
                    'timed_out' => true,
                    'truncated' => false,
                ];
            }

            $json = $this->extractJson($result->stdout !== '' ? $result->stdout : $result->stderr);
            $issues = $this->parseJson($json, $project);
            $error = $this->extractExecutionError($json, $result->exitCode, $result->stderr);

            return [
                'issues' => $issues,
                'error' => $error,
                'timed_out' => false,
                'truncated' => $this->isTruncatedOutput($json),
            ];
        } finally {
            if (is_file($runtimeConfig)) {
                @unlink($runtimeConfig);
            }

            $this->memoryGuard->release();
        }
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

        $files = $data['files'] ?? null;

        if (is_array($files)) {
            foreach ($files as $filePath => $fileData) {
                if ($this->isStubFile((string) $filePath)) {
                    continue;
                }

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

        $errorDetails = $data['error_details'] ?? null;

        if (is_array($errorDetails)) {
            foreach ($errorDetails as $filePath => $messages) {
                if ($this->isStubFile((string) $filePath)) {
                    continue;
                }

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

    private function extractExecutionError(string $json, int $exitCode, string $stderr): ?string
    {
        if ($json !== '') {
            $decoded = json_decode($json, true);

            if (is_array($decoded)) {
                $hasFileIssues = ! empty($decoded['files']) || ! empty($decoded['error_details']);

                if (! $hasFileIssues) {
                    foreach (['general_errors', 'raw'] as $key) {
                        if (! empty($decoded[$key]) && is_array($decoded[$key]) && array_is_list($decoded[$key])) {
                            $messages = array_values(array_filter(array_map(
                                static fn ($item) => is_scalar($item) ? trim((string) $item) : '',
                                $decoded[$key]
                            )));

                            if ($messages !== []) {
                                return implode(' ', $messages);
                            }
                        }
                    }

                    if (($decoded['result'] ?? null) === 'failed') {
                        $message = trim((string) ($decoded['message'] ?? ''));

                        return $message !== ''
                            ? $message
                            : 'PHPStan reported a failure without parseable file issues.';
                    }
                }
            }
        }

        if ($exitCode > 1) {
            $message = trim($stderr);

            return $message !== '' ? $message : 'PHPStan failed.';
        }

        return null;
    }

    private function isTruncatedOutput(string $json): bool
    {
        if ($json === '') {
            return false;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) && (($decoded['truncated'] ?? false) === true);
    }

    private function isStubFile(string $path): bool
    {
        $normalized = str_replace('\\', '/', strtolower($path));

        return str_contains($normalized, 'wordpress-stubs.php')
            || str_contains($normalized, 'wordpress-lite-stubs.php');
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

    private function binaryExists(): bool
    {
        $binary = $this->resolveBinary();

        return is_file($binary) || is_file($binary.'.bat') || is_file($binary.'.phar');
    }

    private function resolveBinary(): string
    {
        $phar = base_path('vendor/phpstan/phpstan/phpstan.phar');

        if (is_file($phar)) {
            return $phar;
        }

        $configured = (string) config('codechecker.binaries.phpstan', base_path('vendor/bin/phpstan'));

        if (is_file($configured)) {
            return $configured;
        }

        if (is_file($configured.'.bat')) {
            return $configured.'.bat';
        }

        return $configured;
    }

    /**
     * @return array<int, string>
     */
    private function phpstanCommand(string $binary, string $runtimeConfig, string $memoryLimit, string $autoload): array
    {
        $command = [];

        if (str_ends_with(strtolower($binary), '.phar')) {
            $command[] = (string) config('codechecker.binaries.php', PHP_BINARY);
        }

        $command = array_merge($command, [
            $binary,
            'analyse',
            '--error-format=json',
            '--no-progress',
            '--memory-limit='.$memoryLimit,
            '--configuration='.$runtimeConfig,
        ]);

        if (is_file($autoload)) {
            $command[] = '--autoload-file='.$autoload;
        }

        return $command;
    }
}
