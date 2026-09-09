<?php

namespace Pterodactyl\Services\Git;

use Carbon\CarbonImmutable;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\GitOperation;
use Pterodactyl\Models\GithubAccount;
use Pterodactyl\Models\ServerGitRepository;
use RuntimeException;

/**
 * Orchestrates Git operations against a server's container volume and keeps
 * the panel's git_operations audit trail up to date.
 */
class RepositoryService
{
    public function __construct(
        private readonly GitService $git,
        private readonly GitHubService $github,
    ) {
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
            $this->git->run($server, ['init', '-b', $defaultBranch ?: 'main']);
            $this->git->run($server, ['config', 'user.name', $name]);
            $this->git->run($server, ['config', 'user.email', $email]);
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
                $this->git->run($server, ['init', '-b', $branch]);
                $this->git->run($server, ['remote', 'add', 'origin', $remoteUrl]);
                $this->git->run($server, ['config', 'user.name', $account->username]);
                $this->git->run($server, ['config', 'user.email', $account->username . '@users.noreply.github.com']);
            } else {
                $this->git->run($server, ['init', '-b', $branch]);
                $this->ensureRemote($server, $remoteUrl);
                // Fetch with an explicit refspec so the remote-tracking ref
                // refs/remotes/origin/<branch> is written even though we pass
                // the token-embedded URL directly instead of the remote name.
                $refspec = "refs/heads/{$branch}:refs/remotes/origin/{$branch}";
                $this->git->runWithTokenUrl($server, ['fetch', 'origin', $refspec], $remoteUrl, $token);
                $this->git->run($server, ['checkout', '-B', $branch, 'origin/' . $branch]);
                if ($mode === 'clone') {
                    $this->git->run($server, ['reset', '--hard', 'origin/' . $branch]);
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
            $this->git->run($server, ['remote', 'get-url', 'origin']);
        } catch (RuntimeException $e) {
            $this->git->run($server, ['remote', 'add', 'origin', $remoteUrl]);

            return;
        }

        $this->git->run($server, ['remote', 'set-url', 'origin', $remoteUrl]);
    }

    /**
     * Removes the repository association from the server.
     * No credential files to clean up — we no longer write any.
     */
    public function disconnect(Server $server): void
    {
        ServerGitRepository::query()->where('server_id', $server->id)->delete();
    }

    /**
     * Returns the current status of the repository.
     */
    public function status(Server $server, ?ServerGitRepository $repository = null): array
    {
        $repository = $repository ?? $this->linked($server);

        $data = [
            'is_repository' => $this->git->isRepository($server),
            'connected' => (bool) $repository,
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
            $data['current_branch'] = $this->git->run($server, ['rev-parse', '--abbrev-ref', 'HEAD']);
        } catch (RuntimeException $e) {
            $data['current_branch'] = null;
        }

        try {
            $this->git->run($server, ['rev-parse', '--verify', 'HEAD']);
            $data['last_commit'] = substr($this->git->run($server, ['rev-parse', 'HEAD']), 0, 7);
            $data['last_commit_message'] = $this->git->run($server, ['log', '-1', '--pretty=%s']);
        } catch (RuntimeException $e) {
            // no commits yet
        }

        $data['changes'] = $this->changes($server);
        $data['is_dirty'] = count($data['changes']) > 0;

        try {
            $statusInfo = $this->git->run($server, ['status', '-sb']);
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
            $args[] = $this->git->resolvePath($server, $path);
        }

        // Raw (untrimmed) output is required: in porcelain format column 0 is
        // the index status and may be a meaningful space for unstaged changes,
        // so the output must never be left-trimmed before parsing.
        $output = $this->git->run($server, $args, true, false);

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
            $args[] = $this->git->resolvePath($server, $root);
        } elseif ($file) {
            $args[] = '--';
            $args[] = $this->git->resolvePath($server, $file);
        }

        try {
            return $this->git->run($server, $args);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'ambiguous argument')) {
                return $this->git->run($server, ['--no-pager', 'diff']);
            }

            throw $e;
        }
    }

    public function stage(Server $server, array $files): void
    {
        $this->assertRepository($server);
        $this->git->run($server, array_merge(['add', '--'], $this->resolveFiles($server, $files)));
    }

    public function unstage(Server $server, array $files): void
    {
        $this->assertRepository($server);
        $this->git->run($server, array_merge(['restore', '--staged', '--'], $this->resolveFiles($server, $files)));
    }

    public function discard(Server $server, array $files): void
    {
        $this->assertRepository($server);
        $this->git->run($server, array_merge(['checkout', '--'], $this->resolveFiles($server, $files)));
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
                $this->git->run($server, ['add', '-A']);
            }

            $staged = $this->git->run($server, ['diff', '--cached', '--name-only']);
            if (trim($staged) === '') {
                throw new RuntimeException('No changes have been staged for commit. Stage files first, or enable "stage all" when committing.');
            }

            $this->git->run($server, ['config', 'user.name']);
            $this->assertIdentity($server);

            $this->git->run($server, ['commit', '-m', $message]);
            $sha = $this->git->run($server, ['rev-parse', 'HEAD']);
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
            $this->git->runWithTokenUrl($server, ['fetch', 'origin', $refspec], $remoteUrl, $token);
            $this->git->run($server, ['merge', '--ff-only', 'origin/' . $branch]);
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

            $this->git->runWithTokenUrl($server, ['push', 'origin', 'HEAD'], $remoteUrl, $token);
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

        $localOutput = $this->git->run($server, ['branch', '--list']);
        $remoteOutput = $this->git->run($server, ['branch', '-r', '--list']);
        $current = $this->git->run($server, ['rev-parse', '--abbrev-ref', 'HEAD']);

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
            $this->git->run($server, ['branch', $name, $basedOn]);
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
            $this->git->run($server, ['checkout', $name]);
        } catch (RuntimeException $exception) {
            $this->fail($exception->getMessage());
            throw $exception;
        }

        $this->succeed();

        ServerGitRepository::query()->where('server_id', $server->id)->update(['current_branch' => $name]);

        return $this->status($server);
    }

    public function deleteBranch(Server $server, string $name, bool $force = false): array
    {
        $this->begin($server, 'delete-branch', null, $name);
        $this->assertValidReference($name);

        try {
            $this->assertRepository($server);
            $args = ['branch', $force ? '-D' : '-d', $name];
            $this->git->run($server, $args);
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

        $output = $this->git->run($server, $args);

        // Count total commits for pagination metadata
        try {
            $total = (int) $this->git->run($server, ['rev-list', '--count', 'HEAD']);
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
            $this->git->run($server, ['revert', '--no-edit', $sha]);
        } catch (RuntimeException $exception) {
            $this->fail($exception->getMessage());
            throw $exception;
        }

        $this->succeed();

        return $this->history($server);
    }

    public function getGitignore(Server $server): string
    {
        $this->assertRepository($server);
        $path = $this->git->containerDirectory($server) . '/.gitignore';

        return file_exists($path) ? (string) file_get_contents($path) : '';
    }

    public function saveGitignore(Server $server, string $content): void
    {
        $this->begin($server, 'gitignore', null, null);

        try {
            $this->assertRepository($server);
            $path = $this->git->containerDirectory($server) . '/.gitignore';
            $this->git->runAsContainerUserWithInput(['/usr/bin/tee', $path], $content);
            $this->git->runAsContainerUser(['/usr/bin/chown', 'pterodactyl:pterodactyl', $path]);
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
            $name = $this->git->run($server, ['config', 'user.name']);
        } catch (RuntimeException $e) {
            $name = '';
        }
        try {
            $email = $this->git->run($server, ['config', 'user.email']);
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
            $this->git->run($server, ['config', 'user.name', $name]);
            $this->git->run($server, ['config', 'user.email', $email]);
        } catch (RuntimeException $exception) {
            $this->fail($exception->getMessage());
            throw $exception;
        }

        $this->succeed();
    }

    public function remote(Server $server): array
    {
        $this->assertRepository($server);
        $url = $this->git->run($server, ['remote', 'get-url', 'origin']);

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

        return array_map(fn (string $file) => $this->git->resolvePath($server, $file), $files);
    }

    private function assertRepository(Server $server): void
    {
        if (!$this->git->isRepository($server)) {
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
        $this->git->runWithTokenUrl($server, ['fetch', 'origin', $refspec], $remoteUrl, $token);
        $this->git->run($server, ['branch', '--set-upstream-to', 'origin/' . $branch, $branch]);
    }

    /**
     * Destructive hard reset of the current tree to a given ref.
     */
    public function hardReset(Server $server, string $ref): void
    {
        $this->assertRepository($server);
        $this->git->run($server, ['clean', '-fd']);
        $this->git->run($server, ['reset', '--hard', $ref]);
    }

    /**
     * Checks out (creating if necessary) the given branch.
     */
    public function checkoutRef(Server $server, string $ref): void
    {
        $this->assertRepository($server);
        try {
            $this->git->run($server, ['checkout', $ref]);
        } catch (RuntimeException $e) {
            $this->git->run($server, ['checkout', '-b', $ref]);
        }
    }

    private function assertIdentity(Server $server): void
    {
        try {
            $name = $this->git->run($server, ['config', 'user.name']);
            $email = $this->git->run($server, ['config', 'user.email']);
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
            return $this->git->run($server, ['rev-parse', '--abbrev-ref', 'HEAD']);
        } catch (RuntimeException $e) {
            return 'main';
        }
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
            'user_id' => $server->owner_id,
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