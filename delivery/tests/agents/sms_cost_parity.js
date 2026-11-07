// The Messages screen (js/admin-agents-messages.js) estimates SMS credits in the browser with the same rule as api/lib/fa_sms_templates.php (fa_sms_cost).
// This runs the browser code with small stand-ins and checks the same sample texts and numbers that backend_sms_templates.php checks.   node delivery/tests/agents/sms_cost_parity.js
const fs = require('fs'), vm = require('vm'), path = require('path');
const src = fs.readFileSync(path.join(__dirname, '../../js/admin-agents-messages.js'), 'utf8');
const noop = () => {};
const el = () => ({ appendChild: noop, addEventListener: noop, contains: () => false });
const win = { ETELAgents: { K: { skeleton: () => '', failText: () => '', toast: noop, confirm: () => Promise.resolve(false) }, esc: s => String(s), run: () => Promise.resolve({}), ok: () => false, dat: () => ({}), S: { tab: '', lang: 'en' }, can: () => true, T: s => s, register: noop, paintScreen: noop, busy: noop } };
const doc = { readyState: 'complete', addEventListener: noop, getElementById: () => null, createElement: el, head: el(), body: el(), documentElement: el() };
vm.runInNewContext(src, { window: win, document: doc, localStorage: { getItem: () => null, setItem: noop }, setTimeout, console });
const cost = win.__etelSmsCost; let bad = 0;
const eq = (name, got, want) => { if (JSON.stringify(got) !== JSON.stringify(want)) { bad++; console.log('X ' + name + ' got ' + JSON.stringify(got) + ' want ' + JSON.stringify(want)); } };
const am = 'አ';
eq('160 plain = 1', [cost('a'.repeat(160)).segments, cost('a'.repeat(160)).encoding], [1, 'GSM-7']);
eq('161 plain = 2 (153 per part)', [cost('a'.repeat(161)).segments, cost('a'.repeat(161)).per], [2, 153]);
eq('307 plain = 3', cost('a'.repeat(307)).segments, 3);
eq('{[]} count twice', cost('{[]}').chars, 8);
eq('70 Amharic = 1 Unicode', [cost(am.repeat(70)).segments, cost(am.repeat(70)).encoding, cost(am.repeat(70)).chars], [1, 'Unicode', 70]);
eq('71 with Amharic = 2 (67 per part)', [cost(am.repeat(70) + 'a').segments, cost(am.repeat(70) + 'a').per], [2, 67]);
eq('backtick = Unicode', cost('hello `world`').encoding, 'Unicode');
eq('empty = 0', cost('').segments, 0);
console.log(bad ? 'FAILED ' + bad : 'sms_cost_parity ok');
process.exit(bad ? 1 : 0);
