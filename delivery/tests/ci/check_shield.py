"""Guards Etel Shield: boot.php calls the guard right after reading the request (inside try/catch: it fails open), the emergency switch exists, the shield never trusts a proxy
header unless told to, signed-in people are never blocked by address, and the attack patterns start in monitor mode."""
import re
import subprocess
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
boot = read('api/boot.php')
m = re.search(r"try \{ require_once __DIR__ \. '/lib/shield\.php'; etel_shield_guard\(\$action, \$body, \$raw\); \} catch \(Exception \$e\) \{\} catch \(Throwable \$e\) \{\}", boot)
if not m:
    bad.append('boot.php must call etel_shield_guard inside try/catch (a broken shield must never take the API down)')
elif boot.find("$action = isset($body['action'])") > m.start():
    bad.append('etel_shield_guard must run after the request is read')
if "case 'admin_shield_get'" not in boot:
    bad.append('the admin_shield_* actions are not registered in boot.php')
store = read('api/lib/shield_store.php')
core = read('api/lib/shield.php')
if "'waf_mode'          => 'monitor'" not in store:
    bad.append("the attack-pattern check must start in 'monitor' mode (log only) until the owner has read the log")
if "'proxy_mode'        => 'none'" not in store:
    bad.append("proxy_mode must default to 'none': a forwarded-for header can be forged by anyone")
if 'SHIELD_OFF' not in store:
    bad.append('the emergency switch SHIELD_OFF is missing')
if '!$signedIn && $n > $limit * 2' not in core:
    bad.append('only a clear flood of SIGNED-OUT traffic may block an address (phone networks share addresses)')
if 'etel_shield_is_internal($ip)' not in core:
    bad.append('the server itself and local networks must never be limited')
if "'geo_mode'          => 'monitor'" not in store or "'geo_device_mode'   => 'monitor'" not in store:
    bad.append("Ethiopia-only must start in 'monitor' mode (log only) until the owner has read who would be turned away")
if 'etel_shield_geo_foreign(' not in core or 'etel_shield_geo_device_out(' not in core:
    bad.append('the guard no longer calls the Ethiopia-only checks')
if "etel_shield_waf_exempt($action, $cfg['geo_exempt_actions'])" not in core:
    bad.append('the Ethiopia-only check must respect the exempt actions list')
for f in ('api/lib/shield.php', 'api/lib/shield_store.php', 'api/lib/shield_waf.php', 'api/lib/shield_geo.php', 'api/lib/shield_geo_data.php', 'api/controllers/ShieldController.php'):
    s = read(f)
    if re.search(r'[^\x00-\x7f]', s):
        bad.append(f + ' has non-ASCII characters (PHP files are ASCII only)')
    if len(s.encode('utf-8')) > 30000:
        bad.append(f + ' is over 30 KB')
    if subprocess.call(['php', '-l', root + '/' + f], stdout=subprocess.DEVNULL) != 0:
        bad.append(f + ' does not parse')
if bad:
    print('FAILED:\n' + '\n'.join(bad)); sys.exit(1)
print('ok: Etel Shield guarded')
