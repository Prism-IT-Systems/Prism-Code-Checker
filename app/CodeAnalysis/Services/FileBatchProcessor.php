<?php

namespace App\CodeAnalysis\Services;

class FileBatchProcessor
{
    /**
     * @param  array<int, string>  $files
     * @return array<int, array<int, string>>
     */
    public function chunk(array $files, ?int $size = null): array
    {
        if ($files === []) {
            return [];
        }

        $size = max(1, $size ?? (int) config('codechecker.batch_size', 25));

        return array_chunk($files, $size);
    }
}
