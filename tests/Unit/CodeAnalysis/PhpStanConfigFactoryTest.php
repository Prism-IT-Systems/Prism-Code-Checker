<?php

namespace Tests\Unit\CodeAnalysis;

use App\CodeAnalysis\DTO\ProjectContext;
use App\CodeAnalysis\Services\PhpStanConfigFactory;
use Tests\TestCase;

class PhpStanConfigFactoryTest extends TestCase
{
    public function test_wordpress_config_includes_official_stubs(): void
    {
        $factory = new PhpStanConfigFactory;
        $project = new ProjectContext(
            path: base_path('tests/Fixtures/wordpress-theme'),
            type: 'wordpress',
            files: [base_path('tests/Fixtures/wordpress-theme/theme-functions.php')],
        );

        $neon = $factory->contents($project, $project->files);

        $this->assertStringContainsString('php-stubs/wordpress-stubs/wordpress-stubs.php', $neon);
        $this->assertStringContainsString('scanFiles:', $neon);
        $this->assertStringContainsString('theme-functions.php', $neon);
        $this->assertStringContainsString('maximumNumberOfProcesses: 1', $neon);
        $this->assertStringNotContainsString('bootstrapFiles:', $neon);
    }

    public function test_php_config_does_not_include_wordpress_stubs(): void
    {
        $factory = new PhpStanConfigFactory;
        $project = new ProjectContext(
            path: base_path('tests/Fixtures/php'),
            type: 'php',
            files: [base_path('tests/Fixtures/php/missing-function.php')],
        );

        $neon = $factory->contents($project, $project->files);

        $this->assertStringNotContainsString('wordpress-stubs.php', $neon);
    }
}
