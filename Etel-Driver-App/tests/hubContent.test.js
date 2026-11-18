// Run: node tests/hubContent.test.js (needs `npm ci` for typescript). The owner's names, descriptions, icons, order, texts and banner (src/hub/hubContent.ts, real files).
const ts = require('typescript'); const fs = require('fs'); const path = require('path'); const Module = require('module');
function load(file, deps) {
  const src = fs.readFileSync(path.join(__dirname, '../src/hub/' + file), 'utf8');
  const js = ts.transpileModule(src, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2019 } }).outputText;
  const m = new Module(file); m.paths = Module._nodeModulePaths(__dirname); const req = m.require.bind(m);
  m.require = (id) => { if (deps && deps[id]) return deps[id]; if (id === 'expo-constants') return { default: { expoConfig: { extra: {} } } }; if (/\.png$/.test(id)) return 1; return req(id); };
  m._compile(js, path.join(__dirname, '../src/hub/' + file.replace('.ts', '.js'))); return m.exports;
}
const apps = load('apps.ts'); const C = load('hubContent.ts', { './apps': apps });
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const app = (k) => apps.HUB_APPS.find((a) => a.key === k);
const ICON = 'https://ethelmarket.com/delivery/uploads/hub/icon_1_1_ab.png';

C.applyHubContent(null, null, null);
t('nothing set: built-in name and description', C.appTitle(app('ride'), 'en') === 'ETEL Ride' && C.appSubtitle(app('ride'), 'en') === 'Book a ride' && C.appIconUri(app('ride')) === '' && C.appBadge(app('ride')) === '');
t('nothing set: no banner, no texts, no contacts', C.getBanner() === null && C.textFor('tagline', 'en') === '' && C.supportContacts().phone === '');
C.applyHubContent({ ride: { title_en: 'ETEL Ride Hawassa', title_am: 'AM-NAME', desc_en: 'Book a ride', desc_am: 'AM-DESC', badge: 'New', sort_order: 2, icon_url: ICON } }, null, null);
t('English phone: the owner\'s English name and description', C.appTitle(app('ride'), 'en') === 'ETEL Ride Hawassa' && C.appSubtitle(app('ride'), 'en') === 'Book a ride');
t('Amharic phone: the owner\'s Amharic name and description', C.appTitle(app('ride'), 'am') === 'AM-NAME' && C.appSubtitle(app('ride'), 'am') === 'AM-DESC');
t('label and icon', C.appBadge(app('ride')) === 'New' && C.appIconUri(app('ride')) === ICON);
t('an app the owner did not touch keeps its built-in name', C.appTitle(app('driver'), 'am') === 'ETEL Driver' && C.appIconUri(app('driver')) === '');
C.applyHubContent({ ride: { title_en: 'Only English' } }, null, null);
t('Amharic phone, no Amharic name: the owner\'s English name', C.appTitle(app('ride'), 'am') === 'Only English');
C.applyHubContent({ ride: { title_am: 'AM-ONLY' } }, null, null);
t('English phone, only an Amharic name: the built-in English name', C.appTitle(app('ride'), 'en') === 'ETEL Ride' && C.appTitle(app('ride'), 'am') === 'AM-ONLY');

// second lock on picture addresses
for (const [u, why] of [['http://ethelmarket.com/delivery/uploads/hub/a.png', 'plain http'], ['https://evil.example.com/delivery/uploads/hub/a.png', 'another site'], ['https://ethelmarket.com/delivery/uploads/items/a.png', 'not the hub folder'], ['https://ethelmarket.com/delivery/uploads/hub/../x.png', 'climbing path'], ['javascript:alert(1)', 'javascript'], ['https://user@ethelmarket.com/delivery/uploads/hub/a.png', 'user name']]) {
  C.applyHubContent({ ride: { icon_url: u } }, null, null);
  t('icon ignored: ' + why, C.appIconUri(app('ride')) === '', C.appIconUri(app('ride')));
}
// order
C.applyHubContent({ driver: { sort_order: 1 }, courier: { sort_order: 2 } }, null, null);
const names = (list) => list.map((a) => a.key).join(',');
const group = [app('ride'), app('driver'), app('courier'), app('customer')];
t('apps with a place number come first, in that order; the others keep their order', names(C.sortApps(group)) === 'driver,courier,ride,customer', names(C.sortApps(group)));
C.applyHubContent({}, null, null);
t('no place numbers: the built-in order', names(C.sortApps(group)) === 'ride,driver,courier,customer');
// texts
C.applyHubContent(null, { tagline_en: 'EN tag', tagline_am: 'AM tag', welcome_title_en: 'Welcome', support_phone: '+251911234567', support_telegram: 'https://t.me/etelsupport', support_email: 'help@ethelmarket.com' }, null);
t('tagline by language', C.textFor('tagline', 'en') === 'EN tag' && C.textFor('tagline', 'am') === 'AM tag');
t('welcome: English only, so the Amharic phone gets the English text', C.textFor('welcome_title', 'am') === 'Welcome');
t('support contacts', C.supportContacts().phone === '+251911234567' && C.supportContacts().telegram === 'https://t.me/etelsupport' && C.supportContacts().email === 'help@ethelmarket.com');
// banner
C.applyHubContent(null, null, { id: 'abc123', title_en: 'Peak hours', title_am: 'AM', text_en: 'Earn more', text_am: '', image_url: ICON, link_app: 'driver' });
const b = C.getBanner();
t('banner parsed', b && b.id === 'abc123' && b.image_url === ICON && b.link_app === 'driver');
t('banner text by language, falling back to English', C.bannerText(b, 'en').title === 'Peak hours' && C.bannerText(b, 'am').title === 'AM' && C.bannerText(b, 'am').text === 'Earn more');
C.applyHubContent(null, null, { id: 'x', title_en: 'T', text_en: '', title_am: '', text_am: '', image_url: 'https://evil.example.com/delivery/uploads/hub/a.png', link_app: '' });
t('a banner picture from another site is dropped, the banner stays', C.getBanner() && C.getBanner().image_url === '');
// junk never throws
let threw = false; try { C.applyHubContent('x', 5, 7); C.applyHubContent({ ride: null }, [], { id: 5 }); C.applyHubContent({ ride: { sort_order: 'x', title_en: 42 } }, { a: 1 }, {}); } catch (e) { threw = true; }
t('junk from the server never throws', !threw);
t('junk gives built-in values', C.appTitle(app('ride'), 'en') === 'ETEL Ride' && C.getBanner() === null);
C.applyHubContent({ ride: { title_en: 'x'.repeat(500) } }, null, null);
t('a very long name is cut to 40', C.appTitle(app('ride'), 'en').length === 40);
console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); fails.forEach((f) => console.log(' - ' + f)); process.exit(fails.length ? 1 : 0);
