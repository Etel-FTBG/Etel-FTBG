/* ETEL Customer - the live order map (additive script; customer.js calls etelTrackLive / etelTrackReset when this file is loaded).
   Shows every step on the map like the courier and driver apps: the shop pin, your home pin, the courier's vehicle moving smoothly, the road route of the
   CURRENT step (courier to shop, then courier to you), a LIVE badge, "updated Ns ago", km and minutes left, and a recenter button.
   Roads come from Google Directions; if that is not available a straight line is drawn and km are estimated. Never shows a courier pin that went stale. */
(function () {
  'use strict';
  var AM = { 'LIVE': 'ቀጥታ', 'Waiting for the courier location': 'የአቅራቢው ቦታ በመጠበቅ ላይ', 'updated': 'ተዘምኗል', 's ago': ' ሰከንድ በፊት', 'Heading to the shop': 'ወደ ሱቁ እየሄደ ነው',
    'Waiting at the shop': 'በሱቁ እየጠበቀ ነው', 'On the way to you': 'ወደ እርስዎ እየመጣ ነው', 'Delivered': 'ደርሷል', 'Shop': 'ሱቅ', 'Pickup': 'መውሰጃ', 'You': 'እርስዎ', 'to the shop': 'እስከ ሱቁ',
    'to you': 'እስከ እርስዎ', 'to pickup': 'እስከ መውሰጃ', 'min': 'ደቂቃ', 'km': 'ኪሜ', 'Show everything': 'ሁሉንም አሳይ', 'Preparing your order': 'ትዕዛዝዎ እየተዘጋጀ ነው' };
  var PRE = { pending: 1, assigned: 1, preparing: 1, accepted: 1 }, POST = { picked_up: 1, in_transit: 1 };
  var S = null;

  function lang() { try { return localStorage.getItem('etel_lang') === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
  function T(s) { return lang() === 'am' && AM[s] ? AM[s] : s; }
  function pt(lat, lng) { var a = parseFloat(lat), b = parseFloat(lng); return (isFinite(a) && isFinite(b) && (a || b)) ? { lat: a, lng: b } : null; }
  function hav(a, b) { var R = 6371, r = Math.PI / 180, dl = (b.lat - a.lat) * r, dn = (b.lng - a.lng) * r, x = Math.sin(dl / 2) * Math.sin(dl / 2) + Math.cos(a.lat * r) * Math.cos(b.lat * r) * Math.sin(dn / 2) * Math.sin(dn / 2); return R * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x)); }
  function key(p) { return p.lat.toFixed(4) + ',' + p.lng.toFixed(4); }
  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

  function css() {
    if (document.getElementById('clm-css')) return;
    var s = document.createElement('style'); s.id = 'clm-css';
    s.textContent =
      '.clm-live{position:absolute;left:10px;top:10px;z-index:5;display:flex;align-items:center;gap:6px;padding:6px 11px;border-radius:999px;background:rgba(20,20,26,.88);color:#fff;font:700 12px system-ui,sans-serif;pointer-events:none}' +
      '.clm-dot{width:9px;height:9px;border-radius:50%;background:#22c55e;animation:clmp 1.4s infinite}.clm-live.off .clm-dot{background:#9ca3af;animation:none}' +
      '@keyframes clmp{0%{box-shadow:0 0 0 0 rgba(34,197,94,.6)}100%{box-shadow:0 0 0 9px rgba(34,197,94,0)}}' +
      '.clm-chip{position:absolute;left:10px;right:56px;bottom:10px;z-index:5;padding:9px 12px;border-radius:12px;background:rgba(20,20,26,.9);color:#fff;font:600 13px system-ui,sans-serif;pointer-events:none}' +
      '.clm-chip b{color:#E5C76B}.clm-chip small{display:block;font-weight:500;opacity:.7;font-size:11px;margin-top:2px}' +
      '.clm-fit{position:absolute;right:10px;bottom:10px;z-index:6;width:40px;height:40px;border-radius:50%;border:0;background:#D4AF37;color:#1a1200;font-size:18px;cursor:pointer}' +
      '.clm-fit:focus-visible{outline:2px solid #fff;outline-offset:2px}';
    document.head.appendChild(s);
  }

  function reset() {
    if (S) { try { ['shop', 'a', 'b'].forEach(function (k) { if (S[k]) S[k].setMap(null); }); if (S.tick) clearInterval(S.tick); ['live', 'chip', 'fit'].forEach(function (k) { var e = S.el && S.el[k]; if (e && e.parentNode) e.parentNode.removeChild(e); }); } catch (e) {} }
    S = null;
  }

  function hud(map, host) {
    css();
    var el = {};
    el.live = document.createElement('div'); el.live.className = 'clm-live off'; el.live.innerHTML = '<span class="clm-dot"></span><span class="clm-t"></span>';
    el.chip = document.createElement('div'); el.chip.className = 'clm-chip'; el.chip.style.display = 'none';
    el.fit = document.createElement('button'); el.fit.type = 'button'; el.fit.className = 'clm-fit'; el.fit.innerHTML = '&#127919;'; el.fit.setAttribute('aria-label', T('Show everything'));
    el.fit.addEventListener('click', function () { if (S) { S.moved = false; fit(); } });
    if (getComputedStyle(host).position === 'static') host.style.position = 'relative';
    host.appendChild(el.live); host.appendChild(el.chip); host.appendChild(el.fit);
    return el;
  }

  function fit() {
    if (!S || !S.map || !window.google) return;
    var b = new google.maps.LatLngBounds(), n = 0;
    S.pts.forEach(function (p) { if (p) { b.extend(p); n++; } });
    if (n > 1) S.map.fitBounds(b, 48); else if (n === 1) { S.map.setCenter(S.pts.filter(Boolean)[0]); }
  }

  function line(old, path, opts) {
    if (!path || path.length < 2) { if (old) old.setMap(null); return null; }
    if (!old) return new google.maps.Polyline(Object.assign({ map: S.map, path: path }, opts));
    old.setPath(path); old.setOptions(opts); if (!old.getMap()) old.setMap(S.map); return old;
  }

  var SOLID = { strokeColor: '#D4AF37', strokeOpacity: .95, strokeWeight: 5, icons: null };
  var FAINT = { strokeOpacity: 0, strokeWeight: 0, icons: [{ icon: { path: 'M 0,-1 0,1', strokeOpacity: .55, strokeColor: '#D4AF37', scale: 3 }, offset: '0', repeat: '14px' }] };

  /* One road request per leg, only when the leg changed or the courier moved ~50 m: the road is cached and drawn again from the cache. */
  function road(from, to, done) {
    var k = key(from) + '>' + key(to);
    if (S.cache[k]) return done(S.cache[k]);
    var fallback = function () { var km = hav(from, to) * 1.3; return { path: [from, to], km: km, min: Math.max(1, Math.round(km / 25 * 60)), est: true }; };
    if (!(window.google && google.maps && google.maps.DirectionsService) || S.noRoads) { var f = fallback(); S.cache[k] = f; return done(f); }
    if (!S.svc) S.svc = new google.maps.DirectionsService();
    var mine = S.id;
    S.svc.route({ origin: from, destination: to, travelMode: 'DRIVING' }, function (res, status) {
      if (!S || S.id !== mine) return;
      var out;
      if (status === 'OK' && res && res.routes && res.routes[0] && res.routes[0].legs && res.routes[0].legs[0]) {
        var L = res.routes[0].legs[0];
        out = { path: res.routes[0].overview_path.map(function (p) { return { lat: p.lat(), lng: p.lng() }; }), km: (L.distance ? L.distance.value : 0) / 1000, min: Math.max(1, Math.round((L.duration ? L.duration.value : 0) / 60)), est: false };
      } else { S.noRoads = status === 'REQUEST_DENIED' || status === 'OVER_QUERY_LIMIT'; out = fallback(); }
      S.cache[k] = out; done(out);
    });
  }

  function stageText(o, hasCourier) {
    var st = o.stage || o.status;
    if (st === 'delivered') return T('Delivered');
    if (POST[st]) return T('On the way to you');
    if (st === 'accepted') return T('Waiting at the shop');
    if (st === 'preparing' || !hasCourier) return T('Preparing your order');
    return T('Heading to the shop');
  }

  function chip(o, leg, label) {
    if (!S) return;
    var txt = stageText(o, !!S.courierPt);
    var more = leg ? '<small>' + Math.round(leg.min) + ' ' + esc(T('min')) + ' &middot; ' + (Math.round(leg.km * 10) / 10).toFixed(1) + ' ' + esc(T('km')) + ' ' + esc(label) + (leg.est ? ' (~)' : '') + '</small>' : '';
    S.el.chip.innerHTML = '<b>' + esc(txt) + '</b>' + more; S.el.chip.style.display = 'block';
  }

  function tick() {
    if (!S) return;
    var t = S.el.live.querySelector('.clm-t'), fresh = !!S.courierPt;
    S.el.live.classList.toggle('off', !fresh);
    if (!fresh) { t.textContent = T('Waiting for the courier location'); return; }
    var s = Math.max(0, Math.round((Date.now() - S.got) / 1000));
    t.textContent = T('LIVE') + ' · ' + T('updated') + ' ' + s + T('s ago');
  }

  /* Called by customer.js on every poll with the order and the Google map it made. Draws everything except the home pin and the vehicle pin. */
  function live(map, o) {
    if (!(window.google && google.maps) || !map) return;
    var host = map.getDiv();
    if (!S || S.map !== map || S.order !== o.id) {
      reset();
      S = { map: map, id: Date.now(), order: o.id, cache: {}, el: hud(map, host), pts: [], moved: false, got: Date.now() };
      S.tick = setInterval(tick, 1000);
      map.addListener('dragstart', function () { if (S) S.moved = true; });
    }
    var shop = o.pickup_point ? pt(o.pickup_point.lat, o.pickup_point.lng) : null;
    var home = pt(o.delivery_lat, o.delivery_lng), cour = pt(o.courier_lat, o.courier_lng);
    var st = o.stage || o.status, done = st === 'delivered' || st === 'cancelled';
    if (done) cour = null;
    var moved = (!cour && S.courierPt) || (cour && (!S.courierPt || hav(cour, S.courierPt) > 0.002));
    if (cour && moved) S.got = Date.now();
    S.courierPt = cour;
    var pts = [shop, home, cour];
    var changed = S.pts.map(function (p) { return p ? key(p) : ''; }).join('|') !== pts.map(function (p) { return p ? key(p) : ''; }).join('|') || S.stage !== st;
    S.pts = pts; S.stage = st;
    if (shop) {
      if (!S.shop) S.shop = new google.maps.Marker({ map: map, position: shop, label: { text: '🏪', fontSize: '18px' }, title: T(o.order_kind === 'package' ? 'Pickup' : 'Shop') });
      else S.shop.setPosition(shop);
      S.shop.setOpacity(POST[st] || done ? .45 : 1);
    }
    tick();
    var pre = !!PRE[st], post = !!POST[st];
    var legA = null, legB = null, label = '';
    if (done) { S.a = line(S.a, null); S.b = line(S.b, null); S.el.chip.innerHTML = '<b>' + esc(stageText(o, false)) + '</b>'; S.el.chip.style.display = 'block'; if (changed && !S.moved) fit(); return; }
    if (pre && cour && shop) { legA = [cour, shop]; label = T('to the shop'); }
    else if (post && cour && home) { legA = [cour, home]; label = T('to you'); }
    if (pre && shop && home) legB = [shop, home];
    if (!legA) { S.a = line(S.a, null); chip(o, null, ''); }
    else if (S.lastA && S.lastA.to === key(legA[1]) && Date.now() - S.lastA.t < 12000) {
      /* the courier moved a little: do not ask Google again for every poll; keep the road, start it at the courier, and re-count the distance */
      var km = hav(legA[0], legA[1]) * 1.3, r2 = { path: [legA[0]].concat(S.lastA.r.path.slice(1)), km: km, min: Math.max(1, Math.round(km / 25 * 60)), est: true };
      S.a = line(S.a, r2.path, SOLID); chip(o, r2, label);
    }
    else road(legA[0], legA[1], function (r) { if (!S) return; S.lastA = { t: Date.now(), to: key(legA[1]), r: r }; S.a = line(S.a, r.path, SOLID); chip(o, r, label); });
    if (!legB) { S.b = line(S.b, null); }
    else road(legB[0], legB[1], function (r) { if (!S) return; S.b = line(S.b, r.path, FAINT); });
    if (changed && !S.moved) fit();
  }

  window.etelTrackLive = live;
  window.etelTrackReset = reset;
})();
