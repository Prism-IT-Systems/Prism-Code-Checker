# Prism Code Checker — Implementation Specification

## Objective

Build a standalone local code-quality application named **Prism Code Checker**.

The purpose of this application is to allow developers to analyze any local PHP or WordPress project before pushing code to Git or deploying it to a live server.

Developers should install Prism Code Checker only once on their computer.

They should **not need to install PHPCS, PHPStan, WordPress Coding Standards, or other analysis tools individually inside every client project**.

The application should support both:

1. CLI execution
2. Browser-based dashboard

Example CLI usage:

```bash
prism-check /home/user/projects/my-project
```

Or:

```bash
prism-check .
```

The browser dashboard should allow the developer to enter/select a local project folder and run the analysis.

---

# Technology Stack

Use:

- Laravel
- PHP 8.2+
- SQLite
- Blade
- Alpine.js or Livewire if required
- Symfony Process component
- Docker / Docker Compose
- Composer
- Git CLI

Do not build React for the initial version.

The application should be simple to install and maintain.

---

# Main Architecture

Use the following architecture:

```text
prism-code-checker/
├── app/
│   ├── CodeAnalysis/
│   │   ├── Contracts/
│   │   │   └── AnalyzerInterface.php
│   │   │
│   │   ├── Analyzers/
│   │   │   ├── PhpLintAnalyzer.php
│   │   │   ├── PhpCsAnalyzer.php
│   │   │   ├── PhpStanAnalyzer.php
│   │   │   ├── WordPressAnalyzer.php
│   │   │   └── ComposerAnalyzer.php
│   │   │
│   │   ├── DTO/
│   │   │   ├── AnalysisIssue.php
│   │   │   └── AnalysisResult.php
│   │   │
│   │   ├── Services/
│   │   │   ├── AnalysisRunner.php
│   │   │   ├── ProjectDetector.php
│   │   │   ├── GitService.php
│   │   │   └── ResultNormalizer.php
│   │   │
│   │   └── Profiles/
│   │       ├── PhpProfile.php
│   │       ├── WordPressProfile.php
│   │       └── LaravelProfile.php
│   │
│   ├── Models/
│   │   ├── Project.php
│   │   ├── Scan.php
│   │   └── ScanIssue.php
│   │
│   └── Http/
│       └── Controllers/
│           ├── DashboardController.php
│           └── ScanController.php
│
├── config/
│   └── codechecker.php
│
├── database/
│   └── database.sqlite
│
├── tools/
│
├── resources/views/
│
├── docker/
│
├── docker-compose.yml
└── prism-check
```

---

# Important Design Rule

The target project must never be modified during a normal scan.

Mount or access the project as **read-only whenever possible**.

Analysis tools should inspect the project but not change files.

Future auto-fix functionality should be handled separately.

---

# Analyzer Interface

All analysis tools must implement a common interface.

Create:

```php
interface AnalyzerInterface
{
    public function name(): string;

    public function supports(ProjectContext $project): bool;

    public function analyze(ProjectContext $project): AnalysisResult;
}
```

Do not place analyzer-specific logic inside controllers.

`AnalysisRunner` should be responsible for executing applicable analyzers.

Concept:

```php
foreach ($this->analyzers as $analyzer) {

    if (!$analyzer->supports($project)) {
        continue;
    }

    $results[] = $analyzer->analyze($project);
}
```

This architecture must make it easy to add additional analyzers later.

---

# Normalized Issue Format

Every analyzer returns different output.

Convert all tool output into one standard format.

Use approximately this structure:

```php
class AnalysisIssue
{
    public string $file;

    public ?int $line;

    public ?int $column;

    public string $severity;

    public string $tool;

    public ?string $rule;

    public string $message;

    public bool $fixable = false;
}
```

Example normalized issue:

```json
{
    "file": "includes/class-payment.php",
    "line": 82,
    "column": 14,
    "severity": "error",
    "tool": "phpstan",
    "rule": "variable.undefined",
    "message": "Undefined variable $customerId",
    "fixable": false
}
```

Allowed severities:

```text
critical
error
warning
notice
info
```

---

# Analyzer 1 — PHP Syntax Check

Create:

```text
PhpLintAnalyzer
```

Purpose:

Detect PHP syntax errors.

Execute:

```bash
php -l filename.php
```

Scan PHP files recursively.

Ignore:

```text
vendor/
node_modules/
.git/
storage/
cache/
dist/
build/
```

Where possible, scan only relevant source files.

Example issue:

```text
File:
includes/payment.php

Line:
82

Tool:
PHP Lint

Severity:
critical

Message:
Unexpected token "}"
```

A PHP syntax error should always be considered critical.

---

# Analyzer 2 — PHPCS

Create:

```text
PhpCsAnalyzer
```

PHPCS should be installed inside Prism Code Checker, not inside every project.

Use machine-readable output such as:

```bash
phpcs --report=json
```

The analyzer must parse JSON output.

If the target project contains:

```text
phpcs.xml
phpcs.xml.dist
.phpcs.xml
```

use the project's configuration.

Otherwise use Prism default configuration.

For normal PHP projects use PSR-12.

For WordPress projects use WordPress Coding Standards.

---

# Analyzer 3 — PHPStan

Create:

```text
PhpStanAnalyzer
```

Execute PHPStan using JSON output.

If the project contains:

```text
phpstan.neon
phpstan.neon.dist
```

use that configuration.

Otherwise use Prism's default configuration.

Do not analyze:

```text
vendor/
node_modules/
.git/
storage/framework/
cache/
```

PHPStan should use the target project's:

```text
vendor/autoload.php
```

if available.

If dependencies are missing, return a clear warning such as:

```text
Project Composer dependencies are not installed.
Run composer install before performing full static analysis.
```

Do not allow the scan to crash.

---

# Analyzer 4 — WordPress Coding Standards

When the project is detected as WordPress, enable WordPress Coding Standards.

Check for:

- escaping problems
- sanitization issues
- nonce problems where detectable
- SQL preparation issues
- naming standards
- deprecated functions
- unsafe output
- WordPress coding standards

Relevant PHPCS rulesets should include:

```text
WordPress
WordPress-Core
WordPress-Docs
WordPress-Extra
```

Use the appropriate combination for practical code reviews.

---

# Analyzer 5 — Composer

Create:

```text
ComposerAnalyzer
```

If `composer.json` exists, execute:

```bash
composer validate
```

Also support:

```bash
composer audit
```

Display dependency/security warnings separately.

A Composer problem should not stop other analyzers from running.

---

# Project Detection

Create:

```text
ProjectDetector
```

When a folder is scanned, automatically determine project type.

Supported types initially:

```text
php
wordpress
laravel
unknown
```

WordPress detection examples:

```text
wp-config.php
wp-content/
wp-includes/
```

Also detect WordPress plugins:

```text
plugin PHP file containing:
Plugin Name:
```

Laravel detection:

```text
artisan
composer.json contains laravel/framework
```

Generic PHP:

```text
composer.json
*.php files
```

Return something similar to:

```php
ProjectContext {
    path
    type
    phpVersion
    composerAvailable
    gitRepository
    branch
    configurationFiles
}
```

---

# Analysis Profiles

Create profiles to decide which analyzers execute.

## PHP Profile

Run:

```text
PHP Lint
PHPCS
PHPStan
Composer validation
Composer audit
```

## WordPress Profile

Run:

```text
PHP Lint
PHPCS
WordPress Coding Standards
PHPStan
Composer validation if available
Composer audit if available
```

## Laravel Profile

Run:

```text
PHP Lint
PHPCS
PHPStan
Composer validation
Composer audit
```

Later Larastan can be added.

---

# Git Integration

Create:

```text
GitService
```

Detect whether the selected project is a Git repository.

Fetch:

```text
Current branch
Changed files
Staged files
Untracked files
Repository root
```

Commands may include:

```bash
git status --porcelain
git branch --show-current
git diff --name-only
git diff --cached --name-only
```

Do not assume the user's current shell directory is the repository.

Always execute Git commands using the selected project's working directory.

---

# Changed Files Scan

Implement an important mode:

```text
Scan Changed Files
```

Instead of scanning the complete project, only analyze files changed by the developer.

Include:

```text
modified files
staged files
new/untracked files
```

Only include relevant file extensions.

Initially:

```text
.php
```

Later:

```text
.js
.ts
.jsx
.tsx
.css
```

can be supported.

---

# Full Scan

Also support:

```text
Full Project Scan
```

This analyzes all applicable source files.

Exclude common large/generated directories.

Default exclusions:

```text
.git/
vendor/
node_modules/
storage/
cache/
coverage/
build/
dist/
logs/
tmp/
```

Make exclusions configurable.

---

# Browser Dashboard

Create a clean local dashboard.

Home page:

```text
Prism Code Checker

Project Path
[ /home/user/projects/client-site              ]

[ Detect Project ]

Project:
WordPress

Git Branch:
feature/payment-update

PHP:
8.2

Composer:
Available

[ Scan Changed Files ]
[ Run Full Scan ]
```

---

# Scan Result Header

After scan:

```text
Project: Client Website

Branch:
feature/payment-update

Project Type:
WordPress

Files Checked:
14

Scan Duration:
8.4 seconds
```

Show summary cards:

```text
Critical
2

Errors
6

Warnings
18

Notices
34
```

---

# Result Filters

Provide filters for:

```text
All

Critical

Errors

Warnings

Notices

PHP Lint

PHPCS

PHPStan

WordPress

Composer
```

Allow searching by:

```text
filename
message
rule
```

---

# Issue Display

Each issue should show:

```text
includes/class-payment.php

Line 82

ERROR

PHPStan

Undefined variable $customerId

Rule:
variable.undefined
```

Make filename and line highly visible.

If possible provide a copyable format:

```text
includes/class-payment.php:82
```

---

# Group Results By File

Results should preferably be grouped:

```text
includes/class-payment.php
    ERROR PHPStan
    WARNING PHPCS
    WARNING WordPress

functions.php
    ERROR PHP Lint
```

Allow collapse/expand.

---

# Scan Status

Final result should show one of:

```text
READY TO PUSH
```

or:

```text
FIX REQUIRED
```

Initial rule:

`FIX REQUIRED` when there is at least one:

```text
critical
error
```

Warnings should not automatically block a push initially.

Make this configurable later.

---

# CLI Command

Create a CLI wrapper named:

```text
prism-check
```

Support:

```bash
prism-check .
```

```bash
prism-check /home/user/projects/project-a
```

Support options:

```bash
prism-check . --changed
```

```bash
prism-check . --full
```

```bash
prism-check . --open
```

Desired behaviour:

```text
$ prism-check . --changed

Prism Code Checker

Project:
client-site

Type:
WordPress

Branch:
feature/payment-update

Changed PHP Files:
7

Running checks...

✓ PHP Syntax
✗ PHPStan
⚠ PHPCS
⚠ WordPress Standards

Critical: 0
Errors: 2
Warnings: 9

Result:
FIX REQUIRED

Dashboard:
http://localhost:8787/scans/123
```

---

# Browser Opening

With:

```bash
prism-check . --open
```

after completing the scan, automatically open the scan report in the user's default browser.

Support:

```text
Windows
macOS
Linux
```

if practical.

---

# Database

Use SQLite initially.

Create these tables.

## projects

Fields:

```text
id
name
path
type
created_at
updated_at
```

## scans

Fields:

```text
id
project_id
branch
scan_type
status
files_scanned
critical_count
error_count
warning_count
notice_count
started_at
completed_at
duration
created_at
```

`scan_type`:

```text
changed
full
```

## scan_issues

Fields:

```text
id
scan_id
file
line
column
severity
tool
rule
message
fixable
created_at
```

---

# Security Requirements

This application executes commands against arbitrary project directories, so path handling is extremely important.

Never concatenate unsanitized project paths directly into shell commands.

Do NOT do:

```php
exec("phpstan analyse " . $userPath);
```

Use Symfony Process with argument arrays:

```php
new Process([
    'phpstan',
    'analyse',
    $projectPath,
]);
```

Validate that the selected folder:

- exists
- is a directory
- is readable
- does not contain invalid path traversal
- is within configured allowed locations if restrictions are enabled

Do not expose Prism Code Checker publicly.

The web server should bind to localhost by default.

Example:

```text
127.0.0.1:8787
```

Not:

```text
0.0.0.0
```

unless explicitly configured.

---

# Process Execution

Create a centralized service such as:

```text
CommandRunner
```

Responsibilities:

- execute command
- set working directory
- configure timeout
- capture STDOUT
- capture STDERR
- capture exit code
- record execution duration
- handle command failure gracefully

Example concept:

```php
$result = $commandRunner->run(
    command: [
        'vendor/bin/phpstan',
        'analyse',
        '--error-format=json',
    ],
    workingDirectory: $projectPath,
    timeout: 120
);
```

Analyzers should not each implement their own unsafe shell execution logic.

---

# Timeouts

Analysis tools must have execution timeouts.

Suggested defaults:

```text
PHP Lint:
60 seconds

PHPCS:
120 seconds

PHPStan:
180 seconds

Composer:
120 seconds
```

Make these configurable.

A timeout should generate a warning instead of crashing the entire scan.

---

# Failed Analyzer Handling

One analyzer failing must not prevent other analyzers from executing.

For example:

```text
PHP Lint       PASSED

PHPCS          PASSED

PHPStan        FAILED
Reason:
Unable to load project autoloader.

Composer       PASSED
```

Display analyzer execution failures separately from actual code issues.

---

# Tool Configuration Priority

Use this priority:

```text
1. Project-specific configuration
2. Prism project-type configuration
3. Prism global default configuration
```

For example PHPStan:

```text
project/phpstan.neon
```

takes priority over:

```text
prism/config/phpstan/wordpress.neon
```

which takes priority over:

```text
prism/config/phpstan/default.neon
```

Use the same concept for PHPCS.

---

# Initial Docker Setup

Provide:

```text
docker-compose.yml
```

The container should include:

- PHP
- Composer
- PHPCS
- PHPStan
- WordPress Coding Standards
- Git

The Laravel dashboard should run inside the container.

Expose:

```text
127.0.0.1:8787
```

The developer's project directory needs to be accessible to the container.

Design the mounting strategy carefully because developers need to scan different project directories.

For the MVP, it is acceptable to configure a parent project directory.

Example `.env`:

```env
PROJECTS_ROOT=/home/user/projects
```

Mount:

```text
/home/user/projects:/projects:ro
```

The UI should then only allow projects underneath:

```text
/projects
```

This is safer than mounting the entire host filesystem.

---

# Configuration

Create:

```text
config/codechecker.php
```

Example:

```php
return [

    'projects_root' => env(
        'PROJECTS_ROOT',
        '/projects'
    ),

    'exclude' => [
        '.git',
        'vendor',
        'node_modules',
        'storage',
        'cache',
        'coverage',
        'dist',
        'build',
    ],

    'blocking_severities' => [
        'critical',
        'error',
    ],

];
```

---

# Ignore Existing Project Problems

Legacy projects may contain hundreds of existing problems.

Eventually we want Prism Code Checker to differentiate:

```text
Existing Issues

vs

Issues Introduced By Current Changes
```

For MVP, focus on Changed Files Scan.

Architect the system so baseline comparison can be added later.

Do not tightly couple scans to the current result set.

---

# Future Baseline Feature

Future flow:

```text
Main branch scan
        ↓
Baseline
        ↓
Developer changes
        ↓
New scan
        ↓
Compare
        ↓
Only show newly introduced issues
```

Desired result:

```text
Existing Issues:
482

New Issues:
3

Resolved Issues:
7
```

Do not implement this unless the MVP is already stable, but keep database and service architecture extendable.

---

# Future Prism Custom Rules

Eventually add company-specific rules such as detecting:

```text
var_dump()

print_r()

die()

exit()

dd()

dump()

hard-coded API keys

hard-coded passwords

hard-coded production URLs

unescaped WordPress output

direct $_POST usage

direct $_GET usage

missing sanitization

unsafe SQL queries

missing $wpdb->prepare()

debug logging accidentally committed

WP_DEBUG enabled

development URLs

TODO markers

FIXME markers
```

Create an architecture allowing:

```text
CustomRuleAnalyzer
```

to be added later.

Do not implement all these rules in MVP.

---

# Future Git Hook

Later provide:

```bash
prism-check install-hook
```

This can install a Git `pre-push` hook.

Flow:

```text
Developer
    ↓
git push
    ↓
Prism Code Checker
    ↓
Scan changed files
    ↓
Critical/Error?
    ↓
YES → Cancel push
NO  → Continue push
```

Do not automatically install Git hooks.

This must always be opt-in.

---

# MVP Development Order

Implement in this order.

## Phase 1 — Laravel Foundation

Create:

- Laravel project
- SQLite database
- Project model
- Scan model
- ScanIssue model
- basic dashboard
- configuration

## Phase 2 — Project Detection

Implement:

- path validation
- PHP detection
- WordPress detection
- Laravel detection
- Git repository detection
- branch detection

## Phase 3 — Analyzer Framework

Implement:

- AnalyzerInterface
- AnalysisIssue DTO
- AnalysisResult DTO
- CommandRunner
- AnalysisRunner

## Phase 4 — PHP Lint

Implement PHP syntax analysis first.

Make sure scan → normalize → database → UI works completely before adding another analyzer.

## Phase 5 — PHPCS

Add:

- PHPCS execution
- JSON parser
- normalized issues
- project configuration detection

## Phase 6 — PHPStan

Add:

- PHPStan execution
- JSON parser
- autoload detection
- project config detection

## Phase 7 — WordPress Profile

Add:

- WPCS
- WordPress project detection
- WordPress-specific profile

## Phase 8 — Git Changed Files

Add:

- changed files
- staged files
- untracked files
- changed scan mode

## Phase 9 — Dashboard Improvement

Add:

- counts
- filters
- grouping
- scan history
- READY TO PUSH / FIX REQUIRED

## Phase 10 — CLI

Create:

```text
prism-check
```

with:

```text
--changed
--full
--open
```

---

# Coding Standards For This Project

Follow:

```text
SOLID principles

service-based architecture

dependency injection

small classes

single responsibility

PSR-12

Laravel conventions
```

Do not create very large controllers.

Controllers should mainly:

```text
validate request
call service
return response/view
```

Business logic belongs in services.

---

# Tests

Add automated tests for important components.

At minimum test:

```text
ProjectDetector

GitService

PHP lint output parser

PHPCS output parser

PHPStan output parser

ResultNormalizer

scan severity calculations

path validation
```

Create fixtures containing deliberately broken PHP files.

Example:

```text
tests/Fixtures/php/syntax-error.php

tests/Fixtures/php/undefined-variable.php

tests/Fixtures/wordpress/unescaped-output.php
```

Use these fixtures to ensure analyzers produce predictable results.

---

# Definition Of MVP Complete

MVP is complete when a developer can:

```bash
git clone prism-code-checker
```

perform initial setup, then run:

```bash
prism-check /projects/client-project --changed
```

and receive something similar to:

```text
Prism Code Checker

Project:
client-project

Type:
WordPress

Branch:
feature/payment

Changed Files:
8

PHP Syntax
✓ Passed

PHPStan
✗ 2 errors

PHPCS
⚠ 7 warnings

WordPress Standards
⚠ 4 warnings

---------------------------------

Critical: 0
Errors: 2
Warnings: 11

FIX REQUIRED
```

The developer must also be able to visit:

```text
http://localhost:8787
```

and view the complete issues in a readable dashboard with:

- filename
- line number
- severity
- analyzer
- rule
- message
- filtering
- scan history

---

# Important Implementation Instructions For Cursor

Implement incrementally.

Do not generate the entire application in one huge change.

Start with the Laravel architecture and models.

Then implement one complete end-to-end analyzer:

```text
PHP Lint
```

Make sure this full workflow works:

```text
Project selected
    ↓
Scan created
    ↓
PHP files discovered
    ↓
PHP Lint executed
    ↓
Issues normalized
    ↓
Issues stored
    ↓
Dashboard displays issues
```

Only after this works correctly should PHPCS and PHPStan be added.

Keep all third-party analyzer implementations behind `AnalyzerInterface`.

Do not place PHPCS-, PHPStan-, Git-, Docker-, or shell-specific logic directly into controllers.

Prioritize:

```text
Security
Maintainability
Simple installation
Clear error reporting
Extensibility
```

The main goal is not merely to wrap PHPCS.

The final product should become a centralized **pre-push code quality gateway for Prism developers**.