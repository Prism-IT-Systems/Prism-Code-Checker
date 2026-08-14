<?php

namespace App\CodeAnalysis\Services;

/**
 * Assigns every finding an impact category.
 *
 * Linters report thousands of rules and only a small, known set of them
 * describes behaviour that can break or expose a site. That set is listed
 * here explicitly; anything unknown falls back to "style" so new or obscure
 * sniffs can never inflate the must-fix list.
 */
class IssueClassifier
{
    public const SECURITY = 'security';

    public const BUG = 'bug';

    public const PRACTICE = 'practice';

    public const STYLE = 'style';

    public const CATEGORIES = [self::SECURITY, self::BUG, self::PRACTICE, self::STYLE];

    /**
     * Categories shown under "Must fix".
     *
     * @var array<int, string>
     */
    public const MUST_FIX = [self::SECURITY, self::BUG];

    private const LABELS = [
        self::SECURITY => 'Security',
        self::BUG => 'Bug risk',
        self::PRACTICE => 'Best practice',
        self::STYLE => 'Formatting',
    ];

    /**
     * Rule prefixes mapped to a category. Matching is case-insensitive and the
     * longest matching prefix wins, so a specific sniff can override its family.
     *
     * @var array<string, string>
     */
    private const RULE_MAP = [
        // Security: unescaped output, unsanitised input, raw SQL, code execution.
        'wordpress.security.' => self::SECURITY,
        'wordpress.db.preparedsql' => self::SECURITY,
        'wordpress.php.dontextract' => self::SECURITY,
        'wordpress.php.restrictedphpfunctions' => self::SECURITY,
        'wordpress.wp.enqueuedresourceparameters.notagged' => self::SECURITY,
        'squiz.php.eval' => self::SECURITY,
        'generic.php.backtickoperator' => self::SECURITY,
        'composer:audit' => self::SECURITY,

        // Bug risk: code that can fatal, behave unpredictably, or leak state.
        'php lint:syntax' => self::BUG,
        'generic.codeanalysis.' => self::BUG,
        'wordpress.codeanalysis.' => self::BUG,
        'universal.codeanalysis.' => self::BUG,
        'generic.functions.calltimepassbyreference' => self::BUG,
        'generic.classes.duplicateclassname' => self::BUG,
        'squiz.classes.duplicateproperty' => self::BUG,
        'squiz.php.nonexecutablecode' => self::BUG,
        'wordpress.wp.globalvariablesoverride' => self::BUG,
        'composer:validate' => self::BUG,

        // Not bugs, but worth a human decision.
        'generic.codeanalysis.unusedfunctionparameter' => self::PRACTICE,
        'generic.metrics.' => self::PRACTICE,
        'generic.php.deprecatedfunctions' => self::PRACTICE,
        'generic.php.discouragedfunctions' => self::PRACTICE,
        'generic.php.forbiddenfunctions' => self::PRACTICE,
        'generic.php.disallowshortopentag' => self::PRACTICE,
        'modernize.' => self::PRACTICE,
        'squiz.php.commentedoutcode' => self::PRACTICE,
        'squiz.php.discouragedfunctions' => self::PRACTICE,
        'squiz.php.globalkeyword' => self::PRACTICE,
        'universal.operators.strictcomparisons' => self::PRACTICE,
        'wordpress.datetime.' => self::PRACTICE,
        'wordpress.db.directdatabasequery' => self::PRACTICE,
        'wordpress.db.slowdbquery' => self::PRACTICE,
        'wordpress.php.developmentfunctions' => self::PRACTICE,
        'wordpress.php.discouragedphpfunctions' => self::PRACTICE,
        'wordpress.php.ini_set' => self::PRACTICE,
        'wordpress.php.nosilencederrors' => self::PRACTICE,
        'wordpress.php.pregquotedelimiter' => self::PRACTICE,
        'wordpress.php.strictinarray' => self::PRACTICE,
        'wordpress.wp.alternativefunctions' => self::PRACTICE,
        'wordpress.wp.capabilities' => self::PRACTICE,
        'wordpress.wp.crondescription' => self::PRACTICE,
        'wordpress.wp.deprecated' => self::PRACTICE,
        'wordpress.wp.discouraged' => self::PRACTICE,
        'wordpress.wp.enqueuedresource' => self::PRACTICE,
        'wordpress.wp.i18n' => self::PRACTICE,
        'wordpress.wp.postsperpage' => self::PRACTICE,
        'composer:abandoned' => self::PRACTICE,

        // PHPStan identifiers that are documentation or layout only.
        'phpdoc.' => self::PRACTICE,
        'missingtype.' => self::PRACTICE,
        'whitespace.' => self::STYLE,
    ];

    /**
     * Tools whose findings are behavioural unless a rule says otherwise.
     *
     * @var array<string, string>
     */
    private const TOOL_FALLBACK = [
        'php lint' => self::BUG,
        'phpstan' => self::BUG,
        'composer' => self::PRACTICE,
    ];

    /**
     * Rules emitted by Prism itself when a tool cannot complete. These are not
     * code problems but must stay visible.
     *
     * @var array<int, string>
     */
    private const OPERATIONAL_RULES = ['timeout', 'execution', 'missing-autoload'];

    public function categorize(string $tool, ?string $rule, string $message = ''): string
    {
        $tool = strtolower(trim($tool));
        $rule = strtolower(trim((string) $rule));

        if (in_array($rule, self::OPERATIONAL_RULES, true)) {
            return self::BUG;
        }

        $candidates = [$tool.':'.$rule, $rule];
        $bestPrefix = '';
        $bestCategory = null;

        foreach (self::RULE_MAP as $prefix => $category) {
            foreach ($candidates as $candidate) {
                if ($candidate !== '' && str_starts_with($candidate, $prefix) && strlen($prefix) > strlen($bestPrefix)) {
                    $bestPrefix = $prefix;
                    $bestCategory = $category;
                }
            }
        }

        if ($bestCategory !== null) {
            return $bestCategory;
        }

        return self::TOOL_FALLBACK[$tool] ?? self::STYLE;
    }

    /**
     * Keeps low-impact findings out of the critical/error counters.
     */
    public function severityFor(string $category, string $reportedSeverity): string
    {
        $reported = strtolower(trim($reportedSeverity));

        return match ($category) {
            self::STYLE => 'notice',
            self::PRACTICE => in_array($reported, ['critical', 'error', 'warning'], true) ? 'warning' : $reported,
            default => $reported,
        };
    }

    public function isMustFix(string $category): bool
    {
        return in_array($category, self::MUST_FIX, true);
    }

    public function label(string $category): string
    {
        return self::LABELS[$category] ?? ucfirst($category);
    }
}
