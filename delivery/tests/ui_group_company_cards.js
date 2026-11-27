// The Etel Group master page, "Our Companies" cards and the Platform launcher: companies that have an ETEL family badge (Madeya, Market/Gebeya, Delivery, Ride, Pay, ID)
// show that transparent badge on a navy card (matched by slug and name, so an old logo_path in the database does not win); other companies keep their own logo or icon.
// English and Amharic text stays readable, light and dark theme, phone and desktop.   Real page and files, fake company list.   node delivery/tests/ui_group_company_cards.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp' };
const srv = http.createServer((q, r) => { const u = decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '').replace('/EtelGroup/EtelGroup/', '/EtelGroup/'); fs.readFile(path.join(ROOT, u), (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('content-type', MIME[path.extname(u)] || 'application/octet-stream'); r.end(d); } }); });
const co = (slug, name, status, logo, extra) => Object.assign({ slug, name, tagline: 'Tagline', tagline_am: 'AM', description_short: 'A short line about ' + name + '.', url: 'https://example.com/' + slug + '/', icon_key: 'generic', dot_color: '#C8922A', tags: 'One,Two', status, sort_order: 1, logo_path: logo || null, launch_text: '', notify_enabled: 0 }, extra || {});
const COMPANIES = [
  co('etel-madeya', 'Etel Madeya', 'live', 'https://ethelmarket.com/delivery/icons/madeya-old-coin.png'),
  co('etel-delivery', 'Etel Delivery', 'live', 'https://ethelmarket.com/delivery/icons/icon-192.png'),
  co('etel-id', 'Etel ID', 'live', 'https://ethelmarket.com/EtelGroup/icons/etelgroup-logo.png'),
  co('etel-ride', 'Etel Ride', 'live', ''),
  co('etel-pay', 'Etel Pay', 'soon', 'https://ethelmarket.com/delivery/icons/pay-old-coin.png'),
  co('etel-market', 'Etel Market', 'soon', ''),
  co('etel-ai', 'Etel AI', 'soon', 'https://ethelmarket.com/EtelAi/icons/icon-192.png'),
  co('etel-transport', 'Etel Transport', 'soon', ''),
  co('etel-media', 'Etel Media', 'soon', '')
];
const WANT = { 'etel-madeya': 'etel-madeya.webp', 'etel-delivery': 'etel-transport.webp', 'etel-id': 'etel-id.webp', 'etel-ride': 'etel-transport.webp', 'etel-pay': 'etel-pay.webp', 'etel-market': 'etel-gebeya.webp' };
const probe = (sel) => {
  const parse = s => { const m = s.match(/[\d.]+/g) || []; return { r: +m[0] || 0, g: +m[1] || 0, b: +m[2] || 0, a: m.length > 3 ? +m[3] : 1 }; };
  const over = (top, bot) => ({ r: top.r * top.a + bot.r * (1 - top.a), g: top.g * top.a + bot.g * (1 - top.a), b: top.b * top.a + bot.b * (1 - top.a), a: 1 });
  const lum = c => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
  const el = document.querySelector(sel); if (!el) return { missing: true };
  let layers = []; for (let e = el; e; e = e.parentElement) { const c = parse(getComputedStyle(e).backgroundColor); if (c.a > 0) layers.push(c); if (c.a >= 0.999) break; }
  let bg = { r: 20, g: 10, b: 0, a: 1 }; for (let i = layers.length - 1; i >= 0; i--) bg = over(layers[i], bg);
  const fg = over(parse(getComputedStyle(el).color), bg); const L1 = lum(fg), L2 = lum(bg);
  return { ratio: Math.round((Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05) * 10) / 10 };
};
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  for (const mode of [{ n: 'desktop dark', w: 1280, h: 800, theme: 'dark' }, { n: 'desktop light', w: 1280, h: 800, theme: 'light' }, { n: 'phone', w: 390, h: 844, theme: 'dark' }, { n: 'phone light', w: 390, h: 844, theme: 'light' }]) {
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
    await ctx.addInitScript(th => { try { localStorage.setItem('theme', th); } catch (e) {} }, mode.theme);
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route(/maps\.googleapis|gstatic|cdnjs|jsdelivr|fonts\.|etel-ai-widget|etel-help-fab/, r => r.abort());
    await p.route(/\.php/, r => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: {} }) }));
    await p.route(/action=list_companies/, r => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { companies: COMPANIES, notify_bot: '' } }) }));
    await p.route(/\/delivery\/apk\/|ethelmarket\.com/, r => r.fulfill({ status: 404, body: '' }));
    await p.goto(HOST + '/EtelGroup/index.html', { waitUntil: 'load' }); await p.waitForTimeout(2500);
    await p.evaluate(th => document.documentElement.setAttribute('data-theme', th), mode.theme);
    const n = mode.n + ': ';
    const cards = await p.evaluate(() => [].slice.call(document.querySelectorAll('#services .co-card')).map(c => ({ name: (c.querySelector('.co-name') || {}).textContent, cls: c.className, bg: c.style.backgroundImage || '', bgc: c.style.backgroundColor || '', icon: !!c.querySelector('.co-icon') })));
    t(n + 'nine company cards are drawn', cards.length === 9, JSON.stringify(cards.map(c => c.name)));
    const by = (nm) => cards.find(c => c.name === nm) || {};
    const NM = { 'etel-madeya': 'Etel Madeya', 'etel-delivery': 'Etel Delivery', 'etel-id': 'Etel ID', 'etel-ride': 'Etel Ride', 'etel-pay': 'Etel Pay', 'etel-market': 'Etel Market' };
    for (const k of Object.keys(WANT)) t(n + NM[k] + ' shows the ' + WANT[k] + ' badge (not its old logo_path)', by(NM[k]).bg.indexOf('/EtelGroup/icons/brand/' + WANT[k]) > -1 && by(NM[k]).bg.indexOf('old-coin') < 0 && /co-card-has-logo/.test(by(NM[k]).cls || ''), JSON.stringify(by(NM[k])));
    t(n + 'the badge sits on a navy card (solid navy colour under it)', cards.filter(c => /icons\/brand\//.test(c.bg)).every(c => /rgb\(10, 18, 48\)/.test(c.bgc)), JSON.stringify(cards.map(c => c.bgc)));
    t(n + 'Etel AI keeps its own logo from the database', /EtelAi\/icons\/icon-192\.png/.test(by('Etel AI').bg), JSON.stringify(by('Etel AI')));
    t(n + 'Etel Transport and Etel Media (no badge, no logo) keep their icon', by('Etel Transport').icon && by('Etel Media').icon && !/brand/.test(by('Etel Transport').bg), JSON.stringify([by('Etel Transport'), by('Etel Media')]));
    // the badge pictures are real, transparent and square
    const pics = await p.evaluate(() => Promise.all(['etel-madeya', 'etel-gebeya', 'etel-transport', 'etel-pay', 'etel-id'].map(k => new Promise(res => { const i = new Image(); i.onload = () => { const c = document.createElement('canvas'); c.width = i.naturalWidth; c.height = i.naturalHeight; const x = c.getContext('2d'); x.drawImage(i, 0, 0); res({ k, w: i.naturalWidth, h: i.naturalHeight, a: [x.getImageData(0, 0, 1, 1).data[3], x.getImageData(c.width - 1, c.height - 1, 1, 1).data[3]] }); }; i.onerror = () => res({ k, w: 0 }); i.src = '/EtelGroup/icons/brand/' + k + '.webp'; }))));
    t(n + 'the five badge pictures load, are square and transparent in the corners', pics.every(x => x.w > 0 && x.w === x.h && x.a[0] === 0 && x.a[1] === 0), JSON.stringify(pics));
    // the Platform launcher cards follow the same rule
    const lc = await p.evaluate(() => [].slice.call(document.querySelectorAll('.launcher-card')).map(c => ({ name: (c.querySelector('.lc-name') || {}).textContent, bg: c.style.backgroundImage || '' })));
    t(n + 'launcher cards: Madeya, Delivery, ID and Ride show their badge too', ['Etel Madeya', 'Etel Delivery', 'Etel ID', 'Etel Ride'].every(nm => (lc.find(c => c.name === nm) || { bg: '' }).bg.indexOf('/EtelGroup/icons/brand/') > -1), JSON.stringify(lc));
    // text on the cards is readable
    const c1 = await p.evaluate(probe, '#services .co-card-has-logo .co-name'), c2 = await p.evaluate(probe, '#services .co-card-has-logo .co-desc');
    t(n + 'name and description on a badge card are readable (contrast 4.5+)', c1.ratio >= 4.5 && c2.ratio >= 4.5, JSON.stringify([c1, c2]));
    t(n + 'no sideways scroll', await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    await p.evaluate(() => { document.documentElement.style.scrollBehavior = 'auto'; document.getElementById('services').scrollIntoView(); }); await p.waitForTimeout(1500);
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/group_cards_' + mode.n.replace(/ /g, '_') + '.png' });
    t(n + 'no page errors', errs.length === 0, errs.slice(0, 3).join(' | '));
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(pass + ' checks passed' + (fails.length ? ', ' + fails.length + ' FAILED:\n - ' + fails.join('\n - ') : ''));
  process.exit(fails.length ? 1 : 0);
})();
