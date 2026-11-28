"""Guards the role marks on the login page role buttons (delivery/login.html, 28 Nov 2026): Customer, Ride and Driver share the transport logo, so each button has a small
gold mark (bag, person, steering wheel) that is hidden from screen readers; Courier and Service (own logos) have none; the marks are three different drawings."""
import os
import re

root = __file__.rsplit('/delivery/tests/', 1)[0]
t = open(os.path.join(root, 'delivery', 'login.html'), encoding='utf-8', errors='replace').read()
bad = []
marks = re.findall(r'<span class="del-rtab-mark" data-mark="([a-z]+)" aria-hidden="true">(.*?)</span>', t, re.S)
if sorted(k for k, _ in marks) != ['customer', 'driver', 'ride']:
    bad.append('login.html should have exactly the marks customer, ride, driver, has %s' % [k for k, _ in marks])
if len(set(s for _, s in marks)) != len(marks):
    bad.append('two role marks are the same drawing')
for k, tab in (('customer', 'tab-customer.png'), ('ride', 'tab-ride.png'), ('driver', 'tab-driver.png')):
    m = re.search(r'src="assets/images/%s[^"]*".*?</span>\s*(?:<span class="del-rtab-mark" data-mark="([a-z]+)")?' % re.escape(tab), t, re.S)
    i = t.find(tab)
    j = t.find('</button>', i)
    if 'data-mark="%s"' % k not in t[i:j]:
        bad.append('the %s button does not contain its own mark' % k)
for tab in ('tab-courier.png', 'tab-service.png'):
    i = t.find(tab)
    j = t.find('</button>', i)
    if 'del-rtab-mark' in t[i:j]:
        bad.append(tab + ' button should have no mark')
if '.del-rtab-mark' not in t or '#D4AF37' not in t[t.find('.del-rtab-mark'):t.find('.del-rtab-mark') + 400]:
    bad.append('the .del-rtab-mark style (gold dot) is missing')
if bad:
    print('FAIL check_login_role_marks:')
    for b in bad:
        print(' -', b)
    raise SystemExit(1)
print('ok check_login_role_marks')
