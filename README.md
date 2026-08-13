# Prism Code Checker

Centralized local pre-push code quality gateway for PHP and WordPress projects.

Install once on your machine. Analyze any local project without installing PHPCS, PHPStan, or WordPress Coding Standards inside each client repo.

## Features

- PHP syntax linting
- PHPCS (PSR-12 by default)
- PHPStan
- WordPress Coding Standards
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

## Docker

```bash
# set host projects directory
set PROJECTS_ROOT=D:\wamp64\www
docker compose up --build
```

Dashboard: [http://127.0.0.1:8787](http://127.0.0.1:8787)

Projects are mounted read-only at `/projects`.

## Architecture

Analyzers implement `AnalyzerInterface`. Controllers only validate requests and call services. Tool output is normalized into a shared issue format before being stored in SQLite.

## Tests

```bash
php artisan test
```

## Large projects

Prism automatically manages memory for scans — developers do not need to change `php.ini`. Scans:

- raise the PHP memory limit internally during analysis (default `512M`)
- process PHPCS, PHPStan, and WordPress checks in batches (default 25 files)
- write issues to the database as each analyzer completes

Optional overrides in `.env`:

```env
PRISM_MEMORY_LIMIT=512M
PRISM_PHPSTAN_MEMORY_LIMIT=512M
PRISM_BATCH_SIZE=25
```

## MVP status labels

- `READY TO PUSH` when there are no critical/error issues
- `FIX REQUIRED` when critical or error issues exist

Warnings do not block by default.
