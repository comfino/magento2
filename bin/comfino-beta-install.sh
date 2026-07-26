#!/bin/bash

# Comfino Magento 2 — Beta clean-install tool (no prior Comfino module on the shop)
#
# A single, self-contained, hands-off tool for beta testers. It installs the
# Composer-based comfino/magento2 4.0.0 beta onto a shop that has NEVER had a
# Comfino module before, and can fully revert that install from the backup it
# creates. For shops that already run a legacy 2.x/3.x app/code Comfino module,
# use bin/comfino-beta-migrate.sh instead — that tool preserves and carries over
# existing settings and order statuses.
#
# Usage:
#   bin/comfino-beta-install.sh install [SHOP_DIR] [options]   # default action
#   bin/comfino-beta-install.sh revert  [SHOP_DIR] [options]
#   bin/comfino-beta-install.sh status  [SHOP_DIR]
#   bin/comfino-beta-install.sh help
#
# Why it is safe:
#   * It NEVER runs `bin/magento module:uninstall`. That command triggers the
#     module's Setup/Uninstall.php which DELETES Comfino order statuses and config.
#     Revert instead disables the module, removes the Composer package, and
#     restores composer.json / composer.lock / auth.json from the snapshot, so any
#     Magento DB state that exists is left untouched by default.
#   * Before touching anything it snapshots composer.json / composer.lock /
#     auth.json and dumps the (normally empty) Comfino config + custom order
#     statuses to JSON. On a truly clean shop those dumps are empty, which gives
#     `revert --purge-data` an exact "return to nothing" target.
#   * Any critical install step triggers an automatic rollback that removes the
#     package and restores the composer snapshot. Every rollback line is guarded
#     so a secondary error cannot abort the rollback itself.
#
# Revert data handling:
#   * Default revert leaves Comfino config rows and order statuses in the DB. This
#     is the safe choice — it never deletes data, and orphaned config/status rows
#     are harmless. Use this if any real orders were placed during beta testing.
#   * `revert --purge-data` additionally restores the DB to the pre-install
#     snapshot (i.e. removes the Comfino config + order statuses created by the
#     beta). Only do this when no orders depend on those statuses.
#
# Requirements:
#   * Magento 2.4.4+. On Magento 2.4.4-2.4.6 (psr/log pinned to 1.x by laminas-di /
#     symfony / elasticsearch) the install needs a Comfino beta built on php-sdk
#     >= 2.0.0-beta5, which widens psr/log to '^1.1 || ^2.0 || ^3.0'. Older betas
#     require psr/log ^3.0 and cannot resolve there; preflight warns and a failed
#     install prints a targeted diagnosis. Magento 2.4.7+ (psr/log 3.x) is unaffected.
#
# Configuration (priority high -> low): CLI option > env var > .env.local > default
#   SHOP_DIR ............. positional arg | COMFINO_TESTSHOP_PATH | TESTSHOP_PATH | autodetect
#   --repo-url URL ....... COMFINO_COMPOSER_REPO_URL  (main module repo; default:
#                          comfino/magento2-staging; SSH when no token, HTTPS when
#                          --auth-token is given). The SDK dependency staging repos
#                          (php-api-client-staging, php-sdk-staging, sdk-for-magento2-
#                          staging) are registered automatically — Composer ignores the
#                          repositories field of dependencies, so they must live in the
#                          shop root too. Override with COMFINO_SDK_REPOS ("key=url ..").
#   --constraint VER ..... COMFINO_PACKAGE_CONSTRAINT (default: ^4.0.0-beta2)
#   --auth-token TOKEN ... COMFINO_COMPOSER_TOKEN     (GitHub PAT; omit when using a deploy
#                          key. One github.com token covers the main + SDK repos; the PAT
#                          needs Contents:Read on all of them, not just magento2-staging)
#   --backup-id ID ....... revert: which backup to restore (default: latest)
#   --purge-data ......... revert: also remove Comfino config + order statuses (DESTRUCTIVE)
#   --force .............. install: proceed even if an existing Comfino install is detected
#   --yes / -y ........... do not prompt for confirmation (fully unattended)
#   --dry-run ............ print what would happen, change nothing
#   --keep-maintenance ... leave maintenance mode on at the end (default: restore prior state)

set -euo pipefail

# --------------------------------------------------------------------------- #
# Defaults — beta distribution settings.
#
# Beta builds are published to the PRIVATE staging repo comfino/magento2-staging
# as git tags (e.g. 4.0.0-beta1, 4.0.0-beta2). Composer reads those tags via VCS.
#   * Deploy-key testers (Approach A, recommended) use the SSH URL — no token.
#   * Token testers (Approach B) use the HTTPS URL + a fine-grained PAT
#     (--auth-token / COMFINO_COMPOSER_TOKEN), which is selected automatically
#     when a token is supplied.
# The constraint carries a -beta2 suffix so Composer accepts pre-releases and
# always resolves to the newest matching beta (or 4.0.x stable once published).
#
# comfino/magento2 depends on comfino/sdk-for-magento2 (which pulls in
# php-api-client / php-sdk). Those packages are public and will ship via Packagist
# alongside the module, but during the beta they are served from the *-staging
# repos. Composer only reads the root composer.json's repositories, never a
# dependency's, so the SDK staging repos are registered in the shop root too (see
# the SDK_REPOS block below).
# --------------------------------------------------------------------------- #
DEFAULT_REPO_URL_SSH="git@github.com:comfino/magento2-staging.git"
DEFAULT_REPO_URL_HTTPS="https://github.com/comfino/magento2-staging.git"
DEFAULT_CONSTRAINT="^4.0.0-beta6"
PACKAGE_NAME="comfino/magento2"
MODULE_NAME="Comfino_ComfinoGateway"
APPCODE_REL="app/code/Comfino/ComfinoGateway"
VENDOR_REL="vendor/comfino/magento2"
BACKUP_REL="var/comfino-install-backup"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# --------------------------------------------------------------------------- #
# Output helpers
# --------------------------------------------------------------------------- #
if [ -t 1 ]; then
    RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; BLUE=''; BOLD=''; NC=''
fi

info()  { echo -e "$@"; }
step()  { echo -e "${BLUE}${BOLD}==>${NC} ${BOLD}$*${NC}"; }
ok()    { echo -e "  ${GREEN}✓${NC} $*"; }
warn()  { echo -e "${YELLOW}⚠ $*${NC}" >&2; }
die()   { echo -e "${RED}Error: $*${NC}" >&2; exit 1; }

# --------------------------------------------------------------------------- #
# Argument parsing
# --------------------------------------------------------------------------- #
ACTION=""
SHOP_DIR_ARG=""
REPO_URL="${COMFINO_COMPOSER_REPO_URL:-}"
CONSTRAINT="${COMFINO_PACKAGE_CONSTRAINT:-}"
AUTH_TOKEN="${COMFINO_COMPOSER_TOKEN:-}"
BACKUP_ID="latest"
PURGE_DATA=0
FORCE=0
ASSUME_YES=0
DRY_RUN=0
KEEP_MAINTENANCE=0

while [ $# -gt 0 ]; do
    case "$1" in
        install|revert|status|help|--help|-h)
            if [ -z "$ACTION" ]; then
                case "$1" in --help|-h) ACTION="help" ;; *) ACTION="$1" ;; esac
            fi
            ;;
        --repo-url)     REPO_URL="${2:-}"; shift ;;
        --repo-url=*)   REPO_URL="${1#*=}" ;;
        --constraint)   CONSTRAINT="${2:-}"; shift ;;
        --constraint=*) CONSTRAINT="${1#*=}" ;;
        --auth-token)   AUTH_TOKEN="${2:-}"; shift ;;
        --auth-token=*) AUTH_TOKEN="${1#*=}" ;;
        --backup-id)    BACKUP_ID="${2:-}"; shift ;;
        --backup-id=*)  BACKUP_ID="${1#*=}" ;;
        --purge-data)   PURGE_DATA=1 ;;
        --force)        FORCE=1 ;;
        -y|--yes)       ASSUME_YES=1 ;;
        --dry-run)      DRY_RUN=1 ;;
        --keep-maintenance) KEEP_MAINTENANCE=1 ;;
        -*)             die "Unknown option: $1 (run 'help' for usage)" ;;
        *)              [ -z "$SHOP_DIR_ARG" ] && SHOP_DIR_ARG="$1" || die "Unexpected argument: $1" ;;
    esac
    shift
done

[ -z "$ACTION" ] && ACTION="install"

if [ -z "$REPO_URL" ]; then
    # Pick HTTPS when a token is provided (Approach B), otherwise SSH/deploy key (Approach A).
    if [ -n "$AUTH_TOKEN" ]; then REPO_URL="$DEFAULT_REPO_URL_HTTPS"; else REPO_URL="$DEFAULT_REPO_URL_SSH"; fi
fi

[ -z "$CONSTRAINT" ] && CONSTRAINT="$DEFAULT_CONSTRAINT"

# --------------------------------------------------------------------------- #
# Transitive private SDK dependencies.
#
# comfino/magento2 requires comfino/sdk-for-magento2, which in turn pulls in the
# php-api-client / php-sdk packages. These live in their own PRIVATE staging
# repos. Composer DOES NOT read the `repositories` field of installed packages —
# only the root composer.json is consulted — so every transitive VCS repo has to
# be registered in the shop root alongside the main module repo, or resolution
# fails with "could not be found in any version".
#
# Protocol mirrors the main repo: HTTPS when a token is supplied (Approach B),
# SSH/deploy key otherwise (Approach A). Override with COMFINO_SDK_REPOS (a
# newline- or space-separated list of "key=url" entries).
# --------------------------------------------------------------------------- #
SDK_REPO_SLUGS="php-api-client-staging php-sdk-staging sdk-for-magento2-staging"
SDK_REPOS=()

if [ -n "${COMFINO_SDK_REPOS:-}" ]; then
    # shellcheck disable=SC2206
    SDK_REPOS=( ${COMFINO_SDK_REPOS} )
else
    for _slug in $SDK_REPO_SLUGS; do
        if [ -n "$AUTH_TOKEN" ]; then
            SDK_REPOS+=( "comfino-${_slug}=https://github.com/comfino/${_slug}.git" )
        else
            SDK_REPOS+=( "comfino-${_slug}=git@github.com:comfino/${_slug}.git" )
        fi
    done
    unset _slug
fi

# --------------------------------------------------------------------------- #
# Help
# --------------------------------------------------------------------------- #
show_help() {
    sed -n '3,65p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

if [ "$ACTION" = "help" ]; then show_help; exit 0; fi

# --------------------------------------------------------------------------- #
# Resolve and validate the shop directory
# --------------------------------------------------------------------------- #
# Load .env.local next to this script (dev convenience; harmless on tester shops).
if [ -f "$SCRIPT_DIR/../.env.local" ]; then
    set +u; source "$SCRIPT_DIR/../.env.local"; set -u
fi

resolve_shop_dir() {
    local d="${SHOP_DIR_ARG:-${COMFINO_TESTSHOP_PATH:-${TESTSHOP_PATH:-}}}"

    if [ -z "$d" ]; then
        # Autodetect: current dir, or the dir this script sits in if it's a Magento root.
        if [ -f "./bin/magento" ] && [ -f "./app/etc/env.php" ]; then d="$(pwd)"
        elif [ -f "$SCRIPT_DIR/../bin/magento" ] && [ -f "$SCRIPT_DIR/../app/etc/env.php" ]; then d="$SCRIPT_DIR/.."
        fi
    fi

    [ -z "$d" ] && die "Magento shop directory not set. Pass it as an argument, or set COMFINO_TESTSHOP_PATH."
    [ -d "$d" ] || die "Shop directory does not exist: $d"
    d="$(cd "$d" && pwd)"
    [ -f "$d/bin/magento" ] || die "Not a Magento root (bin/magento missing): $d"
    [ -f "$d/app/etc/env.php" ] || die "Magento not installed (app/etc/env.php missing): $d"
    [ -f "$d/composer.json" ] || die "composer.json missing in shop root: $d"
    echo "$d"
}

SHOP_DIR="$(resolve_shop_dir)"

# --------------------------------------------------------------------------- #
# Docker detection — mirror the self-extracting installer's behaviour.
# When the shop is wrapped by docker-compose with a bin/composer + bin/magento
# wrapper in the project dir, run tooling through those wrappers.
# --------------------------------------------------------------------------- #
PROJECT_DIR="$SHOP_DIR"
USE_WRAPPERS=0
parent="$(dirname "$SHOP_DIR")"

if { [ -f "$parent/docker-compose.yml" ] || [ -f "$parent/docker-compose.yaml" ]; } \
   && [ -x "$parent/bin/composer" ] && [ -x "$parent/bin/magento" ]; then
    PROJECT_DIR="$parent"; USE_WRAPPERS=1
fi

# In both modes ./bin/magento in PROJECT_DIR is correct: the real binary for a
# native shop, the docker wrapper for a wrapped one.
mage() { ( cd "$PROJECT_DIR" && ./bin/magento "$@" ); }
comp() {
    if [ "$USE_WRAPPERS" = 1 ]; then ( cd "$PROJECT_DIR" && ./bin/composer "$@" )
    else ( cd "$SHOP_DIR" && composer "$@" ); fi
}

PHP_BIN="php"
command -v php >/dev/null 2>&1 || PHP_BIN=""

# Decide how DB snapshot/restore PHP runs. In docker-wrapper shops the host php
# cannot reach the container DB, so route the helper through the bin/php wrapper.
if [ "$USE_WRAPPERS" = 1 ] && [ -x "$PROJECT_DIR/bin/php" ]; then
    DB_AVAILABLE=1; DB_MODE="wrapper"
elif [ -n "$PHP_BIN" ]; then
    DB_AVAILABLE=1; DB_MODE="host"
else
    DB_AVAILABLE=0; DB_MODE="none"
fi

APPCODE_DIR="$SHOP_DIR/$APPCODE_REL"
VENDOR_DIR="$SHOP_DIR/$VENDOR_REL"
BACKUP_ROOT="$SHOP_DIR/$BACKUP_REL"

# Canonical pre-Comfino composer snapshot. Captured exactly once (on the first
# install into this shop) and NEVER overwritten afterwards. Every composer
# rollback restores from here, so no number of repeated/sequential beta installs
# can ever leave the rollback target carrying Comfino entries from an earlier run.
PRISTINE_DIR="$BACKUP_ROOT/pristine"

# --------------------------------------------------------------------------- #
# Embedded PHP helper for DB snapshot / purge (uses app/etc/env.php via PDO).
# Avoids any dependency on the mysql/mysqldump CLI being present on the host.
# --------------------------------------------------------------------------- #
PHP_HELPER=""

make_php_helper() {
    [ -n "$PHP_HELPER" ] && return 0

    if [ "$DB_MODE" = "wrapper" ]; then
        # Must live under the bind-mounted shop dir so the container can read it.
        PHP_HELPER="$SHOP_DIR/var/comfino-dbhelper.php"
    else
        PHP_HELPER="$(mktemp "${TMPDIR:-/tmp}/comfino-dbhelper.XXXXXX.php")"
    fi

    cat > "$PHP_HELPER" <<'PHP'
<?php
// Comfino install DB helper. Args: <env.php> <action> [file]
// actions: dump-config | dump-statuses | purge-config | purge-statuses | print-config
error_reporting(E_ALL & ~E_DEPRECATED);
$envFile = $argv[1] ?? '';
$action  = $argv[2] ?? '';
$file    = $argv[3] ?? '';
if (!is_file($envFile)) { fwrite(STDERR, "env.php not found: $envFile\n"); exit(2); }
$env = include $envFile;
$c = $env['db']['connection']['default'] ?? null;
if (!$c) { fwrite(STDERR, "No default DB connection in env.php\n"); exit(2); }
$prefix = $env['db']['table_prefix'] ?? '';
$host = $c['host'] ?? 'localhost'; $port = '';
if (strpos($host, ':') !== false) { [$host, $port] = explode(':', $host, 2); }
$dsn = "mysql:host={$host}" . ($port !== '' ? ";port={$port}" : '') . ';dbname=' . ($c['dbname'] ?? '') . ';charset=utf8';
try {
    $pdo = new PDO($dsn, $c['username'] ?? '', $c['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) { fwrite(STDERR, 'DB connect failed: ' . $e->getMessage() . "\n"); exit(2); }
$T = function (string $t) use ($prefix) { return $prefix . $t; };
$ccd = $T('core_config_data'); $sos = $T('sales_order_status'); $soss = $T('sales_order_status_state');

switch ($action) {
    case 'dump-config':
        $rows = $pdo->query("SELECT scope, scope_id, path, value FROM `$ccd` WHERE path LIKE 'payment/comfino/%'")->fetchAll(PDO::FETCH_ASSOC);
        file_put_contents($file, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        echo count($rows) . " config row(s)\n";
        break;
    case 'dump-statuses':
        $out = ['status' => [], 'state' => []];
        $out['status'] = $pdo->query("SELECT status, label FROM `$sos` WHERE status LIKE 'comfino%'")->fetchAll(PDO::FETCH_ASSOC);
        $out['state']  = $pdo->query("SELECT status, state, is_default, visible_on_front FROM `$soss` WHERE status LIKE 'comfino%'")->fetchAll(PDO::FETCH_ASSOC);
        file_put_contents($file, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        echo count($out['status']) . " status(es), " . count($out['state']) . " state link(s)\n";
        break;
    case 'purge-config':
        // Restore core_config_data to the pre-install snapshot: delete every
        // payment/comfino/% row, then re-insert exactly what existed before. On a
        // clean shop the snapshot is empty, so this removes all Comfino config.
        $rows = is_file($file) ? json_decode(file_get_contents($file), true) : [];
        if (!is_array($rows)) { fwrite(STDERR, "Bad config snapshot file\n"); exit(2); }
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM `$ccd` WHERE path LIKE 'payment/comfino/%'");
        if ($rows) {
            $ins = $pdo->prepare("INSERT INTO `$ccd` (scope, scope_id, path, value) VALUES (?,?,?,?)");
            foreach ($rows as $r) { $ins->execute([$r['scope'], $r['scope_id'], $r['path'], $r['value']]); }
        }
        $pdo->commit();
        echo "config purged to snapshot (" . count($rows) . " row(s) kept)\n";
        break;
    case 'purge-statuses':
        // Delete every comfino% order status that was NOT in the pre-install
        // snapshot. On a clean shop the snapshot is empty, so this removes all
        // Comfino statuses the beta created. State links go first (FK on status).
        $data = is_file($file) ? json_decode(file_get_contents($file), true) : ['status' => [], 'state' => []];
        if (!is_array($data)) { fwrite(STDERR, "Bad status snapshot file\n"); exit(2); }
        $keep = [];
        foreach (($data['status'] ?? []) as $r) { $keep[$r['status']] = true; }
        $current = $pdo->query("SELECT status FROM `$sos` WHERE status LIKE 'comfino%'")->fetchAll(PDO::FETCH_COLUMN);
        $toDelete = array_values(array_filter($current, function ($s) use ($keep) { return empty($keep[$s]); }));
        if (!$toDelete) { echo "no Comfino statuses to remove\n"; break; }
        $pdo->beginTransaction();
        $delState = $pdo->prepare("DELETE FROM `$soss` WHERE status = ?");
        $delStatus = $pdo->prepare("DELETE FROM `$sos` WHERE status = ?");
        foreach ($toDelete as $s) { $delState->execute([$s]); $delStatus->execute([$s]); }
        $pdo->commit();
        echo count($toDelete) . " Comfino status(es) removed\n";
        break;
    case 'print-config':
        $rows = $pdo->query("SELECT path, value FROM `$ccd` WHERE path LIKE 'payment/comfino/%' AND scope='default' ORDER BY path")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $v = $r['value']; if (in_array($r['path'], ['payment/comfino/api_key','payment/comfino/sandbox_api_key'], true) && $v !== null && $v !== '') { $v = substr($v, 0, 4) . '…(' . strlen($v) . ' chars)'; }
            printf("  %-48s %s\n", str_replace('payment/comfino/', '', $r['path']), $v);
        }
        echo '  (' . count($rows) . " value(s) at default scope)\n";
        break;
    default:
        fwrite(STDERR, "Unknown action: $action\n"); exit(2);
}
PHP
}

# Translate an absolute path under SHOP_DIR to a magento-root-relative path.
rel() { case "$1" in "$SHOP_DIR"/*) echo "${1#"$SHOP_DIR"/}" ;; *) echo "$1" ;; esac; }

db() {
    make_php_helper

    if [ "$DB_MODE" = "wrapper" ]; then
        local a; local -a targs=()

        for a in "$@"; do case "$a" in "$SHOP_DIR"/*) targs+=("$(rel "$a")") ;; *) targs+=("$a") ;; esac; done

        ( cd "$PROJECT_DIR" && ./bin/php "$(rel "$PHP_HELPER")" "app/etc/env.php" "${targs[@]}" )
    else
        "$PHP_BIN" "$PHP_HELPER" "$SHOP_DIR/app/etc/env.php" "$@"
    fi
}

cleanup_helper() { rm -f "$PHP_HELPER" 2>/dev/null; return 0; }
trap cleanup_helper EXIT

# --------------------------------------------------------------------------- #
# Shared helpers
# --------------------------------------------------------------------------- #
confirm() {
    [ "$ASSUME_YES" = 1 ] && return 0
    echo -en "${BOLD}$1${NC} [y/N] "
    read -r r; [[ "$r" =~ ^[yY]$ ]]
}

module_status() { mage module:status "$MODULE_NAME" 2>/dev/null || true; }

# Reports the version of the Composer-installed module, or empty when absent.
detect_installed_version() {
    local cj="$VENDOR_DIR/composer.json"

    [ -n "$PHP_BIN" ] || { [ -d "$VENDOR_DIR" ] && echo "unknown" || echo ""; return; }
    if [ -f "$cj" ]; then
        "$PHP_BIN" -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["version"]??"unknown";' "$cj" 2>/dev/null || echo "unknown"
    elif [ -d "$VENDOR_DIR" ]; then echo "unknown"
    else echo ""; fi
}

# Reports the version of a legacy app/code module, or empty if none there.
detect_appcode_version() {
    local cj="$APPCODE_DIR/composer.json"

    if [ -f "$cj" ] && [ -n "$PHP_BIN" ]; then
        "$PHP_BIN" -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["version"]??"unknown";' "$cj" 2>/dev/null || echo "unknown"
    elif [ -d "$APPCODE_DIR" ]; then echo "unknown"
    else echo ""; fi
}

# Reports the version of a package as currently pinned in the shop's composer.lock,
# or empty when the lock is missing / php unavailable / the package is not locked.
detect_locked_version() {
    local pkg="$1"

    [ -n "$PHP_BIN" ] || return 0
    [ -f "$SHOP_DIR/composer.lock" ] || return 0
    "$PHP_BIN" -r '
        $l = json_decode(file_get_contents($argv[1]), true);
        foreach (array_merge($l["packages"] ?? [], $l["packages-dev"] ?? []) as $p) {
            if (($p["name"] ?? "") === $argv[2]) { echo $p["version"]; return; }
        }' "$SHOP_DIR/composer.lock" "$pkg" 2>/dev/null || true
}

# Major version component of a "1.1.4" / "v3.0.2" style version string ("" if unknown).
version_major() { echo "$1" | sed -E 's/^[vV]//; s/[^0-9].*$//; s/^$//' | head -c 3; }

# comfino/php-sdk requires psr/log ^3.0 in betas before 2.0.0-beta5. Magento 2.4.4-2.4.6
# cap psr/log at 1.x (laminas-di, symfony/http-kernel, elasticsearch), so the install
# fails to resolve. Detect that situation and explain it in plain terms.
PSR_LOG_MIN_FIXED_BETA="2.0.0-beta5"

psr_log_too_old() {
    local locked major
    locked="$(detect_locked_version psr/log)"
    [ -n "$locked" ] || return 1
    major="$(version_major "$locked")"
    [ -n "$major" ] && [ "$major" -lt 3 ] 2>/dev/null
}

# Print a clear, actionable diagnosis after a failed `composer require` whose output
# carries the psr/log conflict signature. Best-effort: silent if it does not match.
diagnose_require_failure() {
    local log="$1"

    [ -f "$log" ] || return 0

    # Security-advisory block: Composer >= 2.8 refuses to load package versions
    # flagged by a published advisory, which can break resolution of a transitive
    # Magento-core dependency (e.g. symfony/polyfill-intl-idn) that has nothing to
    # do with Comfino. Surface the two standard escape hatches.
    if grep -qi 'affected by security advisor' "$log"; then
        local ids; ids="$(grep -oiE '(PKSA-[a-z0-9-]+|CVE-[0-9]{4}-[0-9]+)' "$log" | sort -u | tr '\n' ' ')"
        warn ""
        warn "Likely cause: a Composer security advisory is blocking a transitive dependency."
        warn "  One of Magento's core packages pulls in a version of a dependency that is"
        warn "  flagged by an advisory${ids:+ (${ids%% })}, so Composer refuses to load it."
        warn "  This is unrelated to the Comfino module's own dependencies."
        warn ""
        warn "  Resolve by either:"
        warn "    - patching the flagged package to a fixed version, e.g. add it to the"
        warn "      install command:  composer require $PACKAGE_NAME:$CONSTRAINT <pkg>:<fixed-version> -W"
        warn "    - or, if the fix is unavailable or the severity is acceptable, telling Composer"
        warn "      to ignore the advisory in the shop and re-running:"
        warn "        composer config --json policy.advisories.ignore-id '[\"<ADVISORY-ID>\"]'"
        warn "      (set policy.advisories.block to false to disable the check entirely)."
        warn ""
        return 0
    fi

    grep -qi 'psr/log' "$log" || return 0
    grep -qiE 'minimum-stability|does not match|conflicts with another require|could not be resolved' "$log" || return 0

    local locked; locked="$(detect_locked_version psr/log)"
    warn ""
    warn "Likely cause: psr/log version conflict."
    warn "  comfino/php-sdk (before ${PSR_LOG_MIN_FIXED_BETA}) requires psr/log ^3.0, but this shop is"
    warn "  pinned to psr/log ${locked:-1.x}. Magento 2.4.4-2.4.6 cap psr/log at 1.x via"
    warn "  laminas-di / symfony/http-kernel / elasticsearch, which cannot satisfy ^3.0."
    warn "  Magento 2.4.7+ ships psr/log 3.x and is unaffected."
    warn ""
    warn "  Resolve by either:"
    warn "    - upgrading this shop to Magento 2.4.7+ (psr/log 3.x is standard there), or"
    warn "    - installing a Comfino beta whose php-sdk widens psr/log to"
    warn "      '^1.1 || ^2.0 || ^3.0' (php-sdk >= ${PSR_LOG_MIN_FIXED_BETA})."
    warn ""
}

maint_was_on=0

enable_maintenance() {
    # Match "is active"/"is enabled" but NOT "is not active" — the bare word
    # "active" is a substring of "not active", so anchor on the preceding "is ".
    if mage maintenance:status 2>/dev/null | grep -qiE 'is (active|enabled)'; then maint_was_on=1; fi

    [ "$DRY_RUN" = 1 ] && { info "  (dry-run) would enable maintenance mode"; return; }
    mage maintenance:enable >/dev/null 2>&1 || warn "could not enable maintenance mode"
    ok "maintenance mode enabled"
}

restore_maintenance() {
    [ "$KEEP_MAINTENANCE" = 1 ] && { warn "leaving maintenance mode ON as requested"; return; }
    [ "$maint_was_on" = 1 ] && return  # it was already on before we started
    [ "$DRY_RUN" = 1 ] && { info "  (dry-run) would disable maintenance mode"; return; }
    mage maintenance:disable >/dev/null 2>&1 || warn "could not disable maintenance mode"
    ok "maintenance mode disabled"
}

run_setup_sequence() {
    step "Running Magento setup"

    if [ "$DRY_RUN" = 1 ]; then info "  (dry-run) would run setup:upgrade, di:compile, static-content:deploy -f, cache:flush"; return; fi

    mage setup:upgrade            || { warn "setup:upgrade failed"; return 1; }
    mage setup:di:compile         || { warn "setup:di:compile failed"; return 1; }
    mage setup:static-content:deploy -f || warn "static-content:deploy reported issues (continuing)"
    mage cache:flush              || warn "cache:flush reported issues"
    ok "setup sequence complete"
}

# Deterministic timestamp without relying on the bash date in restricted shells.
mage_now() { date +%Y%m%d-%H%M%S 2>/dev/null || echo "backup-$$"; }

# --------------------------------------------------------------------------- #
# Backup (composer files + pre-install DB snapshot)
# --------------------------------------------------------------------------- #

# True when a composer.json[.bak] already declares the Comfino package (either as
# a require or as a registered VCS repository), i.e. it is NOT a clean baseline.
composer_json_has_comfino() {
    [ -f "$1" ] && grep -q "$PACKAGE_NAME" "$1"
}

# Echo the oldest existing timestamped backup whose composer.json.bak is
# Comfino-free (a usable clean baseline), or nothing if none exists.
find_clean_backup_dir() {
    local d name
    for name in $(ls -1 "$BACKUP_ROOT" 2>/dev/null | sort); do
        d="$BACKUP_ROOT/$name"
        [ -d "$d" ] && [ "$name" != "pristine" ] || continue
        [ -f "$d/composer.json.bak" ] || continue
        composer_json_has_comfino "$d/composer.json.bak" || { echo "$d"; return; }
    done
}

# Establish the canonical pre-Comfino composer snapshot. Runs on every install
# but writes PRISTINE_DIR only the first time, and never overwrites it — so a
# second/third/Nth beta install (whose composer.json already carries Comfino)
# cannot poison the rollback target. Prefers the current shop state when it is
# still Comfino-free, then any earlier clean backup, and only as a last resort
# captures an already-Comfino state (loudly warning that it is not truly clean).
capture_pristine_composer() {
    [ -f "$PRISTINE_DIR/composer.json.bak" ] && { ok "pristine composer snapshot already established (kept)"; return 0; }
    mkdir -p "$PRISTINE_DIR"

    local src
    if ! composer_json_has_comfino "$SHOP_DIR/composer.json"; then
        cp "$SHOP_DIR/composer.json" "$PRISTINE_DIR/composer.json.bak"
        [ -f "$SHOP_DIR/composer.lock" ] && cp "$SHOP_DIR/composer.lock" "$PRISTINE_DIR/composer.lock.bak"
        [ -f "$SHOP_DIR/auth.json" ]     && cp "$SHOP_DIR/auth.json"     "$PRISTINE_DIR/auth.json.bak"
        ok "pristine (pre-Comfino) composer snapshot captured"
    elif src="$(find_clean_backup_dir)"; [ -n "$src" ]; then
        cp "$src/composer.json.bak" "$PRISTINE_DIR/composer.json.bak"
        [ -f "$src/composer.lock.bak" ] && cp "$src/composer.lock.bak" "$PRISTINE_DIR/composer.lock.bak"
        [ -f "$src/auth.json.bak" ]     && cp "$src/auth.json.bak"     "$PRISTINE_DIR/auth.json.bak"
        ok "pristine composer snapshot recovered from earlier clean backup ${src#"$BACKUP_ROOT"/}"
    else
        cp "$SHOP_DIR/composer.json" "$PRISTINE_DIR/composer.json.bak"
        [ -f "$SHOP_DIR/composer.lock" ] && cp "$SHOP_DIR/composer.lock" "$PRISTINE_DIR/composer.lock.bak"
        [ -f "$SHOP_DIR/auth.json" ]     && cp "$SHOP_DIR/auth.json"     "$PRISTINE_DIR/auth.json.bak"
        warn "no Comfino-free baseline found (shop already carried Comfino before the first run);"
        warn "  pristine snapshot captured from the current state — rollback may retain Comfino entries."
    fi
}

# Directory composer.json/lock/auth.json must be restored from on any rollback:
# always the pristine snapshot when present, else the given per-run/selected dir.
composer_restore_dir() {
    if [ -f "$PRISTINE_DIR/composer.json.bak" ]; then echo "$PRISTINE_DIR"; else echo "$1"; fi
}

create_backup() {
    local stamp ts_dir

    stamp="$(mage_now)"
    ts_dir="$BACKUP_ROOT/$stamp"
    CREATED_BACKUP_DIR="$ts_dir"
    step "Creating backup -> ${ts_dir#"$SHOP_DIR"/}"

    if [ "$DRY_RUN" = 1 ]; then info "  (dry-run) would archive composer.json/lock/auth.json + snapshot Comfino config/statuses"; return; fi

    mkdir -p "$ts_dir"
    cp "$SHOP_DIR/composer.json" "$ts_dir/composer.json.bak"
    [ -f "$SHOP_DIR/composer.lock" ] && cp "$SHOP_DIR/composer.lock" "$ts_dir/composer.lock.bak"
    [ -f "$SHOP_DIR/auth.json" ]     && cp "$SHOP_DIR/auth.json" "$ts_dir/auth.json.bak"
    ok "composer.json / lock / auth.json archived"

    # Establish (once) the canonical pre-Comfino composer snapshot used for every
    # rollback, so repeated beta installs can never poison the rollback target.
    capture_pristine_composer

    # Snapshot the pre-install Comfino DB footprint. On a clean shop these are
    # empty; the empty files give `revert --purge-data` an exact restore target.
    if [ "$DB_AVAILABLE" = 1 ]; then
        db dump-config   "$ts_dir/config-data.json"    | sed 's/^/  /' || warn "config snapshot failed"
        db dump-statuses "$ts_dir/order-statuses.json" | sed 's/^/  /' || warn "status snapshot failed"
        ok "pre-install Comfino DB footprint snapshotted"
    else
        warn "no usable PHP/DB access — DB snapshot skipped (composer backup still created)"
    fi

    cat > "$ts_dir/manifest.env" <<EOF
COMFINO_BACKUP_VERSION=1
BACKUP_TIMESTAMP=$stamp
SHOP_DIR=$SHOP_DIR
PACKAGE_NAME=$PACKAGE_NAME
CONSTRAINT=$CONSTRAINT
EOF
    ln -sfn "$stamp" "$BACKUP_ROOT/latest"
    ok "backup complete (manifest written, 'latest' updated)"
}

resolve_backup_dir() {
    local id="$1"

    if [ "$id" = "latest" ]; then
        [ -e "$BACKUP_ROOT/latest" ] || die "no backups found under ${BACKUP_ROOT#"$SHOP_DIR"/} (nothing to revert)"
        echo "$BACKUP_ROOT/$(readlink "$BACKUP_ROOT/latest" 2>/dev/null || echo latest)"
    else
        [ -d "$BACKUP_ROOT/$id" ] || die "backup id not found: $id"
        echo "$BACKUP_ROOT/$id"
    fi
}

# --------------------------------------------------------------------------- #
# Composer configuration for staging
# --------------------------------------------------------------------------- #
configure_composer_repo() {
    step "Configuring Composer for $PACKAGE_NAME ($CONSTRAINT)"

    if [ "$DRY_RUN" = 1 ]; then
        info "  (dry-run) would add VCS repos (main module + ${#SDK_REPOS[@]} SDK dependencies), set minimum-stability=beta + prefer-stable=true, and require $PACKAGE_NAME:$CONSTRAINT"; return
    fi

    if [ -n "$AUTH_TOKEN" ]; then
        # Token auth for github.com (fine-grained PAT). Stored in shop's auth.json.
        # One token for github.com covers the main repo and every SDK staging repo.
        local host; host="$(echo "$REPO_URL" | sed -E 's#https?://([^/]+)/.*#\1#')"
        comp config "http-basic.$host" "x-token-auth" "$AUTH_TOKEN" >/dev/null 2>&1 || \
            comp config --global "http-basic.$host" "x-token-auth" "$AUTH_TOKEN" >/dev/null 2>&1 || \
            warn "could not store auth token (will rely on existing credentials/deploy key)"
    fi

    comp config "repositories.comfino" vcs "$REPO_URL" || { warn "failed to register composer repository"; return 1; }
    ok "repository registered: $REPO_URL"

    # Register the transitive private SDK repos so Composer can resolve them.
    local entry key url
    for entry in "${SDK_REPOS[@]}"; do
        key="${entry%%=*}"; url="${entry#*=}"
        comp config "repositories.$key" vcs "$url" || { warn "failed to register composer repository: $url"; return 1; }
        ok "repository registered: $url"
    done

    # During the beta the SDK is only published as a beta tag (comfino/sdk-for-magento2
    # 1.0.0-beta3). Magento shops default to minimum-stability=stable, which rejects it:
    # the explicit -beta flag on the root require is an inline exception for the top
    # package ONLY and does not propagate to transitive deps. Lower the floor to beta and
    # keep prefer-stable so every other package still resolves to its newest stable tag.
    # (composer.json is snapshotted in the backup, so revert restores the prior values.)
    comp config minimum-stability beta || { warn "failed to set minimum-stability"; return 1; }
    comp config prefer-stable true     || { warn "failed to set prefer-stable"; return 1; }
    ok "stability configured (minimum-stability=beta, prefer-stable=true)"
}

require_module() {
    step "Installing $PACKAGE_NAME:$CONSTRAINT via Composer"

    if [ "$DRY_RUN" = 1 ]; then info "  (dry-run) would run: composer require $PACKAGE_NAME:$CONSTRAINT --no-interaction --with-all-dependencies"; return 0; fi

    # --with-all-dependencies (-W) lets Composer upgrade transitive packages that
    # are already pinned in the shop's lock file (e.g. psr/http-message 1.0.1 ->
    # ^1.1||^2.0 required by comfino/php-api-client). Without it a partial update
    # refuses to touch locked deps and resolution fails. Magento's own deps still
    # float within their constraints, so this only bumps packages the new module
    # genuinely requires.
    #
    # Capture combined output (still shown live via tee) so a resolution failure
    # can be translated into a clear diagnosis instead of a raw Composer dump.
    local out_log rc
    out_log="$(mktemp "${TMPDIR:-/tmp}/comfino-require.XXXXXX.log")"

    run_require() {  # extra composer args -> rc; output tee'd to $out_log
        set +e
        comp require "$PACKAGE_NAME:$CONSTRAINT" --no-interaction --no-progress --with-all-dependencies "$@" 2>&1 | tee "$out_log"
        local r=${PIPESTATUS[0]}
        set -e
        return "$r"
    }

    run_require
    rc=$?

    # Composer >= 2.8 refuses to load package versions flagged by a security
    # advisory. This can abort resolution over a CVE in one of the *shop's own*
    # locked Magento-core dependencies (e.g. symfony/polyfill-intl-idn) that has
    # nothing to do with Comfino. The module must still install on any supported
    # shop, so retry once with policy blocking disabled for this single command.
    # --no-blocking persists nothing and is scoped to this invocation only; we
    # only reach for it after a real advisory block, so the running Composer is
    # known to support the flag (older Composers never hit this branch). Fall
    # back to the deprecated --no-security-blocking name for early 2.8.x.
    if [ "$rc" -ne 0 ] && grep -qi 'affected by security advisor' "$out_log"; then
        warn ""
        warn "Composer blocked resolution over a security advisory in a pre-existing shop"
        warn "dependency (unrelated to Comfino). Retrying with policy blocking disabled for"
        warn "this command only (no change is written to the shop's composer configuration)."
        warn ""
        run_require --no-blocking
        rc=$?
        if [ "$rc" -ne 0 ] && grep -qiE 'no-blocking|not exist|not defined|unknown option' "$out_log"; then
            run_require --no-security-blocking
            rc=$?
        fi
    fi

    [ "$rc" -ne 0 ] && diagnose_require_failure "$out_log"
    rm -f "$out_log"
    return "$rc"
}

# --------------------------------------------------------------------------- #
# INSTALL
# --------------------------------------------------------------------------- #
do_install() {
    info "${BOLD}Comfino Magento 2 — clean install of ${PACKAGE_NAME} ${CONSTRAINT}${NC}"
    info "Shop:     $SHOP_DIR"
    [ "$USE_WRAPPERS" = 1 ] && info "Mode:     docker wrappers ($PROJECT_DIR)"
    info ""

    # Preflight.
    step "Preflight checks"
    [ "$DB_AVAILABLE" = 1 ] || warn "no usable PHP/DB access — DB snapshot/purge will be unavailable"

    if [ "$USE_WRAPPERS" != 1 ]; then command -v composer >/dev/null 2>&1 || die "composer is required (not found in PATH)"; fi

    # This tool targets shops with NO prior Comfino install. Detect existing
    # installs and steer the user to the right tool rather than clobbering them.
    local appcode_ver vendor_ver
    appcode_ver="$(detect_appcode_version)"
    vendor_ver="$(detect_installed_version)"

    if [ -n "$appcode_ver" ]; then
        warn "A legacy app/code Comfino module is present (version ${appcode_ver}) at $APPCODE_REL."
        warn "  This clean-install tool would not migrate its settings or order statuses."
        warn "  Use bin/comfino-beta-migrate.sh instead — it preserves and carries them over."
        [ "$FORCE" = 1 ] || die "aborted (pass --force to override, only if you know what you are doing)"
        warn "  --force given: continuing despite the legacy module."
    fi

    if [ -n "$vendor_ver" ]; then
        warn "$PACKAGE_NAME already appears installed via Composer (version ${vendor_ver})."
        confirm "Re-install / update to $PACKAGE_NAME:$CONSTRAINT?" || die "aborted by user"
    else
        ok "no prior Comfino module detected — clean install"
    fi

    # Heads-up for the known psr/log incompatibility on older Magento. Non-fatal.
    if psr_log_too_old; then
        warn "this shop is pinned to psr/log $(detect_locked_version psr/log) (Magento < 2.4.7)."
        warn "  The Comfino SDK needs php-sdk >= ${PSR_LOG_MIN_FIXED_BETA} here, which widens psr/log to"
        warn "  '^1.1 || ^2.0 || ^3.0'. Older betas require psr/log ^3.0 and will fail to resolve;"
        warn "  if the install aborts on psr/log, upgrade to Magento 2.4.7+ or use a newer beta."
    fi

    ok "preflight passed"
    info ""

    if ! confirm "Proceed with clean install of $PACKAGE_NAME:$CONSTRAINT into $SHOP_DIR?"; then
        die "aborted by user"
    fi

    info ""

    enable_maintenance
    create_backup; local backup_dir="$CREATED_BACKUP_DIR"
    info ""

    # Automatic rollback, invoked explicitly when any critical step fails. Every
    # line is guarded so a secondary error cannot abort the rollback itself.
    rollback_install() {
        [ "$DRY_RUN" = 1 ] && die "dry-run aborted"
        warn "Install failed — attempting automatic rollback from $backup_dir"
        comp remove "$PACKAGE_NAME" --no-interaction --no-progress >/dev/null 2>&1 || true
        rm -rf "$VENDOR_DIR" 2>/dev/null || true
        # Restore composer.json/lock from the pristine (pre-Comfino) snapshot, never
        # from this run's backup — on a repeat install the latter already carries
        # Comfino entries, which would survive the rollback.
        local csrc; csrc="$(composer_restore_dir "$backup_dir")"
        [ -f "$csrc/composer.json.bak" ] && cp "$csrc/composer.json.bak" "$SHOP_DIR/composer.json" || true
        [ -f "$csrc/composer.lock.bak" ] && cp "$csrc/composer.lock.bak" "$SHOP_DIR/composer.lock" || true
        # Restore the pre-install Comfino DB footprint (removes anything a partial
        # setup:upgrade may have written before the failure).
        if [ "$DB_AVAILABLE" = 1 ]; then
            [ -f "$backup_dir/config-data.json" ]    && db purge-config   "$backup_dir/config-data.json"    >/dev/null 2>&1 || true
            [ -f "$backup_dir/order-statuses.json" ] && db purge-statuses "$backup_dir/order-statuses.json" >/dev/null 2>&1 || true
        fi
        mage setup:upgrade >/dev/null 2>&1 || true
        mage cache:flush   >/dev/null 2>&1 || true
        restore_maintenance
        die "rollback finished. Shop restored to the pre-install state. Review logs and retry."
    }

    configure_composer_repo || rollback_install
    require_module          || rollback_install
    ok "composer package installed"
    info ""

    # A fresh Composer install registers the module enabled by default, but make
    # it explicit so setup:upgrade activates it on the first run.
    if [ "$DRY_RUN" != 1 ]; then
        mage module:enable "$MODULE_NAME" >/dev/null 2>&1 || true
    fi

    run_setup_sequence || rollback_install
    info ""

    # Verify.
    step "Verifying installation"

    if [ "$DRY_RUN" != 1 ]; then
        if [ -d "$VENDOR_DIR" ]; then ok "$VENDOR_REL present"; else warn "$VENDOR_REL not found"; fi
        local st; st="$(module_status)"
        echo "$st" | grep -q 'is enabled' && ok "module $MODULE_NAME is enabled" \
            || warn "module not reported enabled — check 'bin/magento module:status $MODULE_NAME'"
    fi

    restore_maintenance
    info ""
    info "${GREEN}${BOLD}✓ Installation complete.${NC}"
    info "  Backup kept at: ${backup_dir#"$SHOP_DIR"/}"
    info "  To roll back:   $0 revert \"$SHOP_DIR\""
    info "  Configure in admin: Stores → Configuration → Sales → Payment Methods → Comfino"
}

# --------------------------------------------------------------------------- #
# REVERT
# --------------------------------------------------------------------------- #
do_revert() {
    local backup_dir; backup_dir="$(resolve_backup_dir "$BACKUP_ID")"

    backup_dir="$(cd "$backup_dir" && pwd)"
    info "${BOLD}Comfino Magento 2 — revert clean install${NC}"
    info "Shop:    $SHOP_DIR"
    info "Backup:  ${backup_dir#"$SHOP_DIR"/}"
    [ "$PURGE_DATA" = 1 ] && info "Mode:    ${RED}--purge-data (will remove Comfino config + order statuses)${NC}"
    info ""

    if [ "$PURGE_DATA" = 1 ]; then
        warn "--purge-data will DELETE Comfino config and any order statuses created by the beta."
        warn "  Do NOT use this if real orders were placed during testing — their status would break."
    fi

    if ! confirm "Remove $PACKAGE_NAME and restore composer.json/lock from this backup?"; then
        die "aborted by user"
    fi

    info ""

    enable_maintenance

    # Disable the module before removing files so its config entry is clean.
    # NEVER module:uninstall — that triggers Setup/Uninstall.php which would delete
    # order statuses unconditionally. We control DB cleanup via --purge-data instead.
    step "Disabling $MODULE_NAME"

    if [ "$DRY_RUN" = 1 ]; then info "  (dry-run) would: module:disable $MODULE_NAME"
    elif module_status | grep -q 'is enabled'; then
        mage module:disable "$MODULE_NAME" >/dev/null 2>&1 && ok "module disabled" || warn "module:disable returned non-zero"
    else
        ok "module already disabled or not registered"
    fi

    info ""

    # Remove the Composer-installed package (composer remove does NOT trigger
    # Magento's data uninstall — only module:uninstall does, which we never call).
    step "Removing Composer package $PACKAGE_NAME"

    if [ "$DRY_RUN" = 1 ]; then info "  (dry-run) would composer remove $PACKAGE_NAME"
    else
        comp remove "$PACKAGE_NAME" --no-interaction --no-progress >/dev/null 2>&1 \
            && ok "composer package removed" \
            || warn "composer remove failed — will restore composer.json from backup instead"
        rm -rf "$VENDOR_DIR" 2>/dev/null || true
    fi

    # Restore composer.json/lock/auth.json so the package definition + repos +
    # stability settings match the pre-install state. Source these from the
    # pristine (pre-Comfino) snapshot when available, so reverting after several
    # sequential beta installs always lands on the original Comfino-free setup
    # rather than a backup that captured an earlier beta's composer entries.
    if [ "$DRY_RUN" != 1 ]; then
        local csrc; csrc="$(composer_restore_dir "$backup_dir")"
        [ "$csrc" = "$PRISTINE_DIR" ] && info "  restoring composer setup from pristine (pre-Comfino) snapshot"
        [ -f "$csrc/composer.json.bak" ] && cp "$csrc/composer.json.bak" "$SHOP_DIR/composer.json" && ok "composer.json restored"
        [ -f "$csrc/composer.lock.bak" ] && cp "$csrc/composer.lock.bak" "$SHOP_DIR/composer.lock" || true
        [ -f "$csrc/auth.json.bak" ]     && cp "$csrc/auth.json.bak" "$SHOP_DIR/auth.json" || true
    fi

    info ""

    # Purge leftover generated/static artefacts for the module.
    if [ "$DRY_RUN" != 1 ]; then
        find "$SHOP_DIR/pub/static" -path "*/${MODULE_NAME}*" -not -path "*/.*" -prune -exec rm -rf {} + 2>/dev/null || true
        rm -rf "$SHOP_DIR/generated/code/Comfino" "$SHOP_DIR/generated/metadata" 2>/dev/null || true
    fi

    # Optional DB cleanup: restore the pre-install Comfino footprint.
    if [ "$PURGE_DATA" = 1 ]; then
        step "Purging Comfino DB data (restoring pre-install snapshot)"

        if [ "$DRY_RUN" = 1 ]; then info "  (dry-run) would restore config-data.json and order-statuses.json snapshots"
        elif [ "$DB_AVAILABLE" = 1 ]; then
            [ -f "$backup_dir/config-data.json" ]    && db purge-config   "$backup_dir/config-data.json"    | sed 's/^/  /' || warn "config purge skipped"
            [ -f "$backup_dir/order-statuses.json" ] && db purge-statuses "$backup_dir/order-statuses.json" | sed 's/^/  /' || warn "status purge skipped"
            ok "Comfino DB data purged to pre-install snapshot"
        else
            warn "no usable PHP/DB access — DB purge skipped; remove Comfino config/statuses manually if needed"
        fi
        info ""
    fi

    run_setup_sequence || warn "setup reported errors during revert — run 'bin/magento setup:upgrade' manually"

    info ""
    restore_maintenance
    info ""
    info "${GREEN}${BOLD}✓ Revert complete.${NC} $PACKAGE_NAME has been removed from the shop."
    if [ "$PURGE_DATA" != 1 ]; then
        info "  Comfino config + order statuses were left in the DB (safe). Re-run with --purge-data to remove them."
    fi
}

# --------------------------------------------------------------------------- #
# STATUS
# --------------------------------------------------------------------------- #
do_status() {
    info "${BOLD}Comfino clean-install status${NC}"
    info "Shop:    $SHOP_DIR"
    [ "$USE_WRAPPERS" = 1 ] && info "Mode:    docker wrappers ($PROJECT_DIR)"
    info ""
    step "Module"
    local av; av="$(detect_appcode_version)"

    if [ -n "$av" ]; then info "  legacy app/code module: present (version ${av}) — use comfino-beta-migrate.sh"; else info "  legacy app/code module: not present"; fi

    local cv; cv="$(detect_installed_version)"
    [ -n "$cv" ] \
        && info "  composer vendor module ($PACKAGE_NAME): present (version ${cv})" \
        || info "  composer vendor module ($PACKAGE_NAME): not installed"
    info "  $(module_status | head -n1)"
    info ""
    step "Current Comfino settings (default scope)"

    if [ "$DB_AVAILABLE" = 1 ]; then db print-config || warn "could not read settings from DB"; else warn "no usable PHP/DB access"; fi

    info ""
    step "Available backups (${BACKUP_REL})"

    if [ -d "$BACKUP_ROOT" ]; then
        local found=0 entry

        for entry in "$BACKUP_ROOT"/*/; do
            [ -d "$entry" ] || continue
            entry="${entry%/}"
            case "$(basename "$entry")" in latest|pristine) continue ;; esac
            info "  $(basename "$entry")"; found=1
        done

        [ "$found" = 0 ] && info "  (none)"
        [ -L "$BACKUP_ROOT/latest" ] && info "  latest -> $(readlink "$BACKUP_ROOT/latest")"
        [ -f "$PRISTINE_DIR/composer.json.bak" ] && info "  pristine (pre-Comfino composer snapshot, used for all rollbacks)"
    else
        info "  (none)"
    fi
}

# --------------------------------------------------------------------------- #
# Dispatch
# --------------------------------------------------------------------------- #
case "$ACTION" in
    install) do_install ;;
    revert)  do_revert ;;
    status)  do_status ;;
    *)       die "unknown action '$ACTION' (use install | revert | status | help)" ;;
esac
