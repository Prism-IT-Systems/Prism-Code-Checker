<?php

namespace App\CodeAnalysis\DTO;

class CommandResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public float $duration,
        public bool $timedOut = false,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut;
    }

    public function output(): string
    {
        return trim($this->stdout."\n".$this->stderr);
    }
}
