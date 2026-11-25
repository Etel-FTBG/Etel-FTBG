"""Guards the Etel Shield on the files that are not behind boot.php: every one of them calls etel_shield_entry() with the right kind (the payment and Telegram callbacks
are 'callback' and so are never turned away by country or blocked automatically), the call sits before any real work, and the guard keeps its safety rules."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
KIND = {'upload.php': 'upload', 'documents_upload.php': 'upload', 'documents_download.php': 'upload', 'private_file.php': 'public', 'trip_share.php': 'public',
        'chapa-return.php': 'public', 'chapa-callback.php': 'callback', 'telebirr-callback.php': 'callback', 'telegram_webhook.php': 'callback',
        'csp-report.php': 'sink', 'client-error.php': 'sink'}
for f, kind in KIND.items():
    src = read('api/' + f)
    m = re.findall(r"etel_shield_entry\('([a-z]+)'\)", src)
    if m != [kind]:
        bad.append('api/%s must call etel_shield_entry(\'%s\') once, found %s' % (f, kind, m))
        continue
    if 'lib/shield_entry.php' not in src:
        bad.append('api/%s does not load lib/shield_entry.php' % f)
    pos = src.index('etel_shield_entry(')
    before = src[:pos]
    if re.search(r'file_get_contents\(\s*[\'"]php://input|json_decode\(|->prepare\(|->query\(|move_uploaded_file', before):
        bad.append('api/%s does real work before the shield runs' % f)
lib = read('api/lib/shield_entry.php')
if "$kind === 'upload' || $kind === 'public'" not in lib:
    bad.append("the Ethiopia-only check must apply to 'upload' and 'public' only (never callbacks or sinks)")
if "if ($kind !== 'callback') {" not in lib or "$kind !== 'callback' && $cfg['scanner_mode']" not in lib:
    bad.append('callbacks must skip the automatic blocks and the hacking-tool check')
if "($kind === 'public' || ($kind === 'upload' && !$signedIn)) && $n > $limit * 2" not in lib:
    bad.append('only a clear flood of open or signed-out traffic may count toward a block (never callbacks, sinks or signed-in people)')
if 'SHIELD_OFF' not in lib or 'catch (Throwable $e)' not in lib:
    bad.append('shield_entry.php lost the emergency switch or the fail-open rule')
for f in ('api/lib/shield_entry.php', 'api/lib/shield_store.php'):
    t = read(f)
    if re.search(r'[^\x00-\x7f]', t):
        bad.append(f + ' is not ASCII only')
    if len(t.encode()) > 30000:
        bad.append(f + ' is over 30KB')
if bad:
    print('FAILED:\n' + '\n'.join(bad)); sys.exit(1)
print('ok: shield on the other entry files guarded')
