"""Trace references to every PHP file in the codebase.

Strategy: search across all PHP+JS+HTACCESS for any reference to each filename
(require, include, URL route, header redirect, JS fetch).
"""
import os, re, sys

ROOT = r'C:\Users\dagianan\Downloads\EtelG\delivery'

# Collect every text file
TEXT_EXTS = {'.php', '.js', '.html', '.htm', '.htaccess', '.json', '.md', '.txt', '.sh', '.py'}
files = []
for d, _, fs in os.walk(ROOT):
    if any(skip in d for skip in ('.git', '\\error', '/error')):
        continue
    for f in fs:
        ext = os.path.splitext(f)[1].lower()
        if ext in TEXT_EXTS:
            files.append(os.path.join(d, f))

# Read all into memory
corpus = {}
for p in files:
    try:
        with open(p, 'r', encoding='utf-8', errors='replace') as fh:
            corpus[p] = fh.read()
    except:
        corpus[p] = ''

# Files we want to evaluate as "is this dead code?"
SUSPECTS = [
    # True duplicates of controllers/ canonical files
    'api\\AdminController.php',
    'api\\AdminController_b.php',
    'api\\OrderController.php',
    'api\\OrderController_b.php',
    'api\\RideController.php',
    'api\\RideController_b.php',
    'api\\ServiceGiverController.php',
    'api\\ServiceGiverController_b.php',
    # Route scripts that may or may not be used
    'api\\routes_b.php',
    'api\\routes_c.php',
    'api\\r2.php',
    'api\\r3.php',
    # Other potentially dead files
    'api\\updaterapi.php',
    'api\\updater.php',   # api/updater.php
    'api\\backfill_open_trip_fares.php',
    'api\\diagnose.php',
    'api\\etel-os.php',
    'api\\etelid_auth.php',
    'api\\service-givers-api.php',
    'api\\telebirr-callback.php',
    'api\\telegram_webhook.php',
    'api\\upload.php',
    'api\\config-etb.php',
    'api\\config.php',
    # Root-level
    'updater.php',
]

def normalize(s):
    return s.replace('/', os.sep).replace('\\', os.sep)

def find_references(target):
    """Look for any reference to target across all files."""
    t = normalize(target)
    base = os.path.basename(t)
    no_ext = os.path.splitext(base)[0]
    refs = []

    for ref_path, content in corpus.items():
        if os.path.normcase(ref_path) == os.path.normcase(os.path.join(ROOT, t)):
            continue
        for n in [t, base, no_ext]:
            patterns = [
                rf"require[^;\n]*['\"][^'\"]*{re.escape(n)}['\"]",
                rf"include[^;\n]*['\"][^'\"]*{re.escape(n)}['\"]",
                rf"fetch\([^)]*{re.escape(n)}[^)]*\)",
                rf"\.{re.escape(n)}",
                rf"['\"][^'\"]*{re.escape(n)}['\"]",
            ]
            for pat in patterns:
                for m in re.finditer(pat, content):
                    line_num = content[:m.start()].count('\n') + 1
                    ctx = content[max(0,m.start()-40):m.end()+40].replace('\n', ' ').strip()[:120]
                    refs.append((os.path.relpath(ref_path, ROOT), line_num, ctx))
                    break
    return refs

for s in SUSPECTS:
    refs = find_references(s)
    status = 'ORPHAN' if not refs else f'{len(refs)} refs'
    print(f"{s}: {status}")
    for r in refs[:5]:
        print(f"    {r[0]}:{r[1]}  {r[2]}")
    print()