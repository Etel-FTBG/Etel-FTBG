/* ETEL Courier - pickup and dropoff distances, real addresses and Navigate buttons on the job cards (additive script, no bundle release).
   Jobs tab: before accepting. Active tab: the same two places with the km that still matter (no second Navigate button: the card has its own).
   Each place shows its address like the Ride system: a plus code with the town, e.g. "2FQM+RHF, Hawassa, Ethiopia" (worked out on the phone from the map pin).
   Asks api action courier_job_geo for the jobs on the screen: km from the courier to the pickup, km from the pickup to the dropoff, and the two map points.
   Navigate opens the phone's map app with that place. The customer's name and phone are never asked for. Fails quietly: with no GPS the pickup km is
   left out, with no map pin the Navigate button is left out. English / Amharic (etel_lang). */
(function () {
  'use strict';
  if (window.__etelJobNav) return;
  window.__etelJobNav = true;
  var AM = { 'Pickup': 'መውሰጃ', 'Dropoff': 'ማድረሻ', 'Navigate': 'መንገድ አሳይ', 'km from you': 'ኪሜ ከእርስዎ', 'km from pickup': 'ኪሜ ከመውሰጃ', 'Whole trip': 'ጠቅላላ ጉዞ', 'km': 'ኪሜ', 'Pin not set': 'ካርታ አልተቀመጠም' };
  var CACHE = {}, MINE = null, BUSY = false, LASTASK = 0;

  function lang() { try { return localStorage.getItem('etel_lang') === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
  function T(s) { return lang() === 'am' && AM[s] ? AM[s] : s; }
  function token() { try { return (window.Auth && Auth.getToken) ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); } catch (e) { return ''; } }
  function apiUrl() { return (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php'; }
  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function km(n) { return (Math.round(n * 10) / 10).toFixed(1); }
  function mapUrl(p) { return 'https://www.google.com/maps/dir/?api=1&destination=' + p.lat + ',' + p.lng + '&travelmode=driving'; }

  function css() {
    if (document.getElementById('cjn-css')) return;
    var s = document.createElement('style'); s.id = 'cjn-css';
    s.textContent =
      '.cjn-box{margin:10px 0;border:1px solid rgba(212,175,55,.4);border-radius:14px;overflow:hidden;font-variant-numeric:lining-nums tabular-nums}' +
      '.cjn-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px}.cjn-row+.cjn-row{border-top:1px solid rgba(128,128,128,.25)}' +
      '.cjn-l{min-width:0;flex:1}.cjn-t{font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;opacity:.65}' +
      '.cjn-n{font-weight:700;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.cjn-a{font-size:12.5px;font-weight:600;opacity:.9;letter-spacing:.02em;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.cjn-s{font-size:11.5px;opacity:.7;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.cjn-k{font-size:13px;color:#E5C76B;font-weight:800}' +
      '.cjn-go{flex:none;display:inline-flex;align-items:center;gap:6px;min-height:40px;padding:8px 14px;border-radius:999px;background:#D4AF37;color:#1a1200;font-weight:800;font-size:13px;text-decoration:none;border:0}' +
      '.cjn-go:focus-visible{outline:2px solid #fff;outline-offset:2px}.cjn-na{font-size:12px;opacity:.6}' +
      '.cjn-sum{padding:8px 12px;font-size:12.5px;border-top:1px solid rgba(128,128,128,.25);background:rgba(212,175,55,.1);font-weight:700}';
    document.head.appendChild(s);
  }

  // Open Location Code (plus code), 11 digits: 8 + "+" + 3. Same maths as the Ride system (js/ride-address-fix.js).
  var ALPHA = '23456789CFGHJMPQRVWX';
  function olc(lat, lng) {
    lat = Math.min(90, Math.max(-90, lat));
    lng = ((lng + 180) % 360 + 360) % 360 - 180;
    var latVal = Math.floor(Math.round((lat + 90) * 40000 * 1e6) / 1e6);
    var lngVal = Math.floor(Math.round((lng + 180) * 32000 * 1e6) / 1e6);
    if (latVal >= 180 * 40000) latVal = 180 * 40000 - 1;
    var code = '';
    code = ALPHA.charAt((latVal % 5) * 4 + (lngVal % 4)) + code;
    latVal = Math.floor(latVal / 5); lngVal = Math.floor(lngVal / 4);
    for (var i = 0; i < 5; i++) {
      code = ALPHA.charAt(lngVal % 20) + code;
      code = ALPHA.charAt(latVal % 20) + code;
      latVal = Math.floor(latVal / 20); lngVal = Math.floor(lngVal / 20);
    }
    return code.slice(0, 8) + '+' + code.slice(8);
  }
  window.__etelJobNavPlus = olc;   // for the tests
  // In and around Hawassa the first 4 digits are the same for everyone, so Google drops them: "2FQM+RHF, Hawassa, Ethiopia".
  function addr(pt) {
    if (!pt || !isFinite(pt.lat) || !isFinite(pt.lng)) return '';
    var c = olc(pt.lat, pt.lng), near = Math.abs(pt.lat - 7.06) < 0.4 && Math.abs(pt.lng - 38.48) < 0.4;
    return near ? c.slice(4) + ', Hawassa, Ethiopia' : c + ', Ethiopia';
  }
  function hav(a, b) {
    var R = 6371, d2r = Math.PI / 180, dLat = (b.lat - a.lat) * d2r, dLng = (b.lng - a.lng) * d2r;
    var x = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(a.lat * d2r) * Math.cos(b.lat * d2r) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return R * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
  }

  // nav: show the Navigate button (Jobs tab). sub: the shop's own address text, when there is one.
  function row(title, name, kmText, pt, nav, sub) {
    var go = '';
    if (nav) go = pt ? '<a class="cjn-go" href="' + esc(mapUrl(pt)) + '" target="_blank" rel="noopener" data-cjn="' + (title === 'Pickup' ? 'pickup' : 'drop') + '">&#129517; ' + esc(T('Navigate')) + '</a>' : '<span class="cjn-na">' + esc(T('Pin not set')) + '</span>';
    var ad = addr(pt);
    return '<div class="cjn-row"><div class="cjn-l"><div class="cjn-t">' + esc(T(title)) + '</div><div class="cjn-n">' + esc(name || '-') + '</div>' +
      (sub ? '<div class="cjn-s">' + esc(sub) + '</div>' : '') +
      (ad ? '<div class="cjn-a" data-cjn-addr="1">&#128205; ' + esc(ad) + '</div>' : '') +
      (kmText ? '<div class="cjn-k">' + esc(kmText) + '</div>' : '') + '</div>' + go + '</div>';
  }

  // st = the order's status for a card on the Active tab ('' on the Jobs tab).
  function box(g, st) {
    var active = !!st, gone = st === 'picked_up' || st === 'in_transit';   // after pickup only the way to the customer matters
    var a = (!gone && g.pickup_km != null) ? km(g.pickup_km) + ' ' + T('km from you') : '';
    var b = g.drop_km != null ? km(g.drop_km) + ' ' + T('km from pickup') : '';
    if (gone) { b = (MINE && g.drop) ? km(hav(MINE, g.drop) * 1.3) + ' ' + T('km from you') : b; }
    var sum = (!gone && g.pickup_km != null && g.drop_km != null) ? '<div class="cjn-sum">' + esc(T('Whole trip')) + ': ' + km(g.pickup_km + g.drop_km) + ' ' + esc(T('km')) + '</div>' : '';
    var sub = (g.pickup_address && g.pickup_address !== g.pickup_label) ? g.pickup_address : '';
    return '<div class="cjn-box">' + row('Pickup', g.pickup_label, a, g.pickup, !active, sub) + row('Dropoff', g.drop_label, b, g.drop, !active, '') + sum + '</div>';
  }

  function cards() {
    var out = [], list = document.querySelectorAll('#jobs-content .job-card');
    for (var i = 0; i < list.length; i++) {
      var b = list[i].querySelector('.job-actions .btn-primary'), m = b && /acceptJob\((\d+)/.exec(b.getAttribute('onclick') || '');
      if (m) out.push({ el: list[i], id: parseInt(m[1], 10), st: '' });
    }
    // Active tab: the card has the order code, the order list (window._activeOrders) has its id and status.
    var act = document.querySelectorAll('#active-content .job-card.accepted'), mine = window._activeOrders || [];
    for (var k = 0; k < act.length; k++) {
      var codeEl = act[k].querySelector('.job-id'), code = codeEl ? codeEl.textContent.replace(/\s+/g, '') : '';
      for (var q = 0; q < mine.length; q++) {
        if (mine[q] && String(mine[q].order_code || '').replace(/\s+/g, '') === code && mine[q].id) { out.push({ el: act[k], id: parseInt(mine[q].id, 10), st: String(mine[q].status || 'assigned') }); break; }
      }
    }
    return out;
  }

  function paint() {
    css();
    cards().forEach(function (c) {
      var g = CACHE[c.id], old = c.el.querySelector('.cjn-box');
      if (!g || old) return;
      var anchor = c.el.querySelector('.job-fee') || c.el.querySelector('.job-actions');
      if (anchor) anchor.insertAdjacentHTML('beforebegin', box(g, c.st));
      var old2 = c.el.querySelector('.dist-badge'); if (old2) old2.style.display = 'none';   // the old zone-based guess would disagree with the exact km
      if (c.st) {   // Active tab: the box now says Pickup and Dropoff, so the two plain lines would repeat it
        var lines = c.el.querySelectorAll('.job-info > .job-address');
        for (var i = 0; i < lines.length && i < 2; i++) { if (/^\s*.{0,4}\s*(Pickup|Dropoff):/.test(lines[i].textContent || '')) lines[i].style.display = 'none'; }
      }
    });
  }

  function ask() {
    var need = cards().filter(function (c) { return !CACHE[c.id]; }).map(function (c) { return c.id; });
    if (!need.length || BUSY || !token()) return;
    if (Date.now() - LASTASK < 1500) return;
    BUSY = true; LASTASK = Date.now();
    var body = { action: 'courier_job_geo', order_ids: need };
    if (MINE) { body.lat = MINE.lat; body.lng = MINE.lng; }
    fetch(apiUrl(), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); })
      .then(function (j) { var jobs = j && j.success && j.data && j.data.jobs; if (jobs) { need.forEach(function (id) { CACHE[id] = jobs[id] || { pickup: null, drop: null }; }); } })
      .catch(function () { /* quiet */ })
      .then(function () { BUSY = false; paint(); });
  }

  function whereAmI() {
    if (!navigator.geolocation) return;
    var ok = function (p) { var first = !MINE; MINE = { lat: p.coords.latitude, lng: p.coords.longitude }; if (first) { CACHE = {}; ask(); } };
    try { navigator.geolocation.getCurrentPosition(ok, function () {}, { maximumAge: 60000, timeout: 8000 }); } catch (e) {}
  }

  var tries = 0;
  (function wait() {
    var host = document.getElementById('jobs-content');
    if (!host) { if (tries++ < 100) return setTimeout(wait, 200); return; }
    whereAmI();
    if (window.MutationObserver) new MutationObserver(function () { paint(); ask(); }).observe(host, { childList: true });
    var act = document.getElementById('active-content');
    if (act && window.MutationObserver) new MutationObserver(function () { paint(); ask(); }).observe(act, { childList: true });
    paint(); ask();
    setInterval(function () { CACHE = {}; ask(); }, 60000);   // positions and km move: ask again each minute
  })();
})();
