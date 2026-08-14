<?php

namespace Tests\Unit\CodeAnalysis;

use App\CodeAnalysis\Services\StyleIssueClassifier;
use Tests\TestCase;

class StyleIssueClassifierTest extends TestCase
{
    public function test_it_treats_spacing_and_braces_as_formatting(): void
    {
        $classifier = new StyleIssueClassifier;

        $this->assertTrue($classifier->isFormatting('Generic.WhiteSpace.DisallowSpaceIndent.SpacesUsed'));
        $this->assertTrue($classifier->isFormatting('PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket'));
        $this->assertTrue($classifier->isFormatting('Generic.Functions.OpeningFunctionBraceKernighanRitchie.BraceOnNewLine'));
        $this->assertTrue($classifier->isFormatting('Squiz.Commenting.FileComment.Missing'));
        $this->assertTrue($classifier->isFormatting('WordPress.Arrays.ArrayIndentation'));
        $this->assertTrue($classifier->isFormatting('PSR12.Files.FileHeader.SpacingAfterBlock'));
    }

    public function test_it_keeps_security_and_sql_as_substantive(): void
    {
        $classifier = new StyleIssueClassifier;

        $this->assertFalse($classifier->isFormatting('WordPress.Security.EscapeOutput.OutputNotEscaped'));
        $this->assertFalse($classifier->isFormatting('WordPress.DB.PreparedSQL.NotPrepared'));
        $this->assertFalse($classifier->isFormatting('WordPress.Security.NonceVerification.Recommended'));
        $this->assertFalse($classifier->isFormatting('WordPress.WP.DeprecatedFunctions.wp_make_link_relativeFound'));
    }
}
