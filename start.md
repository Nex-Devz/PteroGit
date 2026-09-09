# PteroGit – Development & Release Guide

This document is the single reference for anyone (human or AI agent) working on this repository.
It explains how the code is organised, how it maps onto a Pterodactyl panel install, what may and
may not be changed, and how to ship a new release. Follow it exactly so the installer keeps working.

---

## 1. Project context

| Item | Value |
|---|---|
| Product | PteroGit – GitHub/Git integration for Pterodactyl Panel |
| Supported panel | Pterodactyl Panel `1.15.x` (tested on `1.15.1`) |
| Install target | `/var/www/pterodactyl` (default) |
| Panel stack | PHP `^8.2` (FPM), MySQL/MariaDB, Laravel 11, React/Inertia frontend (TypeScript) |
| Git runner | Commands run as system user `pterodactyl` via `sudoers`, scoped to `git/tee/chown` |
| Feature flag | `PTERODACTYL_GIT_ENABLED` (default `true`), exposed to the UI as `siteConfiguration.git.enabled` |
| Data directory | `PTERODACTYL_GIT_DATA_DIRECTORY` (default `/var/lib/pterodactyl`) |

A working reference installation lives on a live panel. A stock panel tree is simulated in
`/tmp/stockpanel` (rebuilt by stripping the feature from the live panel) and used to validate that
the patcher anchors still match.

---

## 2. Two file categories

Every file in this project falls into exactly one category. Never mix them up.

### 2.1 NEW files (added to the panel)

Located under `src/`. These are copied verbatim into the panel tree by `install.sh`
(`cp` into the matching relative path). They are never modified after copy.

```
src/<relative path in panel>/file
```

Examples: `src/app/Services/Git/GitService.php` → `/var/www/pterodactyl/app/Services/Git/GitService.php`.

Full new-file inventory (36 files):

| Area | File |
|---|---|
| Admin controller | `src/app/Http/Controllers/Admin/Settings/GithubController.php` |
| Client account controller | `src/app/Http/Controllers/Api/Client/Account/GithubAccountController.php` |
| Client server controller | `src/app/Http/Controllers/Api/Client/Servers/GithubController.php` |
| OAuth controller | `src/app/Http/Controllers/Auth/GithubOAuthController.php` |
| Admin request | `src/app/Http/Requests/Admin/Settings/GithubSettingsFormRequest.php` |
| Account requests | `src/app/Http/Requests/Api/Client/Account/{ConnectGithubRequest,GithubRepositoriesRequest}.php` |
| Server requests | `src/app/Http/Requests/Api/Client/Servers/Github/*.php` (16 files) |
| Models | `src/app/Models/{GithubAccount,ServerGitRepository,GitOperation}.php` |
| Services | `src/app/Services/Git/{GitService,RepositoryService,GitHubService,GitHubOAuthService}.php` |
| Migration | `src/database/migrations/2026_09_08_000001_create_github_integration_tables.php` |
| Frontend API | `src/resources/scripts/api/account/github.ts`, `src/resources/scripts/api/server/git.ts` |
| Frontend UI | `src/resources/scripts/components/server/git/GitContainer.tsx`, `src/resources/scripts/components/dashboard/GithubContainer.tsx` |
| Admin view | `src/resources/views/admin/settings/github.blade.php` |

### 2.2 PATCHED files (existing panel files, modified in place)

Handled ONLY by `patcher/apply.php`. The patcher finds a unique **anchor string** in the stock
stock file and inserts/replaces a block. Every operation has a **marker substring** used to detect
"already applied", which makes the patcher idempotent.

Patched file inventory:

| Panel file | What the patcher adds |
|---|---|
| `routes/api-client.php` | Account-level github group (after the ssh-keys group) and server-level github group (before the `/users` group) |
| `routes/admin.php` | `GET /settings/github` + `PATCH /settings/github` route entries |
| `routes/base.php` | `GET /account/github/oauth/begin` + `/callback` (named `github.oauth.begin`/`github.oauth.callback`) |
| `config/pterodactyl.php` | `git` config block (data_directory, enabled, oauth) |
| `app/Http/ViewComposers/AssetComposer.php` | `git.enabled` inside `siteConfiguration` |
| `app/Models/User.php` | `githubAccounts(): HasMany` relation after `sshKeys()` |
| `resources/scripts/state/settings.ts` | `git: { enabled: boolean }` inside the `SiteSettings` interface |
| `resources/scripts/routers/routes.ts` | 2 imports, account `/github` route, server `/git` route (`feature: 'git'`, `permission: 'git.*'`) |
| `resources/scripts/routers/ServerRouter.tsx` | `gitEnabled` line + two `.filter((route) => !(route.feature === 'git' && !gitEnabled && !rootAdmin))` |
| `resources/scripts/routers/DashboardRouter.tsx` | `gitEnabled` line + one feature filter |
| `resources/views/partials/admin/settings/nav.blade.php` | GitHub tab `<li>` after the Advanced tab |

---

## 3. Updating code

### 3.1 Editing a NEW file

1. Edit the file under `src/`.
2. Keep the code style identical to the panel (PSR-12 PHP; standard TS/React conventions).
3. If the change is testable on the live panel, sync it there so the running install matches:
   ```bash
   cp src/<rel path> /var/www/pterodactyl/<rel path>
   ```
4. Backend model/controller changes may require `php artisan optimize:clear` before testing.
5. Frontend changes require a rebuild: `cd /var/www/pterodactyl && yarn install && yarn run build:production`.

### 3.2 Editing a PATCHED file operation

1. Open `patcher/apply.php` and locate the operation (`$ops` array) for the file.
2. If inserting different code, update the `insert` text AND the `check` marker.
3. If the anchor no longer exists in stock panel 1.15.x, the operation will report
   `anchor not found` and abort with exit code `1` — that is by design. Update the anchor.
4. After any change, validate with the stock simulation (section 5).

### 3.3 Adding a NEW patch operation

1. Add an entry to `$ops` in `patcher/apply.php` with `file`, `mode` (`after`|`before`|`replace`),
   `needle` (exact anchor), `insert`, and `check` (uniquely identifies the applied patch).
2. Keep the anchor small but unambiguous; prefer anchors a few lines long to avoid matching the
   wrong location.
3. Add the file to the `backup_path` list in `install.sh` so the installer backs it up.

### 3.4 When the panel upgrade is released

Pterodactyl may change stock files between minor versions. Before claiming support for a new minor:

1. Fetch the new stock files from the panel repository.
2. Verify every anchor in `patcher/apply.php` still matches.
3. Rebuild `/tmp/stockpanel` and run `php patcher/apply.php /tmp/stockpanel` until all ops say PATCHED.
4. Update the "Supported panel" line in README + this file.

---

## 4. Key implementation notes (do not regress)

- **History endpoint shape**: `GithubController::history()` returns
  `['commits' => array, 'total' => int, 'limit' => int, 'offset' => int]`. The frontend reads
  `data.commits` as an **array**. Wrapping commits in an object breaks the `/git?history` page.
- **`github_user_id` casting**: `GithubAccountController::store()` and the lookup
  `where('github_user_id', ...)` must cast to `(string)` — GitHub ids exceed PHP integer range.
- **Null safety in `RepositoryService::begin()`**: use `$this->linked($server)?->repository_full_name
  ?? null`; never assume a repository is connected.
- **Command execution**: `GitService` must run `git` via
  `sudo -n -u pterodactyl git -C <data>/<uuid> ...` with argv arrays (no shell string interpolation),
  full paths, and sensible timeouts. It must never run as `root` or the web user.
- **Data directory**: build paths as `PTERODACTYL_GIT_DATA_DIRECTORY . '/' . $server->uuid`;
  the per-server folders are owned by `pterodactyl`.
- **Route permissions**: the server `/git` route uses `permission: 'git.*'`. Sub-users only see
  the tab if the egg defines the git permission group; root admins always see it.
- **Frontend route gating**: both routers hide `feature: 'git'` routes when
  `git.enabled` is false and the user is not root admin.

---

## 5. Validation before release (mandatory)

1. **Lint PHP**: `php -l` every changed file under `src/` and `patcher/apply.php`.
2. **Stock patch test**: rebuild the stock simulation and run the patcher;
   every op must report `PATCHED`, and a second run must report `SKIPPED` (idempotency):
   ```bash
   php patcher/apply.php /var/www/pterodactyl                 # expect SKIPPED (already applied)
   php patcher/apply.php /tmp/stockpanel                      # expect all PATCHED
   php patcher/apply.php /tmp/stockpanel                      # repeat: expect all SKIPPED
   ```
3. **Live smoke test**: on the reference panel, run a history request with a client API token and
   confirm the JSON `commits` is an array; then load `/server/<uuid>/git?history` in the browser.
4. **TypeScript**: `cd /var/www/pterodactyl && yarn run build:production` must succeed.
5. **Migration**: `php artisan migrate:status` shows the three tables as Ran.

---

## 6. Releasing / pushing

1. `cd /root/PteroGit`.
2. Stage the relevant files. Do NOT commit credentials, `.env` files, or the live panel tree.
   This repository contains only the installer, patcher, docs and `src/` feature files.
3. Commit with conventional style:
   ```
   feat: add XYZ
   fix: correct history response shape
   docs: update install instructions
   ```
4. Push to `origin/main`:
   ```bash
   git push origin main
   ```
5. If README changed, the installer one-liner
   (`bash <(curl -sSL https://raw.githubusercontent.com/Nex-Devz/PteroGit/main/install.sh)`)
   is automatically up to date — no extra step.

### Push checklist

- [ ] `php -l` clean on all changed PHP
- [ ] Patcher passes PATCHED → SKIPPED on stock simulation
- [ ] `yarn run build:production` succeeds against the reference panel
- [ ] No secrets, `.env`, panel logs or the whole panel tree staged
- [ ] README still lists the correct supported panel version
- [ ] `install.sh` still references the exact copy destinations in `src/`

---

## 7. Environment variables consumed

| Variable | Default | Purpose |
|---|---|---|
| `PTERODACTYL_GIT_ENABLED` | `true` | Master feature toggle |
| `PTERODACTYL_GIT_DATA_DIRECTORY` | `/var/lib/pterodactyl` | Parent dir of per-server git folders |
| `GITHUB_OAUTH_ENABLED` | `false` | Enable GitHub OAuth sign-in |
| `GITHUB_OAUTH_CLIENT_ID` | `` | OAuth App client id |
| `GITHUB_OAUTH_CLIENT_SECRET` | `` | OAuth App client secret |
| `GITHUB_OAUTH_REDIRECT_URI` | `<APP_URL>/account/github/oauth/callback` | OAuth callback |

These are appended by `install.sh` only when absent; the patcher never rewrites `.env`.