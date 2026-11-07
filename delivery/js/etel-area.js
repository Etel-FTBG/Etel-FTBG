/* ETEL service area: finds the city the person is in and keeps every search (map places, shops, items, drivers) inside it.
   One small file used by Ride, Driver, Courier, Customer, Shop and the Agent portal. The server also enforces the rule, so this
   file is only the friendly side: it detects the city, shows it, narrows the map search, and tells the person plainly when
   they are outside the cities we serve. Works with the old release bundles because it patches the Maps search globally. */
(function () {
  'use strict';
  if (window.EtelArea) return;
  var KEY = 'etel_area_v1', LKEY = 'etel_area_list_v1';
  var script = document.currentScript;
  var noChip = script && script.getAttribute('data-chip') === 'off';
  var state = { area: null, areas: [], enforced: true, outside: false, nearest: null, ready: false, manual: false };
  var listeners = [];

  function lang() {
    var l = '';
    try { l = localStorage.getItem('etel_lang') || localStorage.getItem('lang') || localStorage.getItem('ride_lang') || ''; } catch (e) {}
    if (!l) l = document.documentElement.lang || '';
    return String(l).toLowerCase().indexOf('am') === 0 ? 'am' : 'en';
  }
  var T = {
    chip: { en: 'Your city', am: '\u12a8\u1270\u121b\u12ce' },
    choose: { en: 'Choose your city', am: '\u12a8\u1270\u121b\u12ce\u1295 \u12ed\u121d\u1228\u1321' },
    detect: { en: 'Use my location', am: '\u12a0\u12ab\u1263\u1262\u12ec\u1295 \u1270\u1320\u1240\u121d' },
    hint: { en: 'You will only see services in this city.', am: '\u1260\u12da\u1205 \u12a8\u1270\u121b \u12eb\u1209 \u12a0\u1308\u120d\u130d\u120e\u1276\u127d\u1295 \u1265\u1273 \u12eb\u12eb\u1209\u1362' },
    notyet: { en: 'Not available here yet', am: '\u12a5\u12da\u1205 \u1308\u1293 \u12a0\u12ed\u1308\u129d\u121d' },
    close: { en: 'Close', am: '\u12dd\u1308\u1275' },
    detecting: { en: 'Finding your city\u2026', am: '\u12a8\u1270\u121b\u12ce\u1295 \u12a5\u12eb\u1348\u1208\u1308 \u1290\u12cd\u2026' }
  };
  function tx(k) { return T[k][lang()]; }
  function nm(a) { return a ? (lang() === 'am' && a.name_am ? a.name_am : a.name) : ''; }

  function apiBase() {
    try { if (window.ETEL && ETEL.api) return ETEL.api; } catch (e) {}
    var p = location.pathname, i = p.indexOf('/delivery/');
    return (i >= 0 ? p.slice(0, i) : '') + '/delivery/api/boot.php';
  }
  var realFetch = window.fetch ? window.fetch.bind(window) : null;
  function getJSON(qs) {
    if (!realFetch) return Promise.reject(new Error('no fetch'));
    return realFetch(apiBase() + '?action=get_service_areas' + (qs ? '&' + qs : '')).then(function (r) { return r.json(); })
      .then(function (j) { if (!j || !j.success) throw new Error('bad'); return j.data; });
  }
  function load(k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } }
  function save(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  function byCode(c) { for (var i = 0; i < state.areas.length; i++) if (state.areas[i].code === c) return state.areas[i]; return null; }

  function notify() { for (var i = 0; i < listeners.length; i++) { try { listeners[i](state.area, state); } catch (e) {} } try { window.dispatchEvent(new CustomEvent('etel-area-change', { detail: state })); } catch (e) {} paint(); }

  /* ---- detect ---- */
  function applyData(d, fromGps) {
    state.areas = d.areas || state.areas; state.enforced = d.enforced !== false;
    if (state.areas.length) save(LKEY, { t: Date.now(), areas: state.areas, enforced: state.enforced });
    if (fromGps) {
      state.outside = !!d.outside; state.nearest = d.nearest || null;
      if (d.current) { state.area = d.current; state.manual = false; save(KEY, { code: d.current.code, manual: false, t: Date.now() }); }
      else state.area = null;
    }
  }
  function restore() {
    var l = load(LKEY); if (l && l.areas) { state.areas = l.areas; state.enforced = l.enforced !== false; }
    var s = load(KEY); if (s && s.code) { var a = byCode(s.code); if (a) { state.area = a; state.manual = !!s.manual; } }
  }
  function detect(force) {
    return new Promise(function (resolve) {
      var done = function () { state.ready = true; notify(); resolve(state.area); };
      var s = load(KEY);
      if (!force && s && s.manual && state.area) { getJSON().then(function (d) { applyData(d, false); state.area = byCode(s.code) || state.area; }).catch(function () {}).then(done); return; }
      if (!navigator.geolocation) { getJSON().then(function (d) { applyData(d, false); }).catch(function () {}).then(done); return; }
      navigator.geolocation.getCurrentPosition(function (p) {
        getJSON('lat=' + p.coords.latitude + '&lng=' + p.coords.longitude).then(function (d) { applyData(d, true); }).catch(function () {}).then(done);
      }, function () {
        // no permission or no signal: keep what we knew, or let the person choose
        getJSON().then(function (d) { applyData(d, false); }).catch(function () {}).then(function () { if (!state.area && state.areas.length) state.needChoice = true; done(); });
      }, { enableHighAccuracy: false, timeout: 9000, maximumAge: 300000 });
    });
  }
  function choose(code) {
    var a = byCode(code); if (!a) return;
    state.area = a; state.manual = true; state.outside = false; state.needChoice = false;
    save(KEY, { code: a.code, manual: true, t: Date.now() });
    closeSheet(); notify();
    // the lists on the page were loaded for the old city: reload so everything matches the new one
    setTimeout(function () { try { location.reload(); } catch (e) {} }, 250);
  }

  /* ---- Google Places search narrowed to the city ---- */
  function boundsFor(a) {
    if (!a || !window.google || !google.maps || !google.maps.LatLngBounds) return null;
    var dLat = a.radius_km / 111, dLng = a.radius_km / (111 * Math.max(0.2, Math.cos(a.lat * Math.PI / 180)));
    return new google.maps.LatLngBounds({ lat: a.lat - dLat, lng: a.lng - dLng }, { lat: a.lat + dLat, lng: a.lng + dLng });
  }
  var patched = false;
  function patchPlaces() {
    if (patched || !window.google || !google.maps || !google.maps.places || !google.maps.places.Autocomplete) return;
    var Orig = google.maps.places.Autocomplete;
    function Wrapped(input, opts) {
      opts = opts || {};
      try {
        var b = state.enforced ? boundsFor(state.area) : null;
        if (b) { opts.bounds = b; opts.strictBounds = true; }
        if (!opts.componentRestrictions) opts.componentRestrictions = { country: 'ET' };
      } catch (e) {}
      var ac = new Orig(input, opts);
      try { ac.__etelWrapped = true; listeners.push(function () { var nb = state.enforced ? boundsFor(state.area) : null; if (nb && ac.setBounds) { ac.setBounds(nb); if (ac.setOptions) ac.setOptions({ strictBounds: true }); } }); } catch (e) {}
      return ac;
    }
    Wrapped.prototype = Orig.prototype;
    try { google.maps.places.Autocomplete = Wrapped; patched = true; } catch (e) { /* read-only namespace: the server still enforces the city */ }
  }
  var pollN = 0;
  (function poll() { patchPlaces(); if (!patched && pollN++ < 400) setTimeout(poll, 250); })();

  /* ---- listing requests get the city automatically ---- */
  var SCOPED = /[?&]action=(customer_get_items|get_items|get_zones)(&|$)/;
  if (realFetch) {
    window.fetch = function (input, init) {
      try {
        var url = typeof input === 'string' ? input : (input && input.url) || '';
        if (state.area && state.enforced && SCOPED.test(url) && url.indexOf('area=') < 0 && typeof input === 'string') {
          input = url + (url.indexOf('?') < 0 ? '?' : '&') + 'area=' + encodeURIComponent(state.area.code);
        }
      } catch (e) {}
      return realFetch(input, init);
    };
  }

  /* ---- the small city chip and the chooser ---- */
  var chip = null, sheet = null;
  function css() {
    if (document.getElementById('etel-area-css')) return;
    var s = document.createElement('style'); s.id = 'etel-area-css';
    s.textContent = '.eta-chip{position:fixed;z-index:99990;top:calc(env(safe-area-inset-top,0px) + 8px);left:50%;transform:translateX(-50%);display:inline-flex;align-items:center;gap:6px;max-width:70vw;padding:6px 12px 6px 9px;border-radius:999px;border:1px solid rgba(201,162,39,.55);background:rgba(10,20,40,.88);color:#f6e7b0;font:600 12.5px/1 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;letter-spacing:.2px;box-shadow:0 6px 20px rgba(0,0,0,.28);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);cursor:pointer;min-height:32px}' +
      '.eta-chip svg{width:14px;height:14px;flex:none}.eta-chip span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.eta-chip.warn{border-color:#e0795a;color:#ffd9cf}' +
      '.eta-ov{position:fixed;inset:0;z-index:99995;background:rgba(5,10,22,.6);display:flex;align-items:flex-end;justify-content:center}' +
      '.eta-sh{width:100%;max-width:460px;background:#0f1c33;color:#eef2fb;border-radius:20px 20px 0 0;padding:18px 18px calc(env(safe-area-inset-bottom,0px) + 18px);font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;border-top:2px solid #c9a227;box-shadow:0 -12px 40px rgba(0,0,0,.4)}' +
      '.eta-sh h3{margin:0 0 4px;font-size:17px;color:#f6e7b0}.eta-sh p{margin:0 0 12px;color:#aab6cf;font-size:13px}' +
      '.eta-row{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;text-align:left;margin:0 0 8px;padding:13px 14px;min-height:48px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.04);color:inherit;font:600 15px system-ui,sans-serif;cursor:pointer}' +
      '.eta-row.on{border-color:#c9a227;background:rgba(201,162,39,.14)}.eta-row small{color:#aab6cf;font-weight:500}' +
      '.eta-btn{width:100%;min-height:46px;margin-top:4px;border-radius:12px;border:0;background:#c9a227;color:#1a1405;font:700 15px system-ui,sans-serif;cursor:pointer}' +
      '.eta-btn.ghost{background:transparent;color:#aab6cf;border:1px solid rgba(255,255,255,.18)}.eta-note{margin:0 0 12px;padding:10px 12px;border-radius:10px;background:rgba(224,121,90,.14);color:#ffd9cf;font-size:13px}' +
      'html[data-theme="light"] .eta-sh,body.light .eta-sh{background:#fff;color:#12203a}html[data-theme="light"] .eta-sh h3{color:#7a5a00}html[data-theme="light"] .eta-row{border-color:#d7dde8;background:#f5f7fb}';
    (document.head || document.documentElement).appendChild(s);
  }
  var PIN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/></svg>';
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function paint() {
    if (noChip || !document.body) return;
    css();
    if (!chip) {
      chip = document.createElement('button'); chip.type = 'button'; chip.className = 'eta-chip'; chip.setAttribute('aria-haspopup', 'dialog');
      chip.addEventListener('click', openSheet); document.body.appendChild(chip);
    }
    if (!state.ready) { chip.innerHTML = PIN + '<span>' + esc(tx('detecting')) + '</span>'; return; }
    var warn = !state.area && (state.outside || state.needChoice);
    chip.className = 'eta-chip' + (warn ? ' warn' : '');
    chip.setAttribute('aria-label', tx('chip') + ': ' + (state.area ? nm(state.area) : tx('choose')));
    chip.innerHTML = PIN + '<span>' + esc(state.area ? nm(state.area) : (state.outside ? tx('notyet') : tx('choose'))) + '</span>';
    if (!state.area && (state.outside || state.needChoice) && !state.shownOnce && state.areas.length) { state.shownOnce = true; openSheet(); }
  }
  function closeSheet() { if (sheet && sheet.parentNode) sheet.parentNode.removeChild(sheet); sheet = null; }
  function openSheet() {
    if (sheet || !document.body) return;
    css();
    var rows = state.areas.map(function (a) {
      return '<button type="button" class="eta-row' + (state.area && state.area.code === a.code ? ' on' : '') + '" data-code="' + esc(a.code) + '"><span>' + esc(nm(a)) + '</span><small>' + (state.area && state.area.code === a.code ? '\u2713' : '') + '</small></button>';
    }).join('');
    var note = (!state.area && state.outside && state.enforced) ? '<div class="eta-note">' + esc(tx('notyet')) + '</div>' : '';
    sheet = document.createElement('div'); sheet.className = 'eta-ov'; sheet.setAttribute('role', 'dialog'); sheet.setAttribute('aria-modal', 'true');
    sheet.innerHTML = '<div class="eta-sh"><h3>' + esc(tx('choose')) + '</h3><p>' + esc(tx('hint')) + '</p>' + note + rows +
      '<button type="button" class="eta-btn" data-detect="1">' + esc(tx('detect')) + '</button><button type="button" class="eta-btn ghost" data-close="1">' + esc(tx('close')) + '</button></div>';
    sheet.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('[data-code],[data-detect],[data-close]') : null;
      if (e.target === sheet || (t && t.getAttribute('data-close'))) { closeSheet(); return; }
      if (!t) return;
      if (t.getAttribute('data-code')) choose(t.getAttribute('data-code'));
      else if (t.getAttribute('data-detect')) { try { localStorage.removeItem(KEY); } catch (er) {} closeSheet(); state.shownOnce = false; state.ready = false; paint(); detect(true); }
    });
    document.body.appendChild(sheet);
  }

  /* ---- public ---- */
  window.EtelArea = {
    get: function () { return state.area; },
    state: function () { return state; },
    code: function () { return state.area ? state.area.code : ''; },
    ready: null,
    onChange: function (fn) { listeners.push(fn); },
    detect: function () { return detect(true); },
    choose: choose,
    open: openSheet
  };
  restore();
  window.EtelArea.ready = detect(false);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', paint); else paint();
})();
