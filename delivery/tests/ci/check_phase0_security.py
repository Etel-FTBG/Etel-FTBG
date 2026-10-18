"""Guards the Phase 0 security fixes (part 2 at the end: street hail, cashout, demo accounts, login/OTP lockouts): Telegram linking needs a verified contact, updater needs an owner capability, Telebirr refuses an empty secret, Etel Pay tx_ref is claimed once, driver photos must be the driver's own uploads, push endpoints are allow-listed, high fares are flagged."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

tg = read('api/telegram_webhook.php')
if "$c['user_id']" not in tg or "request_contact" not in tg:
    bad.append('telegram_webhook.php must link only from a contact share where contact.user_id is the sender')
if re.search(r"\$phone\s*=\s*\$raw_phone\s*\?\s*normalise_phone", tg):
    bad.append('telegram_webhook.php must not link a phone typed after /start')

if "require_admin($pdo, 'system.backup')" not in read('api/updater-api.php'):
    bad.append('updater-api.php must require the system.backup capability')

tb = read('api/telebirr-callback.php')
guard = tb.replace('(string)', '').find("TELEBIRR_SECRET_KEY === ''")
if guard < 0 or guard > tb.index('hash_hmac'):
    bad.append('telebirr-callback.php must refuse an empty secret before computing the HMAC')
if '$callbackAmount === null' not in tb:
    bad.append('telebirr-callback.php must reject a callback with no amount')

bridge = read('api/etel_pay_bridge.php')
if 'function etel_pay_claim_tx' not in bridge or 'PRIMARY KEY (tx_ref)' not in bridge:
    bad.append('etel_pay_claim_tx must insert into a payment_claims table keyed on tx_ref')
for path, kind in (('api/controllers/RideController.php', "'ride'"), ('api/controllers/RideController.php', "'topup'"), ('api/controllers/OrderController.php', "'order'")):
    if 'etel_pay_claim_tx($pdo, $tx_ref, ' + kind + ')' not in read(path):
        bad.append(path + ' does not claim the tx_ref for ' + kind)

ride = read('api/controllers/RideController.php')
if 'rides_owned_upload_url' not in ride or "'driver_selfies', 'selfie_'" not in ride or "'driver_licenses', 'license_'" not in ride:
    bad.append('driver_update_profile must check the selfie/license URLs are the driver\'s own uploads')
if "$effectiveMatch = ($newLicName && $newLicDob) ? $identityMatch : 'matched'" in ride:
    bad.append('a driver with no license claim must not be auto-onboarded as matched')

wp = read('api/webpush.php')
if 'function etel_push_endpoint_allowed' not in wp or 'CURLOPT_PROTOCOLS' not in wp:
    bad.append('webpush.php must allow-list push endpoints and restrict cURL to https')
if 'etel_push_endpoint_allowed' not in read('api/controllers/PushController.php'):
    bad.append('save_push_subscription must validate the endpoint')

fare = read('api/lib/ride_fare.php')
if 'function rides_flag_fare_review' not in fare or "'fare_review'" not in read('api/lib/ride_sweeper.php'):
    bad.append('high fares must be flagged and skipped by the auto-complete sweeper')
if 'This trip has no fare yet' not in ride:
    bad.append('customer_confirm_arrival must refuse a zero fare')

# ---- part 2 ----
if "rate_limit_check($pdo, 'hail:'" not in ride:
    bad.append('driver_hail_customer must be rate limited per driver')
hail = ride.split("case 'driver_hail_customer'", 1)[1].split("case 'driver_start_ride'", 1)[0]
if "SET status='cancelled'" in hail:
    bad.append('driver_hail_customer must never cancel a ride the customer booked')
if 'ride_street_hail' not in hail:
    bad.append('driver_hail_customer must notify the customer')

cash = ride.split("case 'driver_cashout'", 1)[1].split("case 'driver_topup_etel_money'", 1)[0]
if "!empty($result['uncertain'])" not in cash or "status='unknown'" not in cash:
    bad.append('driver_cashout must keep the amount reserved when the Etel Pay outcome is unknown')
if "'uncertain' => true" not in bridge:
    bad.append('etel_pay_partner_credit must flag an unknown outcome as uncertain')

boot = read('api/boot.php')
admin = read('api/controllers/AdminController.php')
if 'demotest1234' in boot or 'demotest1234' in admin:
    bad.append('the System Test demo accounts must not use a fixed, known password')
if 'password_hash' not in read('api/migrations/085_lock_demo_accounts.sql'):
    bad.append('migration 085 must lock the existing demo accounts')

if 'function rate_limit_account_check' not in boot:
    bad.append('boot.php needs the account-only rate limit')
auth = read('api/controllers/AuthController.php')
if 'No account found with that phone' in auth or "fail('Incorrect password'" in auth or 'No service giver account found' in auth:
    bad.append('login must give the same answer for an unknown number and a wrong password')
if auth.count('rate_limit_account_check') < 2:
    bad.append('login and sg_login must use the account-only lockout')
if "AND attempts < 5 ORDER BY id DESC LIMIT 1" not in boot:
    bad.append('reset_verify must spend an OTP guess atomically before comparing')

if bad:
    print('check_phase0_security FAILED:\n  ' + '\n  '.join(bad))
    sys.exit(1)
print('check_phase0_security OK')
