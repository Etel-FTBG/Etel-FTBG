/* ETEL Driver - the Pull tab and special offers.
   A new tab (bottom bar on a phone, side menu on a computer) with two lists, from driver_get_pull_requests (api/lib/ride_pull.php):
   - Special offers: trips that pay a lot (VIP) or are called from a special area staff drew in Admin (sweet area, VIP area). Shown at once, with the tag,
     the note from staff and every trip detail (pickup, drop-off, trip km, fare, how far you are, minutes to the pickup, how long it has waited).
   - Trips waiting far from you: a trip the drivers near the rider did not take for a while is shown to drivers who are at least a few km away.
   Taking a trip uses the normal accept (acceptRide, then the Requests tab shows the active ride). The Requests list is not changed.
   The tab shows a small number when something is waiting. It refreshes every 10 s while it is open and every 30 s otherwise.
   Server text goes through esc; no inline handlers. Amharic words are a first draft. */
(function () {
'use strict';
var T = {
  en: { tab: 'Pull', title: 'Pull trips', sub: 'Trips others near the rider did not take, and special offers.', offers: 'Special offers', far: 'Waiting far from you',
    none: 'Nothing to pull right now. This tab fills when a trip waits for a driver or a special offer comes in.', accept: 'Take this trip', from: 'From you', pick: 'Pickup', drop: 'Drop-off', trip: 'Trip', fare: 'Fare', waited: 'Waiting',
    min: 'min', km: 'km', refresh: 'Refresh', nopos: 'Turn on your location and go online to see trips near you.', err: 'Could not load. Pull to refresh.', taken: 'That trip was already taken.', sec: 's',
    offerNote: 'Offered at once to every driver nearby.', farNote: 'Nearer drivers did not take it yet.' },
  am: { tab: 'ጎትት', title: 'ጉዞዎችን ጎትት', sub: 'ከደንበኛው አጠገብ ያሉ ሹፌሮች ያልወሰዷቸው ጉዞዎችና ልዩ ቅናሾች።', offers: 'ልዩ ቅናሾች', far: 'ከእርስዎ ርቀው የሚጠብቁ',
    none: 'አሁን የሚጎተት የለም። ጉዞ ሲጠብቅ ወይም ልዩ ቅናሽ ሲመጣ ይሞላል።', accept: 'ይህን ጉዞ ውሰድ', from: 'ከእርስዎ', pick: 'መነሻ', drop: 'መድረሻ', trip: 'ጉዞ', fare: 'ክፍያ', waited: 'የጠበቀው',
    min: 'ደቂቃ', km: 'ኪሜ', refresh: 'አድስ', nopos: 'አካባቢዎን አብርተው መስመር ላይ ይግቡ።', err: 'መጫን አልተቻለም።', taken: 'ይህ ጉዞ ተወስዷል።', sec: 'ሰ', offerNote: 'ለአቅራቢያ ሹፌሮች ሁሉ በአንድ ጊዜ ይታያል።', farNote: 'የቀረቡ ሹፌሮች ገና አልወሰዱትም።' }
};
var POLL_OPEN = 10000, POLL_IDLE = 30000;
var timer = null, last = null, busy = false;
function lang() { try { return localStorage.getItem('etel_driver_lang') === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
function tx(k) { return (T[lang()] || T.en)[k] || T.en[k] || k; }
function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
function open() { return document.body.getAttribute('data-page') === 'pull'; }
function api(cb) {
  var tok = ''; try { tok = localStorage.getItem('etel_token') || ''; } catch (e) { /* private window */ }
  var url = (window.ETEL && ETEL.api) || '../api/boot.php';
  fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + tok }, body: JSON.stringify({ action: 'driver_get_pull_requests' }) })
    .then(function (r) { return r.json(); }).then(function (j) { cb(j && j.success ? j.data : null, j); }, function () { cb(null, null); });
}
function css() {
  if (document.getElementById('drv-pull-css')) return;
  var s = document.createElement('style'); s.id = 'drv-pull-css';
  s.textContent =
    '#page-pull .pl{padding:16px 14px 96px;max-width:760px;margin:0 auto}' +
    '#page-pull h2{margin:0 0 2px;font-size:20px;font-weight:800}#page-pull .pl-sub{margin:0 0 14px;font-size:12.5px;opacity:.75}' +
    '#page-pull h3{margin:18px 0 8px;font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;opacity:.8}' +
    '#page-pull .pl-card{margin:0 0 12px;padding:14px;border-radius:18px;border:1.5px solid rgba(212,175,55,.35);background:rgba(212,175,55,.07)}' +
    '#page-pull .pl-card.vip{border-color:#d6364a;background:linear-gradient(135deg,rgba(214,54,74,.14),rgba(212,175,55,.08))}' +
    '#page-pull .pl-card.sweet{border-color:#3dbb7a;background:linear-gradient(135deg,rgba(61,187,122,.16),rgba(212,175,55,.06))}' +
    '#page-pull .pl-tags{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px}' +
    '#page-pull .pl-tag{font-size:11.5px;font-weight:800;padding:3px 10px;border-radius:20px;background:rgba(212,175,55,.25)}' +
    '#page-pull .pl-tag.vip{background:#d6364a;color:#fff}#page-pull .pl-tag.sweet{background:#2f9e63;color:#fff}' +
    '#page-pull .pl-note{margin:0 0 8px;font-size:12.5px;font-style:italic;opacity:.9}' +
    '#page-pull .pl-fare{float:right;font-size:22px;font-weight:800}' +
    '#page-pull .pl-row{display:flex;gap:8px;margin:5px 0;font-size:13.5px;line-height:1.35}#page-pull .pl-row i{font-style:normal;flex:none;width:18px}#page-pull .pl-row b{opacity:.65;font-weight:700;margin-right:4px}' +
    '#page-pull .pl-meta{display:flex;gap:6px;flex-wrap:wrap;margin:8px 0 10px}#page-pull .pl-chip{font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:9px;background:rgba(128,128,128,.18)}' +
    '#page-pull .pl-take{width:100%;border:0;border-radius:14px;padding:13px;font:inherit;font-weight:800;font-size:14.5px;cursor:pointer;color:#1c1500;background:linear-gradient(135deg,#f3d36b,#c99a1c)}' +
    '#page-pull .pl-take[disabled]{opacity:.55}' +
    '#page-pull .pl-empty{padding:34px 14px;text-align:center;opacity:.75;border:1.5px dashed rgba(128,128,128,.45);border-radius:18px;font-size:13.5px}' +
    '#page-pull .pl-top{display:flex;align-items:flex-start;gap:10px}#page-pull .pl-top>div{flex:1}' +
    '#page-pull .pl-ref{border:1.5px solid rgba(212,175,55,.5);background:transparent;color:inherit;border-radius:12px;padding:8px 12px;font:inherit;font-weight:700;font-size:12.5px;cursor:pointer}' +
    '#page-pull,#page-pull *{-webkit-text-fill-color:currentColor !important}' +
    '.nav-tab .pl-bd{position:absolute;top:4px;margin-left:14px;min-width:17px;padding:1px 5px;border-radius:10px;font-size:10.5px;font-weight:800;line-height:15px;text-align:center;background:#d6364a;color:#fff}' +
    '.nav-tab[data-page="pull"]{position:relative}';
  document.head.appendChild(s);
}
function mins(s) { s = Number(s) || 0; return s < 90 ? Math.max(0, Math.round(s)) + ' ' + tx('sec') : Math.round(s / 60) + ' ' + tx('min'); }
function card(r, kind) {
  var tags = (r.tags || []).map(function (g) { return '<span class="pl-tag ' + esc(g.kind) + '">' + (g.kind === 'vip' ? '💎 ' : '🍬 ') + esc(g.label) + '</span>'; }).join('');
  var notes = (r.tags || []).filter(function (g) { return g.note && g.kind !== 'vip'; }).map(function (g) { return esc(g.note); }).join(' · ');
  var cls = (r.tags || []).some(function (g) { return g.kind === 'vip'; }) ? 'vip' : ((r.tags || []).length ? 'sweet' : '');
  return '<div class="pl-card ' + cls + '" data-id="' + esc(r.id) + '">' + (tags ? '<div class="pl-tags">' + tags + '</div>' : '') + (notes ? '<p class="pl-note">' + notes + '</p>' : '') +
    '<span class="pl-fare">ETB ' + esc(r.fare) + '</span>' +
    '<div class="pl-row"><i>📍</i><span><b>' + esc(tx('pick')) + '</b>' + esc(r.pickup_address || '—') + '</span></div>' +
    '<div class="pl-row"><i>🏁</i><span><b>' + esc(tx('drop')) + '</b>' + esc(r.dropoff_address || '—') + '</span></div>' +
    '<div class="pl-meta"><span class="pl-chip">' + esc(tx('trip')) + ' ' + esc(r.trip_km) + ' ' + esc(tx('km')) + '</span><span class="pl-chip">' + esc(tx('from')) + ' ' + esc(r.from_driver_km) + ' ' + esc(tx('km')) + ' · ~' + esc(r.eta_min) + ' ' + esc(tx('min')) + '</span>' +
    '<span class="pl-chip">' + esc(tx('waited')) + ' ' + esc(mins(r.waiting_s)) + '</span>' + (r.passenger_name ? '<span class="pl-chip">👤 ' + esc(r.passenger_name) + '</span>' : '') + '</div>' +
    '<button type="button" class="pl-take" data-take="' + esc(r.id) + '">⚡ ' + esc(tx('accept')) + '</button></div>';
}
function paint(d, err) {
  var pg = document.getElementById('page-pull'); if (!pg) return;
  var host = pg.querySelector('.pl'); if (!host) return;
  var h = '<div class="pl-top"><div><h2>⚡ ' + esc(tx('title')) + '</h2><p class="pl-sub">' + esc(tx('sub')) + '</p></div><button type="button" class="pl-ref" data-pl-refresh>🔄 ' + esc(tx('refresh')) + '</button></div>';
  if (!d) h += '<div class="pl-empty">' + esc(tx('err')) + '</div>';
  else if (d.no_position) h += '<div class="pl-empty">📍 ' + esc(tx('nopos')) + '</div>';
  else {
    var o = d.offers || [], p = d.pull || [];
    if (!o.length && !p.length) h += '<div class="pl-empty">⚡ ' + esc(tx('none')) + '</div>';
    if (o.length) h += '<h3>💎 ' + esc(tx('offers')) + ' (' + o.length + ')</h3>' + o.map(function (r) { return card(r, 'offer'); }).join('');
    if (p.length) h += '<h3>🕒 ' + esc(tx('far')) + ' (' + p.length + ')</h3>' + p.map(function (r) { return card(r, 'pull'); }).join('');
  }
  host.innerHTML = h;
}
function badge(d) {
  var n = d && !d.no_position ? (d.offers || []).length + (d.pull || []).length : 0;
  Array.prototype.forEach.call(document.querySelectorAll('.nav-tab[data-page="pull"]'), function (b) {
    var el = b.querySelector('.pl-bd');
    if (!n) { if (el) el.parentNode.removeChild(el); return; }
    if (!el) { el = document.createElement('span'); el.className = 'pl-bd'; b.appendChild(el); }
    el.textContent = n > 9 ? '9+' : String(n);
  });
}
function refresh() {
  if (busy) return; busy = true;
  api(function (d) { busy = false; last = d; badge(d); if (open() || d === null && open()) paint(d); });
}
function loop() { clearTimeout(timer); timer = setTimeout(function () { if (!document.hidden) refresh(); loop(); }, open() ? POLL_OPEN : POLL_IDLE); }

function build() {
  if (document.getElementById('page-pull')) return true;
  var money = document.getElementById('page-money'); if (!money || !money.parentNode) return false;
  css();
  var pg = document.createElement('div'); pg.className = 'drv-page'; pg.id = 'page-pull'; pg.innerHTML = '<div class="pl"></div>';
  money.parentNode.insertBefore(pg, money.nextSibling);
  var bottom = document.querySelector('.driver-bottom-nav'), prof = bottom && bottom.querySelector('.nav-tab[data-page="profile"]');
  if (bottom && prof) {
    var b = document.createElement('button'); b.type = 'button'; b.className = 'nav-tab'; b.setAttribute('data-page', 'pull');
    b.innerHTML = '<span class="ni"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></svg></span><span class="nl">' + esc(tx('tab')) + '</span>';
    bottom.insertBefore(b, prof);
  }
  var side = document.querySelector('.sidebar-nav .sidebar-item[data-page="profile"]');
  if (side) {
    var sb = document.createElement('button'); sb.type = 'button'; sb.className = 'sidebar-item nav-tab'; sb.setAttribute('data-page', 'pull'); sb.title = tx('tab');
    sb.innerHTML = '<span class="si-icon-box"><span class="si-icon">⚡</span></span><span class="si-label">' + esc(tx('tab')) + '</span>';
    side.parentNode.insertBefore(sb, side);
  }
  return true;
}
function wire() {
  document.addEventListener('click', function (e) {
    var t = e.target; if (!t.closest) return;
    var nav = t.closest('.nav-tab[data-page="pull"]');
    if (nav) { if (typeof window.switchPage === 'function') window.switchPage('pull'); return; }
    if (t.closest('[data-pl-refresh]')) { refresh(); return; }
    var tk = t.closest('[data-take]');
    if (tk) {
      var id = Number(tk.getAttribute('data-take')); tk.disabled = true;
      if (typeof window.acceptRide === 'function') {
        window.acceptRide(id);   // the normal accept: it shows the active ride and the route
        setTimeout(function () { if (typeof window.switchPage === 'function') window.switchPage('requests'); refresh(); }, 600);
      }
    }
  });
  var orig = window.switchPage;
  if (typeof orig === 'function' && !orig._pl) {
    window.switchPage = function (page) { var r = orig.apply(this, arguments); if (page === 'pull') { paint(last, null); refresh(); } loop(); return r; };
    window.switchPage._pl = true;
  }
}
function boot() { if (!build()) return; wire(); refresh(); loop(); }
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(boot, 1500); }); else setTimeout(boot, 1500);
})();
