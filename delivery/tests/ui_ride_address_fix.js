// Ride page: pickup / drop-off names in the style of Google Maps (js/ride-address-fix.js, the real file). Needs Playwright (node). No server: the map geocoder is a fake.
//   node delivery/tests/ui_ride_address_fix.js
const { chromium } = require('playwright'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const SRC = fs.readFileSync(path.join(__dirname, '../js/ride-address-fix.js'), 'utf8');
const R = (a, types, first) => ({ formatted_address: a, types: types || ['route'], address_components: first ? [{ long_name: first }] : undefined });
const ALPHA = '23456789CFGHJMPQRVWX';
// an independent decoder: the centre of the cell a full 11-digit code stands for
function decode(code) {
  const d = code.replace('+', ''); let lat = -90, lng = -180, res = 20;
  for (let i = 0; i < 10; i += 2) { lat += ALPHA.indexOf(d[i]) * res; lng += ALPHA.indexOf(d[i + 1]) * res; res /= 20; }
  const g = ALPHA.indexOf(d[10]); const latStep = 1 / 8000 / 5, lngStep = 1 / 8000 / 4;
  lat += Math.floor(g / 4) * latStep; lng += (g % 4) * lngStep;
  return { lat: lat + latStep / 2, lng: lng + lngStep / 2, latStep, lngStep, lat0: lat, lng0: lng };
}
(async () => {
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: 390, height: 844 } }); const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
  await p.setContent('<!doctype html><body><input id="pickup-input"><input id="dropoff-input"></body>');
  await p.evaluate(() => {
    window.GEO = { calls: 0, results: [], status: 'OK', delay: 0 };
    window.google = { maps: { Geocoder: function () { this.geocode = function (req, cb) { window.GEO.calls++; setTimeout(function () { cb(window.GEO.results, window.GEO.status); }, window.GEO.delay); }; } } };
    window.RIDE = {};
    window.setPickup = function (lat, lng, addr) { RIDE.pickupLatLng = { lat: lat, lng: lng }; document.getElementById('pickup-input').value = addr; };
    window.setDropoff = function (lat, lng, addr) { RIDE.dropoffLatLng = { lat: lat, lng: lng }; document.getElementById('dropoff-input').value = addr; };
  });
  await p.addScriptTag({ content: SRC }); await p.waitForTimeout(100);
  const enc = (lat, lng) => p.evaluate(([a, b]) => window.__etelPlusCode(a, b), [lat, lng]);

  // ---- the plus code itself ----
  const zurich = await enc(47.365562, 8.524813);
  t('plus code: Google\'s own Zurich example 8FVC9G8F+6W is reproduced (first 10 digits)', zurich.slice(0, 11) === '8FVC9G8F+6W', zurich);
  const pts = []; for (let i = 0; i < 400; i++) pts.push([7.0 + Math.random() * 0.12, 38.42 + Math.random() * 0.14]);
  const codes = await p.evaluate((pts) => pts.map(x => window.__etelPlusCode(x[0], x[1])), pts);
  let bad = 0; codes.forEach((c, i) => { const d = decode(c); if (!(pts[i][0] >= d.lat0 - 1e-9 && pts[i][0] <= d.lat0 + d.latStep + 1e-9 && pts[i][1] >= d.lng0 - 1e-9 && pts[i][1] <= d.lng0 + d.lngStep + 1e-9)) bad++; });
  t('plus code: 400 points around Hawassa each fall inside the cell their code decodes to (independent decoder)', bad === 0, String(bad));
  t('plus code: every code has 8 digits, "+" and 3 digits', codes.every(c => /^[23456789CFGHJMPQRVWX]{8}\+[23456789CFGHJMPQRVWX]{3}$/.test(c)));
  for (const full of ['6GVW2GW6+8HH', '6GVW2GV5+MJ5', '6GVW2GV6+5FG']) {   // the short codes from your examples ("2GW6+8HH", "2GV5+MJ5", "2GV6+5FG") with the Hawassa prefix
    const f = full.length === 12 ? full : full + 'X'; const d = decode(f); const again = await enc(d.lat, d.lng);
    t('plus code: ' + full.slice(4) + ' (your example) is what we write for that spot, with the first 4 digits dropped', again.slice(4, 12) === f.slice(4, 12), again + ' vs ' + f);
  }

  const run = async (kind, addr, results, o) => {
    o = o || {};
    await p.evaluate(([r, st, d]) => { window.GEO.calls = 0; window.GEO.results = r; window.GEO.status = st; window.GEO.delay = d; ['pickup-input', 'dropoff-input'].forEach(i => document.getElementById(i).value = ''); delete window.RIDE.pickupAddress; delete window.RIDE.dropoffAddress; delete window.RIDE.pickupLatLng; delete window.RIDE.dropoffLatLng; }, [results, o.status || 'OK', o.delay || 0]);
    await p.evaluate(([k, a]) => { (k === 'pickup' ? window.setPickup : window.setDropoff)(7.0462, 38.5110, a); }, [kind, addr]);
    const immediate = await p.evaluate((k) => document.getElementById(k + '-input').value, kind);
    if (o.meanwhile) await p.evaluate(o.meanwhile, kind);
    await p.waitForTimeout(o.delay ? o.delay + 200 : 150);
    const out = await p.evaluate((k) => ({ val: document.getElementById(k + '-input').value, calls: window.GEO.calls, state: (window.RIDE[k + 'Address'] || '') }), kind);
    out.immediate = immediate; return out;
  };
  const S = (await enc(7.0462, 38.5110)).slice(4) + ' Hawassa';     // what the app writes for this point when nothing better is known
  const SP = (await enc(7.0462, 38.5110)).slice(4);
  const town = [R('Hawassa, Ethiopia', ['locality', 'political'], 'Hawassa')];

  let r = await run('pickup', 'Yaliteseyeme Menigedi, Awasa', [R('Yaliteseyeme Menigedi, Awasa, Ethiopia'), ...town]);
  t('the unnamed road from your screenshot: a plus code with the town, NOT just "Hawassa"', r.val === S && /^[23456789CFGHJMPQRVWX]{4}\+[23456789CFGHJMPQRVWX]{3} Hawassa$/.test(r.val), JSON.stringify(r));
  t('... and it is already there before the map answers', r.immediate === S, r.immediate);
  r = await run('pickup', 'Yaliteseyeme Menigedi, Awas', [R('Yaliteseyeme Menigedi, Awasa'), ...town]);
  t('... also when the map data cuts it ("..., Awas")', r.val === S);
  r = await run('pickup', 'Unnamed Road, Awasa', [R('Unnamed Road, Awasa, Ethiopia'), R('Mary Joy Elder Center, Hawassa, Ethiopia', ['establishment', 'point_of_interest'], 'Mary Joy Elder Center'), ...town], { delay: 60 });
  t('a place nearby: "Name, code, Hawassa" like Google Maps', r.val === 'Mary Joy Elder Center, ' + SP + ', Hawassa' && r.immediate === S, JSON.stringify(r));
  r = await run('dropoff', 'Unnamed Road, Awasa', [R('Unnamed Road, Awasa'), R('Awasa Lake Road, Awasa, Ethiopia', ['route'], 'Awasa Lake Road'), ...town]);
  t('a named street counts as a name; a name that contains "Awasa" is not respelled', r.val === 'Awasa Lake Road, ' + SP + ', Hawassa', r.val);
  r = await run('pickup', 'Unnamed Road, Awasa', [R('Unnamed Road, Awasa'), R('Tabor Sub City, Awasa, Ethiopia', ['sublocality', 'political'], 'Tabor Sub City'), R('Awasa, Ethiopia', ['locality'], 'Awasa')]);
  t('a neighbourhood or sub-city is not used as a name (Google Maps does not either): plus code and town', r.val === S, r.val);
  r = await run('pickup', '3F2F+PH8, Hawassa, Ethiopia', [R('3F2F+PH8, Hawassa, Ethiopia', ['plus_code'])]);
  t('Google\'s own plus code answer is kept and tidied: "3F2F+PH8 Hawassa"', r.val === '3F2F+PH8 Hawassa', JSON.stringify(r));
  r = await run('pickup', '3F2F+PH8, Awasa, Ethiopia', [R('2GW6+8HH Awasa, Ethiopia', ['plus_code']), R('Mary Joy Elder Center, Awasa', ['establishment'], 'Mary Joy Elder Center'), R('Awasa, Ethiopia', ['locality'], 'Awasa')]);
  t('a place AND Google\'s plus code: "Mary Joy Elder Center, 2GW6+8HH, Hawassa" (your example)', r.val === 'Mary Joy Elder Center, 2GW6+8HH, Hawassa', r.val);
  r = await run('pickup', '3F2F+PH8, Hawassa, Ethiopia', [R('2GW6+8HH Awasa, Ethiopia', ['plus_code']), ...town]);
  t('only Google\'s plus code known: "2GW6+8HH Hawassa" (your example)', r.val === '2GW6+8HH Hawassa', r.val);
  r = await run('pickup', 'Your location', [R('Mary Joy Elder Center, Hawassa', ['establishment'], 'Mary Joy Elder Center'), ...town], { delay: 60 });
  t('"Your location": a plus code at once, then the place when one is found', r.immediate === S && r.val === 'Mary Joy Elder Center, ' + SP + ', Hawassa', JSON.stringify(r));
  r = await run('pickup', 'Your location', [], { status: 'OVER_QUERY_LIMIT' });
  t('the map lookup fails: the plus code still shows (it is worked out on the phone)', r.val === S, JSON.stringify(r));
  r = await run('pickup', 'Unnamed Road, Awasa', [R('Unnamed Road, Awasa'), R('Yaliteseyeme Menigedi, Awasa')]);
  t('every result unreadable and no town: plus code and Hawassa', r.val === S, r.val);
  r = await run('pickup', 'ያልተሰየመ መንገድ, አዋሳ', [R('ያልተሰየመ መንገድ'), ...town]);
  t('the Amharic-script "unnamed road" is replaced too', r.val === S, r.val);
  r = await run('pickup', 'Tabor Hotel, Hawassa', town);
  t('a normal address is not touched and the map is not even asked', r.val === 'Tabor Hotel, Hawassa' && r.calls === 0, JSON.stringify(r));
  r = await run('dropoff', 'Hawassa University, Ethiopia', town);
  t('... at the drop-off too (", Ethiopia" kept: it was the rider\'s own choice)', r.val === 'Hawassa University, Ethiopia' && r.calls === 0);
  r = await run('pickup', 'Unnamed Road, Awasa', [R('Mary Joy Elder Center, Hawassa', ['establishment'], 'Mary Joy Elder Center'), ...town], { delay: 80, meanwhile: () => { document.getElementById('pickup-input').value = 'My own typing'; } });
  t('the rider typed something else meanwhile: it is not overwritten', r.val === 'My own typing', JSON.stringify(r));
  r = await run('pickup', 'Unnamed Road, Awasa', [R('Mary Joy Elder Center, Hawassa', ['establishment'], 'Mary Joy Elder Center'), ...town], { delay: 80, meanwhile: () => { window.RIDE.pickupLatLng = { lat: 7.5, lng: 38.9 }; } });
  t('the point was moved meanwhile: the old answer is dropped', r.val === S && !/Mary Joy/.test(r.val), JSON.stringify(r));
  // no map at all
  await p.evaluate(() => { window.__g = window.google; delete window.google; });
  r = await run('pickup', 'Your location', []);
  t('no map library at all: the plus code and town still show', r.val === S, r.val);
  await p.evaluate(() => { window.google = window.__g; });
  t('no page errors', errs.length === 0, errs.join('|'));
  await b.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
