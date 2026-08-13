<?php

namespace Tests\Unit\CodeAnalysis;

use App\CodeAnalysis\Analyzers\PhpStanAnalyzer;
use App\CodeAnalysis\DTO\ProjectContext;
use App\CodeAnalysis\Services\CommandRunner;
use App\CodeAnalysis\Services\ResultNormalizer;
use Tests\TestCase;

class PhpStanAnalyzerTest extends TestCase
{
    public function test_it_parses_phpstan_json(): void
    {
        $analyzer = app(PhpStanAnalyzer::class);

        $json = json_encode([
            'totals' => ['errors' => 1, 'file_errors' => 1],
            'files' => [
                base_path('tests/Fixtures/php/missing-variable.php') => [
                    'errors' => 1,
                    'messages' => [
                        [
                            'message' => 'Undefined variable: $undefinedVariable',
                            'line' => 5,
                            'ignorable' => true,
                            'identifier' => 'variable.undefined',
                        ],
                    ],
                ],
            ],
            'errors' => [],
        ], JSON_THROW_ON_ERROR);

        $project = new ProjectContext(
            path: base_path('tests/Fixtures/php'),
            type: 'php',
        );

        $issues = $analyzer->parseJson($json, $project);

        $this->assertCount(1, $issues);
        $this->assertSame('error', $issues[0]->severity);
        $this->assertSame('variable.undefined', $issues[0]->rule);
        $this->assertSame('PHPStan', $issues[0]->tool);
    }

    public function test_it_parses_wrapped_phpstan_json(): void
    {
        $analyzer = app(PhpStanAnalyzer::class);

        $json = json_encode([
            'tool' => 'phpstan',
            'result' => 'failed',
            'errors' => 1,
            'error_details' => [
                base_path('tests/Fixtures/php/missing-variable.php') => [
                    [
                        'line' => 5,
                        'message' => 'Undefined variable: $undefinedVariable',
                        'identifier' => 'variable.undefined',
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
        $this->assertSame('variable.undefined', $issues[0]->rule);
    }

    public function test_undefined_functions_are_critical(): void
    {
        $analyzer = app(PhpStanAnalyzer::class);

        $this->assertSame(
            'critical',
            $analyzer->severityForIssue('function.notFound', 'Function totally_missing_function not found.')
        );

        $this->assertSame(
            'critical',
            $analyzer->severityForIssue(null, 'Call to undefined function totally_missing_function()')
        );

        $this->assertSame(
            'error',
            $analyzer->severityForIssue('variable.undefined', 'Undefined variable: $x')
        );
    }

    public function test_it_marks_undefined_function_issues_critical_when_parsing(): void
    {
        $analyzer = app(PhpStanAnalyzer::class);

        $json = json_encode([
            'files' => [
                base_path('tests/Fixtures/php/missing-function.php') => [
                    'messages' => [
                        [
                            'message' => 'Function totally_missing_function not found.',
                            'line' => 5,
                            'identifier' => 'function.notFound',
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

        $this->assertSame('critical', $issues[0]->severity);
    }
}
