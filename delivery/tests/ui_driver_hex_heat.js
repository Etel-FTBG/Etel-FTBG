// Driver map: hot areas as HEXAGONS in gold (the busier the cell, the brighter the gold), fare multiplier on the busiest cells at peak time.
// A fake Google Maps that records every Polygon / Marker / Circle, the driver_get_demand reply mocked, and the REAL js/driver-demand.js.
// Needs Playwright (node).   node delivery/tests/ui_driver_hex_heat.js
const { chromium } = require('playwright'); const path = require('path'); const fs = require('fs');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const JS = fs.readFileSync(path.join(__dirname, '..', 'js', 'driver-demand.js'), 'utf8');
const AREAS = [{ lat: 7.06, lng: 38.47, requests: 9, waiting: 2, idle_drivers: 0, score: 13, level: 'high', distance_km: 1.2, q: 0, r: 0, heat: 1 },
  { lat: 7.07, lng: 38.48, requests: 5, waiting: 0, idle_drivers: 1, score: 2.5, level: 'med', distance_km: 2.1, q: 1, r: 0, heat: 0.42 },
  { lat: 7.05, lng: 38.49, requests: 2, waiting: 0, idle_drivers: 1, score: 1, level: 'low', distance_km: 3.3, q: 0, r: 1, heat: 0.17 },
  { lat: 7.08, lng: 38.46, requests: 6, waiting: 1, idle_drivers: 0, score: 8, level: 'high', distance_km: 4, q: -1, r: 1, heat: 0.88 }];
const FAKE = () => {
  const rec = { polys: [], markers: [], circles: [] };
  class Polygon { constructor(o) { this.o = o; this.map = o.map; rec.polys.push(this); } setMap(m) { this.map = m; } }
  class Marker { constructor(o) { this.o = o; this.map = o.map; rec.markers.push(this); } setMap(m) { this.map = m; } }
  class Circle { constructor(o) { this.o = o; this.map = o.map; rec.circles.push(this); } setMap(m) { this.map = m; } }
  window.__rec = rec;
  window.google = { maps: { Polygon, Marker, Circle, Size: function (w, h) { this.w = w; this.h = h; }, Point: function (x, y) { this.x = x; this.y = y; } } };
  window.DRV = { map: { id: 'MAP', panTo() {}, getZoom() { return 15; }, setZoom() {} }, isOnline: true, activeRide: null, currentLat: 7.0621, currentLng: 38.4761 };
};
const lum = hex => { const n = parseInt(hex.slice(1), 16); return 0.2126 * (n >> 16) + 0.7152 * ((n >> 8) & 255) + 0.0722 * (n & 255); };
const km = (a, b) => { const R = 6371, dl = (b.lat - a.lat) * Math.PI / 180, dg = (b.lng - a.lng) * Math.PI / 180, h = Math.sin(dl / 2) ** 2 + Math.cos(a.lat * Math.PI / 180) * Math.cos(b.lat * Math.PI / 180) * Math.sin(dg / 2) ** 2; return R * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h)); };
async function run(b, reply, tag, after) {
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  await ctx.addInitScript(FAKE);
  const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
  await p.route('http://driver.test/delivery/api/boot.php', r => r.fulfill({ contentType: 'application/json', body: JSON.stringify(reply) }));
  await p.route('http://driver.test/delivery/driver/', r => r.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body data-page="requests"></body></html>' }));
  await p.goto('http://driver.test/delivery/driver/');
  await p.addScriptTag({ content: JS });
  await p.waitForTimeout(3500); // the page ticks every 2.5 s and draws the areas by itself once online
  await after(p, tag, errs);
  await ctx.close();
}
(async () => {
  const b = await chromium.launch();
  // 1. with hexes and a peak window
  await run(b, { success: true, data: { areas: AREAS, hexes: AREAS, size_m: 700, surge_now: 1.3, window_min: 45 } }, 'hex+surge', async (p, tag, errs) => {
    const r = await p.evaluate(() => ({ polys: window.__rec.polys.map(x => ({ n: x.o.paths.length, fill: x.o.fillColor, fo: x.o.fillOpacity, sw: x.o.strokeWeight, z: x.o.zIndex, click: x.o.clickable, map: x.map && x.map.id, paths: x.o.paths })), circles: window.__rec.circles.length, markers: window.__rec.markers.map(m => ({ url: decodeURIComponent(m.o.icon.url), pos: m.o.position })) }));
    t(tag + ': one hexagon per busy cell (4)', r.polys.length === 4 && r.polys.every(x => x.map === 'MAP'), JSON.stringify(r.polys.length));
    t(tag + ': every hexagon has 6 corners', r.polys.every(x => x.n === 6));
    t(tag + ': no circles any more', r.circles === 0);
    t(tag + ': hexagons do not catch taps (the map stays usable)', r.polys.every(x => x.click === false));
    const byHeat = [...r.polys].sort((a, c) => a.fo - c.fo);
    t(tag + ': busier = brighter gold (colour brightness rises with heat)', byHeat.every((x, i, a) => i === 0 || lum(x.fill) > lum(a[i - 1].fill)), JSON.stringify(byHeat.map(x => x.fill)));
    t(tag + ': busier = more solid fill and thicker edge', byHeat.every((x, i, a) => i === 0 || (x.fo > a[i - 1].fo && x.sw > a[i - 1].sw)), JSON.stringify(byHeat.map(x => [x.fo, x.sw])));
    t(tag + ': the brightest is a bright gold (not dull) and the quietest is dim', lum(byHeat[3].fill) > 200 && lum(byHeat[0].fill) < 150, JSON.stringify([byHeat[0].fill, byHeat[3].fill]));
    t(tag + ': the brightest is drawn on top (highest zIndex)', byHeat[3].z === Math.max(...r.polys.map(x => x.z)) && byHeat[3].z > byHeat[0].z);
    // geometry: corners are one cell size (700 m) from the cell centre, and the centre of cell (0,0) is the Hawassa origin
    const c0 = r.polys.find(x => Math.abs(x.paths[0].lat - 7.0621) < 0.01 && x.fo > 0.6); // heat 1 cell, q=0 r=0
    const cen = c0.paths.reduce((a, q) => ({ lat: a.lat + q.lat / 6, lng: a.lng + q.lng / 6 }), { lat: 0, lng: 0 });
    t(tag + ': cell (0,0) is centred on the grid origin', Math.abs(cen.lat - 7.0621) < 1e-4 && Math.abs(cen.lng - 38.4761) < 1e-4, JSON.stringify(cen));
    t(tag + ': corners are 0.7 km from the centre (the size the server uses)', c0.paths.every(q => Math.abs(km(q, cen) - 0.7) < 0.01), JSON.stringify(c0.paths.map(q => km(q, cen).toFixed(3))));
    t(tag + ': a fare multiplier pill (1.3x) sits on the busiest cells, at most 3, only where heat is 0.6 or more', r.markers.length === 2 && r.markers.every(m => /1\.3x/.test(m.url)), JSON.stringify(r.markers.length));
    t(tag + ': no page errors', errs.length === 0, errs.join('|'));
    // the panel: peak note, legend and heat bars
    await p.evaluate(() => window.EtelDemand.open()); await p.waitForTimeout(500);
    const panel = await p.evaluate(() => { const h = document.querySelector('div[style*="2147483000"]'); const sh = h && h.shadowRoot; return sh ? { sg: (sh.querySelector('.sg') || {}).textContent || '', lg: !!sh.querySelector('.lg'), bars: sh.querySelectorAll('.hb i').length } : null; });
    t(tag + ': the panel says it is peak time and the fare multiplier', panel && /Peak time/.test(panel.sg) && /1\.3/.test(panel.sg), JSON.stringify(panel));
    t(tag + ': the panel has a Quiet-to-Busiest legend and a heat bar per area', panel && panel.lg && panel.bars === 4, JSON.stringify(panel));
  });
  // 2. no peak window: no pills
  await run(b, { success: true, data: { areas: AREAS, hexes: AREAS, size_m: 700, surge_now: null, window_min: 45 } }, 'no-surge', async (p, tag, errs) => {
    const r = await p.evaluate(() => ({ polys: window.__rec.polys.length, markers: window.__rec.markers.length }));
    t(tag + ': hexagons yes, fare multiplier pills no', r.polys === 4 && r.markers === 0, JSON.stringify(r));
    t(tag + ': no page errors', errs.length === 0, errs.join('|'));
  });
  // 3. an old server without hexes: the circles are still drawn
  await run(b, { success: true, data: { areas: AREAS, window_min: 45 } }, 'old-server', async (p, tag, errs) => {
    const r = await p.evaluate(() => ({ polys: window.__rec.polys.length, circles: window.__rec.circles.length }));
    t(tag + ': falls back to circles', r.polys === 0 && r.circles === 4, JSON.stringify(r));
    t(tag + ': no page errors', errs.length === 0, errs.join('|'));
  });
  // 4. a hostile surge value cannot break the pill picture
  await run(b, { success: true, data: { areas: AREAS, hexes: AREAS, size_m: 700, surge_now: '1.3"><script>window.__x=1</script>', window_min: 45 } }, 'hostile', async (p, tag, errs) => {
    const r = await p.evaluate(() => ({ x: window.__x || 0, urls: window.__rec.markers.map(m => decodeURIComponent(m.o.icon.url)) }));
    t(tag + ': the multiplier in the pill keeps only digits, dot and x', r.x === 0 && r.urls.every(u => !/<script/i.test(u)), JSON.stringify(r.urls[0] && r.urls[0].slice(-80)));
    t(tag + ': no page errors', errs.length === 0, errs.join('|'));
  });
  await b.close(); console.log(pass + ' passed, ' + fails.length + ' failed'); fails.forEach(f => console.log(' FAIL ' + f)); process.exit(fails.length ? 1 : 0);
})();
