<?php

namespace App\Console\Commands;

use App\CodeAnalysis\Services\IssueClassifier;
use App\CodeAnalysis\Services\PhpStanConfigFactory;
use App\CodeAnalysis\Services\ScanService;
use App\Models\Scan;
use Illuminate\Console\Command;

class PrismCheckCommand extends Command
{
    protected $signature = 'prism:check
        {path=. : Project path to analyze}
        {--changed : Scan only changed/staged/untracked PHP files}
        {--full : Scan the full project}
        {--dependencies= : Comma-separated plugin, theme, or library paths used for symbols}
        {--parent= : Deprecated alias for one dependency path}
        {--open : Open the scan report in the default browser}';

    protected $description = 'Run Prism Code Checker against a local project';

    public function handle(ScanService $scanService): int
    {
        $path = $this->argument('path');
        $scanType = $this->option('changed') ? 'changed' : 'full';

        if ($this->option('changed') && $this->option('full')) {
            $this->error('Use either --changed or --full, not both.');

            return self::FAILURE;
        }

        $dependencies = array_filter([
            is_string($this->option('dependencies')) ? trim($this->option('dependencies')) : '',
            is_string($this->option('parent')) ? trim($this->option('parent')) : '',
        ]);
        $dependencyPaths = $dependencies !== [] ? implode(',', $dependencies) : null;

        $this->newLine();
        $this->info('Prism Code Checker');
        $this->newLine();

        try {
            $context = $scanService->detect($path, $scanType, $dependencyPaths);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Project:');
        $this->line(basename($context->path));
        $this->newLine();
        $this->line('Type:');
        $this->line($context->typeLabel());
        $this->newLine();
        $this->line('Branch:');
        $this->line($context->branch ?? 'n/a');
        $this->newLine();

        $resolvedDependencies = app(PhpStanConfigFactory::class)
            ->externalDependencyPaths($context);

        $this->line('Dependencies:');
        $this->line($resolvedDependencies !== []
            ? implode(PHP_EOL, $resolvedDependencies)
            : 'none (set --dependencies or PRISM_DEPENDENCY_PATHS)');
        $this->newLine();

        $this->line('Skipped Folders:');
        $this->line($context->ignoredPatterns !== []
            ? implode(', ', $context->ignoredPatterns)
            : 'none (add a .prismignore file to the project root)');
        $this->newLine();

        if ($scanType === 'changed') {
            $this->line('Changed PHP Files:');
        } else {
            $this->line('PHP Files:');
        }
        $this->line((string) count($context->files));
        $this->newLine();

        if ($scanType === 'changed' && $context->files === []) {
            $this->warn('No changed PHP files found.');
        }

        $this->line('Running checks...');
        $this->newLine();

        $reportedTool = null;

        $onProgress = function (string $tool, int $done, int $total) use (&$reportedTool): void {
            if ($reportedTool !== null && $reportedTool !== $tool) {
                $this->output->write(PHP_EOL);
            }

            $reportedTool = $tool;

            $this->output->write(sprintf("\r  %-10s %d/%d files   ", $tool, $done, $total));
        };

        try {
            $scan = $scanService->run($context->path, $scanType, $dependencyPaths, $onProgress);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($reportedTool !== null) {
            $this->newLine(2);
        }

        $this->renderSummaries($scan);

        $categories = $this->categoryTotals($scan);

        $this->newLine();
        $this->line('Must fix: '.($categories['security'] + $categories['bug'])
            .' (security '.$categories['security'].', bug risk '.$categories['bug'].')');
        $this->line('Best practice: '.$categories['practice']);
        $this->line('Formatting: '.$categories['style']);
        $this->newLine();
        $this->line('Critical: '.$scan->critical_count);
        $this->line('Errors: '.$scan->error_count);
        $this->line('Warnings: '.$scan->warning_count);
        $this->newLine();
        $this->line('Result:');
        $this->line($scan->resultLabel());
        $this->newLine();

        $url = rtrim((string) config('codechecker.dashboard_url'), '/').'/scans/'.$scan->id;
        $this->line('Dashboard:');
        $this->line($url);
        $this->newLine();

        if ($this->option('open')) {
            $this->openBrowser($url);
        }

        return $scan->isBlocking() ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function categoryTotals(Scan $scan): array
    {
        $totals = array_fill_keys(IssueClassifier::CATEGORIES, 0);

        foreach ($scan->analyzer_summaries ?? [] as $summary) {
            foreach ($summary['categories'] ?? [] as $category => $count) {
                $totals[$category] = ($totals[$category] ?? 0) + (int) $count;
            }
        }

        return $totals;
    }

    private function renderSummaries(Scan $scan): void
    {
        foreach ($scan->analyzer_summaries ?? [] as $summary) {
            $tool = $summary['tool'] ?? 'Analyzer';
            $count = (int) ($summary['issue_count'] ?? 0);
            $success = (bool) ($summary['success'] ?? false);

            if (! $success) {
                $icon = '[X]';
            } elseif ($count === 0) {
                $icon = '[OK]';
            } else {
                $icon = '[!]';
            }

            $suffix = $count > 0 ? " ({$count})" : '';
            $this->line("{$icon} {$tool}{$suffix}");

            if (! empty($summary['error_message'])) {
                $this->line('  '.$summary['error_message']);
            }
        }
    }

    private function openBrowser(string $url): void
    {
        $url = escapeshellarg($url);

        if (PHP_OS_FAMILY === 'Windows') {
            exec('start "" '.$url);
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            exec('open '.$url);
        } else {
            exec('xdg-open '.$url.' > /dev/null 2>&1 &');
        }
    }
}
