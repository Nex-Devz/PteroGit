<?php

namespace Pterodactyl\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Models\GithubAccount;
use Pterodactyl\Services\Git\GitHubOAuthService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class GithubOAuthController extends AbstractLoginController
{
    public function __construct(
        private readonly GitHubOAuthService $oauth,
    ) {
    }

    /**
     * Starts the OAuth flow by redirecting the browser to GitHub.
     */
    public function begin(Request $request): RedirectResponse
    {
        if (!$this->oauth->isConfigured()) {
            throw new NotFoundHttpException('GitHub OAuth is not configured.');
        }

        return redirect()->away($this->oauth->buildAuthorizeUrl($request));
    }

    /**
     * Handles the GitHub redirect back to the panel and links the account
     * to the logged in panel user.
     */
    public function callback(Request $request): RedirectResponse
    {
        if (!$this->oauth->isConfigured()) {
            throw new NotFoundHttpException('GitHub OAuth is not configured.');
        }

        if ($error = $this->oauth->parseCallbackError($request->query())) {
            return redirect('/account/github')->withQuery(['oauth' => 'error', 'message' => $error]);
        }

        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');

        if ($code === '' || $state === '') {
            return redirect('/account/github')->withQuery(['oauth' => 'error', 'message' => 'Missing code or state from GitHub.']);
        }

        try {
            $result = $this->oauth->exchange($request, $code, $state);
        } catch (Throwable $exception) {
            return redirect('/account/github')->withQuery(['oauth' => 'error', 'message' => $exception->getMessage()]);
        }

        $profile = $result['user'];
        $githubUserId = (string) ($profile['id'] ?? '');
        $username = (string) ($profile['login'] ?? '');

        if ($githubUserId === '' || $username === '') {
            return redirect('/account/github')->withQuery(['oauth' => 'error', 'message' => 'GitHub did not return a valid user profile.']);
        }

        $userId = (int) $request->session()->pull(GitHubOAuthService::SESSION_USER, $request->user()->id);

        $account = GithubAccount::query()
            ->where('user_id', $userId)
            ->where('provider', 'github')
            ->where('github_user_id', $githubUserId)
            ->first();

        if ($account) {
            $account->update([
                'username' => $username,
                'avatar_url' => $profile['avatar_url'] ?? null,
                'access_token_encrypted' => Crypt::encryptString($result['token']),
                'refresh_token_encrypted' => null,
                'token_expires_at' => null,
            ]);
        } else {
            if (count(GithubAccount::where('user_id', $userId)->get()) >= GithubAccount::MAX_ACCOUNTS_PER_USER) {
                return redirect('/account/github')->withQuery(['oauth' => 'error', 'message' => 'You have reached the GitHub connection limit.']);
            }

            GithubAccount::create([
                'user_id' => $userId,
                'provider' => 'github',
                'github_user_id' => $githubUserId,
                'username' => $username,
                'avatar_url' => $profile['avatar_url'] ?? null,
                'access_token_encrypted' => Crypt::encryptString($result['token']),
            ]);
        }

        return redirect('/account/github')->withQuery(['oauth' => 'connected']);
    }
}