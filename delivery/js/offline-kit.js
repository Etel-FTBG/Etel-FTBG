/* ETEL offline kit - the shared engine for apps that talk to the API with JSON POSTs (action + body).
   An app calls EtelOffline.install({...}) once. The kit then:
   - keeps the last good answer of the "read" actions on the phone and serves it, marked as saved, when the network is down;
   - saves the "write" actions the app allows (cfg.queueable) when the network is down, and sends them in order when it is back;
   - never drops a saved action quietly: one the server refuses stays in a visible list with the server's reason until discarded;
   - shows one small bar: "No connection", "saved at HH:MM", "N waiting to send" (+ Send now / Discard).
   Everything else (money, PINs, anything not whitelisted) goes straight to the network and fails normally.
   Config: { name, api, userKey(), token(), reads(action, body)->bool, queueable(action, body)->bool, overlay(action, data, waiting)->data,
             describe(entry)->string, onSent(count), onReconnect(), toast(msg, isError),
             queueKey / savedKey (storage keys; default etel_outbox_<name> / etel_saved_<name>), isApi(url)->bool (default: url === api), getAction(url)->action|null (opt in to GET reads: the query string becomes body._q), savedWhat (banner noun) }
   Storage is localStorage with an in-memory fallback. Every server value shown goes through esc(). */
(function () {
'use strict';
if (window.EtelOffline) return;

function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
function hhmm(t) { var d = new Date(t); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
function uid() { return 'o' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10); }
function json(obj) { return new Response(JSON.stringify(obj), { status: 200, headers: { 'Content-Type': 'application/json' } }); }
function netDown(e) { return !!e && (e.name === 'TypeError' || /fetch|network|load failed/i.test(String(e.message))); }

function install(cfg) {
  var origFetch = window.fetch.bind(window);
  var KQ = cfg.queueKey || ('etel_outbox_' + cfg.name), KC = cfg.savedKey || ('etel_saved_' + cfg.name);
  var memQ = null, memC = {}, flushing = false, staleAt = 0, barId = 'eof-bar', cssId = 'eof-css';

  function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function user() { try { return String((cfg.userKey && cfg.userKey()) || ''); } catch (e) { return ''; } }
  function queue() {
    if (memQ) return memQ;
    try { var a = JSON.parse(lsGet(KQ) || '[]'); memQ = Array.isArray(a) ? a : []; } catch (e) { memQ = []; }
    return memQ;
  }
  function saveQ() { lsSet(KQ, JSON.stringify(queue())); paint(); }
  function mine() { var u = user(); return queue().filter(function (e) { return !e.user || e.user === u; }); }
  function waiting() { return mine().filter(function (e) { return !e.failed; }); }
  function ckey(action, body) {
    var b = {}; Object.keys(body || {}).sort().forEach(function (k) { if (k !== 'action') b[k] = body[k]; });
    return user() + '|' + action + '|' + JSON.stringify(b);
  }
  function saveRead(action, body, data) {
    var entry = { at: Date.now(), data: data }, key = ckey(action, body);
    memC[key] = entry;
    try {
      var all = JSON.parse(lsGet(KC) || '{}'); if (!all || typeof all !== 'object') all = {};
      all[key] = entry;
      var keys = Object.keys(all);
      if (keys.length > 40) keys.sort(function (a, b) { return all[a].at - all[b].at; }).slice(0, keys.length - 40).forEach(function (k) { delete all[k]; });
      lsSet(KC, JSON.stringify(all));
    } catch (e) {}
  }
  function loadRead(action, body) {
    var key = ckey(action, body);
    if (memC[key]) return memC[key];
    try { var all = JSON.parse(lsGet(KC) || '{}'); return all && all[key] || null; } catch (e) { return null; }
  }

  /* ---- the bar ---- */
  function css() {
    if (document.getElementById(cssId)) return;
    var s = document.createElement('style'); s.id = cssId;
    s.textContent = '#eof-bar{position:fixed;left:10px;right:10px;bottom:calc(10px + env(safe-area-inset-bottom,0px));z-index:9998;background:#1b1b2a;color:#f4f1e6;border:1.5px solid #f2c14e;border-radius:14px;padding:10px 12px;font:600 13px/1.4 system-ui,sans-serif;box-shadow:0 8px 28px rgba(0,0,0,.45);display:none;max-width:560px;margin:0 auto}' +
      '#eof-bar.on{display:block}#eof-bar b{color:#f2c14e}#eof-bar .r{display:flex;gap:8px;align-items:center;justify-content:space-between}' +
      '#eof-bar button{font:inherit;border:0;border-radius:9px;padding:7px 11px;cursor:pointer;background:#f2c14e;color:#1b1b2a;min-height:36px}#eof-bar button.g{background:transparent;color:#f4f1e6;border:1px solid #6b6b80}' +
      '#eof-bar ul{list-style:none;margin:8px 0 0;padding:0;max-height:34vh;overflow:auto}#eof-bar li{display:flex;gap:8px;justify-content:space-between;align-items:center;padding:6px 0;border-top:1px solid #34344a;font-weight:500}' +
      '#eof-bar li.bad{color:#ff8a98}#eof-bar button:focus-visible{outline:2px solid #fff;outline-offset:2px}';
    document.head.appendChild(s);
  }
  function label(e) { try { return cfg.describe ? cfg.describe(e) : e.action; } catch (x) { return e.action; } }
  function paint() {
    if (!document.body) return;
    css();
    var bar = document.getElementById(barId);
    if (!bar) { bar = document.createElement('div'); bar.id = barId; bar.setAttribute('role', 'status'); bar.setAttribute('aria-live', 'polite'); document.body.appendChild(bar); }
    var q = mine(), off = navigator.onLine === false;
    if (!q.length && !off && !staleAt) { bar.className = ''; bar.innerHTML = ''; return; }
    var head = off ? '📡 <b>No connection.</b> ' : '';
    if (staleAt) head += 'Showing ' + esc(cfg.savedWhat || 'saved data') + ' from <b>' + esc(hhmm(staleAt)) + '</b>. ';
    var html = '<div class="r"><span>' + head + (q.length ? q.length + ' waiting to send' : (off ? 'Your work is saved on this phone.' : '')) + '</span>' +
      (waiting().length ? '<button type="button" data-eof="send">Send now</button>' : '') + '</div>';
    if (q.length) {
      html += '<ul>' + q.map(function (e) {
        return '<li class="' + (e.failed ? 'bad' : '') + '"><span>' + esc(label(e)) + (e.failed ? ' — ' + esc(e.failed) : ' · ' + esc(hhmm(e.at))) + '</span>' +
          '<button type="button" class="g" data-eof="drop" data-id="' + esc(e.id) + '" aria-label="Discard ' + esc(label(e)) + '">Discard</button></li>';
      }).join('') + '</ul>';
    }
    bar.innerHTML = html; bar.className = 'on';
  }
  document.addEventListener('click', function (ev) {
    var b = ev.target && ev.target.closest && ev.target.closest('[data-eof]'); if (!b) return;
    if (b.getAttribute('data-eof') === 'send') flush(true);
    else { var id = b.getAttribute('data-id'); memQ = queue().filter(function (e) { return e.id !== id; }); saveQ(); }
  });

  /* ---- sending ---- */
  function flush(manual) {
    if (flushing) return Promise.resolve();
    var list = waiting(); if (!list.length) return Promise.resolve();
    flushing = true;
    var chain = Promise.resolve(), stop = false, sent = 0;
    list.forEach(function (e) {
      chain = chain.then(function () {
        if (stop) return;
        var body = Object.assign({}, e.body, { action: e.action, client_req_id: e.id, occurred_at: new Date(e.at).toISOString() });
        return origFetch(cfg.api, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (cfg.token ? cfg.token() : '') }, body: JSON.stringify(body) })
          .then(function (r) { return r.text().then(function (t) { var j = null; try { j = JSON.parse(t); } catch (x) {} return { status: r.status, json: j }; }); })
          .then(function (r) {
            if (r.json && r.json.success) { memQ = queue().filter(function (x) { return x.id !== e.id; }); sent++; return; }
            if (r.status === 401 || r.status >= 500 || !r.json) { stop = true; return; }
            e.failed = (r.json && r.json.message) || 'The server did not accept this';
          }, function () { stop = true; });
      });
    });
    return chain.then(function () {
      flushing = false; saveQ();
      if (sent) { if (cfg.toast) cfg.toast('✓ ' + sent + ' saved action' + (sent > 1 ? 's' : '') + ' sent', false); if (cfg.onSent) { try { cfg.onSent(sent); } catch (x) {} } }
      else if (manual && waiting().length && cfg.toast) cfg.toast('Still no connection', true);
    });
  }

  /* ---- the fetch wrapper ---- */
  window.fetch = function (input, init) {
    var url = typeof input === 'string' ? input : (input && input.url) || '';
    var method = ((init && init.method) || (input && input.method) || 'GET').toUpperCase();
    if (!(cfg.isApi ? cfg.isApi(url) : url === cfg.api)) return origFetch(input, init);
    var body = null, action = null;
    if (method === 'GET' && cfg.getAction) {
      action = cfg.getAction(url);
      body = { _q: url.replace(/^[^?]*\??/, '') };
    } else if (method === 'POST' && init && typeof init.body === 'string') {
      try { body = JSON.parse(init.body); } catch (e) {}
      action = body && body.action;
    }
    if (!action) return origFetch(input, init);

    if (cfg.reads && cfg.reads(action, body)) {
      return origFetch(input, init).then(function (r) {
        if (r.ok) { staleAt = 0; paint(); r.clone().json().then(function (j) { if (j && j.success) saveRead(action, body, j.data); }, function () {}); }
        return r;
      }, function (err) {
        if (!netDown(err)) throw err;
        var c = loadRead(action, body); if (!c) throw err;
        staleAt = c.at; paint();
        var data = c.data; try { if (cfg.overlay) data = cfg.overlay(action, JSON.parse(JSON.stringify(data)), waiting()); } catch (x) {}
        return json({ success: true, offline: true, saved_at: c.at, data: data });
      });
    }
    if (cfg.queueable && cfg.queueable(action, body)) {
      return origFetch(input, init).catch(function (err) {
        if (!netDown(err)) throw err;
        var clean = Object.assign({}, body); delete clean.action;
        queue().push({ id: uid(), user: user(), action: action, body: clean, at: Date.now() });
        saveQ();
        return json({ success: true, queued: true, message: 'Saved on your phone - it will be sent when you are back online', data: { queued: true } });
      });
    }
    return origFetch(input, init);
  };

  window.addEventListener('online', function () {
    paint();
    flush(false).then(function () { if (staleAt && cfg.onReconnect) { try { cfg.onReconnect(); } catch (x) {} } });
  });
  window.addEventListener('offline', paint);
  setInterval(function () { if (navigator.onLine !== false && waiting().length) flush(false); }, 20000);
  function start() { paint(); flush(false); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
  return { flush: function () { return flush(true); }, pending: function () { return mine().length; } };
}

window.EtelOffline = { install: install };
})();
