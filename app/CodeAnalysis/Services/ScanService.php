<?php

namespace App\CodeAnalysis\Services;

use App\CodeAnalysis\DTO\AnalysisIssue;
use App\CodeAnalysis\DTO\AnalysisResult;
use App\CodeAnalysis\DTO\ProjectContext;
use App\Models\Project;
use App\Models\Scan;
use App\Models\ScanIssue;
use Illuminate\Support\Facades\DB;

class ScanService
{
    public function __construct(
        private readonly ProjectDetector $projectDetector,
        private readonly AnalysisRunner $analysisRunner,
        private readonly ResultNormalizer $resultNormalizer,
        private readonly ScanMemoryGuard $memoryGuard,
    ) {}

    public function run(string $path, string $scanType = 'full'): Scan
    {
        $this->memoryGuard->apply();

        $context = $this->projectDetector->detect($path, $scanType);

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

        return $scan->fresh(['project']);
    }

    public function detect(string $path, string $scanType = 'full'): ProjectContext
    {
        return $this->projectDetector->detect($path, $scanType);
    }

    public function detectForScan(string $path, string $scanType): ProjectContext
    {
        return $this->detect($path, $scanType);
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

        $rows = array_map(function (AnalysisIssue $issue) use ($scan) {
            return [
                'scan_id' => $scan->id,
                'file' => $issue->file,
                'line' => $issue->line,
                'column' => $issue->column,
                'severity' => $this->resultNormalizer->normalizeSeverity($issue->severity),
                'tool' => $issue->tool,
                'rule' => $issue->rule,
                'message' => $issue->message,
                'fixable' => $issue->fixable,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }, $result->issues);

        DB::transaction(function () use ($rows) {
            foreach (array_chunk($rows, 200) as $chunk) {
                ScanIssue::query()->insert($chunk);
            }
        });

        unset($rows, $batchCounts);
    }
}
