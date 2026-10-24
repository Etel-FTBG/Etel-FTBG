/* ETEL Admin - Search everything.
   One box that finds a page of the admin AND the things inside it: an order by its code or the customer's phone, a courier by name,
   code or plate, a customer, a business, an item, a promo code, a ride. Open it with Ctrl+K (or Cmd+K), with "/" when you are not typing,
   or with the Search button at the top. Type, move with the arrow keys, press Enter. A page opens straight away; a thing opens its page
   with the search filled in, so the board shows just that match. Without typing it shows the pages you used last.
   It is a dialog (named, keeps Tab inside, Escape closes and puts you back where you were) and a combobox (the list is announced and the
   active result is read out). Results only include what the signed-in person may open; the server decides that (admin_global_search).
   Standalone: nothing else is edited. Every server value goes through AdminKit.esc. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;
var RECENT = 'admin-search-recent';
var S = { open: false, q: '', rows: [], act: -1, seq: 0, prev: null, pages: [], remote: [], busy: false };
var ICON = { orders: '🧾', couriers: '🛵', customers: '👤', servicegivers: '🏪', items: '🍽️', promos: '🎟️', rides: '🚖', page: '📄' };

function t(s) { return (window.AdminI18n && window.AdminI18n.t) ? window.AdminI18n.t(s) : s; }

function css() {
  if (document.getElementById('sx-css')) return;
  var s = document.createElement('style'); s.id = 'sx-css';
  s.textContent = [
    '.sx-btn{display:inline-flex;align-items:center;gap:8px;margin:0 8px;padding:8px 12px;border-radius:12px;border:1.5px solid rgba(128,128,128,.35);background:transparent;color:inherit;font:inherit;font-size:13px;font-weight:600;cursor:pointer}',
    '.sx-btn:hover{border-color:#D4AF37}.sx-btn kbd{font:inherit;font-size:11px;opacity:.7;border:1px solid rgba(128,128,128,.45);border-radius:6px;padding:1px 6px}',
    '.sx-btn:focus-visible{outline:3px solid #D4AF37;outline-offset:2px}',
    '@media(max-width:700px){.sx-btn kbd,.sx-btn .sx-lbl{display:none}.sx-btn{padding:9px 11px}}',
    '.sx-ov{position:fixed;inset:0;z-index:10050;background:rgba(10,8,25,.6);display:flex;justify-content:center;align-items:flex-start;padding:min(12vh,90px) 14px 14px}',
    '.sx-box{width:min(640px,100%);max-height:min(72vh,640px);display:flex;flex-direction:column;border-radius:20px;overflow:hidden;box-shadow:0 24px 70px rgba(0,0,0,.55)}',
    '.sx-in{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid var(--line)}',
    '.sx-in input{flex:1;min-width:0;border:0;outline:0;background:transparent;color:var(--ink);font:inherit;font-size:16px}',
    '.sx-in input::placeholder{color:var(--mute)}',
    '.sx-x{border:1.5px solid var(--line);background:var(--soft);color:var(--ink);border-radius:9px;padding:4px 9px;font:inherit;font-size:12px;cursor:pointer}',
    '.sx-list{overflow-y:auto;padding:6px 8px 10px;margin:0;list-style:none}',
    '.sx-h{margin:10px 8px 4px;font-size:11px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:var(--mute)}',
    '.sx-r{display:flex;align-items:center;gap:12px;padding:9px 10px;border-radius:12px;cursor:pointer;color:var(--ink)}',
    '.sx-r[aria-selected=true],.sx-r:hover{background:rgba(212,175,55,.16)}',
    '.sx-r i{font-style:normal;font-size:18px;width:28px;text-align:center;flex:none}',
    '.sx-r .sx-t{min-width:0;flex:1}.sx-r b{display:block;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.sx-r small{display:block;font-size:12px;color:var(--mute);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.sx-m{font-size:12px;color:var(--mute);white-space:nowrap}',
    '.sx-empty{padding:26px 16px;text-align:center;color:var(--mute);font-size:13.5px}',
    '.sx-foot{padding:8px 16px;border-top:1px solid var(--line);font-size:11.5px;color:var(--mute);display:flex;gap:14px;flex-wrap:wrap}',
    '.sx-foot kbd{font:inherit;border:1px solid var(--line);border-radius:5px;padding:0 5px}',
    '.sx-vh{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}',
    '@media(prefers-reduced-motion:reduce){.sx-ov{transition:none}}'
  ].join('\n');
  document.head.appendChild(s);
}

var memRecent = [];   // kept for this visit even when the browser will not store anything (private window)
function loadRecent() { try { var r = JSON.parse(localStorage.getItem(RECENT) || 'null'); if (r) return r; } catch (e) { /* use memory */ } return memRecent; }
function saveRecent(r) {
  var list = loadRecent().filter(function (x) { return !(x.kind === r.kind && x.page === r.page && x.q === r.q && x.title === r.title); });
  list.unshift(r); list = list.slice(0, 6); memRecent = list;
  try { localStorage.setItem(RECENT, JSON.stringify(list)); } catch (e) { /* private window: remembered for this visit only */ }
}

/* pages the signed-in person can open, read from the side menu itself (so hidden pages never show) */
function collectPages() {
  var out = [], seen = {};
  Array.prototype.forEach.call(document.querySelectorAll('.sidebar .nav-item[data-page]'), function (el) {
    var p = el.getAttribute('data-page');
    if (!p || seen[p] || el.classList.contains('etel-cap-hidden') || el.offsetParent === null && el.style.display === 'none') return;
    var label = (el.textContent || '').replace(/\s+/g, ' ').trim().replace(/^[^\p{L}\p{N}]+/u, '');
    if (!label) return;
    seen[p] = 1; out.push({ kind: 'page', page: p, title: label, sub: t('Open this page'), icon: ICON[p] || ICON.page });
  });
  return out;
}

function build() {
  var q = S.q.toLowerCase(), rows = [], head = null;
  function add(label, list) { if (!list.length) return; rows.push({ header: label }); list.forEach(function (r) { rows.push(r); }); }
  if (!q) {
    var rec = loadRecent();
    add(t('Recent'), rec.map(function (r) { return { kind: r.kind, page: r.page, title: r.title, sub: r.sub || (r.kind === 'page' ? t('Open this page') : ''), icon: r.icon, q: r.q, period: r.period, badge: r.badge }; }));
    add(t('Go to'), S.pages.slice(0, 8));
  } else {
    var pg = S.pages.filter(function (p) { return p.title.toLowerCase().indexOf(q) >= 0; }).slice(0, 5);
    add(t('Pages'), pg);
    S.remote.forEach(function (g) {
      add(t(g.label), g.items.map(function (it) {
        return { kind: 'thing', page: g.page, title: it.title, sub: it.sub, icon: ICON[g.key] || ICON.page, q: it.q, period: it.period, badge: it.badge, meta: it.meta };
      }));
    });
  }
  S.rows = rows.filter(function (r) { return !r.header; });
  return rows;
}

function paint(msg) {
  var ov = document.getElementById('sx-ov'); if (!ov) return;
  var rows = build(), list = ov.querySelector('.sx-list'), n = 0, h = '';
  rows.forEach(function (r) {
    if (r.header) { h += '<li class="sx-h" role="presentation">' + esc(r.header) + '</li>'; return; }
    h += '<li class="sx-r" role="option" id="sx-o' + n + '" data-i="' + n + '" aria-selected="' + (n === S.act ? 'true' : 'false') + '"><i aria-hidden="true">' + esc(r.icon) + '</i><div class="sx-t"><b>' + esc(r.title) + '</b>' +
      (r.sub ? '<small>' + esc(r.sub) + '</small>' : '') + '</div>' + (r.badge ? K.pill(r.badge, 'grey') : '') + (r.meta ? '<span class="sx-m">' + esc(r.meta) + '</span>' : '') + '</li>';
    n++;
  });
  if (!n) h = '<li class="sx-empty" role="presentation">' + esc(msg || (S.q ? (S.busy ? t('Searching…') : t('Nothing found. Try a phone number, a code or part of a name.')) : t('Type to search pages, orders, people, shops and codes.'))) + '</li>';
  list.innerHTML = h;
  var inp = ov.querySelector('input');
  inp.setAttribute('aria-expanded', n ? 'true' : 'false');
  if (S.act >= 0 && S.act < n) { inp.setAttribute('aria-activedescendant', 'sx-o' + S.act); var el = document.getElementById('sx-o' + S.act); if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' }); } else inp.removeAttribute('aria-activedescendant');
  var live = ov.querySelector('.sx-live');
  if (live) live.textContent = S.q ? (n ? n + ' ' + t('results') : (S.busy ? '' : t('No results'))) : '';
}

var fetchRemote = K.debounce(function () {
  var q = S.q, seq = ++S.seq;
  if (q.length < 2) { S.remote = []; S.busy = false; paint(); return; }
  S.busy = true; paint();
  K.api('admin_global_search', { q: q, per: 5 }).then(function (res) {
    if (seq !== S.seq || !S.open) return;
    S.busy = false; S.remote = K.okRes(res) ? (K.data(res).groups || []) : [];
    build(); S.act = S.rows.length ? Math.max(0, Math.min(S.act, S.rows.length - 1)) : -1;
    paint(K.okRes(res) ? '' : t('Search is not available right now.'));
  });
}, 250);

function openBox() {
  if (S.open) return;
  K.css(); css();
  S.open = true; S.q = ''; S.remote = []; S.act = -1; S.pages = collectPages(); S.prev = document.activeElement;
  var ov = document.createElement('div'); ov.id = 'sx-ov'; ov.className = 'ak sx-ov';
  ov.innerHTML = '<div class="sx-box" role="dialog" aria-modal="true" aria-label="' + esc(t('Search')) + '" style="background:var(--card);color:var(--ink)">' +
    '<div class="sx-in"><span aria-hidden="true">🔎</span><input type="text" role="combobox" aria-expanded="false" aria-controls="sx-list" aria-autocomplete="list" aria-label="' + esc(t('Search pages, orders, people, shops and codes')) + '" placeholder="' + esc(t('Search pages, orders, people, shops…')) + '" autocomplete="off" autocapitalize="off" spellcheck="false">' +
    '<button type="button" class="sx-x" data-sx-close aria-label="' + esc(t('Close search')) + '">Esc</button></div>' +
    '<ul class="sx-list" id="sx-list" role="listbox" aria-label="' + esc(t('Results')) + '"></ul>' +
    '<div class="sx-foot" aria-hidden="true"><span><kbd>↑</kbd> <kbd>↓</kbd> ' + esc(t('move')) + '</span><span><kbd>Enter</kbd> ' + esc(t('open')) + '</span><span><kbd>Esc</kbd> ' + esc(t('close')) + '</span></div>' +
    '<div class="sx-vh sx-live" role="status" aria-live="polite"></div></div>';
  document.body.appendChild(ov);
  var inp = ov.querySelector('input');
  inp.addEventListener('input', function () { S.q = inp.value.trim(); S.act = -1; S.remote = S.q.length < 2 ? [] : S.remote; paint(); fetchRemote(); if (S.rows.length && S.q) { S.act = 0; paint(); } });
  ov.addEventListener('mousedown', function (e) { if (e.target === ov) closeBox(); });
  ov.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('[data-sx-close]')) { closeBox(); return; }
    var li = e.target.closest && e.target.closest('.sx-r'); if (li) pick(parseInt(li.getAttribute('data-i'), 10));
  });
  ov.addEventListener('mousemove', function (e) { var li = e.target.closest && e.target.closest('.sx-r'); if (li) { var i = parseInt(li.getAttribute('data-i'), 10); if (i !== S.act) { S.act = i; var all = ov.querySelectorAll('.sx-r'); Array.prototype.forEach.call(all, function (x) { x.setAttribute('aria-selected', x.getAttribute('data-i') === String(i) ? 'true' : 'false'); }); inp.setAttribute('aria-activedescendant', 'sx-o' + i); } } });
  ov.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { e.preventDefault(); closeBox(); return; }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault(); var n = S.rows.length; if (!n) return;
      S.act = e.key === 'ArrowDown' ? (S.act + 1) % n : (S.act <= 0 ? n - 1 : S.act - 1); paint(); return;
    }
    if (e.key === 'Enter') { e.preventDefault(); if (S.act < 0 && S.rows.length) S.act = 0; if (S.act >= 0) pick(S.act); return; }
    if (e.key === 'Tab') {   // keep focus inside: the field and the close button
      var f = [inp, ov.querySelector('[data-sx-close]')], at = document.activeElement, i = f.indexOf(at);
      e.preventDefault(); f[(i + (e.shiftKey ? -1 : 1) + f.length) % f.length].focus();
    }
  });
  paint(); inp.focus();
}

function closeBox() {
  var ov = document.getElementById('sx-ov'); if (ov && ov.parentNode) ov.parentNode.removeChild(ov);
  S.open = false; S.seq++;
  if (S.prev && S.prev.focus && document.contains(S.prev)) { try { S.prev.focus(); } catch (e) { /* gone */ } }
}

/* fill the search box of a page once it has drawn it (some pages draw after their data arrives) */
function fillPageSearch(page, q) {
  var tries = 0;
  (function attempt() {
    var panel = document.getElementById('panel-' + page), inp = panel && panel.querySelector('input[type="search"],input[id$="-q"]');
    if (inp && inp.offsetParent !== null) {
      var setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set; setter.call(inp, q);
      inp.dispatchEvent(new Event('input', { bubbles: true })); try { inp.focus({ preventScroll: true }); } catch (e) { /* ok */ } return;
    }
    if (++tries < 20) setTimeout(attempt, 150);
  })();
}

function pick(i) {
  var r = S.rows[i]; if (!r) return;
  saveRecent({ kind: r.kind, page: r.page, title: r.title, sub: r.sub, icon: r.icon, q: r.q, period: r.period, badge: r.badge });
  closeBox();
  setTimeout(function () {
    if (window.AdminNav && window.AdminNav.go) window.AdminNav.go(r.page, '', r.period || '');
    else { var el = document.querySelector('.sidebar .nav-item[data-page="' + r.page + '"]'); if (el && window.switchPage) window.switchPage(r.page, el); }
    if (r.kind === 'thing' && r.q) fillPageSearch(r.page, r.q);
  }, 30);
}

function addButton() {
  if (document.getElementById('sx-open')) return;
  var title = document.getElementById('page-title'); if (!title || !title.parentNode) return;
  css();
  var b = document.createElement('button'); b.type = 'button'; b.id = 'sx-open'; b.className = 'sx-btn';
  b.setAttribute('aria-haspopup', 'dialog'); b.setAttribute('aria-keyshortcuts', 'Control+K Meta+K');
  b.innerHTML = '<span aria-hidden="true">🔎</span><span class="sx-lbl">' + esc(t('Search')) + '</span><kbd aria-hidden="true">Ctrl K</kbd>';
  b.addEventListener('click', openBox);
  title.parentNode.insertBefore(b, title.nextSibling);
}

function typing(el) { return el && (/^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName) || el.isContentEditable); }
document.addEventListener('keydown', function (e) {
  if ((e.ctrlKey || e.metaKey) && !e.altKey && String(e.key).toLowerCase() === 'k') { e.preventDefault(); if (S.open) closeBox(); else openBox(); return; }
  if (e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey && !S.open && !typing(document.activeElement) && !document.querySelector('.ak-dr,.ak-modal')) { e.preventDefault(); openBox(); }
});

function install() { addButton(); setTimeout(addButton, 500); setTimeout(addButton, 2000); }
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
window.AdminSearch = { open: openBox, close: closeBox };
})();
