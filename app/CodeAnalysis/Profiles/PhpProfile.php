<?php

namespace App\CodeAnalysis\Profiles;

class PhpProfile extends AnalysisProfile
{
    public function analyzerNames(): array
    {
        return [
            'PHP Lint',
            'PHPCS',
            'PHPStan',
            'Composer',
        ];
    }
}
