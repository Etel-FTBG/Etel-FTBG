// The Delivery, Ride and Driver apps use ONE logo (black marble, gold ring, blue car, parcels, runner, ETEL): home-screen icons (all sizes, maskable, apple-touch), start screens,
// and the logo boxes inside the pages. Real pages and files, fake API answers. The browser decodes the pictures, so this reads real pixels.   node delivery/tests/ui_transport_brand.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp' };
const srv = http.createServer((q, r) => { const u = decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, ''); fs.readFile(path.join(ROOT, u), (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('content-type', MIME[path.extname(u)] || 'application/octet-stream'); r.end(d); } }); });
// reads a picture into a canvas and measures it
const measure = (url) => new Promise(res => { const i = new Image(); i.onload = () => { const c = document.createElement('canvas'); c.width = i.naturalWidth; c.height = i.naturalHeight; const x = c.getContext('2d'); x.drawImage(i, 0, 0); const w = c.width, h = c.height;
  const px = (a, b) => { const d = x.getImageData(a, b, 1, 1).data; return { r: d[0], g: d[1], b: d[2], a: d[3] }; }; const lum = p => 0.2126 * p.r + 0.7152 * p.g + 0.0722 * p.b;
  const all = x.getImageData(0, 0, w, h).data; let gold = 0, farGold = 0, n = 0; const cx = w / 2, cy = h / 2;
  for (let yy = 0; yy < h; yy += 2) for (let xx = 0; xx < w; xx += 2) { const k = (yy * w + xx) * 4; const r = all[k], g = all[k + 1], b = all[k + 2], a = all[k + 3]; if (a < 200) continue; n++; if (r > 150 && g > 110 && b < 95 && r > b + 60) { gold++; farGold = Math.max(farGold, Math.hypot(xx - cx, yy - cy) / w); } }
  const corners = [px(2, 2), px(w - 3, 2), px(2, h - 3), px(w - 3, h - 3)];
  res({ w, h, cornerLum: corners.map(lum), cornerAlpha: corners.map(p => p.a), cornerGreen: corners.map(p => p.g - p.r), centre: px(w >> 1, (h >> 1) + 8), goldShare: gold / Math.max(n, 1), farGold }); }; i.onerror = () => res(null); i.src = url; });
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1000, height: 800 }, serviceWorkers: 'block' }); const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
  await p.route(/maps\.googleapis|gstatic|cdnjs|jsdelivr|fonts\.|unpkg/, r => r.abort()); await p.route(/\.php/, r => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: {} }) }));
  await p.goto(HOST + '/delivery/ride/index.html', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(800);
  // 1. home-screen icons: dark marble corners, a gold ring, the same for all three apps
  for (const pre of ['', 'ride-', 'driver-']) {
    const nm = (pre || 'delivery-') + 'icon-512: ';
    const m = await p.evaluate(measure, HOST + '/delivery/icons/' + pre + 'icon-512.png');
    t(nm + 'loads at 512 x 512', m && m.w === 512 && m.h === 512, JSON.stringify(m));
    t(nm + 'the corners are dark black marble (not the old green coin)', m && m.cornerLum.every(l => l < 50) && m.cornerGreen.every(g => Math.abs(g) < 22), JSON.stringify(m && [m.cornerLum, m.cornerGreen]));
    t(nm + 'a gold ring is there (2 to 45 percent gold) and it reaches near the edge', m && m.goldShare > 0.02 && m.goldShare < 0.45 && m.farGold > 0.38, JSON.stringify(m && [m.goldShare, m.farGold]));
    const mk = await p.evaluate(measure, HOST + '/delivery/icons/' + pre + 'icon-512-maskable.png');
    t(nm.replace('512', '512-maskable') + 'the logo stays inside the safe circle (gold within 44 percent of the width from the centre)', mk && mk.farGold < 0.44 && mk.goldShare > 0.015, JSON.stringify(mk && [mk.goldShare, mk.farGold]));
  }
  const at = await p.evaluate(measure, HOST + '/delivery/icons/apple-touch-icon.png'); t('apple-touch-icon is 180 x 180, opaque', at && at.w === 180 && at.cornerAlpha.every(a => a === 255), JSON.stringify(at));
  // 2. start screens: the middle is the dark navy logo, not the old bright gold coin
  for (const f of ['ride-splash-750x1334.png', 'splash-750x1334.png', 'ride-splash-1536x2048.png', 'splash-2048x2732.png']) {
    const m = await p.evaluate(measure, HOST + '/delivery/icons/' + f); const w = m && m.w;
    const dark = await p.evaluate(({ u }) => new Promise(res => { const i = new Image(); i.onload = () => { const c = document.createElement('canvas'); c.width = i.naturalWidth; c.height = i.naturalHeight; const x = c.getContext('2d'); x.drawImage(i, 0, 0);
      const W = c.width, H = c.height, d = x.getImageData(Math.round(W * 0.3), Math.round(H * 0.449 - W * 0.2), Math.round(W * 0.4), Math.round(W * 0.4)).data; let dk = 0, n = 0; for (let k = 0; k < d.length; k += 16) { n++; if (0.2126 * d[k] + 0.7152 * d[k + 1] + 0.0722 * d[k + 2] < 70) dk++; } res(dk / n); }; i.src = u; }), { u: HOST + '/delivery/icons/' + f });
    t(f + ': the new dark logo sits in the middle (more than 35 percent dark pixels there)', dark > 0.35, String(dark));
  }
  // 3. logo boxes inside the pages: transparent corners, solid middle, asked for with ?v=2
  // the pages ask for the pictures with ?v=2 (the Ride and Driver pages send you to the login page when nobody is signed in, so read their source)
  const pages = [['/delivery/ride/index.html', 'etel-ride-coin'], ['/delivery/driver/index.html', 'etel-driver-coin'], ['/delivery/index.html', 'etel-delivery-coin-crop'], ['/delivery/index.html', 'etel-ride-coin'], ['/delivery/customer.html', 'etel-delivery-coin']];
  for (const [url, key] of pages) {
    const refs = await p.evaluate(async ({ u, k }) => { const tx = await (await fetch(u)).text(); return (tx.match(new RegExp(k + '\\.(?:png|webp)[^"\' )]*', 'g')) || []); }, { u: HOST + url, k: key });
    t(url + ' ' + key + ': the page uses the picture and every use ends with ?v=2', refs.length > 0 && refs.every(s => /\?v=2$/.test(s)), JSON.stringify(refs));
    const m = await p.evaluate(measure, HOST + '/delivery/assets/images/' + key + '.png?v=2');
    t(key + '.png is 640 x 640 with transparent corners and a solid middle', m && m.w === 640 && m.h === 640 && m.cornerAlpha.every(a => a === 0) && m.centre.a === 255, JSON.stringify(m && [m.w, m.h, m.cornerAlpha, m.centre]));
    t(key + '.png: the middle is the dark navy disc (not the old bright coin)', m && (0.2126 * m.centre.r + 0.7152 * m.centre.g + 0.0722 * m.centre.b) < 120, JSON.stringify(m && m.centre));
  }
  // the webp versions too
  for (const k of ['etel-ride-coin', 'etel-driver-coin', 'etel-delivery-coin-crop']) { const m = await p.evaluate(measure, HOST + '/delivery/assets/images/' + k + '.webp'); t(k + '.webp is 640 x 640 and transparent in the corners', m && m.w === 640 && m.cornerAlpha.every(a => a === 0), JSON.stringify(m && [m.w, m.cornerAlpha])); }
  t('no page errors on the Ride, Driver and Delivery pages', errs.length === 0, errs.slice(0, 3).join(' | '));
  await b.close(); srv.close();
  console.log(pass + ' checks passed' + (fails.length ? ', ' + fails.length + ' FAILED:\n - ' + fails.join('\n - ') : ''));
  process.exit(fails.length ? 1 : 0);
})();
