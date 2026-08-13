<?php

namespace App\CodeAnalysis\Services;

use InvalidArgumentException;
use RuntimeException;

class PathValidator
{
    public function validate(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            throw new InvalidArgumentException('Project path is required.');
        }

        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Invalid project path.');
        }

        $resolved = realpath($path);

        if ($resolved === false) {
            throw new InvalidArgumentException("Path does not exist: {$path}");
        }

        if (! is_dir($resolved)) {
            throw new InvalidArgumentException("Path is not a directory: {$resolved}");
        }

        if (! is_readable($resolved)) {
            throw new InvalidArgumentException("Path is not readable: {$resolved}");
        }

        $this->assertWithinProjectsRoot($resolved);

        return $resolved;
    }

    public function assertWithinProjectsRoot(string $resolvedPath): void
    {
        $root = config('codechecker.projects_root');

        if ($root === null || $root === '') {
            return;
        }

        $resolvedRoot = realpath($root);

        if ($resolvedRoot === false) {
            throw new RuntimeException("Configured PROJECTS_ROOT does not exist: {$root}");
        }

        $normalizedPath = $this->normalize($resolvedPath);
        $normalizedRoot = $this->normalize($resolvedRoot);

        if ($normalizedPath !== $normalizedRoot && ! str_starts_with($normalizedPath, $normalizedRoot.'/')) {
            throw new InvalidArgumentException(
                "Path must be inside PROJECTS_ROOT ({$resolvedRoot})."
            );
        }
    }

    public function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
