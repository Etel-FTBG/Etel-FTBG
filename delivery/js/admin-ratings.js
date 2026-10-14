/* ETEL Admin - Ratings & Reviews page.
   What customers said about shops, couriers and food: averages, star spread, common tags, lowest-rated couriers and shops,
   and the newest ratings with the low ones flagged. Data: api action admin_ratings_report (api/lib/ratings_report.php).
   Standalone on purpose: it adds its own menu item and page, so no other admin file changes. */
(function () {
'use strict';
var TAGS = { fast_delivery: 'Fast delivery', great_taste: 'Great taste', well_packed: 'Well packed', friendly_courier: 'Friendly courier', good_value: 'Good value', late: 'Late', cold: 'Cold', wrong_item: 'Wrong item' };
var BAD = { late: 1, cold: 1, wrong_item: 1 };
var days = 30;

function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
function api(action, body) {
  var token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
  var url = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
  return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token }, body: JSON.stringify(Object.assign({ action: action }, body || {})) }).then(function (r) { return r.json(); });
}
function stars(n) { n = Math.round(Number(n) || 0); return '<span style="color:#D4AF37;letter-spacing:1px" aria-label="' + n + ' of 5 stars">' + '★'.repeat(n) + '<span style="opacity:.25">' + '★'.repeat(5 - n) + '</span></span>'; }
function avg(v) { return v == null ? '—' : Number(v).toFixed(1); }
function when(s) { s = String(s || ''); return s.length >= 16 ? s.slice(5, 10).replace('-', '/') + ' ' + s.slice(11, 16) : s; }
function panel() { return document.getElementById('panel-ratings'); }

function kpi(label, value, sub, bad) {
  return '<div style="flex:1;min-width:120px;border:1px solid rgba(128,128,128,.3);border-radius:12px;padding:10px 12px">' +
    '<div style="font-size:11px;opacity:.7;text-transform:uppercase;letter-spacing:.04em">' + esc(label) + '</div>' +
    '<div style="font-size:24px;font-weight:800;' + (bad ? 'color:#e74c3c' : '') + '">' + value + '</div>' +
    '<div style="font-size:11px;opacity:.65">' + esc(sub) + '</div></div>';
}
function miniTable(title, rows, what) {
  var h = '<div style="flex:1;min-width:240px"><div style="font-weight:700;margin-bottom:6px">' + esc(title) + '</div>';
  if (!rows.length) return h + '<div style="font-size:12px;opacity:.65">No ratings yet.</div></div>';
  rows.forEach(function (r) {
    var few = Number(r.n) < 3;
    h += '<div style="display:flex;justify-content:space-between;gap:8px;padding:6px 0;border-bottom:1px solid rgba(128,128,128,.2);font-size:13px">' +
      '<span>' + esc(r.name) + '</span><span>' + stars(r.avg) + ' <b>' + avg(r.avg) + '</b> <span style="opacity:.6;font-size:11px">(' + Number(r.n) + (few ? ', few ratings' : '') + ')</span></span></div>';
  });
  return h + '</div>';
}

function render(d) {
  var el = panel(); if (!el) return;
  var s = d.summary || {}, st = s.stars || {};
  var h = '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px">' +
    '<div><div style="font-size:17px;font-weight:800">Ratings &amp; reviews</div><div style="font-size:12px;opacity:.65">What customers said about shops, couriers and food</div></div>' +
    '<div class="pp-range">' + [7, 30, 90].map(function (n) { return '<button type="button" data-rdays="' + n + '"' + (n === d.days ? ' class="active"' : '') + '>' + n + ' days</button>'; }).join('') + '</div></div>';
  if (!s.count) {
    el.innerHTML = h + '<div style="padding:18px;border:1px dashed rgba(128,128,128,.4);border-radius:12px;opacity:.8">No ratings in the last ' + d.days + ' days. Ratings appear here after a customer rates a delivered order.</div>';
    wire(el); return;
  }
  h += '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">' +
    kpi('Ratings', Number(s.count), 'last ' + d.days + ' days') + kpi('Overall', avg(s.avg) + ' ★', 'average stars') +
    kpi('Courier', avg(s.avg_courier) + (s.avg_courier == null ? '' : ' ★'), 'when customers rated the courier') + kpi('Food / order', avg(s.avg_food) + (s.avg_food == null ? '' : ' ★'), 'when customers rated it') +
    kpi('Low ratings', Number(s.low), '1-2 stars, or a courier / food 1', Number(s.low) > 0) + '</div>';
  var max = Math.max(1, st[1] || 0, st[2] || 0, st[3] || 0, st[4] || 0, st[5] || 0);
  h += '<div style="margin-bottom:14px"><div style="font-weight:700;margin-bottom:6px">Stars</div>' + [5, 4, 3, 2, 1].map(function (n) {
    var c = Number(st[n] || 0);
    return '<div style="display:flex;align-items:center;gap:8px;font-size:12px;margin-bottom:3px"><span style="width:26px">' + n + ' ★</span><span style="flex:1;height:8px;border-radius:6px;background:rgba(128,128,128,.2);overflow:hidden"><span style="display:block;height:100%;width:' + Math.round(100 * c / max) + '%;background:' + (n <= 2 ? '#e74c3c' : '#D4AF37') + '"></span></span><span style="width:28px;text-align:right">' + c + '</span></div>';
  }).join('') + '</div>';
  if ((d.tags || []).length) {
    h += '<div style="margin-bottom:14px"><div style="font-weight:700;margin-bottom:6px">What people picked</div><div style="display:flex;gap:6px;flex-wrap:wrap">' + d.tags.map(function (t) {
      return '<span style="padding:4px 10px;border-radius:999px;font-size:12px;border:1px solid ' + (BAD[t.tag] ? '#e74c3c' : 'rgba(212,175,55,.5)') + ';' + (BAD[t.tag] ? 'color:#e74c3c' : '') + '">' + esc(TAGS[t.tag] || t.tag) + ' <b>' + Number(t.n) + '</b></span>';
    }).join('') + '</div></div>';
  }
  h += '<div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:14px">' + miniTable('Lowest-rated couriers', d.couriers || [], 'courier') + miniTable('Lowest-rated shops', d.shops || [], 'shop') + '</div>';
  h += '<div style="font-weight:700;margin-bottom:6px">Newest ratings</div>' + (d.recent || []).map(function (r) {
    var meta = [r.shop, r.courier ? 'courier ' + r.courier : '', r.customer, r.order_code].filter(Boolean).map(esc).join(' · ');
    var extra = [];
    if (r.food_rating) extra.push('food ' + Number(r.food_rating) + '★');
    if (r.courier_rating) extra.push('courier ' + Number(r.courier_rating) + '★');
    return '<div style="border:1px solid ' + (r.low ? '#e74c3c' : 'rgba(128,128,128,.3)') + ';border-radius:10px;padding:8px 10px;margin-bottom:8px">' +
      '<div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;font-size:13px"><span>' + stars(r.stars) + (r.low ? ' <b style="color:#e74c3c">Low</b>' : '') + (extra.length ? ' <span style="opacity:.7;font-size:12px">' + esc(extra.join(' · ')) + '</span>' : '') + '</span><span style="opacity:.65;font-size:12px">' + esc(when(r.rated_at)) + '</span></div>' +
      '<div style="font-size:12px;opacity:.75;margin-top:2px">' + meta + '</div>' +
      ((r.tags || []).length ? '<div style="margin-top:4px;display:flex;gap:5px;flex-wrap:wrap">' + r.tags.map(function (t) { return '<span style="font-size:11px;padding:2px 8px;border-radius:999px;border:1px solid ' + (BAD[t] ? '#e74c3c' : 'rgba(212,175,55,.5)') + ';' + (BAD[t] ? 'color:#e74c3c' : '') + '">' + esc(TAGS[t] || t) + '</span>'; }).join('') + '</div>' : '') +
      (r.comment ? '<div style="margin-top:5px;font-size:13px">“' + esc(r.comment) + '”</div>' : '') + '</div>';
  }).join('');
  el.innerHTML = h; wire(el);
}
function wire(el) {
  Array.prototype.forEach.call(el.querySelectorAll('[data-rdays]'), function (b) { b.onclick = function () { days = parseInt(b.getAttribute('data-rdays'), 10) || 30; load(); }; });
}
function load() {
  var el = panel(); if (!el) return;
  el.innerHTML = '<div style="padding:14px;opacity:.7">Loading ratings…</div>';
  api('admin_ratings_report', { days: days }).then(function (r) {
    if (r && r.success) render(r.data); else el.innerHTML = '<div style="padding:14px;color:#e74c3c">Ratings: ' + esc((r && r.message) || 'could not load') + '</div>';
  }).catch(function (e) { el.innerHTML = '<div style="padding:14px;color:#e74c3c">Ratings: ' + esc(e.message) + '</div>'; });
}

function install() {
  if (document.querySelector('[data-page="ratings"]')) return;
  var after = document.querySelector('.nav-item[data-page="orderchats"]') || document.querySelector('.nav-item[data-page="support"]');
  if (after) {
    var nav = document.createElement('div');
    nav.className = 'nav-item'; nav.setAttribute('data-page', 'ratings');
    nav.setAttribute('onclick', "switchPage('ratings',this)");
    nav.innerHTML = '<span class="nav-icon">⭐</span><span>Ratings</span>';
    after.parentNode.insertBefore(nav, after.nextSibling);
  }
  var host = document.getElementById('panel-orderchats') || document.getElementById('panel-support');
  if (host && !panel()) {
    var p = document.createElement('div'); p.className = 'panel'; p.id = 'panel-ratings'; p.style.display = 'none';
    host.parentNode.appendChild(p);
  }
  // title, empty action buttons and loading when the page is opened (from the menu or from the dashboard's "Low ratings" row)
  var orig = window.switchPage;
  if (typeof orig === 'function') {
    window.switchPage = function (page) {
      var r = orig.apply(this, arguments);
      if (page === 'ratings') {
        var t = document.getElementById('page-title'); if (t) t.textContent = 'Ratings & Reviews';
        var ab = document.getElementById('action-buttons'); if (ab) ab.innerHTML = '';
        load();
      }
      return r;
    };
  }
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
