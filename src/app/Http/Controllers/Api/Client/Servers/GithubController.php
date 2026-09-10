<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\GithubAccount;
use Pterodactyl\Models\Server;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Services\Git\RepositoryService;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Github\ViewGithubRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Github\ConnectRepositoryRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Github\DisconnectRepositoryRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Github\ResetRepositoryRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Github\FilesRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Github\CommitRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Github\PullRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Github\PushRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Github\BranchRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Github\SwitchBranchRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Github\DeleteBranchRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Github\CommitHistoryRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Github\RevertRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Github\DiffRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Github\SaveGitignoreRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Github\SaveIdentityRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Github\CommitDetailRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Github\StashRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Github\StashDropRequest;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use RuntimeException;

class GithubController extends ClientApiController
{
    public function __construct(
        private readonly RepositoryService $repositories,
    ) {
    }

    /**
     * Returns the combined status view for the server repository.
     */
    public function show(ViewGithubRequest $request, Server $server): array
    {
        $notConnected = [
            'is_repository' => false,
            'connected' => false,
            'current_branch' => null,
            'ahead' => 0,
            'behind' => 0,
            'is_dirty' => false,
            'changes' => [],
            'last_commit' => null,
            'last_commit_message' => null,
            'repository' => null,
        ];

        if (!$this->moduleEnabled()) {
            return [
                'status' => $notConnected,
                'module' => ['enabled' => false, 'admin' => (bool) $request->user()->root_admin],
                'accounts' => [],
            ];
        }

        // Use linked() instead of requireLinked() so we return a proper
        // "not connected" response instead of throwing a 500 error.
        $repository = $this->repositories->linked($server);

        if (!$repository) {
            return [
                'status' => $notConnected,
                'module' => ['enabled' => true, 'admin' => (bool) $request->user()->root_admin],
                'accounts' => $request->user()->githubAccounts()->get(['id', 'provider', 'username', 'avatar_url']),
            ];
        }

        $this->authorizeRepositoryOwner($request, $server, $repository);

        return [
            'status' => $this->repositories->status($server, $repository),
            'module' => ['enabled' => true, 'admin' => (bool) $request->user()->root_admin],
            'accounts' => $request->user()->githubAccounts()->get(['id', 'provider', 'username', 'avatar_url']),
        ];
    }

    /**
     * Connects or initializes a repository for the server.
     */
    public function connect(ConnectRepositoryRequest $request, Server $server): JsonResponse
    {
        $this->assertModuleEnabled();
        $repository = $this->repositories->linked($server);
        if ($repository) {
            throw new DisplayException('This server already has a repository connected. Disconnect it first.');
        }

        return $this->guarded($server, function () use ($request, $server) {
            if ($request->boolean('initialize')) {
                $this->repositories->initialize(
                    $server,
                    (string) $request->input('name', ''), // not on the connect form; identity set after connect
                    (string) $request->input('email', ''),
                    (string) $request->input('default_branch', 'main'),
                );

                return new JsonResponse(['status' => $this->repositories->status($server)]);
            }

            /** @var GithubAccount $account */
            $account = $request->user()->githubAccounts()->findOrFail($request->input('account_id'));

            $status = $this->repositories->connect($server, $account, [
                'repository_id' => $request->input('repository_id'),
                'repository_full_name' => $request->input('repository_full_name'),
                'remote_url' => $request->input('remote_url'),
                'default_branch' => $request->input('branch', $request->input('default_branch', 'main')),
                'branch' => $request->input('branch', $request->input('default_branch', 'main')),
                'working_directory' => $request->input('working_directory'),
            ], (string) $request->input('mode', 'clone'));

            return new JsonResponse(['status' => $status]);
        });
    }

    public function disconnect(DisconnectRepositoryRequest $request, Server $server): JsonResponse
    {
        $this->assertModuleEnabled();
        $repository = $this->repositories->requireLinked($server);
        $this->authorizeRepositoryOwner($request, $server, $repository);

        try {
            $this->repositories->disconnect($server);
        } catch (RuntimeException $exception) {
            throw new DisplayException($exception->getMessage());
        }

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * Performs a destructive hard reset of the repository against the remote branch.
     */
    public function reset(ResetRepositoryRequest $request, Server $server): JsonResponse
    {
        $this->assertModuleEnabled();
        $repository = $this->repositories->requireLinked($server);
        $this->authorizeRepositoryOwner($request, $server, $repository);

        $branch = (string) $request->input('branch');

        return $this->guarded($server, function () use ($server, $branch) {
            $this->repositories->assertRepositoryExists($server);
            $this->repositories->fetch($server, $branch);
            $this->repositories->hardReset($server, "origin/$branch");
            $this->repositories->checkoutRef($server, $branch);

            return new JsonResponse(['status' => $this->repositories->status($server)]);
        });
    }

    public function changes(ViewGithubRequest $request, Server $server): JsonResponse
    {
        $this->authorizeLinked($request, $server);

        return new JsonResponse(['changes' => $this->repositories->changes($server)]);
    }

    public function diff(DiffRequest $request, Server $server): JsonResponse
    {
        $this->authorizeLinked($request, $server);

        return new JsonResponse([
            'diff' => $this->repositories->diff(
                $server,
                $request->input('file'),
                $request->input('root'),
            ),
        ]);
    }

    public function stage(FilesRequest $request, Server $server): JsonResponse
    {
        $this->assertModuleEnabled();
        $this->authorizeLinked($request, $server);

        return $this->guarded($server, function () use ($request, $server) {
            $this->repositories->stage($server, $request->input('files'));

            return new JsonResponse(['status' => $this->repositories->status($server)]);
        });
    }

    public function unstage(FilesRequest $request, Server $server): JsonResponse
    {
        $this->assertModuleEnabled();
        $this->authorizeLinked($request, $server);

        return $this->guarded($server, function () use ($request, $server) {
            $this->repositories->unstage($server, $request->input('files'));

            return new JsonResponse(['status' => $this->repositories->status($server)]);
        });
    }

    public function discard(FilesRequest $request, Server $server): JsonResponse
    {
        $this->assertModuleEnabled();
        $this->authorizeLinked($request, $server);

        return $this->guarded($server, function () use ($request, $server) {
            $this->repositories->discard($server, $request->input('files'));

            return new JsonResponse(['status' => $this->repositories->status($server)]);
        });
    }

    public function commit(CommitRequest $request, Server $server): JsonResponse
    {
        $this->assertModuleEnabled();
        $this->authorizeLinked($request, $server);

        return $this->guarded($server, function () use ($request, $server) {
            $stageAll = $request->boolean('all');

            if ($request->boolean('push')) {
                $result = $this->repositories->commitAndPush($server, (string) $request->input('message'), $stageAll);
            } else {
                $result = ['commit' => $this->repositories->commit($server, (string) $request->input('message'), $stageAll), 'pushed' => false];
            }

            return new JsonResponse(array_merge($result, ['status' => $this->repositories->status($server)]));
        });
    }

    public function pull(PullRequest $request, Server $server): JsonResponse
    {
        $this->assertModuleEnabled();
        $this->authorizeLinked($request, $server);

        return $this->guarded($server, function () use ($server) {
            return new JsonResponse(['status' => $this->repositories->pull($server)]);
        });
    }

    public function push(PushRequest $request, Server $server): JsonResponse
    {
        $this->assertModuleEnabled();
        $this->authorizeLinked($request, $server);

        return $this->guarded($server, function () use ($server) {
            return new JsonResponse(['status' => $this->repositories->push($server)]);
        });
    }

    public function branches(ViewGithubRequest $request, Server $server): JsonResponse
    {
        $this->authorizeLinked($request, $server);

        return new JsonResponse(['branches' => $this->repositories->branches($server)]);
    }

    public function createBranch(BranchRequest $request, Server $server): JsonResponse
    {
        $this->assertModuleEnabled();
        $this->authorizeLinked($request, $server);

        return $this->guarded($server, function () use ($request, $server) {
            $this->repositories->createBranch(
                $server,
                (string) $request->input('name'),
                (string) $request->input('based_on', 'HEAD'),
            );

            return new JsonResponse(['branches' => $this->repositories->branches($server)]);
        });
    }

    public function switchBranch(SwitchBranchRequest $request, Server $server): JsonResponse
    {
        $this->assertModuleEnabled();
        $this->authorizeLinked($request, $server);

        return $this->guarded($server, function () use ($request, $server) {
            return new JsonResponse(['status' => $this->repositories->switchBranch($server, (string) $request->input('name'))]);
        });
    }

    public function deleteBranch(DeleteBranchRequest $request, Server $server, string $name): JsonResponse
    {
        $this->assertModuleEnabled();
        $this->authorizeLinked($request, $server);

        return $this->guarded($server, function () use ($request, $server, $name) {
            $branches = $this->repositories->deleteBranch($server, $name, $request->boolean('force'));

            return new JsonResponse(['branches' => $branches]);
        });
    }

    public function history(CommitHistoryRequest $request, Server $server): JsonResponse
    {
        $this->authorizeLinked($request, $server);

        $history = $this->repositories->history($server, (int) $request->input('limit', 25));

        return new JsonResponse([
            'commits' => $history['commits'],
            'total' => $history['total'],
            'limit' => $history['limit'],
            'offset' => $history['offset'],
        ]);
    }

    public function revert(RevertRequest $request, Server $server): JsonResponse
    {
        $this->assertModuleEnabled();
        $this->authorizeLinked($request, $server);

        return $this->guarded($server, function () use ($request, $server) {
            return new JsonResponse(['status' => $this->repositories->revert($server, (string) $request->input('sha'))]);
        });
    }

    public function gitignore(ViewGithubRequest $request, Server $server): JsonResponse
    {
        $this->authorizeLinked($request, $server);

        return new JsonResponse(['content' => $this->repositories->getGitignore($server)]);
    }

    public function saveGitignore(SaveGitignoreRequest $request, Server $server): JsonResponse
    {
        $this->assertModuleEnabled();
        $this->authorizeLinked($request, $server);

        return $this->guarded($server, function () use ($request, $server) {
            $this->repositories->saveGitignore($server, (string) $request->input('content', ''));

            return new JsonResponse(['content' => $this->repositories->getGitignore($server)]);
        });
    }

    public function identity(ViewGithubRequest $request, Server $server): JsonResponse
    {
        $this->authorizeLinked($request, $server);

        return new JsonResponse(['identity' => $this->repositories->identity($server)]);
    }

    public function saveIdentity(SaveIdentityRequest $request, Server $server): JsonResponse
    {
        $this->assertModuleEnabled();
        $this->authorizeLinked($request, $server);

        return $this->guarded($server, function () use ($request, $server) {
            $this->repositories->saveIdentity($server, (string) $request->input('name'), (string) $request->input('email'));

            return new JsonResponse(['identity' => $this->repositories->identity($server)]);
        });
    }

    public function remote(ViewGithubRequest $request, Server $server): JsonResponse
    {
        $this->authorizeLinked($request, $server);

        return new JsonResponse(['remote' => $this->repositories->remote($server)]);
    }

    public function commitDetail(CommitDetailRequest $request, Server $server): JsonResponse
    {
        $this->authorizeLinked($request, $server);

        return new JsonResponse([
            'commit' => $this->repositories->commitDetail($server, (string) $request->input('sha')),
        ]);
    }

    public function stashList(ViewGithubRequest $request, Server $server): JsonResponse
    {
        $this->authorizeLinked($request, $server);

        return new JsonResponse(['stashes' => $this->repositories->stashList($server)]);
    }

    public function stashPush(StashRequest $request, Server $server): JsonResponse
    {
        $this->assertModuleEnabled();
        $this->authorizeLinked($request, $server);

        return $this->guarded($server, function () use ($request, $server) {
            return new JsonResponse($this->repositories->stashPush(
                $server,
                $request->input('message'),
            ));
        });
    }

    public function stashPop(ViewGithubRequest $request, Server $server): JsonResponse
    {
        $this->assertModuleEnabled();
        $this->authorizeLinked($request, $server);

        return $this->guarded($server, function () use ($server) {
            return new JsonResponse($this->repositories->stashPop($server));
        });
    }

    public function stashDrop(StashDropRequest $request, Server $server): JsonResponse
    {
        $this->assertModuleEnabled();
        $this->authorizeLinked($request, $server);

        return $this->guarded($server, function () use ($request, $server) {
            return new JsonResponse($this->repositories->stashDrop($server, (int) $request->input('index')));
        });
    }

    /**
     * Blocks all write-actions when the GitHub module is switched off.
     */
    private function assertModuleEnabled(): void
    {
        if (!config('pterodactyl.git.enabled', true)) {
            throw new DisplayException('The GitHub integration is currently disabled.');
        }
    }

    private function moduleEnabled(): bool
    {
        return (bool) config('pterodactyl.git.enabled', true);
    }

    /**
     * Confirms the requesting user is the owner of the linked repository account or a server admin.
     */
    private function authorizeRepository(ClientApiRequest $request, Server $server): void
    {
        $repository = $this->repositories->requireLinked($server);
        $this->authorizeRepositoryOwner($request, $server, $repository);
    }

    private function authorizeLinked(ClientApiRequest $request, Server $server): void
    {
        $this->authorizeRepository($request, $server);
    }

    /**
     * Runs a write-operation under a per-server lock so concurrent requests cannot corrupt the repo.
     */
    private function guarded(Server $server, callable $callback)
    {
        $lock = Cache::lock('git:server:' . $server->id, 120);

        try {
            if (!$lock->acquire()) {
                throw new DisplayException('Another Git operation is already in progress for this server. Please wait and try again.');
            }

            return $callback();
        } catch (RuntimeException $exception) {
            throw new DisplayException($exception->getMessage(), $exception);
        } finally {
            $lock->release();
        }
    }

    /**
     * Verifies the authenticated user either owns the account that connected the repository
     * or has administrator access to the server.
     */
    private function authorizeRepositoryOwner(ClientApiRequest $request, Server $server, $repository): void
    {
        if ($server->owner_id === $request->user()->id || (bool) $request->user()->root_admin) {
            return;
        }

        if ($repository && $repository->githubAccount?->user_id === $request->user()->id) {
            return;
        }

        throw new DisplayException('You do not have permission to manage this repository.');
    }
}