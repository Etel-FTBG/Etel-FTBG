// Courier app (courier/index.html): the Navigate buttons on a job open the app's OWN route (drawRouteOnMap + the turn-by-turn screen) and do NOT open
// the phone's map app. When the in-app map is not ready, the link still opens the map app (nothing is lost). Real page and files, mocked API.
//   NODE_PATH=/opt/node22/lib/node_modules node delivery/tests/ui_courier_navigate_inapp.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.webp': 'image/webp' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });
const job = (id, code) => ({ id: id, order_code: code, status: 'pending', assignment_status: 'accepted', from_zone: 'Mehal Ketema', to_zone: 'University', recipient_name: 'Emu', recipient_phone: '09****', fare_total: 100, delivery_fee: 105, package_type: 'food', placed_at: '2026-11-24 10:00:00', payment_method: 'cash' });
const GEO = { 7: { pickup: { lat: 7.05, lng: 38.48 }, drop: { lat: 7.06, lng: 38.49 }, pickup_label: 'Geo Kitchen', pickup_address: 'Piassa', drop_label: 'University', pickup_km: 2.3, drop_km: 3.1 } };
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block', geolocation: { latitude: 7.04, longitude: 38.47 }, permissions: ['geolocation'] });
  await ctx.addInitScript(() => { try { localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 9, name: 'Cou', phone: '0911000001', role: 'courier' })); localStorage.setItem('etel_lang', 'en'); } catch (e) {} });
  const p = await ctx.newPage(); const errs = []; let popups = 0;
  ctx.on('page', () => { popups++; });
  p.on('pageerror', e => errs.push(e.message));
  await p.route(/cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net|maps\.googleapis\.com|fonts\.(googleapis|gstatic)\.com/, r => r.abort());
  await p.route(/boot\.php/, r => {
    let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
    const u = r.request().url(); const act = d.action || ((u.match(/action=([a-z_]+)/) || [])[1]); let data = {};
    if (act === 'get_service_areas') data = { areas: [], enforced: false };
    else if (act === 'order_chat_inbox') data = { chats: [], unread_total: 0 };
    else if (act === 'courier_get_available_orders') data = { orders: [job(7, 'ETD-AAA111')] };
    else if (act === 'courier_job_geo') data = { jobs: GEO };
    else if (act === 'courier_get_profile') data = { courier: { id: 22, status: 'online', vehicle_type: 'moto', approval_status: 'approved', active: 1 }, profile: { id: 22, status: 'online', vehicle_type: 'moto', approval_status: 'approved', active: 1 } };
    else if (act === 'courier_get_my_orders') data = { orders: [] };
    else if (act === 'courier_earnings_summary') data = { today: { earned: 0, deliveries: 0, goal: 1000 }, week: { earned: 0 }, streak_days: 0, goal: 1000 };
    else if (act === 'courier_get_statement') data = { week_start: '2026-11-23', week_end: '2026-11-29', totals: { deliveries: 0, pay: 0, tips: 0, earned: 0 }, days: [], orders: [], prev_week: '2026-11-16', next_week: null };
    r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
  });
  await p.goto(HOST + '/delivery/courier/index.html', { waitUntil: 'load' }); await p.waitForTimeout(4500);
  const links = p.locator('#jobs-content .job-card').nth(0).locator('a.cjn-go');
  t('the job has its two Navigate buttons', (await links.count()) === 2);
  t('the buttons carry the point and a label', (await links.nth(0).getAttribute('data-lat')) === '7.05' && (await links.nth(0).getAttribute('data-lng')) === '38.48' && /Pickup/.test(await links.nth(0).getAttribute('data-label')), await links.nth(0).getAttribute('data-label'));

  // 1. the in-app map is ready: stand in for it (the real Google map cannot load in this test)
  await p.evaluate(() => {
    window.google = window.google || {}; window.google.maps = window.google.maps || {};
    window.google.maps.DirectionsService = window.google.maps.DirectionsService || function () {};
    window.__nav = []; window.__asked = 0;
    const ask = window.__courierNavAsk; window.__courierNavAsk = function () { window.__asked++; if (ask) ask(); };
    window.drawRouteOnMap = function (lat, lng, label) { window.__nav.push({ lat, lng, label }); document.getElementById('map-nav-bar').style.display = 'block'; };
  });
  const url0 = p.url();
  await links.nth(0).click(); await p.waitForTimeout(400);
  let nav = await p.evaluate(() => window.__nav);
  t('Pickup Navigate draws the route in the app, to the shop pin', nav.length === 1 && nav[0].lat === 7.05 && nav[0].lng === 38.48, JSON.stringify(nav));
  t('the label names the place', /Pickup/.test((nav[0] || {}).label || '') && /Geo Kitchen/.test((nav[0] || {}).label || ''), JSON.stringify(nav));
  t('the full-screen navigation was asked for (the courier tapped it)', (await p.evaluate(() => window.__asked)) === 1);
  t('no map app, no new tab, the page did not move', popups === 0 && p.url() === url0, popups + ' ' + p.url());
  await links.nth(1).click(); await p.waitForTimeout(400);
  nav = await p.evaluate(() => window.__nav);
  t('Dropoff Navigate draws the route to the customer pin', nav.length === 2 && nav[1].lat === 7.06 && nav[1].lng === 38.49 && /Dropoff/.test(nav[1].label), JSON.stringify(nav));
  t('still no new tab', popups === 0);

  // 2. a link baked into the app ("Open in Maps" picture) is taken in-app too
  await p.evaluate(() => {
    const a = document.createElement('a'); a.id = 'bakedlink'; a.href = 'https://www.google.com/maps/dir/?api=1&destination=7.07,38.5&travelmode=driving'; a.target = '_blank'; a.textContent = 'Open in Maps';
    a.style.cssText = 'display:block;padding:20px;position:relative;z-index:99999;background:#fff;color:#000'; document.body.prepend(a);
  });
  await p.click('#bakedlink'); await p.waitForTimeout(300);
  nav = await p.evaluate(() => window.__nav);
  t('an "Open in Maps" link of the app is also taken in-app', nav.length === 3 && nav[2].lat === 7.07 && nav[2].lng === 38.5, JSON.stringify(nav));
  t('and opens no new tab', popups === 0);

  // 3. an ordinary link is left alone
  await p.evaluate(() => { const a = document.createElement('a'); a.id = 'otherlink'; a.href = 'https://example.com/'; a.target = '_blank'; a.textContent = 'x'; a.style.cssText = 'display:block;padding:20px;position:relative;z-index:99999;background:#fff;color:#000'; document.body.prepend(a); });
  const before = (await p.evaluate(() => window.__nav)).length;
  const popup = ctx.waitForEvent('page', { timeout: 3000 }).catch(() => null);
  await p.click('#otherlink'); const pu = await popup;
  t('any other link still works as before', !!pu && (await p.evaluate(() => window.__nav)).length === before);
  if (pu) await pu.close().catch(() => {});

  // 4. the in-app map is NOT ready (it never drew the route): the old link still opens the phone's map app
  await p.evaluate(() => { window.drawRouteOnMap = function () { /* map not ready: it shows a message and draws nothing */ }; });
  const popup2 = ctx.waitForEvent('page', { timeout: 4000 }).catch(() => null);
  await links.nth(0).click(); const pu2 = await popup2;
  t('map not ready: the link opens the map app as before (nothing is lost)', !!pu2, 'no popup');
  if (pu2) await pu2.close().catch(() => {});
  await p.evaluate(() => { delete window.drawRouteOnMap; });
  const popup3 = ctx.waitForEvent('page', { timeout: 4000 }).catch(() => null);
  await links.nth(1).click(); const pu3 = await popup3;
  t('no in-app route function at all: the link still opens the map app', !!pu3, 'no popup');

  t('no page errors', errs.length === 0, errs.join('|'));
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
