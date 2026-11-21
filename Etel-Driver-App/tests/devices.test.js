// Run: node tests/devices.test.js (needs `npm ci`). Signed-in devices: src/devices/devicesLogic.ts and src/api/devices.ts (real files) and how the screen is wired.
const ts = require('typescript'); const fs = require('fs'); const path = require('path'); const Module = require('module');
const root = path.join(__dirname, '..');
function load(rel, stubs) {
  const js = ts.transpileModule(fs.readFileSync(path.join(root, rel), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2019 } }).outputText;
  const m = new Module(rel); m.paths = Module._nodeModulePaths(__dirname); const req = m.require.bind(m);
  m.require = (id) => (stubs && id in stubs ? stubs[id] : req(id)); m._compile(js, path.join(root, rel)); return m.exports;
}
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const L = load('src/devices/devicesLogic.ts');
const NOW = '2026-11-21 14:00:00';
t('a minute or two ago is "now"', L.ago('2026-11-21 13:59:30', NOW) === 'now' && L.ago(NOW, NOW) === 'now' && L.ago('2026-11-21 13:59:00', NOW) === 'now' && L.ago('2026-11-21 13:58:00', NOW) === '2 min ago');
t('minutes, hours, days', L.ago('2026-11-21 13:55:00', NOW) === '5 min ago' && L.ago('2026-11-21 11:00:00', NOW) === '3 h ago' && L.ago('2026-11-20 13:00:00', NOW) === '1 day ago' && L.ago('2026-11-19 13:00:00', NOW) === '2 days ago');
t('older than a week shows the date', L.ago('2026-11-01 09:00:00', NOW) === '2026-11-01');
t('Amharic words', L.ago(NOW, NOW, true) === 'አሁን' && L.ago('2026-11-21 13:55:00', NOW, true) === 'ከ5 ደቂቃ በፊት' && L.ago('2026-11-21 11:00:00', NOW, true) === 'ከ3 ሰዓት በፊት' && L.ago('2026-11-19 13:00:00', NOW, true) === 'ከ2 ቀን በፊት');
t('a missing time is empty, a missing "now" still shows the date', L.ago(null, NOW) === '' && L.ago('', NOW) === '' && L.ago('2026-11-20 10:00:00', '') === '2026-11-20');
t('a time later than "now" (clock drift) is "now"', L.ago('2026-11-21 14:03:00', NOW) === 'now');
const dv = (id, label, current) => ({ id, label, current, ip: '', signed_in_at: null, last_seen: null });
const list = [dv(1, 'Chrome on Windows', false), dv(2, 'ETEL phone app (Android)', true), dv(3, 'Safari on iPhone', false)];
t('this device is first, the others keep their order', L.ordered(list).map((d) => d.id).join() === '2,1,3' && list[0].id === 1, L.ordered(list).map((d) => d.id).join());
t('how many others', L.othersCount(list) === 2 && L.othersCount([dv(1, 'x', true)]) === 0 && L.othersCount([]) === 0);
t('icons', L.deviceIcon('ETEL phone app (Android)') === 'phone-portrait-outline' && L.deviceIcon('Safari on iPhone') === 'phone-portrait-outline' && L.deviceIcon('Chrome on Windows') === 'desktop-outline' && L.deviceIcon('Unknown device') === 'globe-outline');
const sent = []; let reply = {};
const A = load('src/api/devices.ts', { './client': { boot: async (a, b) => { sent.push({ a, b }); return reply; } } });
(async () => {
  reply = { devices: [dv(2, 'x', true)], now: NOW }; let r = await A.myDevices();
  t('my_devices returns the list and the server clock', sent[0].a === 'my_devices' && r.devices.length === 1 && r.now === NOW);
  reply = {}; r = await A.myDevices();
  t('an empty answer is an empty list', r.devices.length === 0 && r.now === '');
  await A.signOutDevice(7);
  t('signing one out sends its id', sent[2].a === 'device_sign_out' && sent[2].b.id === 7);
  reply = { signed_out: 2 }; const n = await A.signOutOtherDevices();
  t('signing the others out returns how many', sent[3].a === 'devices_sign_out_others' && n === 2);
  reply = {}; t('... and 0 when the server says nothing', (await A.signOutOtherDevices()) === 0);
  const app = fs.readFileSync(path.join(root, 'App.tsx'), 'utf8'), card = fs.readFileSync(path.join(root, 'src/components/ProfileCard.tsx'), 'utf8'), types = fs.readFileSync(path.join(root, 'src/navigation/types.ts'), 'utf8');
  t('the screen is registered and reachable from the profile card', /name="Devices" component=\{DevicesScreen\}/.test(app) && /navigate\('Devices'\)/.test(card) && /Devices: undefined/.test(types));
  const scr = fs.readFileSync(path.join(root, 'src/screens/DevicesScreen.tsx'), 'utf8');
  t('the current device has no sign-out button, every other one has', /!d\.current \?/.test(scr));
  t('both sign-outs ask first', (scr.match(/Alert\.alert\(/g) || []).length === 2);
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
