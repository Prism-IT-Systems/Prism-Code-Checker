<?php

namespace Tests\Unit\CodeAnalysis;

use App\CodeAnalysis\Analyzers\PhpLintAnalyzer;
use App\CodeAnalysis\DTO\ProjectContext;
use App\CodeAnalysis\Services\CommandRunner;
use App\CodeAnalysis\Services\ResultNormalizer;
use Tests\TestCase;

class PhpLintAnalyzerTest extends TestCase
{
    public function test_it_parses_syntax_errors_as_critical(): void
    {
        $analyzer = new PhpLintAnalyzer(new CommandRunner, new ResultNormalizer);
        $file = base_path('tests/Fixtures/php/bad-syntax.php');

        $project = new ProjectContext(
            path: dirname($file),
            type: 'php',
            files: [$file],
        );

        $result = $analyzer->analyze($project);

        $this->assertNotEmpty($result->issues);
        $this->assertSame('critical', $result->issues[0]->severity);
        $this->assertSame('PHP Lint', $result->issues[0]->tool);
    }

    public function test_parse_output_extracts_line_number(): void
    {
        $analyzer = new PhpLintAnalyzer(new CommandRunner, new ResultNormalizer);

        $issue = $analyzer->parseOutput(
            'Parse error: syntax error, unexpected token "{" in file.php on line 3',
            'file.php'
        );

        $this->assertSame(3, $issue->line);
        $this->assertSame('critical', $issue->severity);
    }
}
