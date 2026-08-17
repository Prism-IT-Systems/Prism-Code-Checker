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
            'codeigniter3', 'codeigniter4' => new CodeIgniterProfile,
            default => new PhpProfile,
        };
    }
}
