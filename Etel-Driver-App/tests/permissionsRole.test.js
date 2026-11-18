// Run: node tests/permissionsRole.test.js (needs `npm ci`). Which phone permissions each kind of account must allow (src/permissions/permissions.ts, real file).
const ts = require('typescript'); const fs = require('fs'); const path = require('path'); const Module = require('module');
const src = fs.readFileSync(path.join(__dirname, '../src/permissions/permissions.ts'), 'utf8');
const js = ts.transpileModule(src, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2019 } }).outputText;
const m = new Module('p.js'); m.paths = Module._nodeModulePaths(__dirname); const req = m.require.bind(m);
m.require = (id) => { if (id === 'react-native') return { Alert: {}, Linking: {}, Platform: { OS: 'android' } }; if (/^expo-|etel-overlay/.test(id)) return new Proxy({}, { get: () => () => undefined }); return req(id); };
m._compile(js, path.join(__dirname, '../src/permissions/permissions.js')); const P = m.exports;
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const keys = (r) => P.requiredFor(r).join(',');
t('driver must allow everything', keys('driver') === 'camera,location,locationAlways,notifications,overlay,battery,autostart', keys('driver'));
t('courier and service giver: the same', keys('courier') === keys('driver') && keys('service_giver') === keys('driver'));
t('customer, admin and signed out: only location and notifications', keys('customer') === 'location,notifications' && keys('admin') === 'location,notifications' && keys(undefined) === 'location,notifications');
t('every required key has a title and a reason', ['driver', 'customer'].every((r) => P.permsFor(r).length === P.requiredFor(r).length && P.permsFor(r).every((p) => p.title && p.why)));
t('battery and auto-start are in the list of permissions', P.PERMS.some((p) => p.key === 'battery') && P.PERMS.some((p) => p.key === 'autostart'));
console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); fails.forEach((f) => console.log(' - ' + f)); process.exit(fails.length ? 1 : 0);
