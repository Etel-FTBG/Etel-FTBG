// Driver and Courier maps: the Traffic button (js/etel-map-traffic.js, one file, data-app="driver" or "courier").
// A fake Google Maps (map controls, TrafficLayer) and the REAL file.   Needs Playwright (node).   node delivery/tests/ui_map_traffic_driver_courier.js
const { chromium } = require('playwright'); const path = require('path'); const fs = require('fs');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const JS = fs.readFileSync(path.join(__dirname, '..', 'js', 'etel-map-traffic.js'), 'utf8');
const FAKE = () => {
  const rec = { layers: [], pushed: [] };
  class TrafficLayer { constructor() { this.map = null; rec.layers.push(this); } setMap(m) { this.map = m; } }
  const arr = pos => ({ push: el => { rec.pushed.push({ pos, el }); document.body.appendChild(el); } });
  window.__rec = rec;
  window.__makeMap = () => ({ controls: { 15: arr(15), 3: arr(3) }, id: 'MAP' });
  window.google = { maps: { TrafficLayer, ControlPosition: { RIGHT_CENTER: 15, TOP_RIGHT: 3 } } };
};
const inject = (app) => { const s = document.createElement('script'); s.setAttribute('data-app', app); s.textContent = window.__SRC; document.body.appendChild(s); };
(async () => {
  const b = await chromium.launch();
  for (const app of ['driver', 'courier']) for (const lang of ['en', 'am']) for (const startOn of [false, true]) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    await ctx.addInitScript(([l, on, a, src]) => { window.__SRC = src; try { localStorage.setItem('etel_lang', l); if (on) localStorage.setItem('etel_traffic_' + a, '1'); } catch (e) {} }, [lang, startOn, app, JS]);
    await ctx.addInitScript(FAKE);
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route('http://app.test/', r => r.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body></body></html>' }));
    await p.goto('http://app.test/');
    const tag = app + ' ' + lang + (startOn ? ' saved ON' : ' saved OFF') + ': ';
    await p.evaluate(inject, app); await p.waitForTimeout(250);
    const id = '#etel-traffic-btn-' + app;
    t(tag + 'no button before the map exists', (await p.locator(id).count()) === 0);
    await p.evaluate((a) => { const m = window.__makeMap(); if (a === 'driver') window.DRV = { map: m }; else window.__etelCourierMap = m; }, app);
    await p.waitForSelector(id, { timeout: 4000 });
    const info = await p.evaluate((id) => ({ n: document.querySelectorAll(id).length, pos: window.__rec.pushed.map(x => x.pos), layers: window.__rec.layers.length, on: window.__rec.layers[0].map && window.__rec.layers[0].map.id, pressed: document.querySelector(id).getAttribute('aria-pressed'), title: document.querySelector(id).title }), id);
    t(tag + 'one button on the right-centre stack, one traffic layer', info.n === 1 && info.pos.length === 1 && info.pos[0] === 15 && info.layers === 1, JSON.stringify(info));
    t(tag + 'the saved choice is restored', startOn ? info.on === 'MAP' && info.pressed === 'true' : info.on === null && info.pressed === 'false', JSON.stringify(info));
    t(tag + 'title in the right language', lang === 'am' ? /[ሀ-፿]/.test(info.title) : /traffic/i.test(info.title), info.title);
    await p.click(id);
    const after = await p.evaluate(([id, a]) => ({ on: window.__rec.layers[0].map && window.__rec.layers[0].map.id, pressed: document.querySelector(id).getAttribute('aria-pressed'), saved: localStorage.getItem('etel_traffic_' + a) }), [id, app]);
    t(tag + 'a tap flips it and remembers (per app)', startOn ? after.on === null && after.pressed === 'false' && after.saved === '0' : after.on === 'MAP' && after.pressed === 'true' && after.saved === '1', JSON.stringify(after));
    await p.evaluate(inject, app); await p.waitForTimeout(900);
    t(tag + 'loading twice adds no second button', (await p.locator(id).count()) === 1);
    t(tag + 'no page errors', errs.length === 0, errs.join('|'));
    await ctx.close();
  }
  // a wrong or missing data-app does nothing; the two apps do not share a choice
  const ctx = await b.newContext({ serviceWorkers: 'block' }); const p = await ctx.newPage(); const e = [];
  p.on('pageerror', x => e.push(x.message)); await ctx.addInitScript(FAKE);
  await p.route('http://app.test/', r => r.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body></body></html>' })); await p.goto('http://app.test/');
  await p.evaluate(([src]) => { window.__SRC = src; const s = document.createElement('script'); s.textContent = src; document.body.appendChild(s); const s2 = document.createElement('script'); s2.setAttribute('data-app', 'rider'); s2.textContent = src; document.body.appendChild(s2); window.DRV = { map: window.__makeMap() }; window.__etelCourierMap = window.__makeMap(); }, [JS]);
  await p.waitForTimeout(1600);
  t('no data-app, or an unknown app: no button and no error', (await p.locator('[id^="etel-traffic-btn-"]').count()) === 0 && e.length === 0, e.join('|'));
  await b.close(); console.log(pass + ' passed, ' + fails.length + ' failed'); fails.forEach(f => console.log(' FAIL ' + f)); process.exit(fails.length ? 1 : 0);
})();
