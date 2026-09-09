<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Account;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\GithubAccount;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Services\Git\GitHubService;
use Pterodactyl\Services\Git\GitHubOAuthService;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Http\Requests\Api\Client\Account\ConnectGithubRequest;
use Pterodactyl\Http\Requests\Api\Client\Account\GithubRepositoriesRequest;

class GithubAccountController extends ClientApiController
{
    public function __construct(
        private readonly GitHubService $github,
        private readonly GitHubOAuthService $oauth,
    ) {
    }

    /**
     * Returns all GitHub accounts linked to the authenticated user along with the
     * current module state so the frontend can render maintenance/disabled UI.
     */
    public function index(ClientApiRequest $request): array
    {
        return [
            'enabled' => (bool) config('pterodactyl.git.enabled', true),
            'oauth_enabled' => $this->oauth->isConfigured(),
            'admin' => (bool) $request->user()->root_admin,
            'base_url' => rtrim(config('app.url', ''), '/'),
            'accounts' => $request->user()->githubAccounts()
                ->get()
                ->map(fn (GithubAccount $account) => [
                    'id' => $account->id,
                    'provider' => $account->provider,
                    'github_user_id' => $account->github_user_id,
                    'username' => $account->username,
                    'avatar_url' => $account->avatar_url,
                    'linked_at' => $account->created_at->toISOString(),
                    'has_token' => true,
                ])
                ->values()
                ->toArray(),
        ];
    }

    /**
     * Connects a GitHub account using a personal access token (PAT).
     *
     * @throws DisplayException
     */
    public function store(ConnectGithubRequest $request): JsonResponse
    {
        if (!config('pterodactyl.git.enabled', true)) {
            throw new DisplayException('The GitHub integration is currently disabled.');
        }

        $token = (string) $request->input('token');

        try {
            $profile = $this->github->whoami($token);
        } catch (\Exception $exception) {
            throw new DisplayException('Unable to verify access token with GitHub: ' . $exception->getMessage());
        }

        $exists = GithubAccount::query()
            ->where('user_id', $request->user()->id)
            ->where('provider', 'github')
            ->where('github_user_id', (string) $profile['id'])
            ->first();

        if ($exists) {
            $exists->update([
                'username' => $profile['login'],
                'avatar_url' => $profile['avatar_url'] ?? null,
                'access_token_encrypted' => Crypt::encryptString($token),
                'refresh_token_encrypted' => null,
            ]);
            $account = $exists;
        } else {
            if (count($request->user()->githubAccounts) >= GithubAccount::MAX_ACCOUNTS_PER_USER) {
                throw new DisplayException('You have reached the account limit for GitHub connections.');
            }

            $account = GithubAccount::create([
                'user_id' => $request->user()->id,
                'provider' => 'github',
                'github_user_id' => (string) $profile['id'],
                'username' => $profile['login'],
                'avatar_url' => $profile['avatar_url'] ?? null,
                'access_token_encrypted' => Crypt::encryptString($token),
            ]);
        }

        return new JsonResponse([
            'id' => $account->id,
            'provider' => $account->provider,
            'github_user_id' => $account->github_user_id,
            'username' => $account->username,
            'avatar_url' => $account->avatar_url,
            'linked_at' => $account->created_at->toISOString(),
            'has_token' => true,
        ], JsonResponse::HTTP_CREATED);
    }

    /**
     * Removes a GitHub account connection.
     */
    public function destroy(ClientApiRequest $request, int $account): JsonResponse
    {
        if (!config('pterodactyl.git.enabled', true)) {
            throw new DisplayException('The GitHub integration is currently disabled.');
        }

        $accountModel = $request->user()->githubAccounts()->findOrFail($account);

        if ($accountModel->repositories()->exists()) {
            throw new DisplayException('This GitHub account is in use by one or more servers. Disconnect those servers first.');
        }

        DB::transaction(function () use ($accountModel) {
            $accountModel->delete();
        });

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * Lists repositories for the given account (optionally filtering by name).
     */
    public function repositories(GithubRepositoriesRequest $request): array
    {
        if (!config('pterodactyl.git.enabled', true)) {
            throw new DisplayException('The GitHub integration is currently disabled.');
        }

        $account = $request->user()->githubAccounts()->findOrFail($request->input('account_id'));
        $query = (string) $request->input('q', '');

        return $this->github->repositories($account->accessToken(), $query);
    }
}