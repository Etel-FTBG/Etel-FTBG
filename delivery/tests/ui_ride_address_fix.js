// Ride page: readable pickup / drop-off names (js/ride-address-fix.js, the real file). Needs Playwright (node). No server: the map geocoder is a fake.
//   node delivery/tests/ui_ride_address_fix.js
const { chromium } = require('playwright'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const SRC = fs.readFileSync(path.join(__dirname, '../js/ride-address-fix.js'), 'utf8');
const R = (a, types) => ({ formatted_address: a, types: types || ['route'] });
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
  await p.addScriptTag({ content: SRC });
  await p.waitForTimeout(100);
  const run = async (kind, addr, results, o) => {
    o = o || {};
    await p.evaluate(([r, st, d]) => { window.GEO.calls = 0; window.GEO.results = r; window.GEO.status = st; window.GEO.delay = d; document.getElementById('pickup-input').value = ''; document.getElementById('dropoff-input').value = ''; delete window.RIDE.pickupAddress; delete window.RIDE.dropoffAddress; delete window.RIDE.pickupLatLng; delete window.RIDE.dropoffLatLng; }, [results, o.status || 'OK', o.delay || 0]);
    await p.evaluate(([k, a]) => { (k === 'pickup' ? window.setPickup : window.setDropoff)(7.06, 38.48, a); }, [kind, addr]);
    if (o.meanwhile) await p.evaluate(o.meanwhile, kind);
    await p.waitForTimeout(150);
    return p.evaluate((k) => ({ val: document.getElementById(k + '-input').value, calls: window.GEO.calls, state: (window.RIDE[k + 'Address'] || '') }), kind);
  };
  const good = [R('Tabor Sub City, Awasa, Ethiopia', ['sublocality']), R('Awasa, Ethiopia', ['locality'])];

  let r = await run('pickup', '3F2F+PH8, Hawassa, Ethiopia', [R('3F2F+PH8, Hawassa, Ethiopia', ['plus_code']), R('Tabor Hotel, Hawassa, Ethiopia', ['establishment'])]);
  t('plus code: still replaced by the first readable result (as before), tidied', r.val === 'Tabor Hotel, Hawassa', JSON.stringify(r));
  r = await run('pickup', 'Yaliteseyeme Menigedi, Awasa', [R('Yaliteseyeme Menigedi, Awasa, Ethiopia'), ...good]);
  t('the Latin-letter Amharic "unnamed road" (your screenshot) becomes the neighbourhood, tidied: "Tabor Sub City, Hawassa"', r.val === 'Tabor Sub City, Hawassa' && r.calls === 1, JSON.stringify(r));
  r = await run('pickup', 'Yaliteseyeme Menigedi, Awas', [R('Yaliteseyeme Menigedi, Awasa'), ...good]);
  t('... also when the map data cuts it ("..., Awas")', r.val === 'Tabor Sub City, Hawassa', JSON.stringify(r));
  r = await run('dropoff', 'Unnamed Road, Awasa', [R('Unnamed Road, Awasa, Ethiopia'), R('Piassa, Awasa, Ethiopia', ['neighborhood']), R('Awasa, Ethiopia', ['locality'])]);
  t('English "Unnamed Road" at the drop-off: replaced by the next readable place', r.val === 'Piassa, Hawassa', JSON.stringify(r));
  r = await run('pickup', 'ያልተሰየመ መንገድ, አዋሳ', [R('ያልተሰየመ መንገድ, አዋሳ'), R('Mehal Ketema, Awasa', ['sublocality'])]);
  t('the Amharic-script "unnamed road" is replaced too', r.val === 'Mehal Ketema, Hawassa', JSON.stringify(r));
  r = await run('pickup', 'Your location', good);
  t('the bare "Your location" label is replaced by the real place name', r.val === 'Tabor Sub City, Hawassa', JSON.stringify(r));
  r = await run('pickup', 'Your location', [], { status: 'ZERO_RESULTS' });
  t('"Your location" stays when nothing is found', r.val === 'Your location');
  r = await run('pickup', 'Your location', [], { status: 'OVER_QUERY_LIMIT' });
  t('... or when the lookup fails', r.val === 'Your location');
  r = await run('pickup', 'Unnamed Road, Awasa', [R('Unnamed Road, Awasa'), R('3F2F+PH8, Awasa', ['plus_code']), R('Yaliteseyeme Menigedi, Awasa')]);
  t('every result unreadable: the original text is left alone', r.val === 'Unnamed Road, Awasa', JSON.stringify(r));
  r = await run('pickup', 'Tabor Hotel, Hawassa', good);
  t('a normal address is not touched and the map is not even asked', r.val === 'Tabor Hotel, Hawassa' && r.calls === 0, JSON.stringify(r));
  r = await run('dropoff', 'Hawassa University, Ethiopia', good);
  t('... at the drop-off too (", Ethiopia" kept: it was the rider\'s own choice)', r.val === 'Hawassa University, Ethiopia' && r.calls === 0);
  r = await run('pickup', 'Unnamed Road, Awasa', [R('Unnamed Road, Awasa'), ...good], { delay: 80, meanwhile: () => { document.getElementById('pickup-input').value = 'My own typing'; } });
  t('the rider typed something else meanwhile: it is not overwritten', r.val === 'My own typing', JSON.stringify(r));
  r = await run('pickup', 'Unnamed Road, Awasa', [R('Unnamed Road, Awasa'), ...good], { delay: 80, meanwhile: () => { window.RIDE.pickupLatLng = { lat: 7.5, lng: 38.9 }; } });
  t('the point was moved meanwhile: the old answer is dropped', /Unnamed|Yalit/.test(r.val) && r.state === '', JSON.stringify(r));
  r = await run('pickup', 'Unnamed Road, Awasa', [R('Unnamed Road, Awasa'), R('Gebeya Mehal, Awas', ['neighborhood'])]);
  t('"Awas" written short in the map data is tidied to "Hawassa" as well (only the town part)', r.val === 'Gebeya Mehal, Hawassa', JSON.stringify(r));
  r = await run('pickup', 'Unnamed Road, Awasa', [R('Unnamed Road, Awasa'), R('Awasa Lake Road, Awasa, Ethiopia', ['route'])]);
  t('a named street is a good answer (the first readable one wins)', r.val === 'Awasa Lake Road, Hawassa' && true, JSON.stringify(r));
  t('no page errors', errs.length === 0, errs.join('|'));
  await b.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
