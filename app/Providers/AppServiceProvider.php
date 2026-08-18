<?php

namespace App\Providers;

use App\CodeAnalysis\Analyzers\ComposerAnalyzer;
use App\CodeAnalysis\Analyzers\PhpCsAnalyzer;
use App\CodeAnalysis\Analyzers\PhpCsFixerAnalyzer;
use App\CodeAnalysis\Analyzers\PhpLintAnalyzer;
use App\CodeAnalysis\Analyzers\PhpStanAnalyzer;
use App\CodeAnalysis\Analyzers\WordPressAnalyzer;
use App\CodeAnalysis\Contracts\AnalyzerInterface;
use App\CodeAnalysis\Services\AnalysisRunner;
use App\CodeAnalysis\Services\ScanMemoryGuard;
use App\CodeAnalysis\Services\ScanProgress;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Analyzers share one reporter so the caller sees a single stream.
        $this->app->singleton(ScanProgress::class);

        $this->app->tag([
            PhpLintAnalyzer::class,
            PhpCsAnalyzer::class,
            PhpCsFixerAnalyzer::class,
            WordPressAnalyzer::class,
            PhpStanAnalyzer::class,
            ComposerAnalyzer::class,
        ], AnalyzerInterface::class);

        $this->app->singleton(AnalysisRunner::class, function ($app) {
            return new AnalysisRunner(
                $app->tagged(AnalyzerInterface::class),
                $app->make(\App\CodeAnalysis\Profiles\ProfileResolver::class),
            );
        });
    }

    public function boot(): void
    {
        $this->app->make(ScanMemoryGuard::class)->apply();
    }
}
