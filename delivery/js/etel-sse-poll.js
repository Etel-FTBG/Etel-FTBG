/* ETEL - live updates by short polling instead of long-lived connections.
   The rider and driver apps listen to ?action=sse_ride_status and ?action=sse_driver_stream with EventSource. Each open
   stream holds one PHP worker and one database connection for up to 15 minutes, which is what shared hosting runs out of
   first when drivers go online together. This script (loaded before the app bundles) replaces EventSource for those two
   streams only: it asks the same endpoint for ONE answer (&once=1) every few seconds and hands the answer to the app the
   way EventSource would. Nothing in the bundles changes, so no release is needed.
   Switch back to real streams on one phone with: localStorage.setItem('etel_sse','1'). */
(function () {
'use strict';
var Native = window.EventSource;
try { if (localStorage.getItem('etel_sse') === '1') return; } catch (e) {}
var WATCH = /[?&]action=sse_(ride_status|driver_stream)(&|$)/;

function Poll(url) {
  var self = this, timer = null, fails = 0, ev = {};
  var ride = /sse_ride_status/.test(url);
  self.url = url; self.readyState = 0; self.withCredentials = false;
  self.onopen = self.onmessage = self.onerror = null;
  function fire(type, data) {
    var e = { type: type, data: data, target: self };
    var h = type === 'message' ? self.onmessage : (type === 'open' ? self.onopen : (type === 'error' ? self.onerror : null));
    if (typeof h === 'function') { try { h.call(self, e); } catch (x) {} }
    var l = ev[type] || [];
    for (var i = 0; i < l.length; i++) { try { l[i].call(self, e); } catch (x) {} }
  }
  self.addEventListener = function (t, f) { (ev[t] = ev[t] || []).push(f); };
  self.removeEventListener = function (t, f) { var l = ev[t] || [], i = l.indexOf(f); if (i >= 0) l.splice(i, 1); };
  self.close = function () { self.readyState = 2; if (timer) clearTimeout(timer); timer = null; };
  function gap() {
    if (document.visibilityState === 'hidden') return 15000;
    return ride ? 2500 : 4000;
  }
  function next() { if (self.readyState !== 2) timer = setTimeout(tick, gap()); }
  function fail() {
    fails++;
    if (fails >= 3) { fire('error'); self.close(); return; }   // the app then falls back to its own polling
    next();
  }
  function tick() {
    if (self.readyState === 2) return;
    fetch(url + (url.indexOf('?') >= 0 ? '&' : '?') + 'once=1', { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.text(); })
      .then(function (txt) {
        if (self.readyState === 2) return;
        fails = 0;
        if (self.readyState === 0) { self.readyState = 1; fire('open'); }
        var blocks = txt.split('\n\n'), closed = false;
        for (var i = 0; i < blocks.length; i++) {
          var type = 'message', data = [], lines = blocks[i].split('\n');
          for (var j = 0; j < lines.length; j++) {
            if (lines[j].indexOf('event:') === 0) type = lines[j].slice(6).trim();
            else if (lines[j].indexOf('data:') === 0) data.push(lines[j].slice(5).replace(/^ /, ''));
          }
          if (!data.length) continue;
          fire(type, data.join('\n'));
          if (type === 'message') {   // other scripts (price offers) read the same answers instead of asking again
            try { document.dispatchEvent(new CustomEvent('etel:stream', { detail: { kind: ride ? 'ride' : 'driver', data: JSON.parse(data.join('\n')) } })); } catch (x) {}
          }
          if (type === 'close') closed = true;
        }
        if (closed) { self.readyState = 2; return; }
        next();
      })
      .catch(fail);
  }
  timer = setTimeout(tick, 0);
}
Poll.CONNECTING = 0; Poll.OPEN = 1; Poll.CLOSED = 2;

window.EventSource = function (url, opts) {
  if (!WATCH.test(String(url)) || typeof fetch !== 'function') return new Native(url, opts);
  return new Poll(String(url));
};
window.EventSource.CONNECTING = 0; window.EventSource.OPEN = 1; window.EventSource.CLOSED = 2;
window.EventSource.prototype = Native ? Native.prototype : {};
})();
