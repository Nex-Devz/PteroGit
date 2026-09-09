# Contributing to PteroGit

Thanks for helping improve PteroGit. A few minutes reading this keeps the project
consistent and the installer reliable for everyone.

## Code of Conduct

This project adheres to the [Contributor Covenant](CODE_OF_CONDUCT.md). By
participating you agree to uphold it.

## Development guide

Read [start.md](start.md) first. It is the authoritative reference for:

- how the repository maps onto a Pterodactyl panel,
- the two file categories (NEW files in `src/` vs PATCHED files handled by `patcher/apply.php`),
- how to add or update patch operations,
- the "do not regress" implementation notes (history response shape, `github_user_id`
  casting, sudo git runner, route permission gating),
- the mandatory validation steps and the release/push checklist.

## How to contribute

1. Fork the repository and create a branch:
   ```bash
   git checkout -b feat/describe-change
   ```
2. Make small, focused changes following the panel code style (PSR-12 PHP, usual TS/React
   conventions for `.tsx`/`.ts`).
3. Validate locally — at minimum:
   ```bash
   bash -n install.sh
   find src patcher -type f -name '*.php' -exec php -l {} \;
   php patcher/apply.php /tmp/stockpanel    # all ops should report PATCHED
   php patcher/apply.php /tmp/stockpanel    # repeat: all should report SKIPPED
   ```
4. Update documentation (README / `start.md`) when behaviour, requirements or environment
   variables change.
5. Commit with a conventional, imperative subject:
   ```
   feat: ...
   fix: ...
   docs: ...
   refactor: ...
   ```
   See [PULL_REQUEST_TEMPLATE.md](.github/PULL_REQUEST_TEMPLATE.md) for the PR checklist.
6. Open a pull request against `main`.

## What not to do

- Do not commit credentials, `.env` files, panel logs, or a copy of the whole panel tree.
- Do not overwrite stock panel files inside this repository — this repo only ships `src/`
  feature files, the patcher and the installer.
- Do not change `GithubController::history()` to wrap commits in an object; the frontend
  expects `data.commits` to be an array.
- Do not drop the `(string)` cast on `github_user_id` in `GithubAccountController`.
- Do not make `GitService` run git as `root` or the web user — always the `pterodactyl` user.

## Reporting bugs or requesting features

Use the provided issue templates:

- [Bug report](.github/ISSUE_TEMPLATE/bug_report.yml)
- [Feature request](.github/ISSUE_TEMPLATE/feature_request.yml)

For security vulnerabilities, follow [SECURITY.md](SECURITY.md) — do not open a public issue.