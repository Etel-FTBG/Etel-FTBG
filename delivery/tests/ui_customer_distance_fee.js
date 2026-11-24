// Customer app cart: the delivery fee by distance for each vehicle, and the free service charge (js/customer.js, the real page and file).
// Needs Playwright (node). No server: the page is served from disk and the API is a fake.   node delivery/tests/ui_customer_distance_fee.js
const { chromium } = require('playwright'); const path = require('path'); const http = require('http'); const fs = require('fs');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.woff2': 'font/woff2' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });
const VEHICLES = [
  { tier_key: 'bike', vehicle_class: 'bike', label: 'Bicycle', icon: '\u{1F6B2}', multiplier: 1.0, eta_delta_min: 0, couriers: 1, online: 1, available: true },
  { tier_key: 'moto', vehicle_class: 'moto', label: 'Motorbike', icon: '\u{1F6F5}', multiplier: 1.0, eta_delta_min: 0, couriers: 6, online: 2, available: true },
  { tier_key: 'car', vehicle_class: 'car', label: 'Car', icon: '\u{1F697}', multiplier: 1.12, eta_delta_min: -2, couriers: 1, online: 1, available: true },
  { tier_key: 'van', vehicle_class: 'van', label: 'Van / pickup', icon: '\u{1F690}', multiplier: 1.6, eta_delta_min: 5, couriers: 0, online: 0, available: false }];
const QUOTE_FAR = { mode: 'distance', service_pct: 0, km: 3.9, too_far: 0, vehicles: { bike: { fee: 105, km: 3.9 }, moto: { fee: 125, km: 3.9 }, car: { fee: 190, km: 3.9 }, van: { fee: 275, km: 3.9 } } };
const QUOTE_NEAR = { mode: 'distance', service_pct: 0, km: 1.3, too_far: 0, vehicles: { bike: { fee: 65, km: 1.3 }, moto: { fee: 80, km: 1.3 }, car: { fee: 120, km: 1.3 }, van: { fee: 175, km: 1.3 } } };
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const PAGE = 'http://127.0.0.1:' + srv.address().port + '/customer.html';
  const b = await chromium.launch();
  const MODES = [
    { n: 'by distance', fees: { service_pct: 0, distance_on: true }, quote: q => q.lat > 7.08 ? QUOTE_FAR : QUOTE_NEAR },
    { n: 'service charge on', fees: { service_pct: 5, distance_on: true }, quote: () => Object.assign({}, QUOTE_FAR, { service_pct: 5 }) },
    { n: 'shop has no pin (zone fee)', fees: { service_pct: 0, distance_on: true }, quote: () => ({ mode: 'zone', service_pct: 0, vehicles: [] }) },
    { n: 'too far', fees: { service_pct: 0, distance_on: true }, quote: () => ({ mode: 'distance', service_pct: 0, too_far: 2, vehicles: [] }) },
    { n: 'old server (no fees info)', fees: null, quote: () => QUOTE_FAR },
    { n: 'switched off', fees: { service_pct: 0, distance_on: false }, quote: () => QUOTE_FAR }];
  for (const mode of MODES) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    await ctx.addInitScript(() => { localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 5, name: 'Cust', phone: '0911000001', role: 'customer' })); });
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message)); const quotes = [];
    await p.route(/boot\.php/, r => {
      const u = r.request().url(); let body = {}; try { body = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
      const act = (u.match(/action=([a-z_]+)/) || [])[1] || body.action;
      const items = [{ id: 1, name: 'Burger', price: '600.00', category: 'food', service_giver_id: 14, business_name: 'Shop', zone: 'Z', is_available: 1, stock_quantity: 50, is_online: 1, extras: [], gallery: [] }];
      let data = {};
      if (act === 'customer_get_items' || act === 'get_items') data = { items, count: 1 };
      else if (act === 'get_delivery_tiers') { data = { tiers: [{ tier_key: 'moto', label: 'Delivery by moto', icon: 'x', multiplier: 1, eta_delta_min: 0 }], vehicles: VEHICLES }; if (mode.fees) data.fees = mode.fees; }
      else if (act === 'delivery_fee_quote') { quotes.push(body); data = mode.quote(body); }
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
    });
    await p.goto(PAGE); await p.waitForTimeout(2200);
    await p.evaluate(() => { cart.push({ id: 1, item_id: 1, name: 'Burger', price: 600, quantity: 2, extras: [], xkey: '' }); try { toggleCart(true); } catch (e) { try { openCart(); } catch (e2) {} } renderCartDrawer(); });
    await p.waitForTimeout(700);
    const read = () => p.evaluate(() => ({ co: (document.getElementById('cart-checkout') || document.body).innerText.replace(/\s+/g, ' '), total: (document.getElementById('cart-total') || document.body).innerText.replace(/\s+/g, ' ') }));
    const n = mode.n;
    if (mode.n === 'by distance') {
      await p.evaluate(() => { selectedZone = 'Tabor'; _deliveryLat = 7.087; _deliveryLng = 38.476; selectTier('bike'); }); await p.waitForTimeout(700);
      let s = await read();
      t(n + ': the bicycle button shows the bicycle fee (ETB 105), not the zone fee', /Bicycle[^E]*\d+ min . ETB 105/.test(s.co), s.co.slice(0, 700));
      t(n + ': each vehicle shows its own fee (motorbike 125, car 190, van 275)', /ETB 125/.test(s.co) && /ETB 190/.test(s.co) && /ETB 275/.test(s.co), s.co.slice(0, 900));
      t(n + ': the delivery fee row is the bicycle fee', /Delivery Fee[^E]*ETB 105\.00/.test(s.total), s.total);
      t(n + ': the service charge says Free (no 5% line)', /Service Charge Free/.test(s.total) && !/\(5%\)/.test(s.total), s.total);
      t(n + ': the total is items + the fee (1200 + 105 = 1305), nothing else', /Total ETB 1305\.00/.test(s.total), s.total);
      await p.evaluate(() => { [].slice.call(document.querySelectorAll('button')).forEach(x => { if (/^\s*Not Now\s*$/.test(x.textContent)) x.click(); }); const d = document.querySelector('#cart-checkout label'); if (d) d.scrollIntoView(); }); await p.waitForTimeout(250);
      await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/cart_distance_fee.png' });
      await p.evaluate(() => { const d = document.getElementById('cart-items'); if (d) d.scrollIntoView(); }); await p.waitForTimeout(150);
      await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/cart_distance_fee_top.png' });
      t(n + ': the shop and the pin were sent for the quote', quotes.length >= 1 && quotes[0].item_id === 1 && Math.abs(quotes[0].lat - 7.087) < 1e-9, JSON.stringify(quotes));
      await p.evaluate(() => selectTier('car')); await p.waitForTimeout(300); s = await read();
      t(n + ': choosing the car moves the delivery fee to the car fee (190)', /Delivery Fee[^E]*ETB 190\.00/.test(s.total) && /Total ETB 1390\.00/.test(s.total), s.total);
      const before = quotes.length; await p.evaluate(() => { renderCartDrawer(); renderCartDrawer(); selectTier('moto'); }); await p.waitForTimeout(400);
      t(n + ': drawing the cart again does not ask the server again for the same pin', quotes.length === before, before + ' -> ' + quotes.length);
      await p.evaluate(() => { _deliveryLat = 7.0690; _deliveryLng = 38.476; renderCartDrawer(); }); await p.waitForTimeout(700); s = await read();
      t(n + ': a new pin asks again and the fee follows it (motorbike 80)', quotes.length === before + 1 && /Delivery Fee[^E]*ETB 80\.00/.test(s.total), quotes.length + ' ' + s.total);
      await p.evaluate(() => { _deliveryLat = 7.087; _deliveryLng = 38.476; _dfeeQuote = null; _dfeeKey = ''; renderCartDrawer(); });
      await p.evaluate(() => { _deliveryLat = null; _deliveryLng = null; renderCartDrawer(); });
      s = await read();
      t(n + ': with no pin the zone fee is shown (as before), never the old pin\'s quote', !/ETB 125/.test(s.co) || /Delivery Fee[^E]*ETB 50\.00/.test(s.total), s.total);
    } else if (mode.n === 'service charge on') {
      await p.evaluate(() => { selectedZone = 'Tabor'; _deliveryLat = 7.087; _deliveryLng = 38.476; selectTier('bike'); }); await p.waitForTimeout(700);
      const s = await read();
      t(n + ': the 5% service charge comes back from the server and is shown (ETB 60.00)', /Service Charge \(5%\) ETB 60\.00/.test(s.total) && /Total ETB 1365\.00/.test(s.total), s.total);
    } else if (mode.n === 'shop has no pin (zone fee)') {
      await p.evaluate(() => { selectedZone = 'Tabor'; _deliveryLat = 7.087; _deliveryLng = 38.476; selectTier('moto'); }); await p.waitForTimeout(700);
      const s = await read();
      t(n + ': the zone fee is used (Tabor 50)', /Delivery Fee[^E]*ETB 50\.00/.test(s.total), s.total);
    } else if (mode.n === 'too far') {
      await p.evaluate(() => { selectedZone = 'Tabor'; _deliveryLat = 7.087; _deliveryLng = 38.476; selectTier('moto'); }); await p.waitForTimeout(700);
      const s = await read();
      t(n + ': the customer is told the shop is too far (limit 2 km)', /too far to deliver to you \(the limit is 2 km\)/.test(s.co), s.co.slice(0, 600));
    } else {
      await p.evaluate(() => { selectedZone = 'Tabor'; _deliveryLat = 7.087; _deliveryLng = 38.476; selectTier('moto'); }); await p.waitForTimeout(700);
      const s = await read();
      t(n + ': the zone fee is used and no quote is asked for', /Delivery Fee[^E]*ETB 50\.00/.test(s.total) && quotes.length === 0, quotes.length + ' ' + s.total);
      if (mode.n === 'old server (no fees info)') t(n + ': the service charge line still draws (free)', /Service Charge/.test(s.total), s.total);
    }
    t(n + ': no page errors', errs.length === 0, errs.join('|'));
    await p.evaluate(() => { [].slice.call(document.querySelectorAll('button')).forEach(x => { if (/^\s*Not Now\s*$/.test(x.textContent)) x.click(); }); const d = document.getElementById('cart-checkout'); if (d) d.scrollIntoView(); }); await p.waitForTimeout(200);
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
