# `tests/fixtures/stockpanel/` – stock Pterodactyl files

This directory holds the **unmodified, stock** Pterodactyl Panel `1.15.1` source files that the
patcher (`patcher/apply.php`) modifies. It exists so the CI job `patcher-idempotency` can run
against genuine stock files instead of a hand-made approximation.

## What is inside

Only the 12 files that PteroGit patches, copied verbatim from
[`pterodactyl/panel` v1.15.1](https://github.com/pterodactyl/panel/tree/v1.15.1):

```
routes/api-client.php
routes/admin.php
routes/base.php
config/pterodactyl.php
app/Models/Permission.php
app/Models/User.php
app/Http/ViewComposers/AssetComposer.php
resources/scripts/state/settings.ts
resources/scripts/routers/routes.ts
resources/scripts/routers/ServerRouter.tsx
resources/scripts/routers/DashboardRouter.tsx
resources/views/partials/admin/settings/nav.blade.php
```

plus a stub `artisan` file so the patcher's preflight checks pass.

## Important

- These files must remain **pristine** (unpatched). CI runs the patcher against them and expects
  all operations to report `PATCHED`, then a second run must report all `SKIPPED`.
- Never run locally-patched copies through.

## Regenerating / upgrading

When testing support for a new panel minor version (e.g. 1.16.x):

1. Update the tag in `refresh.sh` and run it:
   ```bash
   bash tests/fixtures/refresh.sh
   ```
2. Verify the patcher passes on the refreshed fixture:
   ```bash
   php patcher/apply.php tests/fixtures/stockpanel   # expect all PATCHED
   php patcher/apply.php tests/fixtures/stockpanel   # repeat: expect all SKIPPED
   ```
3. If any operation reports `anchor not found`, update the corresponding needle in `patcher/apply.php`.

## License

The panel source in this directory is licensed under the MIT License by the Pterodactyl project
(https://github.com/pterodactyl/panel). PteroGit re-distributes these files only as test fixtures
with this attribution; the panel's full MIT license text applies to them.