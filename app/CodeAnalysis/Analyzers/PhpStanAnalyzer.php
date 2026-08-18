<?php

namespace App\CodeAnalysis\Analyzers;

use App\CodeAnalysis\Contracts\AnalyzerInterface;
use App\CodeAnalysis\DTO\AnalysisIssue;
use App\CodeAnalysis\DTO\AnalysisResult;
use App\CodeAnalysis\DTO\ProjectContext;
use App\CodeAnalysis\Services\CommandRunner;
use App\CodeAnalysis\Services\FileBatchProcessor;
use App\CodeAnalysis\Services\IssueBudget;
use App\CodeAnalysis\Services\PhpStanConfigFactory;
use App\CodeAnalysis\Services\ResultNormalizer;
use App\CodeAnalysis\Services\ScanMemoryGuard;
use App\CodeAnalysis\Services\ScanProgress;

class PhpStanAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly CommandRunner $commandRunner,
        private readonly ResultNormalizer $normalizer,
        private readonly FileBatchProcessor $batchProcessor,
        private readonly ScanMemoryGuard $memoryGuard,
        private readonly PhpStanConfigFactory $configFactory,
        private readonly ScanProgress $progress,
        private readonly IssueBudget $budget,
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
        $batchSize = $this->batchSize(count($project->files));
        $issues = [];
        $timedOut = false;
        $truncated = false;
        $executionError = null;
        $total = count($project->files);
        $completed = 0;

        $this->progress->report($this->name(), 0, $total, true);

        $batches = $this->batchProcessor->chunk($project->files, $batchSize);

        if ((bool) config('codechecker.phpstan_full_run_first', true)) {
            $fullRunTimeout = min(
                $timeout,
                max(1.0, (float) config('codechecker.phpstan_full_run_timeout', 120))
            );
            $fullResult = $this->analyzeBatch(
                $project,
                $project->files,
                $binary,
                $autoload,
                $memoryLimit,
                $fullRunTimeout
            );

            if (! $fullResult['timed_out'] && ! $this->needsRetry($fullResult)) {
                $issues = $fullResult['issues'];
                $executionError = $fullResult['error'];
                $completed = $total;
                $batches = [];
                $this->progress->report($this->name(), $completed, $total, true);

                if ($this->budget->isExhausted(count($issues))) {
                    $limit = $this->budget->limit();
                    $issues = array_slice($issues, 0, $limit);
                    $truncated = true;
                }
            }
        }

        foreach ($batches as $batch) {
            $batchResult = $this->analyzeBatch($project, $batch, $binary, $autoload, $memoryLimit, $timeout);

            if ($batchResult['timed_out']) {
                $timedOut = true;
                array_push($issues, ...$batchResult['issues']);
                break;
            }

            if ($this->needsRetry($batchResult) && count($batch) > 1) {
                $retry = $this->retryBatch($project, $batch, $binary, $autoload, $memoryLimit, $timeout, $completed, $total);

                array_push($issues, ...$retry['issues']);
                $completed = $retry['completed'];

                if ($retry['error'] !== null) {
                    $executionError = $retry['error'];
                }

                if ($retry['timed_out']) {
                    $timedOut = true;
                    break;
                }

                if ($this->budget->isExhausted(count($issues))) {
                    $truncated = true;
                    break;
                }

                continue;
            }

            if ($batchResult['error'] !== null && $batchResult['issues'] === []) {
                $executionError = $batchResult['error'];
            }

            array_push($issues, ...$batchResult['issues']);

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

        if ($truncated) {
            $issues[] = $this->budget->truncationIssue($this->name(), count($issues));
        }

        return new AnalysisResult(
            tool: $this->name(),
            success: $executionError === null,
            issues: $issues,
            errorMessage: $executionError ?? ($truncated
                ? $this->budget->truncationMessage($this->name(), count($issues))
                : null),
            duration: round(microtime(true) - $started, 3),
            meta: ['config' => 'runtime', 'truncated' => $truncated],
        );
    }

    /**
     * A batch whose output is unusable is analyzed again in small chunks, and
     * only a chunk that fails again is split into single files.
     *
     * One file can crash PHPStan and take the findings of everything analyzed
     * with it, so the retry has to get narrow — but narrowing straight to one
     * file per run costs a full start-up per file, which on a large batch takes
     * minutes.
     *
     * @param  array<int, string>  $batch
     * @return array{issues: array<int, AnalysisIssue>, error: ?string, timed_out: bool, completed: int}
     */
    private function retryBatch(
        ProjectContext $project,
        array $batch,
        string $binary,
        string $autoload,
        string $memoryLimit,
        float $timeout,
        int $completed,
        int $total,
    ): array {
        $chunkSize = max(1, (int) config('codechecker.phpstan_batch_size', 5));
        $issues = [];
        $error = null;

        foreach ($this->batchProcessor->chunk($batch, $chunkSize) as $chunk) {
            $result = $this->analyzeBatch($project, $chunk, $binary, $autoload, $memoryLimit, $timeout);

            if ($result['timed_out']) {
                array_push($issues, ...$result['issues']);

                return ['issues' => $issues, 'error' => $error, 'timed_out' => true, 'completed' => $completed];
            }

            if ($this->needsRetry($result) && count($chunk) > 1) {
                foreach ($chunk as $file) {
                    $fileResult = $this->analyzeBatch($project, [$file], $binary, $autoload, $memoryLimit, $timeout);

                    if ($fileResult['timed_out']) {
                        array_push($issues, ...$fileResult['issues']);

                        return ['issues' => $issues, 'error' => $error, 'timed_out' => true, 'completed' => $completed];
                    }

                    if ($fileResult['error'] !== null && $fileResult['issues'] === []) {
                        $error = $fileResult['error'];
                    }

                    array_push($issues, ...$fileResult['issues']);

                    // Retries are slow, so every file reports its own progress.
                    $this->progress->report($this->name(), ++$completed, $total);
                }

                continue;
            }

            if ($result['error'] !== null && $result['issues'] === []) {
                $error = $result['error'];
            }

            array_push($issues, ...$result['issues']);

            $completed += count($chunk);
            $this->progress->report($this->name(), $completed, $total);
        }

        return ['issues' => $issues, 'error' => $error, 'timed_out' => false, 'completed' => $completed];
    }

    /**
     * PHPStan replaces its JSON report with a shortened, instruction-heavy one
     * when it detects an AI coding agent in the environment, and that report
     * carries only a fraction of the findings. Prism parses the report itself,
     * so those markers are removed from the child environment.
     *
     * @return array<string, string|false>
     */
    private function reportingEnvironment(string $projectAutoload): array
    {
        $names = [
            'AI_AGENT',
            'CLAUDECODE',
            'CLAUDE_CODE',
            'CURSOR_AGENT',
            'GEMINI_CLI',
            'OPENCODE',
            'REPL_ID',
        ];

        foreach (array_keys(getenv()) as $name) {
            foreach (['CODEX_', 'CONTINUE_'] as $prefix) {
                if (str_starts_with((string) $name, $prefix)) {
                    $names[] = (string) $name;
                }
            }
        }

        return array_merge(
            array_fill_keys(array_unique($names), false),
            [
                'PRISM_PROJECT_AUTOLOAD' => is_file($projectAutoload) ? $projectAutoload : '',
                'PRISM_CI_PHPSTAN_SOURCE' => base_path('vendor/codeigniter/phpstan-codeigniter/src'),
                'PRISM_PHPSTAN_PHAR' => storage_path('app/phpstan/phpstan-isolated.phar'),
            ]
        );
    }

    /**
     * @param  array{issues: array<int, AnalysisIssue>, error: ?string, timed_out: bool, truncated: bool}  $result
     */
    private function needsRetry(array $result): bool
    {
        return $result['truncated']
            || ($result['error'] !== null && $result['issues'] === []);
    }

    /**
     * PHPStan spends around ten seconds loading dependency symbols before it
     * analyses anything, so small batches would spend all their time starting
     * up. Batches grow with the project to keep the number of runs sane.
     */
    private function batchSize(int $files): int
    {
        $configured = max(1, (int) config('codechecker.phpstan_batch_size', 5));
        $maxRuns = max(1, (int) config('codechecker.phpstan_max_runs', 60));
        $maxBatch = max($configured, (int) config('codechecker.phpstan_max_batch_size', 40));

        return min($maxBatch, max($configured, (int) ceil($files / $maxRuns)));
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

            $result = $this->commandRunner->runCapturingToFiles(
                $command,
                $project->path,
                $timeout,
                $this->reportingEnvironment($autoload)
            );

            if ($result->timedOut) {
                return [
                    'issues' => [],
                    'error' => "PHPStan timed out after {$timeout} seconds.",
                    'timed_out' => true,
                    'truncated' => false,
                ];
            }

            $json = $this->extractJson($result->stdout !== '' ? $result->stdout : $result->stderr);
            $issues = $this->onlyBatchIssues($this->parseJson($json, $project), $project, $files);
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
     * Each run also loads the rest of the project so cross-file symbols
     * resolve. Those files are reported by their own batch, so anything found
     * outside the current batch is dropped instead of being stored once per
     * run.
     *
     * @param  array<int, AnalysisIssue>  $issues
     * @param  array<int, string>  $files
     * @return array<int, AnalysisIssue>
     */
    private function onlyBatchIssues(array $issues, ProjectContext $project, array $files): array
    {
        $allowed = ['.' => true, 'unknown' => true];

        foreach ($files as $file) {
            $allowed[$this->comparablePath($project->relativePath($file))] = true;
        }

        return array_values(array_filter(
            $issues,
            fn (AnalysisIssue $issue): bool => isset($allowed[$this->comparablePath($issue->file)])
        ));
    }

    private function comparablePath(string $path): string
    {
        return strtolower(str_replace('\\', '/', $path));
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
        if ($json === '' && $exitCode !== 0) {
            $message = trim($stderr);

            return $message !== '' ? $message : 'PHPStan failed without producing a JSON report.';
        }

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
            } elseif ($exitCode !== 0) {
                return trim($stderr) !== ''
                    ? trim($stderr)
                    : 'PHPStan produced an invalid JSON report.';
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
        $sourcePhar = base_path('vendor/phpstan/phpstan/phpstan.phar');
        $runner = base_path('tools/phpstan/isolated-runner.php');
        $isolatedPhar = storage_path('app/phpstan/phpstan-isolated.phar');

        if (is_file($sourcePhar) && is_file($runner)) {
            $directory = dirname($isolatedPhar);

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            if (
                ! is_file($isolatedPhar)
                || filesize($isolatedPhar) !== filesize($sourcePhar)
                || filemtime($isolatedPhar) < filemtime($sourcePhar)
            ) {
                copy($sourcePhar, $isolatedPhar);
            }

            return $runner;
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

        if (
            str_ends_with(strtolower($binary), '.phar')
            || str_ends_with(strtolower($binary), '.php')
        ) {
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
