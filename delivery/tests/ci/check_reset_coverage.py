"""Guards the admin Danger Zone (api/controllers/SystemResetController.php). Every table the install can create is either WIPED by the reset
(user and activity data) or KEPT (settings, catalogue, release and audit tables). A new table that is in neither list fails here, so nobody launches
with demo rows left in a table the reset never heard of. Also: settings the business typed in (bank accounts, driver goals, prices) are never wiped,
the reset refuses to run if no admin account would remain, and the preview warns about placeholder bank numbers and demo logins."""
import glob
import re

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(p, encoding='utf-8', errors='replace').read()
bad = []

src = read(root + '/api/controllers/SystemResetController.php')
m = re.search(r'function equity_reset_table_plan\(\) \{(.*?)\n\}', src, re.S)
plan = set(re.findall(r"'([a-z0-9_]+)'", re.sub(r'//[^\n]*', '', m.group(1)))) if m else set()
if not plan:
    bad.append('could not read the wipe list')

# Set up by the business, or operational history: the reset must leave these alone.
KEEP = set('''
admin_2fa admin_document_versions admin_documents api_perf_minute app_canary_testers app_release_channels audit_settings bank_accounts
business_hires_plan business_plan_notes courier_offer_settings delivery_tiers delivery_zones driver_announcements driver_goal_tiers
equb_groups equb_packages equity_investors equity_profit_distribution_items equity_profit_distributions equity_round_settings etel_heartbeat
geocode_cache growth_goal_assumptions integration_changes integration_custom integration_settings items launch_checklist_state menu_catalog
package_rates package_settings patch_log payment_method_logos platform_cost_assumptions pricing_rules promo_codes ride_fare_rules
ride_promo_codes ride_safety_settings ride_sweeper_state role_permissions schema_migrations sg_roles system_reset_tokens updater_logs users
rides_legacy_202606120827
'''.split())

created = set()
files = glob.glob(root + '/api/migrations/*.sql') + glob.glob(root + '/api/**/*.php', recursive=True)
for f in files:
    for t in re.findall(r'CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z][a-z0-9_]*)`?\s*\(', read(f), re.I):
        created.add(t.lower())
created -= {'if', 'above'}

for t in sorted(created - plan - KEEP):
    bad.append('table `%s` is neither in the reset wipe list nor in KEEP (tests/ci/check_reset_coverage.py): decide which' % t)
for t in sorted(plan & KEEP):
    bad.append('`%s` is both wiped and kept' % t)
for t in ('bank_accounts', 'driver_goal_tiers', 'pricing_rules', 'ride_fare_rules', 'delivery_zones', 'delivery_tiers', 'schema_migrations', 'role_permissions'):
    if t in plan:
        bad.append('settings table `%s` must not be wiped by the reset' % t)
for t in ('rides', 'orders', 'ride_requests', 'wallet_transactions', 'user_wallets', 'drivers', 'couriers', 'service_givers', 'telebirr_payments', 'bank_payments', 'chapa_payments'):
    if t not in plan:
        bad.append('activity table `%s` is missing from the reset wipe list' % t)
if "role='admin' AND phone IN" not in src or 'at least one admin account' not in src:
    bad.append('the reset must refuse to run when no admin account is among the phones to keep')
bk = src[src.index("case 'system_reset_backup'"):src.index("case 'system_reset_execute'")]
if 'fetchAll' in bk:
    bad.append('the backup must stream rows (fetch one at a time), not load whole tables into memory: a big live table made it fail with HTTP 500')
for must in ("class_exists('ZipArchive')", 'fputcsv', 'MYSQL_ATTR_USE_BUFFERED_QUERY', 'catch (Throwable'):
    if must not in bk:
        bad.append('the backup lost: ' + must)
ui_src = read(root + '/js/admin-danger-zone.js')
if 'resp.json()' not in ui_src:
    bad.append('admin-danger-zone.js must show the reason the backup failed, not just the HTTP status')
for must in ('setup_warnings', 'admins_kept', 'equity_reset_placeholder_warnings'):
    if must not in src:
        bad.append('the preview lost: ' + must)
ui = read(root + '/js/admin-danger-zone.js')
for must in ('setup_warnings', 'admins_kept'):
    if must not in ui:
        bad.append('admin-danger-zone.js no longer shows: ' + must)

if bad:
    print('check_reset_coverage: FAIL')
    for b in bad:
        print('  -', b)
    raise SystemExit(1)
print('check_reset_coverage: ok (%d wiped, %d kept, %d known tables)' % (len(plan), len(KEEP), len(created)))
