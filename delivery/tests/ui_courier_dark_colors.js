// Courier app, dark mode colours: (1) the vehicle picture keeps its real colours although the dark map is drawn through an invert filter; (2) the small location map
// is darkened with the big one; (3) "Open in Maps" is white on its dark pill; (4) no blue glow under the secondary buttons (the app is gold). Real page and files.
// Needs Playwright (node).   node delivery/tests/ui_courier_dark_colors.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'application/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp' };
const srv = http.createServer((q, r) => { const u = q.url.split('?')[0]; if (u === '/marker.html') { r.setHeader('Content-Type', 'text/html; charset=utf-8'); return r.end(MARKER); } const f = path.join(ROOT, decodeURIComponent(u).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });
// a stand-in map: the same invert filter core.js puts on #map in dark mode, with the vehicle marker element inside it
const MARKER = `<!doctype html><html><body style="margin:0;background:#888"><div id="map" style="filter:invert(90%) hue-rotate(180deg);padding:20px;background:#ccc"><div id="slot"></div></div>
<span id="cp-vehicle">qute</span><script>let courierMarker = { content: document.createElement('div') };</script>
<script src="/delivery/js/etel-vehicle-pic.js"></script><script src="/delivery/js/courier-vehicle-marker.js"></script></body></html>`;
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  // 1. the vehicle picture's colours through the dark map filter
  const colourAfter = async (dark) => {
    const ctx = await b.newContext({ viewport: { width: 300, height: 200 }, serviceWorkers: 'block' }); await ctx.addInitScript(() => { localStorage.setItem('etel_token', 'T'); });
    const p = await ctx.newPage(); await p.goto(HOST + '/marker.html'); await p.waitForTimeout(1800);
    await p.evaluate(d => { document.body.classList.toggle('dark-theme', d); document.getElementById('slot').appendChild(courierMarker.content); }, dark); await p.waitForTimeout(500);
    const im = await p.evaluate(async () => { const i = courierMarker.content.querySelector('img'); if (!i) return ''; const bl = await (await fetch(i.src)).blob(); return await new Promise(r => { const fr = new FileReader(); fr.onload = () => r(fr.result); fr.readAsDataURL(bl); }); });
    const shot = await p.locator('#slot img').screenshot(); const page2 = await ctx.newPage();
    const res = await page2.evaluate(async ([a, c]) => { const load = u => new Promise(r => { const i = new Image(); i.onload = () => r(i); i.src = u; });
      const px = img => { const cv = document.createElement('canvas'); cv.width = 48; cv.height = 40; const x = cv.getContext('2d'); x.drawImage(img, 0, 0, 48, 40); return x.getImageData(0, 0, 48, 40).data; };
      const mean = (d, mask) => { let r = 0, g = 0, b2 = 0, n = 0; for (let k = 0; k < d.length; k += 4) { if (mask[k + 3] > 230) { r += d[k]; g += d[k + 1]; b2 += d[k + 2]; n++; } } return [r / n, g / n, b2 / n]; };   // only where the picture itself is solid (not its transparent corners)
      const o = px(await load(c)), sh = px(await load('data:image/png;base64,' + a));
      return { shot: mean(sh, o), orig: mean(o, o) }; }, [shot.toString('base64'), im]);
    await ctx.close(); return res;
  };
  const dist = (a, b2) => Math.sqrt(a.reduce((s, v, i) => s + (v - b2[i]) * (v - b2[i]), 0));
  const dk = await colourAfter(true), lt = await colourAfter(false);
  t('1 dark map: the vehicle picture keeps its real colours (average colour within 30 of the original)', dist(dk.shot, dk.orig) < 30, JSON.stringify(dk));
  t('1 (control) without the fix the same filter would turn it pale (the test can tell the difference)', dist(lt.shot, lt.orig) > 60, JSON.stringify(lt));
  // 2-4. the real courier page
  for (const theme of ['dark', 'light']) {
    const ctx = await b.newContext({ viewport: { width: 412, height: 915 }, isMobile: true, hasTouch: true, serviceWorkers: 'block' });
    await ctx.addInitScript(th => { try { localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 9, name: 'Cou', phone: '0911000001', role: 'courier' })); localStorage.setItem('etel_lang', 'en'); localStorage.setItem('theme', th); } catch (e) {} }, theme);
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route(/cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net|maps\.googleapis\.com|fonts\.(googleapis|gstatic)\.com/, r => r.abort());
    await p.route(/boot\.php/, r => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { orders: [], areas: [], enforced: false, chats: [], unread_total: 0, courier: { id: 22, status: 'online', vehicle_type: 'qute', approval_status: 'approved', active: 1 } } }) }));
    await p.goto(HOST + '/delivery/courier/index.html', { waitUntil: 'load' }); await p.waitForTimeout(3000);
    const r = await p.evaluate(() => {
      const host = document.createElement('div'); host.innerHTML = '<a href="https://www.google.com/maps/dir/?api=1&destination=7,38" style="display:block;position:relative"><img alt="Customer location map" src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" style="width:100%;height:60px"><div id="t-open" style="position:absolute;bottom:8px;right:8px;background:rgba(0,0,0,.75);color:#fff;font-size:11px;padding:4px 10px">Open in Maps</div></a><button class="btn btn-secondary" id="t-sec">Earnings</button>';
      document.body.appendChild(host);
      const im = host.querySelector('img'), op = host.querySelector('#t-open'), sec = host.querySelector('#t-sec');
      return { filter: getComputedStyle(im).filter, fill: getComputedStyle(op).webkitTextFillColor, shadow: getComputedStyle(sec).boxShadow, dark: document.body.classList.contains('dark-theme') };
    });
    t(theme + ' 2 the page is really in ' + theme + ' mode', r.dark === (theme === 'dark'), JSON.stringify(r));
    if (theme === 'dark') t(theme + ' 2 the small location map is darkened like the big one', /invert/.test(r.filter) && /hue-rotate/.test(r.filter), r.filter); else t(theme + ' 2 in light mode the small map is left alone', r.filter === 'none', r.filter);
    t(theme + ' 3 "Open in Maps" is white on its dark pill', r.fill === 'rgb(255, 255, 255)', r.fill);
    t(theme + ' 4 no blue glow under the secondary buttons', !/37, 99, 235/.test(r.shadow) && /212, 175, 55/.test(r.shadow), r.shadow);
    t(theme + ' 5 no page errors', errs.length === 0, errs.join('|')); await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
