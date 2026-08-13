<?php

namespace App\CodeAnalysis\Analyzers;

use App\CodeAnalysis\Contracts\AnalyzerInterface;
use App\CodeAnalysis\DTO\AnalysisIssue;
use App\CodeAnalysis\DTO\AnalysisResult;
use App\CodeAnalysis\DTO\ProjectContext;
use App\CodeAnalysis\Services\CommandRunner;
use App\CodeAnalysis\Services\ResultNormalizer;

class ComposerAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly CommandRunner $commandRunner,
        private readonly ResultNormalizer $normalizer,
    ) {}

    public function name(): string
    {
        return 'Composer';
    }

    public function supports(ProjectContext $project): bool
    {
        return $project->composerAvailable;
    }

    public function analyze(ProjectContext $project): AnalysisResult
    {
        $started = microtime(true);
        $issues = [];
        $timeout = (float) config('codechecker.timeouts.composer', 120);
        $binary = (string) config('codechecker.binaries.composer', 'composer');
        $errors = [];

        $validate = $this->commandRunner->run(
            [$binary, 'validate', '--no-check-publish', '--no-ansi'],
            $project->path,
            $timeout
        );

        if ($validate->timedOut) {
            $issues[] = $this->warning('.', 'timeout', "Composer validate timed out after {$timeout} seconds.");
            $errors[] = 'Composer validate timed out.';
        } elseif (! $validate->successful()) {
            $message = trim($validate->output());
            $issues[] = $this->normalizer->fromArray([
                'file' => 'composer.json',
                'line' => null,
                'column' => null,
                'severity' => 'error',
                'tool' => $this->name(),
                'rule' => 'validate',
                'message' => $message !== '' ? $message : 'composer.json failed validation.',
                'fixable' => false,
            ]);
        }

        $audit = $this->commandRunner->run(
            [$binary, 'audit', '--format=json', '--no-ansi'],
            $project->path,
            $timeout
        );

        if ($audit->timedOut) {
            $issues[] = $this->warning('.', 'timeout', "Composer audit timed out after {$timeout} seconds.");
            $errors[] = 'Composer audit timed out.';
        } else {
            $issues = array_merge($issues, $this->parseAudit($audit->stdout !== '' ? $audit->stdout : $audit->stderr));
        }

        return new AnalysisResult(
            tool: $this->name(),
            success: $errors === [],
            issues: $issues,
            errorMessage: $errors !== [] ? implode(' ', $errors) : null,
            duration: round(microtime(true) - $started, 3),
        );
    }

    /**
     * @return array<int, AnalysisIssue>
     */
    public function parseAudit(string $json): array
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            return [];
        }

        $issues = [];
        $advisories = $data['advisories'] ?? [];

        if (is_array($advisories)) {
            foreach ($advisories as $package => $items) {
                if (! is_array($items)) {
                    continue;
                }

                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $title = $item['title'] ?? ($item['advisoryId'] ?? 'Security advisory');
                    $cve = $item['cve'] ?? null;
                    $message = is_string($cve) && $cve !== ''
                        ? "[{$package}] {$title} ({$cve})"
                        : "[{$package}] {$title}";

                    $issues[] = $this->normalizer->fromArray([
                        'file' => 'composer.lock',
                        'line' => null,
                        'column' => null,
                        'severity' => 'warning',
                        'tool' => $this->name(),
                        'rule' => 'audit',
                        'message' => $message,
                        'fixable' => false,
                    ]);
                }
            }
        }

        $abandoned = $data['abandoned'] ?? [];

        if (is_array($abandoned)) {
            foreach ($abandoned as $package => $replacement) {
                $message = is_string($replacement) && $replacement !== ''
                    ? "Abandoned package {$package}; use {$replacement} instead."
                    : "Abandoned package {$package}.";

                $issues[] = $this->normalizer->fromArray([
                    'file' => 'composer.json',
                    'line' => null,
                    'column' => null,
                    'severity' => 'notice',
                    'tool' => $this->name(),
                    'rule' => 'abandoned',
                    'message' => $message,
                    'fixable' => false,
                ]);
            }
        }

        return $issues;
    }

    private function warning(string $file, string $rule, string $message): AnalysisIssue
    {
        return $this->normalizer->fromArray([
            'file' => $file,
            'line' => null,
            'column' => null,
            'severity' => 'warning',
            'tool' => $this->name(),
            'rule' => $rule,
            'message' => $message,
            'fixable' => false,
        ]);
    }
}
