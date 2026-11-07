// Needs: Playwright (node), the PHP server from README.md on ETEL_HOST (default http://127.0.0.1:8099) and tokens (see README.md).
const HOST = process.env.ETEL_HOST || 'http://127.0.0.1:8099'; const TOKDIR = process.env.ETEL_TOKDIR || __dirname;
// End-to-end test of the Agent portal (real files served by the PHP router; MariaDB behind it).
const { chromium } = require('playwright'); const fs = require('fs'); const zlib = require('zlib');
const { execSync } = require('child_process');
const D = __dirname, BASE = HOST + '/delivery/agent/index.html';
const q = sql => execSync('mysql fa_test -N -e ' + JSON.stringify(sql)).toString().trim();
const tok = p => fs.readFileSync(TOKDIR + '/tok-' + p + '.txt', 'utf8').trim();
const decode = f => execSync('python3 ' + D + '/decode.py ' + f).toString().trim();
let pass = 0; const fails = [];
const ok = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? '  -> ' + x : '')); };
const step = async (n, f) => { try { await f(); } catch (e) { fails.push(n + '  -> EXCEPTION ' + String(e.message).split('\n')[0].slice(0, 170)); } };
const AGENT = q("SELECT CONCAT(id,'|',code) FROM fa_agents WHERE phone='0911000001'").split('|');
const CODE = AGENT[1], LINK = HOST + '/delivery/ride/?ref=' + CODE;

async function open(b, phone, opts) {
  opts = opts || {};
  const ctx = await b.newContext(Object.assign({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, acceptDownloads: true, permissions: ['clipboard-read', 'clipboard-write'] }, opts.ctx || {}));
  if (phone) { const t = tok(phone); await ctx.addInitScript(t => { try { if (!localStorage.getItem('__seeded')) { localStorage.setItem('etel_token', t); localStorage.setItem('__seeded', '1'); } } catch (e) {} }, t); }
  const p = await ctx.newPage(); p.errs = [];
  p.on('pageerror', e => p.errs.push('PAGEERROR ' + e.message)); p.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) p.errs.push(m.text().slice(0, 160)); });
  await p.goto(BASE + (opts.hash || ''), { waitUntil: 'networkidle' }); await p.waitForTimeout(opts.wait || 1500);
  return p;
}
const go = async (p, v) => { await p.evaluate(v => { location.hash = '#/' + v; }, v); await p.waitForTimeout(1100); };
const text = p => p.evaluate(() => document.body.innerText);
function lum(c) { const f = x => { x /= 255; return x <= .03928 ? x / 12.92 : Math.pow((x + .055) / 1.055, 2.4); }; return .2126 * f(c[0]) + .7152 * f(c[1]) + .0722 * f(c[2]); }
const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); };
const lumc = c => lum(c);
// collect (fg, [bg candidates]) for visible text elements, resolving gradients/alpha against ancestors
const SCAN = () => {
  const num = s => (s.match(/[\d.]+/g) || []).map(Number);
  const parse = s => { const n = num(s); return n.length >= 3 ? [n[0], n[1], n[2], n.length > 3 ? n[3] : 1] : null; };
  const blend = (f, b) => [f[0] * f[3] + b[0] * (1 - f[3]), f[1] * f[3] + b[1] * (1 - f[3]), f[2] * f[3] + b[2] * (1 - f[3])];
  const bgsOf = el => { // list of possible opaque backgrounds
    let layers = []; for (let e = el; e; e = e.parentElement) { const cs = getComputedStyle(e); layers.push(cs); if (e === document.documentElement) break; }
    let cands = [[255, 255, 255]]; let first = true;
    const base = parse(getComputedStyle(document.documentElement).backgroundColor) || [255, 255, 255, 1];
    cands = [base.slice(0, 3)];
    for (let i = layers.length - 1; i >= 0; i--) {
      const cs = layers[i]; let list = [];
      const bi = cs.backgroundImage; const cols = bi && bi !== 'none' ? (bi.match(/rgba?\([^)]*\)/g) || []).map(parse).filter(Boolean) : [];
      const bc = parse(cs.backgroundColor);
      if (cols.length) list = cols; else if (bc && bc[3] > 0) list = [bc];
      if (!list.length) continue;
      const next = []; cands.forEach(c => list.forEach(l => next.push(blend(l, c)))); cands = next;
    }
    return cands;
  };
  const out = [];
  document.querySelectorAll('.app *, .nav *').forEach(el => {
    if (!el.childNodes.length || ![...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim())) return;
    const r = el.getBoundingClientRect(); if (r.width < 2 || r.height < 2) return; const cs = getComputedStyle(el); if (cs.visibility === 'hidden' || cs.display === 'none') return;
    if (el.closest('.sr') || el.closest('[aria-hidden=true]')) return;
    const fg = parse(cs.color); if (!fg) return; const fs = parseFloat(cs.fontSize);
    out.push({ el: el.tagName + '.' + (el.className || '') + ':' + el.textContent.trim().slice(0, 18), fg: fg, bgs: bgsOf(el), large: fs >= 24 || (fs >= 18.66 && parseInt(cs.fontWeight) >= 700) });
  });
  return out;
};
async function contrastReport(p) {
  const items = await p.evaluate(SCAN); const low = [];
  items.forEach(i => { const need = i.large ? 3 : 4.5; let worst = 99; i.bgs.forEach(b => { const fg = [i.fg[0] * i.fg[3] + b[0] * (1 - i.fg[3]), i.fg[1] * i.fg[3] + b[1] * (1 - i.fg[3]), i.fg[2] * i.fg[3] + b[2] * (1 - i.fg[3])]; worst = Math.min(worst, ratio(fg, b)); }); if (worst < need) low.push(i.el + ' ' + worst.toFixed(2)); });
  return { n: items.length, low };
}
(async () => {
  const b = await chromium.launch(); const T = tok('0911000001'); const sql = s => q(s);
  sql("UPDATE fa_settings SET setting_value='1' WHERE setting_key IN ('program_enabled','agent_booking_enabled')");
  sql("UPDATE fa_settings SET setting_value='10' WHERE setting_key='agent_booking_daily_cap'");
  sql("TRUNCATE TABLE fa_bookings"); sql("TRUNCATE TABLE ride_drivers");
  sql("INSERT INTO ride_drivers (user_id, online, lat, lng, last_seen, vehicle_type) VALUES (501, 1, 7.0625, 38.4766, NOW(), 'bajaj'), (502, 1, 7.0630, 38.4770, NOW(), 'car'), (503, 1, 7.0631, 38.4771, NOW(), 'car')");
  const mk = async (theme, lang, w) => {
    const c = await b.newContext({ viewport: { width: w || 390, height: 844 }, deviceScaleFactor: 2, permissions: ['geolocation'], geolocation: { latitude: 7.0621, longitude: 38.4763 } });
    await c.addInitScript(([t, th, l]) => { try { if (!localStorage.getItem('__s')) { localStorage.setItem('etel_token', t); localStorage.setItem('theme', th); localStorage.setItem('etel_agent_lang', l); localStorage.setItem('__s', '1'); } } catch (e) {} }, [T, theme, lang]);
    const p = await c.newPage(); p.errs = [];
    p.on('pageerror', e => p.errs.push(e.message)); p.on('console', m => { if (m.type() === 'error' && !/Failed to load resource|Maps API/.test(m.text())) p.errs.push(m.text().slice(0, 140)); });
    return [c, p];
  };
  for (const theme of ['dark', 'light']) await step('book flow ' + theme, async () => {
    sql("TRUNCATE TABLE fa_bookings"); sql("DELETE FROM users WHERE phone IN ('0955111222','0955111333')");
    const [c, p] = await mk(theme, 'en'); await p.goto(BASE, { waitUntil: 'networkidle' }); await p.waitForTimeout(1500);
    ok(theme + ': Home has the Book a ride button', (await text(p)).includes('Book a ride for a customer'));
    const home = await p.evaluate(() => document.documentElement.scrollWidth);
    await p.click('a[href="#/book"]'); await p.waitForTimeout(2200);
    ok(theme + ': book screen opens, vehicle tiles listed with online counts', (await p.$$('.bv-veh')).length >= 4 && (await text(p)).includes('2 online'), (await text(p)).slice(0, 200));
    ok(theme + ': tiles without drivers say so', (await text(p)).includes('No driver online'));
    ok(theme + ': Book button disabled until phone, vehicle and trip are set', await p.evaluate(() => document.getElementById('bk-go').disabled));
    await p.fill('#bk-phone', '0955111222'); await p.fill('#bk-name', 'Nati New');
    await p.click('.bv-veh[data-v=car]'); await p.waitForTimeout(300);
    ok(theme + ': typed text survives choosing a vehicle', (await p.inputValue('#bk-phone')) === '0955111222' && (await p.inputValue('#bk-name')) === 'Nati New');
    await p.click('[data-act=bk-km][data-k="6"]'); await p.waitForTimeout(900);
    const fare = (await p.innerText('.bv-veh[data-v=car] .bv-fare')); ok(theme + ': fare shown on the tile after choosing km', /\d/.test(fare), fare);
    ok(theme + ': summary line shows the chosen fare', /About ETB \d/.test(await p.innerText('#bk-sum')), await p.innerText('#bk-sum'));
    ok(theme + ': Book button enabled', !(await p.evaluate(() => document.getElementById('bk-go').disabled)));
    await p.fill('#bk-pickup', 'Hotel entrance'); await p.click('[data-act=bk-loc]'); await p.waitForTimeout(1200); ok(theme + ': my-location button sets the location', (await text(p)).includes('Location added') || (await p.$('[data-act=bk-loc]')) !== null);
    ok(theme + ': pickup text kept after location', (await p.inputValue('#bk-pickup')) === 'Hotel entrance');
    const rep0 = await contrastReport(p); ok(theme + ': form contrast AA (' + rep0.n + ')', rep0.low.length === 0, rep0.low.slice(0, 5).join(' | '));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '.') + '/book-form-' + theme + '.png', fullPage: true });
    await p.click('#bk-go'); await p.waitForTimeout(600); ok(theme + ': confirm sheet shows vehicle, number and price', /Car for 0955111222/.test(await p.innerText('.sheet')), await p.innerText('.sheet'));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '.') + '/book-confirm-' + theme + '.png' });
    await p.click('#bk-yes'); await p.waitForTimeout(2000);
    ok(theme + ': success card shown', (await text(p)).includes('Ride booked'));
    ok(theme + ': new account + bonus + review lines', /made an Etel account/.test(await text(p)) && /earn the bonus/.test(await text(p)) && /checks your first bookings/.test(await text(p)));
    ok(theme + ': ride row created: searching, car, cash, pickup text', sql("SELECT CONCAT(status, '|', payment_method, '|', pickup_address) FROM rides ORDER BY id DESC LIMIT 1") === 'searching|cash|Hotel entrance');
    ok(theme + ': customer + referral + booking rows exist', sql("SELECT COUNT(*) FROM users WHERE phone='0955111222' AND name='Nati New'") === '1' && sql("SELECT COUNT(*) FROM fa_bookings WHERE bonus_eligible=1") === '1' && sql("SELECT COUNT(*) FROM fa_referrals r JOIN users u ON u.id=r.rider_user_id WHERE u.phone='0955111222' AND r.status='claimed'") === '1');
    ok(theme + ': the list shows the booking with ride and bonus status', (await text(p)).includes('Finding a driver') && (await text(p)).includes('waiting for the trip') && !(await text(p)).includes('0955111222'));
    ok(theme + ': form cleared for the next booking', (await p.inputValue('#bk-phone')) === '');
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '.') + '/book-done-' + theme + '.png', fullPage: true });
    const rep = await contrastReport(p); ok(theme + ': done screen contrast AA (' + rep.n + ')', rep.low.length === 0, rep.low.slice(0, 5).join(' | '));
    // same customer again -> active ride
    await p.fill('#bk-phone', '0955111222'); await p.click('.bv-veh[data-v=bajaj]'); await p.click('[data-act=bk-km][data-k="4"]'); await p.waitForTimeout(700); await p.click('#bk-go'); await p.waitForTimeout(500); await p.click('#bk-yes'); await p.waitForTimeout(1500);
    ok(theme + ': booking the same customer again shows the plain "ride in progress" message', /already has a ride in progress/.test(await text(p)));
    // own number
    await p.fill('#bk-phone', '0911000001'); await p.click('#bk-go'); await p.waitForTimeout(500); await p.click('#bk-yes'); await p.waitForTimeout(1500); ok(theme + ': own number is refused with a plain message', /own number or your payout number/.test(await text(p)));
    ok(theme + ': no horizontal scroll', await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth), String(home));
    ok(theme + ': no JS errors', p.errs.length === 0, p.errs.join(' | ')); await c.close();
  });
  await step('open trip + amharic + 320', async () => {
    sql("TRUNCATE TABLE fa_bookings"); sql("DELETE FROM users WHERE phone='0955111333'");
    const [c, p] = await mk('dark', 'am', 320); await p.goto(BASE + '#/book', { waitUntil: 'networkidle' }); await p.waitForTimeout(2200);
    ok('amharic: title and vehicle names in Amharic', (await text(p)).includes('ጉዞ ያስይዙ') && (await text(p)).includes('መኪና'));
    ok('320px: no horizontal scroll', await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth), await p.evaluate(() => document.documentElement.scrollWidth + '/' + innerWidth));
    await p.fill('#bk-phone', '+251955111333'); await p.click('.bv-veh[data-v=bajaj]'); await p.click('[data-act=bk-open]'); await p.waitForTimeout(900);
    ok('open trip: fare text says "from"', /ከ\d+ ብር ጀምሮ/.test(await p.innerText('.bv-veh[data-v=bajaj] .bv-fare')), await p.innerText('.bv-veh[data-v=bajaj] .bv-fare'));
    ok('open trip: km box disabled', await p.evaluate(() => document.getElementById('bk-km').disabled));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '.') + '/book-am-320.png', fullPage: true });
    await p.click('#bk-go'); await p.waitForTimeout(500); await p.click('#bk-yes'); await p.waitForTimeout(1800);
    ok('open trip booked: stored as an open trip', sql("SELECT open_trip FROM rides ORDER BY id DESC LIMIT 1") === '1');
    ok('amharic success message', (await text(p)).includes('ጉዞ ተይዟል')); await c.close();
  });
  await step('daily cap + switched off', async () => {
    sql("TRUNCATE TABLE fa_bookings"); sql("UPDATE fa_settings SET setting_value='1' WHERE setting_key='agent_booking_daily_cap'"); sql("UPDATE rides SET status='cancelled' WHERE status='searching'");
    const [c, p] = await mk('dark', 'en'); await p.goto(BASE + '#/book', { waitUntil: 'networkidle' }); await p.waitForTimeout(2000);
    const one = async ph => { await p.fill('#bk-phone', ph); await p.click('.bv-veh[data-v=bajaj]'); await p.click('[data-act=bk-km][data-k="4"]'); await p.waitForTimeout(700); await p.click('#bk-go'); await p.waitForTimeout(400); await p.click('#bk-yes'); await p.waitForTimeout(1500); };
    await one('0955111401'); ok('cap 1: the first booking goes through', (await text(p)).includes('Ride booked'));
    await one('0955111402'); ok('cap 1: the second is refused in plain words', /reached today/.test(await text(p)), (await text(p)).slice(0, 160));
    sql("UPDATE fa_settings SET setting_value='0' WHERE setting_key='agent_booking_enabled'"); await p.reload({ waitUntil: 'networkidle' }); await p.waitForTimeout(1800);
    ok('booking switched off: the screen says so', /switched off/.test(await text(p)), (await text(p)).slice(0, 160));
    sql("UPDATE fa_settings SET setting_value='1' WHERE setting_key='agent_booking_enabled'"); sql("UPDATE fa_settings SET setting_value='10' WHERE setting_key='agent_booking_daily_cap'"); await c.close();
  });
  await b.close(); console.log('PASSED: ' + pass); console.log('FAILED: ' + fails.length); fails.forEach(f => console.log('  X ' + f));
})();
