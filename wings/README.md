# PteroGit Wings executor

Panel-only git integration needs git to run on the **server's own node**, not on
the panel host. Stock Wings exposes no way to run arbitrary commands, so
PteroGit ships a tiny **git channel** – this Go module – that runs inside Wings
or as a lightweight standalone daemon on each node.

Because Wings is a compiled Go daemon (no plugin system), this channel ships one
of two ways:

1. **Integrated into Wings** (recommended): drop `internal/auth`, `internal/git`
   and `internal/server` into the `pterodactyl/wings` codebase, register
   `server.NewHandler(...)` on the daemon's HTTP router and ensure a *git*
   nightly/package (`apt install git`) is present on the node. Nodes upgrade
   Wings as usual — no per-node scripts, keys or installs.
2. **Standalone daemon** (quick start): run the built binary on every node. This
   still needs the panel to reach it, so expose it on the node's Wings port.

## Configuration (standalone)

| Flag / env            | Purpose                                          |
| --------------------- | ------------------------------------------------ |
| `--addr`              | Listen address (default `:9100`)                 |
| `--daemon-token` / `PTEROGIT_DAEMON_TOKEN` | The node's daemon token (panel validates JWTs against it). |
| `--volume-root` / `PTEROGIT_VOLUME_ROOT` | The node's **real** volume directory (usually `<root_directory>/volumes`, e.g. `/var/lib/pterodactyl/volumes`). |

The panel resolves the volume path **from the node** through this channel — a
stale `nodes.daemonBase` in the panel DB can no longer point git at an empty
directory.

## HTTP API

### `POST /api/servers/:uuid/git` — run git on the node

Headers: `X-Access-Token: <JWT>` (HS256, signed with the node daemon token;
claims: `iat`, `exp`, `server_uuid`, `sub=pterogit`).

```jsonc
{
  "cwd": "e231e3b0-...",   // relative to the volume root
  "args": ["status", "--porcelain"],
  "token": "ghp_xxx"       // OPTIONAL -> injected via GIT_ASKPASS, never in argv/logs
}
```

Response `200`: `{ "exit": 0, "stdout": "...", "stderr": "..." }`

`cwd` and any `file.path` are **rejected** if they escape the volume root
(`..`, absolute paths).

### File channel (same URL, `file` field)

```jsonc
{
  "file": {
    "op": "stat | read | write | mkdir | chown",
    "path": "e231e3b0-.../.gitignore",   // relative to volume root
    "content": "# optional for write"
  }
}
```

Response `200`: `{ "ok": true, "data": { "exists": true, "content": "..." } }`

Used for `.gitignore` edits and worktree `.git` existence checks.

### `GET /api/servers/:uuid/git/health` — channel probe

Panel's `WingsNodeTransport` calls this (cached per node) to decide whether to
route git through Wings or fall back to local execution. Returns `200
{"ok":true}` when the volume directory exists, `404` otherwise.

## Security notes

- The token travels only in the JSON body. The executor writes a temporary
  `GIT_ASKPASS` script (0700), sets `GIT_TERMINAL_PROMPT=0` and removes it
  afterwards; stdout/stderr are redacted before they are returned.
- `credential.helper` is neutralised for every call, so stored credentials can
  never interfere.
- Git runs as the **volume directory's owner** (the container user), never root.
- The executor's path resolution enforces that every operation stays inside the
  volume root — the panel trusts the executor's boundary, not vice versa.

## Build

```sh
cd wings
go build ./...            # validate
GOOS=linux GOARCH=amd64 go build -o pterogit-wings ./cmd/pterogit-wings
```

## Tests

```sh
GOOS=linux go test ./...
```