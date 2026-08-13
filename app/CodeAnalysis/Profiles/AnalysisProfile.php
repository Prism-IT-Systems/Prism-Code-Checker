<?php

namespace App\CodeAnalysis\Profiles;

abstract class AnalysisProfile
{
    /**
     * @return array<int, string>
     */
    abstract public function analyzerNames(): array;

    public function includes(string $analyzerName): bool
    {
        return in_array($analyzerName, $this->analyzerNames(), true);
    }
}
