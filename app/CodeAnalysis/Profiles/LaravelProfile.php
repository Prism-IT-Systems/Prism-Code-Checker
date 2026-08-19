<?php

namespace App\CodeAnalysis\Profiles;

class LaravelProfile extends AnalysisProfile
{
    public function analyzerNames(): array
    {
        return [
            'PHP Lint',
            'Pint',
            'PHPStan',
            'Composer',
        ];
    }
}
