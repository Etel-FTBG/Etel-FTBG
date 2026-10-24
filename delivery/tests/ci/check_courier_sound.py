"""Guards the courier ring add-on (release v10): js/etel-sounds.js and js/courier-sound.js parse, use no inline handlers, keep the app's own ringing
logic (only the burst is replaced), and the newest release carrying them ends with the exact current text and starts with the exact previous bundle."""
import glob
import re
import subprocess
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(p, encoding='utf-8').read()
bad = []
engine, addon = read(root + '/js/etel-sounds.js'), read(root + '/js/courier-sound.js')
for f in ('js/etel-sounds.js', 'js/courier-sound.js'):
    if subprocess.call(['node', '--check', root + '/' + f]) != 0:
        bad.append(f + ' does not parse')
for must in ('window.EtelSound', 'DynamicsCompressor', 'play: function', 'ring: function', 'stop: function', 'setMuted', 'locked: function', 'etel_sound_off'):
    if must not in engine:
        bad.append('etel-sounds.js lost: ' + must)
for name in ('job', 'order', 'progress', 'delivered', 'cancel', 'message'):
    if not re.search(r'\b' + name + r': function', engine):
        bad.append('etel-sounds.js has no "%s" sound' % name)
for must in ('_playCourierRingBurst', "play('job'", 'window.__etelCourierSound'):
    if must not in addon:
        bad.append('courier-sound.js lost: ' + must)
if re.search(r'startRingingForJob\s*=|stopRingingForJob\s*=', addon):
    bad.append('courier-sound.js must only replace the ring burst, not the ringing logic')
for f, t in (('js/etel-sounds.js', engine), ('js/courier-sound.js', addon)):
    if re.search(r"""[\s'"`>]on[a-z]+\s*=\s*\\?["']""", t):
        bad.append(f + ' must not use inline handlers')
rel = sorted((int(re.search(r'/v(\d+)/', f).group(1)), f) for f in glob.glob(root + '/js/releases/courier/v*/courier-bundle.js'))
# the release that introduced the add-on (its own header line), not later releases that merely build on it
carrying = [(n, f) for n, f in rel if 'release v%d: js/courier-sound.js (added to' % n in read(f)]
if not carrying:
    bad.append('no courier release carries the ring add-on yet (tools/build_courier_release.py --addons js/etel-sounds.js,js/courier-sound.js)')
else:
    n, f = carrying[-1]
    body = read(f)
    if not body.endswith(engine.rstrip('\n') + '\n\n/* ---- release v%d: js/courier-sound.js (added to v%d) ---- */\n' % (n, n - 1) + addon) and not body.endswith(addon):
        bad.append('release v%d must end with the current js/courier-sound.js: build a NEW version instead of editing a released one' % n)
    if engine not in body:
        bad.append('release v%d must contain the current js/etel-sounds.js' % n)
    earlier = [x for x in rel if x[0] < n]
    if earlier and not body.startswith(read(earlier[-1][1]).rstrip('\n')):
        bad.append('release v%d must start with the exact previous bundle (v%d)' % (n, earlier[-1][0]))
if bad:
    print('check_courier_sound FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_courier_sound ok')
