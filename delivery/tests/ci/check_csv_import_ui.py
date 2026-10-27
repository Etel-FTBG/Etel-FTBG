"""Guards the CSV imports (Admin -> Menu catalog -> Import CSV, Items -> Import a menu): the panel script parses, is loaded after AdminKit and before the two
screens that open it, uses kit dialogs (never window.prompt/confirm/alert), has no inline handlers and no "-card" class, always runs a dry-run "Check file"
before "Import", asks before importing, talks to the two import actions, and both actions are admin-only (items.manage), registered as write actions and
covered by the end-to-end test tests/admin_import_smoke.py. Photo files are linked only when they exist on the server."""
import re
import subprocess

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

js = read('js/admin-csv-import.js')
if subprocess.call(['node', '--check', root + '/js/admin-csv-import.js']) != 0:
    bad.append('js/admin-csv-import.js does not parse')
if re.search(r"""[\s'"`>]on[a-z]+\s*=\s*\\?["']""", js):
    bad.append('no inline handlers in admin-csv-import.js')
if re.search(r'window\.(prompt|confirm|alert)\(|[^.\w](prompt|confirm|alert)\(', js):
    bad.append('use AdminKit dialogs, not window.prompt/confirm/alert')
if re.search(r'class="[^"]*-card', js):
    bad.append('a class name containing "-card" gets the admin glare overlay; rename it')
for must in ('window.EtelCsvImport', 'openCatalog', 'openItems', 'admin_catalog_import', 'admin_items_import', 'admin_get_service_givers',
             'dry_run: dry ? 1 : 0', 'K.confirm(', 'data-check', 'data-run', 'S.checked'):
    if must not in js:
        bad.append('admin-csv-import.js lost: ' + must)
if 'btnRun.disabled = S.busy || !S.rows || !S.checked' not in js:
    bad.append('Import must stay disabled until Check file has run')
if js.count('esc(') < 12:
    bad.append('server and file values must go through AdminKit.esc')

html = read('admin.html')
i_kit, i_csv = html.find('<script src="js/admin-kit.js'), html.find('<script src="js/admin-csv-import.js')
i_items, i_mc = html.find('<script src="js/admin-items-pro.js'), html.find('<script src="js/admin-menucat-pro.js')
if not (0 < i_kit < i_csv < i_items and i_csv < i_mc):
    bad.append('admin.html must load admin-csv-import.js after admin-kit.js and before the Items and Menu catalog screens')
if 'data-act="import"' not in read('js/admin-menucat-pro.js') or 'openCatalog' not in read('js/admin-menucat-pro.js'):
    bad.append('Menu catalog lost its Import CSV button')
if 'data-act="import"' not in read('js/admin-items-pro.js') or 'openItems' not in read('js/admin-items-pro.js'):
    bad.append('Items lost its Import button')

boot = read('api/boot.php')
adm = read('api/controllers/AdminController.php')
lib = read('api/lib/menu_catalog.php')
blk = boot[boot.index("case 'admin_catalog_import'"):boot.index("case 'admin_catalog_copy'")]
if "require_admin($pdo, 'items.manage')" not in blk:
    bad.append('admin_catalog_import must require items.manage')
blk2 = adm[adm.index("case 'admin_items_import'"):adm.index("case 'admin_get_all_items'")]
if "require_admin($pdo, 'items.manage')" not in blk2:
    bad.append('admin_items_import must require items.manage')
for name in ('admin_catalog_import', 'admin_items_import'):
    if boot.count("'" + name + "'") < 2:
        bad.append(name + ' must be listed as a write action and as an admin-only action in boot.php')
if "case 'admin_items_import'" not in boot.split("require __DIR__ . '/controllers/AdminController.php';")[0][-6000:] and "case 'admin_items_import':" not in boot:
    bad.append('admin_items_import is not routed to AdminController')
if 'beginTransaction' not in lib[lib.index('function etel_catalog_admin_import'):] or 'rollBack' not in lib[lib.index('function etel_catalog_admin_import'):]:
    bad.append('the catalog dry run must roll back')
if 'rollBack' not in blk2:
    bad.append('the items dry run must roll back')
if "is_file(dirname(__DIR__, 2) . '/img/menu/'" not in lib:
    bad.append('a photo may be linked only when its file exists on the server')
if not re.search(r'jpe\?g\|png\|webp', lib):
    bad.append('photo file names must be restricted to plain image names')
try:
    read('tests/admin_import_smoke.py')
except OSError:
    bad.append('tests/admin_import_smoke.py is missing')

if bad:
    print('check_csv_import_ui: FAIL')
    for b in bad:
        print('  -', b)
    raise SystemExit(1)
print('check_csv_import_ui: ok')
