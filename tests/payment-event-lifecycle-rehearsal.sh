#!/bin/bash

set -euo pipefail

TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STORE_REPOSITORY="$(cd "$TEST_DIR/.." && pwd)"
RED_CMS_CORE_ROOT="${RED_CMS_CORE_ROOT:-$(dirname "$STORE_REPOSITORY")/redcms v5.1}"
if [[ ! -f "$RED_CMS_CORE_ROOT/scripts/db-common.sh" ]]; then
    printf 'RED-CMS core checkout is unavailable: %s\n' "$RED_CMS_CORE_ROOT" >&2
    exit 66
fi

# shellcheck source=/dev/null
source "$RED_CMS_CORE_ROOT/scripts/db-common.sh"

FRANKENPHP_BIN="${FRANKENPHP_BIN:-/Users/oscarrojas/Documents/red-cms-dev/frankenphp-1.12.4/frankenphp}"
REHEARSAL_DATABASE="${RED_STORE_LITE_PAYMENT_LIFECYCLE_DATABASE:-redcms_sl_payment_lifecycle_$(date +%s)_$$}"
TEMP_ROOT=""
STAGED_PROJECT=""
ADMIN_DEFAULTS_FILE=""
APP_ACCOUNT_USER=""
APP_ACCOUNT_HOST=""
DATABASE_CREATED=0
GRANT_CREATED=0
PRIMARY_SNAPSHOT_BEFORE=""
KEEP_AWAKE_PID=0

red_store_lite_p3b4_admin_mysql() {
    "$RED_MYSQL_BIN" \
        "--defaults-extra-file=$ADMIN_DEFAULTS_FILE" \
        --batch --raw --skip-column-names \
        "$@"
}

red_store_lite_p3b4_app_mysql() {
    "$RED_MYSQL_BIN" \
        "--defaults-extra-file=$RED_DB_DEFAULTS_FILE" \
        --batch --raw --skip-column-names \
        "$@"
}

red_store_lite_p3b4_primary_snapshot() {
    "$RED_MYSQLDUMP_BIN" \
        "--defaults-extra-file=$RED_DB_DEFAULTS_FILE" \
        --single-transaction \
        --skip-lock-tables \
        --no-tablespaces \
        --skip-comments \
        --compact \
        --hex-blob \
        "$RED_DB_NAME_RESOLVED" \
        | shasum -a 256 \
        | awk '{print $1}'
}

red_store_lite_p3b4_cleanup() {
    local original_status=$?
    local cleanup_status=0
    local schema_count=""
    local grant_output=""
    local primary_snapshot_after=""
    local process_count=0

    trap - EXIT INT TERM
    set +e
    if [[ "$GRANT_CREATED" -eq 1 ]]; then
        red_store_lite_p3b4_admin_mysql --execute="
            REVOKE ALL PRIVILEGES ON \`$REHEARSAL_DATABASE\`.*
            FROM '$APP_ACCOUNT_USER'@'$APP_ACCOUNT_HOST';
        " >/dev/null 2>&1 || cleanup_status=1
    fi
    if [[ "$DATABASE_CREATED" -eq 1 ]]; then
        red_store_lite_p3b4_admin_mysql --execute="
            DROP DATABASE IF EXISTS \`$REHEARSAL_DATABASE\`;
        " >/dev/null 2>&1 || cleanup_status=1
    fi
    if [[ -n "$ADMIN_DEFAULTS_FILE"
        && -n "$APP_ACCOUNT_USER"
        && -n "$APP_ACCOUNT_HOST"
    ]]; then
        schema_count="$(red_store_lite_p3b4_admin_mysql --execute="
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA
            WHERE SCHEMA_NAME='$REHEARSAL_DATABASE';
        " 2>/dev/null)"
        grant_output="$(red_store_lite_p3b4_admin_mysql --execute="
            SHOW GRANTS FOR '$APP_ACCOUNT_USER'@'$APP_ACCOUNT_HOST';
        " 2>/dev/null)"
        if [[ "$schema_count" != '0'
            || "$grant_output" == *"\`$REHEARSAL_DATABASE\`.*"*
        ]]; then
            printf '%s\n' 'Cleanup failure: disposable database or grant remains.' >&2
            cleanup_status=1
        fi
    fi
    if [[ -n "$PRIMARY_SNAPSHOT_BEFORE" ]]; then
        primary_snapshot_after="$(red_store_lite_p3b4_primary_snapshot 2>/dev/null)"
        if [[ $? -ne 0
            || "$primary_snapshot_after" != "$PRIMARY_SNAPSHOT_BEFORE"
        ]]; then
            printf '%s\n' 'Cleanup failure: configured primary database changed.' >&2
            cleanup_status=1
        fi
    fi
    if [[ -n "$TEMP_ROOT"
        && "$TEMP_ROOT" == "${TMPDIR:-/tmp}/redcms-store-lite-p3b4."*
        && -d "$TEMP_ROOT"
    ]]; then
        rm -rf -- "$TEMP_ROOT"
        if [[ -e "$TEMP_ROOT" ]]; then
            printf '%s\n' 'Cleanup failure: staged project remains.' >&2
            cleanup_status=1
        fi
    fi
    if [[ -n "$ADMIN_DEFAULTS_FILE" && -f "$ADMIN_DEFAULTS_FILE" ]]; then
        rm -f -- "$ADMIN_DEFAULTS_FILE"
    fi
    red_remove_defaults_file
    if [[ "$KEEP_AWAKE_PID" -gt 0 ]]; then
        kill -TERM "$KEEP_AWAKE_PID" >/dev/null 2>&1 || true
        wait "$KEEP_AWAKE_PID" >/dev/null 2>&1 || true
        if kill -0 "$KEEP_AWAKE_PID" >/dev/null 2>&1; then
            process_count=1
            cleanup_status=1
        fi
    fi

    if [[ "$cleanup_status" -eq 0
        && "$DATABASE_CREATED" -eq 1
        && "$GRANT_CREATED" -eq 1
    ]]; then
        printf 'Store Lite P3B-4 cleanup passed: database:0 grant:0 staged-project:0 process:%s primary:unchanged\n' "$process_count"
    fi
    if [[ "$original_status" -ne 0 ]]; then
        exit "$original_status"
    fi
    exit "$cleanup_status"
}

trap red_store_lite_p3b4_cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

if [[ $# -ne 0 ]]; then
    printf 'Usage: %s\n' "$0" >&2
    exit 64
fi
if [[ ! "$REHEARSAL_DATABASE" =~ ^redcms_sl_payment_lifecycle_[A-Za-z0-9_]+$
    || ${#REHEARSAL_DATABASE} -gt 64
    || "$REHEARSAL_DATABASE" == "$RED_DB_NAME_RESOLVED"
]]; then
    printf 'Unsafe Store Lite P3B-4 database name: %s\n' "$REHEARSAL_DATABASE" >&2
    exit 64
fi
if [[ ! -x "$FRANKENPHP_BIN"
    || ! -s "$STORE_REPOSITORY/package/addon.json"
    || ! -s "$TEST_DIR/payment-event-lifecycle-rehearsal.php"
]]; then
    printf '%s\n' 'Store Lite package, rehearsal, or FrankenPHP is unavailable.' >&2
    exit 66
fi
if [[ -e "$RED_CMS_CORE_ROOT/addons" ]]; then
    printf '%s\n' 'Clean RED-CMS checkout unexpectedly contains an addons directory.' >&2
    exit 65
fi

store_version="$("$RED_PHP_BIN_RESOLVED" -r '
    $manifest = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    echo $manifest["version"] ?? "";
' "$STORE_REPOSITORY/package/addon.json")"
if [[ "$store_version" != '0.1.46' ]]; then
    printf 'Store Lite 0.1.46 is required; found %s.\n' "$store_version" >&2
    exit 65
fi

if command -v caffeinate >/dev/null 2>&1; then
    caffeinate -dimsu -w $$ &
    KEEP_AWAKE_PID=$!
    printf '%s\n' 'Mac sleep prevention is active for this rehearsal only.'
fi

TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/redcms-store-lite-p3b4.XXXXXX")"
STAGED_PROJECT="$TEMP_ROOT/project"
mkdir -p "$STAGED_PROJECT/addons/redcms/store-lite"
rsync -a \
    --exclude='.git' \
    --exclude='.codex' \
    --exclude='addons' \
    --exclude='hosting and redcms important keys and password.xlsx' \
    --exclude='includes/config.local.php' \
    "$RED_CMS_CORE_ROOT/" "$STAGED_PROJECT/"
rsync -a \
    "$STORE_REPOSITORY/package/" \
    "$STAGED_PROJECT/addons/redcms/store-lite/"

ADMIN_DEFAULTS_FILE="$(mktemp "${TMPDIR:-/tmp}/redcms-store-lite-p3b4-admin.XXXXXX")"
chmod 600 "$ADMIN_DEFAULTS_FILE"
{
    printf '[client]\n'
    printf 'protocol=tcp\n'
    printf 'host=%s\n' "$RED_DB_HOST_RESOLVED"
    printf 'port=%s\n' "$RED_DB_PORT_RESOLVED"
    printf 'user=%s\n' "${RED_ACCEPTANCE_DB_ADMIN_USER:-root}"
    printf 'password=%s\n' "${RED_ACCEPTANCE_DB_ADMIN_PASS:-}"
    printf 'default-character-set=utf8mb4\n'
} > "$ADMIN_DEFAULTS_FILE"
red_store_lite_p3b4_admin_mysql --execute='SELECT 1;' >/dev/null

APP_ACCOUNT="$(red_store_lite_p3b4_app_mysql --execute='SELECT CURRENT_USER();')"
APP_ACCOUNT_USER="${APP_ACCOUNT%@*}"
APP_ACCOUNT_HOST="${APP_ACCOUNT#*@}"
if [[ ! "$APP_ACCOUNT_USER" =~ ^[A-Za-z0-9_.-]+$
    || ! "$APP_ACCOUNT_HOST" =~ ^[A-Za-z0-9_.:%-]+$
]]; then
    printf 'Unsafe application database account: %s\n' "$APP_ACCOUNT" >&2
    exit 65
fi
database_count="$(red_store_lite_p3b4_admin_mysql --execute="
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA
    WHERE SCHEMA_NAME='$REHEARSAL_DATABASE';
")"
if [[ "$database_count" != '0' ]]; then
    printf 'Refusing to reuse database: %s\n' "$REHEARSAL_DATABASE" >&2
    exit 65
fi

PRIMARY_SNAPSHOT_BEFORE="$(red_store_lite_p3b4_primary_snapshot)"
if [[ -z "$PRIMARY_SNAPSHOT_BEFORE" ]]; then
    printf '%s\n' 'Could not fingerprint the configured primary database.' >&2
    exit 67
fi

red_store_lite_p3b4_admin_mysql --execute="
    CREATE DATABASE \`$REHEARSAL_DATABASE\`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
"
DATABASE_CREATED=1
red_store_lite_p3b4_admin_mysql --execute="
    GRANT ALL PRIVILEGES ON \`$REHEARSAL_DATABASE\`.*
    TO '$APP_ACCOUNT_USER'@'$APP_ACCOUNT_HOST';
"
GRANT_CREATED=1

printf 'Preparing fresh P3B-4 project database: %s\n' "$REHEARSAL_DATABASE"
"$RED_MYSQL_BIN" \
    "--defaults-extra-file=$RED_DB_DEFAULTS_FILE" \
    "$REHEARSAL_DATABASE" < "$STAGED_PROJECT/db-structure.sql"
RED_DB_HOST="$RED_DB_HOST_RESOLVED:$RED_DB_PORT_RESOLVED" \
RED_DB_USER="$RED_DB_USER_RESOLVED" \
RED_DB_PASS="$RED_DB_PASS_RESOLVED" \
RED_DB_NAME="$REHEARSAL_DATABASE" \
    "$STAGED_PROJECT/scripts/db-migrate.sh" \
    "--database=$REHEARSAL_DATABASE"

RED_DB_HOST="$RED_DB_HOST_RESOLVED:$RED_DB_PORT_RESOLVED" \
RED_DB_USER="$RED_DB_USER_RESOLVED" \
RED_DB_PASS="$RED_DB_PASS_RESOLVED" \
RED_DB_NAME="$REHEARSAL_DATABASE" \
RED_STORE_LITE_PROJECT_ROOT="$STAGED_PROJECT" \
    "$FRANKENPHP_BIN" php-cli \
    "$TEST_DIR/payment-event-lifecycle-rehearsal.php"

RED_DB_HOST="$RED_DB_HOST_RESOLVED:$RED_DB_PORT_RESOLVED" \
RED_DB_USER="$RED_DB_USER_RESOLVED" \
RED_DB_PASS="$RED_DB_PASS_RESOLVED" \
RED_DB_NAME="$REHEARSAL_DATABASE" \
RED_STORE_LITE_PROJECT_ROOT="$STAGED_PROJECT" \
    "$FRANKENPHP_BIN" php-cli \
    "$TEST_DIR/destination-preview-service-rehearsal.php"

printf '%s\n' 'Store Lite P3B-4 lifecycle rehearsal passed before cleanup.'
