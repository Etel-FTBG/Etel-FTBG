// Run: node tests/calendar.test.js (needs `npm ci`). The Ethiopian calendar: src/calendar/ethiopic.ts, dateFormat.ts, calendarSetting.ts (real files) and where it is used.
const ts = require('typescript'); const fs = require('fs'); const path = require('path'); const Module = require('module');
const root = path.join(__dirname, '..');
function load(rel, stubs) {
  const js = ts.transpileModule(fs.readFileSync(path.join(root, rel), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2019 } }).outputText;
  const m = new Module(rel); m.paths = Module._nodeModulePaths(__dirname); const req = m.require.bind(m);
  m.require = (id) => (stubs && id in stubs ? stubs[id] : req(id)); m._compile(js, path.join(root, rel)); return m.exports;
}
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const E = load('src/calendar/ethiopic.ts'); const F = load('src/calendar/dateFormat.ts', { './ethiopic': E });
const eth = (y, m, d) => { const r = E.gregorianToEthiopic(y, m, d); return r.y + '-' + r.m + '-' + r.d; };
t('today: 21 Nov 2026 is Hidar 12, 2019', eth(2026, 11, 21) === '2019-3-12', eth(2026, 11, 21));
t('new year: 11 Sep 2026 is Meskerem 1, 2019, and the day before is Pagume 5, 2018', eth(2026, 9, 11) === '2019-1-1' && eth(2026, 9, 10) === '2018-13-5');
t('leap year: 11 Sep 2023 is Pagume 6, 2015 and 12 Sep 2023 is Meskerem 1, 2016', eth(2023, 9, 11) === '2015-13-6' && eth(2023, 9, 12) === '2016-1-1');
t('the millennium: 12 Sep 2007 is Meskerem 1, 2000', eth(2007, 9, 12) === '2000-1-1');
t('Ethiopian Christmas: 7 Jan 2000 is Tahsas 28, 1992 and 7 Jan 2026 is Tahsas 29, 2018', eth(2000, 1, 7) === '1992-4-28' && eth(2026, 1, 7) === '2018-4-29', eth(2026, 1, 7));
t('Genna / Timket / Adwa: 19 Jan 2026 is Tir 11, 2018; 2 Mar 2026 is Yekatit 23, 2018', eth(2026, 1, 19) === '2018-5-11' && eth(2026, 3, 2) === '2018-6-23', eth(2026, 1, 19) + ' ' + eth(2026, 3, 2));
const back = (y, m, d) => { const g = E.ethiopicToGregorian(y, m, d); return g ? g.y + '-' + g.m + '-' + g.d : null; };
t('back again', back(2019, 3, 12) === '2026-11-21' && back(2015, 13, 6) === '2023-9-11' && back(2019, 1, 1) === '2026-9-11' && back(2016, 1, 1) === '2023-9-12');
t('dates that do not exist are refused (Pagume 6 in a normal year, month 14, day 31, day 0)', back(2018, 13, 6) === null && back(2019, 14, 1) === null && back(2019, 3, 31) === null && back(2019, 3, 0) === null && E.isValidEthiopic(2018, 13, 5) && !E.isValidEthiopic(2018, 13, 6) && E.isValidEthiopic(2015, 13, 6));
t('leap years are the ones that leave 3 when divided by 4', E.isLeapEthiopic(2015) && E.isLeapEthiopic(2019) && E.isLeapEthiopic(2023) && !E.isLeapEthiopic(2016) && !E.isLeapEthiopic(2018));
let ok = true; let bad = '';
for (let jd = Date.UTC(1990, 0, 1); jd < Date.UTC(2060, 11, 31); jd += 86400000) { const g = new Date(jd); const y = g.getUTCFullYear(), m = g.getUTCMonth() + 1, d = g.getUTCDate(); const e = E.gregorianToEthiopic(y, m, d); const b = E.ethiopicToGregorian(e.y, e.m, e.d); if (!b || b.y !== y || b.m !== m || b.d !== d) { ok = false; bad = y + '-' + m + '-' + d; break; } }
t('every day from 1990 to 2060 goes there and back to the same day', ok, bad);
let consecutive = true, prev = null; for (let jd = Date.UTC(2020, 0, 1); jd < Date.UTC(2040, 0, 1); jd += 86400000) { const g = new Date(jd); const e = E.gregorianToEthiopic(g.getUTCFullYear(), g.getUTCMonth() + 1, g.getUTCDate()); const key = e.y * 10000 + e.m * 100 + e.d; if (prev) { const nextOk = (e.d === prev.d + 1 && e.m === prev.m && e.y === prev.y) || (e.d === 1 && ((e.m === prev.m + 1 && e.y === prev.y && prev.d === 30) || (e.m === 1 && e.y === prev.y + 1 && prev.m === 13) || (e.m === 13 && e.y === prev.y && prev.m === 12 && prev.d === 30))); if (!nextOk) { consecutive = false; break; } } prev = e; }
t('the days follow each other without gaps or repeats over 20 years', consecutive);

t('formats: Gregorian is exactly as before', F.fmtShort('2026-11-16', 'gregorian') === '16 Nov' && F.fmtDate('2026-11-16', 'gregorian') === '16 Nov 2026' && F.fmtDateTime('2026-11-20 09:30:45', 'gregorian') === '20 Nov 09:30' && F.fmtStamp('2026-11-20 09:30:45', 'gregorian') === '2026-11-20 09:30');
t('formats: Ethiopian in English', F.fmtShort('2026-11-21', 'ethiopian') === '12 Hidar' && F.fmtDate('2026-11-21', 'ethiopian') === '12 Hidar 2019 EC' && F.fmtDateTime('2026-11-21 09:30:00', 'ethiopian') === '12 Hidar 09:30' && F.fmtStamp('2026-11-21 09:30:45', 'ethiopian') === '12 Hidar 2019 EC 09:30');
t('formats: Ethiopian in Amharic', F.fmtShort('2026-11-21', 'ethiopian', true) === 'ኅዳር 12' && F.fmtDate('2026-11-21', 'ethiopian', true) === 'ኅዳር 12 ቀን 2019 ዓ.ም' && F.fmtDate('2026-09-10', 'ethiopian', true) === 'ጳጉሜን 5 ቀን 2018 ዓ.ም');
t('formats: a thing that is not a date comes back as it was, nothing breaks', F.fmtDate('', 'ethiopian') === '' && F.fmtDate(null, 'ethiopian') === '' && F.fmtDate('soon', 'ethiopian') === 'soon' && F.fmtStamp(undefined, 'ethiopian') === '' && F.fmtShort('2026-13-45', 'ethiopian') === '2026-13-45');
t('weekday names: 21 Nov 2026 is a Saturday in both calendars, in English or Amharic', F.weekdayName('2026-11-21') === 'Sat' && F.weekdayName('2026-11-21', true) === 'ቅዳሜ' && F.weekdayName('2026-11-16') === 'Mon' && F.weekdayName('2026-11-22', true) === 'እሁድ');
t('calendar value: only "ethiopian" counts', F.parseCalendar('ethiopian') === 'ethiopian' && F.parseCalendar('gregorian') === 'gregorian' && F.parseCalendar(null) === 'gregorian' && F.parseCalendar('x') === 'gregorian');
t('typed date, Gregorian: real dates only', F.toServerDate('2026-12-01', 'gregorian') === '2026-12-01' && F.toServerDate('2026-2-3', 'gregorian') === '2026-02-03' && F.toServerDate('2026-02-31', 'gregorian') === null && F.toServerDate('2026-13-01', 'gregorian') === null && F.toServerDate('12/01/2026', 'gregorian') === null && F.toServerDate('', 'gregorian') === null);
t('typed date, Ethiopian: converted to the Gregorian date the server stores', F.toServerDate('2019-03-12', 'ethiopian') === '2026-11-21' && F.toServerDate('2019-3-12', 'ethiopian') === '2026-11-21' && F.toServerDate('2019-01-01', 'ethiopian') === '2026-09-11' && F.toServerDate(' 2015-13-06 ', 'ethiopian') === '2023-09-11');
t('typed date, Ethiopian: Pagume 6 only in a leap year, Tir 31 never', F.toServerDate('2018-13-06', 'ethiopian') === null && F.toServerDate('2018-13-05', 'ethiopian') === '2026-09-10' && F.toServerDate('2019-05-31', 'ethiopian') === null);
t('the box can show a stored date back in the chosen calendar', F.toInputDate('2026-11-21', 'ethiopian') === '2019-03-12' && F.toInputDate('2026-11-21', 'gregorian') === '2026-11-21' && F.toInputDate('', 'ethiopian') === '' && F.toServerDate(F.toInputDate('2027-02-14', 'ethiopian'), 'ethiopian') === '2027-02-14');

let store = {}; let boom = false;
const SS = { getItemAsync: async (k) => { if (boom) throw new Error('x'); return k in store ? store[k] : null; }, setItemAsync: async (k, v) => { if (boom) throw new Error('x'); store[k] = v; } };
const React = { useState: (v) => [v, () => {}], useEffect: () => {} };
(async () => {
  let C = load('src/calendar/calendarSetting.ts', { 'expo-secure-store': SS, react: React, './dateFormat': F });
  t('starts Gregorian, and loading with nothing stored stays Gregorian', C.getCalendar() === 'gregorian' && (await C.loadCalendar()) === 'gregorian');
  const seen = []; const off = C.subscribeCalendar((c) => seen.push(c));
  await C.setCalendar('ethiopian');
  t('choosing Ethiopian is remembered and tells listeners', C.getCalendar() === 'ethiopian' && store.etel_calendar === 'ethiopian' && seen.includes('ethiopian'));
  C = load('src/calendar/calendarSetting.ts', { 'expo-secure-store': SS, react: React, './dateFormat': F });
  t('after a restart it is still Ethiopian', (await C.loadCalendar()) === 'ethiopian');
  boom = true; C = load('src/calendar/calendarSetting.ts', { 'expo-secure-store': SS, react: React, './dateFormat': F });
  t('a failing phone store means Gregorian, no crash, and the choice still works for now', (await C.loadCalendar()) === 'gregorian' && (await C.setCalendar('ethiopian'), C.getCalendar() === 'ethiopian'));
  off();

  const S = load('src/statement/statementLogic.ts', { '../calendar/dateFormat': F });
  t('statement: Gregorian labels are unchanged', S.weekLabel('2026-11-16', '2026-11-22') === '16 Nov - 22 Nov 2026' && S.shortDate('2026-11-16') === '16 Nov' && S.tripTime('2026-11-20 09:30:45') === '20 Nov 09:30' && S.dayName('2026-11-16') === 'Mon');
  t('statement: the Ethiopian week and day labels', S.weekLabel('2026-11-16', '2026-11-22', 'ethiopian') === '7 Hidar - 13 Hidar 2019 EC' && S.shortDate('2026-11-16', 'ethiopian') === '7 Hidar' && S.tripTime('2026-11-20 09:30:45', 'ethiopian') === '11 Hidar 09:30', S.weekLabel('2026-11-16', '2026-11-22', 'ethiopian'));
  t('statement: Amharic week label and day name', S.weekLabel('2026-11-16', '2026-11-22', 'ethiopian', true) === 'ኅዳር 7 - ኅዳር 13 ቀን 2019 ዓ.ም' && S.dayName('2026-11-16', true) === 'ሰኞ', S.weekLabel('2026-11-16', '2026-11-22', 'ethiopian', true));
  t('statement: a week that crosses the Ethiopian new year shows the end year', S.weekLabel('2026-09-07', '2026-09-13', 'ethiopian') === '2 Pagume - 3 Meskerem 2019 EC', S.weekLabel('2026-09-07', '2026-09-13', 'ethiopian'));
  const st = { week_start: '2026-11-16', week_end: '2026-11-22', commission_rate: 0.05, totals: { trips: 1, rider_paid: 100, promo: 0, gross: 100, commission: 5, you_keep: 95, cash_collected: 100, etel_collected: 0, km: 3 }, tips: 0, total_with_tips: 95, online_hours: 1, per_hour: 95, days: [{ date: '2026-11-16', trips: 1, gross: 100, you_keep: 95 }], trips: [], prev_week: null, next_week: null };
  const txt = S.shareText(st, 'ethiopian');
  t('the shared statement uses the chosen calendar', /7 Hidar - 13 Hidar 2019 EC/.test(txt) && /Mon 7 Hidar: 1 trip/.test(txt) && /Mon 16 Nov: 1 trip/.test(S.shareText(st)), txt);

  const rd = (p) => fs.readFileSync(path.join(root, p), 'utf8');
  t('Documents: the date is typed in the chosen calendar, converted before it is sent, and shown back', /toServerDate\(expiryDate, cal\)/.test(rd('src/screens/DocumentsScreen.tsx')) && /submitComplianceDocument\(docType, serverDate, url\)/.test(rd('src/screens/DocumentsScreen.tsx')) && /fmtDate\(status\.expiry_date, cal, am\)/.test(rd('src/screens/DocumentsScreen.tsx')));
  t('Statement, Devices, Support, Notifications and Wallet read the setting', ['StatementScreen', 'DevicesScreen', 'SupportScreen', 'NotificationsScreen', 'WalletScreen', 'DocumentsScreen'].every((n) => /useCalendar\(\)/.test(rd('src/screens/' + n + '.tsx'))));
  t('the choice is on the profile card and loaded at start', /setCalendar\(cal === 'ethiopian'/.test(rd('src/components/ProfileCard.tsx')) && /loadCalendar\(\)/.test(rd('App.tsx')));
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
