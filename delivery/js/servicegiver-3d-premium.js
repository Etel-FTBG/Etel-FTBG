/* ============================================================
   ETEL Service Giver — 3D Premium Visual Upgrade
   Injects one stylesheet on top of the existing, already-solid
   design system (servicegiver.html's inline <style>) rather than
   replacing it - upgrades .kpi-card/.card/.menu-item/.btn/.modal-box,
   which are shared across EVERY page (Dashboard, Orders, My Services,
   QR Scanner, Analytics, Payments, Settings), so this one file
   genuinely elevates the whole app at once, not just the dashboard.
   Also adds a real animated 3D ring for the Rating KPI, matching the
   same conic-gradient technique already used in the driver/courier/
   admin panels this session.
   ============================================================ */
(function () {
  function injectCss() {
    if (document.getElementById('sg-3d-premium-css')) return;
    var css = document.createElement('style');
    css.id = 'sg-3d-premium-css';
    css.textContent = `
      @keyframes sgSpin3d { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

      /* ── KPI cards: real depth + glowing sweep border ── */
      .kpi-card {
        background: linear-gradient(165deg, rgba(28,18,4,.92), rgba(10,6,0,.96)) !important;
        box-shadow: 0 20px 44px -12px rgba(0,0,0,.55), 0 1px 0 rgba(255,255,255,.05) inset !important;
        border-color: rgba(212,175,55,.28) !important;
      }
      .kpi-card::after {
        content:''; position:absolute; top:0; left:0; right:0; height:3px;
        background: linear-gradient(90deg, transparent, #D4AF37, #FFE9A8, #D4AF37, transparent);
        box-shadow: 0 0 14px rgba(212,175,55,.55);
      }
      .kpi-card:hover { box-shadow: 0 26px 54px -12px rgba(0,0,0,.65), 0 0 30px -8px rgba(212,175,55,.25) !important; }
      .kpi-label { display:flex; align-items:center; gap:8px; }

      /* ── Cards: same glass depth + sweep border ── */
      .card {
        background: linear-gradient(165deg, rgba(28,18,4,.92), rgba(10,6,0,.96)) !important;
        box-shadow: 0 18px 40px -12px rgba(0,0,0,.5) !important;
        position: relative; overflow: hidden;
      }
      .card::before {
        content:''; position:absolute; top:0; left:0; right:0; height:3px;
        background: linear-gradient(90deg, transparent, #D4AF37, #FFE9A8, #D4AF37, transparent);
        box-shadow: 0 0 14px rgba(212,175,55,.5);
      }

      /* ── Sidebar: glowing active nav item ── */
      .sidebar {
        background: linear-gradient(180deg, rgba(24,16,4,.96), rgba(8,5,0,.98)) !important;
        box-shadow: 6px 0 32px rgba(0,0,0,.4) !important;
      }
      .menu-item.active {
        background: linear-gradient(135deg, rgba(212,175,55,.16), rgba(212,175,55,.04)) !important;
        box-shadow: 0 6px 18px -4px rgba(212,175,55,.3), inset 3px 0 0 #D4AF37 !important;
      }

      /* ── Modal: real dark glass instead of flat #161011 ── */
      .modal-box {
        background: linear-gradient(165deg, #1c1204, #0a0703) !important;
        border: 1px solid rgba(212,175,55,.35) !important;
        box-shadow: 0 30px 80px rgba(0,0,0,.6) !important;
        position: relative;
      }
      .modal-box::before {
        content:''; position:absolute; top:0; left:0; right:0; height:3px; border-radius:18px 18px 0 0;
        background: linear-gradient(90deg, transparent, #D4AF37, #FFE9A8, #D4AF37, transparent);
      }

      /* ── Legacy variable aliases ──
         Orders/Services rendering (in servicegiver.js) uses
         --text-dark/--primary/--border-light, which don't exist
         anywhere in this app's real :root - they were silently
         falling back to browser defaults (near-invisible dark text on
         a dark background in places). Aliasing them to the real
         palette fixes this retroactively with zero JS changes. */
      :root {
        --text-dark: var(--text, #F5EAC8);
        --primary: var(--gold, #D4AF37);
        --border-light: var(--border, rgba(212,175,55,.16));
      }

      /* ── Orders: turn the flat bordered list into distinct glass cards ── */
      #orders-container > div, #recent-orders-container > div {
        background: linear-gradient(165deg, rgba(28,18,4,.75), rgba(10,6,0,.88)) !important;
        border: 1px solid rgba(212,175,55,.16) !important;
        border-radius: 14px !important;
        margin-bottom: 10px;
        border-bottom: none !important;
        transition: transform .15s ease, border-color .15s ease;
      }
      #orders-container > div:hover, #recent-orders-container > div:hover {
        transform: translateX(4px);
        border-color: rgba(212,175,55,.4) !important;
      }

      /* ── Services table: override hardcoded light-theme inline colors ──
         The table markup hardcodes #1a1a2e text and #faf9f7 hover
         backgrounds - built for a light theme, clashing badly against
         this dark gold app (near-invisible dark-on-dark text). */
      #services-container table td { color: var(--text) !important; }
      #services-container table th { color: var(--text-faint) !important; border-bottom-color: rgba(212,175,55,.2) !important; }
      #services-container table tr { border-bottom-color: rgba(212,175,55,.1) !important; }
      #services-container table tr:hover { background: rgba(212,175,55,.06) !important; }

      /* ── QR Scanner: glowing frame with animated scan-line ── */
      .qr-frame { position: relative; }
      #qr-video {
        border-radius: 14px; border: 1px solid rgba(212,175,55,.3);
        box-shadow: 0 0 0 1px rgba(212,175,55,.15), 0 20px 44px -12px rgba(0,0,0,.5);
      }
      .sg-scan-line-wrap { position: relative; }
      .sg-scan-line {
        position: absolute; left: 6%; right: 6%; height: 2px;
        background: linear-gradient(90deg, transparent, #FFE9A8, transparent);
        box-shadow: 0 0 12px 2px rgba(255,233,168,.7);
        animation: sgScanMove 2.2s ease-in-out infinite;
        pointer-events: none;
      }
      @keyframes sgScanMove { 0%,100% { top: 8%; opacity: 0; } 10% { opacity: 1; } 50% { top: 88%; opacity: 1; } 90% { opacity: 1; } }

      /* ── 3D rings (Rating, Accuracy Rate, Monthly Growth) ── */
      .sg-ring-wrap { position:relative; width:56px; height:56px; flex:none; filter: drop-shadow(0 8px 14px rgba(0,0,0,.4)); }
      .sg-ring-outer { position:absolute; inset:0; border-radius:50%; animation: sgSpin3d 11s linear infinite; }
      .sg-ring-inner {
        position:absolute; inset:6px; border-radius:50%;
        background: radial-gradient(circle at 35% 30%, #241804, #0a0703 75%);
        box-shadow: inset 0 3px 8px rgba(0,0,0,.6);
        display:flex; align-items:center; justify-content:center;
        font-family:'JetBrains Mono',monospace; font-weight:800; font-size:15px; color:#FFE9A8;
      }
    `;
    document.head.appendChild(css);
  }

  function buildRing(displayValue, pct) {
    var deg = Math.max(0, Math.min(100, pct)) * 3.6;
    var wrap = document.createElement('div');
    wrap.className = 'sg-ring-wrap';
    wrap.innerHTML =
      '<div class="sg-ring-outer" style="background:conic-gradient(#FFE9A8 ' + deg + 'deg, rgba(255,255,255,.08) ' + deg + 'deg)"></div>' +
      '<div class="sg-ring-inner">' + displayValue + '</div>';
    return wrap;
  }

  // Turns a plain KPI card into "ring + text" layout. `parseFn` reads
  // the current text content and returns { display, pct } - kept
  // separate per metric since Rating is out of 5 and Accuracy/Growth
  // are already 0-100% - never assumes one scale fits all three.
  function upgradeKpiCardWithRing(elementId, parseFn) {
    var valueEl = document.getElementById(elementId);
    if (!valueEl) return;
    var card = valueEl.closest('.kpi-card');
    if (!card || card.dataset.sgRingApplied === '1') return;
    var initial = parseFn(valueEl.textContent || '');
    var ring = buildRing(initial.display, initial.pct);
    var flexWrap = document.createElement('div');
    flexWrap.style.cssText = 'display:flex;align-items:center;gap:14px';
    var existingChildren = Array.prototype.slice.call(card.children);
    flexWrap.appendChild(ring);
    var textCol = document.createElement('div');
    existingChildren.forEach(function (child) { textCol.appendChild(child); });
    flexWrap.appendChild(textCol);
    card.appendChild(flexWrap);
    card.dataset.sgRingApplied = '1';
    // Keep the ring in sync if the KPI value updates later.
    var mo = new MutationObserver(function () {
      var result = parseFn(valueEl.textContent || '');
      var deg = Math.max(0, Math.min(100, result.pct)) * 3.6;
      var outer = ring.querySelector('.sg-ring-outer');
      var inner = ring.querySelector('.sg-ring-inner');
      if (outer) outer.style.background = 'conic-gradient(#FFE9A8 ' + deg + 'deg, rgba(255,255,255,.08) ' + deg + 'deg)';
      if (inner) inner.textContent = result.display;
    });
    mo.observe(valueEl, { childList: true, characterData: true, subtree: true });
  }

  function parseRating(text) {
    var v = parseFloat(text.replace(/[^0-9.]/g, '')) || 0;
    // The big number beside the ring already shows the score, so the ring shows a star (no duplicate "0.0").
    return { display: '\u2605', pct: (Math.max(0, Math.min(5, v)) / 5) * 100 };
  }
  function parsePercent(text) {
    var v = parseFloat(text.replace(/[^0-9.\-]/g, '')) || 0;
    return { display: Math.round(v) + '%', pct: Math.max(0, Math.min(100, v)) };
  }

  function upgradeAllRingCards() {
    upgradeKpiCardWithRing('kpi-rating', parseRating);
    upgradeKpiCardWithRing('analytics-accuracy', parsePercent);
    upgradeKpiCardWithRing('analytics-growth', parsePercent);
  }

  // QR Scanner: add the animated scan-line overlay once the frame exists.
  function upgradeQrFrame() {
    var frame = document.querySelector('.qr-frame');
    if (!frame || frame.dataset.sgScanApplied === '1') return;
    var video = document.getElementById('qr-video');
    if (!video) return;
    video.style.position = 'relative';
    var scanLine = document.createElement('div');
    scanLine.className = 'sg-scan-line';
    // Position relative to the video element specifically, not the
    // whole frame, so it tracks the camera preview only.
    video.insertAdjacentElement('afterend', scanLine);
    var videoWrap = document.createElement('div');
    videoWrap.className = 'sg-scan-line-wrap';
    video.parentNode.insertBefore(videoWrap, video);
    videoWrap.appendChild(video);
    videoWrap.appendChild(scanLine);
    frame.dataset.sgScanApplied = '1';
  }

  function ready(fn) { if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  ready(function () {
    injectCss();
    upgradeAllRingCards();
    upgradeQrFrame();
    // Dashboard/Analytics may render/re-render after this script's
    // initial run (servicegiver.js fetches data async) - keep
    // checking briefly in case elements weren't in the DOM yet on
    // first pass, or the user hasn't navigated to that page yet.
    var tries = 0;
    var poll = setInterval(function () {
      tries++;
      upgradeAllRingCards();
      upgradeQrFrame();
      if (tries > 40) clearInterval(poll);
    }, 400);
  });
})();
