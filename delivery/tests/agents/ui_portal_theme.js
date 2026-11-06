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
  const b = await chromium.launch();
  q("DELETE FROM fa_notifications"); const aid = AGENT[0];
  q("INSERT INTO fa_notifications (agent_id, kind, params, channel, created_at) VALUES (" + aid + ",'nudge','{\"bonus\":20}','portal',NOW()),(" + aid + ",'tier_up','{\"tier\":\"silver\"}','portal',NOW())");
  for (const theme of ['dark', 'light']) await step('theme ' + theme, async () => {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
    await ctx.addInitScript(([t, th]) => { try { if (!localStorage.getItem('__seeded')) { localStorage.setItem('etel_token', t); localStorage.setItem('theme', th); localStorage.setItem('etel_agent_lang', 'en'); localStorage.setItem('__seeded', '1'); } } catch (e) {} }, [tok('0911000001'), theme]);
    const p = await ctx.newPage(); p.errs = []; p.on('pageerror', e => p.errs.push(e.message)); p.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) p.errs.push(m.text().slice(0, 140)); });
    await p.goto(BASE, { waitUntil: 'networkidle' }); await p.waitForTimeout(1800);
    ok(theme + ': html data-theme from the shared theme key', await p.evaluate(() => document.documentElement.getAttribute('data-theme')) === theme);
    ok(theme + ': theme-color meta matches', await p.evaluate(() => document.querySelector('meta[name=theme-color]').content) === (theme === 'light' ? '#FFFBEF' : '#0a0703'));
    const sw = await p.$('[data-act=theme]'); ok(theme + ': switch present, role=switch', !!sw && await sw.getAttribute('role') === 'switch');
    ok(theme + ': switch aria-checked reflects dark', await sw.getAttribute('aria-checked') === String(theme === 'dark'));
    const bb = await sw.boundingBox(); ok(theme + ': switch tap target >= 48px', bb.width >= 47.5 && bb.height >= 47.5, bb.width + 'x' + bb.height);
    ok(theme + ': tier progress card on Home', (await text(p)).includes('Next level'), (await text(p)).slice(0, 120));
    ok(theme + ': tier card hides risk/fraud words', !/risk|fraud|reject/i.test(await p.evaluate(() => document.querySelector('.tier-prog').innerText)));
    const rep = await contrastReport(p); ok(theme + ': home contrast AA (' + rep.n + ' text items)', rep.low.length === 0, rep.low.slice(0, 6).join(' | '));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '.') + '/portal-home-' + theme + '.png' });
    await p.click('[data-act=bell]'); await p.waitForTimeout(900);
    const sheet = await p.innerText('.sheet'); ok(theme + ': nudge text in messages (plain words)', /earn ETB 20/.test(sheet), sheet.slice(0, 160)); ok(theme + ': tier_up text', /reached Silver level/.test(sheet));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '.') + '/portal-msgs-' + theme + '.png' });
    const rs = await p.evaluate(() => { const s = document.querySelector('.sheet'); return getComputedStyle(s).backgroundColor; }); ok(theme + ': sheet background follows theme', theme === 'light' ? /255, 251, 239/.test(rs) : /19, 13, 5/.test(rs), rs);
    await p.click('[data-act=closesheet]').catch(() => {}); await p.waitForTimeout(300);
    for (const v of ['code', 'refs', 'pay', 'me']) { await go(p, v); const r2 = await contrastReport(p); ok(theme + ': ' + v + ' contrast AA (' + r2.n + ')', r2.low.length === 0, r2.low.slice(0, 5).join(' | ')); if (v === 'me') await p.screenshot({ path: (process.env.ETEL_SHOTS || '.') + '/portal-me-' + theme + '.png' }); }
    // toggle
    await go(p, 'home'); await p.click('[data-act=theme]'); await p.waitForTimeout(500);
    const nt = theme === 'dark' ? 'light' : 'dark';
    ok(theme + ': click flips to ' + nt, await p.evaluate(() => document.documentElement.getAttribute('data-theme')) === nt);
    ok(theme + ': saved in shared localStorage.theme', await p.evaluate(() => localStorage.getItem('theme')) === nt);
    // reload keeps it, no flash: attribute set before CSS (script runs in <head>)
    await p.reload({ waitUntil: 'networkidle' }); await p.waitForTimeout(800); ok(theme + ': choice survives reload', await p.evaluate(() => document.documentElement.getAttribute('data-theme')) === nt);
    // another tab (e.g. Ride) changes the key -> this page follows
    await p.evaluate(() => { localStorage.setItem('theme', 'x'); }); 
    const p2 = await ctx.newPage(); await p2.goto(HOST + '/delivery/agent/index.html', { waitUntil: 'domcontentloaded' }); await p2.evaluate(th => localStorage.setItem('theme', th), theme); await p2.waitForTimeout(700);
    ok(theme + ': storage event from another tab re-themes this one', await p.evaluate(() => document.documentElement.getAttribute('data-theme')) === theme);
    ok(theme + ': no JS errors', p.errs.length === 0, p.errs.join(' | '));
    await ctx.close();
  });
  // 320px, Amharic, both themes: no horizontal overflow and header still fits
  for (const theme of ['dark', 'light']) await step('320 ' + theme, async () => {
    const ctx = await b.newContext({ viewport: { width: 320, height: 640 }, deviceScaleFactor: 2 });
    await ctx.addInitScript(([t, th]) => { try { localStorage.setItem('etel_token', t); localStorage.setItem('theme', th); } catch (e) {} }, [tok('0911000001'), theme]);
    const p = await ctx.newPage(); await p.goto(BASE, { waitUntil: 'networkidle' }); await p.waitForTimeout(1600);
    ok('320 ' + theme + ' am: no horizontal scroll', await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth), await p.evaluate(() => document.documentElement.scrollWidth + '/' + innerWidth));
    ok('320 ' + theme + ': top bar buttons all inside viewport', await p.evaluate(() => [...document.querySelectorAll('.top .ib,.top .tsw')].every(e => e.getBoundingClientRect().right <= innerWidth && e.getBoundingClientRect().left >= 0)));
    ok('320 ' + theme + ': brand text not squeezed to nothing', await p.evaluate(() => document.querySelector('.brand').getBoundingClientRect().width >= 40), String(await p.evaluate(() => document.querySelector('.brand').getBoundingClientRect().width)));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '.') + '/portal-320-' + theme + '.png' }); await ctx.close();
  });
  // login page has the switch too
  await step('login switch', async () => { const ctx = await b.newContext({ viewport: { width: 390, height: 844 } }); const p = await ctx.newPage(); await p.goto(BASE, { waitUntil: 'networkidle' }); await p.waitForTimeout(1000); ok('login: theme switch present', !!(await p.$('[data-act=theme]'))); await ctx.close(); });
  await b.close();
  console.log('PASSED: ' + pass); console.log('FAILED: ' + fails.length); fails.forEach(f => console.log('  X ' + f));
})();
