// Rider map: the Traffic button. A fake Google Maps (map controls, TrafficLayer) and the REAL js/ride-map-traffic.js.
// Needs Playwright (node).   node delivery/tests/ui_ride_map_traffic.js
const { chromium } = require('playwright'); const path = require('path'); const fs = require('fs');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const JS = fs.readFileSync(path.join(__dirname, '..', 'js', 'ride-map-traffic.js'), 'utf8');
const FAKE = () => {
  const rec = { layers: [], pushed: [] };
  class TrafficLayer { constructor() { this.map = null; rec.layers.push(this); } setMap(m) { this.map = m; } }
  const arr = pos => ({ push: el => { rec.pushed.push({ pos, el }); document.body.appendChild(el); } });
  const ControlPosition = { RIGHT_CENTER: 15, TOP_RIGHT: 3 };
  window.__rec = rec;
  window.__makeMap = () => { const controls = {}; controls[15] = arr(15); controls[3] = arr(3); return { controls, id: 'MAP' }; };
  window.google = { maps: { TrafficLayer, ControlPosition } };
};
(async () => {
  const b = await chromium.launch();
  for (const lang of ['en', 'am']) for (const startOn of [false, true]) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    await ctx.addInitScript(([l, on]) => { try { localStorage.setItem('etel_lang', l); if (on) localStorage.setItem('etel_ride_traffic', '1'); } catch (e) {} }, [lang, startOn]);
    await ctx.addInitScript(FAKE);
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route('http://rider.test/', r => r.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body></body></html>' }));
    await p.goto('http://rider.test/');
    const tag = lang + (startOn ? ' saved ON' : ' saved OFF');
    // the map does not exist yet: nothing must happen and nothing may break
    await p.addScriptTag({ content: JS });
    await p.waitForTimeout(200);
    t(tag + ': no button before the map exists', (await p.locator('#ride-traffic-btn').count()) === 0);
    await p.evaluate(() => { window.RIDE = { map: window.__makeMap() }; });
    await p.waitForSelector('#ride-traffic-btn', { timeout: 4000 });
    const info = await p.evaluate(() => ({ n: document.querySelectorAll('#ride-traffic-btn').length, pos: window.__rec.pushed.map(x => x.pos), layers: window.__rec.layers.length, on: window.__rec.layers[0].map && window.__rec.layers[0].map.id, pressed: document.getElementById('ride-traffic-btn').getAttribute('aria-pressed'), title: document.getElementById('ride-traffic-btn').title }));
    t(tag + ': one button, on the right-centre control stack (under zoom)', info.n === 1 && info.pos.length === 1 && info.pos[0] === 15, JSON.stringify(info));
    t(tag + ': one traffic layer', info.layers === 1);
    t(tag + ': saved choice is restored (layer on the map only when saved ON)', startOn ? info.on === 'MAP' && info.pressed === 'true' : info.on === null && info.pressed === 'false', JSON.stringify(info));
    t(tag + ': the title is in the right language', lang === 'am' ? /[ሀ-፿]/.test(info.title) : /traffic/i.test(info.title), info.title);
    await p.click('#ride-traffic-btn');
    const after = await p.evaluate(() => ({ on: window.__rec.layers[0].map && window.__rec.layers[0].map.id, pressed: document.getElementById('ride-traffic-btn').getAttribute('aria-pressed'), saved: localStorage.getItem('etel_ride_traffic') }));
    t(tag + ': a tap flips it', startOn ? after.on === null && after.pressed === 'false' && after.saved === '0' : after.on === 'MAP' && after.pressed === 'true' && after.saved === '1', JSON.stringify(after));
    await p.click('#ride-traffic-btn');
    const back = await p.evaluate(() => window.__rec.layers[0].map && window.__rec.layers[0].map.id);
    t(tag + ': a second tap flips it back', startOn ? back === 'MAP' : back === null);
    // loading the file twice must not add a second button
    await p.addScriptTag({ content: JS }); await p.waitForTimeout(900);
    t(tag + ': loading twice adds no second button', (await p.locator('#ride-traffic-btn').count()) === 1);
    t(tag + ': no page errors', errs.length === 0, errs.join('|'));
    await ctx.close();
  }
  // Google Maps missing: silent
  const ctx2 = await b.newContext({ serviceWorkers: 'block' }); const p2 = await ctx2.newPage(); const e2 = [];
  p2.on('pageerror', e => e2.push(e.message));
  await p2.route('http://rider.test/', r => r.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body></body></html>' }));
  await p2.goto('http://rider.test/'); await p2.evaluate(() => { window.RIDE = { map: {} }; }); await p2.addScriptTag({ content: JS }); await p2.waitForTimeout(1600);
  t('without Google Maps: no button and no error', (await p2.locator('#ride-traffic-btn').count()) === 0 && e2.length === 0, e2.join('|'));
  await b.close(); console.log(pass + ' passed, ' + fails.length + ' failed'); fails.forEach(f => console.log(' FAIL ' + f)); process.exit(fails.length ? 1 : 0);
})();
