/* ETEL Admin - Workspaces: Delivery, Ride and Company, each with its own pages.
   The menu used to be one long list that mixed both systems. Now:
   - a switch at the top of the menu: Delivery, Ride, Company (pages the person may not open hide their workspace);
   - the menu shows only the pages of the chosen workspace (the find box still searches all of them);
   - every workspace has a Home: a screen of big cards grouped by job ("Run the day", "Money" ...), with live numbers;
   - "All pages" opens a finder over every page of every workspace (type a few letters, press Enter);
   - on a phone the long strip of tabs is replaced by the switch, Home and All pages;
   - opening a page from anywhere (dashboard links, search) moves to the workspace that owns it.
   Standalone and additive: it moves nothing, it hides and shows existing menu items with its own class, so click handlers and the
   permission hiding in admin-roles.js keep working. If this file fails to load, the old menu is untouched.
   Data: admin_live_ops (numbers on Home, only for staff who may see it). Every value goes through esc; no inline handlers. */
(function () {
'use strict';
var K = window.AdminKit;

var WS = [
  { id: 'delivery', label: 'Delivery', icon: '🚚', accent: '#D4AF37', blurb: 'Orders, couriers, shops, menus and delivery prices.',
    sections: [['Run the day', ['dispatch', 'orders', 'packages', 'orderchats']], ['Couriers and shops', ['couriers', 'servicegivers', 'ratings']],
               ['Menu and prices', ['items', 'menucat', 'pricing', 'dfees', 'promos']], ['Money and reports', ['money', 'telebirr', 'reports']]] },
  { id: 'ride', label: 'Ride', icon: '🚕', accent: '#3B82F6', blurb: 'Ride requests, drivers, safety and ride payments.',
    sections: [['Run the day', ['liveops', 'rides', 'rideoffers']], ['Drivers and safety', ['ridedrivers', 'trust', 'agents']], ['Money and prices', ['pricing', 'ridepay']]] },
  { id: 'company', label: 'Company', icon: '🏢', accent: '#8B5CF6', blurb: 'People, money, support and settings that both systems share.',
    sections: [['Overview', ['dashboard']], ['People', ['users', 'customers']], ['Money', ['finance', 'equb', 'integrations']],
               ['Support and records', ['supportdesk', 'support', 'documents', 'launch']],
               ['System', ['settings', 'serviceareas', 'branding', 'roles', 'releases', 'etelapp', 'updater', 'danger', '#2fa', '#shield', '#display']]] }
];
var DESC = {
  dispatch: 'Orders that need a courier right now. Assign by hand.', orders: 'Every delivery order: search, status, details.', packages: 'Package prices by vehicle, distance and weight.',
  orderchats: 'Chats between customers, shops and couriers.', couriers: 'Courier list, approval, vehicle, documents.', servicegivers: 'Shops and service givers: verification and menus.',
  ratings: 'What customers said about shops and couriers.', items: 'Products and prices in every shop.', menucat: 'The shared menu catalog shops pick from.',
  pricing: 'Delivery fees and zones, and ride fares.', dfees: 'Price each delivery by distance for every vehicle, and share it between the company and the courier.', promos: 'Promo codes for orders and rides.', money: 'Refunds and the cash couriers hold.',
  telebirr: 'Payments to check and confirm.', reports: 'Sales and delivery reports.',
  liveops: 'Ride requests waiting, drivers online, SOS: right now.', ridedrivers: 'Drivers on a live map, approvals, suspend, add a driver.', rideoffers: 'What drivers see in their Pull tab: far waiting trips, VIP and sweet areas.', rides: 'All rides, fares, receipts, cancel or finish a ride.', trust: 'Driver documents, expired papers, fake GPS checks.',
  agents: 'Agents who book rides and bring in drivers.', ridepay: 'Ride payments, wallet and tips.',
  dashboard: 'The numbers of the whole company.', users: 'Everyone with an account, by role.', customers: 'Customers: orders, rides, accounts.',
  finance: 'Money, plans and investors.', equb: 'Equb groups.', integrations: 'Payment companies and banks (keys).', supportdesk: 'Support desk: cases and answers.',
  support: 'Messages from users.', documents: 'Company documents.', launch: 'Is the system ready to open? Checklist.', settings: 'Admin settings.', serviceareas: 'The cities Etel serves.',
  branding: 'Logos, colours, names.', roles: 'Who may do what (staff roles).', releases: 'App releases.', etelapp: 'What the ETEL phone app shows (owner).', updater: 'Upload files.',
  danger: 'Careful: reset and delete tools.', '#2fa': 'Admin two-step sign-in (the code at the EtelID login page is separate: change it in the EtelID portal).', '#shield': 'Block attacks and people outside Ethiopia, sign out when not used, security log.', '#display': 'Make text and buttons bigger or smaller.'
};
var LBL = { ride: { pricing: 'Ride prices' }, delivery: { pricing: 'Delivery prices' } };   // the Pricing page shows the tabs of the workspace it is opened from
var WDESC = { ride: { pricing: 'Ride fares per vehicle, peak hours, driver goals, commission, incentives.' }, delivery: { pricing: 'Delivery fees and zones, vehicle fees, courier pay, bank accounts.' } };
function wlabel(w, k) { return (LBL[w.id] && LBL[w.id][k]) || label(k); }
function wdesc(w, k) { return (WDESC[w.id] && WDESC[w.id][k]) || DESC[k] || ''; }
var OWNER = {};   // page -> the workspaces that list it (a page such as Pricing can be in two)
WS.forEach(function (w) { w.sections.forEach(function (s) { s[1].forEach(function (p) { (OWNER[p] = OWNER[p] || []).push(w.id); }); }); });
var STORE = 'aws-ws';
var cur = 'delivery', snap = null, snapAt = 0, landed = false, capsDone = !(window.ETEL_CAPS_PROMISE && window.ETEL_CAPS_PROMISE.then);
try { var sv = localStorage.getItem(STORE); if (sv === 'delivery' || sv === 'ride' || sv === 'company') cur = sv; } catch (e) { /* private window */ }

function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
function wsById(id) { for (var i = 0; i < WS.length; i++) if (WS[i].id === id) return WS[i]; return WS[0]; }
function sidebar() { return document.querySelector('.sidebar'); }
function itemFor(key) {
  var sb = sidebar(); if (!sb) return null;
  if (key === '#2fa') return sb.querySelector('[data-etel-2fa-open]');
  if (key === '#shield') return sb.querySelector('[data-etel-shield-open]');
  if (key === '#display') return sb.querySelector('[data-etel-display-open]');
  return sb.querySelector('.nav-item[data-page="' + key + '"]');
}
function owners(key) { return OWNER[key] || ['company']; }
function usable(key) {
  var el = itemFor(key); if (!el || el.classList.contains('etel-cap-hidden')) return false;
  if (window.ETEL_RIDE_ONLY && owners(key).every(function (x) { return x === 'delivery'; })) return false;   // ride-only staff never see Delivery pages
  return true;
}
function label(key) {
  var el = itemFor(key); if (!el) return key;
  var nm = el.querySelector('.nav-icon + span'); if (nm) return nm.textContent.trim();
  var sp = el.querySelectorAll('span'); return (sp.length > 1 ? sp[1].textContent : el.textContent).replace(/\s+\d+\s*$/, '').trim();
}
function icon(key) { var el = itemFor(key), ic = el && el.querySelector('.nav-icon'); return ic ? ic.textContent.trim() : '•'; }
function pagesOf(w) { var out = []; w.sections.forEach(function (s) { s[1].forEach(function (p) { if (usable(p)) out.push(p); }); }); return out; }
function wsUsable(w) { return pagesOf(w).length > 0; }
function openPage(key) {
  var el = itemFor(key); if (!el) return;
  if (key.charAt(0) === '#') { el.click(); return; }
  if (typeof window.switchPage === 'function') window.switchPage(key, el);
}

/* Some menu items start their own loader in their inline onclick (Branding cards, Menu lists, Promos ...). Pages opened any other way
   (the home cards, the find-a-page results, the dashboard's "Needs attention" links) used to show empty. So switchPage starts that loader itself;
   each loader is wrapped so that the menu click that follows (which also calls it) does not load twice. */
var LOAD = { branding: 'loadBrandingPanel', ridepay: 'ridePaymentsLoad', menucat: 'loadMenuCatalog', promos: 'loadPromos', orderchats: 'loadOrderChats' };
function startLoader(page) {
  var fn = LOAD[page], f = window[fn];
  if (typeof f !== 'function') return;
  if (!f._once) {
    var inner = f, last = 0;
    var w = function () { var n = Date.now(); if (n - last < 600) return; last = n; return inner.apply(this, arguments); };
    w._once = 1; window[fn] = w; f = w;
  }
  f();
}

function css() {
  if (document.getElementById('aws-css')) return;
  var s = document.createElement('style'); s.id = 'aws-css';
  s.textContent = [
    '.sidebar .nav-item.aws-off{display:none}',
    '.sidebar.aws-all .nav-item.aws-off{display:flex}',
    '.sidebar .anav-group.aws-empty{display:none}.sidebar.aws-all .anav-group.aws-empty{display:block}',
    '.aws-switch{display:grid;gap:7px;margin:6px 6px 12px}',
    '.aws-chip{display:flex;align-items:center;gap:11px;width:100%;text-align:left;padding:11px 12px;border-radius:14px;border:1.5px solid rgba(128,128,128,.3);background:rgba(128,128,128,.08);color:inherit;font:inherit;cursor:pointer;transition:transform .12s,border-color .15s,background .15s}',
    '.aws-chip:hover{border-color:var(--c);transform:translateY(-1px)}',
    '.aws-chip .ic{flex:none;width:38px;height:38px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:21px;background:rgba(128,128,128,.14)}',
    '.aws-chip .tx{display:flex;flex-direction:column;line-height:1.15;min-width:0}.aws-chip .tx b{font-size:14.5px;font-weight:800}.aws-chip .tx small{font-size:11px;opacity:.7;margin-top:2px}',
    '.aws-chip[aria-pressed="true"]{border-color:var(--c);background:rgba(212,175,55,.2);background:linear-gradient(135deg,color-mix(in srgb,var(--c) 26%,transparent),color-mix(in srgb,var(--c) 6%,transparent));box-shadow:0 0 0 3px color-mix(in srgb,var(--c) 22%,transparent)}',
    '.aws-chip[aria-pressed="true"] .ic{background:var(--c);color:#1a1200}',
    '.aws-chip[hidden]{display:none}',
    '.aws-tools{display:flex;gap:7px}.aws-tool{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:9px 8px;border-radius:12px;border:1.5px dashed rgba(128,128,128,.4);background:transparent;color:inherit;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer}',
    '.aws-tool:hover{border-color:var(--aws,#D4AF37)}',
    '.aws-chip:focus-visible,.aws-tool:focus-visible,.aws-tile:focus-visible,.aws-row:focus-visible{outline:3px solid #F2CC6B;outline-offset:2px}',
    '.aws-eyebrow{font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--aws,#D4AF37);margin-bottom:2px}',
    '.aws-hello{margin:0 0 4px;font-size:13px;opacity:.75}',
    '.aws-sec{margin:22px 0 10px;font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;opacity:.7}',
    '.aws-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px}',
    '.aws-tile{position:relative;display:flex;flex-direction:column;align-items:flex-start;gap:4px;text-align:left;padding:15px;border-radius:18px;border:1.5px solid rgba(128,128,128,.28);background:rgba(128,128,128,.07);color:inherit;font:inherit;cursor:pointer;min-height:112px;transition:transform .15s,border-color .15s,box-shadow .15s}',
    '.aws-tile:hover{transform:translateY(-3px);border-color:var(--aws,#D4AF37);box-shadow:0 10px 26px rgba(0,0,0,.18)}',
    '.aws-tile .ic{width:44px;height:44px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;background:rgba(212,175,55,.2);background:color-mix(in srgb,var(--aws,#D4AF37) 20%,transparent);margin-bottom:6px}',
    '.aws-tile b{font-size:15px;font-weight:800}.aws-tile small{font-size:12px;line-height:1.35;opacity:.75}',
    '.aws-tile .bd{position:absolute;top:12px;right:12px;min-width:24px;padding:3px 8px;border-radius:20px;font-style:normal;font-size:12px;font-weight:800;text-align:center;background:var(--aws,#D4AF37);color:#1a1200}',
    '.aws-tile .bd.hot{background:#d6364a;color:#fff}',
    '.aws-empty{padding:30px 14px;text-align:center;opacity:.7;border:1.5px dashed rgba(128,128,128,.4);border-radius:16px}',
    '.aws-ov{position:fixed;inset:0;z-index:100001;background:rgba(8,6,20,.6);backdrop-filter:blur(3px);display:flex;align-items:flex-start;justify-content:center;padding:6vh 12px}',
    '.aws-sheet{width:min(640px,100%);max-height:86vh;display:flex;flex-direction:column;border-radius:20px;background:#17152b;color:#f3efe2;border:1.5px solid rgba(212,175,55,.5);box-shadow:0 24px 70px rgba(0,0,0,.5);overflow:hidden}',
    'body.light-mode .aws-sheet,body.etel-light .aws-sheet{background:#fffdf6;color:#1a1200}',
    '.aws-sheet header{display:flex;gap:8px;align-items:center;padding:12px;border-bottom:1px solid rgba(128,128,128,.3)}',
    '.aws-sheet input{flex:1;padding:12px 14px;border-radius:12px;border:1.5px solid rgba(128,128,128,.4);background:rgba(128,128,128,.1);color:inherit;font:inherit;font-size:15px}',
    '.aws-sheet input:focus{outline:2px solid #D4AF37}',
    '.aws-x{border:0;width:38px;height:38px;border-radius:50%;background:rgba(128,128,128,.2);color:inherit;font-size:16px;cursor:pointer}',
    '.aws-list{overflow-y:auto;padding:8px 10px 14px}',
    '.aws-grp{margin:12px 4px 6px;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;opacity:.65}',
    '.aws-row{display:flex;align-items:center;gap:12px;width:100%;text-align:left;padding:10px;border-radius:12px;border:0;background:transparent;color:inherit;font:inherit;cursor:pointer}',
    '.aws-row:hover,.aws-row.on{background:rgba(212,175,55,.16)}',
    '.aws-row .ic{flex:none;width:36px;height:36px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:20px;background:rgba(128,128,128,.16)}',
    '.aws-row .t{flex:1;min-width:0}.aws-row .t b{display:block;font-size:14px}.aws-row .t small{display:block;font-size:11.5px;opacity:.7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.aws-row .w{flex:none;font-size:10.5px;font-weight:800;padding:3px 8px;border-radius:20px;background:rgba(128,128,128,.2)}',
    '@media(max-width:768px){',
    'body.aws-on .sidebar .nav-item:not(#header-mobile-menu-btn),body.aws-on .sidebar .anav-find,body.aws-on .sidebar .anav-head{display:none!important}',
    '.aws-switch{display:flex;align-items:center;gap:8px;margin:0 8px;flex:none}',
    '.aws-switch{gap:6px}.aws-chip{width:auto;padding:5px 10px 5px 5px;gap:6px;border-radius:999px;flex:none}.aws-chip .ic{width:28px;height:28px;font-size:16px;border-radius:50%}',
    '.aws-chip .tx b{font-size:12px;letter-spacing:0;text-transform:none}.aws-chip .tx small{display:none}',
    '.aws-tools{display:contents}.aws-tool{flex:none;width:auto;padding:8px 12px;border-radius:999px;border-style:solid;white-space:nowrap}.aws-tool[data-aws-home]{display:none}.aws-tool[data-aws-pages] span{display:none}.aws-tool[data-aws-pages]{font-size:16px;padding:6px 12px}',
    '.aws-grid{grid-template-columns:repeat(2,1fr);gap:10px}.aws-tile{min-height:104px;padding:12px}.aws-tile small{font-size:11px}',
    '.aws-ov{padding:0;align-items:stretch}.aws-sheet{max-height:100vh;height:100%;border-radius:0}',
    '}',
    '@media(prefers-reduced-motion:reduce){.aws-chip,.aws-tile{transition:none}}'
  ].join('\n');
  document.head.appendChild(s);
}

function chipHtml(w) {
  var n = pagesOf(w).length;
  return '<button type="button" class="aws-chip" data-ws="' + w.id + '" style="--c:' + w.accent + '" aria-pressed="false"><span class="ic" aria-hidden="true">' + w.icon + '</span><span class="tx"><b>' + esc(w.label) + '</b><small>' + n + ' page' + (n === 1 ? '' : 's') + '</small></span></button>';
}
function build() {
  var sb = sidebar(); if (!sb) return false;
  css();
  var sw = document.getElementById('aws-switch');
  if (!sw) {
    sw = document.createElement('div'); sw.id = 'aws-switch'; sw.className = 'aws-switch'; sw.setAttribute('role', 'group'); sw.setAttribute('aria-label', 'Workspace');
    sw.innerHTML = WS.map(chipHtml).join('') + '<div class="aws-tools"><button type="button" class="aws-tool" data-aws-home>🏠 <span>Home</span></button><button type="button" class="aws-tool" data-aws-pages aria-label="All pages">☰ <span>All pages</span></button></div>';
    sb.insertBefore(sw, sb.firstChild);
  } else if (sb.firstChild !== sw) sb.insertBefore(sw, sb.firstChild);
  panel();   // must exist before the first switchPage('wshome'), which looks it up
  var pt = document.getElementById('page-title');
  if (pt && !document.getElementById('aws-eyebrow')) { var ey = document.createElement('div'); ey.id = 'aws-eyebrow'; ey.className = 'aws-eyebrow'; pt.parentNode.insertBefore(ey, pt); }
  return true;
}

function apply() {
  var sb = sidebar(); if (!sb || !document.getElementById('aws-switch')) return;
  var w = wsById(cur);
  if (!wsUsable(w)) { for (var i = 0; i < WS.length; i++) if (wsUsable(WS[i])) { cur = WS[i].id; w = WS[i]; break; } }
  Array.prototype.forEach.call(sb.querySelectorAll('.nav-item'), function (el) {
    if (el.id === 'header-mobile-menu-btn') return;
    var key = el.getAttribute('data-page') || (el.hasAttribute('data-etel-2fa-open') ? '#2fa' : el.hasAttribute('data-etel-shield-open') ? '#shield' : el.hasAttribute('data-etel-display-open') ? '#display' : '');
    el.classList.toggle('aws-off', owners(key).indexOf(cur) < 0);
  });
  Array.prototype.forEach.call(sb.querySelectorAll('.anav-group'), function (g) {
    var any = false;
    Array.prototype.forEach.call(g.querySelectorAll('.nav-item'), function (el) { if (!el.classList.contains('aws-off') && !el.classList.contains('etel-cap-hidden')) any = true; });
    g.classList.toggle('aws-empty', !any);
  });
  Array.prototype.forEach.call(document.querySelectorAll('#aws-switch .aws-chip'), function (c) {
    var x = wsById(c.getAttribute('data-ws')), ok = wsUsable(x), n = pagesOf(x).length;
    c.hidden = !ok; c.setAttribute('aria-pressed', x.id === cur ? 'true' : 'false');
    var sm = c.querySelector('small'); if (sm) sm.textContent = n + ' page' + (n === 1 ? '' : 's');
  });
  document.body.classList.add('aws-on'); document.body.setAttribute('data-ws', cur);
  document.documentElement.style.setProperty('--aws', w.accent);
  eyebrow();
}
function eyebrow() {
  var ey = document.getElementById('aws-eyebrow'); if (!ey) return;
  var page = typeof currentPage !== 'undefined' ? currentPage : '', o = owners(page), w = wsById(page === 'wshome' || o.indexOf(cur) >= 0 ? cur : o[0]);
  ey.textContent = w.icon + ' ' + w.label + (page === 'wshome' ? ' home' : '');
}
function setWs(id) { cur = id; try { localStorage.setItem(STORE, id); } catch (e) { /* not remembered */ } apply(); }

/* ---------- Home ---------- */
function panel() {
  var p = document.getElementById('panel-wshome'); if (p) return p;
  var ref = document.getElementById('panel-orders') || document.querySelector('.panel'); if (!ref) return null;
  p = document.createElement('div'); p.className = 'panel'; p.id = 'panel-wshome'; p.style.display = 'none'; ref.parentNode.appendChild(p); return p;
}
function nums(w) {
  if (!snap || !snap.now) return [];
  var n = snap.now, g = function (o, k) { return o && o[k] != null ? Number(o[k]) : 0; };
  if (w.id === 'delivery') return [['Orders waiting', g(n.orders, 'waiting')], ['On the way', g(n.orders, 'assigned') + g(n.orders, 'on_the_way')], ['Couriers online', g(n.couriers, 'online')]];
  if (w.id === 'ride') return [['Requests waiting', g(n.rides, 'searching')], ['Trips now', g(n.rides, 'on_the_way') + g(n.rides, 'in_progress')], ['Drivers online', g(n.drivers, 'online')], ['SOS open', g(n.sos, 'open')]];
  return [];
}
function badge(w, key) {
  if (!snap || !snap.now) return '';
  var n = snap.now, v = 0, hot = false;
  if (w.id === 'delivery') { if (key === 'dispatch') { v = Number((n.orders || {}).waiting || 0); hot = v > 0; } else if (key === 'couriers') v = Number((n.couriers || {}).online || 0); else if (key === 'orders') v = Number((n.orders || {}).assigned || 0) + Number((n.orders || {}).on_the_way || 0); }
  if (w.id === 'ride') { if (key === 'liveops') { v = Number((n.sos || {}).open || 0); hot = v > 0; } else if (key === 'rides') v = Number((n.rides || {}).searching || 0) + Number((n.rides || {}).in_progress || 0); }
  return v > 0 ? '<i class="bd' + (hot ? ' hot' : '') + '">' + esc(v) + '</i>' : '';
}
function home() {
  var p = panel(); if (!p) return;
  var w = wsById(cur), h = '<div class="ak">';
  var bigs = nums(w).map(function (b) { return [b[0], b[1]]; });
  h += (K && K.hero) ? K.hero(w.icon + ' ' + w.label, w.blurb, bigs) : '<h2>' + esc(w.label) + '</h2><p>' + esc(w.blurb) + '</p>';
  var any = false;
  w.sections.forEach(function (s) {
    var keys = s[1].filter(usable); if (!keys.length) return; any = true;
    h += '<h3 class="aws-sec">' + esc(s[0]) + '</h3><div class="aws-grid">' + keys.map(function (k) {
      return '<button type="button" class="aws-tile" data-go="' + esc(k) + '">' + badge(w, k) + '<span class="ic" aria-hidden="true">' + esc(icon(k)) + '</span><b>' + esc(wlabel(w, k)) + '</b><small>' + esc(wdesc(w, k)) + '</small></button>';
    }).join('') + '</div>';
  });
  if (!any) h += '<div class="aws-empty">Nothing here is open to your role.</div>';
  p.innerHTML = h + '</div>';
  if (K && K.css) K.css();
}
function loadSnap(cb) {
  if (!K || !K.api || Date.now() - snapAt < 15000) { if (cb) cb(); return; }
  snapAt = Date.now();
  K.api('admin_live_ops', {}).then(function (res) { if (K.okRes(res)) snap = K.data(res); if (cb) cb(); }, function () { if (cb) cb(); });
}
function showHome() {
  if (typeof window.switchPage === 'function') window.switchPage('wshome', null);
}

/* ---------- All pages finder ---------- */
var sheet = null;
function rows(q) {
  q = String(q || '').trim().toLowerCase(); var out = [];
  WS.forEach(function (w) {
    w.sections.forEach(function (s) { s[1].forEach(function (k) {
      if (!usable(k)) return; var t = wlabel(w, k), d = wdesc(w, k);
      if (q && (' ' + (t + ' ' + d).toLowerCase()).indexOf(' ' + q) < 0 && w.label.toLowerCase().indexOf(q) !== 0) return;   // a workspace name finds all its pages when typed from the start
      out.push({ k: k, w: w, t: t, d: d });
    }); });
  });
  return out;
}
function paintSheet() {
  var q = sheet.querySelector('input').value, list = sheet.querySelector('.aws-list'), rs = rows(q), h = '', last = '';
  rs.forEach(function (r, i) {
    if (r.w.id !== last) { last = r.w.id; h += '<div class="aws-grp">' + r.w.icon + ' ' + esc(r.w.label) + '</div>'; }
    h += '<button type="button" class="aws-row' + (i === 0 ? ' on' : '') + '" data-go="' + esc(r.k) + '"><span class="ic" aria-hidden="true">' + esc(icon(r.k)) + '</span><span class="t"><b>' + esc(r.t) + '</b><small>' + esc(r.d) + '</small></span><span class="w">' + esc(r.w.label) + '</span></button>';
  });
  list.innerHTML = h || '<div class="aws-empty">No page matches.</div>';
}
function openSheet() {
  if (sheet) return;
  sheet = document.createElement('div'); sheet.className = 'aws-ov'; sheet.setAttribute('role', 'dialog'); sheet.setAttribute('aria-modal', 'true'); sheet.setAttribute('aria-label', 'All pages');
  sheet.innerHTML = '<div class="aws-sheet"><header><input type="search" placeholder="Find a page… (Delivery, Ride, Company)" aria-label="Find a page" autocomplete="off"><button type="button" class="aws-x" data-x aria-label="Close">✕</button></header><div class="aws-list"></div></div>';
  document.body.appendChild(sheet); paintSheet();
  var inp = sheet.querySelector('input'); setTimeout(function () { inp.focus(); }, 30);
  sheet.addEventListener('input', paintSheet);
  sheet.addEventListener('click', function (e) {
    if (e.target === sheet || (e.target.closest && e.target.closest('[data-x]'))) { closeSheet(); return; }
    var b = e.target.closest ? e.target.closest('[data-go]') : null; if (b) { var k = b.getAttribute('data-go'); closeSheet(); openPage(k); }
  });
  sheet.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeSheet(); return; }
    if (e.key === 'Enter') { var on = sheet.querySelector('.aws-row.on') || sheet.querySelector('.aws-row'); if (on) { var k = on.getAttribute('data-go'); closeSheet(); openPage(k); } }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      var rs = [].slice.call(sheet.querySelectorAll('.aws-row')), i = rs.indexOf(sheet.querySelector('.aws-row.on')); if (!rs.length) return; e.preventDefault();
      i = e.key === 'ArrowDown' ? Math.min(rs.length - 1, i + 1) : Math.max(0, i - 1); rs.forEach(function (r, j) { r.classList.toggle('on', j === i); }); rs[i].scrollIntoView({ block: 'nearest' });
    }
  });
}
function closeSheet() { if (sheet && sheet.parentNode) sheet.parentNode.removeChild(sheet); sheet = null; }

/* ---------- wiring ---------- */
function wire() {
  document.addEventListener('click', function (e) {
    var t = e.target; if (!t.closest) return;
    var c = t.closest('#aws-switch .aws-chip');
    if (c) { var id = c.getAttribute('data-ws'); setWs(id); showHome(); return; }
    if (t.closest('#aws-switch [data-aws-home]')) { showHome(); return; }
    if (t.closest('#aws-switch [data-aws-pages]')) { openSheet(); return; }
    var g = t.closest('#panel-wshome [data-go]'); if (g) openPage(g.getAttribute('data-go'));
  });
  var sb = sidebar();
  if (sb) sb.addEventListener('input', function (e) { if (e.target.id === 'anav-q') sb.classList.toggle('aws-all', !!e.target.value); });
  var orig = window.switchPage;
  if (typeof orig === 'function' && !orig._aws) {
    window.switchPage = function (page) {
      if (page === 'wshome') panel();
      if (page !== 'wshome' && OWNER[page] && OWNER[page].indexOf(cur) < 0) { cur = OWNER[page][0]; try { localStorage.setItem(STORE, cur); } catch (e) { /* ignore */ } }
      var r = orig.apply(this, arguments);
      if (LOAD[page]) startLoader(page);
      if (page === 'wshome') {
        var w = wsById(cur), t = document.getElementById('page-title'); if (t) t.textContent = w.label + ' home';
        var ab = document.getElementById('action-buttons'); if (ab) ab.innerHTML = '';
        home(); loadSnap(function () { if (typeof currentPage !== 'undefined' && currentPage === 'wshome') home(); });
      }
      apply();
      return r;
    };
    window.switchPage._aws = true;
  }
}

window.AdminWS = { show: function (id) { setWs(id); showHome(); }, current: function () { return cur; }, apply: apply };

function install() {
  if (!build()) return;
  wire(); apply();
  var again = function () { build(); apply(); };
  setTimeout(again, 400); setTimeout(again, 1500);
  if (window.ETEL_CAPS_PROMISE && window.ETEL_CAPS_PROMISE.then) window.ETEL_CAPS_PROMISE.then(function () { capsDone = true; setTimeout(function () { again(); land(); }, 80); }, function () { capsDone = true; });
  setTimeout(land, 900); setTimeout(function () { capsDone = true; land(); }, 4000);
}
/* The first screen is the workspace Home, unless the person already opened another page (a link, a filter). */
function land() {
  if (landed || !capsDone) return;
  if (typeof currentPage !== 'undefined' && currentPage !== 'dashboard') { landed = true; return; }
  if (!wsUsable(wsById(cur))) { apply(); if (!wsUsable(wsById(cur))) return; }
  landed = true; showHome();
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
