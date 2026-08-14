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
    */

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

    'scan_parent_theme' => (bool) env('PRISM_SCAN_PARENT_THEME', false),

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
