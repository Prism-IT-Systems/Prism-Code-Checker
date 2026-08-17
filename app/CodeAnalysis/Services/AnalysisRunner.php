<?php

namespace App\CodeAnalysis\Services;

use App\CodeAnalysis\Contracts\AnalyzerInterface;
use App\CodeAnalysis\DTO\AnalysisResult;
use App\CodeAnalysis\DTO\ProjectContext;
use App\CodeAnalysis\Profiles\ProfileResolver;
use Throwable;

class AnalysisRunner
{
    /**
     * @param  iterable<AnalyzerInterface>  $analyzers
     */
    public function __construct(
        private readonly iterable $analyzers,
        private readonly ProfileResolver $profileResolver,
    ) {}

    /**
     * @param  callable(AnalysisResult): void|null  $afterEach
     * @return array<int, AnalysisResult>
     */
    public function run(ProjectContext $project, ?callable $afterEach = null): array
    {
        $profile = $this->profileResolver->resolve($project);
        $results = [];

        foreach ($this->analyzers as $analyzer) {
            if (! $profile->includes($analyzer->name())) {
                continue;
            }

            if (! $analyzer->supports($project)) {
                continue;
            }

            try {
                $result = $analyzer->analyze($project);
            } catch (Throwable $e) {
                $result = new AnalysisResult(
                    tool: $analyzer->name(),
                    success: false,
                    issues: [],
                    errorMessage: $e->getMessage(),
                );
            }

            if ($afterEach === null) {
                $results[] = $result;

                continue;
            }

            $afterEach($result);

            // The findings are stored by now, so only the summary is kept and
            // the next analyzer starts with the memory it needs.
            $results[] = $result->withoutIssues();

            unset($result);
            gc_collect_cycles();
        }

        return $results;
    }
}
