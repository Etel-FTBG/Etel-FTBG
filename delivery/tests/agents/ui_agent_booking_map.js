// Needs: Playwright (node), the PHP server from README.md on ETEL_HOST (default http://127.0.0.1:8099) and tokens (see README.md).
// Google Maps is replaced by a recording stand-in (MOCK_MAPS) because the real API needs a key and internet.
const HOST = process.env.ETEL_HOST || 'http://127.0.0.1:8099'; const TOKDIR = process.env.ETEL_TOKDIR || __dirname;
// End-to-end test of the Agent portal (real files served by the PHP router; MariaDB behind it).
const { chromium } = require('playwright'); const fs = require('fs'); const zlib = require('zlib');
const { execSync } = require('child_process');
const D = __dirname, BASE = HOST + '/delivery/agent/index.html';
const q = sql => execSync('mysql fa_test -N -e ' + JSON.stringify(sql)).toString().trim();
const tok = p => fs.readFileSync(TOKDIR + '/tok-' + p + '.txt', 'utf8').trim();
const decode = f => execSync('python3 ' + D + '/decode.py ' + f).toString().trim();
let pass = 0; const fails = [];
const ok = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? '  -> ' + x : '')); };
const step = async (n, f) => { try { await f(); } catch (e) { fails.push(n + '  -> EXCEPTION ' + String(e.message).split('\n')[0].slice(0, 170)); } };
const AGENT = q("SELECT CONCAT(id,'|',code) FROM fa_agents WHERE phone='0911000001'").split('|');
const CODE = AGENT[1], LINK = HOST + '/delivery/ride/?ref=' + CODE;

async function open(b, phone, opts) {
  opts = opts || {};
  const ctx = await b.newContext(Object.assign({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, acceptDownloads: true, permissions: ['clipboard-read', 'clipboard-write'] }, opts.ctx || {}));
  if (phone) { const t = tok(phone); await ctx.addInitScript(t => { try { if (!localStorage.getItem('__seeded')) { localStorage.setItem('etel_token', t); localStorage.setItem('__seeded', '1'); } } catch (e) {} }, t); }
  const p = await ctx.newPage(); p.errs = [];
  p.on('pageerror', e => p.errs.push('PAGEERROR ' + e.message)); p.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) p.errs.push(m.text().slice(0, 160)); });
  await p.goto(BASE + (opts.hash || ''), { waitUntil: 'networkidle' }); await p.waitForTimeout(opts.wait || 1500);
  return p;
}
const go = async (p, v) => { await p.evaluate(v => { location.hash = '#/' + v; }, v); await p.waitForTimeout(1100); };
const text = p => p.evaluate(() => document.body.innerText);
function lum(c) { const f = x => { x /= 255; return x <= .03928 ? x / 12.92 : Math.pow((x + .055) / 1.055, 2.4); }; return .2126 * f(c[0]) + .7152 * f(c[1]) + .0722 * f(c[2]); }
const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); };
const lumc = c => lum(c);
// collect (fg, [bg candidates]) for visible text elements, resolving gradients/alpha against ancestors
const SCAN = () => {
  const num = s => (s.match(/[\d.]+/g) || []).map(Number);
  const parse = s => { const n = num(s); return n.length >= 3 ? [n[0], n[1], n[2], n.length > 3 ? n[3] : 1] : null; };
  const blend = (f, b) => [f[0] * f[3] + b[0] * (1 - f[3]), f[1] * f[3] + b[1] * (1 - f[3]), f[2] * f[3] + b[2] * (1 - f[3])];
  const bgsOf = el => { // list of possible opaque backgrounds
    let layers = []; for (let e = el; e; e = e.parentElement) { const cs = getComputedStyle(e); layers.push(cs); if (e === document.documentElement) break; }
    let cands = [[255, 255, 255]]; let first = true;
    const base = parse(getComputedStyle(document.documentElement).backgroundColor) || [255, 255, 255, 1];
    cands = [base.slice(0, 3)];
    for (let i = layers.length - 1; i >= 0; i--) {
      const cs = layers[i]; let list = [];
      const bi = cs.backgroundImage; const cols = bi && bi !== 'none' ? (bi.match(/rgba?\([^)]*\)/g) || []).map(parse).filter(Boolean) : [];
      const bc = parse(cs.backgroundColor);
      if (cols.length) list = cols; else if (bc && bc[3] > 0) list = [bc];
      if (!list.length) continue;
      const next = []; cands.forEach(c => list.forEach(l => next.push(blend(l, c)))); cands = next;
    }
    return cands;
  };
  const out = [];
  document.querySelectorAll('.app *, .nav *').forEach(el => {
    if (!el.childNodes.length || ![...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim())) return;
    const r = el.getBoundingClientRect(); if (r.width < 2 || r.height < 2) return; const cs = getComputedStyle(el); if (cs.visibility === 'hidden' || cs.display === 'none') return;
    if (el.closest('.sr') || el.closest('[aria-hidden=true]')) return;
    const fg = parse(cs.color); if (!fg) return; const fs = parseFloat(cs.fontSize);
    out.push({ el: el.tagName + '.' + (el.className || '') + ':' + el.textContent.trim().slice(0, 18), fg: fg, bgs: bgsOf(el), large: fs >= 24 || (fs >= 18.66 && parseInt(cs.fontWeight) >= 700) });
  });
  return out;
};
async function contrastReport(p) {
  const items = await p.evaluate(SCAN); const low = [];
  items.forEach(i => { const need = i.large ? 3 : 4.5; let worst = 99; i.bgs.forEach(b => { const fg = [i.fg[0] * i.fg[3] + b[0] * (1 - i.fg[3]), i.fg[1] * i.fg[3] + b[1] * (1 - i.fg[3]), i.fg[2] * i.fg[3] + b[2] * (1 - i.fg[3])]; worst = Math.min(worst, ratio(fg, b)); }); if (worst < need) low.push(i.el + ' ' + worst.toFixed(2)); });
  return { n: items.length, low };
}
// A stand-in for the Google Maps JavaScript API (the real one cannot be reached from this test machine). It records what the page asks it to do.
const MOCK_MAPS = `
(function(){
  var W = window; W.__mk = []; W.__acs = []; W.__lines = []; W.__maps = [];
  function LL(lat,lng){return {lat:function(){return lat},lng:function(){return lng}}}
  function Map(el,o){ this.el=el; this.c=o.center; this.z=o.zoom; this.l={}; this.styles=o.styles; el.setAttribute('data-mock-map','1'); el.style.background='#2a2a2a'; el.innerHTML='<span style="color:#fff">MAP</span>'; W.__maps.push(this); }
  Map.prototype={setCenter:function(c){this.c=c},setZoom:function(z){this.z=z},fitBounds:function(){this.fit=1},getCenter:function(){var c=this.c;return {lat:function(){return c.lat},lng:function(){return c.lng}}},addListener:function(e,f){this.l[e]=f}};
  function Marker(o){ this.o=o; this.pos=o.position; this.l={}; this.map=o.map; W.__mk.push(this); }
  Marker.prototype={setMap:function(m){this.map=m},setPosition:function(p){this.pos=p},addListener:function(e,f){this.l[e]=f}};
  function Polyline(o){ this.o=o; this.map=o.map; W.__lines.push(this); } Polyline.prototype={setMap:function(m){this.map=m}};
  function Auto(input,o){ this.input=input; this.l={}; this.o=o; W.__acs.push(this); } Auto.prototype={addListener:function(e,f){this.l[e]=f},getPlace:function(){return this.place}};
  function Dirs(){} Dirs.prototype={route:function(r,cb){ setTimeout(function(){ cb({routes:[{legs:[{distance:{value:7300}}],overview_path:[{lat:7.06,lng:38.47},{lat:7.07,lng:38.48}]}]},'OK') },10) }};
  function Geo(){} Geo.prototype={geocode:function(r,cb){ setTimeout(function(){ cb([{formatted_address:'Mock Street, Hawassa'}],'OK') },10) }};
  function Bounds(){} Bounds.prototype={extend:function(){}};
  W.google={maps:{Map:Map,Marker:Marker,Polyline:Polyline,DirectionsService:Dirs,Geocoder:Geo,LatLngBounds:Bounds,Size:function(w,h){this.w=w;this.h=h},Point:function(x,y){this.x=x;this.y=y},SymbolPath:{CIRCLE:0},
    places:{Autocomplete:Auto},event:{trigger:function(){}}}};
  setTimeout(function(){ if (W.__bkMapsReady) W.__bkMapsReady(); },20);
})();`;
(async () => {
  const b = await chromium.launch(); const T = tok('0911000001'); const sql = s => q(s);
  sql("UPDATE fa_settings SET setting_value='1' WHERE setting_key IN ('program_enabled','agent_booking_enabled')"); sql("UPDATE fa_settings SET setting_value='10' WHERE setting_key='agent_booking_daily_cap'");
  const DRIVERS = [{ lat: 7.0625, lng: 38.4766, vehicle_type: 'Bajaj' }, { lat: 7.0630, lng: 38.4770, vehicle_type: 'Car' }, { lat: 7.0640, lng: 38.4780, vehicle_type: 'Minibus' }];
  const mk = async (theme, lang, w, maps) => {
    const c = await b.newContext({ viewport: { width: w || 390, height: 844 }, deviceScaleFactor: 2, permissions: ['geolocation'], geolocation: { latitude: 7.0621, longitude: 38.4763 } });
    await c.addInitScript(([t, th, l]) => { try { if (!localStorage.getItem('__s')) { localStorage.setItem('etel_token', t); localStorage.setItem('theme', th); localStorage.setItem('etel_agent_lang', l); localStorage.setItem('__s', '1'); } } catch (e) {} }, [T, theme, lang]);
    const p = await c.newPage(); p.errs = []; p.reqs = [];
    p.on('pageerror', e => p.errs.push(e.message)); p.on('console', m => { if (m.type() === 'error' && !/Failed to load resource|Maps API|net::ERR/.test(m.text())) p.errs.push(m.text().slice(0, 140)); });
    await p.route('https://maps.googleapis.com/**', r => maps ? r.fulfill({ status: 200, contentType: 'application/javascript', body: MOCK_MAPS }) : r.abort());
    await p.route('**/api/boot.php**', async r => {
      const rq = r.request(); const url = rq.url(); const body = rq.postData() || '';
      if (/action=get_config/.test(url)) return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { google_maps_api_key: maps === 'nokey' ? '' : 'TESTKEY' } }) });
      if (/passenger_get_nearby_drivers/.test(body)) { p.reqs.push(JSON.parse(body)); return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { drivers: DRIVERS } }) }); }
      if (/fa_agent_book_ride/.test(body)) p.book = JSON.parse(body);
      return r.continue();
    });
    return [c, p];
  };
  for (const theme of ['dark', 'light']) await step('map flow ' + theme, async () => {
    sql("TRUNCATE TABLE fa_bookings"); sql("DELETE FROM users WHERE phone IN ('0955222111')"); sql("UPDATE rides SET status='cancelled' WHERE status='searching'");
    const [c, p] = await mk(theme, 'en', 390, true); await p.goto(BASE + '#/book', { waitUntil: 'networkidle' }); await p.waitForTimeout(2500);
    ok(theme + ': the map is shown inside the booking screen', await p.evaluate(() => !!document.querySelector('#bk-map [data-mock-map]')));
    ok(theme + ': map style follows the theme', await p.evaluate(() => { const m = window.__maps[0]; return document.documentElement.getAttribute('data-theme') === 'light' ? (m.styles || []).length === 0 : (m.styles || []).length > 0; }));
    const rows = await p.$$('.bv-veh'); ok(theme + ': vehicle rows listed', rows.length >= 4);
    const imgs = await p.evaluate(() => [...document.querySelectorAll('.bv-veh .bv-img')].map(i => [i.getAttribute('src'), i.complete && i.naturalWidth > 0]));
    ok(theme + ': vehicle rows show the real pictures (loaded)', imgs.length >= 4 && imgs.every(x => x[1] && /^veh\/\w+\.webp$/.test(x[0])), JSON.stringify(imgs));
    ok(theme + ': live drivers drawn as vehicle pictures on the map', await p.evaluate(() => window.__mk.filter(m => m.o.icon && m.o.icon.url && /^data:image\/png/.test(m.o.icon.url)).length === 3), await p.evaluate(() => window.__mk.length + ' markers'));
    ok(theme + ': driver count shown', /3 drivers near the pickup/.test(await p.innerText('#bk-drv')));
    ok(theme + ': drivers asked for with the pickup/centre and radius', p.reqs.length >= 1 && p.reqs[0].radius_km === 6 && p.reqs[0].vehicle_type === 'all', JSON.stringify(p.reqs[0] || {}));
    // pickup via place search
    ok(theme + ': place search attached to both fields', await p.evaluate(() => window.__acs.length === 2 && window.__acs[0].o.componentRestrictions.country === 'ET'));
    await p.evaluate(() => { const a = window.__acs[0]; a.place = { name: 'Hawassa Hilton', formatted_address: 'Lake Road, Hawassa', geometry: { location: { lat: () => 7.0600, lng: () => 38.4700 } } }; a.l.place_changed(); });
    await p.waitForTimeout(400);
    ok(theme + ': chosen pickup fills the field and drops the pickup pin', (await p.inputValue('#bk-pickup')).includes('Hawassa Hilton') && await p.evaluate(() => window.__mk.some(m => m.o.draggable && m.map && m.pos.lat === 7.06)));
    await p.waitForTimeout(500); ok(theme + ': drivers refreshed around the new pickup', p.reqs.length >= 2 && Math.abs(p.reqs[p.reqs.length - 1].lat - 7.06) < 1e-6);
    await p.evaluate(() => { const a = window.__acs[1]; a.place = { name: 'Airport', formatted_address: 'Hawassa Airport, Hawassa', geometry: { location: { lat: () => 7.0900, lng: () => 38.5000 } } }; a.l.place_changed(); });
    await p.waitForTimeout(600);
    ok(theme + ': destination draws the route line and shows the distance', /Route: 7\.3 km/.test(await p.innerText('#bk-route')) && await p.evaluate(() => window.__lines.length >= 1 && window.__lines[window.__lines.length - 1].map));
    ok(theme + ': the distance box is filled from the route and fares appear', (await p.inputValue('#bk-km')) === '7.3');
    await p.fill('#bk-phone', '0955222111'); await p.fill('#bk-name', 'Map Customer'); await p.click('.bv-veh[data-v=car]'); await p.waitForTimeout(900);
    ok(theme + ': the map survives re-rendering (same map, pins still there)', await p.evaluate(() => document.querySelectorAll('#bk-map [data-mock-map]').length === 1 && window.__maps.length === 1 && window.__mk.filter(m => m.o.draggable && m.map).length === 2));
    ok(theme + ': typed text kept', (await p.inputValue('#bk-phone')) === '0955222111' && (await p.inputValue('#bk-pickup')).includes('Hilton'));
    const fare = await p.innerText('.bv-veh[data-v=car] .bv-fare'); ok(theme + ': car row shows a fare for the route distance', /\d/.test(fare), fare);
    ok(theme + ': drivers re-requested for the chosen vehicle type', p.reqs.some(r => r.vehicle_type === 'car'));
    await p.click('[data-act=bk-mappick][data-m=pickup]'); await p.evaluate(() => window.__maps[0].l.click({ latLng: { lat: () => 7.0610, lng: () => 38.4720 } })); await p.waitForTimeout(700);
    ok(theme + ': tapping the map sets the pickup (address looked up)', await p.evaluate(() => window.__mk.some(m => m.o.draggable && Math.abs(m.pos.lat - 7.061) < 1e-6)) && (await p.inputValue('#bk-pickup')).includes('Mock Street'));
    ok(theme + ': pin can be dragged to adjust', await p.evaluate(() => { const m = window.__mk.find(x => x.o.draggable && Math.abs(x.pos.lat - 7.061) < 1e-6); m.l.dragend({ latLng: { lat: () => 7.0612, lng: () => 38.4722 } }); return true; }));
    await p.waitForTimeout(400);
    const rep = await contrastReport(p); ok(theme + ': screen contrast AA (' + rep.n + ')', rep.low.length === 0, rep.low.slice(0, 5).join(' | '));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '.') + '/book2-' + theme + '.png', fullPage: true });
    await p.click('#bk-go'); await p.waitForTimeout(500); ok(theme + ': confirm sheet shows pickup and destination', /Hilton|Mock Street/.test(await p.innerText('.sheet')) && /Airport/.test(await p.innerText('.sheet')));
    await p.click('#bk-yes'); await p.waitForTimeout(2200);
    ok(theme + ': booking sent with the map coordinates', p.book && p.book.pickup_lat === 7.0612 && p.book.dropoff_lat === 7.09 && p.book.dropoff_lng === 38.5 && p.book.vehicle_type === 'car' && Math.abs(p.book.km - 7.3) < 0.01, JSON.stringify(p.book));
    ok(theme + ': success card', (await text(p)).includes('Ride booked'));
    ok(theme + ': ride stored with real pickup and destination coordinates', sql("SELECT CONCAT(ROUND(pickup_lat,4), '|', ROUND(dropoff_lat,4), '|', ROUND(dropoff_lng,4)) FROM rides ORDER BY id DESC LIMIT 1") === '7.0612|7.0900|38.5000', sql("SELECT CONCAT(pickup_lat, '|', dropoff_lat, '|', dropoff_lng) FROM rides ORDER BY id DESC LIMIT 1"));
    ok(theme + ': destination form cleared after booking', (await p.inputValue('#bk-dest')) === '');
    ok(theme + ': no horizontal scroll', await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    ok(theme + ': no JS errors', p.errs.length === 0, p.errs.join(' | ')); await c.close();
  });
  await step('fallback without maps', async () => {
    sql("TRUNCATE TABLE fa_bookings"); sql("DELETE FROM users WHERE phone='0955222333'"); sql("UPDATE rides SET status='cancelled' WHERE status='searching'");
    const [c, p] = await mk('dark', 'en', 320, false); await p.goto(BASE + '#/book', { waitUntil: 'networkidle' }); await p.waitForTimeout(3000);
    ok('no maps: a plain note says the map is not available', /map is not available/.test(await text(p)), (await text(p)).slice(0, 200));
    ok('no maps: the map box is hidden, pictures still there', await p.evaluate(() => getComputedStyle(document.getElementById('bk-map')).display === 'none') && (await p.$$('.bv-veh .bv-img')).length >= 4);
    ok('no maps: 320px no horizontal scroll', await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await p.fill('#bk-phone', '0955222333'); await p.fill('#bk-pickup', 'Hotel lobby'); await p.click('.bv-veh[data-v=bajaj]'); await p.click('[data-act=bk-km][data-k="4"]'); await p.waitForTimeout(800);
    ok('no maps: typed pickup + distance chips still book', !(await p.evaluate(() => document.getElementById('bk-go').disabled)));
    await p.click('#bk-go'); await p.waitForTimeout(400); await p.click('#bk-yes'); await p.waitForTimeout(1800);
    ok('no maps: booked with the typed place', (await text(p)).includes('Ride booked') && sql("SELECT pickup_address FROM rides ORDER BY id DESC LIMIT 1") === 'Hotel lobby');
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '.') + '/book2-nomap-320.png', fullPage: true });
    ok('no maps: no JS errors', p.errs.length === 0, p.errs.join(' | ')); await c.close();
  });
  await step('no api key', async () => {
    const [c, p] = await mk('dark', 'am', 390, 'nokey'); await p.goto(BASE + '#/book', { waitUntil: 'networkidle' }); await p.waitForTimeout(2500);
    ok('no key: falls back quietly (Amharic note)', (await text(p)).includes('ካርታው አሁን አይገኝም')); await c.close();
  });
  await b.close(); console.log('PASSED: ' + pass); console.log('FAILED: ' + fails.length); fails.forEach(f => console.log('  X ' + f));
})();
