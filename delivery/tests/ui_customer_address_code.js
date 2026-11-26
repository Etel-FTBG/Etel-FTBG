// Customer app cart: the delivery address is a NAME + PLUS CODE, like the Ride system and the courier app ("Piassa, 2FQM+RHF, Hawassa, Ethiopia"),
// whether the customer picks a place from the search, taps the location button, or picks a saved address. Real page and files (js/customer.js,
// js/etel-plus-code.js), a fake API, a fake Google search box and a fake GPS.   NODE_PATH=/opt/node22/lib/node_modules node delivery/tests/ui_customer_address_code.js
const { chromium } = require('playwright'); const path = require('path'); const http = require('http'); const fs = require('fs');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.woff2': 'font/woff2' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });   // a tiny static server for the page's own files
let PAGE = '';
const VEHICLES = [{ tier_key: 'moto', vehicle_class: 'moto', label: 'Motorbike', icon: '\u{1F6F5}', multiplier: 1.0, eta_delta_min: 0, couriers: 2, online: 2, available: true, names: ['motorcycle'] }];
const SAVED = [{ id: 11, label: 'Home', address_line: 'Piassa, Hawassa', lat: '7.05', lng: '38.48', zone: 'Mehal Ketema', is_default: 0 }];
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); PAGE = 'http://127.0.0.1:' + srv.address().port + '/customer.html';
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block', geolocation: { latitude: 7.0504, longitude: 38.4762 }, permissions: ['geolocation'] });
  await ctx.addInitScript(() => {
    localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 5, name: 'Cust', phone: '0911000001', role: 'customer' }));
    // a fake Google search box: the test calls window.__pick(place) to stand in for the customer choosing a suggestion
    function LL(a, b) { this._a = a; this._b = b; this.lat = () => a; this.lng = () => b; }
    window.google = { maps: { LatLng: LL, LatLngBounds: function () { this.extend = () => this; }, event: { addListener() {}, trigger() {} }, Geocoder: function () { this.geocode = () => {}; },
      places: { Autocomplete: function (inp, o) { this.setBounds = () => {}; this.addListener = (ev, cb) => { window.__placeCb = cb; }; this.getPlace = () => window.__place; } } } };
    // like the real Google box: it writes the chosen suggestion's own text into the input, THEN reports the place
    window.__pick = (place) => { window.__place = place; const i = document.getElementById('co-address'); if (i) i.value = place.formatted_address || place.name; window.__placeCb && window.__placeCb(); };
  });
  const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message)); let sent = null;
  await p.route(/maps\.googleapis\.com|fonts\.(googleapis|gstatic)\.com/, r => r.abort());
  await p.route(/nominatim\.openstreetmap\.org/, r => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ address: { road: 'Bole Road', suburb: 'Piassa', city: 'Hawassa' }, display_name: 'x' }) }));
  await p.route(/boot\.php/, r => {
    const u = r.request().url(); let body = {}; try { body = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
    const act = (u.match(/action=([a-z_]+)/) || [])[1] || body.action;
    const items = [{ id: 1, name: 'Burger', price: '500.00', category: 'food', service_giver_id: 14, business_name: 'Shop', zone: 'Z', is_available: 1, stock_quantity: 50, is_online: 1, extras: [], gallery: [] }];
    let data = {};
    if (act === 'customer_get_items' || act === 'get_items') data = { items, count: 1 };
    else if (act === 'get_delivery_tiers') data = { tiers: [{ tier_key: 'moto', label: 'Delivery by moto', icon: 'x', multiplier: 1, eta_delta_min: 0 }], vehicles: VEHICLES };
    else if (act === 'customer_get_addresses') data = { addresses: SAVED };
    else if (act === 'customer_create_order') { sent = body; data = { order_id: 9, order_code: 'ETD-9', fare_total: 600 }; }
    r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
  });
  await p.goto(PAGE); await p.waitForTimeout(2500);
  await p.evaluate(() => { cart.push({ id: 1, item_id: 1, name: 'Burger', price: 500, quantity: 1, extras: [], xkey: '' }); try { toggleCart(true); } catch (e) { try { openCart(); } catch (e2) {} } renderCartDrawer(); });
  await p.waitForTimeout(1200);
  const val = () => p.evaluate(() => (document.getElementById('co-address') || {}).value);
  const CODE = /[23456789CFGHJMPQRVWX]{4}\+[23456789CFGHJMPQRVWX]{2,3}, Hawassa, Ethiopia$/;
  t('the shared helper is loaded', await p.evaluate(() => typeof window.EtelPlusCode === 'object'));
  const want = await p.evaluate(() => window.EtelPlusCode.label(7.0504, 38.4762));

  // 1. the customer picks a suggestion from the search box
  await p.evaluate(() => window.__pick({ name: 'Piassa', formatted_address: 'Piassa, Hawassa, Ethiopia', geometry: { location: { lat: () => 7.0504, lng: () => 38.4762 } } }));
  await p.waitForTimeout(400);
  let v = await val();
  t('search: the name and the plus code are in the box', v === 'Piassa, ' + want, v);
  t('search: it looks like 2FQM+RHF, Hawassa, Ethiopia', CODE.test(v), v);
  await p.evaluate(() => window.__pick({ name: 'H-Town Burger', formatted_address: 'Mehal Ketema, Hawassa, Ethiopia', geometry: { location: { lat: () => 7.0504, lng: () => 38.4762 } } }));
  await p.waitForTimeout(400);
  v = await val();
  t('search: a business name is kept in front of the code', v === 'H-Town Burger, ' + want, v);
  await p.evaluate(() => window.__pick({ name: 'Unnamed Road', formatted_address: 'Unnamed Road, Hawassa, Ethiopia', geometry: { location: { lat: () => 7.0504, lng: () => 38.4762 } } }));
  await p.waitForTimeout(400);
  v = await val();
  t('search: "Unnamed Road" is dropped, only the code is shown', v === want, v);

  // 2. the location button
  await p.evaluate(() => { document.getElementById('co-address').value = ''; });
  await p.evaluate(() => getMyLocation()); await p.waitForTimeout(1500);
  v = await val();
  t('location button: street, area and the plus code', /^Bole Road, Piassa, /.test(v) && CODE.test(v), v);
  t('location button: no doubled town', !/Hawassa, .*Hawassa, Ethiopia/.test(v), v);

  // 3. a saved address
  await p.evaluate(() => { _deliveryAddr = ''; _deliveryLat = null; _deliveryLng = null; selectedZone = ''; document.getElementById('co-address').value = ''; renderCartDrawer(); });
  await p.waitForTimeout(800);
  await p.evaluate(() => useSavedPlace(11)); await p.waitForTimeout(500);
  v = await val();
  const wantSaved = await p.evaluate(() => window.EtelPlusCode.label(7.05, 38.48));
  t('saved address: the name and the plus code', v === 'Piassa, ' + wantSaved, v);
  const cart1 = await p.evaluate(() => document.getElementById('cart-checkout').innerText.replace(/\s+/g, ' '));
  t('saved address: it is still known as saved (no "Save this address" button)', !/Save this address/i.test(cart1) && !!(await p.evaluate(() => document.querySelector('.sp-chip.on'))), cart1.slice(0, 300));

  // 4. a typed address with no map point stays as typed (nothing to work out a code from)
  await p.evaluate(() => { _deliveryLat = null; _deliveryLng = null; });
  t('typed text without a point is never changed', await p.evaluate(() => _addrWithCode('Near the church', null, null)) === 'Near the church');
  t('a text that already has a code is never changed twice', await p.evaluate(() => _addrWithCode('Piassa, 2FQM+RHF, Hawassa, Ethiopia', 7.05, 38.48)) === 'Piassa, 2FQM+RHF, Hawassa, Ethiopia');

  // 5. the order carries the address with the code
  await p.evaluate(() => { _deliveryLat = 7.0504; _deliveryLng = 38.4762; selectedZone = 'Tabor'; document.getElementById('co-address').value = 'Piassa, ' + window.EtelPlusCode.label(7.0504, 38.4762); const n = document.getElementById('co-name'); if (n) n.value = 'Emu'; const ph = document.getElementById('co-phone'); if (ph) ph.value = '0911000002'; });
  await p.evaluate(() => { try { handleCheckout(); } catch (e) {} }); await p.waitForTimeout(1200);
  t('the order note carries the name and the code', !!sent && /^Address: Piassa, [23456789CFGHJMPQRVWX]{4}\+[23456789CFGHJMPQRVWX]{2,3}, Hawassa, Ethiopia/.test(sent.notes || ''), JSON.stringify(sent && sent.notes));
  t('the order carries the map point', !!sent && Math.abs(Number(sent.delivery_lat) - 7.0504) < 1e-6, JSON.stringify(sent && [sent.delivery_lat, sent.delivery_lng]));

  t('no page errors', errs.length === 0, errs.join('|'));
  await p.evaluate(() => { [].slice.call(document.querySelectorAll('button')).forEach(x => { if (/^\s*Not Now\s*$/.test(x.textContent)) x.click(); }); });
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
