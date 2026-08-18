<?php

namespace App\CodeAnalysis\Services;

use App\CodeAnalysis\DTO\ProjectContext;

class PhpCsFixerConfigFactory
{
    /**
     * Build an isolated, read-only CodeIgniter coding-standard configuration
     * whose Finder contains exactly the files selected for this Prism scan.
     *
     * @param  array<int, string>  $files
     */
    public function make(ProjectContext $project, array $files): string
    {
        $directory = storage_path('app/php-cs-fixer');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $cacheDirectory = storage_path('framework/cache/php-cs-fixer');

        if (! is_dir($cacheDirectory)) {
            mkdir($cacheDirectory, 0755, true);
        }

        $config = $this->projectConfig($project);
        $configExpression = $config !== null
            ? 'require '.var_export($config, true)
            : '\Nexus\CsConfig\Factory::create(new \CodeIgniter\CodingStandard\CodeIgniter4())->forProjects()';
        $cache = $cacheDirectory.DIRECTORY_SEPARATOR.hash('sha256', $project->path).'.cache';
        $path = $directory.DIRECTORY_SEPARATOR.'scan-'.bin2hex(random_bytes(8)).'.php';
        $autoload = base_path('vendor/autoload.php');

        $contents = implode(PHP_EOL, [
            '<?php',
            '',
            'require_once '.var_export($autoload, true).';',
            '',
            '$config = '.$configExpression.';',
            '$config->setFinder(\PhpCsFixer\Finder::create()->append('.var_export(array_values($files), true).'));',
            '$config->setCacheFile('.var_export($cache, true).');',
            '',
            'return $config;',
            '',
        ]);

        file_put_contents($path, $contents);

        return $path;
    }

    private function projectConfig(ProjectContext $project): ?string
    {
        foreach (['.php-cs-fixer.dist.php', '.php-cs-fixer.php'] as $file) {
            $candidate = $project->path.DIRECTORY_SEPARATOR.$file;

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
