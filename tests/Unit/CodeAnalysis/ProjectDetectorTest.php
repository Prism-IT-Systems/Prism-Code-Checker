<?php

namespace Tests\Unit\CodeAnalysis;

use App\CodeAnalysis\Services\PathValidator;
use App\CodeAnalysis\Services\ProjectDetector;
use App\CodeAnalysis\Services\CommandRunner;
use App\CodeAnalysis\Services\GitService;
use Tests\TestCase;

class ProjectDetectorTest extends TestCase
{
    public function test_it_detects_wordpress_plugin(): void
    {
        $detector = $this->detector();
        $path = base_path('tests/Fixtures/wordpress');

        $type = $detector->detectType($path);

        $this->assertSame('wordpress', $type);
    }

    public function test_it_detects_wordpress_theme(): void
    {
        $detector = $this->detector();
        $path = base_path('tests/Fixtures/wordpress-theme');

        $this->assertTrue($detector->isWordPress($path));
        $this->assertSame('wordpress', $detector->detectType($path));
    }

    public function test_it_detects_generic_php_project(): void
    {
        $detector = $this->detector();
        $path = base_path('tests/Fixtures/php');

        $type = $detector->detectType($path);

        $this->assertSame('php', $type);
    }

    public function test_it_detects_laravel_from_current_app(): void
    {
        $detector = $this->detector();

        $this->assertTrue($detector->isLaravel(base_path()));
        $this->assertSame('laravel', $detector->detectType(base_path()));
    }

    private function detector(): ProjectDetector
    {
        return new ProjectDetector(
            new PathValidator,
            new GitService(new CommandRunner),
        );
    }
}
