<?php

namespace App\CodeAnalysis\Services;

use App\CodeAnalysis\DTO\CommandResult;

class GitService
{
    public function __construct(
        private readonly CommandRunner $commandRunner,
    ) {}

    public function isRepository(string $path): bool
    {
        return is_dir($path.DIRECTORY_SEPARATOR.'.git')
            || $this->run($path, ['rev-parse', '--is-inside-work-tree'])->successful();
    }

    public function currentBranch(string $path): ?string
    {
        $result = $this->run($path, ['branch', '--show-current']);

        if (! $result->successful()) {
            return null;
        }

        $branch = trim($result->stdout);

        return $branch !== '' ? $branch : null;
    }

    public function repositoryRoot(string $path): ?string
    {
        $result = $this->run($path, ['rev-parse', '--show-toplevel']);

        if (! $result->successful()) {
            return null;
        }

        $root = trim($result->stdout);

        return $root !== '' ? realpath($root) ?: $root : null;
    }

    /**
     * @return array<int, string>
     */
    public function changedFiles(string $path, array $extensions = ['php']): array
    {
        $files = array_unique(array_merge(
            $this->listFiles($path, ['diff', '--name-only', '--diff-filter=ACMR']),
            $this->listFiles($path, ['diff', '--cached', '--name-only', '--diff-filter=ACMR']),
            $this->untrackedFiles($path),
        ));

        $absolute = [];

        foreach ($files as $file) {
            $candidate = $path.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);

            if (! is_file($candidate)) {
                continue;
            }

            $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));

            if ($extensions !== [] && ! in_array($ext, $extensions, true)) {
                continue;
            }

            $absolute[] = realpath($candidate) ?: $candidate;
        }

        sort($absolute);

        return array_values(array_unique($absolute));
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(string $path): array
    {
        if (! $this->isRepository($path)) {
            return [
                'is_repository' => false,
                'branch' => null,
                'root' => null,
                'changed_files' => [],
                'staged_files' => [],
                'untracked_files' => [],
            ];
        }

        return [
            'is_repository' => true,
            'branch' => $this->currentBranch($path),
            'root' => $this->repositoryRoot($path),
            'changed_files' => $this->listFiles($path, ['diff', '--name-only', '--diff-filter=ACMR']),
            'staged_files' => $this->listFiles($path, ['diff', '--cached', '--name-only', '--diff-filter=ACMR']),
            'untracked_files' => $this->untrackedFiles($path),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function untrackedFiles(string $path): array
    {
        return $this->listFiles($path, ['ls-files', '--others', '--exclude-standard']);
    }

    /**
     * @param  array<int, string>  $args
     * @return array<int, string>
     */
    private function listFiles(string $path, array $args): array
    {
        $result = $this->run($path, $args);

        if (! $result->successful() && trim($result->stdout) === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($result->stdout)) ?: [];

        return array_values(array_filter(array_map('trim', $lines)));
    }

    /**
     * @param  array<int, string>  $args
     */
    private function run(string $path, array $args): CommandResult
    {
        $binary = config('codechecker.binaries.git', 'git');

        return $this->commandRunner->run(
            array_merge([$binary], $args),
            $path,
            30
        );
    }
}
