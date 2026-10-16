"""Guards the public status page: reads only api/health.php, hides owner-only details, no inline handlers/scripts, Amharic toggle present."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
html, js = read('status.html'), read('js/status.js')
bad = []
if 'js/status.js' not in html:
    bad.append('status.html does not load js/status.js')
if re.search(r'\son[a-z]+\s*=\s*["\']', html):
    bad.append('status.html must not use inline handlers')
for m in re.finditer(r'<script\b([^>]*)>', html):
    if 'src=' not in m.group(1):
        bad.append('status.html must not have an inline <script>')
if "api/health.php" not in js:
    bad.append('status.js must read api/health.php')
if re.search(r"\.(disk|backup|disk_free_pct|backup_age_h|cron_age_min)\b", js):
    bad.append('status.js must not read owner-only fields (disk, backup, ages)')
if 'noindex' not in html:
    bad.append('status.html should be noindex')
if 'am:' not in js or 'etel_status_lang' not in js:
    bad.append('status.js lost the Amharic toggle')
if bad:
    print('check_status_page FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_status_page ok')
