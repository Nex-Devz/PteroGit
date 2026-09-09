# PteroGit

GitHub / Git integration for the [Pterodactyl Panel](https://github.com/pterodactyl/panel) (v1.15.x).
One-command, **theme-agnostic** installer — it works on stock and on any custom panel theme, gives every server a full `GitHub` tab, and adds a GitHub settings page for admins.

> The installer only **adds** the feature on top of your existing panel — it never overwrites stock files you didn't need changed, and every file it touches is backed up before being modified.

---

## Features

- 👤 **Link GitHub accounts** — Personal Access Token (PAT, `repo` scope) or optional OAuth2 sign-in, multiple accounts per user.
- 📦 **Per-server repositories** — connect / switch / disconnect any GitHub repo from your linked accounts.
- 🔁 **Full Git workflow UI** — stage, unstage, discard, commit, push, pull directly from the panel.
- 🌿 **Branch management** — create, switch and delete branches.
- 🕘 **Commit history & revert** — browse history and revert commits in one click.
- 📄 **`.gitignore` editor** — read/write `.gitignore` per repo.
- 🧑‍🔧 **Git identity** — per-server commit name/email management.
- ⚙️ **Admin settings page** — toggle the feature and configure GitHub OAuth from the panel.
- 🔒 All git commands run as a dedicated system user (`pterodactyl`) with a scoped `sudoers` rule — never as `root` or the web user.

---

## Quick install (one command)

Run as `root` (or with `sudo`):

```bash
bash <(curl -sSL https://raw.githubusercontent.com/Nex-Devz/PteroGit/main/install.sh)
```

Custom panel path:

```bash
bash <(curl -sSL https://raw.githubusercontent.com/Nex-Devz/PteroGit/main/install.sh) /opt/pterodactyl
```

Skip the (slow) frontend rebuild if you will run it yourself later:

```bash
GIT_FEATURE_SKIP_BUILD=1 bash install.sh
```

### Requirements

| Requirement | Version |
|---|---|
| Pterodactyl Panel | `1.15.x` |
| PHP CLI (same host as panel) | `^8.2` |
| MySQL / MariaDB | any recent |
| Node.js + Yarn 1.x | Node `18+`, Yarn `1` (only needed to rebuild the frontend) |

Redis is recommended for the panel cache/queue but not required.

---

## What the installer does

1. **Backs up** every file it touches to `storage/github-integration-backup-<timestamp>/`.
2. **Copies** the new feature files (`src/`) into the panel tree.
3. **Patches** routes, config, view-composer, routers and models (idempotent — safe to re-run).
4. Adds the required **`.env` variables** (if missing).
5. Runs `composer dump-autoload` and `php artisan migrate --force` (creates the database tables).
6. Rebuilds the frontend (`yarn install && yarn run build:production`).
7. Rebuilds Laravel caches and restarts the queue, fixes file ownership.
8. **Optionally** creates the `pterodactyl` system user and installs a scoped `sudoers` rule.

### Database tables (created by the migration)

| Table | Purpose |
|---|---|
| `github_accounts` | Linked GitHub accounts per user |
| `server_git_repositories` | GitHub repo connection per server |
| `git_operations` | Operation audit log (connect/commit/push/pull/…) |

---

## Post-install configuration

### 1. Let the panel read server directories

The panel runs git commands against each server's directory under `PTERODACTYL_GIT_DATA_DIRECTORY` (default `/var/lib/pterodactyl`, the Wings data folder). Make sure each server folder is owned by the `pterodactyl` user:

```bash
chown -R pterodactyl:pterodactyl /var/lib/pterodactyl/<server-uuid>
```

If you use a different data directory, set it in `.env` and reload config:

```bash
echo "PTERODACTYL_GIT_DATA_DIRECTORY=/your/path" >> .env
php artisan config:cache
```

### 2. Permission group for eggs

The `/git` route and the tab require the `git.*` permission group. Root admins always see the tab; for **sub-users** you must add a `git` permission group to the relevant eggs in the panel (Admin → Nests → Egg → Permissions).

### 3. (Optional) GitHub OAuth sign-in

1. Create an **OAuth App** on GitHub (Settings → Developer settings → OAuth Apps). Callback URL:
   ```
   https://<your-panel>/account/github/oauth/callback
   ```
2. Add to `.env`:
   ```env
   GITHUB_OAUTH_ENABLED=true
   GITHUB_OAUTH_CLIENT_ID=your_client_id
   GITHUB_OAUTH_CLIENT_SECRET=your_client_secret
   ```
3. `php artisan config:cache`

Users can always connect with a Personal Access Token (`repo` scope) even without OAuth.

---

## Project layout

```
PteroGit/
├── install.sh            # one-command installer
├── patcher/
│   └── apply.php         # idempotent source patcher (routes/config/models)
└── src/                  # new feature files, copied into the panel
    ├── app/
    │   ├── Http/Controllers/...        # account/server/admin controllers + OAuth
    │   ├── Http/Requests/...           # validated feature requests
    │   ├── Models/...                  # GithubAccount, ServerGitRepository, GitOperation
    │   └── Services/Git/...            # GitService (sudo runner), GitHubService, OAuth
    ├── database/migrations/            # creates the 3 tables
    ├── resources/scripts/api/          # frontend API bindings
    ├── resources/scripts/components/   # GitContainer + GithubContainer UIs
    └── resources/views/admin/settings/ # admin settings blade
```

---

## Uninstalling

1. Restore the backup created by the installer:
   ```bash
   cp -a storage/github-integration-backup-<timestamp>/routes/api-client.php routes/api-client.php
   # …repeat for every file in your backup folder
   ```
2. Remove the feature files under `app/Services/Git`, `app/Models/{GithubAccount,ServerGitRepository,GitOperation}.php`, the controllers/requests, `resources/scripts/components/{server/git,dashboard/GithubContainer.tsx}`, `resources/scripts/api/{server/git.ts,account/github.ts}` and `resources/views/admin/settings/github.blade.php`.
3. Drop the tables:
   ```bash
   php artisan migrate:rollback --step=1
   ```
4. Rebuild the frontend: `cd /var/www/pterodactyl && yarn run build:production`.
5. Remove the `sudoers.d` rule and the `pterodactyl` user if you no longer need them.

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| GitHub tab missing for sub-users | Add the `git.*` permission group to the egg (see above) |
| "No repository is connected" | Link a GitHub account, then connect a repo on the server's GitHub tab |
| `Permission denied` running git | Ensure the server folder is owned by the `pterodactyl` user and the `sudoers.d` rule exists |
| Page/`/git?history` shows an error | Ensure the frontend was rebuilt and `journalctl -u php*-fpm` shows no PHP fatal; check `storage/logs/laravel-*.log` |
| OAuth redirect fails | Confirm `GITHUB_OAUTH_*` vars and the callback URL (must use the same host as `APP_URL`) |

---

## License

MIT — see [LICENSE](LICENSE).