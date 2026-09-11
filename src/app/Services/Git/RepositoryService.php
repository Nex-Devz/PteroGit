<?php

namespace Pterodactyl\Services\Git;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Models\GitOperation;
use Pterodactyl\Models\GitWorktree;
use Pterodactyl\Models\GithubAccount;
use Pterodactyl\Models\ServerGitRepository;
use RuntimeException;

/**
 * Orchestrates Git operations against a server's container volume and keeps
 * the panel's git_operations audit trail up to date.
 */
class RepositoryService
{
    private ?int $actingUserId = null;

    public function __construct(
        private readonly GitService $git,
        private readonly GitHubService $github,
    ) {
    }

    /**
     * Returns a per-user context for this service.
     *
     * Sub-users of a server operate on their own git worktree (isolated branch
     * and working directory) instead of the server owner's shared checkout.
     * Passing null (the default) always targets the server's container volume.
     */
    public function forUser(?int $userId): self
    {
        $clone = clone $this;
        $clone->actingUserId = $userId;

        return $clone;
    }

    /**
     * Resolves the git directory that the current request should operate on.
     *
     * The actor is the server/account owner (or there is no linked repository),
     * the shared container checkout is used. Any other user gets their own
     * personal worktree, created on first use.
     */
    private function effectiveCwd(Server $server): string
    {
        $user = $this->actingUserId;
        if ($user === null) {
            return $this->git->repositoryCwd($server);
        }

        $repository = $this->linked($server);
        if (!$repository) {
            return $this->git->repositoryCwd($server);
        }

        if ($server->owner_id === $user || $repository->githubAccount?->user_id === $user) {
            return $this->git->repositoryCwd($server);
        }

        return $this->ensureWorktree($server, $user);
    }

    /**
     * Returns true when the current actor is running on their own worktree.
     */
    private function usingWorktree(Server $server): bool
    {
        return $this->actingUserId !== null
            && !in_array($this->actingUserId, [$server->owner_id, $this->linked($server)?->githubAccount?->user_id], true);
    }

    /**
     * Creates (once) the sub-user's personal worktree and returns its host path.
     *
     * The per-user cache lock prevents two concurrent requests for the same
     * user from racing during the initial `git worktree add`.
     */
    private function ensureWorktree(Server $server, int $userId): string
    {
        $cwd = $this->git->worktreeCwd($server, $userId);

        if ($this->git->isRepositoryAt($server, $cwd)) {
            return $cwd;
        }

        $lock = Cache::lock('git:worktree:' . $server->id . ':' . $userId, 120);
        try {
            if (!$lock->acquire()) {
                throw new RuntimeException('Another request is already creating your workspace. Please retry.');
            }

            if ($this->git->isRepositoryAt($server, $cwd)) {
                return $cwd;
            }

            $row = GitWorktree::firstOrNew(['server_id' => $server->id, 'user_id' => $userId]);
            if ($row->branch === null || $row->branch === '') {
                $row->branch = $this->worktreeBranchName($server, $userId);
                $row->save();
            }

            $this->git->ensureWorktree($server, $userId, $row->branch);

            return $cwd;
        } finally {
            $lock->release();
        }
    }

    /**
     * Builds a deterministic, unique branch name for a sub-user's worktree.
     */
    private function worktreeBranchName(Server $server, int $userId): string
    {
        $username = (string) (User::query()->find($userId)?->username ?? 'user');
        $safeUser = strtolower(preg_replace('/[^a-zA-Z0-9._\/-]+/', '-', $username) ?? 'user');
        $safeUser = trim($safeUser, '.-');
        if ($safeUser === '') {
            $safeUser = 'user' . $userId;
        }

        $default = (string) preg_replace('/[^a-zA-Z0-9._\/-]+/', '-', $this->linked($server)?->default_branch ?? 'main');
        $default = trim($default, '-') ?: 'main';

        return "{$safeUser}-{$userId}/{$default}";
    }

    /**
     * Runs git on the current actor's effective directory.
     */
    private function run(Server $server, array $args, bool $captureError = true, bool $trimOutput = true): string
    {
        return $this->git->runAt($server, $this->effectiveCwd($server), $args, $captureError, $trimOutput);
    }

    /**
     * Runs an authenticated git command on the current actor's effective directory.
     */
    private function runWithTokenUrl(Server $server, array $args, string $remoteUrl, string $token): string
    {
        return $this->git->runWithTokenUrlAt($server, $this->effectiveCwd($server), $args, $remoteUrl, $token);
    }

    /**
     * Returns true when the actor's effective directory is a git repository.
     */
    private function isRepo(Server $server): bool
    {
        return $this->git->isRepositoryAt($server, $this->effectiveCwd($server));
    }

    /**
     * Sanitizes a user-supplied path into a safe relative pathspec.
     */
    private function resolvePath(Server $server, string $path): string
    {
        return $this->git->sanitizeRelPath($path);
    }

    /**
     * Returns the repository linked to a server, or null when none exists.
     */
    public function linked(Server $server): ?ServerGitRepository
    {
        return ServerGitRepository::query()
            ->where('server_id', $server->id)
            ->with('githubAccount')
            ->first();
    }

    public function requireLinked(Server $server): ServerGitRepository
    {
        $repository = $this->linked($server);
        if (!$repository) {
            throw new RuntimeException('No repository is connected to this server.');
        }

        return $repository;
    }

    /**
     * Initializes an empty repository inside the server directory.
     *
     * @throws RuntimeException
     */
    public function initialize(Server $server, string $name, string $email, ?string $defaultBranch = null): array
    {
        $this->begin($server, 'initialize', null, $defaultBranch);

        try {
            $this->run($server, ['init', '-b', $defaultBranch ?: 'main']);
            $this->run($server, ['config', 'user.name', $name]);
            $this->run($server, ['config', 'user.email', $email]);
        } catch (RuntimeException $exception) {
            $this->fail($exception->getMessage());

            throw $exception;
        }

        $this->succeed();

        return $this->status($server);
    }

    /**
     * Connects a GitHub repository to the server by initializing git, wiring
     * up the remote and pulling down the requested branch.
     *
     * The token is embedded in the remote URL only for the network operations
     * (fetch) and never written to disk or stored in git config.
     *
     * @param string $mode one of "clone" (replace contents), "pull" (keep existing files), "init" (empty repo)
     *
     * @throws RuntimeException
     */
    public function connect(Server $server, GithubAccount $account, array $data, string $mode = 'clone'): array
    {
        $repoName = (string) ($data['repository_full_name'] ?? '');
        $remoteUrl = (string) ($data['remote_url'] ?? '');
        $branch = (string) ($data['branch'] ?? $data['default_branch'] ?? 'main');

        if ($repoName === '' || $remoteUrl === '') {
            throw new RuntimeException('Missing repository information.');
        }

        $this->begin($server, 'connect', $repoName, $branch);

        try {
            $token = $account->accessToken();

            if ($mode === 'init') {
                $this->run($server, ['init', '-b', $branch]);
                $this->run($server, ['remote', 'add', 'origin', $remoteUrl]);
                $this->run($server, ['config', 'user.name', $account->username]);
                $this->run($server, ['config', 'user.email', $account->username . '@users.noreply.github.com']);
            } else {
                $this->run($server, ['init', '-b', $branch]);
                $this->ensureRemote($server, $remoteUrl);
                // Fetch with an explicit refspec so the remote-tracking ref
                // refs/remotes/origin/<branch> is written even though we pass
                // the token-embedded URL directly instead of the remote name.
                $refspec = "refs/heads/{$branch}:refs/remotes/origin/{$branch}";
                $this->runWithTokenUrl($server, ['fetch', 'origin', $refspec], $remoteUrl, $token);
                $this->run($server, ['checkout', '-B', $branch, 'origin/' . $branch]);
                if ($mode === 'clone') {
                    $this->run($server, ['reset', '--hard', 'origin/' . $branch]);
                }
            }
        } catch (RuntimeException $exception) {
            $this->fail($exception->getMessage());

            throw $exception;
        }

        $repository = ServerGitRepository::updateOrCreate(
            ['server_id' => $server->id],
            [
                'github_account_id' => $account->id,
                'repository_id' => (string) ($data['repository_id'] ?? $repoName),
                'repository_full_name' => $repoName,
                'remote_url' => $remoteUrl,
                'default_branch' => $branch,
                'current_branch' => $branch,
                'working_directory' => $data['working_directory'] ?? GitService::WORKING_DIRECTORY,
            ]
        );

        $this->succeed();

        return $this->status($server, $repository);
    }

    private function ensureRemote(Server $server, string $remoteUrl): void
    {
        try {
            $this->run($server, ['remote', 'get-url', 'origin']);
        } catch (RuntimeException $e) {
            $this->run($server, ['remote', 'add', 'origin', $remoteUrl]);

            return;
        }

        $this->run($server, ['remote', 'set-url', 'origin', $remoteUrl]);
    }

    /**
     * Removes the repository association from the server.
     * No credential files to clean up — we no longer write any.
     */
    public function disconnect(Server $server): void
    {
        GitWorktree::query()->where('server_id', $server->id)->delete();
        ServerGitRepository::query()->where('server_id', $server->id)->delete();
    }

    /**
     * Returns the current status of the repository.
     */
    public function status(Server $server, ?ServerGitRepository $repository = null): array
    {
        $repository = $repository ?? $this->linked($server);

        $data = [
            'is_repository' => $this->isRepo($server),
            'connected' => (bool) $repository,
            'worktree' => $this->usingWorktree($server),
            'current_branch' => null,
            'ahead' => 0,
            'behind' => 0,
            'is_dirty' => false,
            'changes' => [],
            'last_commit' => null,
            'last_commit_message' => null,
            'repository' => $repository ? array_merge($repository->only([
                'repository_full_name',
                'default_branch',
                'current_branch',
                'working_directory',
            ]), [
                'account_username' => $repository->githubAccount?->username,
                'avatar_url' => $repository->githubAccount?->avatar_url,
            ]) : null,
        ];

        if (!$data['is_repository']) {
            return $data;
        }

        try {
            $data['current_branch'] = $this->run($server, ['rev-parse', '--abbrev-ref', 'HEAD']);
        } catch (RuntimeException $e) {
            $data['current_branch'] = null;
        }

        try {
            $this->run($server, ['rev-parse', '--verify', 'HEAD']);
            $data['last_commit'] = substr($this->run($server, ['rev-parse', 'HEAD']), 0, 7);
            $data['last_commit_message'] = $this->run($server, ['log', '-1', '--pretty=%s']);
        } catch (RuntimeException $e) {
            // no commits yet
        }

        $data['changes'] = $this->changes($server);
        $data['is_dirty'] = count($data['changes']) > 0;

        try {
            $statusInfo = $this->run($server, ['status', '-sb']);
            $branchLine = explode("\n", trim($statusInfo))[0] ?? '';
            if (preg_match('/\[ahead ([0-9]+)(, behind ([0-9]+))?\]/', $branchLine, $m)) {
                $data['ahead'] = (int) $m[1];
                $data['behind'] = isset($m[3]) ? (int) $m[3] : 0;
            } elseif (preg_match('/\[behind ([0-9]+)\]/', $branchLine, $m)) {
                $data['behind'] = (int) $m[1];
            }
        } catch (RuntimeException $e) {
            // untracked upstream
        }

        return $data;
    }

    /**
     * Returns the parsed list of working-directory changes.
     */
    public function changes(Server $server, ?string $path = null): array
    {
        $this->assertRepository($server);

        $args = ['status', '--porcelain'];
        if ($path) {
            $args[] = '--';
            $args[] = $this->resolvePath($server, $path);
        }

        // Raw (untrimmed) output is required: in porcelain format column 0 is
        // the index status and may be a meaningful space for unstaged changes,
        // so the output must never be left-trimmed before parsing.
        $output = $this->run($server, $args, true, false);

        $changes = [];
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $changes[] = $this->parseStatusLine($line);
        }

        return $changes;
    }

    private function parseStatusLine(string $line): array
    {
        // Porcelain v1: column 0 = index status (X), column 1 = working tree
        // status (Y), column 2 is a separator space, the path starts at column
        // 3. The line is NOT left-trimmed because a leading space is meaningful
        // (X = " " means the entry has unstaged changes only).
        $indexStatus = $line[0] ?? ' ';
        $workStatus = $line[1] ?? ' ';
        $file = strlen($line) > 3 ? substr($line, 3) : '';

        $map = ['M' => 'modified', 'A' => 'added', 'D' => 'deleted', 'R' => 'renamed', 'C' => 'copied', 'T' => 'typechange', 'U' => 'unmerged', '?' => 'untracked', '!' => 'ignored'];

        // rename/copy entries look like "old -> new"
        if (str_contains($file, ' -> ')) {
            $parts = explode(' -> ', $file, 2);
            $file = trim(end($parts));
        }

        $code = $indexStatus . $workStatus;
        if (in_array($code, ['DD', 'AU', 'UD', 'UA', 'DU', 'AA', 'UU'], true)) {
            $status = 'unmerged';
        } elseif ($indexStatus === '?' && $workStatus === '?') {
            $status = 'untracked';
        } elseif ($indexStatus === '!' && $workStatus === '!') {
            $status = 'ignored';
        } elseif ($indexStatus !== ' ' && isset($map[$indexStatus])) {
            $status = $map[$indexStatus];
        } elseif ($workStatus !== ' ' && isset($map[$workStatus])) {
            $status = $map[$workStatus];
        } else {
            $status = 'modified';
        }

        return [
            'file' => $file,
            'status' => $status,
            'index' => $indexStatus,
            'working' => $workStatus,
            // Unmerged entries exist in the index but cannot be committed until
            // the conflict is resolved, so they are never reported as staged.
            'staged' => $status !== 'unmerged' && in_array($indexStatus, ['M', 'A', 'D', 'R', 'C', 'T'], true),
        ];
    }

    /**
     * Returns the unified diff for a single file (or all tracked changes).
     */
    public function diff(Server $server, ?string $file = null, ?string $root = null): ?string
    {
        $this->assertRepository($server);

        $args = ['--no-pager', 'diff', 'HEAD'];

        if ($root) {
            $args[] = '--';
            $args[] = $this->resolvePath($server, $root);
        } elseif ($file) {
            $args[] = '--';
            $args[] = $this->resolvePath($server, $file);
        }

        try {
            return $this->run($server, $args);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'ambiguous argument')) {
                return $this->run($server, ['--no-pager', 'diff']);
            }

            throw $e;
        }
    }

    public function stage(Server $server, array $files): void
    {
        $this->assertRepository($server);
        $this->run($server, array_merge(['add', '--'], $this->resolveFiles($server, $files)));
    }

    public function unstage(Server $server, array $files): void
    {
        $this->assertRepository($server);
        $this->run($server, array_merge(['restore', '--staged', '--'], $this->resolveFiles($server, $files)));
    }

    public function discard(Server $server, array $files): void
    {
        $this->assertRepository($server);
        $this->run($server, array_merge(['checkout', '--'], $this->resolveFiles($server, $files)));
    }

    public function commit(Server $server, string $message, bool $stageAll = false): string
    {
        $this->begin($server, 'commit', null, $this->currentBranchish($server));

        try {
            $this->assertRepository($server);
            if (trim($message) === '') {
                throw new RuntimeException('A commit message is required.');
            }

            // Optionally stage everything first so a commit can never fail just
            // because the client forgot to hit "Stage all" beforehand.
            if ($stageAll) {
                $this->run($server, ['add', '-A']);
            }

            $staged = $this->run($server, ['diff', '--cached', '--name-only']);
            if (trim($staged) === '') {
                throw new RuntimeException('No changes have been staged for commit. Stage files first, or enable "stage all" when committing.');
            }

            $this->run($server, ['config', 'user.name']);
            $this->assertIdentity($server);

            $this->run($server, ['commit', '-m', $message]);
            $sha = $this->run($server, ['rev-parse', 'HEAD']);
        } catch (RuntimeException $exception) {
            $this->fail($exception->getMessage());

            throw $exception;
        }

        $this->succeed(substr($sha, 0, 40));

        return trim($sha);
    }

    public function commitAndPush(Server $server, string $message, bool $stageAll = false): array
    {
        $sha = $this->commit($server, $message, $stageAll);
        $push = $this->push($server);

        return ['commit' => $sha, 'pushed' => $push];
    }

    public function pull(Server $server): array
    {
        $this->begin($server, 'pull', null, $this->currentBranchish($server));

        try {
            $this->assertRepository($server);
            $repository = $this->requireLinked($server);
            $token = $repository->githubAccount->accessToken();
            $remoteUrl = $repository->remote_url;
            $branch = $this->currentBranchish($server);

            $refspec = "refs/heads/{$branch}:refs/remotes/origin/{$branch}";
            $this->runWithTokenUrl($server, ['fetch', 'origin', $refspec], $remoteUrl, $token);
            $this->run($server, ['merge', '--ff-only', 'origin/' . $branch]);
        } catch (RuntimeException $exception) {
            $this->fail($exception->getMessage());

            throw $this->pullError($exception);
        }

        $this->succeed();

        return $this->status($server);
    }

    public function push(Server $server): array
    {
        $this->begin($server, 'push', null, $this->currentBranchish($server));

        try {
            $this->assertRepository($server);
            $repository = $this->requireLinked($server);
            $token = $repository->githubAccount->accessToken();
            $remoteUrl = $repository->remote_url;

            $this->runWithTokenUrl($server, ['push', 'origin', 'HEAD'], $remoteUrl, $token);
        } catch (RuntimeException $exception) {
            $this->fail($exception->getMessage());

            throw $this->pushError($exception);
        }

        $this->succeed();

        return $this->status($server);
    }

    public function branches(Server $server): array
    {
        $this->assertRepository($server);

        $localOutput = $this->run($server, ['branch', '--list']);
        $remoteOutput = $this->run($server, ['branch', '-r', '--list']);
        $current = $this->run($server, ['rev-parse', '--abbrev-ref', 'HEAD']);

        $local = array_values(array_filter(array_map('trim', explode("\n", $localOutput))));
        $remote = array_values(array_filter(array_map(fn ($line) => trim($line), explode("\n", $remoteOutput))));

        return [
            'current' => $current,
            'local' => str_replace('* ', '', $local),
            'remote' => $remote,
        ];
    }

    public function createBranch(Server $server, string $name, string $basedOn): void
    {
        $this->begin($server, 'create-branch', null, $basedOn);
        $this->assertValidReference($name);

        try {
            $this->assertRepository($server);
            $this->run($server, ['branch', $name, $basedOn]);
        } catch (RuntimeException $exception) {
            $this->fail($exception->getMessage());
            throw $exception;
        }

        $this->succeed();
    }

    public function switchBranch(Server $server, string $name): array
    {
        $this->begin($server, 'switch-branch', null, $name);
        $this->assertValidReference($name);

        try {
            $this->assertRepository($server);
            $this->run($server, ['checkout', $name]);
        } catch (RuntimeException $exception) {
            $this->fail($exception->getMessage());
            throw $exception;
        }

        $this->succeed();

        if ($this->usingWorktree($server) && $this->actingUserId !== null) {
            GitWorktree::updateOrCreate(
                ['server_id' => $server->id, 'user_id' => $this->actingUserId],
                ['branch' => $name],
            );
        } else {
            ServerGitRepository::query()->where('server_id', $server->id)->update(['current_branch' => $name]);
        }

        return $this->status($server);
    }

    public function deleteBranch(Server $server, string $name, bool $force = false): array
    {
        $this->begin($server, 'delete-branch', null, $name);
        $this->assertValidReference($name);

        try {
            $this->assertRepository($server);
            $args = ['branch', $force ? '-D' : '-d', $name];
            $this->run($server, $args);
        } catch (RuntimeException $exception) {
            $this->fail($exception->getMessage());
            throw $exception;
        }

        $this->succeed();

        return $this->branches($server);
    }

    public function history(Server $server, int $limit = 25, int $offset = 0): array
    {
        $this->assertRepository($server);

        $args = [
            '--no-pager', 'log',
            '--pretty=format:%H|%h|%an|%ae|%s|%ar',
            '--max-count=' . max(1, min(100, $limit)),
            '--skip=' . max(0, $offset),
        ];

        $output = $this->run($server, $args);

        // Count total commits for pagination metadata
        try {
            $total = (int) $this->run($server, ['rev-list', '--count', 'HEAD']);
        } catch (RuntimeException $e) {
            $total = 0;
        }

        $commits = [];
        foreach (array_filter(explode("\n", $output)) as $line) {
            [$hash, $short, $author, $email, $subject, $relative] = array_pad(explode('|', $line, 6), 6, '');

            $commits[] = [
                'hash' => $hash,
                'short' => $short,
                'author' => $author,
                'email' => $email,
                'subject' => $subject,
                'relative' => $relative,
            ];
        }

        return [
            'commits' => $commits,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public function revert(Server $server, string $sha): array
    {
        $this->begin($server, 'revert', null, $this->currentBranchish($server));
        $this->assertValidReference($sha);

        try {
            $this->assertRepository($server);
            $this->assertIdentity($server);
            $this->run($server, ['revert', '--no-edit', $sha]);
        } catch (RuntimeException $exception) {
            $this->fail($exception->getMessage());
            throw $exception;
        }

        $this->succeed();

        return $this->history($server);
    }

    public function getGitignore(Server $server): string
    {
        return $this->git->readFile($server, $this->effectiveCwd($server), '.gitignore') ?? '';
    }

    public function saveGitignore(Server $server, string $content): void
    {
        $this->begin($server, 'gitignore', null, null);

        try {
            $this->assertRepository($server);
            $this->git->writeFile($server, $this->effectiveCwd($server), '.gitignore', $content);
        } catch (RuntimeException $exception) {
            $this->fail($exception->getMessage());
            throw $exception;
        }

        $this->succeed();
    }

    public function identity(Server $server): array
    {
        $this->assertRepository($server);

        try {
            $name = $this->run($server, ['config', 'user.name']);
        } catch (RuntimeException $e) {
            $name = '';
        }
        try {
            $email = $this->run($server, ['config', 'user.email']);
        } catch (RuntimeException $e) {
            $email = '';
        }

        return ['name' => $name, 'email' => $email];
    }

    public function saveIdentity(Server $server, string $name, string $email): void
    {
        $this->begin($server, 'identity', null, null);

        try {
            $this->assertRepository($server);
            $this->run($server, ['config', 'user.name', $name]);
            $this->run($server, ['config', 'user.email', $email]);
        } catch (RuntimeException $exception) {
            $this->fail($exception->getMessage());
            throw $exception;
        }

        $this->succeed();
    }

    public function remote(Server $server): array
    {
        $this->assertRepository($server);
        $url = $this->run($server, ['remote', 'get-url', 'origin']);

        return ['name' => 'origin', 'url' => $this->sanitizeUrl($url)];
    }

    /**
     * Strips any embedded credentials from a remote URL before it reaches the frontend.
     */
    public function sanitizeUrl(string $url): string
    {
        return preg_replace('#^(https?://)([^@/]+@)#', '$1', $url) ?? $url;
    }

    /**
     * @param array<int, string> $files returns resolved absolute paths
     */
    private function resolveFiles(Server $server, array $files): array
    {
        if (count($files) === 0) {
            throw new RuntimeException('No files were provided.');
        }

        return array_map(fn (string $file) => $this->resolvePath($server, $file), $files);
    }

    private function assertRepository(Server $server): void
    {
        if (!$this->isRepo($server)) {
            throw new RuntimeException('The server directory is not a git repository.');
        }
    }

    public function assertRepositoryExists(Server $server): void
    {
        $this->assertRepository($server);
    }

    /**
     * Fetches a branch from origin using the stored token, then sets upstream tracking.
     */
    public function fetch(Server $server, string $branch): void
    {
        $this->assertRepository($server);
        $repository = $this->requireLinked($server);
        $token = $repository->githubAccount->accessToken();
        $remoteUrl = $repository->remote_url;

        $refspec = "refs/heads/{$branch}:refs/remotes/origin/{$branch}";
        $this->runWithTokenUrl($server, ['fetch', 'origin', $refspec], $remoteUrl, $token);
        $this->run($server, ['branch', '--set-upstream-to', 'origin/' . $branch, $branch]);
    }

    /**
     * Destructive hard reset of the current tree to a given ref.
     */
    public function hardReset(Server $server, string $ref): void
    {
        $this->assertRepository($server);
        $this->run($server, ['clean', '-fd']);
        $this->run($server, ['reset', '--hard', $ref]);
    }

    /**
     * Checks out (creating if necessary) the given branch.
     */
    public function checkoutRef(Server $server, string $ref): void
    {
        $this->assertRepository($server);
        try {
            $this->run($server, ['checkout', $ref]);
        } catch (RuntimeException $e) {
            $this->run($server, ['checkout', '-b', $ref]);
        }
    }

    private function assertIdentity(Server $server): void
    {
        try {
            $name = $this->run($server, ['config', 'user.name']);
            $email = $this->run($server, ['config', 'user.email']);
        } catch (RuntimeException $e) {
            throw new RuntimeException('Git identity is not configured. Set a name and email on the Settings tab first.');
        }

        if ($name === '' || $email === '') {
            throw new RuntimeException('Git identity is not configured. Set a name and email on the Settings tab first.');
        }
    }

    private function currentBranchish(Server $server): string
    {
        try {
            return $this->run($server, ['rev-parse', '--abbrev-ref', 'HEAD']);
        } catch (RuntimeException $e) {
            return 'main';
        }
    }

    /**
     * Returns full details for a single commit: metadata, file list, and unified diff.
     */
    public function commitDetail(Server $server, string $sha): array
    {
        $this->assertRepository($server);
        $this->assertValidReference($sha);

        // Full hash
        $fullSha = trim($this->run($server, ['rev-parse', $sha]));

        // Commit metadata
        $format = '%H|%h|%an|%ae|%s|%ar|%ai';
        $log = $this->run($server, ['log', '-1', "--pretty=format:{$format}", $fullSha]);
        [$hash, $short, $author, $email, $subject, $relative, $date] = array_pad(explode('|', $log, 7), 7, '');

        // Changed files with status
        $statRaw = $this->run($server, ['diff-tree', '--no-commit-id', '-r', '--name-status', $fullSha]);
        $files = [];
        foreach (array_filter(explode("\n", $statRaw)) as $line) {
            $parts = preg_split('/\s+/', $line, 2);
            if (count($parts) === 2) {
                $files[] = ['status' => $parts[0], 'file' => $parts[1]];
            } elseif (count($parts) === 1 && $parts[0] !== '') {
                // merge commit — diff-tree -r without -m shows nothing for merges
                $files[] = ['status' => 'M', 'file' => $parts[0]];
            }
        }

        // If merge commit, use -m flag to show combined diff
        if (count($files) === 0) {
            $parents = trim($this->run($server, ['rev-list', '--parents', '-n', '1', $fullSha]));
            $parentCount = count(explode(' ', $parents));
            if ($parentCount > 1) {
                $statRaw = $this->run($server, ['diff-tree', '--no-commit-id', '-r', '-m', '--name-status', $fullSha]);
                foreach (array_filter(explode("\n", $statRaw)) as $line) {
                    $parts = preg_split('/\s+/', $line, 2);
                    if (count($parts) === 2) {
                        $files[] = ['status' => $parts[0], 'file' => $parts[1]];
                    }
                }
            }
        }

        // Unified diff (suppress binary file diffs)
        $diff = '';
        try {
            $diff = $this->run($server, ['diff', $fullSha . '^', $fullSha], true, false);
        } catch (RuntimeException $e) {
            // Root commit has no parent
            $diff = $this->run($server, ['diff', '--no-index', '/dev/null', $fullSha], false, false);
        }

        return [
            'hash' => $fullSha,
            'short' => $short,
            'author' => $author,
            'email' => $email,
            'subject' => $subject,
            'relative' => $relative,
            'date' => $date,
            'files' => $files,
            'diff' => $diff,
        ];
    }

    /**
     * Returns the list of git stash entries.
     */
    public function stashList(Server $server): array
    {
        $this->assertRepository($server);

        try {
            $output = $this->run($server, ['stash', 'list', '--pretty=format:%gd|%gs|%gD|%ar']);
        } catch (RuntimeException $e) {
            return [];
        }

        $stashes = [];
        foreach (array_filter(explode("\n", $output)) as $i => $line) {
            [$ref, $subject, $branch, $relative] = array_pad(explode('|', $line, 4), 4, '');
            $stashes[] = [
                'index' => $i,
                'ref' => $ref,
                'subject' => $subject,
                'branch' => $branch,
                'relative' => $relative,
            ];
        }

        return $stashes;
    }

    /**
     * Stashes the current working directory changes.
     */
    public function stashPush(Server $server, ?string $message = null): array
    {
        $this->begin($server, 'stash', null, $this->currentBranchish($server));

        try {
            $this->assertRepository($server);
            $args = ['stash', 'push'];
            if ($message !== null && trim($message) !== '') {
                $args[] = '-m';
                $args[] = $message;
            }
            $this->run($server, $args);
        } catch (RuntimeException $exception) {
            $this->fail($exception->getMessage());
            throw $exception;
        }

        $this->succeed();

        return ['stashes' => $this->stashList($server), 'status' => $this->status($server)];
    }

    /**
     * Pops the most recent stash entry.
     */
    public function stashPop(Server $server): array
    {
        $this->begin($server, 'stash-pop', null, $this->currentBranchish($server));

        try {
            $this->assertRepository($server);
            $this->run($server, ['stash', 'pop']);
        } catch (RuntimeException $exception) {
            $this->fail($exception->getMessage());
            throw $exception;
        }

        $this->succeed();

        return ['stashes' => $this->stashList($server), 'status' => $this->status($server)];
    }

    /**
     * Drops a stash entry by index.
     */
    public function stashDrop(Server $server, int $index): array
    {
        $this->begin($server, 'stash-drop', null, $this->currentBranchish($server));

        try {
            $this->assertRepository($server);
            $this->run($server, ['stash', 'drop', "stash@{{$index}}"]);
        } catch (RuntimeException $exception) {
            $this->fail($exception->getMessage());
            throw $exception;
        }

        $this->succeed();

        return ['stashes' => $this->stashList($server)];
    }

    private function assertValidReference(string $reference): void
    {
        if ($reference === '' || $reference !== trim($reference)) {
            throw new RuntimeException('Invalid reference.');
        }
        if (preg_match('/[\s~^:?*\x{00}-\x{1f}\x{7f}]|\.\.|@{|^[.\/]|[\/.]$|^\/|^\.|^\$\{?/u', $reference)) {
            throw new RuntimeException('The reference contains invalid characters.');
        }
    }

    private function begin(Server $server, string $operation, ?string $repoName, ?string $branch): void
    {
        // Operations like commit/push/pull do not know the repository name, so
        // resolve it from the linked repository to keep the audit trail useful.
        if ($repoName === null) {
            $repoName = $this->linked($server)?->repository_full_name ?? null;
        }

        GitOperation::create([
            'server_id' => $server->id,
            'user_id' => $this->actingUserId ?? $server->owner_id,
            'repository' => $repoName,
            'operation' => $operation,
            'branch' => $branch,
            'status' => 'running',
            'started_at' => CarbonImmutable::now(),
        ]);
    }

    private function succeed(?string $sha = null): void
    {
        $operation = GitOperation::query()->where('status', 'running')->latest('id')->first();
        if (!$operation) {
            return;
        }

        $operation->update([
            'status' => 'success',
            'commit_sha' => $sha,
            'completed_at' => CarbonImmutable::now(),
        ]);
    }

    private function fail(string $error): void
    {
        $operation = GitOperation::query()->where('status', 'running')->latest('id')->first();
        if (!$operation) {
            return;
        }

        $operation->update([
            'status' => 'failed',
            'error' => $error,
            'completed_at' => CarbonImmutable::now(),
        ]);
    }

    private function pullError(RuntimeException $exception): RuntimeException
    {
        $message = $exception->getMessage();
        if (str_contains($message, 'would be overwritten')) {
            return new RuntimeException('Local changes would be overwritten by this pull. Commit or discard your local changes first.');
        }
        if (str_contains($message, 'diverged') || str_contains($message, 'fast-forward')) {
            return new RuntimeException('Your local branch and the remote branch have diverged. Pull changes in with a merge or reset, then try again.');
        }

        return $exception;
    }

    private function pushError(RuntimeException $exception): RuntimeException
    {
        $message = $exception->getMessage();
        if (str_contains($message, 'rejected') || str_contains($message, 'fetch first')) {
            return new RuntimeException('The remote branch contains changes that are not present locally. Pull first, then push again.');
        }
        if (str_contains($message, 'Authentication failed') || str_contains($message, 'could not read')) {
            return new RuntimeException('Authentication to GitHub failed. Reconnect your GitHub account and try again.');
        }

        return $exception;
    }
}