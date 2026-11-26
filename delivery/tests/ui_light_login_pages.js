// The sign-in pages and the plain pages start LIGHT, with readable text (contrast checked), on a light phone and on a dark phone.
// The two sign-in pages keep a saved "dark" choice (dark-theme). Real pages and files, fake API.
//   NODE_PATH=/opt/node22/lib/node_modules node delivery/tests/ui_light_login_pages.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.webp': 'image/webp' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });

// in the page: effective background of an element (alpha composed over its ancestors), its text colour, the WCAG contrast ratio, and the background brightness
const probe = (sel) => {
  const parse = s => { const m = s.match(/[\d.]+/g) || []; return { r: +m[0] || 0, g: +m[1] || 0, b: +m[2] || 0, a: m.length > 3 ? +m[3] : 1 }; };
  const over = (top, bot) => ({ r: top.r * top.a + bot.r * (1 - top.a), g: top.g * top.a + bot.g * (1 - top.a), b: top.b * top.a + bot.b * (1 - top.a), a: 1 });
  const lum = c => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
  const el = document.querySelector(sel); if (!el) return { missing: true };
  let layers = []; for (let e = el; e; e = e.parentElement) { const c = parse(getComputedStyle(e).backgroundColor); if (c.a > 0) layers.push(c); if (c.a >= 0.999) break; }
  let bg = { r: 255, g: 255, b: 255, a: 1 }; for (let i = layers.length - 1; i >= 0; i--) bg = over(layers[i], bg);
  const col = parse(getComputedStyle(el).color); const fg = over(col, bg);
  const L1 = lum(fg), L2 = lum(bg); const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
  return { ratio: Math.round(ratio * 10) / 10, bgBright: Math.round(0.2126 * bg.r + 0.7152 * bg.g + 0.0722 * bg.b) };
};

const PAGES = [
  { name: 'login page', url: '/delivery/login.html', card: '.del-auth-card', darkChoice: true,
    text: ['.del-auth-title', '.del-auth-subtitle', '.del-auth-org', '.del-af-label', '#inp-phone', '.del-forgot-link', '.del-hero-box > div', '.del-hero-box > p', '.del-auth-ghost', '.del-auth-foot'],
    views: [{ run: "switchMode('register')", text: ['#fv-register [data-i18n="get_etelid_first_title"]', '#fv-register [data-i18n="one_id_all_services"]'] }] },
  { name: 'admin login', url: '/delivery/admin-login.html', card: '.del-auth-card', darkChoice: true, extraLight: ['.del-auth-right'],
    text: ['.del-auth-title', '.del-auth-subtitle', '.del-af-label', '#inp-phone', '.del-auth-foot'] },
  { name: 'privacy page', url: '/delivery/privacy.html', card: 'body', text: ['.hero-title', '.section-title', '.section-body li', '.contact-box p'] },
  { name: 'terms page', url: '/delivery/terms.html', card: 'body', text: ['.hero-title', '.section-title', '.section-body li', '.contact-box p'] },
  { name: 'delete-account page', url: '/delivery/delete-account.html', card: 'body', text: ['.hero-title', '.section-title', '.section-body li', '.contact-box p'] },
  { name: 'investor page', url: '/delivery/investor/index.html', card: 'body', text: ['.hdr p', '.topbar-btn', '#loading'] },
  { name: 'equb page', url: '/delivery/equb/index.html', card: 'body', text: ['.hdr p', '.topbar-btn', '#loading'] }
];

(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  async function open(pg, scheme, stored) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block', colorScheme: scheme });
    await ctx.addInitScript(s => { try { for (const k in s) localStorage.setItem(k, s[k]); } catch (e) {} }, stored || {});
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route(/maps\.googleapis|gstatic|cdnjs|jsdelivr|fonts\./, r => r.abort());
    await p.route(/boot\.php|\.php/, r => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: {} }) }));
    await p.goto(HOST + pg.url, { waitUntil: 'load' }); await p.waitForTimeout(1800);
    return { ctx, p, errs };
  }
  for (const pg of PAGES) {
    for (const scheme of ['light', 'dark']) {
      const { ctx, p } = await open(pg, scheme, null);
      const card = await p.evaluate(probe, pg.card);
      t(pg.name + ': starts light on a ' + scheme + ' phone', !card.missing && card.bgBright > 200, JSON.stringify(card));
      for (const x of (pg.extraLight || [])) { const r = await p.evaluate(probe, x); t(pg.name + ': ' + x + ' is light on a ' + scheme + ' phone', !r.missing && r.bgBright > 200, JSON.stringify(r)); }
      if (scheme === 'light') {
        for (const sel of pg.text) { const r = await p.evaluate(probe, sel); t(pg.name + ': text is readable: ' + sel, !r.missing && r.ratio >= 4.5, JSON.stringify(r)); }
        for (const v of (pg.views || [])) {
          await p.evaluate(v.run); await p.waitForTimeout(500);
          for (const sel of v.text) { const r = await p.evaluate(probe, sel); t(pg.name + ' (' + v.run + '): text is readable: ' + sel, !r.missing && r.ratio >= 4.5, JSON.stringify(r)); }
        }
      }
      await ctx.close();
    }
    if (pg.darkChoice) {
      const { ctx, p } = await open(pg, 'light', { theme: 'dark' });
      const dark = await p.evaluate(() => document.body.classList.contains('dark-theme'));
      const card = await p.evaluate(probe, pg.card);
      t(pg.name + ': a saved dark choice is kept', dark && card.bgBright < 90, JSON.stringify({ dark, card }));
      await ctx.close();
    }
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
