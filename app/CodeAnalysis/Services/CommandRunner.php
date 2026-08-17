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
     * Runs many commands with a fixed number of processes in flight.
     *
     * Tools that need one process per file are far too slow to run serially on
     * a large project.
     *
     * @param  array<int|string, array<int, string>>  $commands
     * @param  callable(int|string, CommandResult): (bool|void)  $onResult  Return false to stop early.
     */
    public function runPool(
        array $commands,
        ?string $workingDirectory,
        ?float $timeout,
        int $concurrency,
        callable $onResult,
    ): void {
        $concurrency = max(1, $concurrency);
        $keys = array_keys($commands);
        $pending = count($keys);
        $next = 0;
        $running = [];
        $stopped = false;

        while (! $stopped && ($next < $pending || $running !== [])) {
            while (! $stopped && count($running) < $concurrency && $next < $pending) {
                $key = $keys[$next++];
                $process = new Process($commands[$key], $workingDirectory);
                $process->setTimeout($timeout);
                $process->start();
                $running[$key] = [$process, microtime(true)];
            }

            $reaped = false;

            foreach ($running as $key => [$process, $startedAt]) {
                $timedOut = false;

                try {
                    $process->checkTimeout();
                } catch (ProcessTimedOutException) {
                    $timedOut = true;
                }

                if (! $timedOut && $process->isRunning()) {
                    continue;
                }

                unset($running[$key]);
                $reaped = true;

                $result = new CommandResult(
                    exitCode: $timedOut ? 124 : ($process->getExitCode() ?? 1),
                    stdout: $process->getOutput(),
                    stderr: $process->getErrorOutput(),
                    duration: round(microtime(true) - $startedAt, 3),
                    timedOut: $timedOut,
                );

                if ($onResult($key, $result) === false) {
                    $stopped = true;
                    break;
                }
            }

            if (! $reaped && $running !== []) {
                usleep(5000);
            }
        }

        foreach ($running as [$process]) {
            $process->stop(0);
        }
    }

    /**
     * Run a command and capture stdout/stderr via temp files.
     * Avoids agent wrappers that rewrite PHPStan JSON on the process pipe.
     *
     * @param  array<int, string>  $command
     * @param  array<string, string|false>  $env  Added to the child environment; false removes a variable.
     */
    public function runCapturingToFiles(
        array $command,
        ?string $workingDirectory = null,
        ?float $timeout = 60,
        array $env = [],
    ): CommandResult {
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
            $process = Process::fromShellCommandline('cmd /C '.$shell, $workingDirectory, $env);
        } else {
            $process = Process::fromShellCommandline($shell, $workingDirectory, $env);
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
