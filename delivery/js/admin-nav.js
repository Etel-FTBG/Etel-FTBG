/* ETEL Admin - grouped menu.
   The sidebar was one flat list of 25 items. This sorts the same items into sections (Overview, Operations, People,
   Catalog & pricing, Money, Support & records, System), lets a section fold away, adds a "find a page" box, hides a
   section when the signed-in person can open nothing in it, and makes every menu item reachable with the keyboard
   (Tab, Enter, Space) and announced properly by screen readers (role, aria-current, aria-expanded).
   Standalone: it moves the existing menu items, it does not recreate them, so their click handlers and the
   capability hiding in admin-roles.js keep working. On phones the menu stays the single scrolling bar it was.
   window.AdminNav.go(page, filter, period) opens a page already filtered (used by the dashboard's "Needs attention"). */
(function () {
'use strict';

var GROUPS = [
  { id: 'overview', label: 'Overview', icon: '📊', pages: ['dashboard'] },
  { id: 'ops', label: 'Operations', icon: '🧾', pages: ['orders', 'packages', 'rides', 'ridepay', 'orderchats'] },
  { id: 'people', label: 'People', icon: '👥', pages: ['users', 'customers', 'couriers', 'servicegivers'] },
  { id: 'catalog', label: 'Catalog & pricing', icon: '🏷️', pages: ['items', 'menucat', 'pricing', 'promos'] },
  { id: 'money', label: 'Money', icon: '💰', pages: ['telebirr', 'finance', 'equb', 'reports'] },
  { id: 'support', label: 'Support & records', icon: '🛟', pages: ['support', 'documents', 'launch'] },
  { id: 'system', label: 'System', icon: '⚙️', pages: ['settings', 'branding', 'roles', 'releases', 'updater', 'danger', '#2fa', '#display'] }
];
/* Pages added by other scripts after the menu loads need a capability here (the rest are hidden by admin-roles.js). */
var EXTRA_CAPS = { customers: 'users.view', packages: 'orders.view' };
var OPEN_BY_DEFAULT = { overview: 1, ops: 1, people: 1 };
var STORE = 'admin-nav-open';
var state = {};
try { state = JSON.parse(localStorage.getItem(STORE) || '{}') || {}; } catch (e) { state = {}; }
function save() { try { localStorage.setItem(STORE, JSON.stringify(state)); } catch (e) { /* private window: not remembered */ } }

function sidebar() { return document.querySelector('.sidebar'); }
function itemFor(key) {
  var sb = sidebar(); if (!sb) return null;
  if (key === '#2fa') return sb.querySelector('[data-etel-2fa-open]');
  if (key === '#display') return sb.querySelector('[data-etel-display-open]');
  return sb.querySelector('.nav-item[data-page="' + key + '"]');
}
function groupOf(page) { for (var i = 0; i < GROUPS.length; i++) if (GROUPS[i].pages.indexOf(page) >= 0) return GROUPS[i].id; return ''; }
function isOpen(g) { return state[g] === undefined ? !!OPEN_BY_DEFAULT[g] : !!state[g]; }

function css() {
  if (document.getElementById('anav-css')) return;
  var s = document.createElement('style'); s.id = 'anav-css';
  s.textContent = [
    '.anav-find{margin:4px 6px 8px}',
    '.anav-find input{width:100%;box-sizing:border-box;padding:8px 10px;border-radius:10px;border:1px solid rgba(128,128,128,.35);background:transparent;color:inherit;font-size:12.5px}',
    '.anav-find input:focus{outline:2px solid #D4AF37;outline-offset:1px}',
    '.anav-group{margin-bottom:4px}',
    '.anav-head{display:flex;width:100%;align-items:center;gap:8px;padding:8px 10px;margin:2px 0;border:0;background:transparent;color:inherit;font:inherit;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;opacity:.75;cursor:pointer;border-radius:8px;text-align:left}',
    '.anav-head:hover{opacity:1;background:rgba(212,175,55,.1)}',
    '.anav-head:focus-visible,.nav-item:focus-visible{outline:2px solid #D4AF37;outline-offset:-2px}',
    '.anav-head .anav-caret{margin-left:auto;transition:transform .15s}',
    '.anav-group.closed .anav-caret{transform:rotate(-90deg)}',
    '.anav-group.closed .anav-body{display:none}',
    '.anav-group.anav-empty{display:none}',
    '.anav-nomatch{padding:10px;font-size:12px;opacity:.7}',
    '@media(max-width:768px){.anav-find,.anav-head,.anav-nomatch{display:none!important}.anav-group,.anav-body{display:contents!important}.anav-group.closed .anav-body{display:contents!important}}',
    '.anav-skip{position:absolute;left:8px;top:-60px;z-index:100000;padding:10px 16px;border-radius:10px;background:#1A1A2E;color:#fff;font-weight:700;font-size:14px;text-decoration:none;border:2px solid #F2CC6B}',
    '.anav-skip:focus{top:8px}',
    '.anav-head:focus-visible,.anav-find input:focus-visible{outline:3px solid #F2CC6B;outline-offset:2px}',
    '@media(prefers-reduced-motion:reduce){.anav-caret{transition:none}}'
  ].join('\n');
  document.head.appendChild(s);
}

function build() {
  var sb = sidebar(); if (!sb) return;
  css();
  sb.setAttribute('role', 'navigation'); sb.setAttribute('aria-label', 'Main menu');
  var main = document.querySelector('.content');
  if (main && !document.getElementById('anav-skip')) {
    main.id = main.id || 'admin-main'; main.setAttribute('tabindex', '-1');
    var skip = document.createElement('a'); skip.id = 'anav-skip'; skip.className = 'anav-skip'; skip.href = '#' + main.id; skip.textContent = 'Skip to main content';
    skip.addEventListener('click', function (e) { e.preventDefault(); main.focus(); });
    document.body.insertBefore(skip, document.body.firstChild);
    var pt = document.getElementById('page-title'); if (pt) { pt.setAttribute('aria-live', 'polite'); pt.setAttribute('role', 'heading'); pt.setAttribute('aria-level', '1'); }
  }
  var find = sb.querySelector('.anav-find');
  if (!find) {
    find = document.createElement('div'); find.className = 'anav-find';
    find.innerHTML = '<input type="search" id="anav-q" placeholder="Find a page…" aria-label="Find a page" autocomplete="off">';
    sb.insertBefore(find, sb.firstChild);
  }
  var apps = document.getElementById('header-mobile-menu-btn');
  var placed = {};
  GROUPS.forEach(function (g) {
    var wrap = sb.querySelector('.anav-group[data-g="' + g.id + '"]');
    if (!wrap) {
      wrap = document.createElement('div'); wrap.className = 'anav-group'; wrap.setAttribute('data-g', g.id);
      wrap.innerHTML = '<button type="button" class="anav-head" aria-expanded="true" aria-controls="anav-b-' + g.id + '"><span aria-hidden="true">' + g.icon + '</span><span>' + g.label + '</span><span class="anav-caret" aria-hidden="true">▾</span></button><div class="anav-body" id="anav-b-' + g.id + '" role="group" aria-label="' + g.label + '"></div>';
      sb.insertBefore(wrap, apps || null);
    }
    var body = wrap.querySelector('.anav-body');
    g.pages.forEach(function (p) {
      var el = itemFor(p);
      if (el) { if (el.parentNode !== body) body.appendChild(el); placed[p] = 1; }
    });
  });
  /* anything new that no group claims goes under System, so nothing ever disappears from the menu */
  Array.prototype.forEach.call(sb.querySelectorAll(':scope > .nav-item'), function (el) {
    if (el === apps) return;
    var body = sb.querySelector('.anav-group[data-g="system"] .anav-body'); if (body) body.appendChild(el);
  });
  if (apps && apps.parentNode === sb) sb.appendChild(apps);
  Array.prototype.forEach.call(sb.querySelectorAll('.nav-item'), function (el) {
    if (!el.hasAttribute('role')) { el.setAttribute('role', 'link'); el.setAttribute('tabindex', '0'); }
  });
  refresh();
}

function refresh() {
  var sb = sidebar(); if (!sb) return;
  var active = sb.querySelector('.nav-item.active'), activeGroup = active ? groupOf(active.getAttribute('data-page')) : '';
  Array.prototype.forEach.call(sb.querySelectorAll('.nav-item'), function (el) {
    if (el.classList.contains('active')) el.setAttribute('aria-current', 'page'); else el.removeAttribute('aria-current');
  });
  var caps = window.ETEL_CAPS;
  Array.prototype.forEach.call(sb.querySelectorAll('.anav-group'), function (wrap) {
    var id = wrap.getAttribute('data-g');
    var items = wrap.querySelectorAll('.nav-item'), shown = false, usable = false;
    Array.prototype.forEach.call(items, function (el) {
      var page = el.getAttribute('data-page'), need = EXTRA_CAPS[page];
      if (need && Array.isArray(caps) && caps.indexOf('*') < 0 && caps.indexOf(need) < 0) el.classList.add('etel-cap-hidden');
      if (!el.classList.contains('etel-cap-hidden')) { usable = true; if (!el.classList.contains('anav-filtered')) shown = true; }
    });
    wrap.classList.toggle('anav-empty', sb.classList.contains('anav-searching') ? !shown : !usable);
    var open = isOpen(id) || id === activeGroup || sb.classList.contains('anav-searching');
    wrap.classList.toggle('closed', !open);
    var head = wrap.querySelector('.anav-head'); if (head) head.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
}
function search(q) {
  var sb = sidebar(); if (!sb) return;
  q = String(q || '').trim().toLowerCase();
  sb.classList.toggle('anav-searching', !!q);
  var shown = 0;
  Array.prototype.forEach.call(sb.querySelectorAll('.nav-item'), function (el) {
    var hit = !q || el.textContent.toLowerCase().indexOf(q) >= 0;
    el.classList.toggle('anav-filtered', !hit);
    el.style.display = hit ? '' : 'none';
    if (hit && q && !el.classList.contains('etel-cap-hidden')) shown++;
  });
  var nm = sb.querySelector('.anav-nomatch');
  if (q && !shown) { if (!nm) { nm = document.createElement('div'); nm.className = 'anav-nomatch'; nm.setAttribute('role', 'status'); nm.textContent = 'No page matches.'; sb.insertBefore(nm, sb.querySelector('.anav-find').nextSibling); } }
  else if (nm) nm.parentNode.removeChild(nm);
  refresh();
}

function wire() {
  var sb = sidebar(); if (!sb || sb._anavWired) return; sb._anavWired = true;
  sb.addEventListener('click', function (e) {
    var h = e.target.closest ? e.target.closest('.anav-head') : null;
    if (h) { var g = h.parentNode.getAttribute('data-g'); state[g] = !isOpen(g); save(); refresh(); }
  });
  sb.addEventListener('keydown', function (e) {
    var el = e.target;
    if ((e.key === 'Enter' || e.key === ' ') && el.classList && el.classList.contains('nav-item')) { e.preventDefault(); el.click(); }
    if (e.key === 'Escape' && el.id === 'anav-q') { el.value = ''; search(''); }
  });
  sb.addEventListener('input', function (e) { if (e.target.id === 'anav-q') search(e.target.value); });
  var orig = window.switchPage;
  if (typeof orig === 'function' && !orig._anav) {
    window.switchPage = function () { var r = orig.apply(this, arguments); setTimeout(function () { var q = document.getElementById('anav-q'); if (q && q.value) { q.value = ''; search(''); } else refresh(); }, 0); return r; };
    window.switchPage._anav = true;
  }
}

/* Open a page already filtered. Pages listen for the 'admin:filter' event; it is sent first so they load with the filter. */
function go(page, filter, period) {
  var el = itemFor(page);
  try { document.dispatchEvent(new CustomEvent('admin:filter', { detail: { page: page, filter: filter || '', period: period || '' } })); } catch (e) { /* old browser: opens unfiltered */ }
  if (typeof window.switchPage === 'function') window.switchPage(page, el);
}
window.AdminNav = { go: go, refresh: refresh };

function install() {
  build(); wire();
  /* items added by other admin scripts (Customers, Package prices) and the capability check land after first paint */
  setTimeout(build, 400); setTimeout(build, 1500);
  if (window.ETEL_CAPS_PROMISE && window.ETEL_CAPS_PROMISE.then) window.ETEL_CAPS_PROMISE.then(function () { setTimeout(function () { build(); refresh(); }, 50); });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
