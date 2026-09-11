<?php

namespace Pterodactyl\Services\Git\Transports;

/**
 * The raw outcome of running git (or a git-adjacent file operation) on a node.
 *
 * Git communicates its non-zero results through the exit code rather than a
 * transport failure, so HTTP errors, exit codes and output are kept separate.
 */
class ExecResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->exitCode === 0;
    }
}