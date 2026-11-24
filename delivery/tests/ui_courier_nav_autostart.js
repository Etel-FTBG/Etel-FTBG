// Courier app (js/courier-nav.js): full-screen navigation starts ONLY after the courier pressed a Navigate button. The app redraws the route by itself every
// time the Active tab refreshes: that must not take over the screen, and must not bring it back after "End". Real script, fake Google Maps.
// Needs Playwright (node).   node delivery/tests/ui_courier_nav_autostart.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
const srv = http.createServer((q, r) => {
  const u = q.url.split('?')[0];
  if (u === '/t.html') { r.setHeader('Content-Type', 'text/html'); return r.end(HTML); }
  const f = path.join(ROOT, decodeURIComponent(u).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', 'application/javascript'); r.end(d); } });
});
const FAKE = () => {
  const any = () => new Proxy(function () {}, { get: (_, k) => (k === Symbol.toPrimitive ? () => '' : any()), apply: () => any(), construct: () => any() });
  class LatLng { constructor(a, b) { this._a = a; this._b = b; } lat() { return this._a; } lng() { return this._b; } }
  class Renderer { constructor() { this.m = { getDiv: () => document.getElementById('courier-map-container'), setMapTypeId() {}, setTilt() {}, setHeading() {}, getMapTypeId() { return 'roadmap'; }, panTo() {}, setZoom() {}, setCenter() {}, fitBounds() {}, getZoom() { return 15; }, addListener() {} }; window.__setOpts = []; } getMap() { return this.m; } setOptions(o) { window.__setOpts.push(o); } setDirections(r) { window.__drawn = (window.__drawn || 0) + 1; } }
  const maps = new Proxy({ LatLng, DirectionsRenderer: Renderer, event: { addListenerOnce() {}, addListener() {}, trigger() {} } }, { get: (o, k) => (k in o ? o[k] : any()) });
  window.google = { maps };
};
const HTML = `<!doctype html><html><body><div id="courier-map-container" style="height:300px"></div><span id="map-route-label">Shop</span>
<button id="navshop" onclick="navigateToShop('x')">Navigate to shop</button><button id="pkgnav" data-act="nav">Navigate (package)</button><button id="other">Other</button>
<div class="pac-container"><div id="pac">A place</div></div>
<script>window.navigateToShop=function(){};</script><script src="/js/courier-nav.js"></script></body></html>`;
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const PAGE = 'http://127.0.0.1:' + srv.address().port + '/t.html';
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  await ctx.addInitScript(FAKE); const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message)); p.on('console', m => { if (/courier-nav:/.test(m.text())) errs.push(m.text()); });
  await p.goto(PAGE); await p.waitForTimeout(900);
  const draw = () => p.evaluate(() => { const g = window.google.maps; const r = window.__r || (window.__r = new g.DirectionsRenderer()); const P = (a, c) => new g.LatLng(a, c);
    r.setDirections({ routes: [{ overview_path: [P(7.05, 38.48), P(7.06, 38.49)], legs: [{ distance: { value: 1300, text: '1.3 km' }, duration: { value: 240, text: '4 min' }, end_location: P(7.06, 38.49), steps: [{ instructions: 'Head north', maneuver: '', distance: { value: 1300 }, duration: { value: 240 }, end_location: P(7.06, 38.49), path: [P(7.05, 38.48), P(7.06, 38.49)] }] }] }] }); });
  const on = () => p.evaluate(() => document.body.classList.contains('cn-on'));
  t('0 the hook is in place', await p.evaluate(() => typeof window.google.maps.DirectionsRenderer.prototype.setDirections === 'function'));
  await draw(); await p.waitForTimeout(100);
  t('1 the app draws a route by itself (tab refresh): navigation does NOT take over the screen', !(await on()));
  t('1 ... and the plain route was still drawn by the app', await p.evaluate(() => window.__drawn) >= 1);
  t('1 ... the app polylines were not hidden', !(await p.evaluate(() => (window.__setOpts || []).some(o => o.suppressPolylines))));
  await p.click('#navshop'); await draw(); await p.waitForTimeout(300);
  t('2 the courier presses Navigate: navigation starts full screen', await on());
  t('2 the navigation bar (End) exists', (await p.locator('#cn-end').count()) === 1);
  await p.evaluate(() => document.getElementById('cn-end').click()); await p.waitForTimeout(200);
  t('3 End closes the navigation', !(await on()));
  await draw(); await p.waitForTimeout(1200); await draw(); await p.waitForTimeout(300);
  t('3 the auto-refresh redraws the route after End: navigation does NOT come back', !(await on()));
  await p.click('#pkgnav'); await draw(); await p.waitForTimeout(300);
  t('4 a package Navigate button starts it too', await on());
  await p.evaluate(() => document.getElementById('cn-end').click()); await p.waitForTimeout(200);
  await p.click('#other'); await draw(); await p.waitForTimeout(200);
  t('5 any other tap does not start it', !(await on()));
  await p.click('#pac'); await draw(); await p.waitForTimeout(300);
  t('6 choosing a place in the destination search starts it', await on());
  await p.evaluate(() => document.getElementById('cn-end').click()); await p.waitForTimeout(200);
  await p.click('#navshop'); await p.evaluate(() => { const n = Date.now; Date.now = () => n() + 25000; }); await draw(); await p.waitForTimeout(200);
  t('7 a Navigate tap older than 20 seconds does not count', !(await on()));
  await p.evaluate(() => window.google.maps.DirectionsRenderer.prototype.setDirections.call(window.__r, { routes: [] })); await p.waitForTimeout(100);
  t('8 clearing the route (empty) still closes everything', !(await on()));
  t('9 no page errors', errs.length === 0, errs.join('|'));
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
