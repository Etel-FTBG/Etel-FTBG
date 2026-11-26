// Courier app (courier/index.html): the pickup and the dropoff show a REAL ADDRESS (plus code + town, like the Ride system) and the km,
// on the Jobs tab (before accepting) AND on the Active tab (after accepting). Real page and files, mocked API.
//   node delivery/tests/ui_courier_job_address.js   (needs: NODE_PATH=/opt/node22/lib/node_modules)
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.webp': 'image/webp' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });
const job = (id, code) => ({ id: id, order_code: code, status: 'pending', assignment_status: 'accepted', from_zone: 'Mehal Ketema', to_zone: 'University', recipient_name: 'Emu', recipient_phone: '09****', fare_total: 100, delivery_fee: 105, package_type: 'food', placed_at: '2026-11-24 10:00:00', payment_method: 'cash' });
const active = (id, code, status) => ({ id: id, order_code: code, status: status, from_zone: 'Mehal Ketema', to_zone: 'Addis Ketema', sg_business_name: 'Admin test shop', sg_lat: '7.05', sg_lng: '38.48', delivery_lat: '7.06', delivery_lng: '38.49', recipient_name: 'Emu', recipient_phone: '0936533606', recipient_address: 'Piassa, Hawassa', notes: 'Address: Piassa, Hawassa', fare_total: 100, delivery_fee: 66, payment_method: 'cash' });
const GEO = {
  7: { pickup: { lat: 7.05, lng: 38.48 }, drop: { lat: 7.06, lng: 38.49 }, pickup_label: 'Geo Kitchen', pickup_address: 'Piassa', drop_label: 'University', pickup_km: 2.3, drop_km: 3.1 },
  21: { pickup: { lat: 7.05, lng: 38.48 }, drop: { lat: 7.06, lng: 38.49 }, pickup_label: 'Admin test shop', pickup_address: '', drop_label: 'Addis Ketema', pickup_km: 1.7, drop_km: 2.4 },
  22: { pickup: { lat: 7.05, lng: 38.48 }, drop: { lat: 7.06, lng: 38.49 }, pickup_label: 'Admin test shop', pickup_address: '', drop_label: 'Addis Ketema', pickup_km: 1.7, drop_km: 2.4 }
};
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block', geolocation: { latitude: 7.04, longitude: 38.47 }, permissions: ['geolocation'] });
  await ctx.addInitScript(() => { try { localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 9, name: 'Cou', phone: '0911000001', role: 'courier' })); localStorage.setItem('etel_lang', 'en'); } catch (e) {} });
  const p = await ctx.newPage(); const errs = [];
  p.on('pageerror', e => errs.push(e.message));
  await p.route(/cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net|maps\.googleapis\.com|fonts\.(googleapis|gstatic)\.com/, r => r.abort());
  let myOrders = [active(21, 'ETD-ACT001', 'assigned')];
  await p.route(/boot\.php/, r => {
    let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
    const u = r.request().url(); const act = d.action || ((u.match(/action=([a-z_]+)/) || [])[1]); let data = {};
    if (act === 'get_service_areas') data = { areas: [], enforced: false };
    else if (act === 'order_chat_inbox') data = { chats: [], unread_total: 0 };
    else if (act === 'courier_get_available_orders') data = { orders: [job(7, 'ETD-AAA111')] };
    else if (act === 'courier_job_geo') data = { jobs: GEO };
    else if (act === 'courier_get_profile') data = { courier: { id: 22, status: 'online', vehicle_type: 'moto', approval_status: 'approved', active: 1 }, profile: { id: 22, status: 'online', vehicle_type: 'moto', approval_status: 'approved', active: 1 } };
    else if (act === 'courier_get_my_orders') data = { orders: myOrders };
    else if (act === 'courier_earnings_summary') data = { today: { earned: 0, deliveries: 0, goal: 1000 }, week: { earned: 0 }, streak_days: 0, goal: 1000 };
    else if (act === 'courier_get_statement') data = { week_start: '2026-11-23', week_end: '2026-11-29', totals: { deliveries: 0, pay: 0, tips: 0, earned: 0 }, days: [], orders: [], prev_week: '2026-11-16', next_week: null };
    r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
  });
  await p.goto(HOST + '/delivery/courier/index.html', { waitUntil: 'load' }); await p.waitForTimeout(4500);

  // the plus-code maths: Google's own test vector (20.3701125, 2.782234375 = 7FG49QCJ+2VX) and a Hawassa point
  const plus = await p.evaluate(() => ({ f: typeof window.__etelJobNavPlus, a: window.__etelJobNavPlus && window.__etelJobNavPlus(20.3701125, 2.782234375), h: window.__etelJobNavPlus && window.__etelJobNavPlus(7.05, 38.48) }));
  t('the plus-code function is there', plus.f === 'function', JSON.stringify(plus));
  t('the plus code is right for the official test point', plus.a === '7FG49QCJ+2VX', plus.a);
  t('a Hawassa point gets a code that starts 6GVQ... style (8 letters + 3)', /^[23456789CFGHJMPQRVWX]{8}\+[23456789CFGHJMPQRVWX]{3}$/.test(plus.h || ''), plus.h);
  const short = (plus.h || '').slice(4);

  // Jobs tab
  const j1 = p.locator('#jobs-content .job-card').nth(0); const jt = await j1.innerText();
  t('jobs: the pickup shows the plus-code address with Hawassa, Ethiopia', jt.indexOf(short + ', Hawassa, Ethiopia') >= 0, jt);
  t('jobs: the dropoff has its own address too (two address lines)', (await j1.locator('[data-cjn-addr]').count()) === 2);
  t('jobs: the km are still there (2.3 from you, 3.1 from pickup)', /2\.3/.test(jt) && /3\.1/.test(jt));
  t('jobs: the shop name is still there', /Geo Kitchen/.test(jt));
  t('jobs: the shop address text (Piassa) is shown under the name', /Piassa/.test(jt));
  t('jobs: two Navigate buttons', (await j1.locator('a.cjn-go').count()) === 2);

  // Active tab, order assigned (going to the shop)
  await p.click('button.tab-btn:nth-child(2)'); await p.waitForTimeout(2500);
  const a1 = p.locator('#active-content .job-card.accepted').nth(0); const at = await a1.innerText();
  t('active: one box with Pickup and Dropoff', (await a1.locator('.cjn-box').count()) === 1, at);
  t('active: pickup shows the real address', at.indexOf(short + ', Hawassa, Ethiopia') >= 0, at);
  t('active: two address lines', (await a1.locator('[data-cjn-addr]').count()) === 2);
  t('active: pickup km from you (1.7)', /1\.7\s*km from you/.test(at), at);
  t('active: dropoff km from pickup (2.4)', /2\.4\s*km from pickup/.test(at), at);
  t('active: whole trip added up (4.1)', /4\.1/.test(at), at);
  t('active: no second Navigate button in the box (the card has its own)', (await a1.locator('.cjn-box a.cjn-go').count()) === 0);
  t('active: the card\'s own NAVIGATE button is still there', /NAVIGATE/i.test(at));
  t('active: the two plain Pickup/Dropoff lines are hidden (no repeat)', (await a1.locator('.job-info > .job-address:visible').count()) === 1, await a1.locator('.job-info > .job-address:visible').count());
  t('active: the customer\'s typed address (Piassa, Hawassa) is kept', /Piassa, Hawassa/.test(at), at);
  t('active: the customer name and phone stay where they were', /Emu/.test(at) && /0936533606/.test(at));

  // the order is picked up: only the way to the customer matters (km from the courier's own position)
  myOrders = [active(22, 'ETD-ACT002', 'picked_up')];
  await p.evaluate(() => { window.renderActiveOrders(window._activeOrders = [{ id: 22, order_code: 'ETD-ACT002', status: 'picked_up', from_zone: 'Mehal Ketema', to_zone: 'Addis Ketema', sg_business_name: 'Admin test shop', sg_lat: '7.05', sg_lng: '38.48', delivery_lat: '7.06', delivery_lng: '38.49', recipient_name: 'Emu', recipient_phone: '0936533606', notes: '', fare_total: 100, delivery_fee: 66, payment_method: 'cash' }]); });
  await p.waitForTimeout(2500);
  const a2 = p.locator('#active-content .job-card.accepted').nth(0); const at2 = await a2.innerText();
  t('picked up: the dropoff shows the km from the courier\'s position', /\d+\.\d\s*km from you/.test(at2) && !/km from pickup/.test(at2), at2);
  t('picked up: the pickup km is no longer shown', !/1\.7/.test(at2), at2);
  t('picked up: the dropoff address is shown', at2.indexOf(((await p.evaluate(() => window.__etelJobNavPlus(7.06, 38.49))) || '').slice(4) + ', Hawassa, Ethiopia') >= 0, at2);

  const over = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1); t('no sideways scroll on the phone', !over);
  await a1.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/courier_job_address_active.png' }).catch(() => {});
  t('no page errors', errs.length === 0, errs.join('|'));
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
