"""Guards js/etel-2fa-settings.js: the two-step set-up panel must open ONCE by itself when the server says 2fa_setup_required, and must
never reload a panel that is already showing. (Before: every refused admin call re-ran open(), so the dashboard's background calls reset
the panel to 'Off / Turn on' every few seconds and an admin could never finish the set-up.) Also guards admin.html: a 403 with
code 2fa_setup_required must not sign the admin out."""
root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
js = read('js/etel-2fa-settings.js')
if "function isOpen()" not in js:
    bad.append('etel-2fa-settings.js lost isOpen()')
if "function open() { if (isOpen()) return;" not in js:
    bad.append('open() must return at once when the panel is already showing (it reloaded the panel and wiped Step 1/2)')
if "autoOpen()" not in js or "function autoOpen()" not in js or "autoOpened" not in js:
    bad.append('the automatic open on 2fa_setup_required must go through autoOpen() (once per page load)')
if "=== '2fa_setup_required') open();" in js:
    bad.append('the fetch wrapper calls open() directly again: every refused admin call would reset the set-up panel')
html = read('admin.html')
if "b.code === '2fa_setup_required'" not in html:
    bad.append('admin.html must keep the session when the capabilities call answers 403 2fa_setup_required')
if bad:
    print('check_2fa_panel_autoopen: FAIL')
    for b in bad:
        print('  -', b)
    raise SystemExit(1)
print('check_2fa_panel_autoopen: ok')
