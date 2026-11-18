// Run: node tests/playReady.test.js (needs `npm ci`). Google Play readiness: battery permission, location disclosure, legal links, account deletion request.
const ts = require('typescript'); const fs = require('fs'); const path = require('path'); const Module = require('module');
const root = path.join(__dirname, '..');
function load(rel, stubs) {
  const js = ts.transpileModule(fs.readFileSync(path.join(root, rel), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2019 } }).outputText;
  const m = new Module(rel); m.paths = Module._nodeModulePaths(__dirname); const req = m.require.bind(m);
  m.require = (id) => (id in stubs ? stubs[id] : req(id)); m._compile(js, path.join(root, rel)); return m.exports;
}
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');

t('manifest no longer asks for the restricted battery permission', !/REQUEST_IGNORE_BATTERY_OPTIMIZATIONS/.test(read('modules/etel-overlay/android/src/main/AndroidManifest.xml')));
t('app.base.json does not list it either', !/REQUEST_IGNORE_BATTERY/.test(read('app.base.json')));
const kt = read('modules/etel-overlay/android/src/main/java/expo/modules/etelOverlay/EtelOverlayModule.kt');
t('Kotlin opens the battery list, not the one-tap dialog', /ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS/.test(kt) && !/ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS/.test(kt));

let shown = null;
const D = load('src/permissions/locationDisclosure.ts', { 'react-native': { Alert: { alert: (title, msg, btns, opts) => { shown = { title, msg, btns, opts }; } } } });
const pAgree = D.showLocationDisclosure();
t('disclosure shows a dialog with Agree and Not now', shown && shown.btns.length === 2 && shown.btns[1].text === D.LOCATION_DISCLOSURE.agree && shown.btns[0].text === D.LOCATION_DISCLOSURE.decline);
t('disclosure says background, closed app, purpose and no ads/sale', /also when the app is closed/.test(shown.msg) && /dispatch/.test(shown.msg) && /not used for advertising/.test(shown.msg));
shown.btns[1].onPress();
const pNo = D.showLocationDisclosure(); shown.btns[0].onPress();
const pDismiss = D.showLocationDisclosure(); shown.opts.onDismiss();
Promise.all([pAgree, pNo, pDismiss]).then(async ([a, n, d]) => {
  t('Agree resolves true', a === true); t('Not now resolves false', n === false); t('dismiss resolves false', d === false);
  const perm = read('src/permissions/permissions.ts'), task = read('src/location/backgroundLocationTask.ts');
  t('permission page asks the disclosure before the system dialog', perm.indexOf('showLocationDisclosure()') < perm.indexOf('requestBackgroundPermissionsAsync'));
  t('going online asks the disclosure before the system dialog', task.indexOf('showLocationDisclosure()') < task.indexOf('requestBackgroundPermissionsAsync()') && task.indexOf('showLocationDisclosure()') > 0);
  const L = load('src/legal/links.ts', {});
  t('legal links are https and point to the three pages', [L.PRIVACY_URL, L.TERMS_URL, L.DELETE_ACCOUNT_URL].every((u) => /^https:\/\/ethelmarket\.com\/delivery\/[a-z-]+\.html$/.test(u)));
  for (const f of ['privacy.html', 'terms.html', 'delete-account.html']) t('page exists on the server side: ' + f, fs.existsSync(path.join(root, '../delivery', f)));
  let sent = null;
  const A = load('src/api/account.ts', { './client': { boot: async (a, b) => { sent = { a, b }; return { success: true }; } } });
  await A.requestAccountDeletion({ name: 'Abel', phone: '0911', role: 'driver' });
  t('deletion request uses the support message action', sent.a === 'customer_send_message' && /deletion/i.test(sent.b.subject) && /Abel/.test(sent.b.body) && /0911/.test(sent.b.body));
  await A.requestAccountDeletion({});
  t('deletion request works with no profile details', sent.b.body.indexOf('()') < 0 && sent.b.body.length > 20);
  const prof = read('src/screens/ProfileScreen.tsx');
  t('profile has privacy, terms and delete rows', /Privacy policy/.test(prof) && /Terms of service/.test(prof) && /Delete my account/.test(prof));
  console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); fails.forEach((f) => console.log(' - ' + f)); process.exit(fails.length ? 1 : 0);
});
