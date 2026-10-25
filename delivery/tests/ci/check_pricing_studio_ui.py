"""Guards the Pricing studio (js/admin-pricing-studio.js): it parses, is built on AdminKit and loaded after it, replaces the old stacked sections and the two old scripts
(admin-pricing.js, admin-courier-pay.js), keeps the entry point admin-ui.js calls (ETELPricing.loadPricing), uses kit dialogs (never window.prompt/confirm/alert), asks before
switching a vehicle type, zone or goal tier off, sends the server the same action names as before, mirrors the server's fare formula, escapes server values, has no inline
handlers, and never uses a class name containing "-card"."""
import re
import subprocess

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

js = read('js/admin-pricing-studio.js')
if subprocess.call(['node', '--check', root + '/js/admin-pricing-studio.js']) != 0:
    bad.append('js/admin-pricing-studio.js does not parse')
if re.search(r"""[\s'"`>]on[a-z]+\s*=\s*\\?["']""", js):
    bad.append('no inline handlers in admin-pricing-studio.js')
if re.search(r'window\.(prompt|confirm|alert)\(|[^.\w](prompt|confirm|alert)\(', js):
    bad.append('use AdminKit dialogs, not window.prompt/confirm/alert')
if re.search(r'class="[^"]*-card', js) or re.search(r"'[^']*-card[ '\"]", js):
    bad.append('a class name containing "-card" gets the admin glare overlay; rename it')
for must in ('window.AdminKit', 'window.ETELPricing', 'loadPricing', 'ETEL_CAPS_PROMISE',
             'admin_get_ride_fare_rules', 'admin_update_ride_fare_rule', 'admin_add_vehicle_type', 'admin_delete_vehicle_type', 'admin_update_ride_surge_multiplier',
             'admin_get_ride_peak_windows', 'admin_update_ride_peak_windows', 'admin_get_goal_tiers', 'admin_save_goal_tier', 'admin_delete_goal_tier',
             'admin_get_zones', 'admin_add_zone', 'admin_update_zone', 'admin_delete_zone', 'admin_get_delivery_tiers', 'admin_update_delivery_tier',
             'admin_get_bank_accounts', 'admin_update_bank_account', 'admin_get_courier_pay', 'admin_save_courier_pay', 'admin_preview_courier_pay', 'admin_get_order_pay', 'admin_set_order_pay'):
    if must not in js:
        bad.append('admin-pricing-studio.js lost: ' + must)
for ask in ("title: 'Switch off ' + vlabel(dv)", "title: 'Delete this goal tier?'", "title: 'Switch off \"' + zn"):
    if ask not in js:
        bad.append('a switch-off/delete no longer asks first: ' + ask)
# the calculator must keep the server's formula (api/lib/ride_fare.php rides_calc_fare)
for formula in ('(v.base_fare + perKm * km + v.wait_rate_per_min * wait) * surge', 'c.open ? v.min_fare : (km <= 3 ? v.min_fare_short_trip : v.min_fare)', 'c.open ? v.per_km_rate_open : v.per_km_rate'):
    if formula not in js:
        bad.append('the fare calculator no longer mirrors rides_calc_fare: ' + formula)
php = read('api/lib/ride_fare.php')
if "($rule['base_fare'] + $per_km*$distKm + $rule['wait_rate_per_min']*$waitMinutes) * $surge" not in php:
    bad.append('rides_calc_fare changed: update the fare calculator in admin-pricing-studio.js and this guard')
if 'innerHTML' in js and 'esc(' not in js:
    bad.append('server values must go through AdminKit.esc')

html = read('admin.html')
tags = [re.search(r'js/([\w.-]+?)\.js', m.group(0)).group(1) for m in re.finditer(r'<script src="js/(admin\.js|admin-kit\.js|admin-roles\.js|admin-pricing-studio\.js)[^"]*"', html)]
if tags.count('admin-pricing-studio') != 1 or tags.index('admin-pricing-studio') < max(tags.index('admin-kit'), tags.index('admin-roles')):
    bad.append('admin.html must load admin-pricing-studio.js after admin-kit.js and admin-roles.js')
if 'id="pricing-studio"' not in html:
    bad.append('admin.html lost #pricing-studio')
for gone in ('id="pricing-fare-rules"', 'id="pricing-zones"', 'id="pricing-peak-windows"', 'id="pricing-goal-tiers"', 'id="pricing-tiers"', 'id="pricing-bank-accounts"', 'admin-pricing.js', 'admin-courier-pay.js', 'cpay-card'):
    if gone in html:
        bad.append('the old Pricing section is back in admin.html: ' + gone)
ui = read('js/admin/admin-ui.js')
if "ETELPricing.loadPricing()" not in ui:
    bad.append('admin-ui.js no longer opens the Pricing page through ETELPricing.loadPricing()')

if bad:
    print('check_pricing_studio_ui FAILED:\n  - ' + '\n  - '.join(bad))
    raise SystemExit(1)
print('check_pricing_studio_ui: ok')
