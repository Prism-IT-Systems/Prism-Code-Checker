<?php

namespace App\CodeAnalysis\DTO;

class ProjectContext
{
    /**
     * @param  array<int, string>  $files
     * @param  array<string, bool|string|null>  $configurationFiles
     * @param  array<string, mixed>  $git
     * @param  array<int, string>  $dependencyPaths
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
        public ?string $parentThemePath = null,
        public array $dependencyPaths = [],
    ) {}

    public function isWordPress(): bool
    {
        return $this->type === 'wordpress';
    }

    public function isLaravel(): bool
    {
        return $this->type === 'laravel';
    }

    public function isCodeIgniter(): bool
    {
        return in_array($this->type, ['codeigniter3', 'codeigniter4'], true);
    }

    public function isCodeIgniter3(): bool
    {
        return $this->type === 'codeigniter3';
    }

    public function isCodeIgniter4(): bool
    {
        return $this->type === 'codeigniter4';
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'codeigniter3' => 'CodeIgniter 3',
            'codeigniter4' => 'CodeIgniter 4',
            default => ucfirst($this->type),
        };
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
