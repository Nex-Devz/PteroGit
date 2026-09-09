<?php

namespace Pterodactyl\Services\Git;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for the GitHub REST API used to validate credentials and load
 * repository metadata. Tokens are only ever exchanged server-side.
 */
class GitHubService
{
    private const API_BASE = 'https://api.github.com';

    /**
     * Validates a token and returns the associated account information.
     *
     * @return array{id: int, login: string, avatar_url: string}
     *
     * @throws RuntimeException
     */
    public function whoami(string $token): array
    {
        try {
            $response = Http::withToken($token)
                ->accept('application/vnd.github+json')
                ->timeout(20)
                ->get(self::API_BASE . '/user');
        } catch (ConnectionException $e) {
            throw new RuntimeException('Unable to reach the GitHub API. Please try again.');
        }

        if ($response->status() === 401) {
            throw new RuntimeException('The provided GitHub token is invalid or was revoked.');
        }

        if ($response->failed()) {
            throw new RuntimeException('GitHub returned an unexpected error (' . $response->status() . ').');
        }

        $data = $response->json();

        return [
            'id' => (int) ($data['id'] ?? 0),
            'login' => (string) ($data['login'] ?? ''),
            'avatar_url' => (string) ($data['avatar_url'] ?? ''),
        ];
    }

    /**
     * Lists repositories the authenticated account can access.
     *
     * @return array<int, array{id: int, full_name: string, default_branch: string, private: bool, clone_url: string}>
     *
     * @throws RuntimeException
     */
    public function repositories(string $token, ?string $query = '', ?int $perPage = 50): array
    {
        $query = (string) ($query ?? '');
        $params = ['per_page' => $perPage, 'sort' => 'updated'];

        try {
            $response = Http::withToken($token)
                ->accept('application/vnd.github+json')
                ->timeout(20)
                ->get(self::API_BASE . ($query === '' ? '/user/repos' : '/search/repositories'), $query === '' ? $params : ['q' => $query]);

            if ($query !== '') {
                $response = $response->json();

                return array_map(fn (array $repo) => $this->mapRepository($repo), $response['items'] ?? []);
            }
        } catch (ConnectionException $e) {
            throw new RuntimeException('Unable to reach the GitHub API. Please try again.');
        }

        if ($response->failed()) {
            throw new RuntimeException('GitHub returned an unexpected error (' . $response->status() . ').');
        }

        return array_map(fn (array $repo) => $this->mapRepository($repo), $response->json());
    }

    /**
     * @param array<string, mixed> $repo
     *
     * @return array{id: int, full_name: string, default_branch: string, private: bool, clone_url: string}
     */
    private function mapRepository(array $repo): array
    {
        return [
            'id' => (int) ($repo['id'] ?? 0),
            'full_name' => (string) ($repo['full_name'] ?? ''),
            'default_branch' => (string) ($repo['default_branch'] ?? 'main'),
            'private' => (bool) ($repo['private'] ?? false),
            'clone_url' => (string) ($repo['clone_url'] ?? ''),
        ];
    }
}