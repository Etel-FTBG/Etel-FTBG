// Run: node tests/webGuard.test.js (needs Playwright and typescript, as the other tests here).
// The app's built-in browser (src/components/WebHost.tsx) must give the person's sign-in, the message bridge and the
// camera ONLY to ETEL's own site (src/hub/webGuard.ts). Before, any https link opened inside the app and got all three.
const ts = require('typescript'); const fs = require('fs'); const path = require('path'); const Module = require('module'); const { chromium } = require('playwright');
const root = path.join(__dirname, '..');
function load(rel, stubs) {
  const file = path.join(root, rel);
  if (!fs.existsSync(file)) return null;
  const js = ts.transpileModule(fs.readFileSync(file, 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020 } }).outputText;
  const m = new Module(rel); m.paths = Module._nodeModulePaths(__dirname); const req = m.require.bind(m); m.require = (id) => (stubs && id in stubs ? stubs[id] : req(id)); m._compile(js, file); return m.exports;
}
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const G = load('src/hub/webGuard.ts');
const S = load('src/hub/session.ts', { 'expo-secure-store': { getItemAsync: async () => null, setItemAsync: async () => {}, deleteItemAsync: async () => {} }, react: { useState: () => [], useEffect: () => {} }, '../api/client': { boot: async () => ({}), setToken: async () => {}, clearToken: async () => {} }, '../location/backgroundLocationTask': { stopDriverLocationTracking: async () => {} }, '../notifications/registerPush': { unregisterPushNotifications: async () => {}, registerForPushNotifications: async () => {} }, '../online/onlineState': { setOnlineState: () => {} } });
const host = fs.readFileSync(path.join(root, 'src/components/WebHost.tsx'), 'utf8');

t('the guard file exists (src/hub/webGuard.ts)', !!G);
if (G) {
  t('ETEL pages are ETEL', G.isEtelUrl('https://ethelmarket.com/delivery/ride/?source=app') && G.isEtelUrl('https://www.ethelmarket.com/EtelId/'));
  t('look-alike addresses are not ETEL', !G.isEtelUrl('https://ethelmarket.com.evil.example/') && !G.isEtelUrl('https://evil.example/ethelmarket.com') && !G.isEtelUrl('https://ethelmarket.com@evil.example/') && !G.isEtelUrl('https://xethelmarket.com/'));
  t('plain http is not ETEL', !G.isEtelUrl('http://ethelmarket.com/delivery/'));
  t('ETEL pages open in the app', G.navDecision('https://ethelmarket.com/delivery/customer.html') === 'load');
  t('the Chapa payment page opens in the app (so the person comes back after paying)', G.navDecision('https://checkout.chapa.co/checkout/payment/abc') === 'load');
  t('any other website opens in the phone browser', G.navDecision('https://evil.example/login') === 'external' && G.navDecision('http://example.com/') === 'external');
  t('phone, maps and Telegram links open their own apps', G.navDecision('tel:+251911000000') === 'external' && G.navDecision('https://www.google.com/maps/dir/?api=1') === 'external' && G.navDecision('https://t.me/etel') === 'external');
  t('a map or video frame inside an ETEL page may load', G.navDecision('https://www.youtube.com/embed/x', false) === 'load');
  t('unknown schemes do nothing', G.navDecision('javascript:alert(1)') === 'block' && G.navDecision('file:///data/x') === 'block');
  t('only ETEL pages may send the app messages', G.trustMessageFrom('https://ethelmarket.com/delivery/driver/') && !G.trustMessageFrom('https://evil.example/') && !G.trustMessageFrom('https://checkout.chapa.co/x') && !G.trustMessageFrom(undefined));
  G.addEtelHost('staging.example.org');
  t('a staging site set in ETEL_API_BASE_URL counts as ETEL', G.isEtelUrl('https://staging.example.org/delivery/'));
  G.ETEL_HOSTS.pop();
}
t('WebHost asks the guard which pages open in the app', /navDecision\(req\.url/.test(host) && !/return req\.url\.startsWith\('https:\/\/'\)/.test(host));
t('WebHost ignores messages from pages that are not ETEL', /if \(!trustMessageFrom\(e\.nativeEvent\.url\)\) return;/.test(host));
t('WebHost wraps the sign-in and the bridge in onlyOnEtel', /onlyOnEtel\(\(app\.syncPrefs \? sessionJs\(getSession\(\)\)/.test(host) && /\+ BRIDGE_JS\)\}/.test(host));
t('the camera is not handed to every page', !/mediaCapturePermissionGrantType="grant"/.test(host) && /mediaCapturePermissionGrantType="grantIfSameHostElsePrompt"/.test(host));

(async () => {
  if (G && S) {
    const b = await chromium.launch();
    const session = { id: 7, name: 'Test', phone: '0911000000', role: 'driver', token: 'SECRET-TOKEN-123' };
    const injected = G.onlyOnEtel(S.sessionJs(session) + "window.__bridge=1;");
    const visit = async (url) => {
      const ctx = await b.newContext(); const p = await ctx.newPage(); const errs = []; p.on('pageerror', (e) => errs.push(e.message));
      await p.route('**/*', (r) => r.fulfill({ contentType: 'text/html', body: '<!doctype html><title>x</title><p>page</p>' }));
      await p.addInitScript(injected);   // the app adds this to every page before it loads
      await p.goto(url);
      const out = await p.evaluate(() => ({ token: localStorage.getItem('etel_token'), bridge: !!window.__bridge }));
      await ctx.close(); return { out, errs };
    };
    let r = await visit('https://ethelmarket.com/delivery/driver/');
    t('on ETEL\'s site the sign-in and bridge are added (the apps keep working)', r.out.token === 'SECRET-TOKEN-123' && r.out.bridge, JSON.stringify(r));
    r = await visit('https://evil.example/');
    t('on another website the sign-in is NOT written and there is no bridge', r.out.token === null && !r.out.bridge, JSON.stringify(r));
    r = await visit('https://checkout.chapa.co/checkout/x');
    t('on the payment page the sign-in is NOT written', r.out.token === null && !r.out.bridge, JSON.stringify(r));
    t('no page errors', r.errs.length === 0, r.errs.join('|'));
    await b.close();
  }
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
