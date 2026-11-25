"""Guards the file-protection rules in delivery/.htaccess: backups, archives, keys, hidden files and hidden folders are never served, the browser policies keep
base-uri and object-src locked, and none of the real files the apps load is caught by those rules by mistake (so a rule can never break the apps)."""
import re
import subprocess
import sys

root = __file__.rsplit('/tests/', 1)[0]
ht = open(root + '/.htaccess', encoding='utf-8').read()
bad = []
deny = []
for m in re.finditer(r'<FilesMatch "([^"]+)">\s*(?:#[^\n]*\n\s*)*Require all denied', ht):
    deny.append(re.compile(m.group(1)))
if len(deny) < 5:
    bad.append('expected at least 5 FilesMatch deny rules, found %d' % len(deny))
blocked = lambda name: any(r.search(name) for r in deny)
for name in ('.env', '.git', '.gitignore', '.htpasswd', '.DS_Store', 'backup.zip', 'site.tar.gz', 'db.sql', 'config.php.old', 'config.php.bak', 'index.php~', 'boot.php.swp',
             'private.pem', 'server.key', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'notes.md', 'x.log', 'config.php.orig', 'a.yml', 'settings.ini'):
    if not blocked(name):
        bad.append(name + ' would be downloadable')
for name in ('index.html', 'admin.html', 'sw.js', 'manifest.json', 'manifest-admin.json', 'icon-192.png', 'core.js', 'admin.css', 'boot.php', 'chapa-return.php', 'app.apk',
             'version.json', 'assetlinks.json', 'font.woff2', 'logo.svg', 'photo.jpg', 'etel-idle-logout.js', 'jsQR.js', 'qrcode.min.js'):
    if blocked(name):
        bad.append(name + ' is blocked by mistake (the apps need it)')
# real files: nothing the apps load may be caught
files = subprocess.check_output(['git', '-C', root, 'ls-files', '.'], text=True).split('\n')
# files the apps never load; the rules rightly block them (the 9 MB logo zip was publicly downloadable until now)
KNOWN_UNUSED = ('.gitignore', 'api/.env.example', 'icons/master-logo-icons.zip')
skip = ('tests/', 'docs/', 'tools/', 'api/backups/', 'api/migrations/', 'api/seeds/', 'api/lib/', 'api/controllers/', 'api/documents_storage/')
for f in files:
    if not f or f.startswith(skip):
        continue
    n = f.rsplit('/', 1)[-1]
    if n == '.htaccess':
        continue
    if f in KNOWN_UNUSED:
        continue
    if blocked(n) and not re.search(r'\.(md|txt|sh|py|log|sql)$', n):
        bad.append('the rules would block a real file: ' + f)
if 'RewriteRule (^|/)\\.(?!well-known(/|$)) - [F]' not in ht:
    bad.append('the hidden-folder rule is missing (and .well-known must stay open)')
site = ht.split('<Files "customer.html">')[0]
for pol in re.findall(r'Header always set Content-Security-Policy\s+"([^"]*)"', ht):
    if "base-uri 'self'" not in pol or "object-src 'none'" not in pol:
        bad.append('a Content-Security-Policy lost base-uri / object-src')
if 'Header always set Content-Security-Policy' not in site:
    bad.append('the site-wide Content-Security-Policy is missing')
if bad:
    print('FAILED:\n' + '\n'.join(bad)); sys.exit(1)
print('ok: file protection rules guarded (%d deny rules)' % len(deny))
