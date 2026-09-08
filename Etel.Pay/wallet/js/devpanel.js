/**
 * ኢE Pay — DevPanel v1.0
 * In-app console + network monitor (no DevTools needed)
 * Activated by tapping the ኢE logo 5× fast, or via EP_DEV.open()
 * PHP 5.6+ server — no transpilation needed on client side
 */
(function() {
  'use strict';

  /* ── Storage ──────────────────────────────────────────────── */
  var _logs     = [];   // {type, msg, time, stack}
  var _network  = [];   // {method, url, status, dur, size, time, error}
  var _maxLogs  = 200;
  var _panel    = null;
  var _tab      = 'console';
  var _tapCount = 0;
  var _tapTimer = null;

  /* ── Console intercept ────────────────────────────────────── */
  var _orig = {
    log:   console.log.bind(console),
    warn:  console.warn.bind(console),
    error: console.error.bind(console),
    info:  console.info.bind(console),
  };

  function _intercept(type) {
    console[type] = function() {
      var args = Array.prototype.slice.call(arguments);
      var msg  = args.map(function(a) {
        try { return (typeof a === 'object') ? JSON.stringify(a, null, 2) : String(a); }
        catch(e) { return String(a); }
      }).join(' ');
      _logs.unshift({ type: type, msg: msg, time: new Date() });
      if (_logs.length > _maxLogs) _logs.pop();
      _orig[type].apply(console, arguments);
      _refreshPanel();
    };
  }
  ['log','warn','error','info'].forEach(_intercept);

  /* ── Global error catch ───────────────────────────────────── */
  window.addEventListener('error', function(e) {
    var msg = e.message + '\n  at ' + e.filename + ':' + e.lineno + ':' + e.colno;
    _logs.unshift({ type: 'error', msg: msg, time: new Date() });
    if (_logs.length > _maxLogs) _logs.pop();
    _refreshPanel();
  });
  window.addEventListener('unhandledrejection', function(e) {
    var msg = 'Unhandled Promise rejection: ' + (e.reason && e.reason.message ? e.reason.message : String(e.reason));
    _logs.unshift({ type: 'error', msg: msg, time: new Date() });
    if (_logs.length > _maxLogs) _logs.pop();
    _refreshPanel();
  });

  /* ── Fetch intercept (Network tab) ───────────────────────── */
  var _origFetch = window.fetch;
  window.fetch = function(input, init) {
    var url    = (typeof input === 'string') ? input : (input && input.url) || String(input);
    var method = (init && init.method) || 'GET';
    var start  = Date.now();
    var entry  = { method: method, url: url, status: '…', dur: 0, size: '—', time: new Date(), error: null };
    _network.unshift(entry);
    if (_network.length > 100) _network.pop();
    _refreshPanel();

    return _origFetch.apply(window, arguments).then(function(res) {
      entry.status = res.status;
      entry.dur    = Date.now() - start;
      entry.ok     = res.ok;
      // Try to read content-length
      var cl = res.headers && res.headers.get('content-length');
      if (cl) entry.size = cl + ' B';
      _refreshPanel();
      return res;
    }, function(err) {
      entry.status = 'ERR';
      entry.dur    = Date.now() - start;
      entry.error  = err && err.message ? err.message : String(err);
      entry.ok     = false;
      _refreshPanel();
      return Promise.reject(err);
    });
  };

  /* ── Build panel DOM ──────────────────────────────────────── */
  function _buildPanel() {
    if (_panel) return;

    var style = document.createElement('style');
    style.textContent = [
      '#ep-dev{position:fixed;inset:0;z-index:99999;display:flex;flex-direction:column;',
      'background:#0d0d12;color:#e2e2e2;font-family:\'SF Mono\',\'Fira Mono\',monospace;font-size:12px;',
      'transform:translateY(100%);transition:transform .3s ease;pointer-events:none}',
      '#ep-dev.open{transform:translateY(0);pointer-events:all}',
      '#ep-dev-bar{display:flex;align-items:center;background:#1a1a24;border-bottom:1px solid #2a2a38;padding:8px 12px;gap:8px;flex-shrink:0}',
      '#ep-dev-bar h3{font-size:13px;font-weight:700;color:#D4A843;margin:0;flex:1;letter-spacing:.5px}',
      '.ep-dev-tab{background:none;border:1px solid #2a2a38;color:#888;padding:4px 12px;border-radius:4px;cursor:pointer;font-size:11px;font-family:inherit}',
      '.ep-dev-tab.active{background:#D4A843;color:#000;border-color:#D4A843;font-weight:700}',
      '#ep-dev-close{background:none;border:none;color:#888;font-size:18px;cursor:pointer;padding:0 4px;line-height:1}',
      '#ep-dev-clear{background:none;border:1px solid #333;color:#888;font-size:11px;padding:3px 8px;border-radius:4px;cursor:pointer;font-family:inherit}',
      '#ep-dev-body{flex:1;overflow-y:auto;overflow-x:auto}',
      '.ep-dev-entry{border-bottom:1px solid #1a1a22;padding:6px 12px;display:flex;gap:8px;align-items:flex-start}',
      '.ep-dev-entry.error{background:#200808}',
      '.ep-dev-entry.warn{background:#1c1600}',
      '.ep-dev-entry.info{background:#001122}',
      '.ep-dev-time{color:#555;font-size:10px;flex-shrink:0;padding-top:1px;min-width:56px}',
      '.ep-dev-type{font-size:10px;font-weight:700;flex-shrink:0;min-width:36px;text-transform:uppercase}',
      '.ep-dev-type.error{color:#f87171}',
      '.ep-dev-type.warn{color:#fbbf24}',
      '.ep-dev-type.info{color:#60a5fa}',
      '.ep-dev-type.log{color:#888}',
      '.ep-dev-msg{color:#e2e2e2;word-break:break-all;white-space:pre-wrap;flex:1}',
      '.ep-net-entry{border-bottom:1px solid #1a1a22;padding:5px 12px;display:grid;grid-template-columns:36px 1fr 50px 56px;gap:6px;align-items:center}',
      '.ep-net-entry.ok .ep-net-st{color:#4ade80}',
      '.ep-net-entry.fail .ep-net-st{color:#f87171}',
      '.ep-net-entry.pend .ep-net-st{color:#888}',
      '.ep-net-st{font-weight:700;font-size:11px}',
      '.ep-net-url{color:#93c5fd;word-break:break-all;font-size:11px}',
      '.ep-net-dur{color:#888;font-size:11px;text-align:right}',
      '.ep-net-sz{color:#555;font-size:10px;text-align:right}',
      '.ep-dev-empty{text-align:center;padding:40px;color:#444;font-size:12px}',
      '#ep-dev-badge{position:fixed;bottom:90px;right:14px;z-index:99998;width:32px;height:32px;',
      'border-radius:50%;background:#D4A843;color:#000;font-size:11px;font-weight:900;',
      'display:none;align-items:center;justify-content:center;cursor:pointer;',
      'box-shadow:0 2px 12px rgba(212,168,67,.5);border:none}',
    ].join('');
    document.head.appendChild(style);

    _panel = document.createElement('div');
    _panel.id = 'ep-dev';
    _panel.innerHTML = [
      '<div id="ep-dev-bar">',
      '  <h3>ኢE DevPanel</h3>',
      '  <button class="ep-dev-tab active" id="ep-tab-con" onclick="EP_DEV.switchTab(\'console\')">Console</button>',
      '  <button class="ep-dev-tab" id="ep-tab-net" onclick="EP_DEV.switchTab(\'network\')">Network</button>',
      '  <button id="ep-dev-clear" onclick="EP_DEV.clear()">Clear</button>',
      '  <button id="ep-dev-close" onclick="EP_DEV.close()">✕</button>',
      '</div>',
      '<div id="ep-dev-body"></div>',
    ].join('');
    document.body.appendChild(_panel);

    // Floating badge (error count) — starts hidden, shows only when errors exist
    var badge = document.createElement('button');
    badge.id = 'ep-dev-badge';
    badge.title = 'Open DevPanel (errors detected)';
    badge.innerHTML = '0';
    badge.style.display = 'none';
    badge.onclick = function() { EP_DEV.open(); };
    document.body.appendChild(badge);
  }

  /* ── Render ───────────────────────────────────────────────── */
  function _refreshPanel() {
    if (!_panel) return;
    var body = document.getElementById('ep-dev-body');
    if (!body) return;

    // Update badge
    var errCount = _logs.filter(function(l){ return l.type === 'error'; }).length +
                   _network.filter(function(n){ return n.ok === false; }).length;
    var badge = document.getElementById('ep-dev-badge');
    if (badge) {
      if (errCount > 0) {
        badge.style.display = 'flex';
        badge.innerHTML = errCount > 99 ? '99+' : errCount;
      } else {
        badge.style.display = 'none';
      }
    }

    if (!_panel.classList.contains('open')) return;

    if (_tab === 'console') {
      if (!_logs.length) {
        body.innerHTML = '<div class="ep-dev-empty">No console output yet</div>';
        return;
      }
      body.innerHTML = _logs.map(function(l) {
        var t = l.time.toTimeString().slice(0,8);
        var msg = l.msg.replace(/</g,'&lt;').replace(/>/g,'&gt;');
        return '<div class="ep-dev-entry ' + l.type + '">' +
          '<span class="ep-dev-time">' + t + '</span>' +
          '<span class="ep-dev-type ' + l.type + '">' + l.type + '</span>' +
          '<span class="ep-dev-msg">' + msg + '</span>' +
          '</div>';
      }).join('');
    } else {
      if (!_network.length) {
        body.innerHTML = '<div class="ep-dev-empty">No network requests yet</div>';
        return;
      }
      body.innerHTML = _network.map(function(n) {
        var cls = n.ok === true ? 'ok' : (n.ok === false ? 'fail' : 'pend');
        var st  = n.status;
        var url = n.url.replace(window.location.origin, '');
        var dur = n.dur ? n.dur + 'ms' : '—';
        return '<div class="ep-net-entry ' + cls + '">' +
          '<span class="ep-net-st">' + st + '</span>' +
          '<span class="ep-net-url">' + n.method + ' ' + url.replace(/</g,'&lt;') + (n.error ? '<br><span style="color:#f87171">' + n.error + '</span>' : '') + '</span>' +
          '<span class="ep-net-dur">' + dur + '</span>' +
          '<span class="ep-net-sz">' + n.size + '</span>' +
          '</div>';
      }).join('');
    }
  }

  /* ── Secret tap trigger (tap ኢE logo 5×) ─────────────────── */
  function _setupTapTrigger() {
    document.addEventListener('click', function(e) {
      var t = e.target;
      // Any click in the greeting area or on the logo-like element
      if (t.closest && (t.closest('.greeting-text') || t.closest('.ep-nav-btn[data-page="wallet"]'))) {
        _tapCount++;
        clearTimeout(_tapTimer);
        if (_tapCount >= 5) {
          _tapCount = 0;
          EP_DEV.open();
        } else {
          _tapTimer = setTimeout(function(){ _tapCount = 0; }, 1200);
        }
      }
    });
  }

  /* ── Public API ───────────────────────────────────────────── */
  window.EP_DEV = {
    open: function() {
      if (!_panel) _buildPanel();
      _panel.classList.add('open');
      _refreshPanel();
    },
    close: function() {
      if (_panel) _panel.classList.remove('open');
    },
    switchTab: function(tab) {
      _tab = tab;
      var tabs = document.querySelectorAll('.ep-dev-tab');
      for (var i = 0; i < tabs.length; i++) tabs[i].classList.remove('active');
      var el = document.getElementById('ep-tab-' + (tab === 'console' ? 'con' : 'net'));
      if (el) el.classList.add('active');
      _refreshPanel();
    },
    clear: function() {
      if (_tab === 'console') _logs = [];
      else _network = [];
      _refreshPanel();
    },
    log: function(msg) { console.log(msg); },
  };

  /* ── Init ─────────────────────────────────────────────────── */
  function _init() {
    _buildPanel();
    _setupTapTrigger();
    // Show any errors captured before panel was built (e.g. on page load)
    _refreshPanel();
    setTimeout(function() { _refreshPanel(); }, 800);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _init);
  } else {
    _init();
  }

})();
