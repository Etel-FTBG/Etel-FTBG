#!/usr/bin/env bash
# Runs every check CI runs, against a database + php -S you point it at.
# Same script locally and in GitHub Actions, so "works on my machine" = CI.
#
#   FRESH_DB=1 ./tests/ci/run_integration.sh   # also rebuilds the DB first
#
# Needs: php, python3, mysql client, a MariaDB reachable via api/.env
# (APP_ENV=development, CRON_DISPATCH_KEY=test_cron_key). THROWAWAY DB ONLY.
set -uo pipefail
cd "$(dirname "$0")/../.."
PORT="${PORT:-8765}"
DB="${ETEL_TEST_DB_NAME:-etel_delivery}"

if [ "${FRESH_DB:-0}" = "1" ]; then
  mysql ${MYSQL_ADMIN_ARGS:-} -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4;" || exit 1
  rm -f api/.schema_done api/.tip_column_ok api/.order_pay_columns_ok   # runtime markers; stale once the DB is rebuilt
  mysql ${MYSQL_ADMIN_ARGS:-} "$DB" < api/seeds/complete_migration.sql >/dev/null 2>&1 || true
  php api/migrate.php --apply || exit 1
  mysql ${MYSQL_ADMIN_ARGS:-} "$DB" < api/seeds/admin_demo_accounts.sql >/dev/null || exit 1
fi

PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:"$PORT" -t . >/tmp/etel-php.log 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null' EXIT
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/api/list-zones.php" && break; sleep 0.5; done

rc=0
run() { echo; echo "=== $*"; "$@" || { echo "!!! FAILED: $*"; rc=1; }; }
run python3 tests/ci/check_migrations.py
run python3 tests/ci/check_reserved_columns.py
run python3 tests/ci/check_xss_sinks.py
run python3 tests/security_regression_smoke.py
run python3 tests/business_logic_smoke.py
run python3 tests/open_meter_smoke.py
run python3 tests/week1_hardening_smoke.py
run python3 tests/stale_assignment_smoke.py
run python3 tests/courier_earnings_smoke.py
run php tests/pricing_fallback_test.php
run php tests/courier_pay_test.php
run php tests/pulse_avg_test.php
run php tests/order_stamps_test.php
run php tests/image_orient_test.php
run php tests/etelid_role_verdict_test.php
run python3 tests/courier_pay_smoke.py
echo; [ $rc = 0 ] && echo "ALL CHECKS PASSED" || echo "SOME CHECKS FAILED"
exit $rc
