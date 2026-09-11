# PteroGit

GitHub/Git integration for the [Pterodactyl Panel](https://github.com/pterodactyl/panel) (v1.15.x).

PteroGit adds a full Git workflow to the Pterodactyl control panel: users link their GitHub accounts, connect repositories to their servers, and manage commits, branches, history and `.gitignore` directly from a dedicated server tab. A single-command installer applies the feature on top of an existing panel — stock or custom theme — without replacing or overwriting panel code.

The installer only adds new files and applies targeted patches. Every modified file is backed up before being changed, and all patch operations are idempotent, so the installer can be re-run safely.

---

## Features

- **Account linking** – Users connect multiple GitHub accounts per panel user via a Personal Access Token (`repo` scope) or optional OAuth2 sign-in.
- **Per-server repositories** – Connect, switch and disconnect GitHub repositories from linked accounts per server.
- **Git workflow UI** – Stage, unstage, discard, commit, push and pull from the panel interface.
- **Branch management** – Create, switch and delete branches.
- **Commit history and revert** – Browse commit history and revert commits.
- **`.gitignore` editor** – Read and write `.gitignore` per repository.
- **Git identity** – Per-server commit name and email configuration.
- **Admin settings page** – Enable/disable the feature and configure GitHub OAuth from the panel.
- **Admin audit log** – Review every Git operation across all servers (server, user, result and error details).
- **Security** – All git commands execute as a dedicated system user (`pterodactyl`) through a scoped sudoers rule; never as `root` or the web user.

---

## Requirements

| Requirement | Version |
|---|---|
| Pterodactyl Panel | 1.15.x |
| PHP CLI (on the panel host) | `^8.2` |
| MySQL / MariaDB | any modern release |
| Node.js + Yarn 1.x | Node `18+`, Yarn `1` (required only to rebuild the frontend) |

Redis is recommended for the panel cache and queue but not required.

---

## Multi-node support (Wings git channel)

By default PteroGit executes git on the panel host (`sudo -u pterodactyl`), which only works when the panel is co-located with Wings. On any multi-node panel the server's volume lives on the **server's Wings node**, not the panel host.

To make git run on the server's own node, PteroGit ships a tiny **git channel** for the Wings daemon (a standalone Go module in [`wings/`](wings/README.md) that also integrates into a Wings fork). Once a node exposes the channel:

- the panel **auto-detects it** (health probe, cached per node) and routes every git operation to that node over HTTPS, authenticated with a short-lived HS256 JWT signed with the node daemon token;
- the **real volume path is resolved by the node** — a stale `nodes.daemonBase` can never point git at an empty directory again;
- credentials travel only in the request body (injected via `GIT_ASKPASS`), so tokens never appear in process args or logs on the node.

Transport selection: `auto` (default), `local` or `wings`. Configure via `config/pterodactyl.php`:

```php
'git' => [
    'transport' => env('PTERODACTYL_GIT_TRANSPORT', 'auto'),
    'data_directory' => env('PTERODACTYL_GIT_DATA_DIRECTORY', '/var/lib/pterodactyl'),
    'probe_ttl' => 300,
],
```

Nodes that still run stock Wings (no git channel) automatically fall back to local execution, so single-node/co-located installs keep working untouched.

---

## Installation

Run as `root` (or with `sudo`). The installer is self-contained — it needs no clone; when
piped through the one-liner it fetches the source bundle and detects your panel automatically:

```bash
bash <(curl -sSL https://raw.githubusercontent.com/Nex-Devz/PteroGit/main/install.sh)
```

Pin a specific release instead of `main`:

```bash
PTEROGIT_VERSION=v1.3.0 bash <(curl -sSL https://raw.githubusercontent.com/Nex-Devz/PteroGit/main/install.sh)
```

Update an existing install or repair a panel: re-run the same command — everything is idempotent.

### Options

| Flag | Description |
|---|---|
| `-p, --panel PATH` | Panel root (default: auto-detect, preferring `/var/www/pterodactyl`). Positional path also works. |
| `--force` | Bypass the panel `1.15.x` version check. |
| `--yes-sudoers` / `--no-sudoers` | Install / skip the scoped sudoers rule without prompting. |
| `--skip-build` | Skip the frontend rebuild (run `yarn run build:production` later). |
| `--dry-run` | Validate everything, print the plan, change nothing. |
| `--rollback [BACKUP_DIR]` | Restore files from the last (or given) timestamped backup. |
| `-q, --quiet` | Minimal output (full log still written to `/var/log/pterogit-install-*.log`). |
| `-a, --allow-non-root` | Continue without root (sudoers step is skipped). |
| `-h, --help` / `-v, --version` | Help / installer version. |

Environment variables: `PTEROGIT_VERSION`, `GIT_FEATURE_SUDOERS=yes|no|ask`,
`GIT_FEATURE_SKIP_BUILD=1`, `PTEROGIT_LOG=/path/log`, `NO_COLOR=1`.

```bash
# examples
bash install.sh /opt/pterodactyl --yes-sudoers
bash install.sh --dry-run                     # preview before touching anything
bash install.sh --rollback                    # undo the previous install
```

### What the installer does

1. Preflight: detects the panel, validates the panel version (`1.15.x`), checks PHP/disk space and acquires a lock so concurrent runs are impossible.
2. Creates a timestamped backup of every file it touches in `storage/github-integration-backup-<timestamp>/` (restorable at any time with `--rollback`).
3. Copies the new feature files from the source bundle (`src/`) into the panel tree.
4. Patches routes, configuration, view composer, routers and models (idempotent), then **re-runs the patcher to prove the panel is only patched once**.
5. Appends the required `.env` variables when absent.
6. Runs `composer dump-autoload` and `php artisan migrate --force`.
7. Rebuilds the frontend (`yarn install && yarn run build:production`) unless skipped.
8. Rebuilds Laravel caches, restarts the queue and fixes file ownership (only when it differs).
9. Optionally creates the `pterodactyl` system user and installs the scoped sudoers rule.
10. Writes a full log to `/var/log/pterogit-install-*.log` with every step.

### Database tables

| Table | Purpose |
|---|---|
| `github_accounts` | Linked GitHub accounts per user |
| `server_git_repositories` | GitHub repository connection per server |
| `git_operations` | Operation audit log (connect/commit/push/pull/…) |

The schema is created by the migration shipped in `src/database/migrations`.

---

## Post-install configuration

### 1. Panel access to server directories

Git commands run against each server's directory under `PTERODACTYL_GIT_DATA_DIRECTORY` (default: `/var/lib/pterodactyl`, the Wings data folder). Each server directory must be owned by the `pterodactyl` user:

```bash
chown -R pterodactyl:pterodactyl /var/lib/pterodactyl/<server-uuid>
```

For a custom data directory, set it in `.env` and refresh the cache:

```bash
echo "PTERODACTYL_GIT_DATA_DIRECTORY=/your/path" >> .env
php artisan config:cache
```

### 2. Sub-user permissions

The installer registers the `git.*` permission group automatically. Grant it to sub-users in
**Server → Users** (see [docs/permissions.md](docs/permissions.md)). Root admins always see the
GitHub tab.

### 3. GitHub OAuth (optional)

1. Create an OAuth App (GitHub → Settings → Developer settings → OAuth Apps). Callback URL:
   ```
   https://<your-panel>/account/github/oauth/callback
   ```
2. Add to `.env`:
   ```
   GITHUB_OAUTH_ENABLED=true
   GITHUB_OAUTH_CLIENT_ID=your_client_id
   GITHUB_OAUTH_CLIENT_SECRET=your_client_secret
   ```
3. Run `php artisan config:cache`.

Users can always connect with a Personal Access Token (`repo` scope), even without OAuth.

---

## Repository layout

```
PteroGit/
├── install.sh            # one-command installer
├── CHANGELOG.md          # release history
├── patcher/
│   └── apply.php         # idempotent source patcher (routes/config/models/routers)
├── docs/
│   └── permissions.md    # sub-user permission guide
├── tests/
│   └── fixtures/         # stock panel 1.15.1 files used by CI (refresh.sh)
└── src/                  # new feature files, copied into the panel
    ├── app/
    │   ├── Http/Controllers/...        # account/server/admin controllers and OAuth
    │   ├── Http/Requests/...           # validated feature requests
    │   ├── Models/...                  # GithubAccount, ServerGitRepository, GitOperation
    │   └── Services/Git/...            # GitService (sudo runner), GitHubService, OAuth
    ├── database/migrations/            # creates the three tables
    ├── resources/scripts/api/          # frontend API bindings
    ├── resources/scripts/components/   # GitContainer and GithubContainer UIs
    └── resources/views/admin/settings/ # admin settings blade template
```

---

## Uninstalling

1. Restore the files changed by the installer from the backup folder.
2. Remove the feature files (services, models, controllers, requests, frontend components and the admin blade).
3. Drop the tables: `php artisan migrate:rollback --step=1`.
4. Rebuild the frontend: `cd /var/www/pterodactyl && yarn run build:production`.
5. Remove the `sudoers.d` rule and the `pterodactyl` user if no longer needed.

---

## Troubleshooting

| Symptom | Resolution |
|---|---|
| GitHub tab missing for sub-users | Add the `git.*` permission group to the egg. |
| "No repository is connected" | Link a GitHub account, then connect a repository on the server's GitHub tab. |
| `Permission denied` when running git | Verify the server directory is owned by `pterodactyl` and the sudoers rule is installed. |
| `/git?history` page errors | Confirm the frontend was rebuilt and check FPM/PHP logs under `storage/logs/`. |
| OAuth redirect fails | Verify `GITHUB_OAUTH_*` variables and that the callback host matches `APP_URL`. |

---

## Development

Contributions are welcome. Review the [Contributing Guide](CONTRIBUTING.md) and
[Code of Conduct](CODE_OF_CONDUCT.md) first, then read [start.md](start.md) — the
authoritative reference for repository conventions, how the source tree maps to the panel,
and the release/push checklist.

To report a bug or request a feature, use the [issue templates](.github/ISSUE_TEMPLATE/).
For security vulnerabilities follow [SECURITY.md](SECURITY.md). See [CHANGELOG.md](CHANGELOG.md)
for release history.

---

## License

[MIT](LICENSE)