<?php

namespace Pterodactyl\Services\Git;

use Pterodactyl\Models\Server;
use Pterodactyl\Services\Git\Transports\GitTransportManager;
use RuntimeException;

/**
 * Runs Git commands against a Pterodactyl server's volume.
 *
 * Execution is transport-agnostic: a git operation is addressed by a cwd
 * RELATIVE to the node's volume root + the git argument array, and the active
 * transport (local sudo execution, or the Wings git channel on the server's
 * node) resolves it against the filesystem that actually owns the data. The
 * panel never needs the node's physical volume path, so a stale daemonBase
 * can never cause "server directory does not exist" again.
 *
 * Commands are always spawned with argument arrays — never through a shell
 * string — so untrusted user input can never be interpreted by the shell.
 */
class GitService
{
    public const GIT_PATH = '/usr/bin/git';

    public const WORKING_DIRECTORY = '/home/container';

    public function __construct(private readonly GitTransportManager $transports)
    {
    }

    /**
     * The volume-root-relative directory of a server's container volume.
     */
    public function repositoryCwd(Server $server): string
    {
        return GitPaths::normalize($server->uuid);
    }

    /**
     * The volume-root-relative directory under which per-subuser worktrees live.
     */
    public function worktreesRootRel(Server $server): string
    {
        return '.git-worktrees';
    }

    /**
     * The volume-root-relative directory of a single sub-user's git worktree.
     */
    public function worktreeCwd(Server $server, int $userId): string
    {
        return '.git-worktrees/' . $this->repositoryCwd($server) . '/' . $userId;
    }

    /**
     * The path argument to hand `git worktree add` for a sub-user's worktree,
     * relative to the MAIN repository cwd (= ../ from the server volume dir).
     */
    public function worktreeArg(Server $server, int $userId): string
    {
        return '../.git-worktrees/' . $this->repositoryCwd($server) . '/' . $userId;
    }

    /**
     * Tracks which transport a server currently routes through.
     */
    public function transportFor(Server $server): string
    {
        return $this->transports->for($server)->id();
    }

    /**
     * Validates that a user-supplied path is a safe RELATIVE path (no absolute
     * segments, no traversal). Git then resolves it against its working dir.
     *
     * @throws RuntimeException
     */
    public function sanitizeRelPath(string $path): string
    {
        return GitPaths::normalize($path);
    }

    /**
     * Returns true when the directory (volume-root relative) contains a git repository.
     */
    public function isRepositoryAt(Server $server, string $cwdRel): bool
    {
        $rel = GitPaths::normalize($cwdRel);

        return $rel === ''
            ? false
            : $this->transports->for($server)->fileExists($server, $rel, '.git');
    }

    /**
     * Returns true when the directory (volume-root relative) exists on the node.
     */
    public function dirExists(Server $server, string $cwdRel): bool
    {
        return $this->transports->for($server)->dirExists($server, GitPaths::normalize($cwdRel));
    }

    /**
     * Reads a single file inside a cwd (volume-root relative); null when absent.
     */
    public function readFile(Server $server, string $cwdRel, string $basename): ?string
    {
        return $this->transports->for($server)->readFile($server, GitPaths::normalize($cwdRel), GitPaths::normalize($basename));
    }

    /**
     * Writes a single file inside a cwd (volume-root relative) as the container user.
     */
    public function writeFile(Server $server, string $cwdRel, string $basename, string $content): void
    {
        $this->transports->for($server)->writeFile($server, GitPaths::normalize($cwdRel), GitPaths::normalize($basename), $content);
    }

    /**
     * Recursively creates a directory (volume-root relative) owned by the container user.
     */
    public function mkdir(Server $server, string $cwdRel): void
    {
        $this->transports->for($server)->mkdir($server, GitPaths::normalize($cwdRel));
    }

    /**
     * Re-owns a single file inside a cwd (volume-root relative) to the container user.
     */
    public function chownFile(Server $server, string $cwdRel, string $basename): void
    {
        $this->transports->for($server)->chownFile($server, GitPaths::normalize($cwdRel), GitPaths::normalize($basename));
    }

    public function repositoryError(Server $server): ?string
    {
        if (!$this->dirExists($server, $this->repositoryCwd($server))) {
            return 'The server directory does not exist yet.';
        }

        return $this->isRepositoryAt($server, $this->repositoryCwd($server))
            ? null
            : 'The server directory is not a git repository.';
    }

    /**
     * Returns true when the given branch exists in the main repository.
     */
    public function branchExists(Server $server, string $branch): bool
    {
        try {
            $this->runAt($server, $this->repositoryCwd($server), ['rev-parse', '--verify', '--quiet', 'refs/heads/' . $branch]);

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
            $this->runAt($server, $this->repositoryCwd($server), ['worktree', 'prune']);
        } catch (RuntimeException $e) {
            // nothing to prune
        }
    }

    /**
     * Creates (or re-registers) a sub-user's worktree on its own branch.
     *
     * The worktree lives at volume-root/.git-worktrees/<uuid>/<userId> and is
     * created as the container user. If the branch already exists (e.g. the
     * worktree was cleaned up after a push) it is simply checked out again.
     *
     * @throws RuntimeException
     */
    public function createWorktree(Server $server, int $userId, string $branch): void
    {
        $cwd = $this->worktreeCwd($server, $userId);
        $path = $this->worktreeArg($server, $userId);

        // Ensure the worktree root parent exists and is owned by the container user.
        $root = $this->worktreesRootRel($server) . '/' . $this->repositoryCwd($server);
        try {
            $this->mkdir($server, $root);
        } catch (RuntimeException $e) {
            // parent may already exist and be writable
        }

        if ($this->branchExists($server, $branch)) {
            try {
                $this->runAt($server, $this->repositoryCwd($server), ['worktree', 'add', $path, $branch]);
            } catch (RuntimeException $e) {
                // Branch may be registered against a stale worktree whose
                // directory was deleted behind our back.
                $this->pruneWorktrees($server);
                $this->runAt($server, $this->repositoryCwd($server), ['worktree', 'add', $path, $branch]);
            }

            return;
        }

        try {
            $this->runAt($server, $this->repositoryCwd($server), ['worktree', 'add', '-b', $branch, $path]);
        } catch (RuntimeException $e) {
            if ($this->isRepositoryAt($server, $cwd)) {
                // A concurrent request won the race and created it already.
                return;
            }

            $this->pruneWorktrees($server);
            $this->runAt($server, $this->repositoryCwd($server), ['worktree', 'add', '-b', $branch, $path]);
        }
    }

    /**
     * Ensures a sub-user's worktree exists and returns its volume-root-relative cwd.
     *
     * @throws RuntimeException
     */
    public function ensureWorktree(Server $server, int $userId, string $branch): string
    {
        $cwd = $this->worktreeCwd($server, $userId);

        if (!$this->isRepositoryAt($server, $cwd)) {
            $this->createWorktree($server, $userId, $branch);
        }

        return $cwd;
    }

    /**
     * Runs a git command at a volume-root-relative cwd.
     *
     * @param array<int, string> $args
     *
     * @throws RuntimeException if the command fails or times out
     */
    public function runAt(Server $server, string $cwdRel, array $args, bool $captureError = true, bool $trimOutput = true): string
    {
        $result = $this->transports->for($server)->git($server, GitPaths::normalize($cwdRel), $args, $trimOutput);

        if (!$result->isSuccess()) {
            $error = trim($result->stderr);
            $output = trim($result->stdout);
            $message = $error !== '' ? $error : ($output !== '' ? $output : 'Git command failed with exit code ' . $result->exitCode);

            throw new RuntimeException($captureError ? $message : '', $result->exitCode);
        }

        return $result->stdout;
    }

    /**
     * Runs an authenticated git command (fetch/push against GitHub).
     *
     * The token never lands in shell arguments on the target host: on the
     * Wings transport it travels in the request body and is injected through
     * GIT_ASKPASS; on the local transport the (existing) technique of a
     * token-embedded URL for the single invocation is used.
     *
     * @param array<int, string> $args git arguments (must not include the URL)
     *
     * @throws RuntimeException
     */
    public function runWithTokenUrlAt(Server $server, string $cwdRel, array $args, string $remoteUrl, string $token): string
    {
        $transport = $this->transports->for($server);

        // Wings executes on the node: the token goes through GIT_ASKPASS.
        if ($transport->id() === 'wings') {
            $result = $transport->git($server, GitPaths::normalize($cwdRel), $args, true, $token);
            if (!$result->isSuccess()) {
                $error = trim($result->stderr);
                $output = trim($result->stdout);
                $message = $error !== '' ? $error : ($output !== '' ? $output : 'Git command failed.');

                throw new RuntimeException(str_replace($token, '[REDACTED]', $message), $result->exitCode);
            }

            return $result->stdout;
        }

        // Local execution: rewrite the remote URL, embedding the token for the
        // one invocation, and neutralise any stored credential helper.
        $authedUrl = $this->buildAuthUrl($remoteUrl, $token);
        $finalArgs = array_merge(
            ['-c', 'credential.helper=', '-c', 'credential.username=x-access-token'],
            array_map(fn (string $arg): string => $arg === 'origin' ? $authedUrl : $arg, $args)
        );

        $result = $transport->git($server, GitPaths::normalize($cwdRel), $finalArgs, true);
        if (!$result->isSuccess()) {
            $error = trim($result->stderr);
            $output = trim($result->stdout);
            $message = $error !== '' ? $error : ($output !== '' ? $output : 'Git command failed.');

            throw new RuntimeException(str_replace($token, '[REDACTED]', $message), $result->exitCode);
        }

        return $result->stdout;
    }

    /**
     * Builds an authenticated HTTPS URL by embedding the token as the password.
     * Format: https://x-access-token:{token}@github.com/owner/repo.git
     */
    public function buildAuthUrl(string $remoteUrl, string $token): string
    {
        $clean = preg_replace('#^(https?://)([^@/]+@)#', '$1', $remoteUrl) ?? $remoteUrl;

        return preg_replace('#^(https?://)#', '${1}x-access-token:' . rawurlencode($token) . '@', $clean) ?? $clean;
    }
}