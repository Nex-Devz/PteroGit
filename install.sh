#!/usr/bin/env bash
#
# PteroGit – GitHub/Git integration installer for Pterodactyl Panel 1.15.x
#
# One-command, idempotent, theme-agnostic installer. Self-contained: when run
# through the one-liner (process substitution) it fetches the PteroGit source
# bundle automatically, so you never need to clone the repository.
#
#   One-command (latest main):
#     bash <(curl -sSL https://raw.githubusercontent.com/Nex-Devz/PteroGit/main/install.sh)
#
#   One-command pinned to a release:
#     PTEROGIT_VERSION=v1.1.0 bash <(curl -sSL https://raw.githubusercontent.com/Nex-Devz/PteroGit/main/install.sh)
#
#   From a local checkout:
#     bash install.sh
#     bash install.sh /opt/panel --yes-sudoers --skip-build
#
# Environment:
#   PTEROGIT_VERSION      source bundle ref (branch/tag/sha). Default: main
#   GIT_FEATURE_SUDOERS   yes|no|ask  sudoers setup mode. Default: ask (see --yes/--no)
#   GIT_FEATURE_SKIP_BUILD=1          skip the frontend build
#   PTEROGIT_LOG=/path/file.log       override install log destination
#   NO_COLOR=1                        disable coloured output
#
# Flags:
#   -p, --panel PATH    panel root (aliases: first positional argument)
#   --force             bypass the panel 1.15.x version check
#   --yes-sudoers       install the scoped sudoers rule without prompting
#   --no-sudoers        skip the sudoers rule without prompting
#   --skip-build        skip the frontend build
#   --dry-run           validate everything, print the plan, change nothing
#   --rollback [DIR]    restore files from a previous backup and exit
#   -q, --quiet         minimal output (full log is still written to disk)
#   -a, --allow-non-root  continue without root (sudoers step is skipped)
#   -h, --help          this help
#   -v, --version       installer version
#
# Requirements:
#   - Root (or sudo) and PHP CLI. MySQL/MariaDB, a running Pterodactyl 1.15.x
#     panel, and Node 18+/Yarn 1.x are needed for a full install.

set -euo pipefail

PTEROGIT_INSTALLER_VER="1.1.2"
PTEROGIT_VERSION="${PTEROGIT_VERSION:-main}"
PTEROGIT_GITHUB="Nex-Devz/PteroGit"

usage() {
    cat <<'EOF'
PteroGit installer - GitHub/Git integration for Pterodactyl Panel 1.15.x

Usage:
  bash install.sh [OPTIONS] [PANEL_PATH]

Options:
  -p, --panel PATH      panel root (default: auto-detect /var/www/pterodactyl)
      --force           bypass the panel 1.15.x version check
      --yes-sudoers     install the scoped sudoers rule without prompting
      --no-sudoers      skip the sudoers rule without prompting
      --skip-build      skip the frontend build
      --dry-run         validate everything, print the plan, change nothing
      --rollback [DIR]  restore files from a previous backup and exit
  -q, --quiet           minimal output (full log still written to disk)
  -a, --allow-non-root  continue without root (sudoers step is skipped)
  -h, --help            show this help
  -v, --version         show installer version

Environment: PTEROGIT_VERSION, GIT_FEATURE_SUDOERS, GIT_FEATURE_SKIP_BUILD,
             PTEROGIT_LOG, NO_COLOR
EOF
}

have() { command -v "$1" >/dev/null 2>&1; }

# -------------------------------------------------------------- parse args
PANEL=""
SUDOERS_MODE="${GIT_FEATURE_SUDOERS:-ask}"
FORCE=0
SKIP_BUILD="${GIT_FEATURE_SKIP_BUILD:-0}"
DRY_RUN=0
ROLLBACK=""
QUIET=0
ALLOW_NON_ROOT=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        -p|--panel) shift; PANEL="${1:-}";;
        --panel=*)  PANEL="${1#*=}";;
        --force)    FORCE=1;;
        --yes-sudoers) SUDOERS_MODE="yes";;
        --no-sudoers)  SUDOERS_MODE="no";;
        --skip-build)  SKIP_BUILD=1;;
        --dry-run)     DRY_RUN=1;;
        --rollback)    ROLLBACK="__latest__";;
        --rollback=*)  ROLLBACK="${1#*=}";;
        -q|--quiet)    QUIET=1;;
        -a|--allow-non-root) ALLOW_NON_ROOT=1;;
        -h|--help)     usage; exit 0;;
        -v|--version)  echo "PteroGit installer $PTEROGIT_INSTALLER_VER"; exit 0;;
        -*) die "Unknown option: $1 (see --help)";;
        *) if [[ -z "$PANEL" ]]; then PANEL="$1"; else die "Unexpected argument: $1"; fi;;
    esac
    shift
done

# ------------------------------------------------ output + logging helpers
if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then
    GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
else
    GREEN=''; RED=''; YELLOW=''; CYAN=''; NC=''
fi

LOG_FILE="${PTEROGIT_LOG:-/var/log/pterogit-install-$(date +%Y%m%d-%H%M%S).log}"
touch "$LOG_FILE"

log()  { printf '%s\n' "$*" >> "$LOG_FILE"; }
info() { log "[*] $*"; [[ "$QUIET" -eq 1 ]] || echo -e "${CYAN}[*]${NC} $*"; }
ok()   { log "[+] $*"; [[ "$QUIET" -eq 1 ]] || echo -e "${GREEN}[+]${NC} $*"; }
warn() { log "[!] $*"; [[ "$QUIET" -eq 1 ]] || echo -e "${YELLOW}[!]${NC} $*"; }
die()  { log "[x] $*"; echo -e "${RED}[x]${NC} $*" >&2; exit 1; }

on_err() {
    local rc=$?
    echo -e "${RED}[x] Installer aborted (exit $rc).${NC}" >&2
    if [[ -n "${BK:-}" && -d "$BK" ]]; then
        echo -e "${RED}[x] Backups are kept in: $BK${NC}" >&2
        echo -e "${RED}[x] Restore with: bash install.sh --rollback '$BK'${NC}" >&2
    fi
    echo -e "${RED}[x] Full log: $LOG_FILE${NC}" >&2
}
trap on_err ERR

# ------------------------------------------------------- lock + temp cleanup
LOCK_FILE="/tmp/pterogit-install.lock"
exec 9>"$LOCK_FILE"
flock -n 9 || die "Another PteroGit installer is already running ($LOCK_FILE)."
TMP_DIR=""

cleanup() { rm -rf "${TMP_DIR:-}"; flock -u 9 2>/dev/null || true; }
trap cleanup EXIT
trap 'echo -e "${YELLOW}[!]${NC} Interrupted by user. Backup (if any): $BK" >&2; exit 130' INT

# ---------------------------------------------------------------- preflight
[[ $EUID -eq 0 ]] || {
    if [[ "$ALLOW_NON_ROOT" -eq 1 ]]; then
        warn "Running without root - the sudoers step will be skipped."
    else
        die "Please run as root (or use sudo). Pass --allow-non-root to force."
    fi
}
have php || die "PHP CLI not found (apt install php-cli)."
have git  || warn "git not found on the host - the panel git feature needs it."

detect_panel() {
    local cand
    for cand in /var/www/pterodactyl /www/pterodactyl /var/www/html/pterodactyl /opt/pterodactyl /var/www/app; do
        if [[ -f "$cand/artisan" ]]; then PANEL="$cand"; return 0; fi
    done
    return 1
}
if [[ -z "$PANEL" ]]; then
    detect_panel || die "No Pterodactyl panel found (pass -p PATH or a path argument)."
    ok "Detected panel at $PANEL"
else
    [[ -f "$PANEL/artisan" ]] || die "No Pterodactyl panel found at '$PANEL' (missing artisan)."
fi

PHP="$(command -v php)"
PHP_MAJOR_MINOR="$("$PHP" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
case "$PHP_MAJOR_MINOR" in
    8.2|8.3|8.4) ;;
    *) warn "PHP $PHP_MAJOR_MINOR detected - Pterodactyl requires PHP 8.2+." ;;
esac

# Validate the panel minor version so legacy anchors don't silently break.
# Primary source: config/app.php's 'version' key (survives composer updates).
# Fallback: `php artisan --version`.
PANEL_VERSION_TEXT="$(grep -Eo "['\"][Vv]ersion['\"]\s*=>\s*['\"][0-9]+\.[0-9]+\.[0-9]+" "$PANEL/config/app.php" 2>/dev/null | grep -Eo '[0-9]+\.[0-9]+\.[0-9]+' | head -1 || true)"
if [[ -z "$PANEL_VERSION_TEXT" ]]; then
    if PANEL_VERSION_TEXT="$(cd "$PANEL" && "$PHP" artisan --version 2>/dev/null)"; then
        :
    else
        PANEL_VERSION_TEXT=""
    fi
fi
PANEL_MINOR="$(printf '%s' "$PANEL_VERSION_TEXT" | grep -Eo '1\.15\.[0-9]+' | head -1 || true)"
if [[ -n "$PANEL_MINOR" ]]; then
    ok "Detected Panel $PANEL_MINOR (supported: 1.15.x)"
elif [[ "$FORCE" -eq 1 ]]; then
    warn "Panel version not detected ($PANEL_VERSION_TEXT) - proceeding because --force was set."
else
    die "Panel version not detected ('$PANEL_VERSION_TEXT'). PteroGit targets 1.15.x; use --force to continue."
fi

# Disk space sanity check (the frontend build needs several hundred MB).
FREE_KB="$(df -Pk "$PANEL" | awk 'NR==2 {print $4}')" || FREE_KB=""
if [[ -n "$FREE_KB" && "$FREE_KB" -lt 524288 ]]; then
    warn "Only $(( FREE_KB / 1024 )) MB free on $(df -Pk "$PANEL" | awk 'NR==2 {print $6}' 2>/dev/null) - the build may fail."
fi

# ------------------------------------------------------------- source bundle
REPO_DIR=""
if [[ -n "${BASH_SOURCE[0]:-}" ]]; then
    if ABS="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd)"; then
        if [[ -f "$ABS/patcher/apply.php" && -d "$ABS/src" ]]; then
            REPO_DIR="$ABS"
        fi
    fi
fi

if [[ -n "$REPO_DIR" ]]; then
    info "Using local PteroGit sources: $REPO_DIR"
else
    [[ "$PTEROGIT_VERSION" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]*$ ]] \
        || die "Invalid PTEROGIT_VERSION '$PTEROGIT_VERSION'."
    if [[ "$DRY_RUN" -eq 1 ]]; then
        info "Would download PteroGit@$PTEROGIT_VERSION (source bundle not present locally)."
    else
        have curl || have wget || die "curl or wget is required to fetch the source bundle."
        have tar || die "tar is required to extract the source bundle."
        TMP_DIR="$(mktemp -d)"
        dl() { # $1 url, $2 dest
            if have curl; then curl -fsSL "$1" -o "$2"
            elif have wget; then wget -qO "$2" "$1"
            else return 1; fi
        }
        info "Fetching PteroGit@$PTEROGIT_VERSION source bundle..."
        if ! dl "https://codeload.github.com/$PTEROGIT_GITHUB/tar.gz/refs/heads/$PTEROGIT_VERSION" "$TMP_DIR/bundle.tgz" 2>/dev/null; then
            dl "https://codeload.github.com/$PTEROGIT_GITHUB/tar.gz/$PTEROGIT_VERSION" "$TMP_DIR/bundle.tgz"
        fi
        [[ -s "$TMP_DIR/bundle.tgz" ]] || die "Failed to download PteroGit source bundle."
        tar -xzf "$TMP_DIR/bundle.tgz" -C "$TMP_DIR"
        REPO_DIR="$(find "$TMP_DIR" -maxdepth 1 -type d -name 'PteroGit-*' | head -1 || true)"
        [[ -n "$REPO_DIR" ]] || die "Failed to unpack the PteroGit source bundle."
        ok "Source bundle ready: $REPO_DIR"
    fi
fi

SRC="$REPO_DIR/src"
PATCHER="$REPO_DIR/patcher/apply.php"

# Guard against serving a stale/cached source bundle (CDN lag) that could
# belong to a different installer revision.
BUNDLE_VER="$(grep -Eo 'PTEROGIT_INSTALLER_VER="[^"]+"' "$REPO_DIR/install.sh" 2>/dev/null | head -1 | cut -d'"' -f2 || true)"
if [[ -z "$BUNDLE_VER" ]]; then
    warn "Source bundle has no version marker (older bundle). Using it anyway."
elif [[ "$BUNDLE_VER" != "$PTEROGIT_INSTALLER_VER" ]]; then
    die "Source bundle version $BUNDLE_VER does not match this installer ($PTEROGIT_INSTALLER_VER) - likely a stale GitHub cache. Retry in a minute, or clone the repo and run 'bash install.sh' locally."
fi

if [[ "$DRY_RUN" -eq 0 ]]; then
    [[ -d "$SRC" ]]     || die "Missing src/ directory in bundle ('$SRC')."
    [[ -f "$PATCHER" ]] || die "Missing patcher/apply.php in bundle ('$PATCHER')."
fi

# web user used by the panel
WEB_USER=""
for u in www-data nginx apache httpd; do
    if id -u "$u" >/dev/null 2>&1; then WEB_USER="$u"; break; fi
done
[[ -n "$WEB_USER" ]] || WEB_USER="www-data"

# ---------------------------------------------------------------- rollback
if [[ -n "$ROLLBACK" ]]; then
    if [[ "$ROLLBACK" == "__latest__" ]]; then
        ROLLBACK="$(find "$PANEL/storage" -maxdepth 1 -type d -name 'github-integration-backup-*' 2>/dev/null | sort | tail -1 || true)"
        [[ -n "$ROLLBACK" ]] || die "No previous backups found under $PANEL/storage."
    fi
    [[ -d "$ROLLBACK" ]] || die "Backup directory not found: $ROLLBACK"
    info "Restoring backup: $ROLLBACK"
    RESTORED=0
    while IFS= read -r -d '' f; do
        rel="${f#"$ROLLBACK"/}"
        cp -a "$f" "$PANEL/$rel"
        RESTORED=$((RESTORED + 1))
    done < <(find "$ROLLBACK" -type f -print0)
    ok "Restored $RESTORED file(s)."
    info "Rebuilding caches..."
    (cd "$PANEL" && "$PHP" artisan config:cache >/dev/null 2>&1) || true
    (cd "$PANEL" && "$PHP" artisan route:cache >/dev/null 2>&1)  || true
    (cd "$PANEL" && "$PHP" artisan view:cache >/dev/null 2>&1)  || true
    ok "Rollback complete. Re-run 'yarn install && yarn run build:production' inside $PANEL if the frontend changed."
    exit 0
fi

# ------------------------------------------------------------ dry-run report
if [[ "$DRY_RUN" -eq 1 ]]; then
    echo
    info "DRY RUN - nothing was changed. Plan for $PANEL:"
    echo "  1. Create timestamped backup of 12 patched files + .env"
    echo "  2. Copy new feature files from src/ into the panel"
    echo "  3. Apply idempotent patches (routes, config, models, router)"
    echo "  4. Ensure .env variables (PTERODACTYL_GIT_*, GITHUB_OAUTH_*)"
    echo "  5. composer dump-autoload + php artisan migrate --force"
    [[ "$SKIP_BUILD" -eq 1 ]] && echo "  6. (skipped) frontend build" \
                              || echo "  6. yarn build:production (unless --skip-build)"
    echo "  7. Rebuild config/route/view caches, restart queue"
    echo "  8. Fix file ownership ($WEB_USER:$WEB_USER) where needed"
    [[ "$SUDOERS_MODE" == "no" ]] && echo "  9. (skipped) sudoers rule" \
                                  || echo "  9. Install scoped sudoers rule (web user may act as 'pterodactyl')"
    echo " 10. Verify idempotency (second patcher run must report all SKIPPED)"
    exit 0
fi

# ------------------------------------------------------------------ backup
START_TS="$(date +%s)"
STAMP="$(date +%Y%m%d-%H%M%S)"
BK="$PANEL/storage/github-integration-backup-$STAMP"
mkdir -p "$BK"
info "Backing up patched files -> $BK"

backup_path() { # $1 relative path
    local rel="$1" target
    [[ -f "$PANEL/$rel" ]] || return 0
    target="$BK/$rel"
    mkdir -p "$(dirname "$target")"
    cp -a "$PANEL/$rel" "$target"
}

for f in routes/api-client.php routes/admin.php routes/base.php \
         config/pterodactyl.php app/Http/ViewComposers/AssetComposer.php \
         app/Models/User.php app/Models/Permission.php \
         resources/scripts/state/settings.ts resources/scripts/routers/routes.ts \
         resources/scripts/routers/ServerRouter.tsx resources/scripts/routers/DashboardRouter.tsx \
         resources/views/partials/admin/settings/nav.blade.php .env; do
    backup_path "$f"
done
ok "Backup created at $BK"

# ---------------------------------------------------------- copy new files
info "Copying new feature files into the panel..."
COPIED=0
while IFS= read -r -d '' f; do
    rel="${f#"$SRC"/}"
    dest="$PANEL/$rel"
    if [[ ! -e "$dest" ]]; then
        mkdir -p "$(dirname "$dest")"
        cp "$f" "$dest"
        COPIED=$((COPIED + 1))
    fi
done < <(find "$SRC" -type f -print0)
ok "Copied $COPIED new file(s) (existing files left untouched)."

# --------------------------------------------------------------- patches
info "Applying route/config/router patches (idempotent)..."
PATCH_OUTPUT=""
if ! PATCH_OUTPUT="$( "$PHP" "$PATCHER" "$PANEL" 2>&1 )"; then
    warn "Patcher returned a non-zero exit code - inspect the output below."
    warn "$PATCH_OUTPUT"
else
    info "$PATCH_OUTPUT"
fi

# Report results from the patcher output
PATCH_ERROR_COUNT="$(printf '%s' "$PATCH_OUTPUT" | grep -c '^ERROR' || true)"
PATCH_PATCHED_COUNT="$(printf '%s' "$PATCH_OUTPUT" | grep -c '^PATCH' || true)"
PATCH_SKIPPED_COUNT="$(printf '%s' "$PATCH_OUTPUT" | grep -c '^SKIP' || true)"

if [[ "$PATCH_ERROR_COUNT" -gt 0 ]]; then
    warn "$PATCH_ERROR_COUNT patch(es) could not be applied (panel may have customised files)."
    warn "The installer will continue - you may need to add those code blocks manually."
    warn "Check the patcher output above for details."
else
    ok "All patches applied successfully ($PATCH_PATCHED_COUNT patched, $PATCH_SKIPPED_COUNT skipped)."
fi

# Verify idempotency on a second pass
if ! PATCH_VERIFY="$( "$PHP" "$PATCHER" "$PANEL" 2>&1 )"; then
    warn "Second patcher pass errored - inspect the output."
else
    VERIFY_PATCHED="$(printf '%s' "$PATCH_VERIFY" | grep -c '^PATCH' || true)"
    VERIFY_SKIPPED="$(printf '%s' "$PATCH_VERIFY" | grep -c '^SKIP' || true)"
    if [[ "$VERIFY_PATCHED" -gt 0 ]]; then
        warn "Re-run produced $VERIFY_PATCHED PATCHED op(s) - expected 0 (idempotency)."
    else
        ok "Patcher idempotent ($VERIFY_SKIPPED op(s) skipped on re-run)."
    fi
fi

# ------------------------------------------------------------------- .env
info "Ensuring required .env variables are present..."
ENV_FILE="$PANEL/.env"
touch "$ENV_FILE"
chown "$WEB_USER:$WEB_USER" "$ENV_FILE" 2>/dev/null || true
add_env() { # $1 key, $2 default
    grep -q "^$1=" "$ENV_FILE" || echo "$1=$2" >> "$ENV_FILE"
}
add_env PTERODACTYL_GIT_ENABLED true
add_env PTERODACTYL_GIT_DATA_DIRECTORY /var/lib/pterodactyl
add_env GITHUB_OAUTH_ENABLED false
add_env GITHUB_OAUTH_CLIENT_ID ""
add_env GITHUB_OAUTH_CLIENT_SECRET ""
ok "Environment variables ensured."

# ------------------------------------------------------- composer + migrate
info "Refreshing composer autoloader..."
if have composer; then
    composer dump-autoload -d "$PANEL" --no-interaction >/dev/null 2>&1 || warn "composer dump-autoload failed."
elif [[ -f "$PANEL/composer.phar" ]]; then
    "$PHP" "$PANEL/composer.phar" dump-autoload -d "$PANEL" --no-interaction >/dev/null 2>&1 || warn "composer dump-autoload failed."
else
    warn "composer not found - skipping autoload refresh."
fi

info "Running database migrations..."
(cd "$PANEL" && "$PHP" artisan migrate --force --no-interaction) || die "Migration failed."

# -------------------------------------------------------------- frontend
if [[ "$SKIP_BUILD" -eq 1 ]]; then
    warn "Frontend build skipped (--skip-build). Run 'yarn install && yarn run build:production' inside $PANEL later."
elif have node && have yarn; then
    info "Rebuilding frontend assets (this can take several minutes)..."
    if ! (cd "$PANEL" && yarn install --frozen-lockfile --non-interactive >/dev/null 2>&1); then
        (cd "$PANEL" && yarn install --non-interactive >/dev/null 2>&1) || warn "yarn install failed - continuing."
    fi
    if ! (cd "$PANEL" && yarn run build:production); then
        warn "Frontend build failed - see output above."
    fi
else
    warn "Node/Yarn not found - frontend not rebuilt. Install Node 18+/Yarn 1.x and run 'yarn install && yarn run build:production' inside $PANEL."
fi

# ---------------------------------------------------------------- caching
info "Rebuilding Laravel caches..."
if ! (cd "$PANEL" && "$PHP" artisan config:cache >/dev/null 2>&1); then warn "config:cache failed"; fi
if ! (cd "$PANEL" && "$PHP" artisan route:cache >/dev/null 2>&1); then warn "route:cache failed"; fi
if ! (cd "$PANEL" && "$PHP" artisan view:cache >/dev/null 2>&1); then warn "view:cache failed"; fi
if ! (cd "$PANEL" && "$PHP" artisan queue:restart >/dev/null 2>&1); then info "Queue restart skipped (queue driver is not synchronous)."; fi

# -------------------------------------------------------- file ownership
PI_OWNER="$(stat -c %U "$PANEL" 2>/dev/null || echo "")"
if [[ "$PI_OWNER" == "$WEB_USER" ]]; then
    info "Panel is already owned by $WEB_USER - skipping chown."
else
    info "Fixing file ownership (chown $WEB_USER:$WEB_USER on tracked dirs)..."
    if ! chown -R "$WEB_USER:$WEB_USER" "$PANEL/app" "$PANEL/routes" "$PANEL/config" \
          "$PANEL/resources" "$PANEL/database" "$PANEL/bootstrap" "$PANEL/storage" >/dev/null 2>&1; then
        warn "chown skipped (permissions)."
    fi
    chown "$WEB_USER:$WEB_USER" "$PANEL/.env" 2>/dev/null || true
fi

# --------------------------------------------------- optional sudoers rule
INSTALL_SUDOERS=0
if [[ "$EUID" -eq 0 ]]; then
    if [[ "$SUDOERS_MODE" == "yes" ]]; then
        INSTALL_SUDOERS=1
    elif [[ "$SUDOERS_MODE" == "no" ]]; then
        INSTALL_SUDOERS=0
    elif [[ -t 0 ]]; then
        read -r -p "Configure sudoers so the web user can run panel git commands (scoped, safe)? [y/N]: " ans
        [[ "${ans,,}" == "y" ]] && INSTALL_SUDOERS=1
    else
        warn "Non-interactive shell - skipping sudoers setup. Re-run with --yes-sudoers to install it."
    fi
else
    warn "Not running as root - skipping sudoers setup."
fi

if [[ "$INSTALL_SUDOERS" -eq 1 ]]; then
    if ! id -u pterodactyl >/dev/null 2>&1; then
        useradd -r -m -s /usr/sbin/nologin pterodactyl 2>/dev/null \
            || useradd -r -s /usr/sbin/nologin pterodactyl
        ok "Created system user 'pterodactyl'."
    fi
    cat > /etc/sudoers.d/pterodactyl-git <<SOF
# GitHub integration for the Pterodactyl panel.
# Allows the web user to run git/tee commands ONLY as the 'pterodactyl' user.
$WEB_USER ALL=(pterodactyl) NOPASSWD: /usr/bin/git, /usr/bin/tee, /usr/bin/chown
SOF
    chmod 0440 /etc/sudoers.d/pterodactyl-git
    if ! visudo -c >/dev/null 2>&1; then
        rm -f /etc/sudoers.d/pterodactyl-git
        die "sudoers validation failed - rule removed."
    fi
    ok "sudoers rule installed."
fi

# ------------------------------------------------------------- final check
ELAPSED="$(( $(date +%s) - START_TS ))s"

echo
echo -e "${GREEN}==================================================================${NC}"
echo -e "${GREEN} GitHub Integration installed successfully!${NC}"
echo    "======================================================================"
echo
echo "Next steps:"
echo "  1. Grant the 'git' permission group to your sub-users:"
echo "     Server -> Users -> Edit -> enable the 'git' permissions."
echo "     The 'git' group is auto-registered by the installer; root admins"
echo "     always see the GitHub tab. (Docs: docs/permissions.md)"
echo
echo "  2. The server 'data_directory' must be readable by the 'pterodactyl'"
echo "     system user. Point it at the Wings data folder:"
echo "       PTERODACTYL_GIT_DATA_DIRECTORY=/var/lib/pterodactyl"
echo "     and ensure each server folder is chown'ed:"
echo "       chown -R pterodactyl:pterodactyl /var/lib/pterodactyl/<server-uuid>"
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
echo "Install log: $LOG_FILE"
echo "Elapsed: $ELAPSED"
echo "======================================================================"