<?php

namespace App\CodeAnalysis\Services;

class ScanMemoryGuard
{
    public function apply(): void
    {
        $limit = (string) config('codechecker.memory_limit', '512M');

        if ($limit === '' || $limit === '0') {
            return;
        }

        @ini_set('memory_limit', $limit);
    }

    public function release(): void
    {
        if (config('codechecker.gc_after_batch', true)) {
            gc_collect_cycles();
        }
    }
}
