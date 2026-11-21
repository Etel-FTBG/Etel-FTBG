// Run: node tests/light.test.js (needs `npm ci`). The golden light: src/online/lightLogic.ts (real file) and where it is used.
const ts = require('typescript'); const fs = require('fs'); const path = require('path'); const Module = require('module');
const root = path.join(__dirname, '..');
function load(rel, stubs) {
  const js = ts.transpileModule(fs.readFileSync(path.join(root, rel), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2019 } }).outputText;
  const m = new Module(rel); m.paths = Module._nodeModulePaths(__dirname); const req = m.require.bind(m);
  m.require = (id) => (stubs && id in stubs ? stubs[id] : req(id)); m._compile(js, path.join(root, rel)); return m.exports;
}
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const L = load('src/online/lightLogic.ts');
let bad = '';
for (let i = 0; i < 16; i++) { const o = { away: !!(i & 1), online: !!(i & 2), featureOn: !!(i & 4), canDraw: !!(i & 8) }; const want = o.away && o.online && o.featureOn && o.canDraw; if (L.shouldShowLight(o) !== want) bad += JSON.stringify(o); }
t('shown only when away AND online AND the owner allows it AND the phone allows it (all 16 combinations)', bad === '', bad);
t('online, app out of sight, nothing going on: the light shows', L.shouldShowLight({ away: true, online: true, featureOn: true, canDraw: true }) === true);
t('offline: it never shows (a live trip is not an input at all)', L.shouldShowLight({ away: true, online: false, featureOn: true, canDraw: true }) === false);
t('the app in front: hidden', L.shouldShowLight({ away: false, online: true, featureOn: true, canDraw: true }) === false);
t('the owner switched it off: hidden', L.shouldShowLight({ away: true, online: true, featureOn: false, canDraw: true }) === false);
t('the phone does not allow drawing over other apps: hidden', L.shouldShowLight({ away: true, online: true, featureOn: true, canDraw: false }) === false);
t('a push may wake the light only for driver, courier and service giver', L.wakeMayShowLight('driver') && L.wakeMayShowLight('courier') && L.wakeMayShowLight('service_giver') && !L.wakeMayShowLight('customer') && !L.wakeMayShowLight('admin') && !L.wakeMayShowLight(undefined) && !L.wakeMayShowLight(''));
const rd = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const bb = rd('src/online/BackgroundBadge.tsx');
t('the badge asks shouldShowLight with the real online flag, and no longer looks at the trip counts to decide', /shouldShowLight\(\{ away, online: isOnline\(\)/.test(bb) && !/c\.total\s*>\s*0/.test(bb) && !/goesOnline/.test(bb));
t('the push wake asks wakeMayShowLight before anything is shown', /if \(!wakeMayShowLight\(role\)/.test(rd('src/online/pushWake.ts')));
console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
