<?php

namespace App\CodeAnalysis\Services;

use App\CodeAnalysis\DTO\AnalysisResult;
use App\CodeAnalysis\DTO\ProjectContext;
use App\Models\Project;
use App\Models\Scan;
use App\Models\ScanIssue;
use Illuminate\Support\Facades\DB;

class ScanService
{
    /**
     * Rows are inserted in blocks of this size so a tool reporting hundreds of
     * thousands of findings never needs all of its rows in memory at once.
     */
    private const INSERT_CHUNK = 500;

    public function __construct(
        private readonly ProjectDetector $projectDetector,
        private readonly AnalysisRunner $analysisRunner,
        private readonly ResultNormalizer $resultNormalizer,
        private readonly ScanMemoryGuard $memoryGuard,
        private readonly PhpStanConfigFactory $phpStanConfigFactory,
        private readonly ScanProgress $progress,
    ) {}

    /**
     * @param  (callable(string, int, int): void)|null  $onProgress
     */
    public function run(
        string $path,
        string $scanType = 'full',
        ?string $dependencyPaths = null,
        ?callable $onProgress = null,
    ): Scan {
        $this->memoryGuard->apply();
        $this->progress->using($onProgress);

        $context = $this->projectDetector->detect($path, $scanType, $dependencyPaths);
        $resolvedParent = $this->phpStanConfigFactory->parentThemePath($context);
        $context->parentThemePath = $resolvedParent;
        $resolvedDependencies = $this->phpStanConfigFactory->externalDependencyPaths($context);

        $project = Project::query()->updateOrCreate(
            ['path' => $context->path],
            [
                'name' => basename($context->path),
                'type' => $context->type,
            ]
        );

        $scan = Scan::query()->create([
            'project_id' => $project->id,
            'branch' => $context->branch,
            'scan_type' => $scanType,
            'status' => 'running',
            'files_scanned' => count($context->files),
            'started_at' => now(),
            'meta' => [
                'php_version' => $context->phpVersion,
                'composer_available' => $context->composerAvailable,
                'git_repository' => $context->gitRepository,
                'configuration_files' => $context->configurationFiles,
                'parent_theme_path' => $resolvedParent,
                'dependency_paths' => $resolvedDependencies,
            ],
        ]);

        $started = microtime(true);
        $summaries = [];
        $counts = [
            'critical' => 0,
            'error' => 0,
            'warning' => 0,
            'notice' => 0,
            'info' => 0,
        ];

        $releaseCrashGuard = $this->guardAgainstCrash($scan);

        try {
            $this->analysisRunner->run($context, function (AnalysisResult $result) use ($scan, &$summaries, &$counts) {
                $this->persistAnalyzerResult($scan, $result, $summaries, $counts);
                $this->memoryGuard->release();
            });

            $scan->status = 'completed';
        } catch (\Throwable $e) {
            $scan->status = 'failed';
            $scan->meta = array_merge($scan->meta ?? [], [
                'failure' => $e->getMessage(),
            ]);
        }

        $duration = microtime(true) - $started;

        $scan->duration = round($duration, 3);
        $scan->completed_at = now();
        $scan->analyzer_summaries = $summaries;
        $scan->critical_count = $counts['critical'];
        $scan->error_count = $counts['error'];
        $scan->warning_count = $counts['warning'];
        $scan->notice_count = $counts['notice'];
        $scan->info_count = $counts['info'];
        $scan->save();

        $releaseCrashGuard();
        $this->progress->using(null);

        return $scan->fresh(['project']);
    }

    /**
     * Records a diagnosis for crashes PHP cannot throw, such as an exhausted
     * memory limit, so the scan never stays "running" without explanation.
     *
     * Reporting a fatal error needs memory of its own, which is exactly what
     * an exhausted process lacks, so a spare block is held back for it.
     *
     * @return callable(): void  Releases the guard once the scan is stored.
     */
    private function guardAgainstCrash(Scan $scan): callable
    {
        $reserve = str_repeat(' ', 2 * 1024 * 1024);
        $active = true;

        register_shutdown_function(function () use ($scan, &$reserve, &$active): void {
            if (! $active) {
                return;
            }

            $error = error_get_last();

            if ($error === null || ! in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                return;
            }

            $reserve = null;
            gc_collect_cycles();

            $message = $error['message'].' in '.$error['file'].':'.$error['line'];

            Scan::query()->whereKey($scan->id)->update([
                'status' => 'failed',
                'completed_at' => now(),
                'meta' => json_encode(array_merge($scan->meta ?? [], ['failure' => $message])),
            ]);

            $notice = 'Scan #'.$scan->id.' stopped: '.$message;

            if (defined('STDERR')) {
                fwrite(STDERR, PHP_EOL.$notice.PHP_EOL);
            } else {
                error_log($notice);
            }
        });

        return function () use (&$reserve, &$active): void {
            $active = false;
            $reserve = null;
        };
    }

    public function detect(string $path, string $scanType = 'full', ?string $dependencyPaths = null): ProjectContext
    {
        return $this->projectDetector->detect($path, $scanType, $dependencyPaths);
    }

    public function detectForScan(string $path, string $scanType, ?string $dependencyPaths = null): ProjectContext
    {
        return $this->detect($path, $scanType, $dependencyPaths);
    }

    /**
     * @param  array<int, array<string, mixed>>  $summaries
     * @param  array<string, int>  $counts
     */
    private function persistAnalyzerResult(
        Scan $scan,
        AnalysisResult $result,
        array &$summaries,
        array &$counts,
    ): void {
        $summaries[] = [
            'tool' => $result->tool,
            'success' => $result->success,
            'issue_count' => $result->issueCount(),
            'categories' => $this->resultNormalizer->countCategories($result->issues),
            'error_message' => $result->errorMessage,
            'duration' => $result->duration,
            'meta' => $result->meta,
        ];

        if ($result->issues === []) {
            return;
        }

        $batchCounts = $this->resultNormalizer->countSeverities($result->issues);

        foreach ($batchCounts as $severity => $count) {
            $counts[$severity] = ($counts[$severity] ?? 0) + $count;
        }

        $timestamp = now();

        DB::transaction(function () use ($scan, $result, $timestamp) {
            $rows = [];

            foreach ($result->issues as $issue) {
                $rows[] = [
                    'scan_id' => $scan->id,
                    'file' => $issue->file,
                    'line' => $issue->line,
                    'column' => $issue->column,
                    'severity' => $this->resultNormalizer->normalizeSeverity($issue->severity),
                    'tool' => $issue->tool,
                    'category' => $issue->category,
                    'rule' => $issue->rule,
                    'message' => $issue->message,
                    'fixable' => $issue->fixable,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];

                if (count($rows) >= self::INSERT_CHUNK) {
                    ScanIssue::query()->insert($rows);
                    $rows = [];
                }
            }

            if ($rows !== []) {
                ScanIssue::query()->insert($rows);
            }
        });

        unset($batchCounts);
    }
}
