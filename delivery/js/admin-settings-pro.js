/* ETEL Admin - Settings hub.
   Settings was one long page of unrelated boxes. This sorts the same boxes into four tabs - My account, Devices & alerts,
   Announcements, Owner tools - under a header that says who is signed in and how much access they have. A tab only shows
   when the person can open something in it, and the box is only moved, never rebuilt, so every button and form inside
   keeps working exactly as before (password change, printer, web push test, code audit, migrations, announcements, Etel Pay).
   The new password box also gets a "show" switch and a strength bar. Nothing is sent to the server by this file.
   Standalone; every value shown comes from this browser (the signed-in profile and the capability list). */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;
var TABS = [
  { key: 'account', icon: '👤', label: 'My account', titles: ['Account Settings'] },
  { key: 'devices', icon: '🖨️', label: 'Devices & alerts', titles: ['Receipt Printer', 'Web Push Self-Test', 'System Info'] },
  { key: 'announce', icon: '📣', label: 'Announcements', titles: ['Announcements'] },
  { key: 'owner', icon: '🛠️', label: 'Owner tools', titles: ['Code Audit', 'Database Migrations', 'Etel Pay Wallet'] }
];
var cur = 'account', built = false;

function css() {
  if (document.getElementById('sx-css')) return;
  var s = document.createElement('style'); s.id = 'sx-css';
  s.textContent = [
    '#set-pro .data-section{background:var(--card);border:1.5px solid var(--line);border-radius:20px;box-shadow:var(--shadow);padding:18px 20px;margin-bottom:14px;color:var(--ink)}',
    '#set-pro .section-title{font-size:15px;font-weight:800;margin-bottom:10px;color:var(--ink)}',
    '#set-pro .filter-sel,#set-pro input[type=text],#set-pro input[type=password],#set-pro select,#set-pro textarea{border-radius:12px;border:1.5px solid var(--line);background:var(--card);color:var(--ink);padding:10px 12px;font:inherit;font-size:13px}',
    '#set-pro .filter-sel:focus,#set-pro input:focus,#set-pro select:focus,#set-pro textarea:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px rgba(212,175,55,.22)}',
    '#set-pro .data-section p,#set-pro .data-section label{color:var(--ink)}',
    '#set-pro .sx-grp{display:none}#set-pro .sx-grp.on{display:block}',
    '.sx-meter{height:6px;border-radius:6px;background:var(--line);overflow:hidden;margin:-4px 0 6px;max-width:420px}',
    '.sx-meter i{display:block;height:100%;width:0;border-radius:6px;transition:width .2s,background .2s}',
    '.sx-meter-t{font-size:11.5px;color:var(--mute);margin-bottom:10px;min-height:15px}'
  ].join('\n');
  document.head.appendChild(s);
}

function titleOf(sec) { var t = sec.querySelector('.section-title'); return t ? t.textContent.replace(/^[^A-Za-z]+/, '').trim() : ''; }
function hidden(el) { return el.style.display === 'none' || el.classList.contains('etel-cap-hidden'); }

function caps() { return Array.isArray(window.ETEL_CAPS) ? window.ETEL_CAPS : null; }
function accessText() {
  var c = caps(); if (!c) return '—';
  return c.indexOf('*') >= 0 ? 'Full access' : c.length + ' permission' + (c.length === 1 ? '' : 's');
}

function build() {
  var panel = document.getElementById('panel-settings'), host = document.getElementById('set-pro');
  if (!panel || !host || built) return;
  K.css(); css(); built = true;
  var secs = Array.prototype.slice.call(panel.querySelectorAll(':scope > .data-section'));
  var u = null; try { u = Auth.getUser(); } catch (e) { /* no profile */ }
  var groups = TABS.map(function (t) { return '<div class="sx-grp" data-grp="' + t.key + '" role="tabpanel"></div>'; }).join('');
  host.innerHTML = '<div class="ak">' + K.hero('Settings', 'Your account, your devices and the tools you are allowed to use.', [
      ['Signed in as', u && u.name ? u.name : '—'], ['Role', u && u.role ? String(u.role).replace(/_/g, ' ') : '—'], ['Your access', accessText()]]) +
    '<div id="sx-tabs"></div><div id="sx-groups">' + groups + '</div></div>';
  var assigned = {};
  secs.forEach(function (s) {
    var tt = titleOf(s), g = null;
    TABS.forEach(function (t) { t.titles.forEach(function (x) { if (tt.indexOf(x) === 0 || tt.indexOf(x) > -1) g = g || t.key; }); });
    g = g || 'devices';                       // anything new that no tab claims lands in Devices & alerts, so nothing disappears
    host.querySelector('[data-grp="' + g + '"]').appendChild(s); assigned[g] = 1;
  });
  meter();
  tabs();
  host.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-seg="sxtab"]') : null;
    if (b) { cur = b.getAttribute('data-val'); tabs(); }
    var sh = e.target.closest ? e.target.closest('[data-sx-show]') : null;
    if (sh) { ['set-cur-pw', 'set-new-pw', 'set-conf-pw'].forEach(function (id) { var i = document.getElementById(id); if (i) i.type = i.type === 'password' ? 'text' : 'password'; }); sh.textContent = sh.textContent.indexOf('Show') >= 0 ? '🙈 Hide passwords' : '👁 Show passwords'; }
  });
  host.addEventListener('input', function (e) { if (e.target.id === 'set-new-pw') strength(e.target.value); });
}

function tabs() {
  var host = document.getElementById('set-pro'); if (!host) return;
  var open = TABS.filter(function (t) {
    var g = host.querySelector('[data-grp="' + t.key + '"]');
    return g && Array.prototype.some.call(g.children, function (c) { return !hidden(c); });
  });
  if (!open.some(function (t) { return t.key === cur; }) && open.length) cur = open[0].key;
  var bar = document.getElementById('sx-tabs');
  if (bar) bar.innerHTML = '<div style="margin:2px 0 12px">' + K.seg('sxtab', open.map(function (t) { return [t.key, t.icon + ' ' + t.label]; }), cur) + '</div>';
  Array.prototype.forEach.call(host.querySelectorAll('.sx-grp'), function (g) { g.classList.toggle('on', g.getAttribute('data-grp') === cur); });
}

function meter() {
  var inp = document.getElementById('set-new-pw'); if (!inp || document.getElementById('sx-meter')) return;
  var w = document.createElement('div');
  w.innerHTML = '<div class="sx-meter" id="sx-meter" aria-hidden="true"><i></i></div><div class="sx-meter-t" id="sx-meter-t" aria-live="polite"></div><button type="button" class="ak-btn" data-sx-show style="margin-bottom:14px">👁 Show passwords</button>';
  inp.parentNode.insertBefore(w, inp.nextSibling);
  inp.style.marginBottom = '8px';
}
function strength(v) {
  var bar = document.querySelector('#sx-meter i'), t = document.getElementById('sx-meter-t'); if (!bar || !t) return;
  var s = 0; if (v.length >= 8) s++; if (v.length >= 12) s++; if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++; if (/\d/.test(v)) s++; if (/[^A-Za-z0-9]/.test(v)) s++;
  if (!v) { bar.style.width = '0'; t.textContent = ''; return; }
  var lv = v.length < 8 ? 0 : s <= 2 ? 1 : s <= 3 ? 2 : 3, names = ['Too short — use at least 8 characters', 'Weak — add numbers or capital letters', 'Good', 'Strong'], col = ['#d6364a', '#d98e04', '#2f6fd0', '#1fa463'];
  bar.style.width = (lv + 1) * 25 + '%'; bar.style.background = col[lv]; t.textContent = names[lv];
}

function install() {
  if (!document.getElementById('set-pro')) return;
  build();
  var p = window.ETEL_CAPS_PROMISE; if (p && p.then) p.then(function () { setTimeout(function () { var h = document.getElementById('set-pro'); if (h) { var ap = h.querySelector('.ak-hero-big div:last-child b'); if (ap) ap.textContent = accessText(); } tabs(); }, 60); });
  var orig = window.switchPage;
  if (typeof orig === 'function' && !orig._sx) {
    window.switchPage = function (page) { var r = orig.apply(this, arguments); if (page === 'settings') setTimeout(tabs, 80); return r; };
    window.switchPage._sx = true;
  }
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
