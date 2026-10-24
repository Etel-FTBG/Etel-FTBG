"""Guards the accessibility work in the admin kit: text colours keep enough contrast in light and dark, dialogs are named and trap
Tab, the drawer is not an <aside> with a dialog role, toasts are announced, there is a skip link, and the focus ring and
reduced-motion rules stay. Contrast uses the WCAG formula on the kit's own colour tokens (4.5 : 1 for text)."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
kit, nav = read('js/admin-kit.js'), read('js/admin-nav.js')
bad = []


def lum(h):
    h = h.lstrip('#')
    if len(h) == 3:
        h = ''.join(c * 2 for c in h)
    r, g, b = [int(h[i:i + 2], 16) / 255 for i in (0, 2, 4)]
    f = lambda c: c / 12.92 if c <= .03928 else ((c + .055) / 1.055) ** 2.4
    return .2126 * f(r) + .7152 * f(g) + .0722 * f(b)


def ratio(a, b):
    la, lb = lum(a), lum(b)
    if la < lb:
        la, lb = lb, la
    return (la + .05) / (lb + .05)


def tokens(line):
    return dict(re.findall(r'--([a-z0-9-]+):(#[0-9a-fA-F]{3,6})', line))


light_line = re.search(r"'\.ak\{(--ink[^']*)\}'", kit)
dark_line = re.search(r"'body\.dark-theme \.ak\{([^']*)\}'", kit)
if not light_line or not dark_line:
    bad.append('could not find the kit colour tokens')
else:
    light = tokens(light_line.group(1))
    dark = dict(light)
    dark.update(tokens(dark_line.group(1)))
    for name, t in (('light', light), ('dark', dark)):
        for fg in ('ink', 'mute', 'red', 'green', 'amber', 'blue', 'violet'):
            for bg in ('card', 'soft'):
                r = ratio(t[fg], t[bg])
                if r < 4.5:
                    bad.append('%s theme: --%s on --%s is %.2f : 1 (needs 4.5)' % (name, fg, bg, r))
        if ratio(t['focus'], t['card']) < 3:
            bad.append(name + ' theme: focus ring colour is too faint')
        if ratio(t['on-red'], t['red']) < 4.5:
            bad.append(name + ' theme: text on the solid red button is too faint')
for needle, why in (("createElement('div'); dr.className", 'the drawer must be a div (a dialog role is not allowed on aside)'), ('aria-labelledby', 'dialogs must be named'),
                    ('trapTab', 'dialogs must keep Tab inside'), ('aria-live', 'toasts must be announced'), ('focus-visible', 'a visible focus ring is required'),
                    ('prefers-reduced-motion', 'reduced motion must be respected'), ('aria-pressed', 'filter buttons must say whether they are on'),
                    ('pointer:coarse', 'touch targets must be 44 px on touch screens')):
    if needle not in kit:
        bad.append('admin-kit.js: ' + why)
if "createElement('aside')" in kit:
    bad.append('admin-kit.js: drawer is an aside again')
if 'anav-skip' not in nav or 'Skip to main content' not in nav:
    bad.append('admin-nav.js lost the skip link')
if bad:
    print('check_admin_a11y FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_admin_a11y ok')
