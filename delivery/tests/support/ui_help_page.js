// Needs: Playwright (node), the PHP server from README.md on ETEL_HOST (default http://127.0.0.1:8099) and tokens (see README.md).
const HOST = process.env.ETEL_HOST || 'http://127.0.0.1:8099'; const TOKDIR = process.env.ETEL_TOKDIR || __dirname;
// End-to-end test of the Agent portal (real files served by the PHP router; MariaDB behind it).
const { chromium } = require('playwright'); const fs = require('fs'); const zlib = require('zlib');
const { execSync } = require('child_process');
const D = __dirname, BASE = HOST + '/delivery/help/index.html';
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
  const b = await chromium.launch(); const T = tok('0911000001');
  const sql = s => q(s);
  sql("TRUNCATE TABLE sup_messages"); sql("TRUNCATE TABLE sup_tickets");
  const mk = async (theme, lang, w) => {
    const c = await b.newContext({ viewport: { width: w || 390, height: 844 }, deviceScaleFactor: 2 });
    await c.addInitScript(([t, th, l]) => { try { if (!localStorage.getItem('__s')) { localStorage.setItem('theme', th); localStorage.setItem('etel_help_lang', l); localStorage.setItem('__s', '1'); if (t) localStorage.setItem('etel_token', t); } } catch (e) {} }, [T, theme, lang]);
    const p = await c.newPage(); p.errs = [];
    p.on('pageerror', e => p.errs.push(e.message));
    p.on('console', m => { if (m.type() === 'error' && !/Failed to load resource|Maps API/.test(m.text())) p.errs.push(m.text().slice(0, 140)); });
    return [c, p];
  };
  await step('signed out', async () => {
    const c = await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 }); const p = await c.newPage();
    await p.goto(BASE, { waitUntil: 'networkidle' }); await p.waitForTimeout(1200);
    const tx = await text(p);
    ok('signed out: sign-in screen shown', !!(await p.$('#lf')) && (tx.includes('How can we help') || tx.includes('እንዴት ልንረዳዎ')));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '.') + '/help-login.png' }); await c.close();
  });
  for (const theme of ['dark', 'light']) await step('flow ' + theme, async () => {
    const [c, p] = await mk(theme, 'en'); await p.goto(BASE, { waitUntil: 'networkidle' }); await p.waitForTimeout(1500);
    ok(theme + ': home greets and lists topics', (await text(p)).includes('Hello') && (await p.$$('.hp-tile')).length === 7);
    ok(theme + ': empty state', (await text(p)).includes('no requests yet'));
    let rep = await contrastReport(p); ok(theme + ': home contrast AA (' + rep.n + ')', rep.low.length === 0, rep.low.slice(0, 5).join(' | '));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '.') + '/help-home-' + theme + '.png' });
    await p.click('.hp-tile[data-cat=payment]'); await p.waitForTimeout(800);
    ok(theme + ': topic tile pre-selects the category', await p.evaluate(() => document.querySelector('.hp-chip[data-v=payment]').getAttribute('aria-pressed')) === 'true');
    await p.fill('[name=subject]', '<img src=x onerror=window.__x=1> Top-up missing');
    await p.fill('[name=body]', 'I paid 200 birr and it is not in my wallet <script>window.__y=1</script>');
    await p.click('.hp-chip[data-v=pay]'); await p.fill('[name=related]', 'TRIP-77');
    ok(theme + ': choosing an app keeps the typed text', (await p.inputValue('[name=subject]')).includes('Top-up'));
    rep = await contrastReport(p); ok(theme + ': form contrast AA (' + rep.n + ')', rep.low.length === 0, rep.low.slice(0, 5).join(' | '));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '.') + '/help-new-' + theme + '.png' });
    await p.click('#nb'); await p.waitForTimeout(1500);
    ok(theme + ': after sending, the thread opens', /#\/t\/\d+/.test(p.url()) && (await text(p)).includes('Top-up missing'));
    ok(theme + ': HTML in subject and body is shown as text, never run', await p.evaluate(() => !window.__x && !window.__y && !document.querySelector('.hp-thread script, h1 img')));
    const tid = sql("SELECT id FROM sup_tickets ORDER BY id DESC LIMIT 1");
    ok(theme + ': saved with the chosen app, category and number', sql("SELECT CONCAT(app, ':', category, ':', related) FROM sup_tickets WHERE id=" + tid) === 'pay:payment:TRIP-77');
    await p.fill('#rf [name=body]', 'Any news?'); await p.click('#rb'); await p.waitForTimeout(1200);
    ok(theme + ': reply appears in the thread', (await text(p)).includes('Any news?'));
    sql("INSERT INTO sup_messages (ticket_id, sender, staff_id, body, created_at) VALUES (" + tid + ", 'staff', 9000, 'We added it. Sorry!', NOW()), (" + tid + ", 'note', 9000, 'SECRET NOTE', NOW())");
    sql("UPDATE sup_tickets SET status='pending', user_unread=1 WHERE id=" + tid);
    await p.goto(BASE + '#/', { waitUntil: 'networkidle' }); await p.waitForTimeout(1200);
    ok(theme + ': unread dot and the "Waiting for you" chip show on the list', !!(await p.$('.hp-dot')) && (await text(p)).includes('Waiting for you'));
    await p.click('.hp-row'); await p.waitForTimeout(1200); const tt = await text(p);
    ok(theme + ': staff reply shown as Etel Support; internal note hidden', tt.includes('We added it') && /etel support/i.test(tt) && !tt.includes('SECRET NOTE'));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '.') + '/help-thread-' + theme + '.png' });
    rep = await contrastReport(p); ok(theme + ': thread contrast AA (' + rep.n + ')', rep.low.length === 0, rep.low.slice(0, 5).join(' | '));
    await p.click('[data-act=solved]'); await p.waitForTimeout(1200); ok(theme + ': solved shows the rating panel', !!(await p.$('.hp-rate')));
    await p.click('.hp-star[data-n="4"]'); await p.waitForTimeout(300); await p.click('[data-act=rate]'); await p.waitForTimeout(1200);
    ok(theme + ': rating saved (4) and thanks shown', sql("SELECT rating FROM sup_tickets WHERE id=" + tid) === '4' && (await text(p)).includes('Thank you'));
    const bb = await (await p.$('[data-act=theme]')).boundingBox(); ok(theme + ': theme switch tap target >= 48', bb.width >= 47.5 && bb.height >= 47.5);
    await p.click('[data-act=theme]'); await p.waitForTimeout(400);
    ok(theme + ': theme switch flips and saves the shared key', await p.evaluate(() => localStorage.getItem('theme')) === (theme === 'dark' ? 'light' : 'dark'));
    ok(theme + ': no JS errors', p.errs.length === 0, p.errs.join(' | ')); await c.close();
    sql("TRUNCATE TABLE sup_messages"); sql("TRUNCATE TABLE sup_tickets");
  });
  await step('deep link from another app', async () => {
    const [c, p] = await mk('dark', 'am'); await p.goto(BASE + '?app=taxi&cat=app&ref=ABC1', { waitUntil: 'networkidle' }); await p.waitForTimeout(1500);
    const on = v => p.evaluate(v => document.querySelector('.hp-chip[data-v=' + v + ']').getAttribute('aria-pressed'), v);
    ok('deep link: opens the form with app, category and number filled', /#\/new/.test(p.url()) && await on('taxi') === 'true' && await on('app') === 'true' && (await p.inputValue('[name=related]')) === 'ABC1');
    ok('amharic: labels in Amharic', (await text(p)).includes('አዲስ ጥያቄ')); await p.screenshot({ path: (process.env.ETEL_SHOTS || '.') + '/help-new-am.png' }); await c.close();
    const [c2, p2] = await mk('dark', 'en', 320); await p2.goto(BASE, { waitUntil: 'networkidle' }); await p2.waitForTimeout(1200);
    ok('320px: no horizontal scroll (home)', await p2.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await p2.goto(BASE + '#/new', { waitUntil: 'networkidle' }); await p2.waitForTimeout(800);
    ok('320px: no horizontal scroll (form)', await p2.evaluate(() => document.documentElement.scrollWidth <= innerWidth)); await c2.close();
  });
  await step('safety hint and desk paused', async () => {
    const [c, p] = await mk('dark', 'en'); await p.goto(BASE + '?cat=safety', { waitUntil: 'networkidle' }); await p.waitForTimeout(1500);
    ok('safety: shows the call-the-police note', (await text(p)).includes('991'));
    sql("UPDATE sup_settings SET setting_value='0' WHERE setting_key='desk_enabled'"); await p.goto(BASE + '#/', { waitUntil: 'networkidle' }); await p.reload({ waitUntil: 'networkidle' }); await p.waitForTimeout(1200);
    ok('paused: banner shown and New request disabled', (await text(p)).includes('paused') && await p.evaluate(() => document.querySelector('.hp-new').disabled));
    sql("UPDATE sup_settings SET setting_value='1' WHERE setting_key='desk_enabled'"); await c.close();
  });
  await b.close(); console.log('PASSED: ' + pass); console.log('FAILED: ' + fails.length); fails.forEach(f => console.log('  X ' + f));
})();
