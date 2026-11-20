// Run: node tests/gpsMock.test.js (needs `npm ci`). The phone tells the server when Android says the position is mocked (fake GPS): src/api/ride.ts and the background task (real files).
const ts = require('typescript'); const fs = require('fs'); const path = require('path'); const Module = require('module');
const root = path.join(__dirname, '..');
function load(rel, stubs) {
  const js = ts.transpileModule(fs.readFileSync(path.join(root, rel), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2019 } }).outputText;
  const m = new Module(rel); m.paths = Module._nodeModulePaths(__dirname); const req = m.require.bind(m);
  m.require = (id) => (stubs && id in stubs ? stubs[id] : req(id)); m._compile(js, path.join(root, rel)); return m.exports;
}
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const sent = [];
const R = load('src/api/ride.ts', { './client': { boot: async (a, b) => { sent.push({ a, b }); return { ok: true }; } }, './driver': {}, '../utils/coords': {} });
(async () => {
  await R.updateLocation(7.06, 38.47, 10, 5, true);
  t('a mocked position is sent with mocked: true', sent[0].a === 'driver_update_location' && sent[0].b.mocked === true && sent[0].b.ride_id === 5 && sent[0].b.lat === 7.06, JSON.stringify(sent[0]));
  await R.updateLocation(7.06, 38.47, 10, undefined, false);
  t('a normal position does not carry the flag', !('mocked' in sent[1].b) && !('ride_id' in sent[1].b), JSON.stringify(sent[1]));
  await R.updateLocation(7.06, 38.47, 10);
  t('older callers (no flag) still work', !('mocked' in sent[2].b));
  await R.updateLocation(7.06, 38.47, 10, 9, undefined);
  t('an unknown flag is not sent', !('mocked' in sent[3].b));
  const task = fs.readFileSync(path.join(root, 'src/location/backgroundLocationTask.ts'), 'utf8');
  t('the background task passes the phone\'s own mocked flag (true only when it is exactly true)', /currentRideId,\s*last\.mocked === true/.test(task));
  console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); fails.forEach((f) => console.log(' - ' + f)); process.exit(fails.length ? 1 : 0);
})();
