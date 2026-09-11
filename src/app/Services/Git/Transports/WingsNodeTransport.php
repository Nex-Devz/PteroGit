<?php

namespace Pterodactyl\Services\Git\Transports;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Git\GitPaths;
use RuntimeException;

/**
 * Runs git on the server's own Wings node over the daemon HTTP API.
 *
 * The panel never needs the node's physical path: every operation is sent as
 * a volume-root RELATIVE descriptor and Wings (which genuinely owns the data)
 * resolves the real directory. Authentication is a short-lived HS256 JWT
 * signed with the node's daemon token — the same secret Wings trusts — and
 * credentials are only ever carried in the request body for GIT_ASKPASS, so
 * tokens never appear in shell arguments or logs on the node.
 */
class WingsNodeTransport implements GitNodeTransportContract
{
    public function id(): string
    {
        return 'wings';
    }

    public function __construct(private readonly ?Client $client = null)
    {
    }

    private function http(): Client
    {
        return $this->client ??= new Client([
            'timeout' => (float) config('pterodactyl.guzzle.timeout', 120),
            'connect_timeout' => (float) config('pterodactyl.guzzle.connect_timeout', 5),
            'verify' => false,
        ]);
    }

    private function baseUrl(Server $server): string
    {
        $node = $server->node;
        if (!$node) {
            throw new RuntimeException('The server has no node assigned.');
        }

        $scheme = in_array($node->scheme, ['https', 'http'], true) ? $node->scheme : 'https';
        $host = is_string($node->fqdn) && $node->fqdn !== '' ? $node->fqdn : null;
        if ($host === null) {
            throw new RuntimeException('The server\'s node has no hostname configured.');
        }

        $port = $node->daemonListen ?? $node->getAttribute('daemon_listen') ?? 8080;

        return $scheme . '://' . $host . ':' . $port;
    }

    /**
     * Pulls the (decrypted) daemon token from a node so a JWT can be signed
     * with the same secret the node uses to validate requests.
     */
    private function nodeToken(Node $node): string
    {
        $accessor = trim((string) ($node->daemonToken ?? ''));
        if ($accessor !== '') {
            return $accessor;
        }

        $raw = $node->getRawOriginal('daemon_token');
        if (!is_string($raw) || $raw === '') {
            $raw = (string) ($node->daemon_token ?? '');
        }

        if ($raw === '') {
            return '';
        }

        try {
            $decrypted = Crypt::decrypt($raw);

            return is_string($decrypted) ? $decrypted : $raw;
        } catch (\Throwable $e) {
            return $raw;
        }
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Builds a short-lived HS256 JWT for one server, signed with the node's
     * daemon token. The same scheme is implemented on the Wings side.
     */
    public function tokenFor(Server $server): string
    {
        $secret = $this->nodeToken($server->node);
        if ($secret === '') {
            throw new RuntimeException('The server\'s node has no daemon token configured; Wings integration cannot be authenticated.');
        }

        $now = time();
        $header = $this->base64Url((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->base64Url((string) json_encode([
            'iat' => $now,
            'exp' => $now + 120,
            'server_uuid' => $server->uuid,
            'sub' => 'pterogit',
        ]));

        $unsigned = $header . '.' . $payload;
        $signature = $this->base64Url(hash_hmac('sha256', $unsigned, $secret, true));

        return $unsigned . '.' . $signature;
    }

    /**
     * Executes one git channel request on the node.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(Server $server, array $payload): array
    {
        try {
            $response = $this->http()->post($this->baseUrl($server) . '/api/servers/' . $server->uuid . '/git', [
                'headers' => [
                    'X-Access-Token' => $this->tokenFor($server),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $decoded = json_decode((string) $response->getBody(), true);
            if (!is_array($decoded)) {
                throw new RuntimeException('The node returned an unreadable git response.');
            }

            return $decoded;
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $token = is_string($payload['token'] ?? null) ? $payload['token'] : null;
            $message = (string) $exception->getMessage();

            throw new RuntimeException(
                ($token !== null && $token !== '' ? str_replace($token, '[REDACTED]', $message) : $message),
                (int) ($exception->getCode() ?: 0)
            );
        }
    }

    /**
     * Probes whether the node actually exposes the PteroGit git channel.
     * A non-existent channel (404) or network error means "not deployed yet";
     * authentication failures are logged but treated as unavailable so the
     * panel can fall back to a co-located local execution.
     */
    public function isReachable(Server $server): bool
    {
        try {
            $response = $this->http()->get($this->baseUrl($server) . '/api/servers/' . $server->uuid . '/git/health', [
                'headers' => [
                    'X-Access-Token' => $this->tokenFor($server),
                    'Accept' => 'application/json',
                ],
                'timeout' => 6,
                'connect_timeout' => 3,
            ]);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                return true;
            }

            logger()->warning('PteroGit Wings git channel returned ' . $response->getStatusCode() . ' for server ' . $server->uuid);

            return false;
        } catch (\Throwable $exception) {
            logger()->warning('PteroGit Wings git channel unreachable: ' . $exception->getMessage());

            return false;
        }
    }

    /**
     * @param array<int, string> $args
     */
    public function git(Server $server, string $cwdRel, array $args, bool $trimOutput = true, ?string $askpassToken = null): ExecResult
    {
        $payload = [
            'cwd' => GitPaths::normalize($cwdRel),
            'args' => array_values($args),
        ];
        if ($askpassToken !== null && $askpassToken !== '') {
            $payload['token'] = $askpassToken;
        }

        $response = $this->post($server, $payload);

        $stdout = is_string($response['stdout'] ?? null) ? $response['stdout'] : '';
        $stderr = is_string($response['stderr'] ?? null) ? $response['stderr'] : '';

        if ($askpassToken !== null && $askpassToken !== '') {
            $stdout = str_replace($askpassToken, '[REDACTED]', $stdout);
            $stderr = str_replace($askpassToken, '[REDACTED]', $stderr);
        }

        return new ExecResult((int) ($response['exit'] ?? 1), $trimOutput ? trim($stdout) : $stdout, trim($stderr));
    }

    public function dirExists(Server $server, string $cwdRel): bool
    {
        $data = $this->stat($server, GitPaths::normalize($cwdRel));

        return (bool) ($data['exists'] ?? false);
    }

    public function fileExists(Server $server, string $cwdRel, string $basename): bool
    {
        $path = GitPaths::normalize($cwdRel) . '/' . GitPaths::normalize($basename);

        $data = $this->stat($server, $path);

        return (bool) ($data['exists'] ?? false);
    }

    public function readFile(Server $server, string $cwdRel, string $basename): ?string
    {
        $path = GitPaths::normalize($cwdRel) . '/' . GitPaths::normalize($basename);
        $data = $this->fileOp($server, 'read', $path);

        return is_string($data['content'] ?? null) ? $data['content'] : null;
    }

    public function writeFile(Server $server, string $cwdRel, string $basename, string $content): void
    {
        $path = GitPaths::normalize($cwdRel) . '/' . GitPaths::normalize($basename);
        $this->fileOp($server, 'write', $path, ['content' => $content]);
    }

    public function mkdir(Server $server, string $cwdRel): void
    {
        $this->fileOp($server, 'mkdir', GitPaths::normalize($cwdRel));
    }

    public function chownFile(Server $server, string $cwdRel, string $basename): void
    {
        $path = GitPaths::normalize($cwdRel) . '/' . GitPaths::normalize($basename);
        $this->fileOp($server, 'chown', $path);
    }

    /**
     * @param array<string, mixed> $extras
     *
     * @return array<string, mixed>
     */
    private function fileOp(Server $server, string $op, string $path, array $extras = []): array
    {
        $response = $this->post($server, ['file' => array_merge(['op' => $op, 'path' => $path], $extras)]);

        if (!($response['ok'] ?? false)) {
            $error = is_string($response['error'] ?? null) ? $response['error'] : 'File operation failed on the node.';

            throw new RuntimeException($error);
        }

        return is_array($response['data'] ?? null) ? $response['data'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function stat(Server $server, string $path): array
    {
        try {
            return $this->fileOp($server, 'stat', $path);
        } catch (RuntimeException $exception) {
            return ['exists' => false];
        }
    }
}