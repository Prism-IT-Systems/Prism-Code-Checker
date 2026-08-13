<?php

namespace Tests\Unit\CodeAnalysis;

use App\CodeAnalysis\Services\FileBatchProcessor;
use Tests\TestCase;

class FileBatchProcessorTest extends TestCase
{
    public function test_it_chunks_files(): void
    {
        config(['codechecker.batch_size' => 3]);

        $processor = new FileBatchProcessor;
        $files = range(1, 10);

        $chunks = $processor->chunk($files);

        $this->assertCount(4, $chunks);
        $this->assertSame([1, 2, 3], $chunks[0]);
        $this->assertSame([10], $chunks[3]);
    }

    public function test_it_returns_empty_for_no_files(): void
    {
        $processor = new FileBatchProcessor;

        $this->assertSame([], $processor->chunk([]));
    }
}
