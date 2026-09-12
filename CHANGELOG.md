# Changelog

All notable changes to PteroGit are documented here. The format is based on [Keep a
Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Releases are created automatically from `v*` tags (see `.github/workflows/release.yml`).

## [1.5.0] – 2026-09-12

### Fixed
- **Installer patcher line-boundary protection**: `patch_multi` now detects line boundaries when inserting blocks, eliminating syntax errors and mid-line splicing on themed or customized panel files (`routes/admin.php`, `routes.ts`, `DashboardRouter.tsx`, etc.).
- **Installer feature file synchronization**: `install.sh` now reliably updates/overwrites all PteroGit feature files (`src/`) so newly added methods (such as `auditLog()`) and controller dependencies are never skipped when re-running the installer or upgrading.
- **Frontend build independence**: Removed external `@fortawesome/free-brands-svg-icons` package dependency by shipping a self-contained `faGithub` icon definition, resolving frontend build breakages on stock panels.
- **Cache driver lock resilience**: `guarded()` and `ensureWorktree()` now gracefully fall back when atomic cache locks are not supported by the cache store (e.g. `CACHE_DRIVER=file`), eliminating 500 errors on standard panel setups.
- **Exception handling & reporting**: `guarded()` now catches `\Throwable` and wraps all underlying errors into `DisplayException`, ensuring actionable error messages reach the client rather than collapsing into generic 500s.
- **Local transport sudo execution**: Removed invalid `sudo -u root` invocations in `LocalGitTransport.php`; all file and directory creations are handled under the `pterodactyl` system user.
- **"Keep existing files" (pull mode) connection**: Non-empty server directories now cleanly commit baseline files and merge `origin/<branch>` with `--allow-unrelated-histories -X ours` instead of aborting with untracked file conflicts.
- **"Clone / replace" mode connection**: Runs `clean -fdx` and checkout with force to prevent untracked file collisions during initial clone.
- **Dynamic remote default branch resolution**: Resolves real upstream default branch via `git ls-remote` (supporting `master`, `main`, etc.) and handles empty remote repositories without failing fetch.
- **Unborn HEAD status and pull resilience**: `currentBranchish()` and `pull()` now detect unborn HEAD states and return appropriate status rather than raising invalid ref fetch errors.

### Added
- **Explicit transport configuration**: Added `PTERODACTYL_GIT_TRANSPORT` (`auto|local|wings`) and `PTERODACTYL_GIT_PROBE_TTL` environment variable support to `config/pterodactyl.php` and `install.sh`.
- **Sudoers rule provisioning**: Installer provisions scoped sudo permissions in `/etc/sudoers.d/pterodactyl-git` for `git`, `cat`, `tee`, `mkdir`, and `chown` with `!requiretty`.

## [1.4.0] – 2026-09-11

### Added
- **Per-subuser git worktrees**: every server now runs a dedicated Git worktree per panel
  subuser (`git_worktrees` table + migration), giving each collaborator an isolated working
  tree and branch. Operations are attributed to the acting panel user and run inside that
  user's worktree, so concurrent collaborators can no longer overwrite each other's changes.
- **Multi-node support — git runs on the server's Wings node**: the panel can route every git
  operation to the node that actually hosts the server volume over HTTPS, authenticated with a
  short-lived HS256 JWT signed with the node's daemon token (`auto`/`local`/`wings` transport
  selection). The real volume path is resolved by the node itself, so a stale `nodes.daemonBase`
  can no longer point git at an empty directory, and credentials travel only in the request
  body via `GIT_ASKPASS` (never in process args or logs). Nodes without the channel fall back
  to local execution, so co-located/single-node installs keep working untouched.
- **Wings git channel Go module** (`wings/`): a small, dependency-free git channel for
  `pterodactyl/wings` with git exec, file ops and a health probe, path-traversal guards and a
  container-user privilege drop. Integration guide in `wings/README.md`; built and tested on
  Linux in CI (`go test ./...`).

### Changed
- `config('pterodactyl.git')` gains transport options: `transport` (`auto|local|wings`),
  `data_directory` and `probe_ttl` (defaults are backwards compatible; no config change needed
  on upgrade).

## [1.3.0] – 2026-09-10

### Added
- Admin **Git operation audit log** page (Admin → Settings → GitHub → Audit Log): paginated
  history of every Git operation across all servers with server, user, operation, result and
  error details.
- Server API endpoints for **commit detail** (`GET /github/commit`) and **git stash**
  (list / push / pop / drop). Backend is fully implemented and permission-guarded
  (`git.read` / `git.manage-repository`); the server-side UI for these lands in a follow-up
  release.

### Fixed
- Fresh installs no longer return **HTTP 500 on every server Git route**: the patcher now
  registers the 9 `Permission::ACTION_GIT_*` constants that the request classes resolve during
  authorization. v1.2.0 added the `git.*` permission group but omitted the constants.
- OAuth settings saved from the admin page now actually take effect: the `pterodactyl:git:*`
  keys are registered in `SettingsServiceProvider::$keys`, so DB values merge into
  `config('pterodactyl.git.*')` on boot, and the `client_secret` is stored encrypted and
  decrypted transparently when loaded (previously admin-saved values were silently ignored by
  the OAuth service).
- Patcher is now line-ending agnostic (LF ⇄ CRLF): patches authored on Windows apply cleanly
  to Linux panels and the idempotency markers match regardless of the working file's EOL. The
  encrypted-keys operation previously re-patched on every run.

### Changed
- Admin GitHub settings page rewritten with a full setup guide (personal access token, OAuth2
  app, `git.*` permission matrix, environment variables and troubleshooting), plus guidance for
  reaching `/account/github` on themed panels that replace the stock account navigation.
- Stock test fixtures now include `app/Providers/SettingsServiceProvider.php`; the CI
  patcher-idempotency and tolerant-whitespace checks cover the new provider and permission
  operations.

## [1.2.0] – 2026-09-10

### Added
- GitHub/Git pages now show a **Login Required** card with a styled login button when the
  user is not authenticated (HTTP 401), instead of a raw error flash message.

### Changed
- Patcher anchors use multi-anchor fallback strategies: each operation tries specific anchors
  first, then generic structural anchors, then file-boundary fallbacks — panels with reordered
  blocks, extra permission groups, or custom route files no longer fail.
- Patcher always exits 0 even on partial failures; the installer warns about skipped
  operations instead of aborting the remaining steps.
- Installer captures patcher output and reports PATCHED / SKIPPED / ERROR counts in the log.
- Config-append handles both `];` and `]);` endings with last-occurrence fallback.
- `find_group_end()` and `find_after_last()` helpers improve robustness for deeply nested
  or reordered permission/route blocks.

### Fixed
- Panels with heavily customised routes, models, or frontend components no longer throw
  spurious "anchor not found" errors during installation.
- Disabled-module state is shown cleanly instead of surfacing HTTP errors.

## [1.1.2] – 2026-09-09

### Changed
- Patcher anchors are now matched whitespace-tolerantly (leading indentation and internal
  whitespace runs are ignored, CRLF tolerated). Real-world panels — custom themes, panels with
  different formatting, or panels patched by older revisions — no longer throw spurious
  "anchor not found" errors.
- Patcher removes duplicate insertions from previous botched runs.
- Installer refuses a source bundle whose version marker does not match the running installer
  (guards against stale GitHub-cached bundles).
- CI: new tolerant-whitespace patcher check (indentation-shifted fixture).

## [1.1.1] – 2026-09-09

### Fixed
- `--dry-run` through the remote one-liner (`bash <(curl -sSL …/install.sh) --dry-run`) no
  longer fails on the missing local source bundle before printing its plan.

## [1.1.0] – 2026-09-09

### Changed
- Installer is now fully self-contained: the one-liner
  `bash <(curl -sSL https://raw.githubusercontent.com/Nex-Devz/PteroGit/main/install.sh)`
  downloads the PteroGit source bundle itself, no clone required.
- Robust installer handler: CLI flags (`-p/--panel`, `--skip-build`, `--dry-run`, `--rollback`,
  `-q/--quiet`, `-a/--allow-non-root`, `-h`, `-v`), `PTEROGIT_VERSION` pinning, panel
  auto-detection, lock file, ERR/INT traps, disk-space check, conditional chown, full file log.
- Panel version detection now reads `config/app.php` `'version'` first (survives `composer
  update`), with `php artisan --version` as a fallback.
- Patcher verifies idempotency automatically by re-running against the panel (all ops must be
  `SKIPPED`).

## [1.0.0] – 2026-09-09

### Added
- GitHub account linking (Personal Access Token `repo` scope) with optional OAuth2 sign-in.
- Per-server repository connection (connect, switch, disconnect).
- Git workflow UI: stage, unstage, discard, commit, push, pull.
- Branch management: create, switch, delete.
- Commit history and revert.
- `.gitignore` editor.
- Git identity (commit name/email) management.
- Admin settings page (Admin → Settings → GitHub).
- `git.*` sub-user permission group, auto-registered during installation.
- One-command, idempotent, theme-agnostic installer (`install.sh`):
  - timestamped backups of every modified file,
  - `.env` variables ensured,
  - migration, composer dump-autoload, frontend build, Laravel caches, queue restart,
  - scoped `sudoers` rule (optional) for the dedicated `pterodactyl` user,
  - panel version check (`1.15.x`) and non-interactive flags (`--yes-sudoers`, `--no-sudoers`,
    `--force`, `GIT_FEATURE_SUDOERS`, `GIT_FEATURE_SKIP_BUILD`).
- Idempotent source patcher (`patcher/apply.php`) covering routes, config, view composer,
  user + permission models and the TS routers.
- Project scaffolding: CONTRIBUTING, CODE_OF_CONDUCT, SECURITY, issue + PR templates,
  CI workflow (PHP lint, shellcheck, patcher idempotency on stock fixtures), release workflow.
- Stock panel test fixtures (`tests/fixtures/stockpanel/`) and fixture refresh script.