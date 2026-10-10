/* EtelERP app core: API calls, tiny router, helpers. No inline scripts anywhere (CSP). */
(function () {
  'use strict';
  var E = window.E = { biz: null, me: null, businesses: [], role: null, views: {} };

  E.esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  E.$ = function (sel, root) { return (root || document).querySelector(sel); };
  E.$$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  E.uid = function () { var a = new Uint8Array(12); crypto.getRandomValues(a); return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); };

  /* money: integers only, same rules as the server (the server's numbers are the ones that count) */
  E.cents = function (v) { v = String(v == null ? '0' : v).replace(/,/g, '').trim(); var m = /^(-?)(\d+)(?:\.(\d{1,2}))?$/.exec(v); if (!m) return NaN; return (m[1] ? -1 : 1) * (parseInt(m[2], 10) * 100 + parseInt((m[3] || '').padEnd(2, '0') || '0', 10)); };
  E.milli = function (v) { v = String(v == null ? '' : v).replace(/,/g, '').trim(); var m = /^(\d+)(?:\.(\d{1,3}))?$/.exec(v); if (!m) return NaN; return parseInt(m[1], 10) * 1000 + parseInt((m[2] || '').padEnd(3, '0') || '0', 10); };
  E.money = function (v) { var c = typeof v === 'number' ? v : E.cents(v); if (isNaN(c)) return '0.00'; var neg = c < 0; c = Math.abs(c); var s = Math.floor(c / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ','); return (neg ? '-' : '') + s + '.' + ('0' + (c % 100)).slice(-2); };
  E.qty = function (v) { return String(v == null ? '' : v).replace(/\.?0+$/, '') || '0'; };
  E.today = function () { var d = new Date(Date.now() + 3 * 3600 * 1000); return d.toISOString().slice(0, 10); };

  E.toast = function (msg, isErr) {
    var old = E.$('.toast'); if (old) old.remove();
    var t = document.createElement('div'); t.className = 'toast' + (isErr ? ' err' : ''); t.textContent = msg; document.body.appendChild(t);
    setTimeout(function () { if (t.parentNode) t.remove(); }, isErr ? 5000 : 2500);
  };

  var base = location.pathname.replace(/[^\/]*$/, '');
  E.api = function (action, opts) {
    opts = opts || {};
    var qs = 'action=' + encodeURIComponent(action);
    if (opts.query) Object.keys(opts.query).forEach(function (k) { if (opts.query[k] !== '' && opts.query[k] != null) qs += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(opts.query[k]); });
    var h = { 'X-Requested-With': 'etelerp' };
    if (E.biz) h['X-Business'] = String(E.biz);
    var init = { method: opts.body !== undefined ? 'POST' : 'GET', headers: h, credentials: 'same-origin' };
    if (opts.body !== undefined) { h['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
    return fetch(base + 'api/index.php?' + qs, init).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Unexpected reply from the server.' }; }).then(function (j) {
        if (j.ok) return j.data;
        if (r.status === 401 && action !== 'auth.login') { E.me = null; E.go('#/login'); }
        var e = new Error(j.error || 'Something went wrong.'); e.status = r.status; throw e;
      });
    }, function () { var e = new Error('No connection. Check your network and try again.'); e.status = 0; throw e; });
  };

  /* views are functions(root, args) that fill the page; render() wraps them in the shell */
  E.hashQuery = function () { var q = {}, s = (location.hash.split('?')[1] || ''); s.split('&').forEach(function (p) { if (p) { var kv = p.split('='); q[decodeURIComponent(kv[0])] = decodeURIComponent(kv[1] || ''); } }); return q; };
  E.go = function (h) { if (location.hash === h) E.render(); else location.hash = h; };
  var NAV = [['#/', '⌂', 'Home'], ['#/sell', '▶', 'Sell'], ['#/invoices', '☰', 'Invoices'], ['#/more', '…', 'More']];
  E.can = function (min) { var r = { cashier: 1, manager: 2, owner: 3 }; return (r[E.role] || 0) >= r[min]; };

  E.render = function () {
    var h = location.hash || '#/', parts = h.slice(2).split('?')[0].split('/'), name = parts[0] || 'home', root = E.$('#app');
    var pub = (name === 'login');
    if (!pub && !E.me) { E.boot(); return; }
    if (!pub && !E.biz && name !== 'biz' && name !== 'new') { E.go('#/biz'); return; }
    var view = E.views[name] || E.views.home;
    root.innerHTML = '';
    if (!pub) {
      var top = document.createElement('div'); top.className = 'top';
      top.innerHTML = '<img src="assets/icons/logo.png" alt=""><b>' + E.esc(E.bizName || 'EtelERP') + '</b>';
      root.appendChild(top);
    }
    var wrap = document.createElement('div'); wrap.className = 'wrap'; root.appendChild(wrap);
    if (!pub && E.biz) {
      var nav = document.createElement('nav'); nav.className = 'bottom';
      nav.innerHTML = NAV.map(function (n) { var on = (n[0] === '#/' ? name === 'home' || name === '' : h.indexOf(n[0]) === 0); return '<a href="' + n[0] + '" class="' + (on ? 'on' : '') + '"><span>' + n[1] + '</span>' + n[2] + '</a>'; }).join('');
      root.appendChild(nav);
    }
    view(wrap, parts.slice(1));
    window.scrollTo(0, 0);
  };

  E.load = function (root, promise, then) {
    root.innerHTML = '<div class="empty">Loading...</div>';
    promise.then(then, function (e) { root.innerHTML = '<div class="empty">' + E.esc(e.message) + '<br><br><button class="alt" id="retry">Try again</button></div>'; var b = E.$('#retry', root); if (b) b.onclick = E.render; });
  };

  E.sheet = function (html, wire) {
    var s = document.createElement('div'); s.className = 'sheet'; s.innerHTML = '<div>' + html + '</div>'; document.body.appendChild(s);
    s.addEventListener('click', function (ev) { if (ev.target === s) s.remove(); });
    var close = function () { s.remove(); };
    if (wire) wire(s, close);
    return close;
  };

  /* work out who is signed in, then which business */
  E.boot = function () {
    E.api('auth.me').then(function (d) {
      E.me = d.member; E.businesses = d.businesses;
      var saved = null; try { saved = parseInt(localStorage.getItem('etelerp_biz'), 10); } catch (e) {}
      var pick = d.businesses.filter(function (b) { return b.id === saved; })[0] || (d.businesses.length === 1 ? d.businesses[0] : null);
      if (pick) E.setBiz(pick); else E.biz = null;
      E.render();
    }, function () { E.go('#/login'); E.render(); });
  };
  E.setBiz = function (b) { E.biz = b.id; E.bizName = b.name; E.role = b.role; try { localStorage.setItem('etelerp_biz', String(b.id)); } catch (e) {} };

  window.addEventListener('hashchange', E.render);
  document.addEventListener('DOMContentLoaded', function () {
    if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(function () {});
    E.boot();
  });
})();
