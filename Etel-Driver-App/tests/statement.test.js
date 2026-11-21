// Run: node tests/statement.test.js (needs `npm ci`). The weekly statement: src/statement/statementLogic.ts and src/api/statement.ts (real files) and how the screen is wired.
const ts = require('typescript'); const fs = require('fs'); const path = require('path'); const Module = require('module');
const root = path.join(__dirname, '..');
function load(rel, stubs) {
  const js = ts.transpileModule(fs.readFileSync(path.join(root, rel), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2019 } }).outputText;
  const m = new Module(rel); m.paths = Module._nodeModulePaths(__dirname); const req = m.require.bind(m);
  m.require = (id) => (stubs && id in stubs ? stubs[id] : req(id)); m._compile(js, path.join(root, rel)); return m.exports;
}
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ETH = load('src/calendar/ethiopic.ts'); const DF = load('src/calendar/dateFormat.ts', { './ethiopic': ETH });
const L = load('src/statement/statementLogic.ts', { '../calendar/dateFormat': DF });
t('money groups thousands and keeps two decimals', L.money(1234.5) === 'ETB 1,234.50' && L.money(0) === 'ETB 0.00' && L.money(1234567.891) === 'ETB 1,234,567.89');
t('money copes with text, nothing and bad values', L.money('12.5') === 'ETB 12.50' && L.money(undefined) === 'ETB 0.00' && L.money('abc') === 'ETB 0.00' && L.money(NaN) === 'ETB 0.00');
t('a negative amount keeps its sign', L.money(-5) === '-ETB 5.00');
t('short date and week label', L.shortDate('2026-11-16') === '16 Nov' && L.weekLabel('2026-11-16', '2026-11-22') === '16 Nov - 22 Nov 2026' && L.shortDate('') === '');
t('a week across the new year shows the end year', L.weekLabel('2026-12-28', '2027-01-03') === '28 Dec - 3 Jan 2027');
t('day names: 16 Nov 2026 is a Monday, 22 Nov a Sunday', L.dayName('2026-11-16') === 'Mon' && L.dayName('2026-11-22') === 'Sun' && L.dayName('2026-11-20') === 'Fri' && L.dayName('x') === '');
t('trip time', L.tripTime('2026-11-20 09:30:45') === '20 Nov 09:30' && L.tripTime('2026-11-20') === '20 Nov');
t('payment words', L.payLabel('cash') === 'Cash' && L.payLabel('etel') === 'Paid through ETEL' && L.payLabel('') === 'Cash');
t('percent', L.percent(0.05) === '5%' && L.percent(0.075) === '7.5%' && L.percent(0) === '0%');
t('bar fraction: best day is full, empty day is nothing, a small day is still visible', L.barFraction(100, 100) === 1 && L.barFraction(0, 100) === 0 && L.barFraction(1, 100) === 0.06 && L.barFraction(50, 0) === 0 && L.barFraction(50, 100) === 0.5);
const st = { week_start: '2026-11-16', week_end: '2026-11-22', commission_rate: 0.05, totals: { trips: 2, rider_paid: 300, promo: 20, gross: 320, commission: 16, you_keep: 304, cash_collected: 200, etel_collected: 100, km: 9 }, tips: 10, total_with_tips: 314, online_hours: 5, per_hour: 62.8, days: [{ date: '2026-11-16', trips: 1, gross: 160, you_keep: 152 }, { date: '2026-11-17', trips: 0, gross: 0, you_keep: 0 }], trips: [], prev_week: null, next_week: null };
const txt = L.shareText(st);
t('share text carries the week, totals and days', /16 Nov - 22 Nov 2026/.test(txt) && /You keep: ETB 304.00/.test(txt) && /ETEL fee \(5%\): -ETB 16.00/.test(txt) && /Total with tips: ETB 314.00/.test(txt) && /Per hour: ETB 62.80/.test(txt) && /Mon 16 Nov: 1 trip, ETB 152.00/.test(txt) && /Tue 17 Nov: 0 trips, ETB 0.00/.test(txt), txt);
t('no per hour line when there are no hours', !/Per hour/.test(L.shareText(Object.assign({}, st, { per_hour: null }))));
const sent = []; const A = load('src/api/statement.ts', { './client': { boot: async (a, b) => { sent.push({ a, b }); return st; } } });
(async () => {
  await A.getStatement(); await A.getStatement('2026-11-09');
  t('asks driver_get_statement, this week by default and a given Monday otherwise', sent[0].a === 'driver_get_statement' && Object.keys(sent[0].b).length === 0 && sent[1].b.week_start === '2026-11-09', JSON.stringify(sent));
  const app = fs.readFileSync(path.join(root, 'App.tsx'), 'utf8'), prof = fs.readFileSync(path.join(root, 'src/screens/ProfileScreen.tsx'), 'utf8'), types = fs.readFileSync(path.join(root, 'src/navigation/types.ts'), 'utf8');
  t('the Statement screen is registered and reachable from Profile', /name="Statement" component=\{StatementScreen\}/.test(app) && /navigate\('Statement'\)/.test(prof) && /Statement: undefined/.test(types));
  const scr = fs.readFileSync(path.join(root, 'src/screens/StatementScreen.tsx'), 'utf8');
  t('the week arrows are off when there is no other week', /disabled=\{!st\.prev_week/.test(scr) && /disabled=\{!st\.next_week/.test(scr));
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
