<?php

namespace App\CodeAnalysis\Services;

class StyleIssueClassifier
{
    /**
     * PHPCS/WPCS rules that are formatting, spacing, or documentation style —
     * not runtime bugs or security issues.
     *
     * @var array<int, string>
     */
    private const FORMATTING_NEEDLES = [
        'whitespace',
        'spacing',
        'indent',
        'brace',
        'alignment',
        'formatting',
        'linelength',
        'lineending',
        'endfile',
        'endline',
        'commenting',
        'filecomment',
        'doccomment',
        'functioncallsignature',
        'arraydeclaration',
        'arrayindentation',
        'operator.spacing',
        'castspacing',
        'concatenationspacing',
        'objectoperatorspacing',
        'functionspacing',
        'methodspacing',
        'disallowtab',
        'disallowspace',
        'scopeindent',
        'fileheader',
        'openingbrace',
        'closingbrace',
        'multipleemptylines',
        'noemptyline',
        'blankline',
        'trailingwhitespace',
        'forloopdeclaration',
        'controlstructure.spacing',
    ];

    /**
     * Rules that remain real findings even if PHPCS reports them as ERROR.
     *
     * @var array<int, string>
     */
    private const SUBSTANTIVE_NEEDLES = [
        'wordpress.security',
        'wordpress.db',
        'wordpress.wp.deprecated',
        'wordpress.wp.alternativefunctions',
        'wordpress.wp.enqueuedresources',
        'wordpress.wp.globalvariablesoverride',
        'wordpress.wp.i18n',
        'wordpress.wp.capabilities',
        'wordpress.php.developmentfunctions',
        'wordpress.php.discouragedphpfunctions',
        'wordpress.php.dontextract',
        'wordpress.php.restrictedphpfunctions',
        'wordpress.php.nosilencederrors',
        'wordpress.php.strictinarray',
        'wordpress.php.typecasts',
        'generic.php.',
        'squiz.php.eval',
        'squiz.php.globalkeyword',
        'security.',
    ];

    public function isFormatting(?string $rule, string $message = ''): bool
    {
        $haystack = strtolower(trim(($rule ?? '').' '.$message));

        if ($haystack === '') {
            return false;
        }

        foreach (self::SUBSTANTIVE_NEEDLES as $needle) {
            if (str_contains($haystack, $needle)) {
                return false;
            }
        }

        foreach (self::FORMATTING_NEEDLES as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return str_starts_with($haystack, 'psr12.')
            || str_starts_with($haystack, 'psr2.')
            || str_starts_with($haystack, 'wordpress.namingconventions')
            || str_starts_with($haystack, 'wordpress.files.filename')
            || str_starts_with($haystack, 'wordpress.arrays.')
            || str_starts_with($haystack, 'generic.arrays.')
            || str_starts_with($haystack, 'generic.functions.openingfunctionbrace')
            || str_starts_with($haystack, 'pear.functions.functioncallsignature');
    }
}
