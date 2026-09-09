## Description

<!-- Summarise what this change does and why. Link the relevant issue with `Closes #123`. -->

## Type of change

- [ ] Bug fix
- [ ] New feature
- [ ] Installer / patcher change
- [ ] Documentation
- [ ] Other (describe)

## Checklist

- [ ] I have read [CONTRIBUTING.md](../CONTRIBUTING.md) and understood the file categories (new vs patched).
- [ ] No secrets, `.env` files, panel logs or the full panel tree are staged.
- [ ] `php -l` passes on every changed PHP file (`src/` and `patcher/`).
- [ ] `bash -n install.sh` passes.
- [ ] The installer one-liner still works end-to-end (`bash <(curl -sSL .../main/install.sh)`).
- [ ] For patcher changes: anchors match stock Panel 1.15.x and a second run reports `SKIPPED`.
- [ ] For backend changes: frontend receives `commits` as an array from `/history`; `github_user_id` is cast to string.
- [ ] For frontend changes: `yarn run build:production` succeeds.
- [ ] README / `start.md` are updated if behaviour, requirements or environment variables changed.

## Testing performed

<!-- Paste the commands you ran and their results (lint, patcher output, smoke tests). -->

## Screenshots (if UI change)