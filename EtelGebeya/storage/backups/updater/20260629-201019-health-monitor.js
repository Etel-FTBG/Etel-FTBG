/**
 * EtelGebeya — Health Monitor Widget
 * Injected on every storefront page for admin-token holders only.
 * Shows a floating 🔬 button; click opens a slide-up diagnostics panel.
 */
(function () {
  'use strict';

  const TOKEN   = localStorage.getItem('eg_admin_token');
  if (!TOKEN) return;   // not admin — do nothing

  // ── base path (handle /EtelGebeya/ subdirectory) ──────────────────
  const BASE = (function () {
    const m = location.pathname.match(/^(\/[^/]+\/EtelGebeya)/i)
           || location.pathname.match(/^(\/EtelGebeya)/i);
    return m ? m[1] : '';
  })();

  const API     = BASE + '/api/diagnostics.php';
  const ADMIN   = BASE + '/admin';
  const STORE   = BASE + '/';

  // ── track JS errors ───────────────────────────────────────────────
  const jsErrors = [];
  const _onerr   = window.onerror;
  window.onerror = function (msg, src, line, col, err) {
    jsErrors.push({ msg, src, line, col });
    if (_onerr) _onerr.apply(this, arguments);
  };
  const _unhandled = window.onunhandledrejection;
  window.onunhandledrejection = function (e) {
    jsErrors.push({ msg: String(e.reason), src: 'Promise', line: 0, col: 0 });
    if (_unhandled) _unhandled.apply(this, arguments);
  };

  // ── styles ────────────────────────────────────────────────────────
  const STYLE = `
  #eg-hm-fab{position:fixed;bottom:22px;right:22px;z-index:999999;width:46px;height:46px;border-radius:50%;
    background:linear-gradient(135deg,#1a3a1a,#2d6a2d);border:2px solid rgba(127,224,127,.45);
    box-shadow:0 4px 18px rgba(0,0,0,.45);cursor:pointer;display:flex;align-items:center;justify-content:center;
    font-size:1.15rem;transition:transform .2s,box-shadow .2s;color:#fff}
  #eg-hm-fab:hover{transform:scale(1.1);box-shadow:0 6px 24px rgba(0,0,0,.6)}
  #eg-hm-fab .eg-hm-badge{position:absolute;top:-4px;right:-4px;min-width:16px;height:16px;border-radius:8px;
    background:#ef4444;color:#fff;font-size:.6rem;font-weight:800;display:flex;align-items:center;
    justify-content:center;padding:0 4px;border:1.5px solid #0d0d0d;display:none}
  #eg-hm-drawer{position:fixed;bottom:0;left:0;right:0;z-index:999998;max-height:60vh;
    background:#0d1a0d;border-top:2px solid rgba(127,224,127,.25);
    box-shadow:0 -8px 40px rgba(0,0,0,.7);transform:translateY(100%);transition:transform .3s ease;
    display:flex;flex-direction:column;font-family:'Courier New',monospace}
  #eg-hm-drawer.open{transform:translateY(0)}
  #eg-hm-head{display:flex;align-items:center;gap:.75rem;padding:.6rem 1rem;
    border-bottom:1px solid rgba(127,224,127,.15);flex-shrink:0;flex-wrap:wrap}
  #eg-hm-head h3{margin:0;font-size:.82rem;font-weight:800;color:#7FE07F;letter-spacing:.05em;flex:1}
  #eg-hm-head .eg-hm-actions{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}
  #eg-hm-head button{background:rgba(127,224,127,.08);border:1px solid rgba(127,224,127,.2);
    color:#7FE07F;border-radius:6px;padding:.25rem .65rem;font-size:.72rem;cursor:pointer}
  #eg-hm-head button:hover{background:rgba(127,224,127,.15)}
  #eg-hm-close{background:transparent!important;border:none!important;font-size:1rem;
    color:rgba(127,224,127,.6)!important;padding:.25rem .5rem!important;margin-left:.25rem}
  #eg-hm-body{overflow-y:auto;padding:.75rem 1rem;flex:1;font-size:.78rem;line-height:1.6}
  .eg-hm-section{margin-bottom:.75rem}
  .eg-hm-section-title{color:rgba(127,224,127,.5);font-size:.7rem;letter-spacing:.1em;text-transform:uppercase;margin-bottom:.35rem}
  .eg-hm-row{display:flex;gap:.6rem;align-items:baseline;padding:.2rem 0;border-bottom:1px solid rgba(127,224,127,.04)}
  .eg-hm-row:last-child{border-bottom:none}
  .eg-hm-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:4px}
  .eg-hm-dot.pass{background:#22c55e}.eg-hm-dot.fail{background:#ef4444}
  .eg-hm-dot.warn{background:#f59e0b}.eg-hm-dot.info{background:#3b82f6}
  .eg-hm-label{color:#e2e8f0;font-weight:700;min-width:110px}
  .eg-hm-msg{color:rgba(200,220,200,.65)}
  .eg-hm-jserr{color:#fca5a5;background:rgba(239,68,68,.06);border-left:3px solid #ef4444;
    padding:.3rem .6rem;border-radius:0 4px 4px 0;font-size:.72rem;margin:.2rem 0}
  .eg-hm-empty{color:rgba(127,224,127,.3);font-style:italic}
  #eg-hm-statusbar{padding:.35rem 1rem;border-top:1px solid rgba(127,224,127,.12);font-size:.7rem;
    color:rgba(127,224,127,.4);flex-shrink:0;display:flex;gap:1rem;flex-wrap:wrap}
  `;
  const s = document.createElement('style');
  s.textContent = STYLE;
  document.head.appendChild(s);

  // ── FAB button ────────────────────────────────────────────────────
  const fab = document.createElement('div');
  fab.id = 'eg-hm-fab';
  fab.title = 'Admin Health Monitor';
  fab.innerHTML = '🔬<span class="eg-hm-badge" id="eg-hm-errcnt"></span>';
  document.body.appendChild(fab);

  // ── drawer ────────────────────────────────────────────────────────
  const drawer = document.createElement('div');
  drawer.id = 'eg-hm-drawer';
  drawer.innerHTML = `
    <div id="eg-hm-head">
      <h3>🔬 HEALTH MONITOR — ${location.pathname}</h3>
      <div class="eg-hm-actions">
        <button onclick="egHmRun()">▶ Run Checks</button>
        <button onclick="egHmClear()">Clear</button>
        <a href="${ADMIN}" style="background:rgba(127,224,127,.08);border:1px solid rgba(127,224,127,.2);color:#7FE07F;border-radius:6px;padding:.25rem .65rem;font-size:.72rem;text-decoration:none">⚙ Admin</a>
        <button id="eg-hm-close" onclick="egHmClose()">✕</button>
      </div>
    </div>
    <div id="eg-hm-body"><span class="eg-hm-empty">Click "▶ Run Checks" to start diagnostics.</span></div>
    <div id="eg-hm-statusbar"><span id="eg-hm-sb">Ready</span></div>
  `;
  document.body.appendChild(drawer);

  // ── state ─────────────────────────────────────────────────────────
  let open = false;

  fab.addEventListener('click', function () {
    open = !open;
    drawer.classList.toggle('open', open);
    if (open && document.getElementById('eg-hm-body').querySelector('.eg-hm-empty')) {
      egHmRun();
    }
    refreshBadge();
  });

  window.egHmClose = function () {
    open = false;
    drawer.classList.remove('open');
  };

  window.egHmClear = function () {
    document.getElementById('eg-hm-body').innerHTML = '<span class="eg-hm-empty">Cleared. Click "▶ Run Checks" to re-run.</span>';
    document.getElementById('eg-hm-sb').textContent = 'Ready';
    jsErrors.length = 0;
    refreshBadge();
  };

  function refreshBadge() {
    const cnt  = document.getElementById('eg-hm-errcnt');
    const n    = jsErrors.length;
    cnt.textContent = n > 9 ? '9+' : n;
    cnt.style.display = n > 0 ? 'flex' : 'none';

    // Change fab color by severity
    if (n > 0) {
      fab.style.borderColor = 'rgba(239,68,68,.7)';
      fab.style.background  = 'linear-gradient(135deg,#3a1a1a,#6a2d2d)';
    } else {
      fab.style.borderColor = 'rgba(127,224,127,.45)';
      fab.style.background  = 'linear-gradient(135deg,#1a3a1a,#2d6a2d)';
    }
  }

  // Auto-update badge when new JS errors arrive
  setInterval(refreshBadge, 2000);

  window.egHmRun = async function () {
    const body = document.getElementById('eg-hm-body');
    const sb   = document.getElementById('eg-hm-sb');
    body.innerHTML = '<span style="color:rgba(127,224,127,.5)">Running checks…</span>';
    sb.textContent = 'Fetching…';

    let diagHtml = '';
    try {
      const r = await fetch(API + '?auth=' + encodeURIComponent(TOKEN));
      const text = await r.text();
      let d;
      try { d = JSON.parse(text); }
      catch (e) {
        body.innerHTML = `<div class="eg-hm-jserr">diagnostics.php returned non-JSON (HTTP ${r.status}):<br><pre style="font-size:.68rem;color:#fca5a5;white-space:pre-wrap">${text.slice(0, 500)}</pre></div>`;
        sb.textContent = 'Parse error';
        return;
      }
      if (!d.ok) { body.innerHTML = `<div class="eg-hm-jserr">API error: ${d.error || 'unknown'}</div>`; sb.textContent = 'Error'; return; }
      const { checks, summary, elapsed_ms } = d.data;
      diagHtml = `<div class="eg-hm-section"><div class="eg-hm-section-title">Server Diagnostics (${elapsed_ms}ms)</div>`;
      for (const c of checks) {
        diagHtml += `<div class="eg-hm-row"><div class="eg-hm-dot ${c.status}"></div><div class="eg-hm-label">${c.name}</div><div class="eg-hm-msg">${c.msg}</div></div>`;
      }
      diagHtml += `</div>`;
      sb.textContent = `✓${summary.pass} pass  ✗${summary.fail} fail  ⚠${summary.warn} warn — ${elapsed_ms}ms`;
    } catch (e) {
      diagHtml = `<div class="eg-hm-jserr">Fetch failed: ${e.message}</div>`;
      sb.textContent = 'Network error';
    }

    // ── JS errors ────────────────────────────────────────────────
    let jsHtml = `<div class="eg-hm-section"><div class="eg-hm-section-title">JS Errors on This Page (${jsErrors.length})</div>`;
    if (jsErrors.length === 0) {
      jsHtml += '<div class="eg-hm-empty">No JS errors caught.</div>';
    } else {
      for (const e of jsErrors) {
        jsHtml += `<div class="eg-hm-jserr">${e.msg}<br><span style="color:rgba(252,165,165,.5);font-size:.68rem">${e.src}:${e.line}:${e.col}</span></div>`;
      }
    }
    jsHtml += '</div>';

    // ── Page info ─────────────────────────────────────────────────
    const pageHtml = `<div class="eg-hm-section"><div class="eg-hm-section-title">Page Info</div>
      <div class="eg-hm-row"><div class="eg-hm-dot info"></div><div class="eg-hm-label">URL</div><div class="eg-hm-msg">${location.href}</div></div>
      <div class="eg-hm-row"><div class="eg-hm-dot info"></div><div class="eg-hm-label">Status</div><div class="eg-hm-msg">HTTP ${document.readyState === 'complete' ? '200 (assumed)' : document.readyState}</div></div>
      <div class="eg-hm-row"><div class="eg-hm-dot info"></div><div class="eg-hm-label">Admin Token</div><div class="eg-hm-msg">${TOKEN ? '✓ present' : '✗ missing'}</div></div>
    </div>`;

    body.innerHTML = diagHtml + jsHtml + pageHtml;
    refreshBadge();
  };

})();
