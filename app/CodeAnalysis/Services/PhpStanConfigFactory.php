<?php

namespace App\CodeAnalysis\Services;

use App\CodeAnalysis\DTO\ProjectContext;

class PhpStanConfigFactory
{
    public function make(ProjectContext $project, array $files): string
    {
        $directory = storage_path('app/phpstan');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $directory.DIRECTORY_SEPARATOR.'scan-'.bin2hex(random_bytes(8)).'.neon';
        file_put_contents($path, $this->contents($project, $files));

        return $path;
    }

    /**
     * @param  array<int, string>  $files
     */
    public function contents(ProjectContext $project, array $files): string
    {
        $lines = [
            'parameters:',
            '    level: '.$this->level($project),
            '    reportUnmatchedIgnoredErrors: false',
            '    parallel:',
            '        maximumNumberOfProcesses: 1',
            '    tmpDir: '.$this->neonPath(storage_path('framework/cache/phpstan')),
            '    paths:',
        ];

        foreach ($files as $file) {
            $lines[] = '        - '.$this->neonPath($file);
        }

        $scanFiles = $this->scanFiles($project);
        $scanDirectories = $this->scanDirectories($project);
        $excludePaths = $this->excludePaths($project);

        if ($scanFiles !== []) {
            $lines[] = '    scanFiles:';
            foreach ($scanFiles as $file) {
                $lines[] = '        - '.$this->neonPath($file);
            }
        }

        if ($scanDirectories !== []) {
            $lines[] = '    scanDirectories:';
            foreach ($scanDirectories as $directory) {
                $lines[] = '        - '.$this->neonPath($directory);
            }
        }

        if ($excludePaths !== []) {
            $lines[] = '    excludePaths:';
            foreach ($excludePaths as $exclude) {
                $lines[] = '        - '.$this->neonPath($exclude);
            }
        }

        $projectConfig = $this->projectConfig($project);

        if ($projectConfig !== null) {
            return "includes:\n    - ".$this->neonPath($projectConfig)."\n\n".implode("\n", $lines)."\n";
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array<int, string>
     */
    public function scanFiles(ProjectContext $project): array
    {
        if (! $project->isWordPress()) {
            return [];
        }

        $stubs = [
            base_path('vendor/php-stubs/wordpress-stubs/wordpress-stubs.php'),
            base_path('tools/phpstan/wordpress-lite-stubs.php'),
        ];

        return array_values(array_filter($stubs, 'is_file'));
    }

    /**
     * @return array<int, string>
     */
    public function scanDirectories(ProjectContext $project): array
    {
        if (! $project->isWordPress()) {
            return [];
        }

        $directories = [];

        if (config('codechecker.scan_parent_theme', false)) {
            $parent = $this->parentThemePath($project);

            if ($parent !== null) {
                $directories[] = $parent;
            }
        }

        return $directories;
    }

    public function parentThemePath(ProjectContext $project): ?string
    {
        $style = $project->path.DIRECTORY_SEPARATOR.'style.css';

        if (! is_file($style)) {
            return null;
        }

        $contents = (string) file_get_contents($style, false, null, 0, 8192);

        if (preg_match('/^\s*Template:\s*(.+)$/mi', $contents, $matches) !== 1) {
            return null;
        }

        $slug = trim($matches[1]);

        if ($slug === '') {
            return null;
        }

        $parent = dirname($project->path).DIRECTORY_SEPARATOR.$slug;

        if (! is_dir($parent) || realpath($parent) === realpath($project->path)) {
            return null;
        }

        return realpath($parent) ?: $parent;
    }

    private function level(ProjectContext $project): int
    {
        return $project->isWordPress() ? 4 : 5;
    }

    private function projectConfig(ProjectContext $project): ?string
    {
        foreach (['phpstan.neon', 'phpstan.neon.dist'] as $file) {
            if ($project->configurationFiles[$file] ?? false) {
                return $project->path.DIRECTORY_SEPARATOR.$file;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function excludePaths(ProjectContext $project): array
    {
        $names = array_merge(
            config('codechecker.exclude', []),
            ['wp-admin', 'wp-includes', 'uploads', 'upgrade', 'languages']
        );

        $paths = [];

        foreach (array_unique($names) as $name) {
            $candidate = $project->path.DIRECTORY_SEPARATOR.$name;

            if (file_exists($candidate)) {
                $paths[] = $candidate;
            }
        }

        $core = [
            $project->path.DIRECTORY_SEPARATOR.'wp-admin',
            $project->path.DIRECTORY_SEPARATOR.'wp-includes',
            $project->path.DIRECTORY_SEPARATOR.'wp-content'.DIRECTORY_SEPARATOR.'uploads',
        ];

        foreach ($core as $candidate) {
            if (file_exists($candidate)) {
                $paths[] = $candidate;
            }
        }

        return array_values(array_unique($paths));
    }

    private function neonPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        return "'".str_replace("'", "''", $normalized)."'";
    }
}
