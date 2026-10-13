/* Courier navigation: turn-by-turn banner, live ETA, off-route re-routing, voice, custom route + pins.
   Standalone on purpose (the courier app code ships as versioned releases). It hooks the app's own
   DirectionsRenderer, so every "Navigate to shop / customer" button gets this experience with no other change. */
(function () {
  'use strict';
  var VOICE_KEY = 'etel_courier_voice';
  var S = null;          // active navigation state (null when idle)
  var ui = null;         // DOM refs
  var hooked = false, prevType = null, watchId = null, autoFull = false;

  /* ---------- small helpers ---------- */
  function ll(p) { return { lat: typeof p.lat === 'function' ? p.lat() : p.lat, lng: typeof p.lng === 'function' ? p.lng() : p.lng }; }
  function rad(d) { return d * Math.PI / 180; }
  function dist(a, b) { // metres, haversine
    var R = 6371000, dLa = rad(b.lat - a.lat), dLn = rad(b.lng - a.lng);
    var x = Math.sin(dLa / 2) * Math.sin(dLa / 2) + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLn / 2) * Math.sin(dLn / 2);
    return 2 * R * Math.asin(Math.sqrt(x));
  }
  function distToSegment(p, a, b) { // metres, local flat projection
    var k = Math.cos(rad(p.lat)), ax = (a.lng - p.lng) * k, ay = a.lat - p.lat, bx = (b.lng - p.lng) * k, by = b.lat - p.lat;
    var dx = bx - ax, dy = by - ay, len = dx * dx + dy * dy, t = len ? Math.max(0, Math.min(1, -(ax * dx + ay * dy) / len)) : 0;
    var cx = ax + t * dx, cy = ay + t * dy;
    return Math.sqrt(cx * cx + cy * cy) * 111320;
  }
  function distToPath(p, path) {
    var best = Infinity;
    for (var i = 1; i < path.length; i++) { var d = distToSegment(p, path[i - 1], path[i]); if (d < best) best = d; }
    return best;
  }
  function fmtDist(m) {
    if (m < 1000) return (m < 200 ? Math.max(10, Math.round(m / 10) * 10) : Math.round(m / 50) * 50) + ' m';
    return (m / 1000).toFixed(m < 10000 ? 1 : 0) + ' km';
  }
  function fmtMin(s) {
    var m = Math.max(1, Math.round(s / 60));
    return m < 60 ? m + ' min' : Math.floor(m / 60) + ' h ' + (m % 60) + ' min';
  }
  function clock(sec) {
    var d = new Date(Date.now() + sec * 1000), h = d.getHours(), m = d.getMinutes();
    return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
  }
  function plain(html) { // instruction HTML -> plain text (never inserted as HTML)
    var t = document.createElement('div');
    t.innerHTML = String(html || '').replace(/<div[^>]*>/gi, ' — ').replace(/<\/?wbr\s*\/?>/gi, '');
    return (t.textContent || '').replace(/\s+/g, ' ').trim();
  }
  function angleFor(man) {
    man = String(man || '');
    if (/uturn/.test(man)) return 180;
    if (/sharp-left/.test(man)) return -135;
    if (/sharp-right/.test(man)) return 135;
    if (/slight-left|keep-left|fork-left/.test(man)) return -40;
    if (/slight-right|keep-right|fork-right/.test(man)) return 40;
    if (/left/.test(man)) return -90;
    if (/right/.test(man)) return 90;
    return 0;
  }
  function voiceOn() { try { return localStorage.getItem(VOICE_KEY) === '1'; } catch (e) { return false; } }
  function say(text) {
    if (!voiceOn() || !window.speechSynthesis || !text) return;
    try { window.speechSynthesis.cancel(); window.speechSynthesis.speak(new SpeechSynthesisUtterance(text)); } catch (e) {}
  }

  /* ---------- UI ---------- */
  var ARROW = '<svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20V5M6 11l6-6 6 6"/></svg>';
  var FLAG = '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 21V4M5 4h11l-2 4 2 4H5"/></svg>';

  function css() {
    if (document.getElementById('cn-css')) return;
    var s = document.createElement('style'); s.id = 'cn-css';
    s.textContent =
      'body.cn-on #map-nav-bar,body.cn-on #map-route-panel{display:none!important}' +
      'body.cn-on #courier-map-expand-btn{top:128px!important}' +
      '#cn-top,#cn-bot,#cn-steps,#cn-tools{position:absolute;z-index:220;font-family:Sora,system-ui,sans-serif;color:#F5F5F7}' +
      '#cn-top{top:10px;left:10px;right:10px;display:flex;gap:12px;align-items:center;padding:10px 14px;border-radius:18px;background:linear-gradient(135deg,rgba(20,20,36,.96),rgba(12,12,24,.96));border:1px solid rgba(229,199,107,.4);box-shadow:0 10px 30px -8px rgba(0,0,0,.7)}' +
      '#cn-ico{flex:0 0 52px;height:52px;border-radius:15px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#E5C76B,#C99A2E);color:#14141f}' +
      '#cn-ico svg{transition:transform .25s}' +
      '#cn-main{flex:1;min-width:0}' +
      '#cn-dist{font-size:24px;font-weight:800;line-height:1.05;letter-spacing:-.01em}' +
      '#cn-ins{font-size:13.5px;font-weight:600;opacity:.92;margin-top:2px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;line-height:1.25}' +
      '#cn-next{font-size:11px;opacity:.6;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
      '#cn-top.cn-warn{border-color:rgba(255,170,60,.7)}#cn-top.cn-done #cn-ico{background:linear-gradient(135deg,#2ECC71,#1fa355)}' +
      '#cn-bot{left:10px;right:10px;bottom:10px;display:flex;align-items:center;gap:10px;padding:10px 12px 10px 16px;border-radius:20px;background:rgba(14,14,26,.96);border:1px solid rgba(255,255,255,.12);box-shadow:0 10px 30px -8px rgba(0,0,0,.7)}' +
      '#cn-eta{flex:1;min-width:0}#cn-eta b{display:block;font-size:22px;font-weight:800;color:#2ECC71;line-height:1.05}' +
      '#cn-eta span{font-size:12px;opacity:.75;display:block;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
      '.cn-btn{flex:0 0 auto;width:44px;height:44px;border-radius:14px;border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.07);color:#F5F5F7;display:flex;align-items:center;justify-content:center;font-size:18px;cursor:pointer;font-family:inherit}' +
      '.cn-btn.on{background:rgba(229,199,107,.22);border-color:rgba(229,199,107,.6)}' +
      '#cn-end{width:auto;padding:0 16px;background:#E63946;border-color:#E63946;font-size:14px;font-weight:800}' +
      '#cn-tools{right:10px;bottom:86px;display:flex;flex-direction:column;gap:8px}' +
      '#cn-tools .cn-btn{background:rgba(14,14,26,.94);width:46px;height:46px}' +
      '#cn-steps{left:10px;right:68px;bottom:86px;max-height:46%;overflow-y:auto;border-radius:18px;background:rgba(14,14,26,.97);border:1px solid rgba(255,255,255,.12);display:none}' +
      '#cn-steps.open{display:block}' +
      '#cn-steps .cn-st{display:flex;gap:10px;align-items:center;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.07);font-size:13px}' +
      '#cn-steps .cn-st.cur{background:rgba(229,199,107,.12)}#cn-steps .cn-st.past{opacity:.4}' +
      '#cn-steps .cn-st i{flex:0 0 30px;height:30px;border-radius:9px;background:rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;font-style:normal;color:#E5C76B}' +
      '#cn-steps .cn-st i svg{width:18px;height:18px}' +
      '#cn-steps .cn-st em{margin-left:auto;font-style:normal;opacity:.65;white-space:nowrap;font-size:12px}';
    document.head.appendChild(s);
  }

  function build(box) {
    var d = document.createElement('div');
    d.id = 'cn-root';
    d.innerHTML =
      '<div id="cn-top"><div id="cn-ico">' + ARROW + '</div><div id="cn-main"><div id="cn-dist">…</div><div id="cn-ins"></div><div id="cn-next"></div></div></div>' +
      '<div id="cn-steps"></div>' +
      '<div id="cn-tools"><button type="button" class="cn-btn" id="cn-sat" aria-label="Satellite view">🛰</button>' +
      '<button type="button" class="cn-btn" id="cn-voice" aria-label="Voice guidance">🔊</button>' +
      '<button type="button" class="cn-btn" id="cn-list" aria-label="All steps">☰</button>' +
      '<button type="button" class="cn-btn" id="cn-gm" aria-label="Open in Google Maps">↗</button>' +
      '<button type="button" class="cn-btn" id="cn-me" aria-label="Centre on me">◎</button></div>' +
      '<div id="cn-bot"><div id="cn-eta"><b id="cn-t">—</b><span id="cn-s"></span></div><button type="button" class="cn-btn" id="cn-end">End</button></div>';
    box.appendChild(d);
    ui = {
      root: d, top: d.querySelector('#cn-top'), ico: d.querySelector('#cn-ico'), dist: d.querySelector('#cn-dist'),
      ins: d.querySelector('#cn-ins'), next: d.querySelector('#cn-next'), steps: d.querySelector('#cn-steps'),
      t: d.querySelector('#cn-t'), s: d.querySelector('#cn-s'), voice: d.querySelector('#cn-voice'), me: d.querySelector('#cn-me'), sat: d.querySelector('#cn-sat')
    };
    ui.voice.classList.toggle('on', voiceOn());
    ui.voice.onclick = function () {
      var on = !voiceOn();
      try { localStorage.setItem(VOICE_KEY, on ? '1' : '0'); } catch (e) {}
      ui.voice.classList.toggle('on', on);
      if (on) say('Voice guidance on'); else if (window.speechSynthesis) window.speechSynthesis.cancel();
    };
    d.querySelector('#cn-list').onclick = function () { ui.steps.classList.toggle('open'); renderSteps(); };
    d.querySelector('#cn-end').onclick = function () { try { if (typeof window.clearRoute === 'function') window.clearRoute(); } catch (e) {} stop(); };
    d.querySelector('#cn-gm').onclick = function () {
      if (!S) return;
      window.open('https://www.google.com/maps/dir/?api=1&destination=' + S.dest.lat + ',' + S.dest.lng + '&travelmode=driving', '_blank');
    };
    ui.me.onclick = function () { if (S) { S.follow = true; ui.me.classList.add('on'); if (S.pos) follow(S.pos, true); } };
    ui.sat.onclick = function () {
      try {
        var m = S.map, cur = m.getMapTypeId();
        m.setMapTypeId(cur === 'roadmap' ? 'hybrid' : 'roadmap'); ui.sat.classList.toggle('on', cur === 'roadmap');
      } catch (e) {}
    };
  }

  /* ---------- navigation state ---------- */
  function setRoute(route, map, label, keepFollow) {
    var leg = route.legs[0];
    var path = (route.overview_path || []).map(ll);
    var steps = (leg.steps || []).map(function (st) {
      return {
        text: plain(st.instructions), man: st.maneuver || '', meters: st.distance ? st.distance.value : 0, secs: st.duration ? st.duration.value : 0,
        end: ll(st.end_location), path: (st.path || []).map(ll)
      };
    });
    if (!steps.length) steps = [{ text: 'Head to ' + (label || 'destination'), man: '', meters: leg.distance.value, secs: leg.duration.value, end: ll(leg.end_location), path: path }];
    var prev = S;
    S = {
      map: map, route: route, path: path, steps: steps, idx: 0, dest: ll(leg.end_location), label: label || 'Destination',
      total: leg.distance.value, totalSecs: leg.duration.value, pos: prev ? prev.pos : null, follow: prev && keepFollow ? prev.follow : true,
      off: 0, lastReroute: prev ? prev.lastReroute : 0, said: {}, arrived: false, lines: [], pin: null
    };
    if (prev) { clearOverlays(prev); }
    draw();
    render();
  }

  function draw() {
    var g = window.google.maps, m = S.map;
    var gp = S.path.map(function (p) { return new g.LatLng(p.lat, p.lng); });
    S.lines = [
      new g.Polyline({ map: m, path: gp, strokeColor: '#ffffff', strokeOpacity: 1, strokeWeight: 11, zIndex: 40 }),
      new g.Polyline({ map: m, path: gp, strokeColor: '#1A6BFF', strokeOpacity: 1, strokeWeight: 7, zIndex: 41 })
    ];
    try {
      var el = document.createElement('div');
      var shop = /^shop/i.test(S.label);
      el.style.cssText = 'width:42px;height:42px;border-radius:50% 50% 50% 4px;transform:rotate(-45deg);background:linear-gradient(135deg,#E5C76B,#C99A2E);border:3px solid #fff;box-shadow:0 6px 16px rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center';
      var inner = document.createElement('span');
      inner.style.cssText = 'transform:rotate(45deg);font-size:19px';
      inner.textContent = shop ? '🏪' : '🏠';
      el.appendChild(inner);
      S.pin = new g.marker.AdvancedMarkerElement({ map: m, position: S.dest, content: el, title: S.label });
    } catch (e) { S.pin = null; }
    try { prevType = prevType || m.getMapTypeId(); m.setMapTypeId('roadmap'); } catch (e) {}
  }
  function clearOverlays(st) {
    (st.lines || []).forEach(function (l) { try { l.setMap(null); } catch (e) {} });
    if (st.pin) { try { st.pin.map = null; } catch (e) {} }
  }

  function stepRemaining(pos) { // metres from pos to the end of the current step, along the step's own line
    var st = S.steps[S.idx], p = st.path;
    if (!p || p.length < 2) return dist(pos, st.end);
    var bi = 0, bd = Infinity, i;
    for (i = 0; i < p.length; i++) { var d = dist(pos, p[i]); if (d < bd) { bd = d; bi = i; } }
    var rem = bd;
    for (i = bi; i < p.length - 1; i++) rem += dist(p[i], p[i + 1]);
    return rem;
  }

  function render() {
    if (!S || !ui) return;
    var st = S.steps[S.idx], pos = S.pos;
    var toTurn = pos ? stepRemaining(pos) : st.meters;
    var restM = 0, restS = 0, i;
    for (i = S.idx + 1; i < S.steps.length; i++) { restM += S.steps[i].meters; restS += S.steps[i].secs; }
    var frac = st.meters ? Math.min(1, toTurn / st.meters) : 1;
    var remM = toTurn + restM, remS = st.secs * frac + restS;
    var last = S.idx >= S.steps.length - 1;
    if (S.arrived) {
      ui.top.classList.add('cn-done'); ui.ico.innerHTML = FLAG;
      ui.dist.textContent = 'You have arrived'; ui.ins.textContent = S.label; ui.next.textContent = '';
      ui.t.textContent = 'Arrived'; ui.s.textContent = S.label;
    } else {
      ui.top.classList.remove('cn-done');
      ui.ico.innerHTML = last ? FLAG : ARROW;
      if (!last) ui.ico.firstChild.style.transform = 'rotate(' + angleFor(st.man) + 'deg)';
      ui.dist.textContent = last ? fmtDist(toTurn) : fmtDist(toTurn);
      ui.ins.textContent = st.text;
      var nx = S.steps[S.idx + 1];
      ui.next.textContent = nx ? 'Then: ' + nx.text : '';
      ui.t.textContent = fmtMin(remS);
      ui.s.textContent = fmtDist(remM) + ' · Arrive ' + clock(remS) + ' · ' + S.label;
    }
    ui.top.classList.toggle('cn-warn', S.off >= 2);
    if (ui.steps.classList.contains('open')) renderSteps();
  }

  function renderSteps() {
    if (!S) return;
    ui.steps.textContent = '';
    S.steps.forEach(function (st, i) {
      var row = document.createElement('div'); row.className = 'cn-st' + (i === S.idx ? ' cur' : (i < S.idx ? ' past' : ''));
      var ic = document.createElement('i'); ic.innerHTML = ARROW; ic.firstChild.style.transform = 'rotate(' + angleFor(st.man) + 'deg)';
      var tx = document.createElement('span'); tx.textContent = st.text;
      var di = document.createElement('em'); di.textContent = fmtDist(st.meters);
      row.appendChild(ic); row.appendChild(tx); row.appendChild(di); ui.steps.appendChild(row);
    });
  }

  function follow(pos, force) {
    var m = S.map;
    try {
      m.panTo(pos);
      if (force && m.getZoom() < 16) m.setZoom(17);
      if (m.getRenderingType && m.getRenderingType() === 'VECTOR') {
        if (pos.heading != null && pos.speed > 1.5) m.setHeading(pos.heading);
        m.setTilt(45);
      }
    } catch (e) {}
  }

  function reroute() {
    var now = Date.now();
    if (!S || now - S.lastReroute < 15000 || !S.pos) return;
    S.lastReroute = now;
    ui.ins.textContent = 'Re-routing…';
    var g = window.google.maps, svc = new g.DirectionsService(), map = S.map, label = S.label, keep = S.lastReroute;
    svc.route({ origin: new g.LatLng(S.pos.lat, S.pos.lng), destination: new g.LatLng(S.dest.lat, S.dest.lng), travelMode: g.TravelMode.DRIVING }, function (res, status) {
      if (status === 'OK' && S) { setRoute(res.routes[0], map, label, true); S.lastReroute = keep; say('Route updated'); }
    });
  }

  function onFix(p) {
    if (!S) return;
    var pos = { lat: p.coords.latitude, lng: p.coords.longitude, heading: p.coords.heading, speed: p.coords.speed };
    var acc = p.coords.accuracy || 20;
    S.pos = pos;
    if (acc > 120) { render(); return; } // too imprecise to steer by
    // advance steps
    while (S.idx < S.steps.length - 1 && dist(pos, S.steps[S.idx].end) < 30) {
      S.idx++; S.said = {}; say(S.steps[S.idx].text);
    }
    var toDest = dist(pos, S.dest);
    if (!S.arrived && toDest < 40) { S.arrived = true; try { if (navigator.vibrate) navigator.vibrate([200, 80, 200]); } catch (e) {} say('You have arrived'); }
    // off route
    if (!S.arrived) {
      var off = distToPath(pos, S.path);
      S.off = off > Math.max(60, acc * 1.5) ? S.off + 1 : 0;
      if (S.off >= 3) { S.off = 0; reroute(); }
      var toTurn = stepRemaining(pos);
      if (toTurn < 160 && !S.said.near && S.idx < S.steps.length - 1) { S.said.near = true; say('In ' + fmtDist(toTurn) + ', ' + S.steps[S.idx].text); }
    }
    if (S.follow) follow(pos, false);
    render();
  }

  function startWatch() {
    if (watchId != null || !navigator.geolocation) return;
    watchId = navigator.geolocation.watchPosition(onFix, function () {}, { enableHighAccuracy: true, maximumAge: 2000, timeout: 20000 });
  }
  function stopWatch() { if (watchId != null && navigator.geolocation) { navigator.geolocation.clearWatch(watchId); watchId = null; } }

  function start(renderer, res) {
    var map = renderer.getMap && renderer.getMap();
    var box = document.getElementById('courier-map-container');
    if (!map || !box || !res || !res.routes || !res.routes.length) return;
    css();
    if (!ui || !document.body.contains(ui.root)) build(box);
    var labelEl = document.getElementById('map-route-label');
    var label = (labelEl && labelEl.textContent && labelEl.textContent !== 'Destination') ? labelEl.textContent : 'Destination';
    setRoute(res.routes[0], map, label, false);
    document.body.classList.add('cn-on');
    ui.root.style.display = '';
    ui.me.classList.add('on');
    try {
      google.maps.event.addListenerOnce(map, 'dragstart', function () { if (S) { S.follow = false; ui.me.classList.remove('on'); } });
    } catch (e) {}
    // Navigation wants the whole screen: open the full map (and give the panel back when navigation ends).
    try { if (typeof window.toggleCourierMap === 'function' && !window._courierMapCollapsed) { window.toggleCourierMap(); autoFull = true; } } catch (e) {}
    say(S.steps[0].text);
    startWatch();
  }

  function stop() {
    stopWatch();
    if (S) { clearOverlays(S); try { if (prevType) S.map.setMapTypeId(prevType); S.map.setTilt(0); S.map.setHeading(0); } catch (e) {} }
    prevType = null; S = null;
    try { if (autoFull && window._courierMapCollapsed && typeof window.toggleCourierMap === 'function') window.toggleCourierMap(); } catch (e) {}
    autoFull = false;
    document.body.classList.remove('cn-on');
    if (ui && ui.root) ui.root.style.display = 'none';
    if (window.speechSynthesis) try { window.speechSynthesis.cancel(); } catch (e) {}
  }

  /* ---------- hook into the app's DirectionsRenderer ---------- */
  function hook() {
    if (hooked) return true;
    var g = window.google && window.google.maps;
    if (!g || !g.DirectionsRenderer || !g.DirectionsRenderer.prototype.setDirections) return false;
    var orig = g.DirectionsRenderer.prototype.setDirections;
    g.DirectionsRenderer.prototype.setDirections = function (res) {
      var empty = !res || !res.routes || !res.routes.length;
      if (!empty) { try { this.setOptions({ suppressPolylines: true, suppressMarkers: true }); } catch (e) {} }
      var out = orig.apply(this, arguments);
      try { if (empty) stop(); else start(this, res); } catch (e) { if (window.console) console.warn('courier-nav:', e); }
      return out;
    };
    hooked = true;
    return true;
  }

  var tries = 0;
  (function wait() { if (!hook() && tries++ < 150) setTimeout(wait, 300); })();
  window.__courierNav = { dist: dist, distToPath: distToPath, fmtDist: fmtDist, fmtMin: fmtMin, plain: plain, angleFor: angleFor };
})();
