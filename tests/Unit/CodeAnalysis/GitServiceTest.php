<?php

namespace Tests\Unit\CodeAnalysis;

use App\CodeAnalysis\Services\CommandRunner;
use App\CodeAnalysis\Services\GitService;
use Tests\TestCase;

class GitServiceTest extends TestCase
{
    public function test_it_detects_this_repository(): void
    {
        $git = new GitService(new CommandRunner);

        $this->assertTrue($git->isRepository(base_path()));
        $this->assertNotNull($git->currentBranch(base_path()));
    }

    public function test_summary_for_non_repository(): void
    {
        $git = new GitService(new CommandRunner);
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-non-git-'.uniqid();
        mkdir($path);

        try {
            $summary = $git->summary($path);
            $this->assertFalse($summary['is_repository']);
            $this->assertNull($summary['branch']);
        } finally {
            rmdir($path);
        }
    }
}
