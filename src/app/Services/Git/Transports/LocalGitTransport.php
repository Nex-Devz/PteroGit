<?php

namespace Pterodactyl\Services\Git\Transports;

use Pterodactyl\Models\Server;
use Pterodactyl\Services\Git\GitPaths;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs git on the panel host as the container user via sudo.
 *
 * Used for co-located installs (panel and Wings on the same host) and as the
 * automatic fallback when a node does not yet run the PteroGit Wings git
 * channel. Volume root resolution probes known locations before trusting the
 * configured nodes.daemonBase, so a stale daemonBase never points git at an
 * empty directory.
 */
class LocalGitTransport implements GitNodeTransportContract
{
    public const GIT_PATH = '/usr/bin/git';

    public function id(): string
    {
        return 'local';
    }

    /**
     * Resolves the panel host's data root for a server by probing known
     * volume locations; the first root that actually contains the server's
     * directory wins.
     */
    public function dataRoot(Server $server): string
    {
        $candidates = array_values(array_filter([
            config('pterodactyl.git.data_directory'),
            '/var/lib/pterodactyl/volumes',
            '/srv/daemon-data',
            $server->node?->daemonBase,
        ], fn ($value): bool => is_string($value) && $value !== ''));

        if (count($candidates) === 0) {
            $candidates[] = '/var/lib/pterodactyl/volumes';
        }

        $uuid = $server->uuid;
        foreach ($candidates as $candidate) {
            $root = rtrim((string) $candidate, '/');
            if (is_dir($root . '/' . $uuid)) {
                return $root;
            }
        }

        return rtrim((string) $candidates[0], '/');
    }

    private function abs(Server $server, string $cwdRel): string
    {
        $rel = GitPaths::normalize($cwdRel);
        $root = $this->dataRoot($server);

        return $rel === '' ? $root : $root . '/' . $rel;
    }

    /**
     * @param array<int, string> $command
     */
    private function process(array $command, ?string $input, int $timeout): Process
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->setIdleTimeout($timeout);
        $process->setInput($input ?? '');
        $process->run();

        return $process;
    }

    public function git(Server $server, string $cwdRel, array $args, bool $trimOutput = true, ?string $askpassToken = null): ExecResult
    {
        $command = array_merge(
            ['/usr/bin/sudo', '-n', '-u', 'pterodactyl', self::GIT_PATH, '-C', $this->abs($server, $cwdRel)],
            $args
        );

        $process = $this->process($command, null, 120);

        $stdout = (string) $process->getOutput();
        $stderr = (string) $process->getErrorOutput();

        if ($askpassToken !== null && $askpassToken !== '') {
            $stdout = str_replace($askpassToken, '[REDACTED]', $stdout);
            $stderr = str_replace($askpassToken, '[REDACTED]', $stderr);
        }

        return new ExecResult((int) $process->getExitCode(), $trimOutput ? trim($stdout) : $stdout, trim($stderr));
    }

    public function dirExists(Server $server, string $cwdRel): bool
    {
        return is_dir($this->abs($server, $cwdRel));
    }

    public function fileExists(Server $server, string $cwdRel, string $basename): bool
    {
        $path = $this->abs($server, $cwdRel) . '/' . GitPaths::normalize($basename);

        return file_exists($path);
    }

    public function readFile(Server $server, string $cwdRel, string $basename): ?string
    {
        $path = $this->abs($server, $cwdRel) . '/' . GitPaths::normalize($basename);

        $contents = @file_get_contents($path);
        if ($contents !== false) {
            return $contents;
        }

        $process = $this->process(['/usr/bin/sudo', '-n', '-u', 'pterodactyl', '/usr/bin/cat', $path], null, 30);

        return $process->isSuccessful() ? (string) $process->getOutput() : null;
    }

    public function writeFile(Server $server, string $cwdRel, string $basename, string $content): void
    {
        $path = $this->abs($server, $cwdRel) . '/' . GitPaths::normalize($basename);

        $process = $this->process(['/usr/bin/sudo', '-n', '-u', 'pterodactyl', '/usr/bin/tee', $path], $content, 30);
        if (!$process->isSuccessful()) {
            throw new RuntimeException(trim((string) $process->getErrorOutput()) ?: 'Failed to write file.');
        }
    }

    public function mkdir(Server $server, string $cwdRel): void
    {
        $path = $this->abs($server, $cwdRel);

        $mkdir = $this->process(['/usr/bin/sudo', '-n', '-u', 'pterodactyl', '/usr/bin/mkdir', '-p', $path], null, 30);
        if (!$mkdir->isSuccessful()) {
            throw new RuntimeException(trim((string) $mkdir->getErrorOutput()) ?: 'Failed to create directory.');
        }
    }

    public function chownFile(Server $server, string $cwdRel, string $basename): void
    {
        $path = $this->abs($server, $cwdRel) . '/' . GitPaths::normalize($basename);

        $chown = $this->process(['/usr/bin/sudo', '-n', '-u', 'pterodactyl', '/usr/bin/chown', 'pterodactyl:pterodactyl', $path], null, 30);
        if (!$chown->isSuccessful()) {
            // Non-fatal if already owned or running under pterodactyl user
            $error = trim((string) $chown->getErrorOutput());
            if ($error !== '' && !str_contains($error, 'Operation not permitted')) {
                throw new RuntimeException($error ?: 'Failed to set file ownership.');
            }
        }
    }
}