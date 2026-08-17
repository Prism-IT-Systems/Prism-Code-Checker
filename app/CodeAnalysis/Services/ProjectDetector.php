<?php

namespace App\CodeAnalysis\Services;

use App\CodeAnalysis\DTO\ProjectContext;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class ProjectDetector
{
    public function __construct(
        private readonly PathValidator $pathValidator,
        private readonly GitService $gitService,
    ) {}

    public function detect(string $path, string $scanType = 'full', ?string $dependencyPaths = null): ProjectContext
    {
        $resolved = $this->pathValidator->validate($path);
        $type = $this->detectType($resolved);
        $git = $this->gitService->summary($resolved);
        $configFiles = $this->detectConfigurationFiles($resolved);

        $files = $scanType === 'changed'
            ? $this->gitService->changedFiles($resolved, config('codechecker.extensions', ['php']))
            : $this->discoverPhpFiles($resolved);

        $resolvedDependencies = [];

        foreach ($this->parseDependencyPaths($dependencyPaths) as $dependencyPath) {
            $resolvedDependencies[] = $this->pathValidator->validate($dependencyPath);
        }

        return new ProjectContext(
            path: $resolved,
            type: $type,
            phpVersion: PHP_VERSION,
            composerAvailable: is_file($resolved.DIRECTORY_SEPARATOR.'composer.json'),
            gitRepository: (bool) ($git['is_repository'] ?? false),
            branch: $git['branch'] ?? null,
            configurationFiles: $configFiles,
            files: $files,
            scanType: $scanType,
            git: $git,
            dependencyPaths: array_values(array_unique($resolvedDependencies)),
        );
    }

    /**
     * @return array<int, string>
     */
    private function parseDependencyPaths(?string $paths): array
    {
        if ($paths === null || trim($paths) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[\r\n,]+/', $paths) ?: []
        )));
    }

    public function detectType(string $path): string
    {
        if ($this->isLaravel($path)) {
            return 'laravel';
        }

        if ($this->isWordPress($path)) {
            return 'wordpress';
        }

        $codeIgniterVersion = $this->codeIgniterVersion($path);

        if ($codeIgniterVersion !== null) {
            return 'codeigniter'.$codeIgniterVersion;
        }

        if (is_file($path.DIRECTORY_SEPARATOR.'composer.json') || $this->hasPhpFiles($path)) {
            return 'php';
        }

        return 'unknown';
    }

    public function isLaravel(string $path): bool
    {
        if (is_file($path.DIRECTORY_SEPARATOR.'artisan')) {
            return true;
        }

        $composer = $path.DIRECTORY_SEPARATOR.'composer.json';

        if (! is_file($composer)) {
            return false;
        }

        $json = json_decode((string) file_get_contents($composer), true);

        if (! is_array($json)) {
            return false;
        }

        $require = array_merge($json['require'] ?? [], $json['require-dev'] ?? []);

        return isset($require['laravel/framework']);
    }

    public function isCodeIgniter(string $path): bool
    {
        return $this->codeIgniterVersion($path) !== null;
    }

    public function codeIgniterVersion(string $path): ?int
    {
        $packages = $this->composerPackages($path);

        if (
            isset($packages['codeigniter4/framework'])
            || (
                is_file($path.DIRECTORY_SEPARATOR.'spark')
                && is_dir($path.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Config')
            )
        ) {
            return 4;
        }

        if (
            isset($packages['codeigniter/framework'])
            || (
                is_file($path.DIRECTORY_SEPARATOR.'system'.DIRECTORY_SEPARATOR.'core'.DIRECTORY_SEPARATOR.'CodeIgniter.php')
                && is_file($path.DIRECTORY_SEPARATOR.'application'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'config.php')
            )
        ) {
            return 3;
        }

        return null;
    }

    public function isWordPress(string $path): bool
    {
        $markers = [
            'wp-config.php',
            'wp-content',
            'wp-includes',
            'wp-load.php',
        ];

        foreach ($markers as $marker) {
            if (file_exists($path.DIRECTORY_SEPARATOR.$marker)) {
                return true;
            }
        }

        if ($this->looksLikeWordPressPlugin($path) || $this->looksLikeWordPressTheme($path)) {
            return true;
        }

        return $this->isInsideWordPressTree($path);
    }

    /**
     * @return array<string, bool>
     */
    public function detectConfigurationFiles(string $path): array
    {
        $candidates = [
            'phpcs.xml',
            'phpcs.xml.dist',
            '.phpcs.xml',
            '.phpcs.xml.dist',
            'phpstan.neon',
            'phpstan.neon.dist',
            'composer.json',
            'composer.lock',
        ];

        $found = [];

        foreach ($candidates as $candidate) {
            $found[$candidate] = is_file($path.DIRECTORY_SEPARATOR.$candidate);
        }

        return $found;
    }

    /**
     * @return array<int, string>
     */
    public function discoverPhpFiles(string $path): array
    {
        $exclude = config('codechecker.exclude', []);
        $codeIgniterVersion = $this->codeIgniterVersion($path);

        if ($codeIgniterVersion === 3) {
            $exclude = array_merge($exclude, ['system', 'third_party']);
        } elseif ($codeIgniterVersion === 4) {
            $exclude[] = 'writable';
        }

        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $relative = $this->pathValidator->normalize(
                ltrim(str_replace($path, '', $file->getPathname()), '/\\')
            );

            if ($this->isExcluded($relative, $exclude)) {
                continue;
            }

            $files[] = $file->getRealPath() ?: $file->getPathname();
        }

        sort($files);

        return $files;
    }

    /**
     * @param  array<int, string>  $exclude
     */
    private function isExcluded(string $relativePath, array $exclude): bool
    {
        $parts = explode('/', str_replace('\\', '/', $relativePath));

        foreach ($parts as $part) {
            if (in_array($part, $exclude, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function composerPackages(string $path): array
    {
        $composer = $path.DIRECTORY_SEPARATOR.'composer.json';

        if (! is_file($composer)) {
            return [];
        }

        $json = json_decode((string) file_get_contents($composer), true);

        if (! is_array($json)) {
            return [];
        }

        return array_merge($json['require'] ?? [], $json['require-dev'] ?? []);
    }

    private function hasPhpFiles(string $path): bool
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path.DIRECTORY_SEPARATOR.$entry;

            if (is_file($full) && str_ends_with(strtolower($entry), '.php')) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeWordPressPlugin(string $path): bool
    {
        foreach (glob($path.DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
            $contents = (string) file_get_contents($file, false, null, 0, 8192);

            if (preg_match('/Plugin Name\s*:/i', $contents) === 1) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeWordPressTheme(string $path): bool
    {
        $styleCss = $path.DIRECTORY_SEPARATOR.'style.css';

        if (is_file($styleCss)) {
            $contents = (string) file_get_contents($styleCss, false, null, 0, 8192);

            if (preg_match('/Theme Name\s*:/i', $contents) === 1) {
                return true;
            }
        }

        $normalized = str_replace('\\', '/', $path);

        return (bool) preg_match('#/wp-content/(themes|plugins)(/|$)#i', $normalized);
    }

    private function isInsideWordPressTree(string $path): bool
    {
        $current = realpath($path) ?: $path;

        for ($i = 0; $i < 8; $i++) {
            $parent = dirname($current);

            if ($parent === $current) {
                break;
            }

            foreach (['wp-config.php', 'wp-includes', 'wp-load.php'] as $marker) {
                if (file_exists($parent.DIRECTORY_SEPARATOR.$marker)) {
                    return true;
                }
            }

            $current = $parent;
        }

        return false;
    }
}
