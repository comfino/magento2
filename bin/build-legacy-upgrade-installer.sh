#!/bin/bash
# Build a self-extracting upgrade installer for the legacy Comfino 3.1.0 module.
# Creates a standalone shell script that embeds the 3.1.0 module and can upgrade
# a Magento shop currently running a 2.x app/code Comfino module.
#
# The generated installer also supports reverting to the previous 2.x module
# using the backup it creates before touching anything.
#
# Usage: bin/build-legacy-upgrade-installer.sh [OUTPUT_DIR] [SOURCE_DIR]
#   OUTPUT_DIR  Where to write the installer (default: ../magento2-legacy-dist)
#   SOURCE_DIR  Path to the 3.1.0 source repo (default: ../../Magento-2.3)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODULE_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

OUTPUT_DIR="${1:-$MODULE_DIR/../magento2-legacy-dist}"
SOURCE_DIR="${2:-$MODULE_DIR/../Magento-2.3}"

if [ ! -d "$SOURCE_DIR" ]; then
    echo "Error: 3.1.0 source directory not found: $SOURCE_DIR" >&2
    echo "Pass SOURCE_DIR as the second argument, or ensure Magento-2.3 repo is adjacent to this repo." >&2
    exit 1
fi

if [ ! -f "$SOURCE_DIR/composer.json" ]; then
    echo "Error: $SOURCE_DIR does not look like a Comfino module (no composer.json)" >&2
    exit 1
fi

SOURCE_DIR="$(cd "$SOURCE_DIR" && pwd)"
mkdir -p "$OUTPUT_DIR"
OUTPUT_DIR="$(cd "$OUTPUT_DIR" && pwd)"

MODULE_VERSION="$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["version"]??"3.1.0";' "$SOURCE_DIR/composer.json" 2>/dev/null || echo "3.1.0")"

INSTALLER_NAME="install-comfino-${MODULE_VERSION}-from-2x.sh"
INSTALLER_PATH="$OUTPUT_DIR/$INSTALLER_NAME"

echo "Building legacy upgrade installer..."
echo "  Version:         $MODULE_VERSION"
echo "  Source:          $SOURCE_DIR"
echo "  Output:          $INSTALLER_PATH"
echo ""

TMP_PKG_DIR="$(mktemp -d)"
trap "rm -rf '$TMP_PKG_DIR'" EXIT

MODULE_COPY_DIR="$TMP_PKG_DIR/comfino-module"
mkdir -p "$MODULE_COPY_DIR"

echo "Packaging 3.1.0 module (git ls-files, excluding dev files)..."

# Files to exclude from distribution.
EXCLUDE_PATTERNS=(
    "^bin/"
    "^docker/"
    "^docker-compose\.yml$"
    "^tests/"
    "^phpunit\.xml"
    "^composer\.lock$"
    "^\.claude/"
    "^CLAUDE\.md$"
)

cd "$SOURCE_DIR"

git ls-files --exclude-standard | while IFS= read -r file; do
    [ -f "$file" ] || continue

    skip=0
    for pat in "${EXCLUDE_PATTERNS[@]}"; do
        if echo "$file" | grep -qE "$pat"; then
            skip=1
            break
        fi
    done
    [ "$skip" = 1 ] && continue

    dst_file="$MODULE_COPY_DIR/$file"
    mkdir -p "$(dirname "$dst_file")"
    cp "$file" "$dst_file"
done

echo "  $(find "$MODULE_COPY_DIR" -type f | wc -l | tr -d ' ') files packaged"

echo "Creating payload archive..."
tar -czf "$TMP_PKG_DIR/module.tar.gz" -C "$TMP_PKG_DIR" comfino-module

PAYLOAD_SIZE=$(stat -c%s "$TMP_PKG_DIR/module.tar.gz" 2>/dev/null || stat -f%z "$TMP_PKG_DIR/module.tar.gz")
if command -v numfmt >/dev/null 2>&1; then
    SIZE_FMT=$(numfmt --to=iec "$PAYLOAD_SIZE")
else
    SIZE_FMT="$PAYLOAD_SIZE bytes"
fi
echo "  Payload size: $SIZE_FMT"

PAYLOAD_B64=$(base64 < "$TMP_PKG_DIR/module.tar.gz" | fold -w 76)

TEMP_INSTALLER="$(mktemp)"

cat > "$TEMP_INSTALLER" << 'INSTALLER_EOF'
#!/bin/bash
# Comfino Magento 2 — Self-Extracting Legacy Upgrade Installer
# Upgrades a Magento shop running the 2.x app/code Comfino module to 3.1.0.
#
# Usage:
#   ./INSTALLER_NAME upgrade [SHOP_DIR] [options]   # default action
#   ./INSTALLER_NAME revert  [SHOP_DIR] [options]
#   ./INSTALLER_NAME status  [SHOP_DIR]
#   ./INSTALLER_NAME help
#
# Options:
#   -y, --yes             do not prompt for confirmation (fully unattended)
#   --dry-run             print what would happen, change nothing
#   --keep-maintenance    leave maintenance mode on at the end
#   --backup-id ID        revert: which backup to restore (default: latest)
#
# Why it is safe:
#   * Never calls module:uninstall — that would wipe Comfino order statuses.
#   * Settings in core_config_data are preserved (same XML paths across 2.x/3.x).
#   * Before touching anything, archives the old module dir and snapshots DB rows.
#   * revert fully restores files + DB to the pre-upgrade state.
#
# Requirements:
#   bash, tar, base64, php (>=7.4)

set -euo pipefail

MODULE_VERSION="MODULE_VERSION_PLACEHOLDER"
MODULE_NAME="Comfino_ComfinoGateway"
APPCODE_REL="app/code/Comfino/ComfinoGateway"
BACKUP_REL="var/comfino-legacy-upgrade-backup"

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
BACKUP_ID="latest"
ASSUME_YES=0
DRY_RUN=0
KEEP_MAINTENANCE=0

while [ $# -gt 0 ]; do
    case "$1" in
        upgrade|revert|status|help|--help|-h)
            if [ -z "$ACTION" ]; then
                case "$1" in --help|-h) ACTION="help" ;; *) ACTION="$1" ;; esac
            fi
            ;;
        --backup-id)    BACKUP_ID="${2:-}"; shift ;;
        --backup-id=*)  BACKUP_ID="${1#*=}" ;;
        -y|--yes)       ASSUME_YES=1 ;;
        --dry-run)      DRY_RUN=1 ;;
        --keep-maintenance) KEEP_MAINTENANCE=1 ;;
        -*) die "Unknown option: $1 (run 'help' for usage)" ;;
        *)  [ -z "$SHOP_DIR_ARG" ] && SHOP_DIR_ARG="$1" || die "Unexpected argument: $1" ;;
    esac
    shift
done

[ -z "$ACTION" ] && ACTION="upgrade"

# --------------------------------------------------------------------------- #
# Help
# --------------------------------------------------------------------------- #
if [ "$ACTION" = "help" ]; then
    sed -n '3,25p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    exit 0
fi

# --------------------------------------------------------------------------- #
# Resolve shop directory
# --------------------------------------------------------------------------- #
resolve_shop_dir() {
    local d="${SHOP_DIR_ARG:-${COMFINO_TESTSHOP_PATH:-${TESTSHOP_PATH:-}}}"

    if [ -z "$d" ]; then
        if   [ -f "./bin/magento" ] && [ -f "./app/etc/env.php" ]; then d="$(pwd)"
        elif [ -f "./magento/bin/magento" ] && [ -f "./magento/app/etc/env.php" ]; then d="$(pwd)/magento"
        fi
    fi

    [ -z "$d" ] && die "Magento shop directory not set. Pass it as an argument, or set COMFINO_TESTSHOP_PATH."
    [ -d "$d" ] || die "Shop directory does not exist: $d"
    d="$(cd "$d" && pwd)"
    [ -f "$d/bin/magento" ] || die "Not a Magento root (bin/magento missing): $d"
    [ -f "$d/app/etc/env.php" ] || die "Magento not installed (app/etc/env.php missing): $d"
    echo "$d"
}

SHOP_DIR="$(resolve_shop_dir)"
APPCODE_DIR="$SHOP_DIR/$APPCODE_REL"
BACKUP_ROOT="$SHOP_DIR/$BACKUP_REL"

# --------------------------------------------------------------------------- #
# Docker detection — if shop is wrapped by docker-compose with bin/magento,
# run tooling through those wrappers.
# --------------------------------------------------------------------------- #
PROJECT_DIR="$SHOP_DIR"
USE_WRAPPERS=0
_parent="$(dirname "$SHOP_DIR")"

if { [ -f "$_parent/docker-compose.yml" ] || [ -f "$_parent/docker-compose.yaml" ]; } \
   && [ -x "$_parent/bin/magento" ]; then
    PROJECT_DIR="$_parent"; USE_WRAPPERS=1
fi
unset _parent

mage() {
    if [ "$USE_WRAPPERS" = 0 ] && [ -n "$PHP_BIN" ] && ! command -v php >/dev/null 2>&1; then
        ( cd "$PROJECT_DIR" && "$PHP_BIN" bin/magento "$@" )
    else
        ( cd "$PROJECT_DIR" && ./bin/magento "$@" )
    fi
}

PHP_BIN="${COMFINO_PHP_BIN:-}"
if [ -z "$PHP_BIN" ]; then
    for _candidate in php php8.3 php8.2 php8.1 php8.0 php7.4; do
        if command -v "$_candidate" >/dev/null 2>&1; then PHP_BIN="$_candidate"; break; fi
    done
    unset _candidate
fi

if [ "$USE_WRAPPERS" = 1 ] && [ -x "$PROJECT_DIR/bin/php" ]; then
    DB_AVAILABLE=1; DB_MODE="wrapper"
elif [ -n "$PHP_BIN" ]; then
    DB_AVAILABLE=1; DB_MODE="host"
else
    DB_AVAILABLE=0; DB_MODE="none"
fi

# --------------------------------------------------------------------------- #
# Embedded PHP helper for DB snapshot / restore (connects via app/etc/env.php).
# --------------------------------------------------------------------------- #
PHP_HELPER=""

make_php_helper() {
    [ -n "$PHP_HELPER" ] && return 0

    if [ "$DB_MODE" = "wrapper" ]; then
        PHP_HELPER="$SHOP_DIR/var/comfino-dbhelper.php"
    else
        PHP_HELPER="$(mktemp "${TMPDIR:-/tmp}/comfino-dbhelper.XXXXXX.php")"
    fi

    cat > "$PHP_HELPER" <<'PHP'
<?php
// Comfino legacy upgrade DB helper. Args: <env.php> <action> [file]
// actions: dump-config | restore-config | dump-statuses | restore-statuses
//          dump-application | restore-application | fixup-config | print-config
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
$T = fn(string $t) => $prefix . $t;
$ccd  = $T('core_config_data');
$sos  = $T('sales_order_status');
$soss = $T('sales_order_status_state');
$app  = $T('comfino_application');

switch ($action) {
    case 'dump-config':
        $rows = $pdo->query("SELECT scope, scope_id, path, value FROM `$ccd` WHERE path LIKE 'payment/comfino/%'")->fetchAll(PDO::FETCH_ASSOC);
        file_put_contents($file, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        echo count($rows) . " config rows\n";
        break;

    case 'restore-config':
        $rows = json_decode(file_get_contents($file), true);
        if (!is_array($rows)) { fwrite(STDERR, "Bad config backup\n"); exit(2); }
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM `$ccd` WHERE path LIKE 'payment/comfino/%'");
        $ins = $pdo->prepare("INSERT INTO `$ccd` (scope, scope_id, path, value) VALUES (?,?,?,?)");
        foreach ($rows as $r) { $ins->execute([$r['scope'], $r['scope_id'], $r['path'], $r['value']]); }
        $pdo->commit();
        echo count($rows) . " config rows restored\n";
        break;

    case 'dump-statuses':
        $out = ['status' => [], 'state' => []];
        $out['status'] = $pdo->query("SELECT status, label FROM `$sos` WHERE status LIKE 'comfino%'")->fetchAll(PDO::FETCH_ASSOC);
        $out['state']  = $pdo->query("SELECT status, state, is_default, visible_on_front FROM `$soss` WHERE status LIKE 'comfino%'")->fetchAll(PDO::FETCH_ASSOC);
        file_put_contents($file, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        echo count($out['status']) . " statuses, " . count($out['state']) . " state links\n";
        break;

    case 'restore-statuses':
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) { fwrite(STDERR, "Bad status backup\n"); exit(2); }
        $pdo->beginTransaction();
        $s1 = $pdo->prepare("INSERT INTO `$sos` (status, label) VALUES (?,?) ON DUPLICATE KEY UPDATE label=VALUES(label)");
        foreach (($data['status'] ?? []) as $r) { $s1->execute([$r['status'], $r['label']]); }
        $s2 = $pdo->prepare("INSERT INTO `$soss` (status, state, is_default, visible_on_front) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE is_default=VALUES(is_default), visible_on_front=VALUES(visible_on_front)");
        foreach (($data['state'] ?? []) as $r) { $s2->execute([$r['status'], $r['state'], $r['is_default'], $r['visible_on_front']]); }
        $pdo->commit();
        echo count($data['status'] ?? []) . " statuses, " . count($data['state'] ?? []) . " state links restored\n";
        break;

    case 'dump-application':
        // Backs up comfino_application rows (2.x custom table).
        // The table stays in the DB after upgrade (3.1.0 has an empty db_schema.xml,
        // so Magento will not drop it in safe mode), but snapshot it for safety.
        $rows = [];
        try {
            $rows = $pdo->query("SELECT status, external_id, redirect_uri, href, order_id, created_at, updated_at FROM `$app`")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // Table already absent — treat as empty.
        }
        file_put_contents($file, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        echo count($rows) . " application row(s)\n";
        break;

    case 'restore-application':
        $rows = json_decode(file_get_contents($file), true);
        if (!is_array($rows)) { fwrite(STDERR, "Bad application backup\n"); exit(2); }
        if (empty($rows)) { echo "0 application rows (nothing to restore)\n"; break; }
        try {
            $ins = $pdo->prepare("INSERT IGNORE INTO `$app` (status, external_id, redirect_uri, href, order_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?)");
            $pdo->beginTransaction();
            foreach ($rows as $r) { $ins->execute([$r['status'], $r['external_id'], $r['redirect_uri'], $r['href'], $r['order_id'], $r['created_at'], $r['updated_at']]); }
            $pdo->commit();
            echo count($rows) . " application row(s) restored\n";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            fwrite(STDERR, "Could not restore application rows: " . $e->getMessage() . "\n");
            exit(1);
        }
        break;

    case 'fixup-config':
        $fixed = 0;
        // Clear widget_code if it still contains the 2.x ComfinoProductWidget.init() template.
        // 3.1.0 generates its own widget initialisation code at runtime.
        $row = $pdo->query("SELECT config_id, value FROM `$ccd` WHERE path = 'payment/comfino/widget_code' AND scope = 'default' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row && strpos($row['value'] ?? '', 'ComfinoProductWidget.init') !== false) {
            $pdo->prepare("UPDATE `$ccd` SET value = '' WHERE config_id = ?")->execute([$row['config_id']]);
            echo "  cleared widget_code (contained legacy 2.x ComfinoProductWidget.init template)\n"; $fixed++;
        }
        // Reset widget_type='error' — written by a failed API sync; causes the widget script
        // endpoint to return an empty response and the banner to silently disappear.
        $stmt = $pdo->prepare("UPDATE `$ccd` SET value = 'standard' WHERE path = 'payment/comfino/widget_type' AND value = 'error'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) { echo "  reset widget_type from 'error' to 'standard'\n"; $fixed++; }
        // Remove 2.x-only paths that have no meaning in 3.x.
        $legacy = [
            'payment/comfino/instructions',
            'payment/comfino/allowspecific',
            'payment/comfino/specificcountry',
            'payment/comfino/tax_id',
        ];
        foreach ($legacy as $path) {
            $stmt = $pdo->prepare("DELETE FROM `$ccd` WHERE path = ?");
            $stmt->execute([$path]);
            if ($stmt->rowCount() > 0) { echo "  removed orphaned 2.x path: $path\n"; $fixed++; }
        }
        echo ($fixed > 0 ? "  $fixed fix-up(s) applied" : "  nothing to fix up") . "\n";
        break;

    case 'print-config':
        $rows = $pdo->query("SELECT path, value FROM `$ccd` WHERE path LIKE 'payment/comfino/%' AND scope='default' ORDER BY path")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $v = $r['value'];
            if (in_array($r['path'], ['payment/comfino/api_key', 'payment/comfino/sandbox_api_key'], true) && $v !== null && $v !== '') {
                $v = substr($v, 0, 4) . '…(' . strlen($v) . ' chars)';
            }
            printf("  %-48s %s\n", str_replace('payment/comfino/', '', $r['path']), $v);
        }
        echo '  (' . count($rows) . " values at default scope)\n";
        break;

    default:
        fwrite(STDERR, "Unknown action: $action\n"); exit(2);
}
PHP
}

# Translate a SHOP_DIR-absolute path to a magento-root-relative path (for docker wrapper mode).
rel() { case "$1" in "$SHOP_DIR"/*) echo "${1#"$SHOP_DIR"/}" ;; *) echo "$1" ;; esac; }

db() {
    make_php_helper

    if [ "$DB_MODE" = "wrapper" ]; then
        local a; local -a targs=()
        for a in "$@"; do
            case "$a" in "$SHOP_DIR"/*) targs+=("$(rel "$a")") ;; *) targs+=("$a") ;; esac
        done
        ( cd "$PROJECT_DIR" && ./bin/php "$(rel "$PHP_HELPER")" "app/etc/env.php" "${targs[@]}" )
    else
        "$PHP_BIN" "$PHP_HELPER" "$SHOP_DIR/app/etc/env.php" "$@"
    fi
}

cleanup_helper() { [ -n "$PHP_HELPER" ] && rm -f "$PHP_HELPER" || true; }
trap cleanup_helper EXIT

# --------------------------------------------------------------------------- #
# Shared helpers
# --------------------------------------------------------------------------- #
mage_now() { date +%Y%m%d-%H%M%S 2>/dev/null || echo "backup-$$"; }

confirm() {
    [ "$ASSUME_YES" = 1 ] && return 0
    echo -en "${BOLD}$1${NC} [y/N] "
    read -r r; [[ "$r" =~ ^[yY]$ ]]
}

detect_installed_version() {
    local cj="$APPCODE_DIR/composer.json"
    if [ -f "$cj" ] && [ -n "$PHP_BIN" ]; then
        "$PHP_BIN" -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["version"]??"unknown";' "$cj" 2>/dev/null || echo "unknown"
    elif [ -d "$APPCODE_DIR" ]; then echo "unknown"
    else echo ""
    fi
}

module_status() { mage module:status "$MODULE_NAME" 2>/dev/null || true; }

maint_was_on=0

enable_maintenance() {
    if mage maintenance:status 2>/dev/null | grep -qiE 'is (active|enabled)'; then maint_was_on=1; fi
    [ "$DRY_RUN" = 1 ] && { info "  (dry-run) would enable maintenance mode"; return; }
    mage maintenance:enable >/dev/null 2>&1 || warn "could not enable maintenance mode"
    ok "maintenance mode enabled"
}

restore_maintenance() {
    [ "$KEEP_MAINTENANCE" = 1 ] && { warn "leaving maintenance mode ON as requested"; return; }
    [ "$maint_was_on" = 1 ] && return
    [ "$DRY_RUN" = 1 ] && { info "  (dry-run) would disable maintenance mode"; return; }
    mage maintenance:disable >/dev/null 2>&1 || warn "could not disable maintenance mode"
    ok "maintenance mode disabled"
}

run_setup_sequence() {
    step "Running Magento setup"
    if [ "$DRY_RUN" = 1 ]; then
        info "  (dry-run) would run: setup:upgrade, setup:di:compile, setup:static-content:deploy -f, cache:flush"
        return
    fi
    mage setup:upgrade                        || { warn "setup:upgrade failed"; return 1; }
    mage setup:di:compile                     || { warn "setup:di:compile failed"; return 1; }
    mage setup:static-content:deploy -f       || warn "static-content:deploy reported issues (continuing)"
    mage cache:flush                          || warn "cache:flush reported issues"
    ok "setup sequence complete"
}

# --------------------------------------------------------------------------- #
# Payload extraction (lazy — only called when needed for upgrade)
# --------------------------------------------------------------------------- #
EXTRACT_DIR=""

extract_payload() {
    [ -n "$EXTRACT_DIR" ] && return 0

    EXTRACT_DIR="$(mktemp -d)" || die "Failed to create temporary directory"

    cleanup_extract() {
        rm -rf "$EXTRACT_DIR"
        cleanup_helper
    }
    trap cleanup_extract EXIT

    info "Extracting embedded module package..."

    sed -n '/^__PAYLOAD_START__$/,/^__PAYLOAD_END__$/p' "$0" | \
        sed '1d;$d' | \
        base64 -d | \
        tar -xz -C "$EXTRACT_DIR" || die "Failed to extract embedded payload"

    [ -d "$EXTRACT_DIR/comfino-module" ] || die "Module directory not found in payload"
    ok "Module extracted ($(find "$EXTRACT_DIR/comfino-module" -type f | wc -l | tr -d ' ') files)"
    info ""
}

# --------------------------------------------------------------------------- #
# Backup
# --------------------------------------------------------------------------- #
CREATED_BACKUP_DIR=""

create_backup() {
    local old_ver="$1"
    local stamp ts_dir
    stamp="$(mage_now)"
    ts_dir="$BACKUP_ROOT/$stamp"
    CREATED_BACKUP_DIR="$ts_dir"
    step "Creating backup -> ${ts_dir#"$SHOP_DIR"/}"

    if [ "$DRY_RUN" = 1 ]; then
        info "  (dry-run) would archive module files + dump config/statuses/application-table"
        return
    fi

    mkdir -p "$ts_dir"

    if [ -d "$APPCODE_DIR" ]; then
        tar -czf "$ts_dir/module-appcode.tar.gz" -C "$(dirname "$APPCODE_DIR")" "$(basename "$APPCODE_DIR")"
        ok "module archived ($(du -h "$ts_dir/module-appcode.tar.gz" | cut -f1))"
    else
        warn "no app/code module dir to archive"
    fi

    if [ "$DB_AVAILABLE" = 1 ]; then
        db dump-config      "$ts_dir/config-data.json"      | sed 's/^/  /' || warn "config dump failed"
        db dump-statuses    "$ts_dir/order-statuses.json"   | sed 's/^/  /' || warn "status dump failed"
        db dump-application "$ts_dir/application-data.json" | sed 's/^/  /' || warn "application dump failed"
        ok "database settings snapshotted"
    else
        warn "no usable PHP/DB access — DB snapshot skipped (file backup still created)"
    fi

    cat > "$ts_dir/manifest.env" <<EOF
COMFINO_BACKUP_VERSION=1
OLD_MODULE_VERSION=$old_ver
BACKUP_TIMESTAMP=$stamp
SHOP_DIR=$SHOP_DIR
MODULE_NAME=$MODULE_NAME
HAD_APPCODE=$([ -d "$APPCODE_DIR" ] && echo 1 || echo 0)
EOF
    ln -sfn "$stamp" "$BACKUP_ROOT/latest"
    ok "backup complete ('latest' symlink updated)"
}

resolve_backup_dir() {
    local id="$1"
    if [ "$id" = "latest" ]; then
        [ -e "$BACKUP_ROOT/latest" ] || die "no backups found under ${BACKUP_ROOT#$SHOP_DIR/} (nothing to revert)"
        echo "$BACKUP_ROOT/$(readlink "$BACKUP_ROOT/latest" 2>/dev/null || echo latest)"
    else
        [ -d "$BACKUP_ROOT/$id" ] || die "backup id not found: $id"
        echo "$BACKUP_ROOT/$id"
    fi
}

restore_appcode_from_backup() {
    local backup_dir="$1"
    local tarball="$backup_dir/module-appcode.tar.gz"
    [ -f "$tarball" ] || die "backup tarball not found: $tarball"
    step "Restoring 2.x module files from backup"
    if [ "$DRY_RUN" = 1 ]; then info "  (dry-run) would extract $tarball"; return; fi
    rm -rf "$APPCODE_DIR"
    mkdir -p "$(dirname "$APPCODE_DIR")"
    tar -xzf "$tarball" -C "$(dirname "$APPCODE_DIR")"
    ok "module files restored to $APPCODE_REL"
    mage module:enable "$MODULE_NAME" >/dev/null 2>&1 || true
}

# --------------------------------------------------------------------------- #
# UPGRADE
# --------------------------------------------------------------------------- #
do_upgrade() {
    info "${BOLD}Comfino Magento 2 — upgrade 2.x → $MODULE_VERSION${NC}"
    info "Shop:     $SHOP_DIR"
    [ "$USE_WRAPPERS" = 1 ] && info "Mode:     docker wrappers ($PROJECT_DIR)"
    info ""

    step "Preflight checks"
    command -v tar    >/dev/null 2>&1 || die "tar is required"
    command -v base64 >/dev/null 2>&1 || die "base64 is required"
    [ "$DB_AVAILABLE" = 1 ] || warn "no usable PHP/DB access — DB snapshot/restore will be unavailable"

    local old_ver; old_ver="$(detect_installed_version)"

    if [ -z "$old_ver" ]; then
        warn "No app/code Comfino module detected at $APPCODE_REL."
        warn "If the module is already at 3.x or 4.x, this upgrade is not needed."
        confirm "Continue anyway?" || die "aborted by user"
    else
        ok "legacy module detected: version ${old_ver:-unknown}"
        if [ "$old_ver" != "unknown" ]; then
            local major; major="$(echo "$old_ver" | sed 's/\..*//')"
            if [ "$major" -ge 3 ] 2>/dev/null; then
                warn "Detected version ($old_ver) is already 3.x or newer — this installer upgrades 2.x shops only."
                confirm "Continue anyway?" || die "aborted by user"
            fi
        fi
    fi

    ok "preflight passed"
    info ""

    if ! confirm "Upgrade $SHOP_DIR to Comfino $MODULE_VERSION?"; then
        die "aborted by user"
    fi

    info ""

    enable_maintenance
    create_backup "${old_ver:-unknown}"
    local backup_dir="$CREATED_BACKUP_DIR"
    info ""

    # Automatic rollback on critical failure.
    rollback_upgrade() {
        [ "$DRY_RUN" = 1 ] && die "dry-run aborted"
        warn "Upgrade failed — attempting automatic rollback"
        if [ -f "$backup_dir/module-appcode.tar.gz" ]; then
            rm -rf "$APPCODE_DIR"
            mkdir -p "$(dirname "$APPCODE_DIR")"
            tar -xzf "$backup_dir/module-appcode.tar.gz" -C "$(dirname "$APPCODE_DIR")" 2>/dev/null || \
                warn "could not restore module files; run: $0 revert"
            mage module:enable "$MODULE_NAME" >/dev/null 2>&1 || true
        fi
        [ -f "$backup_dir/config-data.json" ] && \
            db restore-config "$backup_dir/config-data.json" >/dev/null 2>&1 || true
        mage setup:upgrade >/dev/null 2>&1 || true
        mage cache:flush   >/dev/null 2>&1 || true
        restore_maintenance
        die "Rollback finished. Shop restored to the previous module. Review logs and retry."
    }

    # Extract payload and install new module files.
    extract_payload || rollback_upgrade

    step "Installing Comfino $MODULE_VERSION module files"

    if [ "$DRY_RUN" = 1 ]; then
        info "  (dry-run) would: disable module, delete $APPCODE_REL, copy 3.1.0 files"
    else
        if module_status | grep -q 'is enabled'; then
            mage module:disable "$MODULE_NAME" >/dev/null 2>&1 || warn "module:disable returned non-zero"
            ok "module disabled"
        fi

        rm -rf "$APPCODE_DIR"
        mkdir -p "$(dirname "$APPCODE_DIR")"
        cp -r "$EXTRACT_DIR/comfino-module" "$APPCODE_DIR" || rollback_upgrade
        ok "module files installed to $APPCODE_REL"

        mage module:enable "$MODULE_NAME" >/dev/null 2>&1 || warn "module:enable returned non-zero"
        ok "module enabled"

        # Purge stale generated artefacts from the previous version.
        find "$SHOP_DIR/pub/static" -path "*/${MODULE_NAME}*" -not -path "*/.*" -prune -exec rm -rf {} + 2>/dev/null || true
        rm -rf "$SHOP_DIR/generated/code/Comfino" "$SHOP_DIR/generated/metadata" 2>/dev/null || true
        ok "generated artefacts cleared"
    fi

    info ""

    run_setup_sequence || rollback_upgrade
    info ""

    step "Applying configuration fix-ups"
    if [ "$DRY_RUN" = 1 ]; then
        info "  (dry-run) would: clear legacy widget_code; reset widget_type='error'; remove orphaned 2.x config paths"
    elif [ "$DB_AVAILABLE" = 1 ]; then
        db fixup-config | sed 's/^/  /' || warn "config fix-ups failed — check widget_code manually"
        ok "fix-ups applied"
    else
        warn "no usable PHP/DB access — fix-ups skipped; clear widget_code manually in admin"
    fi
    info ""

    step "Verifying installation"
    if [ "$DRY_RUN" != 1 ]; then
        local st; st="$(module_status)"
        echo "$st" | grep -qE 'Comfino_ComfinoGateway|is enabled' || warn "module not found or not enabled in module:status — check setup:upgrade output"
        [ -d "$APPCODE_DIR" ] && ok "$APPCODE_REL present" || warn "$APPCODE_REL missing"
    fi

    restore_maintenance
    info ""
    info "${GREEN}${BOLD}✓ Upgrade complete.${NC} Comfino $MODULE_VERSION is now installed."
    info "  Backup kept at: ${backup_dir#$SHOP_DIR/}"
    info "  To roll back:   $0 revert \"$SHOP_DIR\""
    info "  Verify settings in admin: Stores → Configuration → Sales → Payment Methods → Comfino"
}

# --------------------------------------------------------------------------- #
# REVERT
# --------------------------------------------------------------------------- #
do_revert() {
    local backup_dir; backup_dir="$(resolve_backup_dir "$BACKUP_ID")"
    backup_dir="$(cd "$backup_dir" && pwd)"

    local old_ver=""
    [ -f "$backup_dir/manifest.env" ] && { set +u; source "$backup_dir/manifest.env"; old_ver="${OLD_MODULE_VERSION:-}"; set -u; }

    info "${BOLD}Comfino Magento 2 — revert to previous 2.x module${NC}"
    info "Shop:    $SHOP_DIR"
    info "Backup:  ${backup_dir#$SHOP_DIR/}"
    [ -n "$old_ver" ] && info "Restoring version: $old_ver"
    info ""

    confirm "Remove 3.1.0 module and restore the 2.x module from this backup?" || die "aborted by user"
    info ""

    enable_maintenance

    step "Removing 3.1.0 module files"
    if [ "$DRY_RUN" = 1 ]; then
        info "  (dry-run) would delete $APPCODE_REL"
    else
        if module_status | grep -q 'is enabled'; then
            mage module:disable "$MODULE_NAME" >/dev/null 2>&1 || warn "module:disable returned non-zero"
        fi
        rm -rf "$APPCODE_DIR"
        find "$SHOP_DIR/pub/static" -path "*/${MODULE_NAME}*" -not -path "*/.*" -prune -exec rm -rf {} + 2>/dev/null || true
        rm -rf "$SHOP_DIR/generated/code/Comfino" "$SHOP_DIR/generated/metadata" 2>/dev/null || true
        ok "3.1.0 module files removed"
    fi
    info ""

    restore_appcode_from_backup "$backup_dir"
    info ""

    if [ "$DB_AVAILABLE" = 1 ]; then
        step "Restoring database settings"
        if [ "$DRY_RUN" = 1 ]; then
            info "  (dry-run) would restore config-data.json, order-statuses.json, application-data.json"
        else
            [ -f "$backup_dir/config-data.json" ]      && db restore-config      "$backup_dir/config-data.json"      | sed 's/^/  /' || warn "config restore skipped"
            [ -f "$backup_dir/order-statuses.json" ]   && db restore-statuses    "$backup_dir/order-statuses.json"   | sed 's/^/  /' || warn "status restore skipped"
            if [ -f "$backup_dir/application-data.json" ]; then
                warn "application table rows will be restored after setup:upgrade recreates the table"
                RESTORE_APPLICATION="$backup_dir/application-data.json"
            fi
            ok "database settings restored"
        fi
    else
        warn "no usable PHP/DB access — DB settings left as-is"
    fi
    info ""

    run_setup_sequence || warn "setup reported errors — run 'bin/magento setup:upgrade' manually"

    if [ -n "${RESTORE_APPLICATION:-}" ] && [ "$DB_AVAILABLE" = 1 ] && [ "$DRY_RUN" != 1 ]; then
        db restore-application "$RESTORE_APPLICATION" | sed 's/^/  /' || warn "application table restore failed"
    fi

    info ""
    restore_maintenance
    info ""
    info "${GREEN}${BOLD}✓ Revert complete.${NC} Previous Comfino module (${old_ver:-2.x}) is restored."
}

# --------------------------------------------------------------------------- #
# STATUS
# --------------------------------------------------------------------------- #
do_status() {
    info "${BOLD}Comfino legacy upgrade status${NC}"
    info "Shop:    $SHOP_DIR"
    [ "$USE_WRAPPERS" = 1 ] && info "Mode:    docker wrappers ($PROJECT_DIR)"
    info ""

    step "Module"
    local cv; cv="$(detect_installed_version)"
    if [ -n "$cv" ]; then
        info "  app/code module: present (version ${cv})"
    else
        info "  app/code module: not present"
    fi
    info "  $(module_status | head -n1)"
    info ""

    step "Current Comfino settings (default scope)"
    if [ "$DB_AVAILABLE" = 1 ]; then
        db print-config || warn "could not read settings from DB"
    else
        warn "no usable PHP/DB access"
    fi
    info ""

    step "Available backups ($BACKUP_REL)"
    if [ -d "$BACKUP_ROOT" ]; then
        local found=0 entry
        for entry in "$BACKUP_ROOT"/*/; do
            [ -d "$entry" ] || continue
            entry="${entry%/}"; [ "$(basename "$entry")" = "latest" ] && continue
            info "  $(basename "$entry")"; found=1
        done
        [ "$found" = 0 ] && info "  (none)"
        [ -L "$BACKUP_ROOT/latest" ] && info "  latest -> $(readlink "$BACKUP_ROOT/latest")"
    else
        info "  (none)"
    fi
}

# --------------------------------------------------------------------------- #
# Dispatch
# --------------------------------------------------------------------------- #
case "$ACTION" in
    upgrade) do_upgrade ;;
    revert)  do_revert ;;
    status)  do_status ;;
    *)       die "unknown action '$ACTION' (use upgrade | revert | status | help)" ;;
esac

exit 0

__PAYLOAD_START__
INSTALLER_EOF

# Replace placeholders.
sed -i "s/MODULE_VERSION_PLACEHOLDER/$MODULE_VERSION/g" "$TEMP_INSTALLER"
sed -i "s|INSTALLER_NAME|$INSTALLER_NAME|g" "$TEMP_INSTALLER"

# Append base64 payload.
echo "" >> "$TEMP_INSTALLER"
echo "$PAYLOAD_B64" >> "$TEMP_INSTALLER"
echo "__PAYLOAD_END__" >> "$TEMP_INSTALLER"

mv "$TEMP_INSTALLER" "$INSTALLER_PATH"
chmod +x "$INSTALLER_PATH"

echo ""
echo "╔════════════════════════════════════════════════════════════════╗"
echo "║  Self-extracting legacy upgrade installer created!"
echo "╚════════════════════════════════════════════════════════════════╝"
echo ""
echo "Installer: $INSTALLER_PATH"
echo "Size:      $(du -h "$INSTALLER_PATH" | cut -f1)"
echo ""
echo "Usage:"
echo "  # Upgrade a 2.x shop to Comfino $MODULE_VERSION:"
echo "  ./$INSTALLER_NAME upgrade /path/to/magento"
echo ""
echo "  # Show current module status and available backups:"
echo "  ./$INSTALLER_NAME status /path/to/magento"
echo ""
echo "  # Revert to the previous 2.x module:"
echo "  ./$INSTALLER_NAME revert /path/to/magento"
echo ""