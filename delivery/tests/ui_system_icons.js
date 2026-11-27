// Each system shows its OWN logo: the icons of Courier, Service, Admin, Agent, Equb, Investor and the ETEL Group are navy squares with that system's badge (real pixels read in a browser),
// the four full sets look different from each other, maskable icons keep the badge inside the safe circle, the opening-splash pictures are transparent badges, and the master page / root pages
// really load the Group icon as their tab icon.   Real files, no network.   node delivery/tests/ui_system_icons.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.webmanifest': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp', '.ico': 'image/x-icon' };
const srv = http.createServer((q, r) => { const u = decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, ''); fs.readFile(path.join(ROOT, u), (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('content-type', MIME[path.extname(u)] || 'application/octet-stream'); r.end(d); } }); });
const measure = (url) => new Promise(res => { const i = new Image(); i.onload = () => { const c = document.createElement('canvas'); c.width = i.naturalWidth; c.height = i.naturalHeight; const x = c.getContext('2d'); x.drawImage(i, 0, 0); const w = c.width, h = c.height;
  const px = (a, b) => { const d = x.getImageData(a, b, 1, 1).data; return { r: d[0], g: d[1], b: d[2], a: d[3] }; }; const lum = p => 0.2126 * p.r + 0.7152 * p.g + 0.0722 * p.b;
  const all = x.getImageData(0, 0, w, h).data; let gold = 0, n = 0, far = 0; for (let yy = 0; yy < h; yy += 2) for (let xx = 0; xx < w; xx += 2) { const k = (yy * w + xx) * 4; const r = all[k], g = all[k + 1], b = all[k + 2], a = all[k + 3]; if (a < 200) continue; n++; if (r > 150 && g > 110 && b < 95 && r > b + 60) { gold++; far = Math.max(far, Math.hypot(xx - w / 2, yy - h / 2) / w); } }
  // 32 x 32 colour signature to tell two pictures apart
  const sig = []; const sc = document.createElement('canvas'); sc.width = 32; sc.height = 32; const sx = sc.getContext('2d'); sx.drawImage(i, 0, 0, 32, 32); const sd = sx.getImageData(0, 0, 32, 32).data; for (let k = 0; k < sd.length; k++) sig.push(sd[k]);
  const cs = [px(2, 2), px(w - 3, 2), px(2, h - 3), px(w - 3, h - 3)];
  res({ w, h, cornerLum: cs.map(lum), cornerBlue: cs.map(p => p.b - p.r), cornerA: cs.map(p => p.a), centreA: px(w >> 1, h >> 1).a, goldShare: gold / Math.max(n, 1), far, sig }); }; i.onerror = () => res(null); i.src = url; });
const dist = (a, b) => a.reduce((s, v, k) => s + Math.abs(v - b[k]), 0) / a.length;
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1000, height: 700 }, serviceWorkers: 'block' }); const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
  await p.route(/maps\.googleapis|gstatic|cdnjs|jsdelivr|fonts\.|etel-ai-widget|etel-help-fab/, r => r.abort()); await p.route(/\.php/, r => r.fulfill({ contentType: 'application/json', body: '{"success":true,"data":{}}' }));
  await p.goto(HOST + '/EtelGroup/api/', { waitUntil: 'commit' }).catch(() => {});
  // 1. the icon sets
  const SETS = { courier: '/delivery/icons/courier-icon-', service: '/delivery/icons/service-icon-', admin: '/delivery/icons/admin-icon-', agent: '/delivery/icons/agent-icon-', group: '/EtelGroup/icons/icon-', delivery: '/delivery/icons/icon-' };
  const sigs = {};
  for (const [k, base] of Object.entries(SETS)) {
    const m = await p.evaluate(measure, HOST + base + '512.png'); const nm = k + ' icon-512: ';
    t(nm + 'loads at 512 x 512', m && m.w === 512 && m.h === 512, JSON.stringify(m && [m.w, m.h]));
    if (k !== 'delivery') { t(nm + 'navy square (dark blue corners) with a gold ring', m && m.cornerLum.every(l => l < 50) && m.cornerBlue.every(v => v > 4) && m.goldShare > 0.004 && m.far > 0.3, JSON.stringify(m && [m.cornerLum, m.cornerBlue, m.goldShare, m.far])); const mk = await p.evaluate(measure, HOST + base + '512-maskable.png'); t(nm + 'maskable keeps the badge inside the safe circle', mk && mk.far < 0.44 && mk.goldShare > 0.003, JSON.stringify(mk && [mk.far, mk.goldShare])); }
    if (m) sigs[k] = m.sig;
  }
  const names = Object.keys(sigs); let minD = 1e9, pair = '';
  for (let i = 0; i < names.length; i++) for (let j = i + 1; j < names.length; j++) { const d = dist(sigs[names[i]], sigs[names[j]]); if (d < minD) { minD = d; pair = names[i] + '/' + names[j]; } }
  t('the six icon sets all look different from each other (closest pair ' + pair + ' differs by at least 0.5)', minD >= 0.5, minD + ' ' + pair);
  for (const k of ['equb', 'investor']) { const m = await p.evaluate(measure, HOST + '/delivery/icons/' + k + '-icon-96.png'); t(k + ' icon-96 loads (96 x 96, navy corners)', m && m.w === 96 && m.cornerLum.every(l => l < 50), JSON.stringify(m && [m.w, m.cornerLum])); }
  for (const k of ['courier', 'service', 'admin', 'agent']) { const m = await p.evaluate(measure, HOST + '/delivery/icons/' + k + '-apple-touch-icon.png'); t(k + ' apple-touch-icon is 180 x 180 and opaque', m && m.w === 180 && m.cornerA.every(a => a === 255), JSON.stringify(m && [m.w, m.cornerA])); }
  // 2. opening splash pictures
  for (const k of ['transport', 'courier', 'service', 'admin']) { const m = await p.evaluate(measure, HOST + '/delivery/assets/images/splash-' + k + '.webp'); t('splash-' + k + '.webp: a 320 px transparent badge (corners transparent, middle solid)', m && m.w === 320 && m.h === 320 && m.cornerA.every(a => a === 0) && m.centreA === 255, JSON.stringify(m && [m.w, m.cornerA, m.centreA])); }
  const ss = []; for (const k of ['transport', 'courier', 'service', 'admin']) ss.push((await p.evaluate(measure, HOST + '/delivery/assets/images/splash-' + k + '.webp')).sig);
  t('the four opening-splash pictures differ from each other', ss.every((s, i) => ss.every((u, j) => i === j || dist(s, u) >= 1.5)));
  // login-page logos: transparent 512 px pictures, one per system, and the login page picks them by role
  const LG = { customer: 'logo', ride: 'logo-ride', driver: 'logo-driver', courier: 'logo-courier', service: 'logo-service', admin: 'logo-admin' }; const lgs = {};
  for (const [k, f] of Object.entries(LG)) { const m = await p.evaluate(measure, HOST + '/delivery/assets/images/' + f + '.png'); t('login logo ' + f + '.png: 512 x 512, transparent corners, solid middle', m && m.w === 512 && m.h === 512 && m.cornerA.every(a => a === 0) && m.centreA === 255, JSON.stringify(m && [m.w, m.cornerA, m.centreA])); if (m) lgs[k] = m.sig; const w = await p.evaluate(measure, HOST + '/delivery/assets/images/' + f + '.webp'); t('login logo ' + f + '.webp loads and is transparent', w && w.w === 512 && w.cornerA.every(a => a === 0), JSON.stringify(w && [w.w, w.cornerA])); }
  t('login logos: Customer, Ride and Driver are the same transport logo', dist(lgs.customer, lgs.ride) < 0.01 && dist(lgs.customer, lgs.driver) < 0.01);
  t('login logos: Courier, Service and Admin each differ from the transport logo and from each other', ['courier', 'service', 'admin'].every((k, i, a) => dist(lgs[k], lgs.customer) >= 0.5 && a.every((u, j) => i === j || dist(lgs[k], lgs[u]) >= 0.5)));
  // 3. the Group pages really use the Group icon as their tab icon
  for (const page of ['/EtelGroup/index.html', '/portfolio.html', '/privacy.html', '/terms.html', '/cookie-policy.html']) {
    // read the page source (the real page redirects and loads many scripts; the tab icon is in its head)
    const li = await p.evaluate(async (u) => { const tx = await (await fetch(u)).text(); const out = []; const re = /<link[^>]+rel="(?:shortcut )?(?:icon|apple-touch-icon)"[^>]*>/g; let m; while ((m = re.exec(tx))) { const h = /href="([^"]+)"/.exec(m[0]); if (h) out.push(h[1]); } const og = /property="og:image"\s+content="([^"]+)"/.exec(tx); return { links: out, og: og ? og[1] : '' }; }, HOST + page);
    t(page + ': the tab icon and the apple-touch icon are the ETEL Group icons', li.links.length >= 2 && li.links.every(h => /EtelGroup\/icons\//.test(h)), JSON.stringify(li.links));
    t(page + ': the share picture (og:image) is the Group icon, not EtelAi', /EtelGroup\/icons\//.test(li.og), li.og);
    const m = await p.evaluate(measure, HOST + li.links[0].replace(/^https:\/\/ethelmarket\.com/, ''));
    t(page + ': the tab icon loads and is a navy badge', m && m.w > 0 && m.cornerLum.every(l => l < 50), JSON.stringify(m && [m.w, m.cornerLum]));
  }
  // the delivery pages: own icon and own opening splash in the page source
  const PG = { '/delivery/courier/index.html': ['courier-icon-96', 'splash-courier.webp'], '/delivery/servicegiver.html': ['service-icon-96', 'splash-service.webp'], '/delivery/admin.html': ['admin-icon-96', 'splash-admin.webp'], '/delivery/customer.html': ['icon-96', 'splash-transport.webp'], '/delivery/driver/index.html': ['driver-icon-96', 'splash-transport.webp'], '/delivery/equb/index.html': ['equb-icon-96', ''], '/delivery/investor/index.html': ['investor-icon-96', ''] };
  for (const [page, [ic, sp]] of Object.entries(PG)) {
    const tx = await p.evaluate(async (u) => (await (await fetch(u)).text()), HOST + page);
    t(page + ': links its own tab icon (' + ic + ')', tx.indexOf(ic + '.png') > -1);
    if (sp) t(page + ': the opening splash shows its own logo (' + sp + '), not the group master logo', tx.indexOf(sp) > -1 && tx.indexOf('master-logo-splash.png') < 0);
  }
  // the real login page picks the logo by role
  for (const [role, q, f, nm] of [['Customer', '', 'logo', 'ETEL DELIVERY'], ['Ride', '?redirect=ride/', 'logo-ride', 'ETEL RIDE'], ['Driver', '?role=driver', 'logo-driver', 'ETEL DRIVER'], ['Courier', '?role=courier', 'logo-courier', 'ETEL COURIER'], ['Service', '?role=service_giver', 'logo-service', 'ETEL SERVICE']]) {
    await p.goto(HOST + '/delivery/login.html' + q, { waitUntil: 'load' }); await p.waitForTimeout(1200);
    const r = await p.evaluate(() => { const i = document.getElementById('auth-logo-card'); return { cur: i ? (i.currentSrc || i.src).split('/').pop() : '', w: i ? i.naturalWidth : 0, name: (document.getElementById('auth-name') || {}).textContent, fav: (document.querySelector('link[rel="icon"]') || {}).getAttribute ? document.querySelector('link[rel="icon"]').getAttribute('href') : '' }; });
    t('login page, ' + role + ': shows its own logo (' + f + ') and the name ' + nm, r.cur.indexOf(f + '.') === 0 && r.w === 512 && r.name === nm, JSON.stringify(r));
  }
  // the role buttons: real logos, no emoji
  await p.goto(HOST + '/delivery/login.html', { waitUntil: 'load' }); await p.waitForTimeout(1000); await p.getByText('Choose app').first().click(); await p.waitForTimeout(700);
  const tabs = await p.evaluate(() => [].slice.call(document.querySelectorAll('.del-rtab')).map(b => { const i = b.querySelector('.del-rtab-icon img'); return { txt: b.innerText.trim(), img: i ? i.getAttribute('src').split('?')[0].split('/').pop() : '', w: i ? i.naturalWidth : 0, shown: i ? Math.round(i.getBoundingClientRect().width) : 0, emoji: /[\u{1F300}-\u{1FAFF}]/u.test(b.innerText) }; }));
  t('login role buttons: Customer, Ride, Courier, Driver, Service each show their own logo (96 px picture, 28 px or more on screen), no emoji', JSON.stringify(tabs.map(x => [x.txt, x.img])) === JSON.stringify([['Customer', 'tab-customer.png'], ['Ride', 'tab-ride.png'], ['Courier', 'tab-courier.png'], ['Driver', 'tab-driver.png'], ['Service', 'tab-service.png']]) && tabs.every(x => x.w === 96 && x.shown >= 28 && !x.emoji), JSON.stringify(tabs));
  const tl = []; for (const k of ['customer', 'courier', 'service', 'pay', 'id']) { const m = await p.evaluate(measure, HOST + '/delivery/assets/images/tab-' + k + '.png'); tl.push(m && m.w === 96 && m.cornerA.every(a => a === 0) && m.centreA === 255); }
  t('the small tab logos are transparent 96 px pictures', tl.every(Boolean), JSON.stringify(tl));
  t('login role buttons: Courier and Service logos differ from the transport logo', await p.evaluate(async (u) => { const sig = (url) => new Promise(res => { const i = new Image(); i.onload = () => { const c = document.createElement('canvas'); c.width = 16; c.height = 16; const x = c.getContext('2d'); x.drawImage(i, 0, 0, 16, 16); res(Array.from(x.getImageData(0, 0, 16, 16).data)); }; i.src = url; }); const a = await sig(u + 'tab-customer.png'), b = await sig(u + 'tab-courier.png'), c = await sig(u + 'tab-service.png'); const d = (x, y) => x.reduce((s, v, k) => s + Math.abs(v - y[k]), 0) / x.length; return d(a, b) > 0.5 && d(a, c) > 0.5 && d(b, c) > 0.5; }, HOST + '/delivery/assets/images/'));
  t('no page errors', errs.length === 0, errs.slice(0, 3).join(' | '));
  await b.close(); srv.close();
  console.log(pass + ' checks passed' + (fails.length ? ', ' + fails.length + ' FAILED:\n - ' + fails.join('\n - ') : ''));
  process.exit(fails.length ? 1 : 0);
})();
