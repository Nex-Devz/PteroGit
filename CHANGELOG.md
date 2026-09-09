# Changelog

All notable changes to PteroGit are documented here. The format is based on [Keep a
Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Releases are created automatically from `v*` tags (see `.github/workflows/release.yml`).

## [Unreleased]

### Added
- None yet.

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