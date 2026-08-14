<?php

namespace App\CodeAnalysis\Services;

use App\CodeAnalysis\DTO\CommandResult;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class CommandRunner
{
    /**
     * @param  array<int, string>  $command
     */
    public function run(array $command, ?string $workingDirectory = null, ?float $timeout = 60): CommandResult
    {
        $process = new Process($command, $workingDirectory);
        $process->setTimeout($timeout);

        $started = microtime(true);

        try {
            $process->run();
            $timedOut = false;
        } catch (ProcessTimedOutException) {
            $timedOut = true;
        }

        $duration = microtime(true) - $started;

        return new CommandResult(
            exitCode: $timedOut ? 124 : ($process->getExitCode() ?? 1),
            stdout: $process->getOutput(),
            stderr: $process->getErrorOutput(),
            duration: round($duration, 3),
            timedOut: $timedOut,
        );
    }

    /**
     * Run a command and capture stdout/stderr via temp files.
     * Avoids agent wrappers that rewrite PHPStan JSON on the process pipe.
     *
     * @param  array<int, string>  $command
     */
    public function runCapturingToFiles(array $command, ?string $workingDirectory = null, ?float $timeout = 60): CommandResult
    {
        $directory = storage_path('app/phpstan');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $stdoutFile = $directory.DIRECTORY_SEPARATOR.'out-'.bin2hex(random_bytes(8)).'.json';
        $stderrFile = $directory.DIRECTORY_SEPARATOR.'err-'.bin2hex(random_bytes(8)).'.txt';

        $parts = [];

        foreach ($command as $part) {
            $parts[] = $this->escapeArgument($part);
        }

        $shell = implode(' ', $parts)
            .' > '.$this->escapeArgument($stdoutFile)
            .' 2> '.$this->escapeArgument($stderrFile);

        if (PHP_OS_FAMILY === 'Windows') {
            $process = Process::fromShellCommandline('cmd /C '.$shell, $workingDirectory);
        } else {
            $process = Process::fromShellCommandline($shell, $workingDirectory);
        }

        $process->setTimeout($timeout);

        $started = microtime(true);

        try {
            $process->run();
            $timedOut = false;
        } catch (ProcessTimedOutException) {
            $timedOut = true;
        }

        $duration = microtime(true) - $started;
        $stdout = is_file($stdoutFile) ? (string) file_get_contents($stdoutFile) : '';
        $stderr = is_file($stderrFile) ? (string) file_get_contents($stderrFile) : '';

        @unlink($stdoutFile);
        @unlink($stderrFile);

        return new CommandResult(
            exitCode: $timedOut ? 124 : ($process->getExitCode() ?? 1),
            stdout: $stdout,
            stderr: $stderr,
            duration: round($duration, 3),
            timedOut: $timedOut,
        );
    }

    private function escapeArgument(string $argument): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            if ($argument === '') {
                return '""';
            }

            if (! preg_match('/[\s"]/', $argument)) {
                return $argument;
            }

            return '"'.str_replace('"', '""', $argument).'"';
        }

        return escapeshellarg($argument);
    }
}
