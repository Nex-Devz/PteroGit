<?php

namespace Pterodactyl\Services\Git\Transports;

use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\Server;
use RuntimeException;

/**
 * Chooses which transport executes git for a given server.
 *
 *   auto  (default) — probe the server's node once (cached); when the node
 *                     exposes the PteroGit Wings git channel use it, otherwise
 *                     fall back to local execution (co-located installs).
 *   local            — always run git on the panel host.
 *   wings            — always run git on the server's node; errors about the
 *                     channel being unreachable.
 *
 * `config('pterodactyl.git.transport')` may be set to any of these.
 */
class GitTransportManager
{
    public function __construct(
        private readonly LocalGitTransport $local,
        private readonly WingsNodeTransport $wings,
    ) {
    }

    public function local(): LocalGitTransport
    {
        return $this->local;
    }

    public function wings(): WingsNodeTransport
    {
        return $this->wings;
    }

    public function for(Server $server): GitNodeTransportContract
    {
        $mode = (string) config('pterodactyl.git.transport', 'auto');

        if ($mode === 'local') {
            return $this->local;
        }

        if ($mode === 'wings') {
            if ($this->wingsReachable($server)) {
                return $this->wings;
            }

            throw new RuntimeException(
                'The Wings git channel is not reachable on this server\'s node, but git transport is configured to require Wings.'
                . ' Deploy the PteroGit Wings extension on the node (or set PTERODACTYL_GIT_TRANSPORT=auto to fall back to local execution).'
            );
        }

        return $this->wingsReachable($server) ? $this->wings : $this->local;
    }

    private function wingsReachable(Server $server): bool
    {
        $nodeId = $server->node_id;
        $key = 'git:transport:wings:' . $nodeId;

        if (is_bool($cached = Cache::get($key))) {
            return $cached;
        }

        $reachable = $this->wings->isReachable($server);
        Cache::put($key, $reachable, (int) config('pterodactyl.git.probe_ttl', 300));

        return $reachable;
    }
}