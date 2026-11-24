/* ETEL Courier - pickup and dropoff distances with Navigate buttons on every job card, BEFORE accepting (additive script, no bundle release).
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
      '.cjn-n{font-weight:700;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.cjn-k{font-size:13px;color:#E5C76B;font-weight:800}' +
      '.cjn-go{flex:none;display:inline-flex;align-items:center;gap:6px;min-height:40px;padding:8px 14px;border-radius:999px;background:#D4AF37;color:#1a1200;font-weight:800;font-size:13px;text-decoration:none;border:0}' +
      '.cjn-go:focus-visible{outline:2px solid #fff;outline-offset:2px}.cjn-na{font-size:12px;opacity:.6}' +
      '.cjn-sum{padding:8px 12px;font-size:12.5px;border-top:1px solid rgba(128,128,128,.25);background:rgba(212,175,55,.1);font-weight:700}';
    document.head.appendChild(s);
  }

  function row(title, name, kmText, pt) {
    return '<div class="cjn-row"><div class="cjn-l"><div class="cjn-t">' + esc(T(title)) + '</div><div class="cjn-n">' + esc(name || '-') + '</div>' +
      (kmText ? '<div class="cjn-k">' + esc(kmText) + '</div>' : '') + '</div>' +
      (pt ? '<a class="cjn-go" href="' + esc(mapUrl(pt)) + '" target="_blank" rel="noopener" data-cjn="' + (title === 'Pickup' ? 'pickup' : 'drop') + '">&#129517; ' + esc(T('Navigate')) + '</a>' : '<span class="cjn-na">' + esc(T('Pin not set')) + '</span>') + '</div>';
  }

  function box(g) {
    var a = g.pickup_km != null ? km(g.pickup_km) + ' ' + T('km from you') : '';
    var b = g.drop_km != null ? km(g.drop_km) + ' ' + T('km from pickup') : '';
    var sum = (g.pickup_km != null && g.drop_km != null) ? '<div class="cjn-sum">' + esc(T('Whole trip')) + ': ' + km(g.pickup_km + g.drop_km) + ' ' + esc(T('km')) + '</div>' : '';
    return '<div class="cjn-box">' + row('Pickup', g.pickup_label, a, g.pickup) + row('Dropoff', g.drop_label, b, g.drop) + sum + '</div>';
  }

  function cards() {
    var out = [], list = document.querySelectorAll('#jobs-content .job-card');
    for (var i = 0; i < list.length; i++) {
      var b = list[i].querySelector('.job-actions .btn-primary'), m = b && /acceptJob\((\d+)/.exec(b.getAttribute('onclick') || '');
      if (m) out.push({ el: list[i], id: parseInt(m[1], 10) });
    }
    return out;
  }

  function paint() {
    css();
    cards().forEach(function (c) {
      var g = CACHE[c.id], old = c.el.querySelector('.cjn-box');
      if (!g || old) return;
      var anchor = c.el.querySelector('.job-fee') || c.el.querySelector('.job-actions');
      if (anchor) anchor.insertAdjacentHTML('beforebegin', box(g));
      var old2 = c.el.querySelector('.dist-badge'); if (old2) old2.style.display = 'none';   // the old zone-based guess would disagree with the exact km
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
    paint(); ask();
    setInterval(function () { CACHE = {}; ask(); }, 60000);   // positions and km move: ask again each minute
  })();
})();
