// Run: node tests/support.test.js (needs `npm ci`). Support requests from the phone: src/support/supportLogic.ts and src/api/support.ts (real files) and how the screens are wired.
const ts = require('typescript'); const fs = require('fs'); const path = require('path'); const Module = require('module');
const root = path.join(__dirname, '..');
function load(rel, stubs) {
  const js = ts.transpileModule(fs.readFileSync(path.join(root, rel), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2019 } }).outputText;
  const m = new Module(rel); m.paths = Module._nodeModulePaths(__dirname); const req = m.require.bind(m);
  m.require = (id) => (stubs && id in stubs ? stubs[id] : req(id)); m._compile(js, path.join(root, rel)); return m.exports;
}
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const L = load('src/support/supportLogic.ts');
t('roles map to the Support desk apps', L.appForRole('driver') === 'driver' && L.appForRole('courier') === 'courier' && L.appForRole('service_giver') === 'service' && L.appForRole('customer') === 'other' && L.appForRole(undefined) === 'other' && L.appForRole('admin') === 'other');
t('the seven categories match the server', L.SUPPORT_CATEGORIES.map((c) => c.key).join(',') === 'payment,account,trip,order,safety,app,other');
t('a title of 2 letters is refused, 3 is fine', L.validateRequest('ab', 'long enough text') !== '' && L.validateRequest('abc', 'long enough text') === '');
t('a message of 4 letters is refused, 5 is fine', L.validateRequest('A title', 'abcd') !== '' && L.validateRequest('A title', 'abcde') === '');
t('spaces do not count', L.validateRequest('   a   ', 'long enough text') !== '' && L.validateRequest('A title', '    ab    ') !== '');
t('too long is refused (161-letter title, 4001-letter message)', L.validateRequest('x'.repeat(161), 'long enough') !== '' && L.validateRequest('A title', 'x'.repeat(4001)) !== '' && L.validateRequest('x'.repeat(160), 'x'.repeat(4000)) === '');
t('Amharic letters count as letters (not bytes)', L.validateRequest('አአአ', 'አአአአአ') === '');
t('a reply needs 2 letters', L.validateReply('a') !== '' && L.validateReply('ab') === '' && L.validateReply('x'.repeat(4001)) !== '');
t('status words', L.statusLabel('open') === 'Waiting for support' && L.statusLabel('pending') === 'Support replied' && L.statusLabel('solved') === 'Solved' && L.statusLabel('closed') === 'Closed' && L.statusLabel('weird') === 'weird');
t('"solved" can be pressed only while open or pending', L.canMarkSolved('open') && L.canMarkSolved('pending') && !L.canMarkSolved('solved') && !L.canMarkSolved('closed'));
t('unread text', L.unreadText(0) === '' && L.unreadText(2) === ' (2 new)' && L.unreadText(-1) === '');
t('time is cut to the minute', L.shortTime('2026-11-20 09:30:45') === '2026-11-20 09:30' && L.shortTime(undefined) === '');

const sent = []; let reply = {};
const A = load('src/api/support.ts', { './client': { boot: async (a, b) => { sent.push({ a, b }); return reply; } } });
(async () => {
  reply = { enabled: false, csat: true, unread: 3, categories: ['a'] };
  let m = await A.supMeta();
  t('meta: desk paused, rating on, 3 unread', sent[0].a === 'sup_meta' && m.enabled === false && m.csat === true && m.unread === 3);
  reply = {}; m = await A.supMeta();
  t('meta: an empty answer means enabled, nothing unread', m.enabled === true && m.unread === 0 && m.csat === false);
  reply = { tickets: [{ id: 1 }], unread: 1 }; let r = await A.supMyTickets();
  t('my tickets', sent[2].a === 'sup_my_tickets' && r.tickets.length === 1 && r.unread === 1);
  reply = {}; r = await A.supMyTickets(); t('my tickets: empty answer is an empty list', r.tickets.length === 0 && r.unread === 0);
  reply = { ticket: { id: 5, subject: 's' }, messages: [{ from: 'me', body: 'x', at: 'y' }], can_rate: true, can_reply: false };
  r = await A.supGet(5);
  t('one ticket: id sent, messages and flags read', sent[4].a === 'sup_ticket_get' && sent[4].b.id === 5 && r.messages.length === 1 && r.can_rate === true && r.can_reply === false);
  reply = { ticket: { id: 9 } }; const tk = await A.supCreate({ app: 'driver', category: 'payment', subject: 'S', body: 'Body text', lang: 'en' });
  t('create: sends app, category, subject, body, lang', sent[5].a === 'sup_ticket_create' && sent[5].b.app === 'driver' && sent[5].b.category === 'payment' && sent[5].b.lang === 'en' && tk.id === 9);
  await A.supReply(9, 'hello'); await A.supClose(9); await A.supRate(9, 4);
  t('reply, close and rate use the desk actions', sent[6].a === 'sup_ticket_reply' && sent[6].b.id === 9 && sent[6].b.body === 'hello' && sent[7].a === 'sup_ticket_close' && sent[8].a === 'sup_ticket_rate' && sent[8].b.rating === 4);
  const app = fs.readFileSync(path.join(root, 'App.tsx'), 'utf8'), types = fs.readFileSync(path.join(root, 'src/navigation/types.ts'), 'utf8');
  const help = fs.readFileSync(path.join(root, 'src/screens/HelpScreen.tsx'), 'utf8'), prof = fs.readFileSync(path.join(root, 'src/screens/ProfileScreen.tsx'), 'utf8');
  t('the Support screen is a route, only for signed-in users', /Support: undefined/.test(types) && app.indexOf('name="Support"') > app.indexOf('<>') && app.indexOf('name="Support"') < app.indexOf('name="Login"'));
  t('the Help page opens it and shows the unread count', /navigate\('Support'\)/.test(help) && /unreadText\(unread\)/.test(help) && /supMeta\(\)/.test(help));
  t('the Profile menu shows the unread count on Help & Support', /unreadText\(supUnread\)/.test(prof) && /supMeta\(\)/.test(prof));
  t('the Emergency card and the owner contacts are still on the Help page', /Emergency/.test(help) && /supportContacts\(\)/.test(help));
  console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); fails.forEach((f) => console.log(' - ' + f)); process.exit(fails.length ? 1 : 0);
})();
