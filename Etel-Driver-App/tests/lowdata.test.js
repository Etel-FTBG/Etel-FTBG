// Run: node tests/lowdata.test.js (needs `npm ci`). Low-data mode: src/data/lowDataLogic.ts and src/data/lowData.ts (real files) and where it is used.
const ts = require('typescript'); const fs = require('fs'); const path = require('path'); const Module = require('module');
const root = path.join(__dirname, '..');
function load(rel, stubs) {
  const js = ts.transpileModule(fs.readFileSync(path.join(root, rel), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2019 } }).outputText;
  const m = new Module(rel); m.paths = Module._nodeModulePaths(__dirname); const req = m.require.bind(m);
  m.require = (id) => (stubs && id in stubs ? stubs[id] : req(id)); m._compile(js, path.join(root, rel)); return m.exports;
}
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const L = load('src/data/lowDataLogic.ts');
t('off: every gap is unchanged', L.scaleInterval(3000, false) === 3000 && L.scaleInterval(10000, false) === 10000);
t('on: gaps are 2.5 times longer', L.scaleInterval(3000, true) === 7500 && L.scaleInterval(6000, true) === 15000 && L.scaleInterval(5000, true) === 12500);
t('on: never more than 30 s apart, a gap already that long is left alone', L.scaleInterval(10000, true) === 25000 && L.scaleInterval(12000, true) === 30000 && L.scaleInterval(30000, true) === 30000 && L.scaleInterval(45000, true) === 45000);
t('a zero, negative or bad gap is returned as it is', L.scaleInterval(0, true) === 0 && L.scaleInterval(-5, true) === -5 && Number.isNaN(L.scaleInterval(NaN, true)));
t('saving text', L.savingText(6000) === '6 s -> 15 s' && L.savingText(45000) === '' && L.savingText(0) === '');
t('stored value: only "1" means on', L.parseStored('1') === true && L.parseStored('0') === false && L.parseStored(null) === false && L.parseStored(undefined) === false && L.parseStored('yes') === false && L.toStored(true) === '1' && L.toStored(false) === '0');

let store = {}; let boom = false;
const SS = { getItemAsync: async (k) => { if (boom) throw new Error('no store'); return k in store ? store[k] : null; }, setItemAsync: async (k, v) => { if (boom) throw new Error('no store'); store[k] = v; } };
(async () => {
  let D = load('src/data/lowData.ts', { 'expo-secure-store': SS, './lowDataLogic': L });
  t('starts off', D.isLowData() === false && D.pollMs(6000) === 6000);
  const seen = []; const off = D.subscribeLowData((v) => seen.push(v));
  t('loading with nothing stored stays off', (await D.loadLowData()) === false);
  await D.setLowData(true);
  t('turning it on is remembered, tells listeners and stretches the gaps', D.isLowData() === true && store.etel_low_data === '1' && seen.includes(true) && D.pollMs(6000) === 15000, JSON.stringify(store));
  D = load('src/data/lowData.ts', { 'expo-secure-store': SS, './lowDataLogic': L });
  t('after a restart it is still on', (await D.loadLowData()) === true && D.pollMs(3000) === 7500);
  await D.setLowData(false);
  t('turning it off is remembered', D.isLowData() === false && store.etel_low_data === '0' && D.pollMs(3000) === 3000);
  off(); const n = seen.length; await D.setLowData(true); t('an unsubscribed listener hears nothing', seen.length === n);
  boom = true; D = load('src/data/lowData.ts', { 'expo-secure-store': SS, './lowDataLogic': L });
  t('if the phone store fails, the app stays off and does not crash', (await D.loadLowData()) === false);
  await D.setLowData(true); t('... and the switch still works until the app closes', D.isLowData() === true);

  const rd = (p) => fs.readFileSync(path.join(root, p), 'utf8');
  t('the four screens and the golden light use the stretched gap', /pollMs\(NEARBY_POLL_MS\)/.test(rd('src/screens/RequestsScreen.tsx')) && /pollMs\(ACTIVE_RIDE_POLL_MS\)/.test(rd('src/screens/RequestsScreen.tsx')) && /pollMs\(STATUS_POLL_MS\)/.test(rd('src/screens/ActiveRideScreen.tsx')) && /pollMs\(POLL_MS\)/.test(rd('src/screens/ChatsScreen.tsx')) && /pollMs\(POLL_MS\)/.test(rd('src/online/BackgroundBadge.tsx')));
  t('the banner picture and the downloaded app icons are skipped', /image_url && !lowData/.test(rd('src/components/HubBanner.tsx')) && /lowData \? ''/.test(rd('src/components/AppTileIcon.tsx')));
  t('the web apps are told through window.ETEL_LOW_DATA', /isLowData\(\) \? 'window\.ETEL_LOW_DATA=true;'/.test(rd('src/components/WebHost.tsx')));
  t('the switch is on the profile card and loaded at start', /Save mobile data/.test(rd('src/components/ProfileCard.tsx')) && /setLowData\(next\)/.test(rd('src/components/ProfileCard.tsx')) && /loadLowData\(\)/.test(rd('App.tsx')));
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
