"""Guards the rider light theme: script and stylesheet exist and are wired in, the choice is remembered and follows the phone setting, the map style switches, and the light palette keeps text readable."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

page = read('ride/index.html')
head = page.split('</head>', 1)[0]
if 'js/ride-theme.js' not in head:
    bad.append('ride/index.html must load js/ride-theme.js in <head> so the theme is set before first paint')
if 'css/ride-light.css' not in page:
    bad.append('ride/index.html does not load css/ride-light.css')
if page.index('css/ride-light.css') < page.index('css/etel-rider-premium.css'):
    bad.append('ride-light.css must load after etel-rider-premium.css')

js = read('js/ride-theme.js')
for needle in ("data-etel-theme", "etel_ride_theme", "prefers-color-scheme", "EtelUI.mapStyles", "aria-pressed", "aria-label", "theme-color"):
    if needle not in js:
        bad.append('ride-theme.js is missing ' + needle)

css = read('css/ride-light.css')
if 'html[data-etel-theme="light"]' not in css:
    bad.append('ride-light.css must be scoped to html[data-etel-theme="light"] so the dark theme is untouched')
# every rule except the theme button, the hidden core toggle and the header comment must be scoped to the light theme
unscoped = []
body = re.sub(r'/\*.*?\*/', '', css, flags=re.S)
def split_selectors(sel):
    parts, depth, quote, cur = [], 0, '', ''
    for ch in sel:
        if quote:
            if ch == quote:
                quote = ''
        elif ch in '"\'':
            quote = ch
        elif ch in '[(':
            depth += 1
        elif ch in '])':
            depth -= 1
        elif ch == ',' and depth == 0:
            parts.append(cur.strip()); cur = ''; continue
        cur += ch
    parts.append(cur.strip())
    return [x for x in parts if x]


for sel in re.findall(r'([^{}]+)\{', body):
    for part in split_selectors(sel):
        if not part.startswith('html[data-etel-theme="light"]') and not part.startswith('.ride-theme-btn') and part != '#dark-theme-toggle':
            unscoped.append(part)
if unscoped:
    bad.append('unscoped selectors would change the dark theme: ' + ', '.join(unscoped[:5]))


def lum(h):
    h = h.lstrip('#'); r, g, b = [int(h[i:i + 2], 16) / 255 for i in (0, 2, 4)]
    f = lambda v: v / 12.92 if v <= 0.03928 else ((v + 0.055) / 1.055) ** 2.4
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b)


def ratio(a, b):
    la, lb = lum(a), lum(b)
    return (max(la, lb) + 0.05) / (min(la, lb) + 0.05)


vars_ = dict(re.findall(r'--([a-z-]+):(#[0-9A-Fa-f]{6})', css.split('}', 1)[0]))
for fg, bg, need in (('text', 'sheet', 7), ('dim', 'sheet', 4.5), ('dim', 'bg', 4.5), ('gold-lt', 'sheet', 4.5), ('green', 'sheet', 4.5), ('red', 'sheet', 4.5)):
    if fg in vars_ and bg in vars_ and ratio(vars_[fg], vars_[bg]) < need:
        bad.append('light palette: %s on %s is %.2f:1, needs %s:1' % (fg, bg, ratio(vars_[fg], vars_[bg]), need))

if 'body.dark-page input:not([type=radio])' not in css or 'pac-container' not in css:
    bad.append('ride-light.css must outrank the dark body.dark-page input rules and restyle .pac-container')

if bad:
    print('check_ride_theme FAILED:\n  ' + '\n  '.join(bad))
    sys.exit(1)
print('check_ride_theme OK')
