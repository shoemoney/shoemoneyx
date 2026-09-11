#!/usr/bin/env bash
# ops/migrate-preflight.sh — one-shot migrate run against MariaDB with room for the online
# rebuild the pending migrations trigger.
#
# `backtests` (7.6 GiB) already carries indexed VIRTUAL columns, so ADD COLUMN / ADD INDEX on
# it is neither INSTANT nor NOCOPY (ER 1845/1846) -- each is a full online rebuild that streams
# concurrent DML into innodb_online_alter_log_max_size. Default (128M) risks
# ER_INNODB_ONLINE_LOG_TOO_BIG under the fleet's ~35 inserts/s. This script:
#   1. prints innodb_online_alter_log_max_size before touching it
#   2. bumps it to 1 GiB for this run only (SET GLOBAL, session-scoped effect on new ALTERs)
#   3. runs `php artisan migrate --force`
#   4. restores the previous value, even if the migration run fails
#   5. prints migration status before and after
#
# Safe to re-run: the migrations it drives are idempotent (guarded hasColumn/hasIndex checks),
# and the value restore runs on any exit via trap, so a failed run never leaves the global
# setting bumped.
#
# Usage: ops/migrate-preflight.sh
# Run on the host that holds both the app checkout and the MariaDB client pointed at the
# database in .env (the production desk host).
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
REPO_ROOT="$(pwd)"
ENV_FILE="$REPO_ROOT/.env"

envval() {
    local key="$1"
    [[ -f "$ENV_FILE" ]] || return 0
    grep -E "^${key}=" "$ENV_FILE" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'
}

DB_CONNECTION="$(envval DB_CONNECTION)"
if [[ "$DB_CONNECTION" != "mysql" && "$DB_CONNECTION" != "mariadb" ]]; then
    echo "DB_CONNECTION=${DB_CONNECTION:-<unset>} is not mysql/mariadb; nothing to preflight, running migrate directly." >&2
    php artisan migrate --force
    exit 0
fi

DB_HOST="$(envval DB_HOST)"
DB_PORT="$(envval DB_PORT)"
DB_DATABASE="$(envval DB_DATABASE)"
DB_USERNAME="$(envval DB_USERNAME)"
DB_PASSWORD="$(envval DB_PASSWORD)"
DB_PORT="${DB_PORT:-3306}"

MYSQL=(mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE")
if [[ -n "$DB_PASSWORD" ]]; then
    export MYSQL_PWD="$DB_PASSWORD"
fi

TARGET_BYTES=$((1024 * 1024 * 1024)) # 1 GiB

echo "=== innodb_online_alter_log_max_size before ==="
"${MYSQL[@]}" -e "SHOW VARIABLES LIKE 'innodb_online_alter_log_max_size'"

PREV_VALUE="$("${MYSQL[@]}" -N -e "SHOW VARIABLES LIKE 'innodb_online_alter_log_max_size'" | awk '{print $2}')"
if [[ -z "$PREV_VALUE" ]]; then
    echo "could not read innodb_online_alter_log_max_size, aborting" >&2
    exit 1
fi

restore() {
    echo "=== restoring innodb_online_alter_log_max_size to ${PREV_VALUE} ==="
    "${MYSQL[@]}" -e "SET GLOBAL innodb_online_alter_log_max_size = ${PREV_VALUE}" \
        || echo "WARNING: failed to restore innodb_online_alter_log_max_size — restore it manually to ${PREV_VALUE}" >&2
}
trap restore EXIT

echo "=== setting innodb_online_alter_log_max_size = ${TARGET_BYTES} (1 GiB) for this run ==="
"${MYSQL[@]}" -e "SET GLOBAL innodb_online_alter_log_max_size = ${TARGET_BYTES}"

echo "=== migration status before ==="
php artisan migrate:status

echo "=== running migrations ==="
php artisan migrate --force

echo "=== migration status after ==="
php artisan migrate:status
