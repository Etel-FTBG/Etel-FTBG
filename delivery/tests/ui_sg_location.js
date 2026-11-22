// Service giver Settings > Shop location (js/servicegiver-location.js, the real file). Needs Playwright (node). No server: apiCall, Google Maps and the GPS are fakes.
//   node delivery/tests/ui_sg_location.js
const { chromium } = require('playwright'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const SRC = fs.readFileSync(path.join(__dirname, '../js/servicegiver-location.js'), 'utf8');
(async () => {
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, permissions: [], geolocation: { latitude: 7.0612, longitude: 38.4777 } });
  const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
  await p.route('http://etel.test/**', r => r.fulfill({ contentType: 'text/html', body: '<!doctype html><body><div id="page-settings"><div class="card" id="sg-papers-card">papers</div></div></body>' }));
  await p.goto('http://etel.test/');
  const setup = (opts) => p.evaluate((o) => {
    window.CALLS = []; window.CONFIRMS = []; window.NOTES = [];
    window.ETEL = { api: 'http://etel.test/api/boot.php' };
    window.showNotification = (m, k) => window.NOTES.push(m);
    window.confirm = (m) => { window.CONFIRMS.push(m); return o.confirm !== false; };
    window.SERVER = { lat: o.lat, lng: o.lng, fail: false };
    window.apiCall = (action, data) => { window.CALLS.push({ action, data }); if (action === 'sg_profile') return Promise.resolve({ service_giver: { id: 14, lat: window.SERVER.lat, lng: window.SERVER.lng } }); if (action === 'sg_update_profile') return window.SERVER.fail ? Promise.resolve(null) : (window.SERVER.lat = data.lat, window.SERVER.lng = data.lng, Promise.resolve({ ok: 1 })); return Promise.resolve(null); };
    window.fetch = (u) => Promise.resolve(new Response(JSON.stringify({ success: true, data: { google_maps_api_key: o.maps ? 'K' : '' } }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    if (o.maps) {   // a tiny fake Google Maps: a map you can click, and a draggable marker
      window.google = { maps: { Map: function (el, opt) { this.opt = opt; this.l = {}; window.MAP = this; this.setCenter = function (c) { this.center = c; }; this.setZoom = function (z) { this.zoom = z; }; this.addListener = (ev, fn) => { this.l[ev] = fn; }; },
        Marker: function (opt) { this.p = opt.position; window.MARK = this; this.setPosition = (q) => { this.p = q; }; this.getPosition = () => ({ lat: () => this.p.lat, lng: () => this.p.lng }); this.addListener = (ev, fn) => { this.l = this.l || {}; this.l[ev] = fn; }; } } };
    }
  }, opts);
  const boot = async () => { await p.addScriptTag({ content: SRC }); await p.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded'))); await p.waitForTimeout(2100); };

  // ---- a shop with no location yet ----
  await setup({ maps: true, lat: null, lng: null }); await boot();
  const val = (id) => p.evaluate((id) => document.getElementById(id).value, id);
  const txt = (id) => p.evaluate((id) => document.getElementById(id).textContent, id);
  t('the card appears right under the papers card', await p.evaluate(() => document.getElementById('sg-papers-card').nextElementSibling.id === 'sg-location-card'));
  t('no location yet: the card says customers cannot see the shop (in red)', /No location yet/.test(await txt('sgl-status')), await txt('sgl-status'));
  t('the map opens on Hawassa', await p.evaluate(() => Math.abs(MAP.opt.center.lat - 7.05) < 0.01 && Math.abs(MAP.opt.center.lng - 38.485) < 0.01));
  await p.evaluate(() => MAP.l.click({ latLng: { lat: () => 7.0471, lng: () => 38.4902 } }));
  t('tapping the map drops a pin and fills the two numbers', await val('sgl-lat') === '7.047100' && await val('sgl-lng') === '38.490200' && await p.evaluate(() => !!window.MARK && MARK.p.lat === 7.0471));
  t('... and the status shows the spot', /7\.04710, 38\.49020/.test(await txt('sgl-status')));
  await p.evaluate(() => { MARK.p = { lat: 7.0499, lng: 38.4911 }; MARK.l.dragend(); });
  t('dragging the pin updates the numbers', await val('sgl-lat') === '7.049900' && await val('sgl-lng') === '38.491100');
  await p.fill('#sgl-lat', '7,0512'); await p.fill('#sgl-lng', '38.4850'); await p.dispatchEvent('#sgl-lng', 'change');
  t('typing the numbers (a comma works too) moves the pin', await p.evaluate(() => MARK.p.lat === 7.0512 && MARK.p.lng === 38.485 && MAP.zoom === 17));
  await p.click('#sgl-save'); await p.waitForTimeout(80);
  let call = await p.evaluate(() => CALLS.filter(c => c.action === 'sg_update_profile').pop());
  t('Save sends the latitude and longitude as numbers', call && call.data.lat === 7.0512 && call.data.lng === 38.485 && typeof call.data.lat === 'number', JSON.stringify(call));
  t('... and says so, in the card and as a notice', /Location saved/.test(await txt('sgl-msg')) && await p.evaluate(() => NOTES.length === 1));
  t('... no "outside Ethiopia" question for a spot in Hawassa', await p.evaluate(() => CONFIRMS.length === 0));

  // ---- bad input ----
  await p.fill('#sgl-lat', 'abc'); await p.dispatchEvent('#sgl-lat', 'change');
  t('letters in a number box: a plain message, the pin does not move', /numbers like/.test(await txt('sgl-msg')) && await p.evaluate(() => MARK.p.lat === 7.0512));
  const before = await p.evaluate(() => CALLS.filter(c => c.action === 'sg_update_profile').length);
  await p.fill('#sgl-lat', '95'); await p.fill('#sgl-lng', '38'); await p.click('#sgl-save');
  t('a latitude over 90 is refused before it is sent', await p.evaluate(() => CALLS.filter(c => c.action === 'sg_update_profile').length) === before && /Drop a pin/.test(await txt('sgl-msg')));
  await p.fill('#sgl-lat', '0'); await p.fill('#sgl-lng', '0'); await p.click('#sgl-save');
    t('0, 0 is refused (it means "nothing")', await p.evaluate(() => CALLS.filter(c => c.action === 'sg_update_profile').length) === before);
  await p.evaluate(() => { window.confirm = (m) => { CONFIRMS.push(m); return false; }; }); await p.fill('#sgl-lat', '51.5'); await p.fill('#sgl-lng', '-0.12'); await p.click('#sgl-save'); await p.waitForTimeout(60);
  t('a spot outside Ethiopia asks first; "no" sends nothing', await p.evaluate(() => CONFIRMS.length === 1 && /not in Ethiopia/.test(CONFIRMS[0])) && await p.evaluate(() => CALLS.filter(c => c.action === 'sg_update_profile').length) === before);
  await p.evaluate(() => { window.confirm = (m) => { CONFIRMS.push(m); return true; }; }); await p.click('#sgl-save'); await p.waitForTimeout(60);
  t('... "yes" sends it', await p.evaluate(() => CALLS.filter(c => c.action === 'sg_update_profile').length) === before + 1);

  // ---- the phone's location ----
  await p.evaluate(() => { navigator.geolocation.getCurrentPosition = (ok) => ok({ coords: { latitude: 7.0612, longitude: 38.4777 } }); });
  await p.click('#sgl-gps'); await p.waitForTimeout(60);
  t('"Use my current location" fills the numbers and moves the pin (nothing is saved until Save)', await val('sgl-lat') === '7.061200' && await val('sgl-lng') === '38.477700' && await p.evaluate(() => MARK.p.lat === 7.0612));
  await p.evaluate(() => { navigator.geolocation.getCurrentPosition = (ok, bad) => bad({ code: 1 }); });
  await p.click('#sgl-gps'); await p.waitForTimeout(60);
  t('location refused by the phone: a plain message', /Could not get your location/.test(await txt('sgl-msg')));

  // ---- a failed save ----
  await p.evaluate(() => { window.confirm = () => true; SERVER.fail = true; });
  await p.fill('#sgl-lat', '7.05'); await p.fill('#sgl-lng', '38.48'); const n0 = await p.evaluate(() => NOTES.length); await p.click('#sgl-save'); await p.waitForTimeout(60);
  t('the server says no: no false "saved" message, the button works again', !(/Location saved/.test(await txt('sgl-msg'))) && await p.evaluate((n0) => !document.getElementById('sgl-save').disabled && NOTES.length === n0, n0));

  // ---- a shop that already has a pin; and no Google Maps ----
  await p.reload(); await setup({ maps: false, lat: '7.040000', lng: '38.480000' }); await boot();
  t('an existing pin shows in the boxes and the status', await val('sgl-lat') === '7.040000' && /7\.04000, 38\.48000/.test(await txt('sgl-status')));
  t('without Google Maps the map box is hidden, the typed fields and GPS button stay', await p.evaluate(() => document.getElementById('sgl-map').style.display === 'none' && !!document.getElementById('sgl-gps') && !!document.getElementById('sgl-lat')));
  await p.fill('#sgl-lat', '7.0455'); await p.fill('#sgl-lng', '38.4790'); await p.click('#sgl-save'); await p.waitForTimeout(60);
  t('... and saving still works', await p.evaluate(() => SERVER.lat === 7.0455 && SERVER.lng === 38.479));
  t('no page errors', errs.length === 0, errs.join('|'));
  await b.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
