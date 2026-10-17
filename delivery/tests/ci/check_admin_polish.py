"""Guards the admin design pass: css/admin-polish.css is linked in admin.html after the other admin stylesheets, is CSS only (no @import, no url(), no script),
and still carries its main fixes (compact header, tidy floating-button stack, focus ring, form font)."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
html, css = read('admin.html'), read('css/admin-polish.css')
bad = []
order = [html.find(x) for x in ('css/admin-group1.min.css', 'css/admin-group2.min.css', 'css/admin-footer.css', 'css/admin-polish.css')]
if min(order) < 0 or order != sorted(order):
    bad.append('admin.html must load admin-polish.css after admin-group1, admin-group2 and admin-footer')
if re.search(r'@import|url\(|<script|expression\(', css):
    bad.append('admin-polish.css must be plain CSS: no @import, url(), script')
for must in ('.header .header-stat', '#dark-theme-toggle', '#_diag_fab', '#etel-refresh-btn', ':focus-visible', 'font-family: inherit', '#dash-stats.stats-grid'):
    if must not in css:
        bad.append('admin-polish.css lost: ' + must)
if css.count('{') != css.count('}'):
    bad.append('admin-polish.css has unbalanced braces')
for href in ('driver/', 'courier/'):
    if not re.search(r'<a href="%s" target="_blank" class="header-stat"[^>]*title="' % re.escape(href), html):
        bad.append('the %s header link needs a title (the label is hidden on narrower screens)' % href)
if bad:
    print('check_admin_polish FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_admin_polish ok')
