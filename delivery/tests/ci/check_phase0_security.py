"""Guards the Phase 0 security fixes: Telegram linking needs a verified contact, updater needs an owner capability, Telebirr refuses an empty secret, Etel Pay tx_ref is claimed once, driver photos must be the driver's own uploads, push endpoints are allow-listed, high fares are flagged."""
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

if bad:
    print('check_phase0_security FAILED:\n  ' + '\n  '.join(bad))
    sys.exit(1)
print('check_phase0_security OK')
