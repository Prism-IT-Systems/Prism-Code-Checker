<?php

namespace Tests\Unit\CodeAnalysis;

use App\CodeAnalysis\Analyzers\PhpCsAnalyzer;
use App\CodeAnalysis\DTO\ProjectContext;
use App\CodeAnalysis\Services\CommandRunner;
use App\CodeAnalysis\Services\ResultNormalizer;
use Tests\TestCase;

class PhpCsAnalyzerTest extends TestCase
{
    public function test_it_parses_phpcs_json(): void
    {
        $analyzer = app(PhpCsAnalyzer::class);

        $json = json_encode([
            'files' => [
                base_path('tests/Fixtures/php/missing-variable.php') => [
                    'messages' => [
                        [
                            'message' => 'Missing file doc comment',
                            'source' => 'Squiz.Commenting.FileComment.Missing',
                            'severity' => 5,
                            'type' => 'ERROR',
                            'line' => 1,
                            'column' => 1,
                            'fixable' => false,
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $project = new ProjectContext(
            path: base_path('tests/Fixtures/php'),
            type: 'php',
        );

        $issues = $analyzer->parseJson($json, $project);

        $this->assertCount(1, $issues);
        $this->assertSame('notice', $issues[0]->severity);
        $this->assertSame('Formatting', $issues[0]->tool);
        $this->assertSame(1, $issues[0]->line);
    }

    public function test_it_keeps_wordpress_security_as_error(): void
    {
        $analyzer = app(PhpCsAnalyzer::class);

        $json = json_encode([
            'files' => [
                base_path('tests/Fixtures/wordpress/unsafe-output.php') => [
                    'messages' => [
                        [
                            'message' => 'All output should be escaped.',
                            'source' => 'WordPress.Security.EscapeOutput.OutputNotEscaped',
                            'severity' => 5,
                            'type' => 'ERROR',
                            'line' => 6,
                            'column' => 6,
                            'fixable' => false,
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $project = new ProjectContext(
            path: base_path('tests/Fixtures/wordpress'),
            type: 'wordpress',
        );

        $issues = $analyzer->parseJson($json, $project);

        $this->assertSame('error', $issues[0]->severity);
        $this->assertSame('WordPress', $issues[0]->tool);
    }
}
