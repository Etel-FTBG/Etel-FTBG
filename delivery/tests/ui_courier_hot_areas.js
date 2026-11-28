// Courier map: hot areas as gold hexagons (js/courier-demand.js) and the Traffic button (js/etel-map-traffic.js, data-app="courier").
// The courier app keeps its map in a script-level `let map` and its state in `let COURIER_IS_ONLINE`: the test declares them the same way (a classic script with top-level let).
// A fake Google Maps, api courier_get_demand mocked, the REAL files.   Needs Playwright (node).   node delivery/tests/ui_courier_hot_areas.js
const { chromium } = require('playwright'); const path = require('path'); const fs = require('fs');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const DEM = fs.readFileSync(path.join(__dirname, '..', 'js', 'courier-demand.js'), 'utf8');
const TRA = fs.readFileSync(path.join(__dirname, '..', 'js', 'etel-map-traffic.js'), 'utf8');
const AREAS = [{ lat: 7.06, lng: 38.47, requests: 4, waiting: 2, idle_drivers: 0, score: 8, level: 'high', distance_km: 1.4, q: 0, r: 0, heat: 1 },
  { lat: 7.07, lng: 38.48, requests: 2, waiting: 0, idle_drivers: 1, score: 1, level: 'low', distance_km: 2.2, q: 1, r: 0, heat: 0.17 },
  { lat: 7.05, lng: 38.49, requests: 3, waiting: 0, idle_drivers: 1, score: 1.5, level: 'low', distance_km: 3.1, q: 0, r: 1, heat: 0.5 }];
const FAKE = () => {
  const rec = { polys: [], pushed: [], layers: [], pans: [], zoom: [] };
  class Polygon { constructor(o) { this.o = o; this.map = o.map; rec.polys.push(this); } setMap(m) { this.map = m; } }
  class TrafficLayer { constructor() { this.map = null; rec.layers.push(this); } setMap(m) { this.map = m; } }
  const arr = pos => ({ push: el => { rec.pushed.push({ pos, el }); document.body.appendChild(el); } });
  window.__rec = rec;
  window.google = { maps: { Polygon, TrafficLayer, ControlPosition: { LEFT_TOP: 5, RIGHT_CENTER: 15, TOP_RIGHT: 3 } } };
  window.__mapObj = () => ({ controls: { 5: arr(5), 15: arr(15), 3: arr(3) }, id: 'MAP', panTo: p => rec.pans.push(p), getZoom: () => 13, setZoom: z => rec.zoom.push(z) });
};
const lum = hex => { const n = parseInt(hex.slice(1), 16); return 0.2126 * (n >> 16) + 0.7152 * ((n >> 8) & 255) + 0.0722 * (n & 255); };
async function open(b, lang, prefs) {
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  await ctx.addInitScript(([l, pr]) => { try { localStorage.setItem('etel_lang', l); localStorage.setItem('etel_token', 'T'); Object.keys(pr).forEach(k => localStorage.setItem(k, pr[k])); } catch (e) {} }, [lang, prefs || {}]);
  await ctx.addInitScript(FAKE);
  const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
  p.calls = [];
  await p.route('http://courier.test/delivery/api/boot.php', r => { p.calls.push(JSON.parse(r.request().postData() || '{}')); r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { areas: AREAS, hexes: AREAS, size_m: 700, surge_now: null, window_min: 180 } }) }); });
  await p.route('http://courier.test/delivery/courier/', r => r.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body></body></html>' }));
  await p.goto('http://courier.test/delivery/courier/');
  // like the courier bundle: script-level lets
  await p.addScriptTag({ content: 'let map; let COURIER_IS_ONLINE = false; let courierLat = 7.0621, courierLng = 38.4761;' });
  await p.evaluate(([dem, tra]) => { const a = document.createElement('script'); a.textContent = dem; document.body.appendChild(a); const b = document.createElement('script'); b.setAttribute('data-app', 'courier'); b.textContent = tra; document.body.appendChild(b); }, [DEM, TRA]);
  p.errs = errs; p.ctx = ctx; return p;
}
(async () => {
  const b = await chromium.launch();
  for (const lang of ['en', 'am']) {
    const p = await open(b, lang); const tag = lang + ': ';
    await p.waitForTimeout(400);
    t(tag + 'nothing before the map exists', (await p.evaluate(() => window.__rec.pushed.length)) === 0);
    // the app creates its map: `map = new google.maps.Map(...)` (a script-level let assignment, as in the bundle)
    await p.addScriptTag({ content: 'map = window.__mapObj();' });
    await p.waitForSelector('#etel-traffic-btn-courier', { timeout: 4000 });
    await p.waitForSelector('#etel-courier-demand', { state: 'attached', timeout: 4000 });
    const st0 = await p.evaluate(() => ({ polys: window.__rec.polys.length, vis: getComputedStyle(document.getElementById('etel-courier-demand')).display, pos: window.__rec.pushed.map(x => x.pos) }));
    t(tag + 'the traffic button is on the right-centre stack, the hot-areas card on the top-left stack', st0.pos.includes(15) && st0.pos.includes(5) && st0.pos.length === 2, JSON.stringify(st0.pos));
    t(tag + 'offline: card hidden, no hexagons, no server call', st0.vis === 'none' && st0.polys === 0 && p.calls.length === 0, JSON.stringify([st0, p.calls.length]));
    // go online
    await p.addScriptTag({ content: 'COURIER_IS_ONLINE = true;' });
    await p.waitForFunction(() => window.__rec.polys.length >= 3, null, { timeout: 6000 });
    const r = await p.evaluate(() => ({ polys: window.__rec.polys.map(x => ({ n: x.o.paths.length, fill: x.o.fillColor, fo: x.o.fillOpacity, sw: x.o.strokeWeight, z: x.o.zIndex, click: x.o.clickable, map: x.map && x.map.id })), vis: getComputedStyle(document.getElementById('etel-courier-demand')).display, text: document.getElementById('etel-courier-demand').innerText }));
    t(tag + 'online: the card shows and one hexagon is drawn per busy cell (3), 6 corners, not clickable', r.vis === 'block' && r.polys.length === 3 && r.polys.every(x => x.n === 6 && x.click === false && x.map === 'MAP'), JSON.stringify(r.polys.length));
    const by = [...r.polys].sort((a, c) => a.fo - c.fo);
    t(tag + 'busier = brighter gold, more solid, thicker edge', by.every((x, i, a) => i === 0 || (lum(x.fill) > lum(a[i - 1].fill) && x.fo > a[i - 1].fo && x.sw > a[i - 1].sw)), JSON.stringify(by.map(x => [x.fill, x.fo, x.sw])));
    t(tag + 'the brightest is drawn on top', by[2].z === Math.max(...r.polys.map(x => x.z)));
    t(tag + 'the card names the busiest area, its jobs and distance', /4/.test(r.text) && /1\.4/.test(r.text), r.text);
    t(tag + 'the card is in the right language', lang === 'am' ? /[ሀ-፿]/.test(r.text) : /Hot areas/.test(r.text) && /Quiet/.test(r.text) && /Go there/.test(r.text), r.text);
    const call = p.calls[0];
    t(tag + 'asks courier_get_demand with the courier position', call && call.action === 'courier_get_demand' && call.lat === 7.0621 && call.lng === 38.4761, JSON.stringify(call));
    // Go there
    await p.click('#etel-courier-demand-go');
    const pan = await p.evaluate(() => ({ pans: window.__rec.pans, zoom: window.__rec.zoom }));
    t(tag + 'Go there moves the map to the busiest area and zooms in', pan.pans.length === 1 && pan.pans[0].lat === 7.06 && pan.pans[0].lng === 38.47 && pan.zoom[0] === 15, JSON.stringify(pan));
    // traffic
    await p.click('#etel-traffic-btn-courier');
    t(tag + 'the traffic button turns the traffic layer on the courier map (found through the app\'s own map variable)', (await p.evaluate(() => window.__rec.layers[0].map && window.__rec.layers[0].map.id)) === 'MAP');
    // hide
    await p.click('#etel-courier-demand-toggle');
    const off = await p.evaluate(() => ({ on: window.__rec.polys.filter(x => x.map).length, saved: localStorage.getItem('etel_courier_hot_areas'), pressed: document.getElementById('etel-courier-demand-toggle').getAttribute('aria-pressed'), go: !!document.getElementById('etel-courier-demand-go') }));
    t(tag + 'Off hides every hexagon and the details, and is remembered', off.on === 0 && off.saved === 'off' && off.pressed === 'false' && off.go === false, JSON.stringify(off));
    await p.click('#etel-courier-demand-toggle');
    t(tag + 'On shows them again', (await p.evaluate(() => window.__rec.polys.filter(x => x.map && x.map.id === 'MAP').length)) >= 3);
    // go offline: everything off
    await p.addScriptTag({ content: 'COURIER_IS_ONLINE = false;' }); await p.waitForTimeout(3000);
    const offl = await p.evaluate(() => ({ on: window.__rec.polys.filter(x => x.map).length, vis: getComputedStyle(document.getElementById('etel-courier-demand')).display }));
    t(tag + 'going offline clears the map and hides the card', offl.on === 0 && offl.vis === 'none', JSON.stringify(offl));
    t(tag + 'no page errors', p.errs.length === 0, p.errs.join('|'));
    await p.ctx.close();
  }
  // a saved "off" starts hidden
  const p = await open(b, 'en', { etel_courier_hot_areas: 'off' });
  await p.addScriptTag({ content: 'map = window.__mapObj(); COURIER_IS_ONLINE = true;' });
  await p.waitForSelector('#etel-courier-demand-toggle', { state: 'attached', timeout: 4000 }); await p.waitForTimeout(3500);
  t('saved Off: no hexagons and no server call', (await p.evaluate(() => window.__rec.polys.length)) === 0 && p.calls.length === 0, String(p.calls.length));
  await p.ctx.close();
  await b.close(); console.log(pass + ' passed, ' + fails.length + ' failed'); fails.forEach(f => console.log(' FAIL ' + f)); process.exit(fails.length ? 1 : 0);
})();
