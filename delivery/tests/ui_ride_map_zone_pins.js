// Rider map: the quick-zone places as small gold pins, a card with "Pickup here" / "Drop-off here", and a Show/Hide button.
// A fake Google Maps, a fake city (EtelArea), the places API mocked, and the REAL js/ride-map-zone-pins.js.
// Needs Playwright (node).   node delivery/tests/ui_ride_map_zone_pins.js
const { chromium } = require('playwright'); const path = require('path'); const fs = require('fs');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const JS = fs.readFileSync(path.join(__dirname, '..', 'js', 'ride-map-zone-pins.js'), 'utf8');
const PLACES = { hwa: [{ name: 'Hawassa University', name_am: 'ሀዋሳ ዩኒቨርሲቲ', lat: 7.05, lng: 38.5 }, { name: 'Bus Station', name_am: '', lat: 7.06, lng: 38.47 },
  { name: 'Broken', lat: 'x', lng: 38 }, { name: 'Zero', lat: 0, lng: 0 }, { name: '<img src=x onerror="window.__xss=1">', lat: 7.07, lng: 38.48 }], add: [{ name: 'Bole', lat: 9.0, lng: 38.76 }] };
const FAKE = () => {
  const rec = { markers: [], info: null, pushed: [], calls: [] };
  class Marker { constructor(o) { this.o = o; this.map = o.map; this.l = {}; rec.markers.push(this); } setMap(m) { this.map = m; } addListener(e, f) { this.l[e] = f; } }
  class InfoWindow { constructor() { rec.info = this; this.open_ = false; } setContent(n) { this.node = n; document.getElementById('iw').innerHTML = ''; document.getElementById('iw').appendChild(n); } open(map, m) { this.open_ = true; this.marker = m; } close() { this.open_ = false; } }
  const arr = pos => ({ push: el => { rec.pushed.push({ pos, el }); document.body.appendChild(el); } });
  window.__rec = rec;
  window.__makeMap = () => ({ controls: { 15: arr(15), 3: arr(3) }, id: 'MAP' });
  window.google = { maps: { Marker, InfoWindow, ControlPosition: { RIGHT_CENTER: 15, TOP_RIGHT: 3 }, SymbolPath: { CIRCLE: 0 } } };
  window.setPickup = (a, b, c) => rec.calls.push(['pickup', a, b, c]);
  window.setDropoff = (a, b, c) => rec.calls.push(['dropoff', a, b, c]);
  window.__area = { code: 'hwa', name: 'Hawassa' };
  window.EtelArea = { get: () => window.__area };
};
(async () => {
  const b = await chromium.launch();
  for (const lang of ['en', 'am']) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    await ctx.addInitScript(l => { try { localStorage.setItem('etel_lang', l); } catch (e) {} }, lang);
    await ctx.addInitScript(FAKE);
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    let hits = 0;
    await p.route('http://rider.test/delivery/api/boot.php**', r => { hits++; const code = new URL(r.request().url()).searchParams.get('area'); r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { places: PLACES[code] || [] } }) }); });
    await p.route('http://rider.test/delivery/ride/', r => r.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body><div id="iw"></div></body></html>' }));
    await p.goto('http://rider.test/delivery/ride/');
    await p.addScriptTag({ content: JS });
    await p.waitForTimeout(300);
    t(lang + ': nothing before the map exists', (await p.evaluate(() => window.__rec.markers.length)) === 0 && (await p.locator('#ride-zonepins-btn').count()) === 0);
    await p.evaluate(() => { window.RIDE = { map: window.__makeMap() }; });
    await p.waitForSelector('#ride-zonepins-btn', { timeout: 4000 });
    await p.waitForFunction(() => window.__rec.markers.length >= 3, null, { timeout: 4000 });
    const st = await p.evaluate(() => ({ n: window.__rec.markers.length, onMap: window.__rec.markers.filter(m => m.map && m.map.id === 'MAP').length, pos: window.__rec.pushed.map(x => x.pos), titles: window.__rec.markers.map(m => m.o.title), icon: window.__rec.markers[0].o.icon }));
    t(lang + ': 3 valid pins (bad, zero and non-number places are skipped)', st.n === 3 && st.onMap === 3, JSON.stringify(st));
    t(lang + ': the button is in the right-centre stack', st.pos.length === 1 && st.pos[0] === 15);
    t(lang + ': small gold round pins', st.icon.fillColor === '#D4AF37' && st.icon.scale <= 8);
    t(lang + ': pin title is in the right language', lang === 'am' ? st.titles[0] === 'ሀዋሳ ዩኒቨርሲቲ' && st.titles[1] === 'Bus Station' : st.titles[0] === 'Hawassa University', JSON.stringify(st.titles));
    // tap a pin
    await p.evaluate(() => window.__rec.markers[0].l.click());
    const card = await p.evaluate(() => ({ open: window.__rec.info.open_, btns: [...document.querySelectorAll('#iw button')].map(b => b.textContent), head: document.querySelector('#iw div div').textContent }));
    t(lang + ': a tap opens the card with two buttons', card.open && card.btns.length === 2, JSON.stringify(card));
    t(lang + ': card buttons are in the right language', lang === 'am' ? /እዚህ/.test(card.btns[0]) : card.btns[0] === 'Pickup here' && card.btns[1] === 'Drop-off here', JSON.stringify(card.btns));
    await p.click('#iw button:nth-of-type(1)');
    await p.evaluate(() => window.__rec.markers[0].l.click()); await p.click('#iw button:nth-of-type(2)');
    const calls = await p.evaluate(() => window.__rec.calls);
    t(lang + ': Pickup here calls setPickup with the place and city', calls[0] && calls[0][0] === 'pickup' && calls[0][1] === 7.05 && calls[0][2] === 38.5 && calls[0][3] === 'Hawassa University, Hawassa', JSON.stringify(calls));
    t(lang + ': Drop-off here calls setDropoff', calls[1] && calls[1][0] === 'dropoff' && calls[1][1] === 7.05, JSON.stringify(calls));
    // a place name with HTML is only text
    await p.evaluate(() => window.__rec.markers[2].l.click());
    t(lang + ': a place name with HTML stays text (no injected element, no script run)', (await p.evaluate(() => !window.__xss && !document.querySelector('#iw img'))) && (await p.evaluate(() => document.querySelector('#iw div div').textContent)).indexOf('<img') === 0);
    // hide / show, remembered
    await p.click('#ride-zonepins-btn');
    const hid = await p.evaluate(() => ({ onMap: window.__rec.markers.filter(m => m.map).length, pressed: document.getElementById('ride-zonepins-btn').getAttribute('aria-pressed'), saved: localStorage.getItem('etel_ride_zone_pins'), open: window.__rec.info.open_ }));
    t(lang + ': the button hides every pin, closes the card, and remembers', hid.onMap === 0 && hid.pressed === 'false' && hid.saved === '0' && hid.open === false, JSON.stringify(hid));
    await p.click('#ride-zonepins-btn');
    t(lang + ': and shows them again', (await p.evaluate(() => window.__rec.markers.filter(m => m.map && m.map.id === 'MAP').length)) === 3);
    // a city change redraws
    await p.evaluate(() => { window.__area = { code: 'add', name: 'Addis Ababa' }; window.dispatchEvent(new Event('etel-area-change')); });
    await p.waitForFunction(() => window.__rec.markers.some(m => m.o.title === 'Bole'), null, { timeout: 4000 });
    t(lang + ': a new city replaces the pins', (await p.evaluate(() => window.__rec.markers.filter(m => m.map).map(m => m.o.title))).join() === 'Bole');
    t(lang + ': the places list is loaded once per city (cached)', hits === 2, String(hits));
    // loading the file twice adds no second button
    await p.addScriptTag({ content: JS }); await p.waitForTimeout(900);
    t(lang + ': loading twice adds no second button', (await p.locator('#ride-zonepins-btn').count()) === 1);
    t(lang + ': no page errors', errs.length === 0, errs.join('|'));
    await ctx.close();
  }
  // saved OFF: the pins start hidden
  const ctx = await b.newContext({ serviceWorkers: 'block' }); await ctx.addInitScript(() => { try { localStorage.setItem('etel_ride_zone_pins', '0'); } catch (e) {} }); await ctx.addInitScript(FAKE);
  const p = await ctx.newPage();
  await p.route('http://rider.test/delivery/api/boot.php**', r => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { places: PLACES.hwa } }) }));
  await p.route('http://rider.test/delivery/ride/', r => r.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body><div id="iw"></div></body></html>' }));
  await p.goto('http://rider.test/delivery/ride/'); await p.addScriptTag({ content: JS }); await p.evaluate(() => { window.RIDE = { map: window.__makeMap() }; });
  await p.waitForFunction(() => window.__rec.markers.length >= 3, null, { timeout: 4000 });
  t('saved OFF: pins are made but start hidden', (await p.evaluate(() => window.__rec.markers.filter(m => m.map).length)) === 0 && (await p.getAttribute('#ride-zonepins-btn', 'aria-pressed')) === 'false');
  // no city: no pins and no error
  const p2 = await (await b.newContext({ serviceWorkers: 'block' })).newPage(); const e2 = []; p2.on('pageerror', e => e2.push(e.message));
  await p2.addInitScript(FAKE); await p2.route('http://rider.test/x', r => r.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body><div id="iw"></div></body></html>' }));
  await p2.goto('http://rider.test/x'); await p2.evaluate(() => { window.__area = null; }); await p2.addScriptTag({ content: JS }); await p2.evaluate(() => { window.RIDE = { map: window.__makeMap() }; }); await p2.waitForTimeout(1600);
  t('no city: no pins and no error', (await p2.evaluate(() => window.__rec.markers.length)) === 0 && e2.length === 0, e2.join('|'));
  await b.close(); console.log(pass + ' passed, ' + fails.length + ' failed'); fails.forEach(f => console.log(' FAIL ' + f)); process.exit(fails.length ? 1 : 0);
})();
