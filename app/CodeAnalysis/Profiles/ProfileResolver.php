<?php

namespace App\CodeAnalysis\Profiles;

use App\CodeAnalysis\DTO\ProjectContext;

class ProfileResolver
{
    public function resolve(ProjectContext $project): AnalysisProfile
    {
        return match ($project->type) {
            'wordpress' => new WordPressProfile,
            'laravel' => new LaravelProfile,
            default => new PhpProfile,
        };
    }
}
