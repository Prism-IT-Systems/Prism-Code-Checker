<?php

namespace App\CodeAnalysis\Services;

use App\CodeAnalysis\DTO\ProjectContext;

class PhpStanConfigFactory
{
    /**
     * Config sections that stay the same for every batch of one project.
     *
     * @var array<string, array{header: array<int, string>, symbols: array<int, string>, sections: array<int, string>, include: ?string}>
     */
    private array $environments = [];

    public function __construct(
        private readonly PathValidator $pathValidator,
    ) {}

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
        $environment = $this->environment($project);
        $lines = $environment['header'];
        $lines[] = '    paths:';

        foreach ($files as $file) {
            $lines[] = '        - '.$this->neonPath($file);
        }

        $scanFiles = array_merge(
            $environment['symbols'],
            $this->projectSymbolFiles($project, $files)
        );

        if ($scanFiles !== []) {
            $lines[] = '    scanFiles:';

            foreach ($scanFiles as $file) {
                $lines[] = '        - '.$this->neonPath($file);
            }
        }

        $lines = array_merge($lines, $environment['sections']);

        if ($environment['include'] !== null) {
            return "includes:\n    - ".$environment['include']."\n\n".implode("\n", $lines)."\n";
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Builds the parts of the config that do not depend on the current batch.
     *
     * Resolving dependencies walks the whole project tree, which is far too
     * slow to repeat for every batch of a large scan.
     *
     * @return array{header: array<int, string>, symbols: array<int, string>, sections: array<int, string>, include: ?string}
     */
    private function environment(ProjectContext $project): array
    {
        $key = implode('|', [
            $project->path,
            $project->type,
            (string) $project->parentThemePath,
            implode(',', $project->dependencyPaths),
        ]);

        if (isset($this->environments[$key])) {
            return $this->environments[$key];
        }

        $header = [
            'parameters:',
            '    level: '.$this->level($project),
            '    reportUnmatchedIgnoredErrors: false',
            '    parallel:',
            '        maximumNumberOfProcesses: 1',
            '    tmpDir: '.$this->neonPath(storage_path('framework/cache/phpstan')),
        ];

        if ($project->isCodeIgniter3()) {
            $header[] = '    universalObjectCratesClasses:';
            $header[] = '        - CI_Controller';
            $header[] = '        - CI_Model';
        }

        $lines = [];
        $scanFiles = $this->scanFiles($project);
        $scanDirectories = $this->scanDirectories($project);
        $bootstrapFiles = $this->dependencyAutoloaders($project);
        $codeIgniterBootstrap = $this->codeIgniterConstantsBootstrap($project);

        if ($codeIgniterBootstrap !== null) {
            $bootstrapFiles[] = $codeIgniterBootstrap;
        }

        $aliasBootstrap = $this->dependencyAliasBootstrap($project);

        if ($aliasBootstrap !== null) {
            $bootstrapFiles[] = $aliasBootstrap;
        }
        $excludePaths = $this->excludePaths($project, $scanDirectories);

        if ($bootstrapFiles !== []) {
            $lines[] = '    bootstrapFiles:';
            foreach ($bootstrapFiles as $file) {
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

        return $this->environments[$key] = [
            'header' => $header,
            'symbols' => $scanFiles,
            'sections' => $lines,
            'include' => $projectConfig !== null ? $this->neonPath($projectConfig) : null,
        ];
    }

    /**
     * Every project file outside the current batch is offered to PHPStan as a
     * symbol source.
     *
     * Batching keeps memory under control, but PHPStan only knows the symbols
     * of the files it is given. Without this, a helper function defined in one
     * batch is reported as undefined everywhere it is called from another.
     *
     * @param  array<int, string>  $files
     * @return array<int, string>
     */
    public function projectSymbolFiles(ProjectContext $project, array $files): array
    {
        if (count($project->files) <= count($files)) {
            return [];
        }

        $analysed = [];

        foreach ($files as $file) {
            $analysed[$this->comparablePath($file)] = true;
        }

        $symbols = [];

        foreach ($project->files as $file) {
            if (! isset($analysed[$this->comparablePath($file)])) {
                $symbols[] = $file;
            }
        }

        return $symbols;
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
        $directories = $this->dependencyDirectories($project->path);

        foreach ($this->externalDependencyPaths($project) as $dependency) {
            $directories[] = $dependency;
        }

        foreach ($this->codeIgniterFrameworkDirectories($project) as $framework) {
            $directories[] = $framework;
        }

        return array_values(array_unique($directories));
    }

    /**
     * CodeIgniter framework and third-party folders provide symbols but should
     * never contribute findings to the application report.
     *
     * @return array<int, string>
     */
    public function codeIgniterFrameworkDirectories(ProjectContext $project): array
    {
        if (! $project->isCodeIgniter()) {
            return [];
        }

        $candidates = [
            $project->path.DIRECTORY_SEPARATOR.'system',
        ];

        if ($project->isCodeIgniter3()) {
            $candidates[] = $project->path
                .DIRECTORY_SEPARATOR.'application'
                .DIRECTORY_SEPARATOR.'third_party';
        }

        return array_values(array_filter(array_map(
            static fn (string $path) => is_dir($path)
                ? (realpath($path) ?: $path)
                : null,
            $candidates
        )));
    }

    /**
     * Resolve explicit plugin/library dependencies and the automatic parent
     * theme into directories used only for PHPStan symbol discovery.
     *
     * @return array<int, string>
     */
    public function externalDependencyPaths(ProjectContext $project): array
    {
        $candidates = $project->dependencyPaths;
        $configured = (string) config('codechecker.dependency_paths', '');

        if (trim($configured) !== '') {
            array_push(
                $candidates,
                ...array_filter(array_map(
                    'trim',
                    preg_split('/[\r\n,]+/', $configured) ?: []
                ))
            );
        }

        if ($project->isWordPress() && config('codechecker.scan_parent_theme', true)) {
            $parent = $this->parentThemePath($project);

            if ($parent !== null) {
                $candidates[] = $parent;
            }
        }

        $resolved = [];

        foreach ($candidates as $candidate) {
            $path = $this->resolveParentDirectory($candidate, $project->path);

            if ($path !== null) {
                $resolved[] = $path;
            }
        }

        return array_values(array_unique($resolved));
    }

    /**
     * Find every Composer vendor tree bundled inside the scanned project.
     *
     * These directories provide symbols only. They are intentionally absent
     * from PHPStan's paths, so third-party defects do not enter the report.
     *
     * @return array<int, string>
     */
    public function dependencyDirectories(string $projectPath): array
    {
        $found = [];
        $root = realpath($projectPath) ?: $projectPath;
        $normalizedRoot = $this->pathValidator->normalize($root);
        $queue = [$root];
        $visited = [];
        $skip = ['.git', 'node_modules', 'storage', 'cache', 'coverage', 'dist', 'build', 'logs', 'tmp'];

        while ($queue !== []) {
            $directory = array_shift($queue);
            $resolvedDirectory = realpath($directory) ?: $directory;
            $normalizedDirectory = $this->pathValidator->normalize($resolvedDirectory);

            if (
                isset($visited[$normalizedDirectory])
                || (
                    $normalizedDirectory !== $normalizedRoot
                    && ! str_starts_with($normalizedDirectory, $normalizedRoot.'/')
                )
            ) {
                continue;
            }

            $visited[$normalizedDirectory] = true;

            foreach (scandir($resolvedDirectory) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || in_array(strtolower($entry), $skip, true)) {
                    continue;
                }

                $candidate = $resolvedDirectory.DIRECTORY_SEPARATOR.$entry;

                if (! is_dir($candidate)) {
                    continue;
                }

                if (strtolower($entry) === 'vendor') {
                    $found[] = realpath($candidate) ?: $candidate;
                    continue;
                }

                $queue[] = $candidate;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @return array<int, string>
     */
    public function dependencyAutoloaders(ProjectContext $project): array
    {
        $autoloaders = [];

        foreach ($this->dependencyDirectories($project->path) as $vendor) {
            $autoload = $vendor.DIRECTORY_SEPARATOR.'autoload.php';

            if (is_file($autoload)) {
                $autoloaders[] = $autoload;
            }
        }

        return $autoloaders;
    }

    /**
     * Generate the constants CodeIgniter defines before application code runs.
     *
     * Loading these as a PHPStan bootstrap preserves errors for genuinely
     * misspelled constants while recognizing CI's runtime globals and every
     * project-specific constant declared in app/Config/Constants.php.
     */
    public function codeIgniterConstantsBootstrap(ProjectContext $project): ?string
    {
        if (! $project->isCodeIgniter4()) {
            return null;
        }

        $directory = storage_path('app/phpstan');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $root = rtrim(realpath($project->path) ?: $project->path, '/\\').DIRECTORY_SEPARATOR;
        $app = $root.'app'.DIRECTORY_SEPARATOR;
        $public = $root.'public'.DIRECTORY_SEPARATOR;
        $writable = $root.'writable'.DIRECTORY_SEPARATOR;
        $tests = $root.'tests'.DIRECTORY_SEPARATOR;
        $vendor = $root.'vendor'.DIRECTORY_SEPARATOR;
        $system = $vendor.'codeigniter4'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'system'.DIRECTORY_SEPARATOR;
        $constantsFile = $app.'Config'.DIRECTORY_SEPARATOR.'Constants.php';

        $constants = [
            'APPPATH' => $app,
            'ROOTPATH' => $root,
            'SYSTEMPATH' => $system,
            'WRITEPATH' => $writable,
            'TESTPATH' => $tests,
            'FCPATH' => $public,
            'HOMEPATH' => $root,
            'CONFIGPATH' => $app.'Config'.DIRECTORY_SEPARATOR,
            'PUBLICPATH' => $public,
            'CIPATH' => dirname(rtrim($system, '/\\')).DIRECTORY_SEPARATOR,
            'SUPPORTPATH' => $tests.'_support'.DIRECTORY_SEPARATOR,
            'VENDORPATH' => $vendor,
            'COMPOSER_PATH' => $vendor.'autoload.php',
            'ENVIRONMENT' => 'development',
            'CI_DEBUG' => true,
        ];

        $lines = ['<?php', ''];

        foreach ($constants as $name => $value) {
            $lines[] = sprintf(
                "defined('%s') || define('%s', %s);",
                $name,
                $name,
                var_export($value, true)
            );
        }

        if (is_file($constantsFile)) {
            $lines[] = '';
            $lines[] = 'require_once '.var_export($constantsFile, true).';';
        } else {
            // APP_NAMESPACE is normally declared by Config/Constants.php.
            $lines[] = "defined('APP_NAMESPACE') || define('APP_NAMESPACE', 'App');";
        }

        $path = $directory.DIRECTORY_SEPARATOR
            .'codeigniter-constants-'.hash('sha256', $project->path).'.php';
        file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);

        return $path;
    }

    /**
     * Build a safe bootstrap containing only class aliases declared by project
     * code. This supports libraries that retain legacy, non-namespaced names
     * without executing the surrounding application bootstrap.
     */
    public function dependencyAliasBootstrap(ProjectContext $project): ?string
    {
        $aliases = [];

        foreach ($this->projectPhpFiles($project->path) as $file) {
            $contents = file_get_contents($file);

            if (! is_string($contents) || ! str_contains($contents, 'class_alias')) {
                continue;
            }

            preg_match_all(
                '/class_alias\s*\(\s*([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)::class\s*,\s*[\'"]([^\'"]+)[\'"]/i',
                $contents,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $target = ltrim($match[1], '\\');
                $alias = $match[2];

                if (
                    preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $target) === 1
                    && preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $alias) === 1
                ) {
                    $aliases[$alias] = $target;
                }
            }
        }

        if ($aliases === []) {
            return null;
        }

        $directory = storage_path('app/phpstan');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $directory.DIRECTORY_SEPARATOR
            .'aliases-'.hash('sha256', $project->path).'.php';
        $lines = ['<?php', ''];

        foreach ($aliases as $alias => $target) {
            $escapedAlias = addslashes($alias);
            $targetClass = '\\'.$target;
            $lines[] = "if (! class_exists('{$escapedAlias}', false) && class_exists({$targetClass}::class)) {";
            $lines[] = "    class_alias({$targetClass}::class, '{$escapedAlias}');";
            $lines[] = '}';
        }

        file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);

        return $path;
    }

    /**
     * @return array<int, string>
     */
    private function projectPhpFiles(string $projectPath): array
    {
        $files = [];
        $queue = [realpath($projectPath) ?: $projectPath];
        $visited = [];
        $skip = ['.git', 'vendor', 'node_modules', 'storage', 'cache', 'coverage', 'dist', 'build', 'logs', 'tmp'];

        while ($queue !== []) {
            $directory = array_shift($queue);
            $resolvedDirectory = realpath($directory) ?: $directory;

            if (isset($visited[$resolvedDirectory])) {
                continue;
            }

            $visited[$resolvedDirectory] = true;

            foreach (scandir($resolvedDirectory) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || in_array(strtolower($entry), $skip, true)) {
                    continue;
                }

                $candidate = $resolvedDirectory.DIRECTORY_SEPARATOR.$entry;

                if (is_dir($candidate)) {
                    $queue[] = $candidate;
                } elseif (is_file($candidate) && strtolower(pathinfo($candidate, PATHINFO_EXTENSION)) === 'php') {
                    $files[] = $candidate;
                }
            }
        }

        return $files;
    }

    public function parentThemePath(ProjectContext $project): ?string
    {
        if (! config('codechecker.scan_parent_theme', true)) {
            return null;
        }

        $candidates = [
            $project->parentThemePath,
            (string) config('codechecker.parent_theme_path', ''),
            $this->discoverParentThemePath($project),
        ];

        foreach ($candidates as $candidate) {
            $resolved = $this->resolveParentDirectory($candidate, $project->path);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    public function discoverParentThemePath(ProjectContext $project): ?string
    {
        $style = $project->path.DIRECTORY_SEPARATOR.'style.css';

        if (! is_file($style)) {
            return null;
        }

        $contents = (string) file_get_contents($style, false, null, 0, 8192);
        $slug = $this->headerValue($contents, 'Template');

        if ($slug === null) {
            return null;
        }

        // 1) Sibling folder named exactly like Template.
        $sibling = dirname($project->path).DIRECTORY_SEPARATOR.$slug;
        $resolvedSibling = $this->resolveParentDirectory($sibling, $project->path);

        if ($resolvedSibling !== null) {
            return $resolvedSibling;
        }

        // 2) Walk up until wp-content/themes/{Template} is found.
        $themesRoots = $this->candidateThemesDirectories($project->path);

        foreach ($themesRoots as $themesRoot) {
            $candidate = $themesRoot.DIRECTORY_SEPARATOR.$slug;
            $resolved = $this->resolveParentDirectory($candidate, $project->path);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        // 3) Match Theme Name when the folder name differs from Template.
        foreach ($themesRoots as $themesRoot) {
            $matched = $this->findThemeDirectoryByName($themesRoot, $slug, $project->path);

            if ($matched !== null) {
                return $matched;
            }
        }

        return null;
    }

    private function resolveParentDirectory(?string $path, string $childPath): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        try {
            $resolved = $this->pathValidator->validate($path);
        } catch (\Throwable) {
            return null;
        }

        $resolvedChild = realpath($childPath) ?: $childPath;

        if ($resolved === $resolvedChild || ! is_dir($resolved)) {
            return null;
        }

        return $resolved;
    }

    /**
     * @return array<int, string>
     */
    private function candidateThemesDirectories(string $childPath): array
    {
        $directories = [];
        $current = realpath($childPath) ?: $childPath;

        for ($i = 0; $i < 10; $i++) {
            $parent = dirname($current);

            if ($parent === $current) {
                break;
            }

            $basename = strtolower(basename($current));
            $parentBasename = strtolower(basename($parent));

            if ($basename === 'themes' || ($parentBasename === 'wp-content' && $basename === 'themes')) {
                $directories[] = $current;
            }

            $themes = $parent.DIRECTORY_SEPARATOR.'wp-content'.DIRECTORY_SEPARATOR.'themes';

            if (is_dir($themes)) {
                $directories[] = realpath($themes) ?: $themes;
            }

            $current = $parent;
        }

        $siblingThemes = dirname($childPath);

        if (is_dir($siblingThemes)) {
            $directories[] = realpath($siblingThemes) ?: $siblingThemes;
        }

        return array_values(array_unique($directories));
    }

    private function findThemeDirectoryByName(string $themesRoot, string $needle, string $childPath): ?string
    {
        $needle = strtolower(trim($needle));

        foreach (scandir($themesRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $candidate = $themesRoot.DIRECTORY_SEPARATOR.$entry;

            if (! is_dir($candidate)) {
                continue;
            }

            $style = $candidate.DIRECTORY_SEPARATOR.'style.css';

            if (! is_file($style)) {
                continue;
            }

            $contents = (string) file_get_contents($style, false, null, 0, 8192);
            $themeName = $this->headerValue($contents, 'Theme Name');
            $folder = strtolower($entry);

            if (
                $folder === $needle
                || ($themeName !== null && strtolower($themeName) === $needle)
            ) {
                return $this->resolveParentDirectory($candidate, $childPath);
            }
        }

        return null;
    }

    private function headerValue(string $contents, string $header): ?string
    {
        if (preg_match('/^\s*'.preg_quote($header, '/').':\s*(.+)$/mi', $contents, $matches) !== 1) {
            return null;
        }

        $value = trim($matches[1]);

        if ($value === '') {
            return null;
        }

        // Template must be a single folder name when auto-discovered.
        if ($header === 'Template' && ($value !== basename($value) || preg_match('/^[A-Za-z0-9._-]+$/', $value) !== 1)) {
            return null;
        }

        return $value;
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
     * @param  array<int, string>  $symbolSources
     * @return array<int, string>
     */
    private function excludePaths(ProjectContext $project, array $symbolSources = []): array
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

        if ($project->isCodeIgniter4()) {
            $core[] = $project->path.DIRECTORY_SEPARATOR.'writable';
        }

        foreach ($core as $candidate) {
            if (file_exists($candidate)) {
                $paths[] = $candidate;
            }
        }

        return array_values(array_filter(
            array_unique($paths),
            fn (string $path): bool => ! $this->isSymbolSource($path, $symbolSources)
        ));
    }

    /**
     * PHPStan's excludePaths hides a path from analysis and from symbol
     * discovery alike, so anything listed as a symbol source must stay out of
     * it. Framework and vendor code never reaches PHPStan's paths anyway,
     * because only the batch files are analysed.
     *
     * @param  array<int, string>  $symbolSources
     */
    private function isSymbolSource(string $path, array $symbolSources): bool
    {
        $candidate = $this->comparablePath($path);

        foreach ($symbolSources as $source) {
            $source = $this->comparablePath($source);

            if (
                $candidate === $source
                || str_starts_with($candidate, $source.'/')
                || str_starts_with($source, $candidate.'/')
            ) {
                return true;
            }
        }

        return false;
    }

    private function comparablePath(string $path): string
    {
        return rtrim(strtolower(str_replace('\\', '/', $path)), '/');
    }

    private function neonPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        return "'".str_replace("'", "''", $normalized)."'";
    }
}
