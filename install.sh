#!/usr/bin/env bash
#
# PteroGit – GitHub/Git integration installer for Pterodactyl Panel 1.15.x
#
# One-command, idempotent, theme-agnostic installer for Pterodactyl's
# built-in GitHub/Git feature (account linking, commit/push/pull/history UI,
# gitignore & identity management, admin settings page).
#
#   bash install.sh                     # installs into /var/www/pterodactyl
#   bash install.sh /opt/panel          # installs into /opt/panel
#   GIT_FEATURE_SKIP_BUILD=1 bash install.sh   # skip frontend build
#
# Requirements:
#   - Root or sudo access.
#   - PHP ^8.2 with the pterodactyl panel on the same host, MySQL/MariaDB, Redis recommended.
#   - Node.js 18+ and Yarn 1.x to (re)compile the panel frontend.
#

set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PANEL="${1:-/var/www/pterodactyl}"
SRC="$REPO_DIR/src"
PATCHER="$REPO_DIR/patcher/apply.php"

GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
info()  { echo -e "${CYAN}[*]${NC} $*"; }
ok()    { echo -e "${GREEN}[+]${NC} $*"; }
warn()  { echo -e "${YELLOW}[!]${NC} $*"; }
die()   { echo -e "${RED}[x]${NC} $*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Please run this installer as root (or with sudo)."

# ---------------------------------------------------------------- preflight
[[ -f "$PANEL/artisan" ]] || die "No Pterodactyl panel found at '$PANEL' (missing artisan)."
[[ -d "$SRC" ]]           || die "Missing src/ directory next to installer ('$SRC')."

PHP="$(command -v php)"    || die "PHP CLI not found."
YARN="$(command -v yarn)"  || true
NODE="$(command -v node)"  || true

PHP_MAJOR_MINOR="$("$PHP" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
case "$PHP_MAJOR_MINOR" in
    8.2|8.3|8.4) ;;
    *) warn "PHP $PHP_MAJOR_MINOR detected – Pterodactyl requires PHP 8.2+." ;;
esac

# --------------------------------------------------------------- locate web user
WEB_USER=""
for u in www-data nginx apache http; do
    if id -u "$u" >/dev/null 2>&1; then WEB_USER="$u"; break; fi
done
[[ -n "$WEB_USER" ]] || WEB_USER="www-data"
ok "Panel: $PANEL   Web user: $WEB_USER   PHP: $PHP_MAJOR_MINOR"

# --------------------------------------------------------------- backup originals
STAMP="$(date +%Y%m%d-%H%M%S)"
BK="$PANEL/storage/github-integration-backup-$STAMP"
mkdir -p "$BK"
info "Backing up patched files -> $BK"

backup_path() {  # $1 relative path
    local rel="$1" target
    [[ -f "$PANEL/$rel" ]] || return 0
    target="$BK/$rel"; mkdir -p "$(dirname "$target")"
    cp -a "$PANEL/$rel" "$target"
}

backup_path routes/api-client.php
backup_path routes/admin.php
backup_path routes/base.php
backup_path config/pterodactyl.php
backup_path app/Http/ViewComposers/AssetComposer.php
backup_path app/Models/User.php
backup_path resources/scripts/state/settings.ts
backup_path resources/scripts/routers/routes.ts
backup_path resources/scripts/routers/ServerRouter.tsx
backup_path resources/scripts/routers/DashboardRouter.tsx
backup_path resources/views/partials/admin/settings/nav.blade.php
backup_path .env
ok "Backup created at $BK"

# -------------------------------------------------------------- copy new files
info "Copying new feature files into the panel..."
COPIED=0
while IFS= read -r -d '' f; do
    rel="${f#"$SRC"/}"
    dest="$PANEL/$rel"
    if [[ ! -e "$dest" ]]; then
        mkdir -p "$(dirname "$dest")"
        cp "$f" "$dest"
        COPIED=$((COPIED+1))
    fi
done < <(find "$SRC" -type f -print0)
ok "Copied $COPIED new file(s) (existing files left untouched)."

# ------------------------------------------------------------------- patches
info "Applying route/config/router patches (idempotent)..."
if "$PHP" "$PATCHER" "$PANEL"; then
    ok "Patches applied."
else
    die "Patch step failed – restore from $BK if needed."
fi

# -------------------------------------------------------------------- .env
info "Ensuring required .env variables are present..."
ENV_FILE="$PANEL/.env"
touch "$ENV_FILE"
add_env() { # $1 key, $2 default
    grep -q "^$1=" "$ENV_FILE" || echo "$1=$2" >> "$ENV_FILE"
}
add_env PTERODACTYL_GIT_ENABLED true
add_env PTERODACTYL_GIT_DATA_DIRECTORY /var/lib/pterodactyl
add_env GITHUB_OAUTH_ENABLED false
add_env GITHUB_OAUTH_CLIENT_ID ""
add_env GITHUB_OAUTH_CLIENT_SECRET ""
ok "Environment variables ensured."

# --------------------------------------------------------- composer + migrate
info "Refreshing composer autoloader..."
if command -v composer >/dev/null 2>&1; then
    composer dump-autoload -d "$PANEL" --no-interaction >/dev/null 2>&1 || true
elif [[ -f "$PANEL/composer.phar" ]]; then
    "$PHP" "$PANEL/composer.phar" dump-autoload -d "$PANEL" --no-interaction >/dev/null 2>&1 || true
else
    warn "composer not found – skipping autoload refresh."
fi

info "Running database migrations..."
(cd "$PANEL" && "$PHP" artisan migrate --force --no-interaction) || die "Migration failed."

# ------------------------------------------------------------------- frontend
if [[ "${GIT_FEATURE_SKIP_BUILD:-0}" == "1" ]]; then
    warn "GIT_FEATURE_SKIP_BUILD=1 – frontend build skipped. You must run 'yarn run build:production' later."
elif [[ -n "$NODE" && -n "$YARN" ]]; then
    info "Rebuilding frontend assets (this can take several minutes)..."
    (cd "$PANEL" && yarn install --frozen-lockfile --non-interactive >/dev/null 2>&1 || yarn install --non-interactive >/dev/null 2>&1)
    (cd "$PANEL" && yarn run build:production) || warn "Frontend build failed – see output above."
else
    warn "Node/Yarn not found – frontend not rebuilt. Install Node 18+/Yarn 1.x and run 'yarn install && yarn run build:production' inside $PANEL."
fi

# ------------------------------------------------------------------- caching
info "Rebuilding Laravel caches..."
(cd "$PANEL" && "$PHP" artisan config:cache >/dev/null 2>&1 || warn "config:cache failed")
(cd "$PANEL" && "$PHP" artisan route:cache >/dev/null 2>&1 || warn "route:cache failed")
(cd "$PANEL" && "$PHP" artisan view:cache >/dev/null 2>&1 || warn "view:cache failed")
(cd "$PANEL" && "$PHP" artisan queue:restart >/dev/null 2>&1 || true)

# ------------------------------------------------------------- file ownership
info "Fixing file ownership (chown -R $WEB_USER:$WEB_USER panel)..."
chown -R "$WEB_USER:$WEB_USER" "$PANEL/app" "$PANEL/routes" "$PANEL/config" \
      "$PANEL/resources" "$PANEL/database" "$PANEL/bootstrap" "$PANEL/storage" >/dev/null 2>&1 || warn "chown skipped (permissions)."
chown "$WEB_USER:$WEB_USER" "$PANEL/.env" 2>/dev/null || true

# ------------------------------------------------------- optional sudoers rule
read -r -p "Configure sudoers so the web user can run panel git commands (safe, scoped)? [y/N]: " ans
if [[ "${ans,,}" == "y" ]]; then
    if ! id -u pterodactyl >/dev/null 2>&1; then
        useradd -r -m -s /usr/sbin/nologin pterodactyl 2>/dev/null || useradd -r -s /usr/sbin/nologin pterodactyl
        ok "Created system user 'pterodactyl'."
    fi
    cat > /etc/sudoers.d/pterodactyl-git <<SOF
# GitHub integration for the Pterodactyl panel.
# Allows the web user to run git/tee commands ONLY as the 'pterodactyl' user.
$WEB_USER ALL=(pterodactyl) NOPASSWD: /usr/bin/git, /usr/bin/tee, /usr/bin/chown
SOF
    chmod 0440 /etc/sudoers.d/pterodactyl-git
    visudo -c >/dev/null 2>&1 || { rm -f /etc/sudoers.d/pterodactyl-git; die "sudoers validation failed – removed."; }
    ok "sudoers rule installed."
fi

# ---------------------------------------------------------------------- done
echo
echo -e "${GREEN}==================================================================${NC}"
echo -e "${GREEN} GitHub Integration installed successfully!${NC}"
echo    "======================================================================"
echo
echo "Next steps:"
echo "  1. Add the 'git.*' permission group to the panel Eggs you want the"
echo "     GitHub tab to appear for (Admin -> Nests -> Egg -> Permissions)."
echo "     Without it, only root admins will see the GitHub tab."
echo
echo "  2. Your server 'data_directory' (below) must be readable by the"
echo "     'pterodactyl' system user. Pointing it at the Wings data folder:"
echo "       PTERODACTYL_GIT_DATA_DIRECTORY=/var/lib/pterodactyl"
echo "     and ensure each server folder is chown'ed:"
echo "       chown -R pterodactyl:pterodactyl /var/lib/pterodactyl/<server-uuid>"
echo "     (this host already has server folders owned by pterodactyl, so the"
echo "     setup script on this machine is ready to go.)"
echo
echo "  3. (Optional) GitHub OAuth2 sign-in:"
echo "     Create an OAuth App on GitHub (Callback: <panel URL>/account/github/oauth/callback)"
echo "     then set in .env:"
echo "       GITHUB_OAUTH_ENABLED=true"
echo "       GITHUB_OAUTH_CLIENT_ID=your_client_id"
echo "       GITHUB_OAUTH_CLIENT_SECRET=your_client_secret"
echo "     Users can still connect with a Personal Access Token (repo scope) without OAuth."
echo
echo "  4. Flush the panel cache in the admin UI when required."
echo
echo "Backup of overwritten files: $BK"
echo "======================================================================"