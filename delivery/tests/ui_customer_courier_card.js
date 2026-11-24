// Customer app > track order: the "Your courier" card shows who is coming (photo, name, rating, deliveries), the REAL picture of the vehicle, the plate, the make
// and colour, the vehicle photos (tap to enlarge) and a call button. Real page and files, mocked API.   node delivery/tests/ui_customer_courier_card.js
const { chromium } = require('playwright'); const path = require('path'); const http = require('http'); const fs = require('fs');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'application/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
const TL = (cur) => ['pending', 'assigned', 'preparing', 'accepted', 'picked_up', 'in_transit', 'delivered'].map((s, i, a) => ({ status: s, label: s, done: i <= a.indexOf(cur), current: s === cur }));
const CARD = (n) => ({ name: 'Abebe Kebede', phone: '0911223344', vehicle_type: 'qute', plate: 'AA 12345', rating: 4.8, deliveries: 57, since: 2025, photo: 'http://127.0.0.1/delivery/api/private_file.php?f=driver_documents:selfie.jpg&e=' + (1000 + n) + '&s=aaa' + n, vehicle_info: 'Red Bajaj, 2021', vehicle_photo_front: 'http://127.0.0.1/uploads/vehicle_photos/front.jpg', vehicle_photo_side: 'http://127.0.0.1/uploads/vehicle_photos/side.jpg' });
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const PAGE = 'http://127.0.0.1:' + srv.address().port + '/customer.html';
  const b = await chromium.launch();
  for (const c of [{ n: 'en', lang: 'en', card: true }, { n: 'am', lang: 'am', card: true }, { n: 'old-server', lang: 'en', card: false }]) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    await ctx.addInitScript(l => { localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 5, name: 'Cust', phone: '0911000001', role: 'customer' })); localStorage.setItem('etel_lang', l); }, c.lang);
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    let poll = 0, BADPHOTO = false;
    await p.route(/maps\.googleapis\.com|fonts\.(googleapis|gstatic)\.com/, r => r.abort());
    await p.route(/selfie\.jpg|front\.jpg|side\.jpg/, r => { if (BADPHOTO && /selfie/.test(r.request().url())) return r.fulfill({ status: 404, body: '' }); r.fulfill({ contentType: 'image/png', body: PNG }); });
    await p.route(/boot\.php/, r => {
      const u = r.request().url(); let body = {}; try { body = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
      const act = (u.match(/action=([a-z_]+)/) || [])[1] || body.action; let data = {};
      if (act === 'track_order') { poll++; data = { order: { id: 5, order_code: 'ETD-1', status: 'picked_up', stage: 'picked_up', courier_name: 'Abebe Kebede', courier_phone: '0911223344', courier_vehicle: 'qute', courier_avg_rating: 4.8, courier_card: c.card ? CARD(poll) : undefined, items: [], delivery_lat: 7.06, delivery_lng: 38.49, placed_at: '2026-11-24 10:00:00' }, timeline: TL('picked_up') }; }
      else if (act === 'customer_get_items' || act === 'get_items') data = { items: [], count: 0 };
      else if (act === 'order_chat_inbox') data = { chats: [], unread_total: 0 };
      else if (act === 'customer_get_orders') data = { orders: [] };
      else if (act === 'get_service_areas') data = { areas: [], enforced: false };
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
    });
    await p.goto(PAGE); await p.waitForTimeout(2000); const n = c.n, am = c.lang === 'am';
    await p.evaluate(() => { [].slice.call(document.querySelectorAll('button')).forEach(x => { if (/^\s*Not Now\s*$/.test(x.textContent)) x.click(); }); const o = document.getElementById('perm-onboard-bg'); if (o) o.remove(); });
    await p.evaluate(() => openTrackOrder(5)); await p.waitForTimeout(1200);
    const txt = () => p.evaluate(() => (document.getElementById('track-courier-card') || {}).innerText || '');
    if (!c.card) {
      const tx = await txt();
      t(n + ' an older server (no card) still shows the simple courier line', /Abebe Kebede/.test(tx) && (await p.locator('.ccd').count()) === 0, tx);
      t(n + ' no page errors', errs.length === 0, errs.join('|')); await ctx.close(); continue;
    }
    const tx = await txt();
    t(n + ' 1 the full card is there', (await p.locator('.ccd').count()) === 1, tx);
    t(n + ' 1 name, rating, deliveries and since', /Abebe Kebede/.test(tx) && /4\.8/.test(tx) && /57/.test(tx) && /2025/.test(tx), tx);
    t(n + ' 1 the title says your courier', (am ? /አቅራቢዎ/ : /YOUR COURIER/).test(tx), tx);
    t(n + ' 1 the plate number, in a plate box', (await p.locator('.ccd-plate').innerText()) === 'AA 12345');
    t(n + ' 1 the make and colour text', /Red Bajaj, 2021/.test(tx));
    t(n + ' 1 the vehicle type in words (Qute / bajaj)', (am ? /ኩቴ/ : /Qute \(bajaj\)/).test(tx), tx);
    t(n + ' 2 the REAL vehicle picture of a qute (not an emoji)', await p.evaluate(() => { const i = document.querySelector('.ccd-vp img'); return !!i && /img\/vehicles\/qute\.png/.test(i.src); }));
    t(n + ' 2 the courier photo is shown', await p.evaluate(() => { const i = document.querySelector('.ccd-av img'); return !!i && /selfie\.jpg/.test(i.src); }));
    t(n + ' 3 two vehicle photos (front and side)', (await p.locator('.ccd-ph button').count()) === 2);
    t(n + ' 3 a call button with the number', (await p.locator('a.ccd-call').getAttribute('href')) === 'tel:0911223344');
    const src1 = await p.evaluate(() => document.querySelector('.ccd-av img').src);
    await p.evaluate(() => refreshTrackOrder()); await p.waitForTimeout(500);
    t(n + ' 4 a refresh keeps the same photo address (the signed link changes, the picture does not reload)', (await p.evaluate(() => document.querySelector('.ccd-av img').src)) === src1, src1);
    await p.locator('.ccd-ph button').first().click(); await p.waitForTimeout(200);
    t(n + ' 5 tapping a vehicle photo opens it large', (await p.locator('.ccd-box img').count()) === 1);
    await p.locator('.ccd-box button').click(); await p.waitForTimeout(150);
    t(n + ' 5 Close closes it', (await p.locator('.ccd-box').count()) === 0);
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/courier_card_' + n + '.png', fullPage: false });
    if (c.n === 'en') {
      BADPHOTO = true; await p.evaluate(() => { document.getElementById('track-courier-card').innerHTML = ''; });
      await p.evaluate(() => { const o = { courier_name: 'Zed Man', courier_card: { name: 'Zed Man', phone: '', vehicle_type: 'moto', plate: '', rating: 0, deliveries: 0, since: null, photo: 'http://127.0.0.1/delivery/api/private_file.php?f=driver_documents:selfie_bad.jpg&e=1&s=b', vehicle_info: '', vehicle_photo_front: '', vehicle_photo_side: '' } }; document.getElementById('track-courier-card').innerHTML = etelCourierCard(o); });
      await p.waitForTimeout(500);
      const t2 = await txt();
      t(n + ' 6 a photo that does not load falls back to the first letter', (await p.locator('.ccd-av img').count()) === 0 && (await p.locator('.ccd-av').innerText()) === 'Z', t2);
      t(n + ' 6 a new courier says so, and no plate box or call button when there is none', /New courier/.test(t2) && (await p.locator('.ccd-plate').count()) === 0 && (await p.locator('a.ccd-call').count()) === 0, t2);
    }
    const over = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1); t(n + ' 7 no sideways scroll', !over);
    t(n + ' 8 no page errors', errs.length === 0, errs.join('|')); await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
