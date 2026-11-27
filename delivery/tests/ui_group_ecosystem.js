// The Etel Group master page: the Ecosystem section (the master logo and six company badges: Pay, Gebeya, Ride, Delivery, Madeya, ID; all transparent pictures),
// English / Amharic, light and dark, phone and desktop.   Real page and files, fake API answers.   node delivery/tests/ui_group_ecosystem.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp' };
const srv = http.createServer((q, r) => { const u = decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '').replace('/EtelGroup/EtelGroup/', '/EtelGroup/'); fs.readFile(path.join(ROOT, u), (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('content-type', MIME[path.extname(u)] || 'application/octet-stream'); r.end(d); } }); });
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
  for (const mode of [{ n: 'desktop dark', w: 1280, h: 800, theme: 'dark' }, { n: 'desktop light', w: 1280, h: 800, theme: 'light' }, { n: 'phone', w: 390, h: 844, theme: 'dark' }, { n: 'small phone', w: 360, h: 740, theme: 'light' }]) {
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
    await ctx.addInitScript(th => { try { localStorage.setItem('theme', th); } catch (e) {} }, mode.theme);
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route(/maps\.googleapis|gstatic|cdnjs|jsdelivr|fonts\.|etel-ai-widget|etel-help-fab/, r => r.abort());
    await p.route(/\/delivery\/apk\//, r => r.fulfill({ status: 404, body: '' }));
    await p.route(/\/delivery\/app\/version\.json/, r => r.fulfill({ contentType: 'application/json', body: '{}' }));
    await p.route(/boot\.php|\.php/, r => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: {} }) }));
    await p.goto(HOST + '/EtelGroup/index.html', { waitUntil: 'load' }); await p.waitForTimeout(2000);
    await p.evaluate(th => document.documentElement.setAttribute('data-theme', th), mode.theme);
    const n = mode.n + ': ';
    t(n + 'the page has an Ecosystem section between the companies and About', await p.evaluate(() => { const ids = [].slice.call(document.querySelectorAll('section')).map(s => s.id); return ids.indexOf('ecosystem') === ids.indexOf('services') + 1 && ids.indexOf('about') === ids.indexOf('ecosystem') + 1; }));
    await p.evaluate(() => { document.documentElement.style.scrollBehavior = 'auto'; document.getElementById('ecosystem').scrollIntoView(); }); await p.waitForTimeout(1500);
    // the seven logos: real transparent pictures (corners and the gap between ring and orbit have alpha 0)
    const KEYS = ['group-master', 'pay', 'gebeya', 'ride', 'delivery', 'madeya', 'id'];
    const imgs = await p.evaluate(() => Promise.all([].slice.call(document.querySelectorAll('#ecosystem img')).map(i => new Promise(res => { const f = () => { const c = document.createElement('canvas'); c.width = i.naturalWidth; c.height = i.naturalHeight; const x = c.getContext('2d'); x.drawImage(i, 0, 0); const px = (a, b) => x.getImageData(a, b, 1, 1).data[3]; const w = c.width, h = c.height;
      res({ src: i.getAttribute('src').split('?')[0], w: i.naturalWidth, h: i.naturalHeight, shown: i.getBoundingClientRect().width, corners: [px(0, 0), px(w - 1, 0), px(0, h - 1), px(w - 1, h - 1)], centre: px(w >> 1, (h >> 1) + 5), alt: i.alt }); }; if (i.complete) f(); else { i.onload = f; i.onerror = f; } }))));
    t(n + 'seven logos: the master and the six company badges', JSON.stringify(imgs.map(i => i.src)) === JSON.stringify(KEYS.map(k => 'EtelGroup/icons/brand/etel-' + k + '.webp')), JSON.stringify(imgs.map(i => i.src)));
    t(n + 'every logo loaded and is square', imgs.length === 7 && imgs.every(i => i.w > 0 && i.w === i.h), JSON.stringify(imgs.map(i => [i.w, i.h])));
    t(n + 'the backgrounds are removed: all four corners of every picture are fully transparent', imgs.every(i => i.corners.every(a => a === 0)), JSON.stringify(imgs.map(i => i.corners)));
    t(n + 'the badge itself is solid (not transparent in the middle)', imgs.every(i => i.centre > 250), JSON.stringify(imgs.map(i => i.centre)));
    t(n + 'the logos are big enough to see (110 px or more)', imgs.every(i => i.shown >= 110), JSON.stringify(imgs.map(i => i.shown)));
    t(n + 'the logos have alt text', imgs.every(i => (i.alt || '').length > 15));
    // cards link to the real companies
    const links = await p.evaluate(() => [].slice.call(document.querySelectorAll('#ecosystem a.eco-card')).map(a => ({ h: a.getAttribute('href'), x: a.innerText.replace(/\s+/g, ' ').trim(), blank: a.target === '_blank' && /noopener/.test(a.rel) })));
    const WANT = { 'Etel.Pay/': 'ETEL Pay', 'EtelGebeya/': 'ETEL Gebeya', 'EtelTaxi/': 'ETEL Ride', 'delivery/': 'ETEL Delivery', 'etel.madeya/': 'ETEL Madeya', 'EtelId/etel-id/apply/': 'ETEL ID' };
    t(n + 'six cards, each opens its real company page in a new tab', links.length === 6 && Object.keys(WANT).every(k => links.some(l => (l.h || '').indexOf(k) > -1 && l.x.indexOf(WANT[k]) === 0 && l.blank)), JSON.stringify(links));
    // text and contrast
    const c1 = await p.evaluate(probe, '#ecosystem .eco-title'), c2 = await p.evaluate(probe, '#ecosystem .eco-sub'), c3 = await p.evaluate(probe, '#ecosystem .eco-card-t b'), c4 = await p.evaluate(probe, '#ecosystem .eco-card-t em'), c5 = await p.evaluate(probe, '#ecosystem .eco-hub-cap');
    t(n + 'title, sub line, card names, card lines and the logo caption are readable (contrast 4.5+)', [c1, c2, c3, c4, c5].every(c => c.ratio >= 4.5), JSON.stringify([c1, c2, c3, c4, c5]));
    t(n + 'no sideways scroll', await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    t(n + 'cards: three per row on desktop, two on a phone', await p.evaluate(() => { const tops = [].slice.call(document.querySelectorAll('#ecosystem a.eco-card')).map(c => Math.round(c.getBoundingClientRect().top)); const per = tops.filter(x => x === tops[0]).length; return window.innerWidth > 1000 ? per === 3 : window.innerWidth > 760 ? per === 3 : per === 2; }));
    t(n + 'the master logo is not squeezed by the cards (same width on screen as set)', await p.evaluate(() => { const m = document.querySelector('#ecosystem .eco-master').getBoundingClientRect(); return m.width >= 250 && Math.abs(m.width - m.height) < 2; }));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/group_eco_' + mode.n.replace(/ /g, '_') + '.png' });
    // Amharic and back
    await p.evaluate(() => window.setLang('am')); await p.waitForTimeout(300);
    t(n + 'Amharic: the title and sub line change', await p.evaluate(() => /ብዙ ኩባንያዎች/.test(document.querySelector('#ecosystem .eco-title').innerText) && /አንድ እምነት/.test(document.querySelector('#ecosystem .eco-sub').innerText)));
    t(n + 'Amharic: the section still has no sideways scroll', await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    await p.evaluate(() => window.setLang('en')); await p.waitForTimeout(300);
    t(n + 'English again', await p.evaluate(() => /Many companies/.test(document.querySelector('#ecosystem .eco-title').innerText)));
    // the card hover/focus is keyboard reachable
    await p.focus('#eco-pay'); t(n + 'a card can be reached with the keyboard', await p.evaluate(() => document.activeElement.id === 'eco-pay'));
    t(n + 'no page errors', errs.length === 0, errs.join(' | '));
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(pass + ' checks passed' + (fails.length ? ', ' + fails.length + ' FAILED:\n - ' + fails.join('\n - ') : ''));
  process.exit(fails.length ? 1 : 0);
})();
