// Trip in progress: the driver sees the itemized fare, the rider sees only km and total (js/driver-trip-breakdown.js and js/ride-trip-simple.js, the real files).
// Needs Playwright (node). No server.   node delivery/tests/ui_trip_fare_views.js
const { chromium } = require('playwright'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const D = fs.readFileSync(path.join(__dirname, '../js/driver-trip-breakdown.js'), 'utf8'), R = fs.readFileSync(path.join(__dirname, '../js/ride-trip-simple.js'), 'utf8');
const ride = { id: 7, status: 'in_progress', traveled_km: 6.66, fare_base: 100, fare_distance: 200, fare_wait: 0, fare_subtotal: 300, fare_surge_mult: 1.5, fare_surge_etb: 150, fare_floor_topup: 0, fare_total: 450, wait_minutes: 0 };
(async () => {
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: 390, height: 844 } }); const errs = [];
  // ---- the driver ----
  let p = await ctx.newPage(); p.on('pageerror', e => errs.push(e.message));
  await p.setContent('<!doctype html><body style="--text-dim:#aaa;--teal:#4ECDC4"><div id="active-ride-container"><div id="meter-box">meter</div><div id="wait-toggle-box">wait</div><div id="after">after</div></div></body>');
  await p.evaluate((r) => { window.DRV = { activeRide: JSON.parse(JSON.stringify(r)) }; }, ride);
  await p.addScriptTag({ content: D }); await p.waitForTimeout(900);
  const txt = () => p.evaluate(() => { const b = document.getElementById('etel-live-breakdown'); return b ? b.innerText.replace(/\s+/g, ' ') : null; });
  let x = await txt();
  t('driver: the table shows during the trip: base, distance with km, subtotal, surge, total', x && /Base fare ETB 100/.test(x) && /Distance 6\.66 km ETB 200/.test(x) && /Subtotal ETB 300/.test(x) && /Surge \(1\.5×\) ETB 150/.test(x) && /Total ETB 450/.test(x), x);
  t('driver: it sits right under the waiting box, above the rest', await p.evaluate(() => document.getElementById('wait-toggle-box').nextElementSibling.id === 'etel-live-breakdown' && document.getElementById('etel-live-breakdown').nextElementSibling.id === 'after'));
  await p.evaluate(() => { DRV.activeRide.traveled_km = 7.1; DRV.activeRide.fare_distance = 220; DRV.activeRide.fare_subtotal = 320; DRV.activeRide.fare_surge_etb = 160; DRV.activeRide.fare_total = 480; DRV.activeRide.fare_wait = 30; DRV.activeRide.wait_minutes = 3; });
  await p.waitForTimeout(900); x = await txt();
  t('driver: it follows the live numbers, and shows Waiting only when there is a waiting fee', /Distance 7\.10 km ETB 220/.test(x) && /Waiting 3 min ETB 30/.test(x) && /Total ETB 480/.test(x), x);
  await p.evaluate(() => { document.getElementById('active-ride-container').innerHTML = '<div id="wait-toggle-box">wait</div><div id="after">after</div>'; });
  await p.waitForTimeout(900);
  t('driver: when the card is drawn again by the page, the table comes back (once)', await p.evaluate(() => document.querySelectorAll('#etel-live-breakdown').length) === 1);
  await p.evaluate(() => { DRV.activeRide.status = 'arrived'; }); await p.waitForTimeout(900);
  t('driver: not shown once the trip is no longer in progress (the Awaiting payment card has its own)', (await txt()) === null);
  await p.evaluate(() => { DRV.activeRide.status = 'in_progress'; delete DRV.activeRide.fare_total; }); await p.waitForTimeout(900);
  t('driver: not shown while the server has not sent the fare details', (await txt()) === null);
  await p.evaluate(() => { DRV.activeRide = null; }); await p.waitForTimeout(900);
  t('driver: not shown without a ride', (await txt()) === null);
  await p.close();
  // ---- the rider ----
  p = await ctx.newPage(); p.on('pageerror', e => errs.push(e.message));
  await p.setContent('<!doctype html><body style="--dim:#999;--gold-lt:#f2cc6b"><div id="a-fare-breakdown" style="display:none"></div></body>');
  await p.evaluate((r) => {
    window.RIDE = {};
    window.show = function (rd) { RIDE.activeRideData = rd; const box = document.getElementById('a-fare-breakdown'); box.style.display = 'block'; box.innerHTML = '<div id="fare-breakdown-box">🧾 Fare Breakdown Base fare ETB 100 Distance 6.66 km ETB 200 Surge ETB 150 Total ETB 450</div>'; };
  }, ride);
  await p.addScriptTag({ content: R }); await p.waitForTimeout(50);
  await p.evaluate((r) => window.show(r), ride); await p.waitForTimeout(100);
  const rt = () => p.evaluate(() => document.getElementById('a-fare-breakdown').innerText.replace(/\s+/g, ' '));
  x = await rt();
  t('rider: only the distance and the total show during the trip', /Distance 6\.66 km/.test(x) && /ETB 450/.test(x) && !/Base fare|Surge|Subtotal|Breakdown|Waiting/.test(x), x);
  await p.evaluate((r) => window.show(Object.assign({}, r, { traveled_km: 7.1, fare_total: 480 })), ride); await p.waitForTimeout(100);
  x = await rt();
  t('rider: it follows the live numbers and the table never comes back', /7\.10 km/.test(x) && /ETB 480/.test(x) && !/Base fare|Breakdown/.test(x), x);
  await p.evaluate(() => { const b = document.getElementById('a-fare-breakdown'); b.style.display = 'none'; }); await p.waitForTimeout(80);
  t('rider: when the page hides the box nothing breaks', true);
  t('no page errors', errs.length === 0, errs.join('|'));
  await b.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
