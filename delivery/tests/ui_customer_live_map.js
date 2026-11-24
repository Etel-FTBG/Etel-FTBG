// Customer app > track order: the LIVE map. Shop pin, home pin, the road route of the CURRENT step (courier to shop, then courier to you), a LIVE badge,
// minutes and km left, and no hammering of the road service. Real page and files, mocked API, a FAKE Google Maps that records what is drawn.
// Needs Playwright (node).   node delivery/tests/ui_customer_live_map.js
const { chromium } = require('playwright'); const path = require('path'); const http = require('http'); const fs = require('fs');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });
const SHOP = { lat: 7.05, lng: 38.48 }, HOME = { lat: 7.06, lng: 38.49 };
const FAKE = () => {
  const rec = { markers: [], lines: [], routes: [], fits: 0 };
  const ll = (p) => ({ lat: () => p.lat, lng: () => p.lng });
  class Marker { constructor(o) { this.o = o; this.pos = o.position; this.map = o.map; this.op = 1; rec.markers.push(this); } setPosition(p) { this.pos = p; } getPosition() { return ll(this.pos); } setMap(m) { this.map = m; } setOpacity(v) { this.op = v; } }
  class Polyline { constructor(o) { this.o = o; this.path = o.path; this.map = o.map; rec.lines.push(this); } setPath(p) { this.path = p; } setOptions(o) { Object.assign(this.o, o); } setMap(m) { this.map = m; } getMap() { return this.map; } }
  class GMap { constructor(el, o) { this.el = el; this.o = o; } getDiv() { return this.el; } addListener() {} fitBounds() { rec.fits++; } setCenter() {} setOptions() {} }
  class Bounds { constructor() { this.n = 0; } extend() { this.n++; } }
  class Dir { route(req, cb) { rec.routes.push({ from: req.origin, to: req.destination }); const f = req.origin, to = req.destination; setTimeout(() => cb({ routes: [{ legs: [{ distance: { value: 2300 }, duration: { value: 420 } }], overview_path: [ll(f), ll({ lat: (f.lat + to.lat) / 2, lng: (f.lng + to.lng) / 2 }), ll(to)] }] }, 'OK'), 5); } }
  window.__rec = rec;
  window.google = { maps: { Map: GMap, Marker, Polyline, LatLngBounds: Bounds, Size: function () {}, Point: function () {}, DirectionsService: Dir, event: { addListener() {} } } };
};
const TL = (cur) => ['pending', 'assigned', 'preparing', 'accepted', 'picked_up', 'in_transit', 'delivered'].map((s, i, a) => ({ status: s, label: s, done: i <= a.indexOf(cur), current: s === cur }));
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const PAGE = 'http://127.0.0.1:' + srv.address().port + '/customer.html';
  const b = await chromium.launch();
  for (const lang of ['en', 'am']) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    await ctx.addInitScript(l => { localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 5, name: 'Cust', phone: '0911000001', role: 'customer' })); localStorage.setItem('etel_lang', l); localStorage.setItem('etel_onboarded', '1'); }, lang);
    await ctx.addInitScript(FAKE);
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message + ' @ ' + ((e.stack || '').split('\n')[1] || '').trim()));
    await p.route(/maps\.googleapis\.com|fonts\.(googleapis|gstatic)\.com/, r => r.abort());
    let ST = { stage: 'preparing', courier: null };
    await p.route(/boot\.php/, r => {
      const u = r.request().url(); let body = {}; try { body = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
      const act = (u.match(/action=([a-z_]+)/) || [])[1] || body.action; let data = {};
      if (act === 'track_order') data = { order: { id: 5, order_code: 'ETD-1', status: ST.stage === 'preparing' ? 'assigned' : ST.stage, stage: ST.stage, delivery_lat: HOME.lat, delivery_lng: HOME.lng, pickup_point: SHOP, courier_name: ST.courier ? 'Abebe' : null, courier_lat: ST.courier ? ST.courier.lat : null, courier_lng: ST.courier ? ST.courier.lng : null, courier_vehicle: 'moto', items: [], service_giver_name: 'Shop', placed_at: '2026-11-24 10:00:00' }, timeline: TL(ST.stage) };
      else if (act === 'customer_get_items' || act === 'get_items') data = { items: [], count: 0 };
      else if (act === 'order_chat_inbox') data = { chats: [], unread_total: 0 };
      else if (act === 'customer_get_orders') data = { orders: [] };
      else if (act === 'get_service_areas') data = { areas: [], enforced: false };
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
    });
    await p.goto(PAGE); await p.waitForTimeout(2200);
    const am = lang === 'am', n = lang;
    const R = () => p.evaluate(() => ({ markers: window.__rec.markers.map(m => ({ pos: m.pos, op: m.op, map: !!m.map })), lines: window.__rec.lines.map(l => ({ path: l.path, dashed: !!(l.o.icons && l.o.icons.length), map: !!l.map })), routes: window.__rec.routes, live: (document.querySelector('.clm-live') || {}).innerText || '', liveOff: !!document.querySelector('.clm-live.off'), chip: (document.querySelector('.clm-chip') || {}).innerText || '', fit: !!document.querySelector('.clm-fit'), huds: document.querySelectorAll('.clm-live').length }));
    const poll = async () => { await p.evaluate(() => refreshTrackOrder()); await p.waitForTimeout(400); };
    await p.evaluate(() => openTrackOrder(5)); await p.waitForTimeout(1200);
    let s = await R();
    t(n + ' 1 preparing: a shop pin and a home pin', s.markers.some(m => m.pos.lat === SHOP.lat && m.pos.lng === SHOP.lng) && s.markers.some(m => m.pos.lat === HOME.lat && m.pos.lng === HOME.lng), JSON.stringify(s.markers));
    t(n + ' 1 no courier: the badge is grey and says it waits for the courier location', s.liveOff && (am ? /የአቅራቢው ቦታ/ : /Waiting for the courier location/).test(s.live), s.live);
    t(n + ' 1 the chip says the order is being prepared', (am ? /ትዕዛዝዎ እየተዘጋጀ/ : /Preparing your order/).test(s.chip), s.chip);
    t(n + ' 1 the shop-to-home line is drawn, dashed (the part still to come)', s.lines.some(l => l.dashed && l.map && l.path[0].lat === SHOP.lat), JSON.stringify(s.lines.map(l => l.dashed)));
    t(n + ' 1 a recenter button exists', s.fit);
    ST = { stage: 'assigned', courier: { lat: 7.04, lng: 38.47 } }; await poll(); await p.waitForTimeout(300); s = await R();
    t(n + ' 2 courier on the way to the shop: the road is asked FROM the courier TO the shop', s.routes.some(r => r.from.lat === 7.04 && r.to.lat === SHOP.lat && r.to.lng === SHOP.lng), JSON.stringify(s.routes));
    t(n + ' 2 the badge is LIVE', !s.liveOff && (am ? /ቀጥታ/ : /LIVE/).test(s.live), s.live);
    t(n + ' 2 the chip says heading to the shop with minutes and km', (am ? /ወደ ሱቁ እየሄደ/ : /Heading to the shop/).test(s.chip) && /7/.test(s.chip) && /2\.3/.test(s.chip), s.chip);
    t(n + ' 2 a solid route line is drawn', s.lines.some(l => !l.dashed && l.map && l.path.length >= 3), JSON.stringify(s.lines.map(l => [l.dashed, l.path.length])));
    const callsBefore = s.routes.length;
    ST = { stage: 'assigned', courier: { lat: 7.042, lng: 38.472 } }; await poll(); s = await R();
    t(n + ' 3 the courier moves a little: Google is NOT asked again (kept the road)', s.routes.length === callsBefore, callsBefore + ' -> ' + s.routes.length);
    t(n + ' 3 ... and the road now starts at the courier', s.lines.some(l => !l.dashed && l.map && l.path[0].lat === 7.042), JSON.stringify(s.lines.map(l => l.path[0])));
    ST = { stage: 'accepted', courier: { lat: 7.0498, lng: 38.4798 } }; await poll(); s = await R();
    t(n + ' 4 ready at the shop: the chip says waiting at the shop', (am ? /በሱቁ እየጠበቀ/ : /Waiting at the shop/).test(s.chip), s.chip);
    ST = { stage: 'picked_up', courier: { lat: 7.052, lng: 38.482 } }; await poll(); await p.waitForTimeout(300); s = await R();
    t(n + ' 5 picked up: the road is asked from the courier to HOME', s.routes.some(r => r.to.lat === HOME.lat && r.to.lng === HOME.lng && r.from.lat === 7.052), JSON.stringify(s.routes.slice(-2)));
    t(n + ' 5 the chip says on the way to you', (am ? /ወደ እርስዎ እየመጣ/ : /On the way to you/).test(s.chip), s.chip);
    t(n + ' 5 the shop pin fades (done) and the dashed shop-to-home line is gone', s.markers.some(m => m.pos.lat === SHOP.lat && m.op < 1) && !s.lines.some(l => l.dashed && l.map), JSON.stringify(s.markers.map(m => m.op)));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/customer_live_map_' + n + '.png' });
    ST = { stage: 'delivered', courier: { lat: 7.0599, lng: 38.4899 } }; await poll(); s = await R();
    t(n + ' 6 delivered: no route line is left and the chip says delivered', !s.lines.some(l => l.map) && (am ? /ደርሷል/ : /Delivered/).test(s.chip), s.chip);
    await p.evaluate(() => closeTrackOrder()); await p.waitForTimeout(200);
    t(n + ' 7 closing the order removes the badge and the chip', (await p.evaluate(() => document.querySelectorAll('.clm-live,.clm-chip,.clm-fit').length)) === 0);
    ST = { stage: 'preparing', courier: null }; await p.evaluate(() => openTrackOrder(5)); await p.waitForTimeout(1200); s = await R();
    t(n + ' 7 opening it again shows exactly one badge', s.huds === 1, s.huds);
    t(n + ' 8 no page errors', errs.length === 0, errs.join('|')); await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
