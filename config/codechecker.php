<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Projects Root
    |--------------------------------------------------------------------------
    |
    | When set, only project paths under this directory may be scanned.
    | Leave empty to disable the restriction (useful for local CLI usage).
    |
    */

    'projects_root' => env('PROJECTS_ROOT', ''),

    /*
    |--------------------------------------------------------------------------
    | Dashboard URL
    |--------------------------------------------------------------------------
    */

    'dashboard_url' => env('PRISM_DASHBOARD_URL', 'http://127.0.0.1:8787'),

    /*
    |--------------------------------------------------------------------------
    | Default Exclusions
    |--------------------------------------------------------------------------
    |
    | Folder names skipped in every project. Three ways to skip more:
    | PRISM_EXCLUDE for comma-separated names, Prism's own .prismignore file
    | for folders to skip in every project it scans, and a .prismignore in a
    | scanned project's root for folders specific to that project.
    |
    */

    'exclude_extra' => env('PRISM_EXCLUDE', ''),

    'ignore_file' => env('PRISM_IGNORE_FILE', base_path('.prismignore')),

    'exclude' => [
        '.git',
        'vendor',
        'node_modules',
        'storage',
        'cache',
        'coverage',
        'dist',
        'build',
        'logs',
        'tmp',
    ],

    /*
    |--------------------------------------------------------------------------
    | Blocking Severities
    |--------------------------------------------------------------------------
    |
    | Scans with at least one issue at these severities are marked FIX REQUIRED.
    |
    */

    'blocking_severities' => [
        'critical',
        'error',
    ],

    /*
    |--------------------------------------------------------------------------
    | Analyzer Timeouts (seconds)
    |--------------------------------------------------------------------------
    */

    'timeouts' => [
        'php_lint' => (int) env('PRISM_TIMEOUT_PHP_LINT', 60),
        'phpcs' => (int) env('PRISM_TIMEOUT_PHPCS', 120),
        'php_cs_fixer' => (int) env('PRISM_TIMEOUT_PHP_CS_FIXER', 180),
        'pint' => (int) env('PRISM_TIMEOUT_PINT', 180),
        'phpstan' => (int) env('PRISM_TIMEOUT_PHPSTAN', 300),
        'composer' => (int) env('PRISM_TIMEOUT_COMPOSER', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scan Memory & Batching
    |--------------------------------------------------------------------------
    |
    | Prism raises memory for scan operations automatically so developers do
    | not need to change php.ini. Large projects are analyzed in file batches
    | to keep peak memory usage under control.
    |
    */

    'memory_limit' => env('PRISM_MEMORY_LIMIT', '512M'),

    'phpstan_memory_limit' => env('PRISM_PHPSTAN_MEMORY_LIMIT', '1G'),

    'batch_size' => (int) env('PRISM_BATCH_SIZE', 25),

    'phpstan_batch_size' => (int) env('PRISM_PHPSTAN_BATCH_SIZE', 5),

    // Batches grow on large projects so PHPStan's start-up cost is not paid
    // hundreds of times, without letting a single run get too big to survive.
    'phpstan_max_runs' => (int) env('PRISM_PHPSTAN_MAX_RUNS', 60),

    'phpstan_max_batch_size' => (int) env('PRISM_PHPSTAN_MAX_BATCH_SIZE', 40),

    // A normal scan starts PHPStan once for the whole project. Batching is a
    // recovery path for projects that time out, crash, or exhaust memory.
    'phpstan_full_run_first' => (bool) env('PRISM_PHPSTAN_FULL_RUN_FIRST', true),

    'phpstan_full_run_timeout' => (int) env('PRISM_PHPSTAN_FULL_RUN_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Findings Limit
    |--------------------------------------------------------------------------
    |
    | Legacy code bases can report hundreds of thousands of findings from a
    | single tool. Each analyzer stops collecting at this limit and reports
    | that it was truncated. Set to 0 to collect everything.
    |
    */

    'max_issues_per_analyzer' => (int) env('PRISM_MAX_ISSUES', 40000),

    /*
    |--------------------------------------------------------------------------
    | Lint Concurrency
    |--------------------------------------------------------------------------
    |
    | PHP lint needs one process per file, so files are linted in parallel.
    |
    */

    'lint_concurrency' => (int) env('PRISM_LINT_CONCURRENCY', 8),

    /*
    |--------------------------------------------------------------------------
    | Child Theme Parent Symbols
    |--------------------------------------------------------------------------
    |
    | When a scanned theme declares "Template" in style.css, PHPStan loads the
    | parent theme as a symbol source. Parent files are not reported as scan
    | targets; they only provide functions, classes, and constants used by the
    | child theme.
    |
    | Parent themes are discovered automatically. Additional plugins, themes,
    | or shared libraries can be supplied as comma-separated dependency paths.
    | Every dependency provides symbols only and is not included in findings.
    |
    */

    'scan_parent_theme' => (bool) env('PRISM_SCAN_PARENT_THEME', true),

    'dependency_paths' => env('PRISM_DEPENDENCY_PATHS', ''),

    // Backward-compatible single parent override.
    'parent_theme_path' => env('PRISM_PARENT_THEME_PATH', ''),

    'dashboard_files_per_page' => (int) env('PRISM_DASHBOARD_FILES_PER_PAGE', 12),

    'dashboard_issues_per_file' => (int) env('PRISM_DASHBOARD_ISSUES_PER_FILE', 80),

    /*
    |--------------------------------------------------------------------------
    | Tool Binaries
    |--------------------------------------------------------------------------
    */

    'binaries' => [
        'php' => env('PRISM_PHP_BINARY', PHP_BINARY),
        'phpcs' => env('PRISM_PHPCS_BINARY', base_path('vendor/bin/phpcs')),
        'php_cs_fixer' => env('PRISM_PHP_CS_FIXER_BINARY', base_path('vendor/bin/php-cs-fixer')),
        'pint' => env('PRISM_PINT_BINARY', base_path('vendor/bin/pint')),
        'phpstan' => env('PRISM_PHPSTAN_BINARY', base_path('vendor/bin/phpstan')),
        'composer' => env('PRISM_COMPOSER_BINARY', 'composer'),
        'git' => env('PRISM_GIT_BINARY', 'git'),
    ],

    /*
    |--------------------------------------------------------------------------
    | File Extensions
    |--------------------------------------------------------------------------
    */

    'extensions' => [
        'php',
    ],

];
