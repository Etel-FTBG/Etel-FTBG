// The Etel Group master page, "Our Companies": the ETEL Group logo in a slowly turning gold ring with six dots, and every company card shows its picture inside the same ring
// on a navy plate. Companies that have an ETEL family badge (Madeya, Market/Gebeya, Delivery, Ride, Pay, ID) show that transparent badge (matched by slug and name, so an old
// logo_path in the database does not win); other companies keep their own logo (round) or their icon. The old "Many companies. One ETEL." section is gone.
// The Platform launcher cards below have the same navy plate and turning ring. Light and dark theme, phone and desktop.   Real page and files, fake company list.   node delivery/tests/ui_group_company_cards.js
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
// the real API hides every coming-soon company (name, text, link, picture) and gives the sign-up button a private key
const MASKED = COMPANIES.map(c => c.status === 'live' ? c : Object.assign({}, c, { slug: 'zkey' + c.slug.length + c.slug.charCodeAt(5), mystery: 1, name: '', tagline: '', tagline_am: '', description_short: '', description_long: '', url: '', logo_path: '', icon_key: '', tags: '' }));
const WANT = { 'etel-madeya': 'etel-madeya.webp', 'etel-delivery': 'etel-transport.webp', 'etel-id': 'etel-id.webp', 'etel-ride': 'etel-transport.webp', 'etel-pay': 'etel-pay.webp', 'etel-market': 'etel-gebeya.webp', 'etel-media': 'etel-entertainment.webp' };
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
    await p.route(/action=list_companies/, r => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { companies: MASKED, notify_bot: '' } }) }));
    await p.route(/\/delivery\/apk\/|ethelmarket\.com/, r => r.fulfill({ status: 404, body: '' }));
    await p.goto(HOST + '/EtelGroup/index.html', { waitUntil: 'load' }); await p.waitForTimeout(2500);
    await p.evaluate(th => document.documentElement.setAttribute('data-theme', th), mode.theme);
    const n = mode.n + ': ';
    const cards = await p.evaluate(() => [].slice.call(document.querySelectorAll('#services .co-card')).map(c => { const im = c.querySelector('.co-orb-img'); const ring = c.querySelector('.co-orb-ring'); return { name: (c.querySelector('.co-name') || {}).textContent, cls: c.className, img: im ? im.getAttribute('src') : '', round: !!(im && im.classList.contains('co-orb-round')), icon: !!c.querySelector('.co-orb-icon'), dots: c.querySelectorAll('.co-orb-ring i').length, spin: ring ? getComputedStyle(ring).animationName : '', bg: c.style.backgroundImage || '' }; }));
    t(n + 'nine company cards are drawn', cards.length === 9, JSON.stringify(cards.map(c => c.name)));
    const by = (nm) => cards.find(c => c.name === nm) || {};
    const NM = { 'etel-madeya': 'Etel Madeya', 'etel-delivery': 'Etel Delivery', 'etel-id': 'Etel ID', 'etel-ride': 'Etel Ride', 'etel-pay': 'Etel Pay', 'etel-market': 'Etel Market', 'etel-media': 'Etel Media' };
    for (const k of Object.keys(WANT).filter(k => ['etel-madeya', 'etel-delivery', 'etel-id', 'etel-ride'].indexOf(k) > -1)) t(n + NM[k] + ' shows the ' + WANT[k] + ' badge (not its old logo_path)', (by(NM[k]).img || '').indexOf('/EtelGroup/icons/brand/' + WANT[k]) > -1 && (by(NM[k]).img || '').indexOf('old-coin') < 0 && !by(NM[k]).round, JSON.stringify(by(NM[k])));
    t(n + 'every card has the gold ring with six dots, and it turns', cards.every(c => c.dots === 6 && c.spin === 'co-orb-spin'), JSON.stringify(cards.map(c => [c.dots, c.spin])));
    t(n + 'the cards are navy plates with no background picture (the picture is inside the ring)', await p.evaluate(() => [].slice.call(document.querySelectorAll('#services .co-orb-card')).every(c => getComputedStyle(c).backgroundColor === 'rgb(12, 20, 48)' && !c.style.backgroundImage && getComputedStyle(c).clipPath === 'none')));
    // coming soon = a surprise: no name, no hint, a blurred mystery picture
    const soon = await p.evaluate(() => [].slice.call(document.querySelectorAll('#services .co-card-mystery')).map(c => ({ html: c.innerHTML, name: c.querySelector('.co-name').textContent, pic: !!c.querySelector('.co-mystery-pic'), img: !!c.querySelector('.co-orb-img, .co-orb-icon, svg'), notify: (c.querySelector('.co-notify-btn') || { getAttribute: () => '' }).getAttribute('data-notify') })));
    t(n + 'the five coming soon companies are mystery cards', soon.length === 5 && soon.every(c => c.name === 'Surprise' && c.pic && !c.img), JSON.stringify(soon.map(c => [c.name, c.pic, c.img])));
    t(n + 'nothing on the page says what is coming (no Pay, Market, AI, Transport or Media on a coming soon card)', soon.every(c => !/Pay|Market|AI|Transport|Media|example\.com|etel-/i.test(c.html.replace(/Coming Soon/g, ''))), soon.map(c => c.html).join('').slice(0, 200));
    t(n + 'the mystery picture is a round disc', await p.evaluate(() => { const e = document.querySelector('#services .co-mystery-pic'); const r = e.getBoundingClientRect(); return r.width > 60 && Math.abs(r.width - r.height) < 2 && getComputedStyle(e).borderRadius.indexOf('50%') > -1; }));
    t(n + 'the page text and the menus do not name the coming soon companies', await p.evaluate(() => { const names = ['Etel Pay', 'Etel Market', 'Etel AI', 'Etel Transport', 'Etel Media']; const parts = ['nav-dropdown-list', 'mobile-nav-list', 'footer-companies-list', 'co-group-soon'].map(i => (document.getElementById(i) || {}).innerHTML || ''); return parts.every(h => names.every(x => h.indexOf(x) < 0)); }));
    // the ETEL Group logo with its turning ring, above the companies; the old Ecosystem section is gone
    const hub = await p.evaluate(() => { const h = document.querySelector('#services .co-hub'); const im = h && h.querySelector('.co-hub-img'); const ring = h && h.querySelector('.co-hub-ring'); const g = document.querySelector('#services .companies-grid, #services .co-group'); return h ? { img: im.getAttribute('src').split('?')[0], w: im.getBoundingClientRect().width, dots: h.querySelectorAll('.co-hub-ring i').length, spin: getComputedStyle(ring).animationName, above: !!(g && (h.compareDocumentPosition(g) & Node.DOCUMENT_POSITION_FOLLOWING)), inServices: true } : null; });
    t(n + 'the ETEL Group logo sits above the companies in a turning ring with six dots', hub && hub.img === 'EtelGroup/icons/brand/etel-group-master.webp' && hub.w >= 200 && hub.dots === 6 && hub.spin === 'co-orb-spin' && hub.above, JSON.stringify(hub));
    t(n + 'the old "Many companies. One ETEL." section is gone', await p.evaluate(() => !document.getElementById('ecosystem') && !/Many companies/.test(document.body.innerText) && !document.querySelector('.eco-title')));
    // the badge pictures are real, transparent and square
    const pics = await p.evaluate(() => Promise.all(['etel-madeya', 'etel-gebeya', 'etel-transport', 'etel-pay', 'etel-id', 'etel-entertainment'].map(k => new Promise(res => { const i = new Image(); i.onload = () => { const c = document.createElement('canvas'); c.width = i.naturalWidth; c.height = i.naturalHeight; const x = c.getContext('2d'); x.drawImage(i, 0, 0); res({ k, w: i.naturalWidth, h: i.naturalHeight, a: [x.getImageData(0, 0, 1, 1).data[3], x.getImageData(c.width - 1, c.height - 1, 1, 1).data[3]] }); }; i.onerror = () => res({ k, w: 0 }); i.src = '/EtelGroup/icons/brand/' + k + '.webp'; }))));
    t(n + 'the six badge pictures load, are square and transparent in the corners', pics.every(x => x.w > 0 && x.w === x.h && x.a[0] === 0 && x.a[1] === 0), JSON.stringify(pics));
    // the Platform launcher cards: the same navy plate and turning ring, the picture inside the ring
    const lc = await p.evaluate(() => [].slice.call(document.querySelectorAll('.launcher-card')).map(c => { const im = c.querySelector('.lc-orb .co-orb-img'); const ring = c.querySelector('.lc-orb .co-orb-ring'); return { name: (c.querySelector('.lc-name') || {}).textContent, img: im ? im.getAttribute('src') : '', dots: c.querySelectorAll('.lc-orb .co-orb-ring i').length, spin: ring ? getComputedStyle(ring).animationName : '', bgc: getComputedStyle(c).backgroundColor, bgi: c.style.backgroundImage || '', clip: getComputedStyle(c).clipPath }; }));
    const lcBy = (nm) => lc.find(c => c.name === nm) || { img: '' };
    t(n + 'launcher cards: the four live companies are drawn', lc.length === 4, JSON.stringify(lc.map(c => c.name)));
    t(n + 'launcher cards: Madeya, Delivery, ID and Ride show their badge inside the ring', [['Etel Madeya', 'etel-madeya.webp'], ['Etel Delivery', 'etel-transport.webp'], ['Etel ID', 'etel-id.webp'], ['Etel Ride', 'etel-transport.webp']].every(([nm, f]) => lcBy(nm).img.indexOf('/EtelGroup/icons/brand/' + f) > -1), JSON.stringify(lc));
    t(n + 'launcher cards: gold ring with six dots that turns, navy plate, no background picture', lc.every(c => c.dots === 6 && c.spin === 'co-orb-spin' && c.bgc === 'rgb(12, 20, 48)' && !c.bgi && c.clip === 'none'), JSON.stringify(lc.map(c => [c.dots, c.spin, c.bgc, c.bgi, c.clip])));
    const l1 = await p.evaluate(probe, '.launcher-card.lc-orb-card .lc-name'), l2 = await p.evaluate(probe, '.launcher-card.lc-orb-card .lc-desc');
    t(n + 'launcher cards: name and description are readable (contrast 4.5+)', l1.ratio >= 4.5 && l2.ratio >= 4.5, JSON.stringify([l1, l2]));
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
