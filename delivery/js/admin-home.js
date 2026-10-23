/* ETEL Admin - dashboard "Needs your attention".
   A short list at the top of the dashboard of what a person should look at now (late orders, orders with no courier,
   confirmations stuck, couriers and businesses waiting to be approved). Each line opens the right page already
   filtered. Only lines the signed-in person may open are counted; when nothing needs attention it says so.
   Data: admin_attention (api/controllers/AdminAttentionController.php). Look and pieces from js/admin-kit.js. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;
var ICON = { late_orders: '⏰', no_courier: '🛵', stuck_confirms: '🔔', couriers_pending: '🕒', sg_pending: '🏪' };
var timer = null, busy = false;

function host() {
  var p = document.getElementById('panel-dashboard'); if (!p) return null;
  var el = document.getElementById('home-attention');
  if (!el) { el = document.createElement('div'); el.id = 'home-attention'; p.insertBefore(el, p.firstChild); }
  return el;
}
function paint(d) {
  var el = host(); if (!el) return;
  var h = '<div class="ak"><div class="ak-card" style="cursor:default;margin-bottom:16px" role="region" aria-label="Needs your attention"><div class="ak-top"><div class="ak-av" style="--a:#E8C14A;--b:#9a7a1c">' + (d.all_clear ? '✅' : '🔔') + '</div><div class="ak-nm"><b>' + (d.all_clear ? 'All clear' : 'Needs your attention') + '</b><small>' + (d.all_clear ? 'Nothing is waiting on you right now.' : 'Tap a line to open it, already filtered.') + '</small></div><button type="button" class="ak-btn" data-home-refresh aria-label="Refresh">🔄</button></div>';
  if (!d.all_clear) {
    h += '<div class="ak-chips" style="margin-top:12px">' + d.items.map(function (it) {
      return '<button type="button" class="ak-btn' + (it.tone === 'red' ? ' danger' : '') + '" data-home-go="' + esc(it.page) + '" data-filter="' + esc(it.filter) + '" data-period="' + esc(it.period) + '">' + esc(ICON[it.key] || '•') + ' <b>' + esc(it.count) + '</b> ' + esc(it.label) + ' →</button>';
    }).join('') + '</div>';
  }
  el.innerHTML = h + '</div></div>';
}
function load() {
  if (busy || !host()) return;
  busy = true;
  K.css();
  K.api('admin_attention', {}).then(function (res) {
    busy = false;
    if (!K.okRes(res)) { var el = host(); if (el) el.innerHTML = ''; return; }   // the dashboard works without it
    paint(K.data(res));
  });
}
function visible() { var p = document.getElementById('panel-dashboard'); return p && p.style.display !== 'none' && !document.hidden; }

function install() {
  var p = document.getElementById('panel-dashboard'); if (!p) return;
  if (!p._homeWired) {
    p._homeWired = true;
    p.addEventListener('click', function (e) {
      var g = e.target.closest ? e.target.closest('[data-home-go]') : null;
      if (g && window.AdminNav) { window.AdminNav.go(g.getAttribute('data-home-go'), g.getAttribute('data-filter'), g.getAttribute('data-period')); return; }
      if (e.target.closest && e.target.closest('[data-home-refresh]')) load();
    });
  }
  var orig = window.switchPage;
  if (typeof orig === 'function' && !orig._home) {
    window.switchPage = function (page) { var r = orig.apply(this, arguments); if (page === 'dashboard') load(); return r; };
    window.switchPage._home = true;
  }
  load();
  if (!timer) timer = setInterval(function () { if (visible()) load(); }, 60000);
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
