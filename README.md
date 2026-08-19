# Prism Code Checker

Centralized local pre-push code quality gateway for PHP, WordPress, Laravel,
and CodeIgniter projects.

Install once on your machine. Analyze any local project without installing PHPCS, PHPStan, or WordPress Coding Standards inside each client repo.

## Features

- PHP syntax linting
- PHPCS (PSR-12 by default)
- PHPStan
- Official CodeIgniter PHPStan extension and coding standard
- Official Laravel Larastan extension and Pint coding standard
- Official WordPress PHPStan extension and WordPress Coding Standards
- Laravel project detection
- CodeIgniter 3/4 detection and framework-aware analysis
- Composer validate + audit
- Changed-files and full-project scans
- Browser dashboard + CLI

## Requirements

- PHP 8.3+
- Composer
- Git
- SQLite

## Quick start (local)

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Start the dashboard on localhost only:

```bash
php artisan serve --host=127.0.0.1 --port=8787
```

Open [http://127.0.0.1:8787](http://127.0.0.1:8787)

### Optional path restriction

```env
PROJECTS_ROOT=D:\wamp64\www
```

When set, only folders under that root can be scanned.

## CLI

Windows:

```bat
prism-check.bat .
prism-check.bat D:\wamp64\www\my-project --changed
prism-check.bat . --full --open
```

Unix:

```bash
chmod +x prism-check
./prism-check .
./prism-check /path/to/project --changed
./prism-check . --full --open
```

Or via Artisan:

```bash
php artisan prism:check . --changed
```

### Supported PHP frameworks

- Laravel is detected from `artisan` or `laravel/framework`.
- CodeIgniter 4 is detected from `spark`, `app/Config`, or
  `codeigniter4/framework`.
- CodeIgniter 3 is detected from `application/config`, `system/core`, or
  `codeigniter/framework`.

Laravel scans use `larastan/larastan` for PHPStan and Laravel Pint for style.
WordPress scans use `szepeviktor/phpstan-wordpress` for PHPStan (core stubs,
dynamic return types, hook docblocks) and WPCS for style. ACF Pro and
WooCommerce stubs are bundled in Prism, so `get_field()`, `wc_get_product()`,
and similar APIs resolve without extra configuration. Other plugin symbols can
still be supplied through `--dependencies` or nested project `vendor` trees.
CodeIgniter scans analyze application PHP with PHP Lint, static analysis,
coding-standard, and Composer checks. CI4 uses the maintained
`codeigniter/phpstan-codeigniter` extension and `codeigniter/coding-standard`
through PHP-CS-Fixer. CI3 retains the PHPCS and PHPStan compatibility profile
because the official CI4 packages do not support CI3. Framework and vendor code
is loaded as symbol sources but excluded from findings.

## Docker

```bash
# set host projects directory
set PROJECTS_ROOT=D:\wamp64\www
docker compose up --build
```

Dashboard: [http://127.0.0.1:8787](http://127.0.0.1:8787)

Projects are mounted read-only at `/projects`.

## Issue categories

Linters report thousands of rules and most of them describe layout, not behaviour. Prism assigns every finding one of four categories so real problems stay visible:

| Category | What it holds | Severity | Blocks a push |
| --- | --- | --- | --- |
| `security` | Unescaped output, unsanitised input, missing nonces, unprepared SQL, `eval`, Composer advisories | As reported | Yes |
| `bug` | Syntax errors, undefined functions/classes/variables, assignment in condition, global overrides | As reported | Yes |
| `practice` | Deprecated or discouraged calls, unenqueued assets, loose comparisons, commented-out code | Capped at `warning` | No |
| `style` | Spacing, indentation, quotes, Yoda conditions, array syntax, naming, docblocks | Always `notice` | No |

The mapping lives in `App\CodeAnalysis\Services\IssueClassifier`. It is an allowlist: only rules listed there are promoted, so an unrecognised sniff is treated as `style` and can never inflate the must-fix list. To promote a rule, add its sniff prefix to `RULE_MAP` — the longest matching prefix wins, so `Generic.CodeAnalysis.` can map to `bug` while `Generic.CodeAnalysis.UnusedFunctionParameter` maps to `practice`.

## Architecture

Analyzers implement `AnalyzerInterface`. Controllers only validate requests and call services. Tool output is normalized into a shared issue format before being stored in SQLite.

## Tests

```bash
php artisan test
```

## Large projects

Prism automatically manages memory for scans — developers do not need to change `php.ini`. Scans:

- raise the PHP memory limit internally during analysis (default `512M`)
- run PHPStan once for normal projects, with adaptive batching only after a
  timeout or execution failure
- process PHPCS and WordPress checks in batches (default 25 files)
- lint files in parallel processes (default 8) and print live progress
- write issues to the database in blocks as each analyzer completes
- stop a tool once it reports 40,000 findings, mark the result partial, and keep
  going with the remaining tools

Optional overrides in `.env`:

```env
PRISM_MEMORY_LIMIT=512M
PRISM_PHPSTAN_MEMORY_LIMIT=512M
PRISM_BATCH_SIZE=25
PRISM_PHPSTAN_FULL_RUN_FIRST=true
PRISM_PHPSTAN_FULL_RUN_TIMEOUT=120
PRISM_MAX_ISSUES=40000
PRISM_LINT_CONCURRENCY=8
PRISM_SCAN_PARENT_THEME=true
# Folder names skipped in every project
# PRISM_EXCLUDE=ckfinder,bower_components
# PRISM_DEPENDENCY_PATHS=D:\path\to\acf,D:\path\to\shared-library
```

### Skipping folders

Legacy copies, generated code, and bundled third-party tools produce findings
nobody will act on. List them in a `.prismignore` file — one folder name,
relative path, or wildcard per line:

```
# skipped by Prism
_legacy_ci3
_php84_patches
public/ckfinder
patched_*
```

The file is read from two places, and both apply:

| Location | Applies to |
| --- | --- |
| `.prismignore` in the Prism install folder | every project Prism scans |
| `.prismignore` in a scanned project's root | that project only |

Point the shared file somewhere else with `PRISM_IGNORE_FILE`. Every scan prints
the folders it skipped, so a file in the wrong place is easy to spot.

External code used by a project can be supplied as comma-separated dependency
paths through `--dependencies`, the dashboard field, or
`PRISM_DEPENDENCY_PATHS`. Dependencies can be plugins, themes, or shared
libraries. For example, adding the ACF plugin makes functions such as
`get_field()` available to PHPStan without reporting ACF's own issues.

WordPress parent themes are also added automatically using the child theme's
`Template:` header. Parent and dependency files are symbol sources only, so
their issues are not included in the project report.

Prism also discovers every Composer `vendor` directory nested inside the
project, loads each `autoload.php`, and uses those vendor trees as symbol
sources. This supports themes that bundle separate third-party libraries under
folders such as `includes/library/vendor`. Vendor issues remain excluded from
the report. Legacy names created with `class_alias()` are extracted into a safe
PHPStan bootstrap so their available methods and properties are recognized.

Example using ACF, another plugin, and a parent theme:

```bash
php artisan prism:check D:\themes\Divi-child --full --dependencies="D:\wordpress\wp-content\plugins\advanced-custom-fields-pro,D:\wordpress\wp-content\plugins\custom-api,D:\shared\themes\Divi"
```

## MVP status labels

- `READY TO PUSH` when there are no critical/error issues
- `FIX REQUIRED` when critical or error issues exist

Warnings do not block by default.
