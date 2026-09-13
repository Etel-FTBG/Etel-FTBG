"""Verify dead files have no live references before deletion."""
import os, re

ROOT = r'C:\Users\dagianan\Downloads\EtelG\delivery'

DEAD_FILES = [
    'api\\AdminController.php',
    'api\\AdminController_b.php',
    'api\\OrderController.php',
    'api\\OrderController_b.php',
    'api\\RideController.php',
    'api\\RideController_b.php',
    'api\\ServiceGiverController.php',
    'api\\ServiceGiverController_b.php',
    'api\\routes_b.php',
    'api\\routes_c.php',
    'api\\service-givers-api.php',
    'api\\updaterapi.php',
]

# Build corpus of ALL text files (exclude my own scan scripts)
SKIP_NAMES = {'find_orphans.py', 'scan_sqli.py', 'scan_sqli_user_vars.py', 'scan_non_ascii.py'}
TEXT_EXTS = {'.php', '.js', '.html', '.htm', '.htaccess', '.json', '.md', '.txt', '.sh', '.py'}

live_refs = {}  # file -> [refs]
for d, _, fs in os.walk(ROOT):
    if any(skip in d for skip in ('.git', '\\error', '/error')):
        continue
    for f in fs:
        if f in SKIP_NAMES:
            continue
        ext = os.path.splitext(f)[1].lower()
        if ext not in TEXT_EXTS:
            continue
        p = os.path.join(d, f)
        try:
            with open(p, 'r', encoding='utf-8', errors='replace') as fh:
                content = fh.read()
        except:
            continue
        for dead in DEAD_FILES:
            base = os.path.basename(dead)
            no_ext = os.path.splitext(base)[0]
            # Look for: require, include, fetch, URL, js reference
            patterns = [
                rf"require[^;\n]*['\"][^'\"]*{re.escape(base)}['\"]",
                rf"include[^;\n]*['\"][^'\"]*{re.escape(base)}['\"]",
                rf"fetch\([^)]*{re.escape(base)}[^)]*\)",
                rf"['\"][^'\"]*{re.escape(base)}['\"]",
                rf"['\"][^'\"]*{re.escape(no_ext)}['\"]",
                rf"/{re.escape(base)}",
                rf"['\"]/{re.escape(no_ext)}\.php['\"]",
            ]
            for pat in patterns:
                for m in re.finditer(pat, content):
                    # Skip if it's a doc file (.txt, .md) describing errors fixed
                    if ext in ('.txt', '.md') and 'FIX' in content[max(0,m.start()-50):m.end()+50].upper():
                        continue
                    live_refs.setdefault(dead, []).append((os.path.relpath(p, ROOT), content[:m.start()].count('\n')+1, content[max(0,m.start()-30):m.end()+30].replace('\n',' ').strip()[:100]))

print(f"{'DEAD FILE':<45} {'SIZE':>7}  {'LIVE REFS':>10}")
print('-' * 80)
total_size = 0
for dead in DEAD_FILES:
    p = os.path.join(ROOT, dead)
    if not os.path.exists(p):
        print(f"{dead:<45} {'MISSING':>7}  -")
        continue
    size = os.path.getsize(p)
    total_size += size
    refs = live_refs.get(dead, [])
    print(f"{dead:<45} {size:>7}  {len(refs):>10}")
    for r in refs:
        print(f"    {r[0]}:{r[1]}  {r[2]}")

print()
print(f"Total dead code: {len(DEAD_FILES)} files, {total_size} bytes ({total_size/1024:.1f} KB)")