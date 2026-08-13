<?php

namespace App\CodeAnalysis\DTO;

class ProjectContext
{
    /**
     * @param  array<int, string>  $files
     * @param  array<string, bool|string|null>  $configurationFiles
     * @param  array<string, mixed>  $git
     */
    public function __construct(
        public string $path,
        public string $type,
        public ?string $phpVersion = null,
        public bool $composerAvailable = false,
        public bool $gitRepository = false,
        public ?string $branch = null,
        public array $configurationFiles = [],
        public array $files = [],
        public string $scanType = 'full',
        public array $git = [],
    ) {}

    public function isWordPress(): bool
    {
        return $this->type === 'wordpress';
    }

    public function isLaravel(): bool
    {
        return $this->type === 'laravel';
    }

    public function relativePath(string $absolutePath): string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $this->path), '/');
        $normalizedFile = str_replace('\\', '/', $absolutePath);

        if (str_starts_with($normalizedFile, $normalizedRoot.'/')) {
            return substr($normalizedFile, strlen($normalizedRoot) + 1);
        }

        return $normalizedFile;
    }
}
