// Run: node tests/appStatus.test.js (needs `npm ci`). Maintenance page and "update the app" page rules: src/status/statusLogic.ts (real file).
const ts = require('typescript'); const fs = require('fs'); const path = require('path'); const Module = require('module');
const root = path.join(__dirname, '..');
function load(rel, stubs) {
  const js = ts.transpileModule(fs.readFileSync(path.join(root, rel), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2019 } }).outputText;
  const m = new Module(rel); m.paths = Module._nodeModulePaths(__dirname); const req = m.require.bind(m);
  m.require = (id) => (stubs && id in stubs ? stubs[id] : req(id)); m._compile(js, path.join(root, rel)); return m.exports;
}
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const L = load('src/status/statusLogic.ts');
const clear = { maintenance: { on: false, text_en: '', text_am: '' }, update: { required: false, min: '', text_en: '', text_am: '', url: '' } };
const withM = (o) => ({ ...clear, maintenance: { ...clear.maintenance, ...o } });
const withU = (o) => ({ ...clear, update: { ...clear.update, ...o } });

t('no answer = nothing blocked', L.decide(null, 'driver', 'en') === null);
t('all clear = nothing blocked', L.decide(clear, 'driver', 'en') === null);
let b = L.decide(withM({ on: true, text_en: 'Back at 3', text_am: 'ሶስት' }), 'driver', 'en');
t('maintenance blocks a driver with the English text', b && b.kind === 'maintenance' && b.text === 'Back at 3', JSON.stringify(b));
b = L.decide(withM({ on: true, text_en: 'Back at 3', text_am: 'ሶስት' }), 'customer', 'am');
t('Amharic is used when the phone is in Amharic', b && b.text === 'ሶስት');
b = L.decide(withM({ on: true, text_en: 'Back at 3' }), 'customer', 'am');
t('missing Amharic falls back to English', b && b.text === 'Back at 3');
b = L.decide(withM({ on: true }), undefined, 'en');
t('no text at all: a built-in message', b && b.kind === 'maintenance' && b.text.length > 20);
b = L.decide(withM({ on: true }), undefined, 'am');
t('no text at all, Amharic: a built-in Amharic message', b && /[ሀ-፿]/.test(b.text));
t('signed out phones are blocked too (role unknown)', L.decide(withM({ on: true }), undefined, 'en') !== null);
t('an admin account is NOT blocked by maintenance (so the owner can test)', L.decide(withM({ on: true, text_en: 'x' }), 'admin', 'en') === null);
b = L.decide(withU({ required: true, min: '1.8.0', text_en: 'Update please', url: 'https://play.google.com/store/apps/details?id=com.etelgroup.driver' }), 'driver', 'en');
t('an old app must update, with text, minimum and address', b && b.kind === 'update' && b.text === 'Update please' && b.min === '1.8.0' && /play\.google\.com/.test(b.url), JSON.stringify(b));
b = L.decide(withU({ required: true, min: '1.8.0' }), 'admin', 'en');
t('an old app must update even for an admin (old code may not work)', b && b.kind === 'update');
b = L.decide({ maintenance: { on: true, text_en: 'm', text_am: '' }, update: { required: true, min: '1.8.0', text_en: 'u', text_am: '', url: '' } }, 'driver', 'en');
t('update wins over maintenance', b && b.kind === 'update');
b = L.decide(withU({ required: true, min: '1.8.0', url: 'https://evil.example.com/a.apk' }), 'driver', 'en');
t('an address outside Play, Expo and our site is dropped (no button)', b && b.url === '', JSON.stringify(b));
for (const ok of ['https://play.google.com/store/apps/details?id=x', 'https://expo.dev/accounts/a/b', 'https://build.expo.dev/x.apk', 'https://ethelmarket.com/delivery/install.html', 'https://www.ethelmarket.com/x'])
  t('update address allowed: ' + ok, L.updateUrl(ok) === ok);
for (const bad of ['http://play.google.com/x', 'https://play.google.com.evil.com/x', 'https://evil.com/?https://play.google.com/', 'javascript:alert(1)', 'https://user@play.google.com/x', 'https://notexpo.dev/x', '', undefined])
  t('update address refused: ' + bad, L.updateUrl(bad) === '');
t('a broken answer is ignored (clean returns null)', L.cleanStatus(null) === null && L.cleanStatus({}) === null && L.cleanStatus('x') === null && L.cleanStatus({ maintenance: {} }) === null);
const c = L.cleanStatus({ maintenance: { on: 'yes', text_en: 5 }, update: { required: 1, min: null } });
t('odd values never switch a page on (only real true counts)', c && c.maintenance.on === false && c.update.required === false && c.maintenance.text_en === '' && c.update.min === '');
t('a good answer is kept', L.cleanStatus(withM({ on: true, text_en: 'a' })).maintenance.on === true);
// the hook file asks with auth off and treats failure as all clear
const sent = []; let mode = 'ok';
const A = load('src/status/appStatus.ts', {
  react: { useEffect() {}, useRef: () => ({ current: 0 }), useState: () => [null, () => {}] }, 'react-native': { AppState: { addEventListener: () => ({ remove() {} }) } },
  'expo-constants': { __esModule: true, default: { expoConfig: { version: '1.7.0' } } }, '../api/client': { boot: async (a, b, o) => { sent.push({ a, b, o }); if (mode === 'throw') throw new Error('offline'); return mode === 'junk' ? 'nope' : clear; } },
  './statusLogic': L });
(async () => {
  let r = await A.fetchAppStatus();
  t('the phone sends its version, with no sign-in needed', sent[0].a === 'app_status' && sent[0].b.version === '1.7.0' && sent[0].o.auth === false && A.APP_VERSION === '1.7.0', JSON.stringify(sent[0]));
  t('a good answer comes back', r && r.maintenance.on === false);
  mode = 'throw'; t('no network = all clear (null), never a block', (await A.fetchAppStatus()) === null);
  mode = 'junk'; t('a junk answer = all clear (null)', (await A.fetchAppStatus()) === null);
  const gate = fs.readFileSync(path.join(root, 'src/status/StatusGate.tsx'), 'utf8'), app = fs.readFileSync(path.join(root, 'App.tsx'), 'utf8');
  t('the gate keeps the app mounted underneath (nothing is reset)', /\{children\}/.test(gate) && /absoluteFill/.test(gate));
  t('App.tsx puts the status gate outside the permission gate', app.indexOf('<StatusGate>') > 0 && app.indexOf('<StatusGate>') < app.indexOf('<PermissionGate>') && app.indexOf('</PermissionGate>') < app.indexOf('</StatusGate>'));
  console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); fails.forEach((f) => console.log(' - ' + f)); process.exit(fails.length ? 1 : 0);
})();
