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
  rm -f api/.schema_done api/.tip_column_ok api/.order_pay_columns_ok api/.price_offer_ok   # runtime markers; stale once the DB is rebuilt
  mysql ${MYSQL_ADMIN_ARGS:-} "$DB" < api/seeds/complete_migration.sql >/dev/null 2>&1 || true
  php api/migrate.php --apply || exit 1
  mysql ${MYSQL_ADMIN_ARGS:-} "$DB" < api/seeds/admin_demo_accounts.sql >/dev/null || exit 1
fi

# The other smokes sign couriers up and put them straight to work, so approval is off for the run;
# tests/courier_approval_smoke.py switches it on for itself and puts it back.
(cd tests && python3 -c "import etel_test_db as t; print(t.run_sql(\"INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('courier_require_approval', 0, 'CI: couriers start work without approval') ON DUPLICATE KEY UPDATE rule_value = 0\"))") || true

PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:"$PORT" -t . >/tmp/etel-php.log 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null' EXIT
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/api/list-zones.php" && break; sleep 0.5; done

rc=0
run() { echo; echo "=== $*"; "$@" || { echo "!!! FAILED: $*"; rc=1; }; }
run python3 tests/ci/check_migrations.py
run python3 tests/ci/check_vehicle_pic.py
run python3 tests/ci/check_courier_marker.py
run python3 tests/ci/check_driver_desk.py
run python3 tests/ci/check_courier_desk.py
run python3 tests/ci/check_reserved_columns.py
run python3 tests/ci/check_xss_sinks.py
run python3 tests/security_regression_smoke.py
run python3 tests/authz_matrix_smoke.py
run python3 tests/auditor_findings_smoke.py
run python3 tests/package_smoke.py
run python3 tests/csp_report_smoke.py
run python3 tests/admin_import_smoke.py
run python3 tests/sg_claim_smoke.py
run python3 tests/item_extras_smoke.py
run python3 tests/sg_owner_menu_smoke.py
run python3 tests/people_register_smoke.py
run python3 tests/phone_book_ride_smoke.py
run python3 tests/customers_admin_smoke.py
run python3 tests/people_admin_smoke.py
run python3 tests/courier_approval_smoke.py
run python3 tests/admin_boards_smoke.py
run python3 tests/business_logic_smoke.py
run python3 tests/shop_business_smoke.py
run python3 tests/open_meter_smoke.py
run python3 tests/ride_dispatch_smoke.py
run python3 tests/ride_offer_smoke.py
run python3 tests/ride_vehicle_types_smoke.py
run python3 tests/ride_wallet_soon_smoke.py
run python3 tests/ride_promo_smoke.py
run python3 tests/ride_tip_smoke.py
run python3 tests/hub_counts_smoke.py
run python3 tests/ride_sse_once_smoke.py
run python3 tests/ride_statement_smoke.py
run python3 tests/ride_meter_smoke.py
run python3 tests/ride_payments_smoke.py
run python3 tests/ride_offer_price_smoke.py
run python3 tests/request_id_beacon_smoke.py
run python3 tests/post_deploy_check.py "http://127.0.0.1:$PORT" --prefix "" --allow-degraded
run python3 tests/week1_hardening_smoke.py
run python3 tests/stale_assignment_smoke.py
run python3 tests/courier_earnings_smoke.py
run php tests/pricing_fallback_test.php
run php tests/courier_pay_test.php
run php tests/upload_security_test.php
run php tests/pulse_avg_test.php
run php tests/order_stamps_test.php
run php tests/ratings_report_test.php
run php tests/track_eta_test.php
run php tests/shop_hours_test.php
run php tests/money_ledger_test.php
run php tests/ride_tip_chapa_test.php
run php tests/ride_meter_floor_test.php
run php tests/manual_dispatch_test.php
run php tests/order_cancel_test.php
run php tests/package_orders_test.php
run php tests/own_shop_test.php
run php tests/customer_suspend_test.php
run php tests/cron_lock_test.php
run php tests/backup_offsite_test.php
run php tests/integrations_test.php
run php tests/health_backup_test.php
run php tests/image_orient_test.php
run php tests/etelid_role_verdict_test.php
run php tests/etelid_phone_trust_test.php
run python3 tests/courier_pay_smoke.py
echo; [ $rc = 0 ] && echo "ALL CHECKS PASSED" || echo "SOME CHECKS FAILED"
exit $rc
