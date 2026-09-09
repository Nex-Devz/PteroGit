#!/usr/bin/env bash
#
# Refreshes the stock panel test fixtures from the pterodactyl/panel repository.
#
#   bash tests/fixtures/refresh.sh
#
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIXTURE="$REPO_DIR/tests/fixtures/stockpanel"
TAG="${1:-v1.15.1}"
BASE="https://raw.githubusercontent.com/pterodactyl/panel/$TAG"

[[ -d "$FIXTURE" ]] || { echo "fixture directory missing: $FIXTURE" >&2; exit 1; } 

rm -rf "${FIXTURE:?}"/*
mkdir -p "$FIXTURE"/{app/Http/ViewComposers,app/Models,config,resources/scripts/routers,resources/scripts/state,resources/views/partials/admin/settings,routes}

FILES=(
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
)

for f in "${FILES[@]}"; do
    mkdir -p "$(dirname "$FIXTURE/$f")"
    if ! curl -fsSL "$BASE/$f" -o "$FIXTURE/$f"; then
        echo "error: failed to fetch $TAG/$f" >&2
        exit 1
    fi
done

printf '#!/usr/bin/env php\n<?php\necho "Pterodactyl Panel v1.15.1";' > "$FIXTURE/artisan"

echo "Fixture refreshed from panel $TAG."
echo "Verify:"
echo "  php patcher/apply.php tests/fixtures/stockpanel   # all PATCHED"
echo "  php patcher/apply.php tests/fixtures/stockpanel   # all SKIPPED"