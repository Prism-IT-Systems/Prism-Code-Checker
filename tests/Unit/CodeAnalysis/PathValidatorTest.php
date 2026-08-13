<?php

namespace Tests\Unit\CodeAnalysis;

use App\CodeAnalysis\Services\PathValidator;
use InvalidArgumentException;
use Tests\TestCase;

class PathValidatorTest extends TestCase
{
    public function test_it_rejects_missing_paths(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PathValidator)->validate(base_path('does-not-exist-'.uniqid()));
    }

    public function test_it_accepts_existing_directories(): void
    {
        $resolved = (new PathValidator)->validate(base_path('tests/Fixtures/php'));

        $this->assertDirectoryExists($resolved);
    }

    public function test_it_enforces_projects_root_when_configured(): void
    {
        config(['codechecker.projects_root' => base_path('tests/Fixtures')]);

        $resolved = (new PathValidator)->validate(base_path('tests/Fixtures/php'));
        $this->assertDirectoryExists($resolved);

        $this->expectException(InvalidArgumentException::class);
        (new PathValidator)->validate(base_path('app'));
    }
}
