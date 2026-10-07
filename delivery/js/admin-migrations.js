/* ETEL Admin — Database Migrations (Settings tab)
   migrate.php is a standalone endpoint (not part of boot.php's action
   router), so this calls it directly rather than through the usual
   api(action, body) pattern the rest of admin.js uses. Status (GET, no
   apply) is read-only and safe without auth; applying pending migrations
   requires the admin's own bearer token, same as every other admin action. */
(function(){
'use strict';

function migrateUrl(){
  var base = (window.ETEL && window.ETEL.api) ? window.ETEL.api.replace('api/boot.php','') : '/delivery/';
  return base + 'api/migrate.php?_=' + Date.now();
}

function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function toast(msg, type){
  if (typeof window.toast === 'function') { window.toast(msg, type); return; }
  console.log('[migrations]', msg);
}

function renderMigrations(data){
  var wrap = document.getElementById('migrations-report');
  var runBtn = document.getElementById('migrations-run-btn');
  if (!wrap) return;
  if (!data || !data.ok) {
    wrap.innerHTML = '<div style="padding:16px;color:#E63946;background:rgba(230,57,70,.1);border:1px solid rgba(230,57,70,.25);border-radius:8px">' + esc((data && data.error) || 'Could not read migration status') + '</div>';
    return;
  }
  var pending = data.pending || 0;
  if (runBtn) runBtn.style.display = pending > 0 ? 'inline-block' : 'none';

  var summary = '<div style="display:flex;gap:20px;margin-bottom:14px">' +
    '<div><div style="font-size:22px;font-weight:800;color:' + (pending > 0 ? '#E67E22' : '#2ECC71') + '">' + pending + '</div><div style="font-size:11px;color:#B8B0A0">PENDING</div></div>' +
    '<div><div style="font-size:22px;font-weight:800;color:#3395FF">' + (data.applied || 0) + '</div><div style="font-size:11px;color:#B8B0A0">APPLIED</div></div>' +
    '<div><div style="font-size:22px;font-weight:800;color:#D4AF37">' + (data.total || 0) + '</div><div style="font-size:11px;color:#B8B0A0">TOTAL</div></div>' +
    '</div>';

  var rows = (data.migrations || []).map(function(m){
    var isPending = m.status === 'pending';
    return '<div style="display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid rgba(212,175,55,.12)">' +
      '<div><span style="font-family:monospace;font-weight:700;color:#e8e2d4">' + esc(m.version) + '</span> &nbsp; <span style="color:#c9c2b3">' + esc(m.name) + '</span></div>' +
      '<div style="font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;' +
        (isPending ? 'background:rgba(230,126,34,.15);color:#E67E22' : 'background:rgba(46,204,113,.15);color:#2ECC71') + '">' +
        (isPending ? 'PENDING' : 'APPLIED') + '</div></div>';
  }).join('');

  wrap.innerHTML = summary + '<div>' + rows + '</div>';
}

function checkMigrationStatus(silent){
  var wrap = document.getElementById('migrations-report');
  if (wrap && !silent) wrap.innerHTML = '<div style="padding:16px;color:#B8B0A0;text-align:center">Checking\u2026</div>';
  fetch(migrateUrl())
    .then(function(r){ return r.json(); })
    .then(renderMigrations)
    .catch(function(e){
      if (wrap) wrap.innerHTML = '<div style="padding:16px;color:#E63946;background:rgba(230,57,70,.1);border:1px solid rgba(230,57,70,.25);border-radius:8px">Could not reach migrate.php: ' + esc(e.message) + '</div>';
    });
}

function runPendingMigrations(){
  var btn = document.getElementById('migrations-run-btn');
  var wrap = document.getElementById('migrations-report');
  var token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
  if (!confirm('Apply all pending migrations now? This changes your live database - make sure you trust the migration files that were uploaded.')) return;
  if (btn) { btn.disabled = true; btn.textContent = '\u23f3 Applying\u2026'; }
  if (wrap) wrap.innerHTML = '<div style="padding:16px;color:#B8B0A0;text-align:center">Applying pending migrations\u2026</div>';
  fetch(migrateUrl() + '&apply=1', { headers: { 'Authorization': 'Bearer ' + token } })
    .then(function(r){ return r.json(); })
    .then(function(data){
      if (btn) { btn.disabled = false; btn.textContent = '\u25b6\ufe0f Run Pending Migrations'; }
      if (data && data.ok) {
        toast('Migrations applied', 'ok');
        checkMigrationStatus(true);
      } else {
        toast('Migration failed: ' + ((data && (data.error || data.message)) || 'Unknown error'), 'err');
        checkMigrationStatus(true);
      }
    })
    .catch(function(e){
      if (btn) { btn.disabled = false; btn.textContent = '\u25b6\ufe0f Run Pending Migrations'; }
      toast('Could not reach migrate.php: ' + e.message, 'err');
    });
}

window.checkMigrationStatus = checkMigrationStatus;
window.runPendingMigrations = runPendingMigrations;

// ── Mobile header menu (Driver/Courier/Ride links + timestamp) ─────
function toggleHeaderMobileMenu(e){
  if (e) e.stopPropagation();
  var menu = document.getElementById('header-mobile-menu');
  if (menu) menu.classList.toggle('open');
}
window.toggleHeaderMobileMenu = toggleHeaderMobileMenu;
document.addEventListener('click', function(e){
  var menu = document.getElementById('header-mobile-menu');
  var btn = document.getElementById('header-mobile-menu-btn');
  if (!menu || !menu.classList.contains('open')) return;
  if (menu.contains(e.target) || (btn && btn.contains(e.target))) return;
  menu.classList.remove('open');
});

// ── Web Push self-test ──────────────────────────────────────────────
function urlBase64ToUint8Array(base64String){
  var padding = '='.repeat((4 - base64String.length % 4) % 4);
  var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  var rawData = window.atob(base64);
  var outputArray = new Uint8Array(rawData.length);
  for (var i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
  return outputArray;
}
function ensureAdminPushSubscribed(){
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return Promise.reject(new Error('Push not supported in this browser'));
  return navigator.serviceWorker.ready.then(function(reg){
    return reg.pushManager.getSubscription().then(function(existing){
      if (existing) return existing;
      var base = (window.ETEL && window.ETEL.api) ? window.ETEL.api.replace('api/boot.php','') : '/delivery/';
      return fetch(base + 'api/boot.php?action=get_vapid_public_key')
        .then(function(r){ return r.json(); })
        .then(function(res){
          if (!res || !res.success || !res.data || !res.data.publicKey) throw new Error((res && res.error) || 'VAPID key unavailable - did you run webpush-generate-keys.php and set .env?');
          return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(res.data.publicKey) });
        });
    });
  }).then(function(subscription){
    var token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
    return fetch((window.ETEL && window.ETEL.api) || '/delivery/api/boot.php', {
      method: 'POST',
      headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'save_push_subscription', subscription: subscription.toJSON() })
    });
  });
}
function sendTestPush(){
  var report = document.getElementById('test-push-report');
  if (report) report.innerHTML = '<div style="color:#B8B0A0">Setting up subscription…</div>';
  if (Notification.permission === 'default') {
    Notification.requestPermission().then(function(p){
      if (p === 'granted') sendTestPush();
      else if (report) report.innerHTML = '<div style="color:#E63946">Notifications were denied - enable them for this site in your browser settings and try again.</div>';
    });
    return;
  }
  if (Notification.permission === 'denied') {
    if (report) report.innerHTML = '<div style="color:#E63946">Notifications are blocked for this site - enable them in your browser settings first.</div>';
    return;
  }
  ensureAdminPushSubscribed().then(function(){
    if (report) report.innerHTML = '<div style="color:#B8B0A0">Subscribed. Sending test push…</div>';
    var token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
    return fetch((window.ETEL && window.ETEL.api) || '/delivery/api/boot.php', {
      method: 'POST',
      headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'test_send_push' })
    }).then(function(r){ return r.json(); });
  }).then(function(res){
    if (!report) return;
    if (res && res.success) {
      report.innerHTML = '<div style="padding:12px;background:rgba(46,204,113,.12);color:#2ECC71;border:1px solid rgba(46,204,113,.3);border-radius:8px">✓ ' + (res.data && res.data.message || 'Sent') + '</div>';
    } else {
      report.innerHTML = '<div style="padding:12px;background:rgba(230,57,70,.12);color:#E63946;border:1px solid rgba(230,57,70,.3);border-radius:8px">' + ((res && res.message) || 'Failed') + '</div>';
    }
  }).catch(function(e){
    if (report) report.innerHTML = '<div style="padding:12px;background:rgba(230,57,70,.12);color:#E63946;border:1px solid rgba(230,57,70,.3);border-radius:8px">' + e.message + '</div>';
  });
}
window.sendTestPush = sendTestPush;

// ── End-of-panel marker, on every admin panel ───────────────────────
// Both brand coins, clearly large, at the true bottom of each tab - so
// there's never ambiguity about whether more content is hidden below.
function _appendEndMarkerToAllPanels(){
  document.querySelectorAll('.panel').forEach(function(panel){
    if (panel.querySelector('.etel-end-marker')) return; // don't duplicate on re-run
    var marker = document.createElement('div');
    marker.className = 'etel-end-marker';
    marker.innerHTML =
      '<div style="display:flex;align-items:center;justify-content:center;gap:40px;padding:44px 20px;margin-top:8px">' +
        '<img src="assets/images/etel-delivery-coin.png" alt="ETEL Delivery" style="width:200px;height:200px;object-fit:contain;filter:drop-shadow(0 10px 30px rgba(212,175,55,.45))">' +
        '<img class="etel-driver-coin" src="icons/driver-icon-512.png" alt="ETEL Driver" style="display:none;width:200px;height:200px;object-fit:contain;filter:drop-shadow(0 10px 30px rgba(212,175,55,.45))">' +
        '<img src="assets/images/etel-ride-coin.png" alt="ETEL Ride" style="width:200px;height:200px;object-fit:contain;filter:drop-shadow(0 10px 30px rgba(51,149,255,.45))">' +
      '</div>' +
      '<div style="text-align:center;font-size:11px;color:#B8B0A0;padding-bottom:24px">— End — ETEL Group —</div>';
    panel.appendChild(marker);
  });
}
document.addEventListener('DOMContentLoaded', function(){
  setTimeout(_appendEndMarkerToAllPanels, 500);
});

// Auto-check once, shortly after the Settings tab's own scripts have
// initialized, so the pending count is visible without needing a tap first.
document.addEventListener('DOMContentLoaded', function(){
  setTimeout(function(){ if (document.getElementById('migrations-report')) checkMigrationStatus(true); }, 800);
});
})();
