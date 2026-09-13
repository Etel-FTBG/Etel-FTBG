/* ════════════════════════════════════════════════════════════════
   ETEL COURIER — premium dashboard enhancements (ADD-ON, optional)
   Load AFTER courier.js:  <script src="js/courier-premium.js"></script>
   Purely additive: injects an earnings card + online toggle + haptics.
   Does NOT modify courier.js, the map, or the API key.
   ════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  // Safely reach courier.js helpers (shared global scope). Guarded everywhere.
  function getApiGet() { try { return (typeof apiGet === 'function') ? apiGet : null; } catch (e) { return null; } }
  function getUser()   { try { return (typeof user !== 'undefined') ? user : null; } catch (e) { return null; } }

  ready(function () {
    injectOnlineToggle();
    injectEarningsCard();
    wireHaptics();
    wireTabRefresh();
    initMapExpand();
  });

  /* == Full-map toggle ==
     Courier's map is a fixed 300px box on mobile (not full-screen like
     Ride/Driver), so "full map" here means growing that box to fill the
     screen and fading out the header + sidebar, rather than resizing an
     already-fullscreen map or just hiding overlays. */
  window._courierMapCollapsed = false;
  function initMapExpand() { /* placeholder kept for symmetry with other init* calls; real work happens in toggleCourierMap */ }
  window.toggleCourierMap = function () {
    window._courierMapCollapsed = !window._courierMapCollapsed;
    var mapBox = document.getElementById('courier-map-container');
    var header = document.querySelector('.header');
    var sidebar = document.querySelector('.sidebar');
    var btn = document.getElementById('courier-map-expand-btn');
    if (window._courierMapCollapsed) {
      if (mapBox) { mapBox.style.height = '100vh'; mapBox.style.maxHeight = '100vh'; }
      if (header) { header.style.opacity = '0'; header.style.pointerEvents = 'none'; }
      if (sidebar) { sidebar.style.opacity = '0'; sidebar.style.pointerEvents = 'none'; }
      if (btn) { btn.innerHTML = '✕'; btn.title = 'Show panel'; }
      var fab = document.getElementById('courier-map-fab');
      if (!fab) {
        fab = document.createElement('button');
        fab.id = 'courier-map-fab';
        fab.style.cssText = 'position:fixed;bottom:30px;left:50%;transform:translateX(-50%);z-index:12;' +
          'background:rgba(212,175,55,.95);color:#0d0d0d;padding:10px 22px;border-radius:999px;' +
          'font-family:Poppins,sans-serif;font-size:13px;font-weight:800;cursor:pointer;border:none;' +
          'box-shadow:0 6px 20px rgba(0,0,0,.4);display:none;white-space:nowrap;';
        fab.textContent = '🗺 Full Map — tap to restore panel';
        fab.onclick = function () { window.toggleCourierMap(); };
        document.body.appendChild(fab);
      }
      fab.style.display = 'block';
    } else {
      if (mapBox) { mapBox.style.height = ''; mapBox.style.maxHeight = ''; }
      if (header) { header.style.opacity = '1'; header.style.pointerEvents = ''; }
      if (sidebar) { sidebar.style.opacity = '1'; sidebar.style.pointerEvents = ''; }
      if (btn) { btn.innerHTML = '⤢'; btn.title = 'Full map'; }
      var fab2 = document.getElementById('courier-map-fab');
      if (fab2) fab2.style.display = 'none';
    }
  };

  /* == "You're Online" reminder (persistent notification + app-icon badge) ==
     Same mechanism as the Driver app: a tappable reminder that brings this
     tab back to the front while the courier is "Available" and off in some
     other app. Note this toggle is currently frontend-only (see comment
     above) - the reminder reflects the local toggle state, not a real
     backend presence check, until that's wired up. */
  function showCourierOnlineReminder() {
    try { if (navigator.setAppBadge) navigator.setAppBadge(); } catch (e) {}
    if (!('Notification' in window) || !navigator.serviceWorker) return;
    function show() {
      navigator.serviceWorker.ready.then(function (reg) {
        reg.showNotification('ETEL Courier \u2014 Available', {
          body: 'Ready for deliveries. Tap to return to the courier app.',
          icon: './icons/icon-192.png',
          badge: './icons/icon-72.png',
          tag: 'etel-online-status',
          renotify: false,
          requireInteraction: true,
          silent: true,
          data: './courier.html'
        }).catch(function () {});
      });
    }
    if (Notification.permission === 'granted') show();
    else if (Notification.permission === 'default') {
      Notification.requestPermission().then(function (p) { if (p === 'granted') show(); });
    }
  }

  function clearCourierOnlineReminder() {
    try { if (navigator.clearAppBadge) navigator.clearAppBadge(); } catch (e) {}
    if (!navigator.serviceWorker) return;
    navigator.serviceWorker.ready.then(function (reg) {
      reg.getNotifications({ tag: 'etel-online-status' }).then(function (ns) {
        ns.forEach(function (n) { n.close(); });
      });
    }).catch(function () {});
  }

  /* ── SLIDE-TO-GO-ONLINE (matches the Driver app's control) ──
     Frontend state persisted locally. If you add a presence endpoint,
     wire it where marked below. */
  function injectOnlineToggle() {
    var wrap  = document.getElementById('c-slide-online-wrap');
    var track = document.getElementById('c-slide-online-track');
    var thumb = document.getElementById('c-slide-online-thumb');
    var fill  = document.getElementById('c-slide-online-fill');
    var pill  = document.getElementById('c-online-pill');
    if (!wrap || !track || !thumb) return;

    var on = localStorage.getItem('etelCourierOnline') === '1'; // default OFFLINE until slid, matching the Driver app
    var dragging = false, startX = 0, progress = 0;

    function render() {
      wrap.style.display = on ? 'none' : 'block';
      if (pill) pill.style.display = on ? 'flex' : 'none';
      document.body.classList.toggle('is-offline', !on);
    }
    render();
    if (on) showCourierOnlineReminder();

    function setOnline(val) {
      on = val;
      localStorage.setItem('etelCourierOnline', on ? '1' : '0');
      render();
      if (navigator.vibrate && !reduced) navigator.vibrate(on ? [12, 40, 12] : 12);
      if (on) showCourierOnlineReminder(); else clearCourierOnlineReminder();
      // OPTIONAL: wire to backend presence here, e.g.
      // if (typeof api === 'function') api('courier_set_status', {online: on ? 1 : 0}).catch(function(){});
    }

    function getClientX(e) { return e.touches ? e.touches[0].clientX : e.clientX; }
    function onStart(e) {
      if (on) return;
      dragging = true; startX = getClientX(e); progress = 0;
      e.preventDefault();
    }
    function onMove(e) {
      if (!dragging) return;
      var maxX = track.offsetWidth - thumb.offsetWidth - 10;
      if (maxX <= 0) return;
      var dx = startX - getClientX(e); // positive when sliding LEFT (RTL, matches Driver app)
      dx = Math.max(0, Math.min(dx, maxX));
      progress = dx / maxX;
      thumb.style.transform = 'translateX(' + (-dx) + 'px)';
      if (fill) fill.style.transform = 'scaleX(' + progress + ')';
      e.preventDefault();
    }
    function onEnd() {
      if (!dragging) return;
      dragging = false;
      if (progress >= 0.75) {
        var maxX = track.offsetWidth - thumb.offsetWidth - 10;
        thumb.style.transform = 'translateX(' + (-maxX) + 'px)';
        if (fill) fill.style.transform = 'scaleX(1)';
        setTimeout(function () {
          thumb.style.transform = '';
          if (fill) fill.style.transform = '';
          setOnline(true);
        }, 180);
      } else {
        thumb.style.transition = 'transform .25s';
        thumb.style.transform = 'translateX(0)';
        if (fill) fill.style.transform = 'scaleX(0)';
        progress = 0;
        setTimeout(function () { thumb.style.transition = ''; }, 260);
      }
    }
    track.addEventListener('mousedown', onStart);
    track.addEventListener('touchstart', onStart, { passive: false });
    document.addEventListener('mousemove', onMove);
    document.addEventListener('touchmove', onMove, { passive: false });
    document.addEventListener('mouseup', onEnd);
    document.addEventListener('touchend', onEnd);

    /* Tap-and-hold the online pill to go back offline (mirrors the Driver
       app's hold-the-power-button-to-go-offline gesture, so the two service-
       provider apps behave the same way). */
    if (pill) {
      var holdTimer = null;
      function startHold() {
        pill.classList.add('holding');
        try { if (typeof showToast === 'function') showToast('Keep holding to go offline…'); } catch (e) {}
        holdTimer = setTimeout(function () { pill.classList.remove('holding'); setOnline(false); }, 900);
      }
      function cancelHold() { clearTimeout(holdTimer); pill.classList.remove('holding'); }
      pill.addEventListener('mousedown', startHold);
      pill.addEventListener('touchstart', startHold, { passive: true });
      pill.addEventListener('mouseup', cancelHold);
      pill.addEventListener('mouseleave', cancelHold);
      pill.addEventListener('touchend', cancelHold);
    }

    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden' && on) showCourierOnlineReminder();
    });
  }


  /* ── EARNINGS SUMMARY CARD (top of sidebar) ── */
  function injectEarningsCard() {
    var sidebar = document.querySelector('.sidebar');
    var tabs = sidebar && sidebar.querySelector('.tabs');
    if (!sidebar || !tabs || document.querySelector('.earn-card')) return;

    var card = document.createElement('div');
    card.className = 'earn-card';
    card.innerHTML =
      '<div class="earn-top">' +
        '<span class="earn-label">Today\u2019s Earnings</span>' +
        '<button class="earn-refresh" title="Refresh" aria-label="Refresh earnings">\u21bb</button>' +
      '</div>' +
      '<div class="earn-amount"><span class="cur">ETB</span><span id="earn-today">0.00</span></div>' +
      '<div class="earn-sub">' +
        '<div class="earn-stat"><b id="earn-count">0</b><span>Deliveries</span></div>' +
        '<div class="earn-stat"><b id="earn-total">ETB 0</b><span>All-time</span></div>' +
      '</div>';
    sidebar.insertBefore(card, tabs);

    card.querySelector('.earn-refresh').addEventListener('click', function () {
      var b = this; b.classList.add('spinning');
      setTimeout(function () { b.classList.remove('spinning'); }, 700);
      fetchEarnings();
    });

    fetchEarnings();
    // light auto-refresh while the dashboard is open
    setInterval(function () { if (!document.hidden) fetchEarnings(); }, 60000);
  }

  function isToday(ts) {
    if (!ts) return false;
    var d = new Date(ts), n = new Date();
    return d.getFullYear() === n.getFullYear() && d.getMonth() === n.getMonth() && d.getDate() === n.getDate();
  }

  var earnTries = 0;
  function fetchEarnings() {
    var ag = getApiGet();
    if (!ag) { // courier.js not ready yet — retry briefly
      if (earnTries++ < 25) return setTimeout(fetchEarnings, 200);
      return;
    }
    ag('courier_get_my_orders', { status: 'delivered' }).then(function (res) {
      if (!res || !res.success || !res.data) return;
      var orders = res.data.orders || [];
      var allTotal = 0, todayTotal = 0;
      orders.forEach(function (o) {
        var fee = parseFloat(o.fare_total || 0) || 0;
        allTotal += fee;
        var when = o.delivered_at || o.completed_at || o.updated_at || o.placed_at;
        if (isToday(when)) todayTotal += fee;
      });
      countUp(document.getElementById('earn-today'), todayTotal, 2);
      countUp(document.getElementById('earn-count'), orders.length, 0);
      var totalEl = document.getElementById('earn-total');
      if (totalEl) totalEl.textContent = 'ETB ' + Math.round(allTotal).toLocaleString();
    }).catch(function () {/* silent — keep last values */});
  }

  /* ── Animated count-up ── */
  function countUp(el, target, decimals) {
    if (!el) return;
    if (reduced) { el.textContent = target.toFixed(decimals); return; }
    var from = parseFloat((el.textContent || '0').replace(/[^0-9.]/g, '')) || 0;
    var start = performance.now(), dur = 650;
    function tick(now) {
      var p = Math.min((now - start) / dur, 1);
      var eased = 1 - Math.pow(1 - p, 3);
      el.textContent = (from + (target - from) * eased).toFixed(decimals);
      if (p < 1) requestAnimationFrame(tick);
      else el.textContent = target.toFixed(decimals);
    }
    requestAnimationFrame(tick);
  }

  /* ── Haptic feedback on Accept / nav buttons (delegated, no JS edits) ── */
  function wireHaptics() {
    if (!navigator.vibrate || reduced) return;
    document.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('.btn-primary, .nav-btn');
      if (b) navigator.vibrate(15);
    }, true);
  }

  /* ── Refresh earnings when Active/History tab is opened ── */
  function wireTabRefresh() {
    document.querySelectorAll('.tab-btn').forEach(function (btn) {
      btn.addEventListener('click', function () { setTimeout(fetchEarnings, 600); });
    });
  }

  // expose a manual hook in case you want to call it after deliveries
  window.refreshEarningsCard = fetchEarnings;
})();
