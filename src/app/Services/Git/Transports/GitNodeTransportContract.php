<?php

namespace Pterodactyl\Services\Git\Transports;

use Pterodactyl\Models\Server;

/**
 * Executes git operations against a Pterodactyl server's volume.
 *
 * Every path is expressed RELATIVE to the node's volume root. Each transport
 * maps those descriptors onto its own filesystem view:
 *
 *   - LocalGitTransport resolves them against the panel host's data root;
 *   - WingsNodeTransport forwards them to the server's node, which resolves
 *     them against the REAL volume root it owns (never nodes.daemonBase).
 *
 * This keeps the panel independent of where a node actually stores volumes,
 * and lets the same logical operation run on co-located and remote nodes alike.
 */
interface GitNodeTransportContract
{
    public function id(): string;

    /**
     * Runs git with the working directory pinned to $cwdRel (volume-root relative).
     *
     * @param array<int, string> $args git arguments (everything after the -C switch)
     */
    public function git(Server $server, string $cwdRel, array $args, bool $trimOutput = true, ?string $askpassToken = null): ExecResult;

    /**
     * Returns true when the directory exists on the node.
     */
    public function dirExists(Server $server, string $cwdRel): bool;

    /**
     * Returns true when the entry named $basename exists inside $cwdRel.
     */
    public function fileExists(Server $server, string $cwdRel, string $basename): bool;

    /**
     * Reads a single file inside $cwdRel; null when it does not exist.
     */
    public function readFile(Server $server, string $cwdRel, string $basename): ?string;

    /**
     * Creates (or overwrites) a single file inside $cwdRel as the container user.
     */
    public function writeFile(Server $server, string $cwdRel, string $basename, string $content): void;

    /**
     * Recursively creates $cwdRel, owned by the container user when possible.
     */
    public function mkdir(Server $server, string $cwdRel): void;

    /**
     * Re-owns a single file inside $cwdRel to the container user.
     */
    public function chownFile(Server $server, string $cwdRel, string $basename): void;
}