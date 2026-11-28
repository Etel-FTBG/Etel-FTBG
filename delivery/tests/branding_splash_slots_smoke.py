"""Admin > Branding: the opening splash has one slot per app family (28 Nov 2026).
Runs the REAL slot table and the REAL image code of delivery/api/admin-branding.php in PHP (cut out of the file, without the login and database part)
and checks what each slot writes. Needs php with GD + WebP.   python3 delivery/tests/branding_splash_slots_smoke.py
Also checks the screen (js/admin-branding-pro.js) offers the same slots and that every file a slot writes is a file the pages really load."""
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile

root = __file__.rsplit('/delivery/tests/', 1)[0]
D = os.path.join(root, 'delivery')
php_src = open(os.path.join(D, 'api', 'admin-branding.php'), encoding='utf-8').read()
bad = []
ok = 0

def check(name, cond, extra=''):
    global ok
    if cond:
        ok += 1
    else:
        bad.append(name + (' -> ' + extra if extra else ''))

if subprocess.call(['php', '-r', 'exit(function_exists("imagewebp") ? 0 : 1);']) != 0:
    print('SKIP: php with GD + WebP not available')
    sys.exit(0)

slots = re.search(r'\$SLOTS = \[.*?\n\];', php_src, re.S).group(0)
funcs = php_src[php_src.index('function bup_square_crop'):php_src.index('$written = [];')]
handler = php_src[php_src.index("if ($cfg['type'] === 'icon') {"):php_src.index('imagedestroy($src);\n\nif (!$written)')]
harness = '<?php\nfunction bupfail($m, $c = 400) { echo "FAIL " . $m . "\\n"; exit(1); }\n$ROOT = $argv[1]; $slot = $argv[2];\n$src = imagecreatefrompng($argv[3]); $srcW = imagesx($src); $srcH = imagesy($src);\n' + slots + '\nif (!isset($SLOTS[$slot])) bupfail("Unknown slot: " . $slot);\n$cfg = $SLOTS[$slot];\n' + funcs + '\n$written = [];\n' + handler + '\necho json_encode($written);\n'

tmp = tempfile.mkdtemp()
try:
    hp = os.path.join(tmp, 'h.php')
    open(hp, 'w').write(harness)
    pic = os.path.join(tmp, 'in.png')
    subprocess.check_call(['php', '-r', '$i=imagecreatetruecolor(600,400);imagefill($i,0,0,imagecolorallocate($i,200,30,30));imagepng($i,"%s");' % pic])
    FOUR = ['splash-transport.webp', 'splash-courier.webp', 'splash-service.webp', 'splash-admin.webp']
    for slot, want in (('splash_transport', ['splash-transport.webp']), ('splash_courier', ['splash-courier.webp']), ('splash_service', ['splash-service.webp']),
                       ('splash_admin', ['splash-admin.webp']), ('splash', FOUR)):
        r = os.path.join(tmp, 'root_' + slot)
        os.makedirs(os.path.join(r, 'assets', 'images'))
        p = subprocess.run(['php', hp, r, slot, pic], capture_output=True, text=True)
        out = p.stdout.strip()
        check(slot + ': slot is accepted and writes files', p.returncode == 0 and out.startswith('['), out[:120] + p.stderr[:120])
        try:
            got = sorted(json.loads(out))
        except Exception:
            got = []
        check(slot + ': writes exactly ' + ','.join(want), got == sorted(want), str(got))
        for f in want:
            fp = os.path.join(r, 'assets', 'images', f)
            if os.path.exists(fp):
                d = open(fp, 'rb').read(16)
                check(slot + ': ' + f + ' is a WebP file', d[:4] == b'RIFF' and d[8:12] == b'WEBP')
        # a slot must not touch the other splash files
        left = sorted(set(os.listdir(os.path.join(r, 'assets', 'images'))) - set(want))
        check(slot + ': leaves the other pictures alone', left == [], str(left))
finally:
    shutil.rmtree(tmp, ignore_errors=True)

# the screen offers the same slots, with the file each one shows
js = open(os.path.join(D, 'js', 'admin-branding-pro.js'), encoding='utf-8').read()
for slot in ('splash_transport', 'splash_courier', 'splash_service', 'splash_admin', 'splash'):
    check('screen has the slot ' + slot, "['%s'," % slot in js)
check('screen no longer shows the unused master-logo-splash.png', 'master-logo-splash.png' not in js)
# the pages really load the four files
pages = ''
for f in ('customer.html', 'servicegiver.html', 'admin.html', 'courier/index.html', 'driver/index.html', 'ride/index.html'):
    pth = os.path.join(D, f)
    if os.path.exists(pth):
        pages += open(pth, encoding='utf-8', errors='replace').read()
for js_file in ('customer-early.js', 'servicegiver-splash.js'):
    pth = os.path.join(D, 'js', js_file)
    if os.path.exists(pth):
        pages += open(pth, encoding='utf-8', errors='replace').read()
for n in ('transport', 'courier', 'service', 'admin'):
    check('splash-%s.webp exists' % n, os.path.exists(os.path.join(D, 'assets', 'images', 'splash-%s.webp' % n)))
    check('a page or script loads splash-%s.webp' % n, 'splash-%s.webp' % n in pages)

if bad:
    print('FAIL:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('ok %d checks' % ok)
