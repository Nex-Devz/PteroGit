<?php

namespace Pterodactyl\Services\Git;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Implements the GitHub OAuth2 "authorization code with PKCE" flow so users can
 * link a GitHub account to the panel through the browser instead of pasting a
 * personal access token.
 *
 * Requires a GitHub OAuth App configured in config('pterodactyl.git.oauth').
 */
class GitHubOAuthService
{
    public const SESSION_STATE = 'github_oauth_state';
    public const SESSION_VERIFIER = 'github_oauth_verifier';
    public const SESSION_USER = 'github_oauth_user_id';

    private const AUTHORIZE_URL = 'https://github.com/login/oauth/authorize';
    private const TOKEN_URL = 'https://github.com/login/oauth/access_token';

    /**
     * Returns whether the OAuth flow is available for use.
     */
    public function isConfigured(): bool
    {
        return (bool) config('pterodactyl.git.enabled', true)
            && (bool) config('pterodactyl.git.oauth.enabled')
            && (string) config('pterodactyl.git.oauth.client_id') !== ''
            && (string) config('pterodactyl.git.oauth.client_secret') !== '';
    }

    /**
     * Generates the GitHub authorize URL for the current request, persisting the
     * PKCE state + verifier and the panel user the account should be linked to.
     */
    public function buildAuthorizeUrl(Request $request): string
    {
        $state = Str::random(32);
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $request->session()->put(self::SESSION_STATE, $state);
        $request->session()->put(self::SESSION_VERIFIER, $verifier);
        $request->session()->put(self::SESSION_USER, $request->user()->id);

        $query = http_build_query([
            'client_id' => config('pterodactyl.git.oauth.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'scope' => 'repo',
            'state' => $state,
            'allow_signup' => 'true',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        return self::AUTHORIZE_URL . '?' . $query;
    }

    /**
     * Exchanges the authorization code for an access token. Returns null when
     * the returned payload does not contain a token (e.g. access_denied).
     *
     * @return array{token: string, user: array<string, mixed>}
     */
    public function exchange(Request $request, string $code, string $state): array
    {
        if ($request->session()->pull(self::SESSION_STATE) !== $state) {
            throw new RuntimeException('The OAuth state does not match. Please try again.');
        }

        $verifier = (string) $request->session()->pull(self::SESSION_VERIFIER);

        $response = Http::asForm()->acceptJson()->post(self::TOKEN_URL, [
            'client_id' => config('pterodactyl.git.oauth.client_id'),
            'client_secret' => config('pterodactyl.git.oauth.client_secret'),
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'code_verifier' => $verifier,
        ]);

        $payload = $response->json();

        if (!$response->successful() || empty($payload['access_token'])) {
            throw new RuntimeException((string) ($payload['error_description'] ?? $payload['error'] ?? 'GitHub did not return an access token.'));
        }

        $userResponse = Http::withToken($payload['access_token'])->acceptJson()->get('https://api.github.com/user');

        if ($userResponse->failed()) {
            throw new RuntimeException('Unable to fetch the GitHub user profile.');
        }

        return [
            'token' => $payload['access_token'],
            'user' => $userResponse->json(),
        ];
    }

    public function redirectUri(): string
    {
        return (string) config('pterodactyl.git.oauth.redirect_uri');
    }

    /**
     * Checks the callback for an error and converts it into a human readable message.
     */
    public function parseCallbackError(array $query): ?string
    {
        if (!isset($query['error'])) {
            return null;
        }

        return match ($query['error']) {
            'access_denied' => 'You denied the GitHub authorization request.',
            default => (string) ($query['error_description'] ?? $query['error']),
        };
    }
}