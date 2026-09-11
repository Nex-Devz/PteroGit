<?php

namespace Pterodactyl\Services\Git;

use RuntimeException;

/**
 * Path normalization shared by every git transport.
 *
 * All git operations address directories RELATIVE to the node's volume root
 * (the folder that contains <server-uuid>/). Relative descriptors make the
 * panel completely independent of the node's physical data location, so a
 * stale nodes.daemonBase can never send git to the wrong directory again.
 */
class GitPaths
{
    /**
     * Normalizes and validates a path relative to the node volume root.
     *
     * Rejects absolute paths and traversal segments. The empty string is a
     * valid descriptor and means "the volume root itself".
     *
     * @throws RuntimeException
     */
    public static function normalize(string $rel): string
    {
        $clean = str_replace('\\', '/', $rel);

        if (str_contains($clean, "\0")) {
            throw new RuntimeException('The requested path is invalid.');
        }

        if ($clean === '') {
            return '';
        }

        if ($clean[0] === '/') {
            throw new RuntimeException('The requested path is invalid.');
        }

        $parts = explode('/', $clean);
        foreach ($parts as $part) {
            if ($part === '..') {
                throw new RuntimeException('The requested path is outside the server directory.');
            }
        }

        $normalized = implode('/', $parts);

        return (string) preg_replace('#/{2,}#', '/', rtrim($normalized, '/'));
    }
}