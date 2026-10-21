/* ETEL Admin - People: Users and Customers tabs.
   One screen for everyone on ETEL: live counts that double as filters, search, sort, card or table view, and a
   profile drawer with the account controls (disable / restore, sign out everywhere, reset two-step sign-in).
   Data: admin_people_list, admin_person_get, admin_person_set_active, admin_person_force_logout
         (api/controllers/UserAdminController.php), admin_customers_list (CustomerAdminController.php),
         admin_duplicate_phones, admin_reset_user_2fa, admin_get_my_capabilities.
   Standalone on purpose: it replaces the old plain tables by overriding loadUsers() and installing the Customers
   page itself, so admin.js is not edited. Every server value is escaped before it goes into HTML. */
(function () {
'use strict';

var ROLES = {
  customer:      { label: 'Customer',      plural: 'Customers',       icon: '🛍️', a: '#4ECDC4', b: '#2a9d8f' },
  courier:       { label: 'Courier',       plural: 'Couriers',        icon: '🛵', a: '#a875d6', b: '#7b3fb0' },
  driver:        { label: 'Driver',        plural: 'Drivers',         icon: '🚗', a: '#5aa9ff', b: '#2f6fd0' },
  service_giver: { label: 'Service giver', plural: 'Service givers',  icon: '🏪', a: '#ff9a62', b: '#e0622c' },
  admin:         { label: 'Staff',         plural: 'Staff',           icon: '🛡️', a: '#E8C14A', b: '#9a7a1c' }
};
var ROLE_ORDER = ['customer', 'courier', 'driver', 'service_giver', 'admin'];
var CAPS = null;                 // null until the capability check answers; the server enforces either way
var OPEN_ID = null;
var CUR = null;                  // the account the open drawer is showing

var VIEWS = {
  users:     { key: 'users',     role: '', status: '', q: '', sort: 'recent', mode: 'cards', data: [], summary: null, loaded: false },
  customers: { key: 'customers', role: '', status: '', q: '', sort: 'recent', mode: 'cards', data: [], summary: null, loaded: false }
};
try { var m = localStorage.getItem('ppl-mode'); if (m === 'table' || m === 'cards') { VIEWS.users.mode = m; VIEWS.customers.mode = m; } } catch (e) { /* private window: keep the default */ }

/* ---------- helpers ---------- */
function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
function money(n) { return 'ETB ' + (Number(n) || 0).toLocaleString(undefined, { maximumFractionDigits: 0 }); }
function num(n) { return (Number(n) || 0).toLocaleString(); }
function parseDate(s) { if (!s) return null; var d = new Date(String(s).replace(' ', 'T')); return isNaN(d.getTime()) ? null : d; }
function ago(s) {
  var d = parseDate(s); if (!d) return '—';
  var sec = Math.max(0, (Date.now() - d.getTime()) / 1000);
  if (sec < 90) return 'just now';
  if (sec < 3600) return Math.round(sec / 60) + ' min ago';
  if (sec < 86400) return Math.round(sec / 3600) + ' h ago';
  if (sec < 86400 * 30) return Math.round(sec / 86400) + ' d ago';
  if (sec < 86400 * 365) return Math.round(sec / 86400 / 30) + ' mo ago';
  return Math.round(sec / 86400 / 365) + ' y ago';
}
function fullDate(s) { var d = parseDate(s); return d ? d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'; }
function initials(name) {
  var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '?';
  var a = Array.from(parts[0])[0] || '?', b = parts.length > 1 ? (Array.from(parts[1])[0] || '') : '';
  return (a + b).toUpperCase();
}
function role(r) { return ROLES[r] || { label: String(r || 'User').replace(/_/g, ' '), plural: 'Users', icon: '👤', a: '#b8b0a0', b: '#7b7566' }; }
function can(c) { return CAPS === null || CAPS.indexOf('*') >= 0 || CAPS.indexOf(c) >= 0; }
function token() { return typeof Auth !== 'undefined' && Auth.getToken ? Auth.getToken() : ''; }
function api(action, body) {
  var url = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
  return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() }, body: JSON.stringify(Object.assign({ action: action }, body || {})) })
    .then(function (r) { return r.json().catch(function () { return { success: false, message: 'The server sent an unreadable answer.' }; }); });
}
function failText(res, dflt) { return (res && (res.message || res.error)) || dflt; }
function toast(msg, bad) {
  var t = document.createElement('div');
  t.className = 'ppl-toast' + (bad ? ' bad' : '');
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(function () { t.classList.add('out'); }, 2600);
  setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 3100);
}
function copy(text) {
  var done = function () { toast('Copied ' + text); };
  if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, function () { toast('Could not copy', true); }); return; }
  var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select();
  try { document.execCommand('copy'); done(); } catch (e) { toast('Could not copy', true); }
  document.body.removeChild(ta);
}

/* ---------- styles ---------- */
function css() {
  if (document.getElementById('ppl-css')) return;
  var s = document.createElement('style'); s.id = 'ppl-css';
  s.textContent = [
    '.ppl{--ink:#1A1A2E;--mute:#6b6b82;--line:#ece5d2;--card:#fff;--soft:#faf6ea;--gold:#D4AF37;--gold2:#8B7230;--red:#d6364a;--green:#1fa463;--shadow:0 6px 22px rgba(60,45,10,.09);color:var(--ink);font-family:inherit}',
    'body.dark-theme .ppl{--ink:#f4efe0;--mute:#a9a38f;--line:#34324a;--card:#1b1b2a;--soft:#232337;--shadow:0 8px 26px rgba(0,0,0,.45)}',
    '.ppl *{box-sizing:border-box}',
    '.ppl-hero{position:relative;overflow:hidden;border-radius:20px;padding:18px 20px;margin-bottom:14px;color:#fff;background:linear-gradient(120deg,#1a1630 0%,#2b2147 55%,#6d5413 130%);box-shadow:var(--shadow)}',
    '.ppl-hero::after{content:"";position:absolute;right:-40px;top:-60px;width:220px;height:220px;border-radius:50%;background:radial-gradient(circle,rgba(212,175,55,.45),rgba(212,175,55,0) 70%)}',
    '.ppl-hero h2{margin:0;font-size:19px;font-weight:800;letter-spacing:.2px;position:relative}',
    '.ppl-hero p{margin:4px 0 0;font-size:12.5px;opacity:.78;position:relative;max-width:520px}',
    '.ppl-hero-row{display:flex;justify-content:space-between;gap:12px;align-items:flex-end;flex-wrap:wrap;position:relative}',
    '.ppl-hero-big{display:flex;gap:18px;margin-top:12px;position:relative;flex-wrap:wrap}',
    '.ppl-hero-big b{display:block;font-size:26px;font-weight:800;line-height:1.1;color:#F2CC6B}',
    '.ppl-hero-big span{font-size:11px;opacity:.75;text-transform:uppercase;letter-spacing:.06em}',
    '.ppl-kpis{display:flex;gap:10px;overflow-x:auto;padding:2px 2px 8px;margin-bottom:6px;scrollbar-width:thin}',
    '.ppl-kpi{flex:0 0 auto;min-width:112px;text-align:left;cursor:pointer;border:1.5px solid var(--line);background:var(--card);border-radius:16px;padding:11px 14px;color:var(--ink);font:inherit;box-shadow:var(--shadow);transition:transform .15s,border-color .15s}',
    '.ppl-kpi:hover{transform:translateY(-2px)}',
    '.ppl-kpi.on{border-color:var(--gold);background:linear-gradient(160deg,rgba(212,175,55,.16),var(--card))}',
    '.ppl-kpi.static{cursor:default}.ppl-kpi.static:hover{transform:none}',
    '.ppl-kpi i{font-style:normal;font-size:17px;display:block}',
    '.ppl-kpi b{display:block;font-size:22px;font-weight:800;margin-top:2px;line-height:1.1}',
    '.ppl-kpi span{display:block;font-size:11px;color:var(--mute);margin-top:2px;white-space:nowrap}',
    '.ppl-bar{height:4px;border-radius:4px;background:var(--line);margin-top:7px;overflow:hidden}.ppl-bar em{display:block;height:100%;border-radius:4px;background:linear-gradient(90deg,var(--gold),var(--gold2))}',
    '.ppl-tools{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:8px 0 10px}',
    '.ppl-search{position:relative;flex:1 1 220px;min-width:180px}',
    '.ppl-search input{width:100%;padding:11px 36px 11px 38px;border-radius:14px;border:1.5px solid var(--line);background:var(--card);color:var(--ink);font-size:14px;outline:none}',
    '.ppl-search input:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(212,175,55,.22)}',
    '.ppl-search u{position:absolute;left:13px;top:50%;transform:translateY(-50%);text-decoration:none;opacity:.55;font-size:14px;pointer-events:none}',
    '.ppl-search button{position:absolute;right:8px;top:50%;transform:translateY(-50%);border:0;background:var(--soft);color:var(--mute);border-radius:50%;width:22px;height:22px;cursor:pointer;font-size:12px;line-height:22px;padding:0}',
    '.ppl-seg{display:inline-flex;background:var(--soft);border:1.5px solid var(--line);border-radius:12px;padding:3px}',
    '.ppl-seg button{border:0;background:transparent;color:var(--mute);font:inherit;font-size:12.5px;font-weight:600;padding:7px 12px;border-radius:9px;cursor:pointer}',
    '.ppl-seg button.on{background:var(--card);color:var(--ink);box-shadow:0 2px 8px rgba(0,0,0,.12)}',
    '.ppl-sel,.ppl-btn{border:1.5px solid var(--line);background:var(--card);color:var(--ink);font:inherit;font-size:12.5px;font-weight:600;border-radius:12px;padding:9px 12px;cursor:pointer}',
    '.ppl-btn:hover,.ppl-sel:hover{border-color:var(--gold)}',
    '.ppl-btn.gold{background:linear-gradient(135deg,var(--gold),var(--gold2));color:#1c1500;border-color:transparent}',
    '.ppl-btn.danger{color:var(--red);border-color:rgba(214,54,74,.45)}',
    '.ppl-btn.danger.solid{background:var(--red);color:#fff;border-color:transparent}',
    '.ppl-btn[disabled]{opacity:.5;cursor:not-allowed}',
    '.ppl-count{font-size:12px;color:var(--mute);margin:2px 2px 10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}',
    '.ppl-chip{display:inline-flex;gap:6px;align-items:center;background:var(--soft);border:1px solid var(--line);border-radius:20px;padding:3px 6px 3px 10px;font-size:11.5px;color:var(--ink)}',
    '.ppl-chip button{border:0;background:transparent;color:var(--mute);cursor:pointer;font-size:12px;padding:0 2px}',
    '.ppl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:12px}',
    '.ppl-card{position:relative;background:var(--card);border:1.5px solid var(--line);border-radius:18px;padding:14px;cursor:pointer;box-shadow:var(--shadow);transition:transform .15s,border-color .15s;outline:none}',
    '.ppl-card:hover,.ppl-card:focus-visible{transform:translateY(-2px);border-color:var(--gold)}',
    '.ppl-card.off{border-style:dashed;border-color:rgba(214,54,74,.5);background:linear-gradient(0deg,rgba(214,54,74,.05),rgba(214,54,74,.05)),var(--card)}',
    '.ppl-card.off .ppl-av{filter:grayscale(.85);opacity:.8}',
    '.ppl-top{display:flex;gap:11px;align-items:center}',
    '.ppl-av{flex:none;width:46px;height:46px;border-radius:15px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:16px;letter-spacing:.3px;box-shadow:0 6px 14px -4px rgba(0,0,0,.35),inset 0 1px 0 rgba(255,255,255,.35)}',
    '.ppl-av.lg{width:64px;height:64px;border-radius:20px;font-size:23px}',
    '.ppl-nm{min-width:0;flex:1}',
    '.ppl-nm b{display:block;font-size:14.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.ppl-pill{display:inline-flex;gap:4px;align-items:center;font-size:10.5px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;padding:2px 9px;border-radius:20px;margin-top:3px;color:#fff}',
    '.ppl-st{flex:none;font-size:10.5px;font-weight:800;padding:3px 9px;border-radius:20px;display:inline-flex;gap:5px;align-items:center}',
    '.ppl-st::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}',
    '.ppl-st.on{color:var(--green);background:rgba(31,164,99,.12)}.ppl-st.off{color:var(--red);background:rgba(214,54,74,.12)}',
    '.ppl-ph{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:12px;padding:8px 10px;border-radius:11px;background:var(--soft);font-family:ui-monospace,Menlo,monospace;font-size:12.5px}',
    '.ppl-ph button{border:0;background:transparent;cursor:pointer;font-size:14px;padding:2px 4px;color:var(--mute)}',
    '.ppl-stats{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;min-height:24px}',
    '.ppl-stat{font-size:11.5px;border-radius:9px;padding:3px 9px;background:var(--soft);border:1px solid var(--line)}',
    '.ppl-stat.dim{color:var(--mute)}',
    '.ppl-foot{display:flex;justify-content:space-between;gap:8px;margin-top:10px;font-size:11px;color:var(--mute)}',
    '.ppl-tblw{overflow-x:auto;border:1.5px solid var(--line);border-radius:16px;background:var(--card);box-shadow:var(--shadow)}',
    '.ppl-tbl{width:100%;border-collapse:collapse;min-width:640px}',
    '.ppl-tbl th{position:sticky;top:0;text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.07em;color:var(--mute);padding:11px 12px;background:var(--soft);white-space:nowrap}',
    '.ppl-tbl td{padding:10px 12px;border-top:1px solid var(--line);font-size:13px;vertical-align:middle}',
    '.ppl-tbl tr[data-id]{cursor:pointer}.ppl-tbl tr[data-id]:hover td{background:rgba(212,175,55,.08)}',
    '.ppl-tbl tr.off td{opacity:.6}',
    '.ppl-cell{display:flex;gap:10px;align-items:center;min-width:0}.ppl-cell .ppl-av{width:34px;height:34px;border-radius:11px;font-size:12.5px}',
    '.ppl-mono{font-family:ui-monospace,Menlo,monospace;font-size:12px}',
    '.ppl-empty{text-align:center;padding:46px 16px;color:var(--mute)}.ppl-empty big{display:block;font-size:42px;margin-bottom:6px}',
    '.ppl-skel{height:150px;border-radius:18px;background:linear-gradient(90deg,var(--soft) 0,var(--line) 50%,var(--soft) 100%);background-size:200% 100%;animation:ppl-sk 1.2s linear infinite}',
    '@keyframes ppl-sk{to{background-position:-200% 0}}',
    '.ppl-err{padding:18px;border-radius:14px;background:rgba(214,54,74,.1);color:var(--red);display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}',
    /* drawer */
    '.ppl-ov{position:fixed;inset:0;z-index:9000;background:rgba(14,10,30,.55);backdrop-filter:blur(3px);opacity:0;transition:opacity .2s}',
    '.ppl-ov.in{opacity:1}',
    '.ppl-dr{position:fixed;z-index:9001;background:var(--card,#fff);color:var(--ink,#1A1A2E);--ink:#1A1A2E;--mute:#6b6b82;--line:#ece5d2;--card:#fff;--soft:#faf6ea;--gold:#D4AF37;--gold2:#8B7230;--red:#d6364a;--green:#1fa463;display:flex;flex-direction:column;box-shadow:0 -10px 50px rgba(0,0,0,.4);transition:transform .25s cubic-bezier(.2,.8,.2,1);font-family:inherit}',
    'body.dark-theme .ppl-dr{--ink:#f4efe0;--mute:#a9a38f;--line:#34324a;--card:#1b1b2a;--soft:#232337}',
    '.ppl-dr{left:0;right:0;bottom:0;max-height:92vh;border-radius:24px 24px 0 0;transform:translateY(100%)}',
    '.ppl-dr.in{transform:none}',
    '@media(min-width:900px){.ppl-dr{left:auto;top:0;bottom:0;right:0;width:460px;max-height:none;border-radius:24px 0 0 24px;transform:translateX(100%)}}',
    '.ppl-dh{position:relative;padding:20px 20px 16px;background:linear-gradient(130deg,#1a1630,#2b2147 60%,#6d5413 140%);color:#fff;border-radius:24px 24px 0 0}',
    '@media(min-width:900px){.ppl-dh{border-radius:24px 0 0 0}}',
    '.ppl-dh .x{position:absolute;right:14px;top:14px;border:0;background:rgba(255,255,255,.16);color:#fff;width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:15px}',
    '.ppl-dh h3{margin:10px 0 2px;font-size:19px;font-weight:800;word-break:break-word}',
    '.ppl-dh .sub{font-size:12px;opacity:.78}',
    '.ppl-db{padding:16px 20px 28px;overflow-y:auto;flex:1}',
    '.ppl-sec{margin-top:16px}',
    '.ppl-sec h4{margin:0 0 8px;font-size:11px;text-transform:uppercase;letter-spacing:.09em;color:var(--mute);font-weight:800}',
    '.ppl-acts{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}',
    '.ppl-acts a,.ppl-acts button{display:flex;flex-direction:column;align-items:center;gap:4px;border:1.5px solid var(--line);background:var(--soft);color:var(--ink);border-radius:14px;padding:11px 6px;font:inherit;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none}',
    '.ppl-acts a i,.ppl-acts button i{font-style:normal;font-size:18px}',
    '.ppl-acts a:hover,.ppl-acts button:hover{border-color:var(--gold)}',
    '.ppl-kv{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}',
    '.ppl-kv div{background:var(--soft);border:1px solid var(--line);border-radius:13px;padding:10px 12px}',
    '.ppl-kv b{display:block;font-size:17px;font-weight:800}.ppl-kv span{font-size:11px;color:var(--mute)}',
    '.ppl-list{border:1px solid var(--line);border-radius:14px;overflow:hidden}',
    '.ppl-list div{display:flex;justify-content:space-between;gap:10px;padding:9px 12px;font-size:12.5px;border-top:1px solid var(--line)}',
    '.ppl-list div:first-child{border-top:0}.ppl-list small{color:var(--mute);display:block;font-size:11px}',
    '.ppl-tag{font-size:10.5px;font-weight:800;padding:2px 8px;border-radius:12px;background:var(--soft);border:1px solid var(--line);text-transform:capitalize;white-space:nowrap;align-self:center}',
    '.ppl-warn{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border-radius:13px;background:rgba(240,180,41,.14);color:#8a6200;font-size:12.5px;margin-top:12px}',
    'body.dark-theme .ppl-warn{color:#F2CC6B}',
    '.ppl-ctl{display:grid;gap:8px}',
    '.ppl-ctl .ppl-btn{width:100%;text-align:left;display:flex;gap:10px;align-items:center;padding:12px 14px}',
    '.ppl-ctl small{display:block;font-weight:400;color:var(--mute);font-size:11px;margin-top:1px}',
    /* modal */
    '.ppl-md{position:fixed;z-index:9100;left:50%;top:50%;transform:translate(-50%,-50%);width:min(92vw,420px);background:#fff;color:#1A1A2E;border-radius:20px;padding:20px;box-shadow:0 30px 80px rgba(0,0,0,.5)}',
    'body.dark-theme .ppl-md{background:#1b1b2a;color:#f4efe0}',
    '.ppl-md h3{margin:0 0 6px;font-size:17px}.ppl-md p{margin:0 0 12px;font-size:13px;opacity:.8;line-height:1.45}',
    '.ppl-md textarea{width:100%;min-height:70px;border-radius:12px;border:1.5px solid rgba(128,128,128,.35);background:transparent;color:inherit;padding:10px;font:inherit;font-size:13px;resize:vertical}',
    '.ppl-md .row{display:flex;gap:8px;justify-content:flex-end;margin-top:14px}',
    '.ppl-md .ppl-btn{--ink:#1A1A2E;--card:#fff;--line:#d9d2bd;--gold:#D4AF37;--red:#d6364a;--gold2:#8B7230}',
    'body.dark-theme .ppl-md .ppl-btn{--ink:#f4efe0;--card:#232337;--line:#34324a}',
    '.ppl-dup{margin-top:10px;border:1px solid rgba(128,128,128,.3);border-radius:12px;overflow:hidden}',
    '.ppl-dup div{display:flex;justify-content:space-between;gap:8px;padding:8px 10px;font-size:12.5px;border-top:1px solid rgba(128,128,128,.2);cursor:pointer}.ppl-dup div:first-child{border-top:0}',
    '.ppl-toast{position:fixed;z-index:9200;left:50%;bottom:90px;transform:translateX(-50%);background:#1A1A2E;color:#fff;padding:10px 18px;border-radius:24px;font-size:13px;box-shadow:0 10px 30px rgba(0,0,0,.4);transition:opacity .4s}',
    '.ppl-toast.bad{background:#b3243a}.ppl-toast.out{opacity:0}',
    '@media(prefers-reduced-motion:reduce){.ppl-card,.ppl-kpi,.ppl-dr,.ppl-ov{transition:none}.ppl-skel{animation:none}}'
  ].join('\n');
  document.head.appendChild(s);
}

/* ---------- list rendering ---------- */
function avatar(p, cls) {
  var r = role(p.role);
  return '<div class="ppl-av ' + (cls || '') + '" style="background:linear-gradient(145deg,' + r.a + ',' + r.b + ')" aria-hidden="true">' + esc(initials(p.name)) + '</div>';
}
function pill(p) {
  var r = role(p.role);
  return '<span class="ppl-pill" style="background:linear-gradient(135deg,' + r.a + ',' + r.b + ')">' + r.icon + ' ' + esc(r.label) + '</span>';
}
function stateTag(p) { return p.suspended ? '<span class="ppl-st off">Disabled</span>' : '<span class="ppl-st on">Active</span>'; }

function statsFor(p, v) {
  var out = [];
  if (v.key === 'customers') {
    out.push('<span class="ppl-stat">🧾 ' + num(p.orders_total) + ' order' + (p.orders_total === 1 ? '' : 's') + '</span>');
    if (p.cancelled) out.push('<span class="ppl-stat dim">✖ ' + num(p.cancelled) + ' cancelled</span>');
    out.push('<span class="ppl-stat">💰 ' + money(p.spent) + '</span>');
  } else {
    if (p.orders_total) out.push('<span class="ppl-stat">🧾 ' + num(p.orders_total) + ' order' + (p.orders_total === 1 ? '' : 's') + '</span>');
    if (p.rides_total) out.push('<span class="ppl-stat">🚗 ' + num(p.rides_total) + ' ride' + (p.rides_total === 1 ? '' : 's') + '</span>');
    if (!out.length) out.push('<span class="ppl-stat dim">No activity yet</span>');
  }
  return out.join('');
}
function cardHtml(p, v) {
  var last = p.last_order_at ? 'Last order ' + ago(p.last_order_at) : 'No orders yet';
  return '<div class="ppl-card' + (p.suspended ? ' off' : '') + '" data-id="' + p.id + '" role="button" tabindex="0" aria-label="Open ' + esc(p.name) + '">' +
    '<div class="ppl-top">' + avatar(p) + '<div class="ppl-nm"><b>' + esc(p.name || 'Unnamed') + '</b>' + pill(p) + '</div>' + stateTag(p) + '</div>' +
    '<div class="ppl-ph"><span>' + esc(p.phone || '—') + '</span><button type="button" data-copy="' + esc(p.phone) + '" aria-label="Copy phone number" title="Copy">📋</button></div>' +
    '<div class="ppl-stats">' + statsFor(p, v) + '</div>' +
    '<div class="ppl-foot"><span title="' + esc(fullDate(p.created_at)) + '">Joined ' + ago(p.created_at) + '</span><span>' + (v.key === 'customers' || p.orders_total ? esc(last) : '#' + p.id) + '</span></div></div>';
}
function rowHtml(p, v) {
  var cust = v.key === 'customers';
  return '<tr data-id="' + p.id + '" class="' + (p.suspended ? 'off' : '') + '"><td><div class="ppl-cell">' + avatar(p) + '<div style="min-width:0"><b>' + esc(p.name || 'Unnamed') + '</b><div class="ppl-mono" style="opacity:.6">#' + p.id + '</div></div></div></td>' +
    '<td>' + pill(p) + '</td><td class="ppl-mono">' + esc(p.phone) + '</td>' +
    '<td>' + (cust ? num(p.orders_total) + ' · ' + money(p.spent) : (num(p.orders_total) + ' orders · ' + num(p.rides_total) + ' rides')) + '</td>' +
    '<td title="' + esc(fullDate(p.created_at)) + '">' + ago(p.created_at) + '</td><td>' + stateTag(p) + '</td></tr>';
}
function listHtml(v) {
  var rows = filtered(v);
  if (!rows.length) return '<div class="ppl-empty"><big>🔎</big><b>No one matches</b><div style="margin-top:4px;font-size:12.5px">Try a different search, or clear the filters.</div></div>';
  if (v.mode === 'table') {
    return '<div class="ppl-tblw"><table class="ppl-tbl"><thead><tr><th>Person</th><th>Role</th><th>Phone</th><th>' + (v.key === 'customers' ? 'Orders · Spent' : 'Activity') + '</th><th>Joined</th><th>Status</th></tr></thead><tbody>' +
      rows.map(function (p) { return rowHtml(p, v); }).join('') + '</tbody></table></div>';
  }
  return '<div class="ppl-grid">' + rows.map(function (p) { return cardHtml(p, v); }).join('') + '</div>';
}
function filtered(v) {
  return v.data.filter(function (p) {
    if (v.status === 'active' && p.suspended) return false;
    if (v.status === 'suspended' && !p.suspended) return false;
    if (v.role && p.role !== v.role) return false;
    if (v.q) { var q = v.q.toLowerCase(); if (String(p.name || '').toLowerCase().indexOf(q) < 0 && String(p.phone || '').indexOf(v.q) < 0 && String(p.id) !== v.q) return false; }
    return true;
  });
}

/* ---------- page shell ---------- */
function kpisHtml(v) {
  var s = v.summary || {}, by = s.by_role || {}, t = s.total || 0, tiles = [];
  if (v.key === 'users') {
    tiles.push({ id: '', icon: '👥', n: t, label: 'Everyone', share: 1 });
    ROLE_ORDER.forEach(function (r) { if (by[r] != null) tiles.push({ id: r, icon: role(r).icon, n: by[r], label: role(r).plural, share: t ? by[r] / t : 0 }); });
    tiles.push({ id: '!off', icon: '⛔', n: s.suspended || 0, label: 'Disabled', share: t ? (s.suspended || 0) / t : 0 });
    tiles.push({ id: '!new', icon: '✨', n: s.new_7d || 0, label: 'New this week', share: t ? (s.new_7d || 0) / t : 0, st: 1 });
  } else {
    var off = v.data.filter(function (p) { return p.suspended; }).length;
    var rev = v.data.reduce(function (a, p) { return a + (Number(p.spent) || 0); }, 0);
    tiles.push({ id: '', icon: '🛍️', n: s.total || 0, label: 'Customers', share: 1 });
    tiles.push({ id: '!act', icon: '🔥', n: s.active_30d || 0, label: 'Ordered in 30 days', share: t ? (s.active_30d || 0) / t : 0, st: 1 });
    tiles.push({ id: '!new', icon: '✨', n: s.new_7d || 0, label: 'New this week', share: t ? (s.new_7d || 0) / t : 0, st: 1 });
    tiles.push({ id: '!off', icon: '⛔', n: off, label: 'Disabled', share: t ? off / t : 0 });
    tiles.push({ id: '!rev', icon: '💰', n: Math.round(rev).toLocaleString(), label: 'Spent (ETB, delivered)', share: 0, st: 1 });
  }
  return '<div class="ppl-kpis">' + tiles.map(function (k) {
    var on = (k.id === '!off' ? v.status === 'suspended' : (k.id === '' ? (!v.role && !v.status) : k.id === v.role && !v.status));
    return '<button type="button" class="ppl-kpi' + (on ? ' on' : '') + (k.st ? ' static' : '') + '" data-kpi="' + esc(k.id) + '"' + (k.st ? ' tabindex="-1"' : '') + '><i>' + k.icon + '</i><b>' + esc(k.n) + '</b><span>' + esc(k.label) + '</span>' +
      (k.share ? '<div class="ppl-bar"><em style="width:' + Math.max(3, Math.round(k.share * 100)) + '%"></em></div>' : '') + '</button>';
  }).join('') + '</div>';
}
function shellHtml(v) {
  var sorts = v.key === 'customers'
    ? [['recent', 'Latest order'], ['orders', 'Most orders'], ['spent', 'Spent most'], ['name', 'Name A–Z']]
    : [['recent', 'Newest first'], ['oldest', 'Oldest first'], ['name', 'Name A–Z'], ['orders', 'Most active']];
  var s = v.summary || {};
  var hero = v.key === 'customers'
    ? { t: 'Customers', p: 'Who orders, how often and how much. Open anyone to see their orders and control their account.' }
    : { t: 'People', p: 'Every customer, courier, driver, shop and staff account on ETEL. Open anyone for their profile and controls.' };
  return '<div class="ppl" data-ppl="' + v.key + '">' +
    '<div class="ppl-hero"><div class="ppl-hero-row"><div><h2>' + hero.t + '</h2><p>' + hero.p + '</p></div>' +
    '<div style="display:flex;gap:8px;flex-wrap:wrap">' + (v.key === 'users' && can('users.manage') ? '<button type="button" class="ppl-btn gold" data-act="new">➕ New user</button>' : '') +
    '<button type="button" class="ppl-btn" data-act="dupes" title="Accounts that share one phone number written two ways">📞 Duplicate phones</button></div></div>' +
    '<div class="ppl-hero-big"><div><b>' + num(s.total || 0) + '</b><span>' + (v.key === 'customers' ? 'customers' : 'accounts') + '</span></div>' +
    '<div><b>' + num(s.new_7d || 0) + '</b><span>new this week</span></div>' +
    (v.key === 'users' ? '<div><b>' + num(s.suspended || 0) + '</b><span>disabled</span></div>' : '<div><b>' + num(s.active_30d || 0) + '</b><span>ordered in 30 days</span></div>') + '</div></div>' +
    '<div data-kpis>' + kpisHtml(v) + '</div>' +
    '<div class="ppl-tools"><div class="ppl-search"><u>🔎</u><input data-q type="search" placeholder="Search name, phone or ID…" value="' + esc(v.q) + '" aria-label="Search people" autocomplete="off"><button type="button" data-clearq aria-label="Clear search" style="' + (v.q ? '' : 'display:none') + '">✕</button></div>' +
    '<div class="ppl-seg" role="group" aria-label="Status">' + [['', 'All'], ['active', 'Active'], ['suspended', 'Disabled']].map(function (o) { return '<button type="button" data-status="' + o[0] + '" class="' + (v.status === o[0] ? 'on' : '') + '">' + o[1] + '</button>'; }).join('') + '</div>' +
    '<select class="ppl-sel" data-sort aria-label="Sort">' + sorts.map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === v.sort ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select>' +
    '<div class="ppl-seg" role="group" aria-label="View"><button type="button" data-mode="cards" class="' + (v.mode === 'cards' ? 'on' : '') + '" title="Cards">▦</button><button type="button" data-mode="table" class="' + (v.mode === 'table' ? 'on' : '') + '" title="Table">☰</button></div>' +
    '<button type="button" class="ppl-btn" data-act="export" title="Download this list as a spreadsheet">📥 Export</button>' +
    '<button type="button" class="ppl-btn" data-act="refresh" title="Refresh">🔄</button></div>' +
    '<div class="ppl-count" data-count></div><div data-list></div></div>';
}
function paintCount(el, v) {
  var n = filtered(v).length, c = el.querySelector('[data-count]');
  var chips = '';
  if (v.role && v.key === 'users') chips += '<span class="ppl-chip">' + esc(role(v.role).plural) + ' <button type="button" data-clr="role" aria-label="Remove filter">✕</button></span>';
  if (v.status) chips += '<span class="ppl-chip">' + (v.status === 'suspended' ? 'Disabled' : 'Active') + ' <button type="button" data-clr="status" aria-label="Remove filter">✕</button></span>';
  c.innerHTML = '<span>Showing <b>' + num(n) + '</b> of ' + num(v.data.length) + '</span>' + chips;
}
function paint(el, v) {
  el.querySelector('[data-list]').innerHTML = listHtml(v);
  el.querySelector('[data-kpis]').innerHTML = kpisHtml(v);
  paintCount(el, v);
  if (v.key === 'users') { try { usersData = filtered(v); } catch (e) { /* the old CSV export reads this list */ } }
}

/* ---------- loading ---------- */
function rootOf(v) { return document.querySelector('#panel-' + v.key + ' .ppl[data-ppl="' + v.key + '"]'); }
function skeleton(v) {
  var panel = document.getElementById('panel-' + v.key); if (!panel) return;
  panel.innerHTML = '<div class="ppl"><div class="ppl-hero"><h2>' + (v.key === 'customers' ? 'Customers' : 'People') + '</h2><p>Loading…</p></div><div class="ppl-grid"><div class="ppl-skel"></div><div class="ppl-skel"></div><div class="ppl-skel"></div></div></div>';
}
function shims(panel) {
  /* Old code may still look these up; keep harmless hidden copies. */
  var h = document.createElement('div'); h.style.display = 'none';
  h.innerHTML = '<table><tbody id="users-tbody"></tbody></table><select id="user-role-filter"></select><input id="user-search"><div id="dup-phones-box"></div>';
  panel.appendChild(h);
}
function mount(v, keep) {
  var panel = document.getElementById('panel-' + v.key); if (!panel) return;
  var el = rootOf(v);
  if (el && keep) { paint(el, v); return; }
  panel.innerHTML = shellHtml(v);
  if (v.key === 'users') shims(panel);
  paint(rootOf(v), v);
}
function load(v, keep) {
  var panel = document.getElementById('panel-' + v.key); if (!panel) return Promise.resolve();
  if (!keep || !rootOf(v)) skeleton(v);
  var p;
  if (v.key === 'customers') {
    p = api('admin_customers_list', { sort: v.sort, limit: 500 }).then(function (res) {
      if (!res || res.success === false || !res.data) throw new Error(failText(res, 'Could not load the customers.'));
      v.data = (res.data.customers || []).map(function (c) { c.role = 'customer'; c.rides_total = 0; return c; });
      v.summary = Object.assign({ by_role: { customer: res.data.summary ? res.data.summary.total : v.data.length } }, res.data.summary || {});
    });
  } else {
    p = api('admin_people_list', { sort: v.sort, limit: 500 }).then(function (res) {
      if (res && res.success !== false && res.data) { v.data = res.data.people || []; v.summary = res.data.summary || {}; return; }
      if (res && /unknown|not found|invalid action/i.test(String(res.message || res.error || ''))) return legacyUsers(v);   // new server file not uploaded yet
      throw new Error(failText(res, 'Could not load the accounts.'));
    });
  }
  return p.then(function () { v.loaded = true; mount(v, false); }).catch(function (e) {
    panel.innerHTML = '<div class="ppl"><div class="ppl-err"><span>⚠️ ' + esc(e.message || 'Could not load.') + '</span><button type="button" class="ppl-btn" data-act="refresh">Try again</button></div></div>';
  });
}
function legacyUsers(v) {
  return api('admin_get_users', { limit: 500 }).then(function (res) {
    if (!res || res.success === false) throw new Error(failText(res, 'Could not load the accounts.'));
    var rows = ((res.data && res.data.users) || []).map(function (u) { u.suspended = Number(u.active) === 0; u.orders_total = 0; u.rides_total = 0; return u; });
    var by = {}; rows.forEach(function (u) { by[u.role] = (by[u.role] || 0) + 1; });
    v.data = rows;
    v.summary = { total: rows.length, by_role: by, suspended: rows.filter(function (u) { return u.suspended; }).length, new_7d: rows.filter(function (u) { var d = parseDate(u.created_at); return d && Date.now() - d.getTime() < 7 * 864e5; }).length };
  });
}

/* ---------- events ---------- */
var searchTimer = null;
function wire(panel, v) {
  panel.addEventListener('click', function (e) {
    var el = rootOf(v); if (!el) return;
    var t = e.target;
    var cp = t.closest('[data-copy]'); if (cp) { e.stopPropagation(); copy(cp.getAttribute('data-copy')); return; }
    var k = t.closest('[data-kpi]');
    if (k && !k.classList.contains('static')) {
      var id = k.getAttribute('data-kpi');
      if (id === '!off') { v.status = v.status === 'suspended' ? '' : 'suspended'; v.role = ''; }
      else { v.role = id; v.status = ''; }
      paint(el, v); syncSeg(el, v); return;
    }
    var st = t.closest('[data-status]'); if (st) { v.status = st.getAttribute('data-status'); paint(el, v); syncSeg(el, v); return; }
    var md = t.closest('[data-mode]'); if (md) { v.mode = md.getAttribute('data-mode'); VIEWS.users.mode = v.mode; VIEWS.customers.mode = v.mode; try { localStorage.setItem('ppl-mode', v.mode); } catch (x) { /* ignore */ } paint(el, v); syncSeg(el, v); return; }
    var cl = t.closest('[data-clr]'); if (cl) { if (cl.getAttribute('data-clr') === 'role') v.role = ''; else v.status = ''; paint(el, v); syncSeg(el, v); return; }
    if (t.closest('[data-clearq]')) { v.q = ''; el.querySelector('[data-q]').value = ''; paint(el, v); syncSeg(el, v); return; }
    var act = t.closest('[data-act]');
    if (act) {
      var a = act.getAttribute('data-act');
      if (a === 'refresh') load(v, true);
      else if (a === 'new' && typeof window.openModal === 'function') window.openModal('new-user');
      else if (a === 'dupes') openDupes();
      else if (a === 'export') exportCsv(v);
      return;
    }
    var row = t.closest('[data-id]'); if (row) openDrawer(row.getAttribute('data-id'), v);
  });
  panel.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.classList && e.target.classList.contains('ppl-card')) { e.preventDefault(); openDrawer(e.target.getAttribute('data-id'), v); }
  });
  panel.addEventListener('input', function (e) {
    if (!e.target.matches || !e.target.matches('[data-q]')) return;
    var el = rootOf(v); v.q = e.target.value.trim();
    el.querySelector('[data-clearq]').style.display = v.q ? '' : 'none';
    clearTimeout(searchTimer); searchTimer = setTimeout(function () { paint(el, v); }, 120);
  });
  panel.addEventListener('change', function (e) {
    if (!e.target.matches || !e.target.matches('[data-sort]')) return;
    v.sort = e.target.value; load(v, true);
  });
}
function syncSeg(el, v) {
  Array.prototype.forEach.call(el.querySelectorAll('[data-status]'), function (b) { b.classList.toggle('on', b.getAttribute('data-status') === v.status); });
  Array.prototype.forEach.call(el.querySelectorAll('[data-mode]'), function (b) { b.classList.toggle('on', b.getAttribute('data-mode') === v.mode); });
}
function exportCsv(v) {
  var rows = filtered(v);
  if (!rows.length) { toast('Nothing to export', true); return; }
  var cols = v.key === 'customers' ? ['id', 'name', 'phone', 'orders_total', 'delivered', 'cancelled', 'spent', 'last_order_at', 'created_at', 'suspended']
                                   : ['id', 'name', 'phone', 'role', 'orders_total', 'rides_total', 'created_at', 'suspended'];
  var q = function (x) { x = x == null ? '' : String(x); if (/^[=+\-@]/.test(x)) x = "'" + x; return '"' + x.replace(/"/g, '""') + '"'; };   // a leading = + - @ would run as a formula in Excel
  var csv = [cols.join(',')].concat(rows.map(function (r) { return cols.map(function (c) { return q(c === 'suspended' ? (r[c] ? 'yes' : 'no') : r[c]); }).join(','); })).join('\n');
  var a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
  a.download = (v.key === 'customers' ? 'customers_' : 'people_') + new Date().toISOString().slice(0, 10) + '.csv';
  a.click();
  toast('Exported ' + rows.length + ' rows');
}

/* ---------- modal ---------- */
function ask(o) {
  return new Promise(function (resolve) {
    var ov = document.createElement('div'); ov.className = 'ppl-ov in'; ov.style.zIndex = '9099';
    var md = document.createElement('div'); md.className = 'ppl-md'; md.setAttribute('role', 'dialog'); md.setAttribute('aria-modal', 'true');
    md.innerHTML = '<h3>' + esc(o.title) + '</h3><p>' + esc(o.body) + '</p>' + (o.reason ? '<textarea data-reason maxlength="200" placeholder="Reason (optional, kept in the log)"></textarea>' : '') +
      '<div class="row"><button type="button" class="ppl-btn" data-no>Cancel</button><button type="button" class="ppl-btn ' + (o.danger ? 'danger solid' : 'gold') + '" data-yes>' + esc(o.yes || 'Confirm') + '</button></div>';
    function close(ok) { var r = md.querySelector('[data-reason]'); var val = r ? r.value : ''; document.body.removeChild(ov); document.body.removeChild(md); document.removeEventListener('keydown', onKey, true); resolve(ok ? { ok: true, reason: val } : { ok: false }); }
    function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); close(false); } }
    ov.addEventListener('click', function () { close(false); });
    md.querySelector('[data-no]').addEventListener('click', function () { close(false); });
    md.querySelector('[data-yes]').addEventListener('click', function () { close(true); });
    document.addEventListener('keydown', onKey, true);
    document.body.appendChild(ov); document.body.appendChild(md);
    (md.querySelector('[data-reason]') || md.querySelector('[data-yes]')).focus();
  });
}

/* ---------- drawer ---------- */
function closeDrawer() {
  var ov = document.getElementById('ppl-ov'), dr = document.getElementById('ppl-dr');
  OPEN_ID = null;
  if (dr) dr.classList.remove('in'); if (ov) ov.classList.remove('in');
  setTimeout(function () { if (ov && ov.parentNode) ov.parentNode.removeChild(ov); if (dr && dr.parentNode) dr.parentNode.removeChild(dr); }, 280);
  document.removeEventListener('keydown', drawerKey);
}
function drawerKey(e) { if (e.key === 'Escape' && !document.querySelector('.ppl-md')) closeDrawer(); }
function openDrawer(id, v) {
  OPEN_ID = String(id);
  var ov = document.getElementById('ppl-ov'), dr = document.getElementById('ppl-dr');
  if (!dr) {
    ov = document.createElement('div'); ov.id = 'ppl-ov'; ov.className = 'ppl-ov';
    dr = document.createElement('aside'); dr.id = 'ppl-dr'; dr.className = 'ppl-dr'; dr.setAttribute('role', 'dialog'); dr.setAttribute('aria-modal', 'true'); dr.setAttribute('aria-label', 'Account details');
    document.body.appendChild(ov); document.body.appendChild(dr);
    ov.addEventListener('click', closeDrawer);
    dr.addEventListener('click', function (e) {
      var c = e.target.closest('[data-copy]'); if (c) { copy(c.getAttribute('data-copy')); return; }
      if (e.target.closest('[data-dupes]')) { e.preventDefault(); openDupes(); return; }
      var b = e.target.closest('[data-ctl]'); if (b && CUR) control(b.getAttribute('data-ctl'), CUR.p, CUR.v);
    });
    document.addEventListener('keydown', drawerKey);
    requestAnimationFrame(function () { ov.classList.add('in'); dr.classList.add('in'); });
  }
  dr.innerHTML = '<div class="ppl-dh"><button type="button" class="x" data-x aria-label="Close">✕</button><div class="ppl-skel" style="height:64px;width:64px;border-radius:20px"></div></div><div class="ppl-db"><div class="ppl-skel" style="height:90px"></div><div class="ppl-skel" style="height:120px;margin-top:12px"></div></div>';
  dr.querySelector('[data-x]').addEventListener('click', closeDrawer);
  api('admin_person_get', { id: Number(id) }).then(function (res) {
    if (OPEN_ID !== String(id)) return;
    if (!res || res.success === false || !res.data) { dr.querySelector('.ppl-db').innerHTML = '<div class="ppl-err">⚠️ ' + esc(failText(res, 'Could not open this account.')) + '</div>'; return; }
    renderDrawer(dr, res.data, v);
  }).catch(function () { dr.querySelector('.ppl-db').innerHTML = '<div class="ppl-err">⚠️ Could not reach the server. Try again.</div>'; });
}
function kv(rows) { return '<div class="ppl-kv">' + rows.map(function (r) { return '<div><b>' + r[1] + '</b><span>' + esc(r[0]) + '</span></div>'; }).join('') + '</div>'; }
function renderDrawer(dr, d, v) {
  var p = d.person, r = role(p.role), det = p.detail || {};
  var phone = String(p.phone || '').replace(/[^0-9+]/g, '');
  var roleStats = [];
  if (p.role === 'courier') { roleStats = [['Courier code', esc(det.courier_code || '—')], ['Vehicle', esc(det.vehicle_type || '—')], ['Deliveries', num(det.total_deliveries)], ['Rating', det.rating ? '⭐ ' + Number(det.rating).toFixed(2) : '—']]; }
  else if (p.role === 'driver') { roleStats = [['Plate', esc(det.vehicle_plate || '—')], ['Vehicle', esc([det.vehicle_color, det.vehicle_make].filter(Boolean).join(' ') || '—')], ['Rides driven', num(det.total_rides)], ['Rating', det.rating ? '⭐ ' + Number(det.rating).toFixed(2) : '—']]; }
  else if (p.role === 'service_giver') { roleStats = [['Business', esc(det.business_name || '—')], ['Type', esc(det.service_type || '—')], ['Completed', num(det.completed_deliveries)], ['Status', esc(det.status || '—')]]; }
  else if (p.role === 'admin') { roleStats = [['Staff role', esc(det.staff_role || 'Owner / full access')], ['Two-step sign-in', p.two_step ? '✅ On' : 'Off']]; }
  var h = '<div class="ppl-dh"><button type="button" class="x" data-x aria-label="Close">✕</button>' + avatar(p, 'lg') +
    '<h3>' + esc(p.name || 'Unnamed') + (p.is_you ? ' <small style="opacity:.7;font-weight:500">(you)</small>' : '') + '</h3>' +
    '<div class="sub">' + pill(p) + ' &nbsp; ' + stateTag(p) + ' &nbsp; <span title="' + esc(fullDate(p.created_at)) + '">Joined ' + ago(p.created_at) + ' · #' + p.id + '</span></div></div><div class="ppl-db">';
  h += '<div class="ppl-acts"><a href="tel:' + esc(phone) + '"><i>📞</i>Call</a><a href="sms:' + esc(phone) + '"><i>💬</i>Message</a><button type="button" data-copy="' + esc(p.phone) + '"><i>📋</i>Copy phone</button></div>';
  h += '<div class="ppl-sec"><div class="ppl-ph" style="margin:0"><span>' + esc(p.phone) + '</span><span style="opacity:.6">' + esc(r.label) + '</span></div></div>';
  if (p.same_phone_accounts > 0) h += '<div class="ppl-warn"><span>⚠️</span><span>' + p.same_phone_accounts + ' other account' + (p.same_phone_accounts > 1 ? 's use' : ' uses') + ' this phone number. <a href="#" data-dupes style="color:inherit;font-weight:700">Review duplicates</a></span></div>';
  if (roleStats.length) h += '<div class="ppl-sec"><h4>' + esc(r.label) + ' details</h4>' + kv(roleStats) + '</div>';
  if (p.orders_total || p.role === 'customer') h += '<div class="ppl-sec"><h4>Orders</h4>' + kv([['Total orders', num(p.orders_total)], ['Delivered', num(p.delivered)], ['Cancelled', num(p.cancelled)], ['Spent (delivered)', money(p.spent)]]) + '</div>';
  if (d.orders && d.orders.length) h += '<div class="ppl-sec"><h4>Recent orders</h4><div class="ppl-list">' + d.orders.map(function (o) { return '<div><span><b>' + esc(o.order_code) + '</b><small>' + esc(ago(o.placed_at)) + ' · ' + esc(o.payment_method || '') + '</small></span><span style="text-align:right"><b>' + money(o.fare_total) + '</b><br><span class="ppl-tag">' + esc(String(o.status || '').replace(/_/g, ' ')) + '</span></span></div>'; }).join('') + '</div></div>';
  if (d.rides && d.rides.length) h += '<div class="ppl-sec"><h4>Recent rides</h4><div class="ppl-list">' + d.rides.map(function (o) { return '<div><span><b>Ride #' + o.id + '</b><small>' + esc(ago(o.created_at)) + ' · as ' + esc(o.as) + '</small></span><span style="text-align:right"><b>' + money(o.fare) + '</b><br><span class="ppl-tag">' + esc(String(o.status || '').replace(/_/g, ' ')) + '</span></span></div>'; }).join('') + '</div></div>';
  /* controls */
  var ownerOnly = p.role === 'admin' && !(CAPS === null || CAPS.indexOf('*') >= 0);
  h += '<div class="ppl-sec"><h4>Account controls</h4>';
  if (p.is_you) h += '<div class="ppl-warn"><span>ℹ️</span><span>This is your own account. It can’t be disabled or signed out from here.</span></div>';
  else if (!can('users.manage')) h += '<div class="ppl-warn"><span>🔒</span><span>Your role can view accounts but not change them.</span></div>';
  else if (ownerOnly) h += '<div class="ppl-warn"><span>🔒</span><span>Only an owner admin can change another staff account.</span></div>';
  else {
    h += '<div class="ppl-ctl">' +
      (p.suspended
        ? '<button type="button" class="ppl-btn" data-ctl="restore">✅ <span>Restore account<small>They can sign in and use the apps again.</small></span></button>'
        : '<button type="button" class="ppl-btn danger" data-ctl="disable">⛔ <span>Disable account<small>Signs them out everywhere and blocks sign-in, ordering and rides.</small></span></button>') +
      '<button type="button" class="ppl-btn" data-ctl="logout">🚪 <span>Sign out of all devices<small>Their current sessions stop working. They can sign in again.</small></span></button>' +
      (p.two_step ? '<button type="button" class="ppl-btn" data-ctl="twostep">🔑 <span>Reset two-step sign-in<small>For someone who lost their phone and backup codes.</small></span></button>' : '') + '</div>';
  }
  h += '</div></div>';
  dr.innerHTML = h;
  CUR = { p: p, v: v };
  dr.querySelector('[data-x]').addEventListener('click', closeDrawer);
}
function control(kind, p, v) {
  var name = p.name || ('#' + p.id);
  var done = function (msg) { toast(msg); load(v, true).then(function () { if (OPEN_ID === String(p.id)) openDrawer(p.id, v); }); };
  var oops = function (res) { toast(failText(res, 'That did not work. Try again.'), true); };
  if (kind === 'disable') {
    ask({ title: 'Disable ' + name + '?', body: 'They are signed out of every device now and cannot sign in, order or take rides until you restore the account. Nothing is deleted.', danger: true, yes: 'Disable account', reason: true }).then(function (a) {
      if (a.ok) api('admin_person_set_active', { id: p.id, active: 0, reason: a.reason }).then(function (r) { (r && r.success !== false) ? done(name + ' is disabled') : oops(r); });
    });
  } else if (kind === 'restore') {
    ask({ title: 'Restore ' + name + '?', body: 'They will be able to sign in and use the apps again.', yes: 'Restore account' }).then(function (a) {
      if (a.ok) api('admin_person_set_active', { id: p.id, active: 1 }).then(function (r) { (r && r.success !== false) ? done(name + ' is active again') : oops(r); });
    });
  } else if (kind === 'logout') {
    ask({ title: 'Sign ' + name + ' out everywhere?', body: 'Every phone and browser they are signed in on stops working. They can sign in again with their password.', yes: 'Sign out' }).then(function (a) {
      if (a.ok) api('admin_person_force_logout', { id: p.id }).then(function (r) { (r && r.success !== false) ? toast(name + ' was signed out') : oops(r); });
    });
  } else if (kind === 'twostep') {
    ask({ title: 'Reset two-step sign-in for ' + name + '?', body: 'It is switched off for them. They can sign in with the password and turn it on again. Only do this after checking it is really them.', danger: true, yes: 'Reset two-step' }).then(function (a) {
      if (a.ok) api('admin_reset_user_2fa', { phone: p.phone }).then(function (r) { (r && r.success !== false) ? done('Two-step sign-in reset for ' + name) : oops(r); });
    });
  }
}

/* ---------- duplicate phones ---------- */
function openDupes() {
  var ov = document.createElement('div'); ov.className = 'ppl-ov in'; ov.style.zIndex = '9099';
  var md = document.createElement('div'); md.className = 'ppl-md'; md.style.width = 'min(94vw,520px)'; md.style.maxHeight = '80vh'; md.style.overflowY = 'auto';
  md.innerHTML = '<h3>📞 Duplicate phone numbers</h3><p>Accounts that share one number written two ways (0924… and +251924…). Nothing here changes automatically. Tap one to open it.</p><div data-body>Checking…</div><div class="row"><button type="button" class="ppl-btn" data-x>Close</button></div>';
  function close() { if (ov.parentNode) { document.body.removeChild(ov); document.body.removeChild(md); } }
  ov.addEventListener('click', close); md.querySelector('[data-x]').addEventListener('click', close);
  document.body.appendChild(ov); document.body.appendChild(md);
  api('admin_duplicate_phones', {}).then(function (res) {
    var b = md.querySelector('[data-body]');
    if (!res || res.success === false) { b.textContent = failText(res, 'Could not load the report.'); return; }
    var groups = (res.data && res.data.groups) || [];
    if (!groups.length) { b.textContent = '✅ No phone number is shared by more than one account.'; return; }
    b.innerHTML = groups.map(function (g) {
      return '<div class="ppl-dup">' + g.accounts.map(function (a) { return '<div data-open="' + a.id + '"><span><b>' + esc(a.name || 'Unnamed') + '</b> · ' + esc(a.role) + '</span><span class="ppl-mono">' + esc(a.phone) + ' · #' + a.id + '</span></div>'; }).join('') + '</div>';
    }).join('');
    b.addEventListener('click', function (e) { var r = e.target.closest('[data-open]'); if (r) { close(); openDrawer(r.getAttribute('data-open'), VIEWS.users); } });
  }).catch(function () { md.querySelector('[data-body]').textContent = 'Could not reach the server.'; });
}

/* ---------- install ---------- */
function loadCaps() {
  if (CAPS !== null) return;
  api('admin_get_my_capabilities', {}).then(function (res) {
    var c = res && res.data && res.data.capabilities;
    if (c && c.length !== undefined) CAPS = c;
  }).catch(function () { /* the server still enforces every action */ });
}
function install() {
  css();
  loadCaps();
  /* The Customers page: a nav item, a panel, and the page hook. */
  if (!document.querySelector('[data-page="customers"]')) {
    var after = document.querySelector('.nav-item[data-page="users"]') || document.querySelector('.nav-item[data-page="orders"]');
    if (after) {
      var nav = document.createElement('div');
      nav.className = 'nav-item'; nav.setAttribute('data-page', 'customers');
      nav.setAttribute('onclick', "switchPage('customers',this)");
      nav.innerHTML = '<span class="nav-icon">🛍️</span><span>Customers</span>';
      after.parentNode.insertBefore(nav, after.nextSibling);
    }
  }
  var host = document.getElementById('panel-users') || document.getElementById('panel-orders');
  if (host && !document.getElementById('panel-customers')) {
    var cp = document.createElement('div'); cp.className = 'panel'; cp.id = 'panel-customers'; cp.style.display = 'none';
    host.parentNode.appendChild(cp);
  }
  var up = document.getElementById('panel-users'), cu = document.getElementById('panel-customers');
  if (up) wire(up, VIEWS.users);
  if (cu) wire(cu, VIEWS.customers);

  /* Old entry points now open the new screen. */
  window.loadUsers = function () { return load(VIEWS.users, VIEWS.users.loaded); };
  window.renderUserTable = function () { var el = rootOf(VIEWS.users); if (el) paint(el, VIEWS.users); };
  window.filterUserTable = window.renderUserTable;
  window.loadDuplicatePhones = openDupes;
  var orig = window.switchPage;
  if (typeof orig === 'function') {
    window.switchPage = function (page) {
      var r = orig.apply(this, arguments);
      if (page === 'customers') {
        var t = document.getElementById('page-title'); if (t) t.textContent = 'Customers';
        var ab = document.getElementById('action-buttons'); if (ab) ab.innerHTML = '';
        load(VIEWS.customers, VIEWS.customers.loaded);
      }
      return r;
    };
  }
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
