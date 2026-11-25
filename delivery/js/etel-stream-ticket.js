/* ETEL - keeps the login token out of web addresses.
   A live stream (EventSource) cannot send a header, so the apps used to put the whole login token in the address (...&token=...), and it ended up in server
   logs and browser history. This file wraps EventSource: for a stream address that carries a token it first asks the server for a 2-minute sealed ticket
   (action stream_ticket, sent with the normal Authorization header) and opens the stream with ?ticket= instead. If the ticket cannot be had (an old server, no
   network) it falls back to the old address, so nothing stops working. It also wraps navigator.sendBeacon: a "...beacon_ping" with a token in the address
   sends the token in the body instead. The apps' own code is not changed. Load it before the app scripts. */
(function () {
  'use strict';
  if (window.__etelStreamTicket) return;
  window.__etelStreamTicket = 1;
  var Native = window.EventSource;

  function parse(u) { try { return new URL(String(u), location.href); } catch (e) { return null; } }

  if (Native) {
    var Wrapped = function (url, opts) {
      var p = parse(url);
      var action = p ? (p.searchParams.get('action') || '') : '';
      var token = p ? (p.searchParams.get('token') || '') : '';
      if (!p || !token || action.indexOf('sse_') !== 0) return new Native(url, opts);
      var self = this, real = null, closed = false, listeners = [], handlers = { onopen: null, onmessage: null, onerror: null };
      var original = String(url);

      function attach(l) { real.addEventListener(l.type, l.fn, l.opts); }
      function start(finalUrl) {
        if (closed) return;
        real = new Native(finalUrl, opts);
        real.onopen = function (e) { if (handlers.onopen) handlers.onopen.call(self, e); };
        real.onmessage = function (e) { if (handlers.onmessage) handlers.onmessage.call(self, e); };
        real.onerror = function (e) { if (handlers.onerror) handlers.onerror.call(self, e); };
        listeners.forEach(attach);
      }
      ['onopen', 'onmessage', 'onerror'].forEach(function (n) {
        Object.defineProperty(self, n, { get: function () { return handlers[n]; }, set: function (f) { handlers[n] = typeof f === 'function' ? f : null; } });
      });
      Object.defineProperty(self, 'readyState', { get: function () { return real ? real.readyState : (closed ? 2 : 0); } });
      Object.defineProperty(self, 'url', { get: function () { return original; } });
      Object.defineProperty(self, 'withCredentials', { get: function () { return !!(opts && opts.withCredentials); } });
      self.CONNECTING = 0; self.OPEN = 1; self.CLOSED = 2;
      self.addEventListener = function (type, fn, o) { var l = { type: type, fn: fn, opts: o }; listeners.push(l); if (real) attach(l); };
      self.removeEventListener = function (type, fn, o) {
        listeners = listeners.filter(function (l) { return !(l.type === type && l.fn === fn); });
        if (real) real.removeEventListener(type, fn, o);
      };
      self.close = function () { closed = true; if (real) real.close(); };

      var api = p.origin + p.pathname;
      fetch(api, {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
        body: JSON.stringify({ action: 'stream_ticket', 'for': action, id: p.searchParams.get('id') || '' })
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (d && d.success && d.data && d.data.ticket) {
          p.searchParams.delete('token'); p.searchParams.set('ticket', d.data.ticket);
          start(p.toString());
        } else start(original);
      }).catch(function () { start(original); });
    };
    Wrapped.CONNECTING = 0; Wrapped.OPEN = 1; Wrapped.CLOSED = 2;
    window.EventSource = Wrapped;
  }

  /* A beacon is sent when the tab is hidden, when there is no time to ask for anything, so its ticket is fetched ahead (10 minutes, renewed every 8 minutes)
     for the one beacon this app uses; the body of the beacon (position) is left exactly as the app made it. */
  var beaconTicket = null, beaconAt = 0, BEACON = 'driver_beacon_ping';
  function ticketFor(token, action, done) {
    var base = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
    fetch(base, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token }, body: JSON.stringify({ action: 'stream_ticket', 'for': action }) })
      .then(function (r) { return r.json(); }).then(function (d) { done(d && d.success && d.data && d.data.ticket ? d.data.ticket : null); }).catch(function () { done(null); });
  }
  function warm() {
    var t = ''; try { t = localStorage.getItem('etel_token') || ''; } catch (e) {}
    if (!t || !/\/driver(\/|$)/.test(location.pathname)) return;
    ticketFor(t, BEACON, function (tk) { if (tk) { beaconTicket = tk; beaconAt = Date.now(); } });
  }
  warm(); setInterval(warm, 8 * 60 * 1000);

  var nativeBeacon = navigator.sendBeacon;
  if (nativeBeacon) {
    navigator.sendBeacon = function (url, data) {
      try {
        var p = parse(url);
        if (p && p.searchParams.get('token') && p.searchParams.get('action') === BEACON && beaconTicket && Date.now() - beaconAt < 9 * 60 * 1000) {
          p.searchParams.delete('token'); p.searchParams.set('ticket', beaconTicket);
          return nativeBeacon.call(navigator, p.toString(), data);
        }
      } catch (e) { /* fall through to the normal call */ }
      return nativeBeacon.apply(navigator, arguments);
    };
  }
})();
