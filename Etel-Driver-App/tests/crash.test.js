// Run: node tests/crash.test.js (needs `npm ci`). Crash reports: src/crash/crashLogic.ts and src/crash/crashReport.ts (real files, phone modules stubbed).
const ts = require('typescript'); const fs = require('fs'); const path = require('path'); const Module = require('module');
const root = path.join(__dirname, '..');
function load(rel, stubs) {
  const js = ts.transpileModule(fs.readFileSync(path.join(root, rel), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2019 } }).outputText;
  const m = new Module(rel); m.paths = Module._nodeModulePaths(__dirname); const req = m.require.bind(m);
  m.require = (id) => (stubs && id in stubs ? stubs[id] : req(id)); m._compile(js, path.join(root, rel)); return m.exports;
}
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const L = load('src/crash/crashLogic.ts');
const ctx = { version: '1.8.2', os: 'android', osVersion: 14, uid: 7 };

t('scrub removes a bearer token, a JWT, a long hex id, a phone number and key=value secrets',
  !/abc123def/.test(L.scrub('Authorization: Bearer abc123def456.xyz')) && L.scrub('x eyJhbGciOiJIUzI1NiJ9.abcdefghijk.sig y').includes('#jwt') &&
  L.scrub('id 0123456789abcdef0123456789abcdef end') === 'id # end' && !/911234567/.test(L.scrub('call +251911234567 now')) &&
  !/hunter2/.test(L.scrub('{"password":"hunter2","a":1}')) && !/s3cr3t/.test(L.scrub('token=s3cr3t&x=1')), L.scrub('{"password":"hunter2"}'));
t('scrub leaves ordinary words alone', L.scrub('Cannot read property map of undefined') === 'Cannot read property map of undefined');

const err = new Error('Cannot read property x of undefined'); err.stack = 'TypeError: Cannot read property x of undefined\n    at render (http://10.0.0.5:8081/index.bundle?platform=android&token=abc:120:14)\n    at src/screens/Foo.tsx:88:3';
const r = L.buildReport('js', err, 'screen', ctx);
t('a report has the fixed app name, kind and message', r.app === 'etelapp' && r.kind === 'js' && r.message === 'Cannot read property x of undefined');
t('the page carries where, app version and system; ua carries the app version', /^screen - app 1\.8\.2 - android 14$/.test(r.page) && r.ua === 'ETEL app 1.8.2 (android 14)', r.page);
t('file and line come from the stack, with no query string', r.source.length > 0 && !/[?#]/.test(r.source) && r.line > 0, r.source + ':' + r.line);
t('the user id goes along, or 0', r.uid === 7 && L.buildReport('js', err, 's', Object.assign({}, ctx, { uid: 0 })).uid === 0 && L.buildReport('js', err, 's', Object.assign({}, ctx, { uid: -3 })).uid === 0);
t('a string, an object without a message and nothing at all are handled', L.buildReport('js', 'boom', 's', ctx).message === 'boom' && L.buildReport('js', { a: 1 }, 's', ctx).message === 'Unknown error' && L.buildReport('js', null, 's', ctx) === null && L.buildReport('js', undefined, 's', ctx) === null && L.buildReport('js', '   ', 's', ctx) === null);
const big = L.buildReport('js', Object.assign(new Error('m'.repeat(900)), { stack: 's'.repeat(5000) }), 'w'.repeat(300), ctx);
t('everything is cut to what the server keeps (300 / 1200 / 120 / 160 / 200)', big.message.length === 300 && big.stack.length <= 1200 && big.page.length <= 120 && big.source.length <= 160 && big.ua.length <= 200);
const lim = L.createLimiter(3); const mk = (m, k) => L.buildReport(k || 'js', new Error(m), 's', ctx);
t('the same error is sent once, numbers do not make it new', lim(mk('Failed for order 12')) === true && lim(mk('Failed for order 99')) === false && lim(mk('Other thing')) === true);
const lim3 = L.createLimiter(5); const same = new Error('Same text');
t('a different kind is a different error, the same kind is not', lim3(L.buildReport('js', same, 's', ctx)) === true && lim3(L.buildReport('promise', same, 's', ctx)) === true && lim3(L.buildReport('js', same, 's', ctx)) === false);
t('once the cap is reached nothing more is sent', lim(mk('Third distinct')) === true && lim(mk('Fourth distinct')) === false);
const lim2 = L.createLimiter(); let sent = 0; for (let i = 0; i < 50; i++) if (lim2(mk('Distinct error ' + 'abcdefghijklmnopqrstuvwxyz'[i % 26] + String.fromCharCode(97 + (i % 26)) + 'q'.repeat(i)))) sent++;
t('at most 10 reports per run, however many different errors', sent === 10, String(sent));

const posts = []; let failFetch = false; let throwFetch = false;
global.fetch = (url, opt) => { if (throwFetch) throw new Error('sync boom'); posts.push({ url, body: JSON.parse(opt.body), method: opt.method }); return failFetch ? Promise.reject(new Error('offline')) : Promise.resolve({ ok: true }); };
const stubs = {
  'react-native': { Platform: { OS: 'android', Version: 14 } },
  'expo-constants': { default: { expoConfig: { version: '1.8.2' } } },
  '../api/client': { API_BASE_URL: 'https://example.test/delivery/api/boot.php' },
  '../hub/session': { getSession: () => ({ id: 7 }) },
  './crashLogic': L,
};
const C = load('src/crash/crashReport.ts', stubs);
(async () => {
  const first = new Error('First problem'); first.stack = 'Error: First problem\n    at src/screens/Foo.tsx:10:2';
  C.reportCrash('js', first, 'screen');
  t('it posts to client-error.php next to boot.php, as JSON', posts.length === 1 && posts[0].url === 'https://example.test/delivery/api/client-error.php' && posts[0].method === 'POST' && posts[0].body.app === 'etelapp' && posts[0].body.uid === 7, JSON.stringify(posts[0]));
  C.reportCrash('js', first, 'screen');
  t('the same error is not sent twice', posts.length === 1);
  failFetch = true; C.reportCrash('js', new Error('Offline problem'), 'screen'); await new Promise((r) => setTimeout(r, 20));
  t('an offline phone does not throw or crash', posts.length === 2);
  throwFetch = true; let threw = false; try { C.reportCrash('js', new Error('Sync problem'), 'screen'); } catch (e) { threw = true; }
  t('even a fetch that throws at once is swallowed', threw === false);

  const calls = []; let prevCalled = 0; let tracker = null;
  global.ErrorUtils = { getGlobalHandler: () => (e, f) => { prevCalled++; }, setGlobalHandler: (h) => calls.push(h) };
  global.HermesInternal = { enablePromiseRejectionTracker: (o) => { tracker = o; } };
  throwFetch = false; failFetch = false;
  C.installCrashReporting(); C.installCrashReporting();
  t('install hooks the global handler once and the promise tracker', calls.length === 1 && tracker && tracker.allRejections === true);
  const before = posts.length; calls[0](new Error('Fatal one'), true);
  t('a fatal error is reported and the old handler still runs', posts.length === before + 1 && /^fatal - /.test(posts[posts.length - 1].body.page) && prevCalled === 1, posts[posts.length - 1] && posts[posts.length - 1].body.page);
  tracker.onUnhandled(1, new Error('Nobody caught this'));
  t('an unhandled promise is reported as "promise"', posts[posts.length - 1].body.kind === 'promise' && /^promise - /.test(posts[posts.length - 1].body.page));
  const rd = (p) => fs.readFileSync(path.join(root, p), 'utf8');
  t('the error screen reports, and App.tsx installs the handlers at start', /reportCrash\('js', error, 'screen'\)/.test(rd('src/components/ErrorBoundary.tsx')) && /installCrashReporting\(\);/.test(rd('App.tsx')));
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
