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

class PhpCsAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly CommandRunner $commandRunner,
        private readonly ResultNormalizer $normalizer,
        private readonly FileBatchProcessor $batchProcessor,
        private readonly ScanMemoryGuard $memoryGuard,
    ) {}

    public function name(): string
    {
        return 'PHPCS';
    }

    public function supports(ProjectContext $project): bool
    {
        // WordPress projects are handled by WordPressAnalyzer to avoid duplicate PHPCS runs.
        if ($project->isWordPress()) {
            return false;
        }

        return $project->files !== [] && $this->binaryExists();
    }

    public function analyze(ProjectContext $project): AnalysisResult
    {
        $started = microtime(true);
        $binary = $this->resolveBinary();
        $timeout = (float) config('codechecker.timeouts.phpcs', 120);
        $standard = $this->resolveStandard($project);
        $issues = [];
        $timedOut = false;

        foreach ($this->batchProcessor->chunk($project->files) as $batch) {
            $command = [
                $binary,
                '--report=json',
                '-q',
            ];

            if ($standard !== null) {
                $command[] = '--standard='.$standard;
            }

            foreach ($batch as $file) {
                $command[] = $file;
            }

            $result = $this->commandRunner->run($command, $project->path, $timeout);

            if ($result->timedOut) {
                $timedOut = true;
                break;
            }

            $batchIssues = $this->parseJson(
                $result->stdout !== '' ? $result->stdout : $result->stderr,
                $project
            );

            array_push($issues, ...$batchIssues);
            unset($result, $batchIssues);
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
                        message: "PHPCS timed out after {$timeout} seconds.",
                    ),
                ],
                errorMessage: "PHPCS timed out after {$timeout} seconds.",
                duration: round(microtime(true) - $started, 3),
                meta: ['standard' => $standard],
            );
        }

        return new AnalysisResult(
            tool: $this->name(),
            success: true,
            issues: $issues,
            duration: round(microtime(true) - $started, 3),
            meta: ['standard' => $standard],
        );
    }

    /**
     * @return array<int, AnalysisIssue>
     */
    public function parseJson(string $json, ProjectContext $project): array
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ! isset($data['files']) || ! is_array($data['files'])) {
            return [];
        }

        $issues = [];

        foreach ($data['files'] as $filePath => $fileData) {
            $relative = is_string($filePath) ? $project->relativePath($filePath) : 'unknown';
            $messages = $fileData['messages'] ?? [];

            foreach ($messages as $message) {
                $type = strtolower((string) ($message['type'] ?? 'warning'));
                $rule = isset($message['source']) ? (string) $message['source'] : null;
                $text = (string) ($message['message'] ?? 'Coding standard violation');

                $issues[] = $this->normalizer->fromArray([
                    'file' => $relative,
                    'line' => $message['line'] ?? null,
                    'column' => $message['column'] ?? null,
                    'severity' => $type === 'error' ? 'error' : ($type === 'warning' ? 'warning' : 'notice'),
                    'tool' => $this->isWordPressRule((string) $rule) ? 'WordPress' : $this->name(),
                    'rule' => $rule,
                    'message' => $text,
                    'fixable' => (bool) ($message['fixable'] ?? false),
                ]);
            }
        }

        return $issues;
    }

    private function resolveStandard(ProjectContext $project): ?string
    {
        foreach (['phpcs.xml', 'phpcs.xml.dist', '.phpcs.xml', '.phpcs.xml.dist'] as $file) {
            if ($project->configurationFiles[$file] ?? false) {
                return $project->path.DIRECTORY_SEPARATOR.$file;
            }
        }

        if ($project->isLaravel()) {
            $laravel = base_path('tools/phpcs/laravel.xml');

            return is_file($laravel) ? $laravel : 'PSR12';
        }

        if ($project->isCodeIgniter()) {
            $version = $project->isCodeIgniter3() ? '3' : '4';
            $codeIgniter = base_path("tools/phpcs/codeigniter{$version}.xml");

            return is_file($codeIgniter) ? $codeIgniter : 'PSR12';
        }

        $default = base_path('tools/phpcs/default.xml');

        return is_file($default) ? $default : 'PSR12';
    }

    private function isWordPressRule(string $source): bool
    {
        return str_starts_with($source, 'WordPress.')
            || str_starts_with($source, 'WordPressCS.');
    }

    private function binaryExists(): bool
    {
        $binary = $this->resolveBinary();

        return is_file($binary) || is_file($binary.'.bat') || is_file($binary.'.phar');
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
