<?php

namespace App\CodeAnalysis\Profiles;

class WordPressProfile extends AnalysisProfile
{
    public function analyzerNames(): array
    {
        return [
            'PHP Lint',
            'WordPress',
            'PHPStan',
            'Composer',
        ];
    }
}
