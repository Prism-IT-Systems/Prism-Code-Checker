<?php

namespace App\CodeAnalysis\Contracts;

use App\CodeAnalysis\DTO\AnalysisResult;
use App\CodeAnalysis\DTO\ProjectContext;

interface AnalyzerInterface
{
    public function name(): string;

    public function supports(ProjectContext $project): bool;

    public function analyze(ProjectContext $project): AnalysisResult;
}
