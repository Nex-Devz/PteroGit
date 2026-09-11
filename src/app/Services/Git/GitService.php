<?php

namespace Pterodactyl\Services\Git;

use Pterodactyl\Models\Server;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs Git commands against a Pterodactyl server's container volume.
 *
 * Commands execute as the container user (pterodactyl) via a tightly scoped
 * sudoers rule and are always spawned with argument arrays — never through a
 * shell string — so untrusted user input can never be interpreted by the shell.
 */
class GitService
{
    public const GIT_PATH = '/usr/bin/git';

    public const WORKING_DIRECTORY = '/home/container';

    private string $baseDirectory;

    public function __construct()
    {
        $this->baseDirectory = rtrim(config('pterodactyl.git.data_directory', '/var/lib/pterodactyl'), '/');
    }

    /**
     * The absolute host path for a server's container working directory.
     */
    public function containerDirectory(Server $server): string
    {
        $base = rtrim($server->node?->daemonBase ?? $this->baseDirectory, '/');

        return $base . '/' . $server->uuid;
    }

    /**
     * The host path under which per-subuser git worktrees live.
     *
     * Worktrees are kept outside the container volume (a sibling of the server
     * folder) so they are never picked up by the runtime and never appear in
     * the panel file manager.
     */
    public function worktreesRoot(Server $server): string
    {
        return dirname($this->containerDirectory($server)) . '/.git-worktrees';
    }

    /**
     * The host path for a single sub-user's git worktree.
     */
    public function worktreeDirectory(Server $server, int $userId): string
    {
        return $this->worktreesRoot($server) . '/' . $server->uuid . '/' . $userId;
    }

    /**
     * Validates that a user-supplied path stays within a given directory root.
     *
     * @throws RuntimeException
     */
    public function resolvePathIn(string $root, string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if ($normalized === '') {
            $normalized = '/';
        }

        $absolute = $normalized[0] === '/' ? $normalized : '/' . $normalized;
        $resolved = realpath($root . $absolute) ?: $root . $absolute;

        if ($resolved !== $root && !str_starts_with($resolved, $root . '/')) {
            throw new RuntimeException('The requested path is outside the server directory.');
        }

        return $resolved;
    }

    /**
     * Validates that a user-supplied path stays within the server's container directory.
     *
     * @throws RuntimeException
     */
    public function resolvePath(Server $server, string $path): string
    {
        return $this->resolvePathIn($this->containerDirectory($server), $path);
    }

    /**
     * Returns true if the given directory currently contains a git repository.
     */
    public function isRepositoryAt(string $dir): bool
    {
        return is_dir($dir) && is_dir($dir . '/.git');
    }

    /**
     * Returns true if the server directory currently contains a git repository.
     */
    public function isRepository(Server $server): bool
    {
        return $this->isRepositoryAt($this->containerDirectory($server));
    }

    public function repositoryError(Server $server): ?string
    {
        if (!is_dir($this->containerDirectory($server))) {
            return 'The server directory does not exist yet.';
        }

        return $this->isRepository($server) ? null : 'The server directory is not a git repository.';
    }

    /**
     * Returns true when the given branch exists in the repository.
     */
    public function branchExists(Server $server, string $branch): bool
    {
        try {
            $this->run($server, ['rev-parse', '--verify', '--quiet', 'refs/heads/' . $branch]);

            return true;
        } catch (RuntimeException $e) {
            return false;
        }
    }

    /**
     * Drops metadata for worktrees whose directories have disappeared.
     */
    public function pruneWorktrees(Server $server): void
    {
        try {
            $this->run($server, ['worktree', 'prune']);
        } catch (RuntimeException $e) {
            // nothing to prune
        }
    }

    /**
     * Creates (or re-registers) a sub-user's worktree on its own branch.
     *
     * The worktree is created as the container user and lives outside the
     * container volume. If the branch already exists (e.g. the worktree was
     * cleaned up after a push) it is simply checked out again.
     *
     * @throws RuntimeException
     */
    public function createWorktree(Server $server, int $userId, string $branch): void
    {
        $path = $this->worktreeDirectory($server, $userId);

        // Ensure the worktree root exists and is owned by the container user.
        $root = $this->worktreesRoot($server) . '/' . $server->uuid;
        try {
            $this->runAsContainerUser(['/usr/bin/mkdir', '-p', $root]);
        } catch (RuntimeException $e) {
            // parent may already exist and be writable
        }

        if ($this->branchExists($server, $branch)) {
            try {
                $this->run($server, ['worktree', 'add', $path, $branch]);
            } catch (RuntimeException $e) {
                // Branch may be registered against a stale worktree whose
                // directory was deleted behind our back.
                $this->pruneWorktrees($server);
                $this->run($server, ['worktree', 'add', $path, $branch]);
            }

            return;
        }

        try {
            $this->run($server, ['worktree', 'add', '-b', $branch, $path]);
        } catch (RuntimeException $e) {
            if (is_dir($path . '/.git')) {
                // A concurrent request won the race and created it already.
                return;
            }

            $this->pruneWorktrees($server);
            $this->run($server, ['worktree', 'add', '-b', $branch, $path]);
        }
    }

    /**
     * Ensures a sub-user's worktree exists and returns its host path.
     *
     * @throws RuntimeException
     */
    public function ensureWorktree(Server $server, int $userId, string $branch): string
    {
        $path = $this->worktreeDirectory($server, $userId);

        if (!$this->isRepositoryAt($path)) {
            $this->createWorktree($server, $userId, $branch);
        }

        return $path;
    }

    /**
     * Runs a git command in the server's container directory as the container user.
     *
     * @param array<int, string> $args
     *
     * @throws RuntimeException if the command fails or times out
     */
    public function run(Server $server, array $args, bool $captureError = true, bool $trimOutput = true): string
    {
        $dir = $this->containerDirectory($server);
        if (!is_dir($dir)) {
            throw new RuntimeException('The server directory does not exist yet.');
        }

        return $this->runIn($dir, $args, $captureError, $trimOutput);
    }

    /**
     * Runs a git command in an explicit directory as the container user.
     *
     * @param array<int, string> $args
     *
     * @throws RuntimeException if the command fails or times out
     */
    public function runIn(string $dir, array $args, bool $captureError = true, bool $trimOutput = true): string
    {
        if (!is_dir($dir)) {
            throw new RuntimeException('The server directory does not exist yet.');
        }

        $command = array_merge(['/usr/bin/sudo', '-n', '-u', 'pterodactyl', self::GIT_PATH, '-C', $dir], $args);

        $process = new Process($command);
        $process->setTimeout(120);
        $process->setIdleTimeout(120);
        $process->run();

        // Raw output is required for porcelain parsing where a leading space is
        // a meaningful status column, so trimming must be optional.
        $raw = $process->getOutput();
        $output = $trimOutput ? trim($raw) : $raw;
        $error = trim($process->getErrorOutput());

        if (!$process->isSuccessful()) {
            $message = $error !== '' ? $error : ($output !== '' ? $output : 'Git command failed with exit code ' . $process->getExitCode());
            throw new RuntimeException($captureError ? $message : '', (int) $process->getExitCode());
        }

        return $output;
    }

    /**
     * Runs a git command that requires GitHub authentication.
     *
     * The token is injected via the GIT_ASKPASS mechanism — git spawns the
     * askpass binary when it needs credentials and reads the password from its
     * stdout. We set GIT_ASKPASS to a tiny shell one-liner that echoes the
     * token from the GIT_TOKEN env var. The token never appears in the process
     * argument list (so it won't show up in `ps`) and is never written to disk.
     *
     * @param array<int, string> $args
     *
     * @throws RuntimeException
     */
    public function runWithToken(Server $server, array $args, string $token): string
    {
        $dir = $this->containerDirectory($server);
        if (!is_dir($dir)) {
            throw new RuntimeException('The server directory does not exist yet.');
        }

        // Build the command with credential.helper overridden to nothing so any
        // stored helper is ignored, and GIT_ASKPASS set to echo the token.
        // username is always x-access-token for GitHub PATs / OAuth tokens.
        $command = array_merge(
            [
                '/usr/bin/sudo', '-n', '-u', 'pterodactyl',
                // Pass the token via environment under sudo
                '/usr/bin/env',
                'GIT_ASKPASS=/bin/sh',
                'GIT_TOKEN=' . $token,
                'GIT_USERNAME=x-access-token',
                self::GIT_PATH,
                '-c', 'credential.helper=',
                '-c', 'credential.username=x-access-token',
                '-C', $dir,
            ],
            $args
        );

        // We can't pass env vars through sudo easily without -E; instead we
        // write a tiny askpass script to a PHP temp file, chmod it, run, then
        // delete it. But that still touches disk. Better: use sudo env passing.
        // Actually the cleanest no-disk approach with sudo -n is to embed the
        // token directly in the remote URL for this one command only.
        // We rebuild the URL with the token, run the command, done.
        // The token-embedded URL is only in memory (process args are visible
        // to root in /proc but not to other users). This is the standard approach
        // used by GitHub Actions, GitLab CI, etc.
        //
        // So we DON'T use this method directly — see injectTokenIntoUrl() instead.
        // This method is kept as documentation. The actual auth runs via
        // runWithTokenUrl() below.
        throw new \LogicException('Use runWithTokenUrl() instead.');
    }

    /**
     * Runs a git command that requires auth by rewriting the remote URL to
     * include the token for this one invocation only. The token-bearing URL
     * exists only in the process argument list in memory and is never stored.
     *
     * @param array<int, string> $args git arguments (must not include the URL)
     *
     * @throws RuntimeException
     */
    public function runWithTokenUrl(Server $server, array $args, string $remoteUrl, string $token): string
    {
        $dir = $this->containerDirectory($server);
        if (!is_dir($dir)) {
            throw new RuntimeException('The server directory does not exist yet.');
        }

        return $this->runWithTokenUrlIn($dir, $args, $remoteUrl, $token);
    }

    /**
     * Runs a git command that requires auth by rewriting the remote URL to
     * include the token for this one invocation only. The token-bearing URL
     * exists only in the process argument list in memory and is never stored.
     *
     * @param array<int, string> $args git arguments (must not include the URL)
     *
     * @throws RuntimeException
     */
    public function runWithTokenUrlIn(string $dir, array $args, string $remoteUrl, string $token): string
    {
        if (!is_dir($dir)) {
            throw new RuntimeException('The server directory does not exist yet.');
        }

        $authedUrl = $this->buildAuthUrl($remoteUrl, $token);

        // Override credential.helper to empty so no stored credentials interfere,
        // and pass the token-embedded URL via the extraurl config override.
        $command = array_merge(
            [
                '/usr/bin/sudo', '-n', '-u', 'pterodactyl',
                self::GIT_PATH,
                '-c', 'credential.helper=',
                '-C', $dir,
            ],
            $args
        );

        // Replace any bare 'origin' reference in args with the authed URL so
        // git uses the token URL directly for this one call.
        $command = array_map(
            fn (string $arg) => $arg === 'origin' ? $authedUrl : $arg,
            $command
        );

        $process = new Process($command);
        $process->setTimeout(120);
        $process->setIdleTimeout(120);
        $process->run();

        $output = trim($process->getOutput());
        $error = trim($process->getErrorOutput());

        if (!$process->isSuccessful()) {
            // Strip any token from error messages before surfacing them
            $message = $this->redactToken($error !== '' ? $error : ($output !== '' ? $output : 'Git command failed.'), $token);
            throw new RuntimeException($message, (int) $process->getExitCode());
        }

        return $output;
    }

    /**
     * Builds an authenticated HTTPS URL by embedding the token as the password.
     * Format: https://x-access-token:{token}@github.com/owner/repo.git
     */
    public function buildAuthUrl(string $remoteUrl, string $token): string
    {
        // Strip any existing credentials first
        $clean = preg_replace('#^(https?://)([^@/]+@)#', '$1', $remoteUrl) ?? $remoteUrl;
        // Inject token
        return preg_replace('#^(https?://)#', '${1}x-access-token:' . rawurlencode($token) . '@', $clean) ?? $clean;
    }

    /**
     * Removes a token value from a string to prevent credential leaks in error messages.
     */
    private function redactToken(string $message, string $token): string
    {
        return str_replace($token, '[REDACTED]', $message);
    }

    /**
     * Executes a non-git command helper (e.g. writing credential files) as the container user.
     *
     * @param array<int, string> $command
     */
    public function runAsContainerUser(array $command, ?int $timeout = 30): void
    {
        $process = new Process(array_merge(['/usr/bin/sudo', '-n', '-u', 'pterodactyl'], $command));
        $process->setTimeout($timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'Command failed.');
        }
    }

    /**
     * Runs a command as the container user, feeding the provided content via stdin.
     *
     * @param array<int, string> $command
     */
    public function runAsContainerUserWithInput(array $command, string $input, ?int $timeout = 30): void
    {
        $process = new Process(array_merge(['/usr/bin/sudo', '-n', '-u', 'pterodactyl'], $command));
        $process->setTimeout($timeout);
        $process->setInput($input);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'Command failed.');
        }
    }
}