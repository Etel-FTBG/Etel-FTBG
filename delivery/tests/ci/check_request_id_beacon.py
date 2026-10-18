"""Guards request ids, the browser error beacon and the post-deploy check: boot.php sends X-Request-Id and puts the id in
error answers and the error log, both apps load the beacon first, the admin card and actions exist, and the post-deploy
check and its manual workflow are in place (the check only reads)."""
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

boot = read('api/boot.php')
for needle in ("define('ETEL_RID'", "header('X-Request-Id: ' . ETEL_RID)", "'rid' => ETEL_RID", "[rid=' . ETEL_RID . ']"):
    if needle not in boot:
        bad.append('boot.php is missing ' + needle)
for action in ('admin_client_errors', 'admin_client_errors_clear'):
    if "case '" + action + "'" not in boot:
        bad.append('boot.php does not route ' + action)
    if "case '" + action + "': {" not in read('api/controllers/RideController.php'):
        bad.append('RideController.php is missing ' + action)
for page in ('ride/index.html', 'driver/index.html'):
    h = read(page)
    a, b = h.find('js/etel-beacon.js'), h.find('js/release-loader.js')
    if a < 0:
        bad.append(page + ' does not load js/etel-beacon.js')
    elif b >= 0 and a > b:
        bad.append(page + ' must load the beacon BEFORE the app bundles so it sees their errors')
if 'js/admin-client-errors.js' not in read('admin.html'):
    bad.append('admin.html does not load js/admin-client-errors.js')
ce = read('api/client-error.php')
for needle in ("'js', 'promise', 'api'", 'strlen($raw) > 4096', '< 500', "preg_replace('/[?#].*$/'"):
    if needle not in ce:
        bad.append('client-error.php is missing ' + needle)
beacon = read('js/etel-beacon.js')
for needle in ('sendBeacon', 'unhandledrejection', 'X-Request-Id', 'MAX = 8'):
    if needle not in beacon:
        bad.append('etel-beacon.js is missing ' + needle)
pd = read('tests/post_deploy_check.py')
if 'urllib.request.Request(url, headers=' not in pd or 'method="POST"' in pd or "method='POST'" in pd:
    bad.append('post_deploy_check.py must only read (GET)')
wf = open(root + '/../.github/workflows/post-deploy-check.yml', encoding='utf-8').read()
if 'workflow_dispatch' not in wf or '${{ inputs.base_url }}' not in wf or 'run: python3 delivery/tests/post_deploy_check.py "${{ inputs' in wf:
    bad.append('post-deploy-check.yml must be manual and pass the address through an env variable, not inline in the shell line')

if bad:
    print('check_request_id_beacon FAILED:\n  ' + '\n  '.join(bad))
    sys.exit(1)
print('check_request_id_beacon OK')
