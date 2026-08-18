<?php

namespace App\CodeAnalysis\Profiles;

class CodeIgniterProfile extends AnalysisProfile
{
    public function analyzerNames(): array
    {
        return [
            'PHP Lint',
            'PHPCS',
            'PHP-CS-Fixer',
            'PHPStan',
            'Composer',
        ];
    }
}
