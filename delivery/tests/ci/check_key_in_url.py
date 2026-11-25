"""Guards secret keys being kept out of web addresses: every cron job, diagnose.php, etel-os.php and updater.php reads its key through api/lib/cron_key.php (header first,
a key in the address only as the Etel Shield setting url_key_mode allows), and no new cron file takes a raw ?key= without it."""
import glob
import os
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
EXPECT = {'cron_dispatch_scheduled.php': 'X-Cron-Key', 'cron_close_stuck_rides.php': 'X-Cron-Key', 'cron_ride_monitor.php': 'X-Cron-Key', 'cron_chapa_reconcile.php': 'X-Cron-Key',
          'cron_field_agents.php': 'X-Cron-Key', 'equb_cron.php': 'X-Cron-Key', 'cron_backup_db.php': 'X-Cron-Key', 'diagnose.php': 'X-Etel-Key', 'etel-os.php': 'X-Etel-Key',
          'updater.php': None}
for f, hdr in EXPECT.items():
    src = read('api/' + f)
    if 'lib/cron_key.php' not in src or 'etel_secret_from_request(' not in src:
        bad.append('api/%s does not read its key through lib/cron_key.php' % f)
    elif hdr and hdr not in src:
        bad.append('api/%s should accept the %s header' % (f, hdr))
# any other cron / key file in api/ that reads ?key= straight
for p in sorted(glob.glob(root + '/api/*.php')):
    f = os.path.basename(p)
    if f in EXPECT or f in ('boot.php',):
        continue
    src = open(p, encoding='utf-8', errors='replace').read()
    if re.search(r"\$_GET\[\s*['\"]key['\"]\s*\]", src) and 'cron' in f.lower():
        bad.append('api/%s reads ?key= straight: use lib/cron_key.php' % f)
lib = read('api/lib/cron_key.php')
for needle in ("'refuse'", "'monitor'", 'keyurl_', 'catch (Throwable $e)'):
    if needle not in lib:
        bad.append('lib/cron_key.php lost ' + needle)
if re.search(r'[^\x00-\x7f]', lib):
    bad.append('lib/cron_key.php is not ASCII only')
if 'url_key_mode' not in read('api/lib/shield_store.php'):
    bad.append('url_key_mode is no longer a shield setting')
if bad:
    print('FAILED:\n' + '\n'.join(bad)); sys.exit(1)
print('ok: secret keys kept out of web addresses (guarded)')
