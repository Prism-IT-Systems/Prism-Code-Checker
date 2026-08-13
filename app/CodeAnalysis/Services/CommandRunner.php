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
}
