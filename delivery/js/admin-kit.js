/* ETEL Admin - shared kit for the premium Orders, Couriers, Service givers and Package prices screens.
   Gives them one look (gold on carbon, light and dark) and the small pieces they all need: escaping, formatting,
   the API call, toasts, KPI tiles, a side drawer and a confirm dialog that can ask for a reason.
   Loaded before admin-orders-pro.js, admin-couriers-pro.js, admin-sg-pro.js and admin-packages.js.
   Every server value must go through AdminKit.esc before it is put into HTML. */
(function () {
'use strict';
if (window.AdminKit) return;

function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
function num(n) { return (Number(n) || 0).toLocaleString(); }
function money(n) { return 'ETB ' + (Number(n) || 0).toLocaleString(undefined, { maximumFractionDigits: 0 }); }
function parseDate(s) { if (!s) return null; var d = new Date(String(s).replace(' ', 'T')); return isNaN(d.getTime()) ? null : d; }
function ago(s) {
  var d = parseDate(s); if (!d) return '—';
  var sec = Math.max(0, (Date.now() - d.getTime()) / 1000);
  if (sec < 90) return 'just now';
  if (sec < 3600) return Math.round(sec / 60) + ' min ago';
  if (sec < 86400) return Math.round(sec / 3600) + ' h ago';
  if (sec < 86400 * 30) return Math.round(sec / 86400) + ' d ago';
  return Math.round(sec / 86400 / 30) + ' mo ago';
}
function minutes(m) {
  m = Number(m) || 0;
  if (m < 60) return m + ' min';
  if (m < 1440) return Math.floor(m / 60) + ' h ' + (m % 60) + ' min';
  return Math.floor(m / 1440) + ' d ' + Math.floor((m % 1440) / 60) + ' h';
}
function fullDate(s) { var d = parseDate(s); return d ? d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'; }
function initials(name) {
  var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '?';
  var a = Array.from(parts[0])[0] || '?', b = parts.length > 1 ? (Array.from(parts[1])[0] || '') : '';
  return (a + b).toUpperCase();
}
function token() { return typeof Auth !== 'undefined' && Auth.getToken ? Auth.getToken() : ''; }
function api(action, body) {
  var url = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
  return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() }, body: JSON.stringify(Object.assign({ action: action }, body || {})) })
    .then(function (r) { return r.json().catch(function () { return { success: false, message: 'The server sent an unreadable answer.' }; }); })
    .catch(function () { return { success: false, message: 'Could not reach the server. Try again.' }; });
}
function okRes(res) { return !!res && res.success !== false && !res.error; }
function data(res) { return (res && res.data) || res || {}; }
function failText(res, dflt) { return (res && (res.message || res.error)) || dflt || 'Something went wrong.'; }

function toast(msg, bad) {
  var t = document.createElement('div');
  t.className = 'ak-toast' + (bad ? ' bad' : '');
  t.setAttribute('role', 'status');
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(function () { t.classList.add('out'); }, 2800);
  setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 3300);
}

function css() {
  if (document.getElementById('ak-css')) return;
  var s = document.createElement('style'); s.id = 'ak-css';
  s.textContent = [
    '.ak{--ink:#1A1A2E;--mute:#6b6b82;--line:#ece5d2;--card:#fff;--soft:#faf6ea;--gold:#D4AF37;--gold2:#8B7230;--red:#d6364a;--green:#1fa463;--amber:#d98e04;--blue:#2f6fd0;--shadow:0 6px 22px rgba(60,45,10,.09);color:var(--ink);font-family:inherit}',
    'body.dark-theme .ak{--ink:#f4efe0;--mute:#a9a38f;--line:#34324a;--card:#1b1b2a;--soft:#232337;--shadow:0 8px 26px rgba(0,0,0,.45)}',
    '.ak *{box-sizing:border-box}',
    '.ak-hero{position:relative;overflow:hidden;border-radius:20px;padding:18px 20px;margin-bottom:14px;color:#fff;background:linear-gradient(120deg,#1a1630 0%,#2b2147 55%,#6d5413 130%);box-shadow:var(--shadow)}',
    '.ak-hero::after{content:"";position:absolute;right:-40px;top:-60px;width:220px;height:220px;border-radius:50%;background:radial-gradient(circle,rgba(212,175,55,.45),rgba(212,175,55,0) 70%)}',
    '.ak-hero h2{margin:0;font-size:19px;font-weight:800;position:relative}',
    '.ak-hero p{margin:4px 0 0;font-size:12.5px;opacity:.78;position:relative;max-width:560px}',
    '.ak-hero-row{display:flex;justify-content:space-between;gap:12px;align-items:flex-end;flex-wrap:wrap;position:relative}',
    '.ak-hero-big{display:flex;gap:20px;margin-top:12px;position:relative;flex-wrap:wrap}',
    '.ak-hero-big b{display:block;font-size:26px;font-weight:800;line-height:1.1;color:#F2CC6B}',
    '.ak-hero-big span{font-size:11px;opacity:.75;text-transform:uppercase;letter-spacing:.06em}',
    '.ak-kpis{display:flex;gap:10px;overflow-x:auto;padding:2px 2px 8px;margin-bottom:6px;scrollbar-width:thin}',
    '.ak-kpi{flex:0 0 auto;min-width:108px;text-align:left;cursor:pointer;border:1.5px solid var(--line);background:var(--card);border-radius:16px;padding:11px 14px;color:var(--ink);font:inherit;box-shadow:var(--shadow);transition:transform .15s,border-color .15s}',
    '.ak-kpi:hover{transform:translateY(-2px)}',
    '.ak-kpi.on{border-color:var(--gold);background:linear-gradient(160deg,rgba(212,175,55,.16),var(--card))}',
    '.ak-kpi.static{cursor:default}.ak-kpi.static:hover{transform:none}',
    '.ak-kpi i{font-style:normal;font-size:17px;display:block}',
    '.ak-kpi b{display:block;font-size:22px;font-weight:800;margin-top:2px;line-height:1.1}',
    '.ak-kpi span{display:block;font-size:11px;color:var(--mute);margin-top:2px;white-space:nowrap}',
    '.ak-kpi.warn b{color:var(--red)}',
    '.ak-tools{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:8px 0 10px}',
    '.ak-search{position:relative;flex:1 1 220px;min-width:180px}',
    '.ak-search input{width:100%;padding:11px 14px 11px 38px;border-radius:14px;border:1.5px solid var(--line);background:var(--card);color:var(--ink);font-size:14px;outline:none}',
    '.ak-search input:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(212,175,55,.22)}',
    '.ak-search u{position:absolute;left:13px;top:50%;transform:translateY(-50%);text-decoration:none;opacity:.55;font-size:14px;pointer-events:none}',
    '.ak-seg{display:inline-flex;background:var(--soft);border:1.5px solid var(--line);border-radius:12px;padding:3px;max-width:100%;overflow-x:auto}',
    '.ak-seg button{border:0;background:transparent;color:var(--mute);font:inherit;font-size:12.5px;font-weight:600;padding:7px 12px;border-radius:9px;cursor:pointer;white-space:nowrap}',
    '.ak-seg button.on{background:var(--card);color:var(--ink);box-shadow:0 2px 8px rgba(0,0,0,.12)}',
    '.ak-sel,.ak-btn,.ak-in{border:1.5px solid var(--line);background:var(--card);color:var(--ink);font:inherit;font-size:12.5px;font-weight:600;border-radius:12px;padding:9px 12px}',
    '.ak-btn{cursor:pointer}.ak-sel{cursor:pointer}',
    '.ak-btn:hover,.ak-sel:hover{border-color:var(--gold)}',
    '.ak label>.ak-in{display:block;margin-top:4px}',
    '.ak-in{font-weight:500;width:100%;outline:none}.ak-in:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(212,175,55,.22)}',
    '.ak-btn.gold{background:linear-gradient(135deg,var(--gold),var(--gold2));color:#1c1500;border-color:transparent}',
    '.ak-btn.danger{color:var(--red);border-color:rgba(214,54,74,.45)}',
    '.ak-btn.danger.solid{background:var(--red);color:#fff;border-color:transparent}',
    '.ak-btn.good{color:var(--green);border-color:rgba(31,164,99,.45)}',
    '.ak-btn[disabled]{opacity:.5;cursor:not-allowed}',
    '.ak-count{font-size:12px;color:var(--mute);margin:2px 2px 10px}',
    '.ak-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(285px,1fr));gap:12px}',
    '.ak-card{position:relative;background:var(--card);border:1.5px solid var(--line);border-radius:18px;padding:14px;cursor:pointer;box-shadow:var(--shadow);transition:transform .15s,border-color .15s;outline:none}',
    '.ak-card:hover,.ak-card:focus-visible{transform:translateY(-2px);border-color:var(--gold)}',
    '.ak-card.alert{border-color:rgba(214,54,74,.6)}',
    '.ak-card.off{border-style:dashed;border-color:rgba(214,54,74,.5)}',
    '.ak-top{display:flex;gap:11px;align-items:center}',
    '.ak-av{flex:none;width:46px;height:46px;border-radius:15px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:16px;background:linear-gradient(135deg,var(--a,#a875d6),var(--b,#7b3fb0));box-shadow:0 6px 14px -4px rgba(0,0,0,.35),inset 0 1px 0 rgba(255,255,255,.35)}',
    '.ak-av.lg{width:64px;height:64px;border-radius:20px;font-size:23px}',
    '.ak-nm{min-width:0;flex:1}.ak-nm b{display:block;font-size:14.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.ak-nm small{display:block;font-size:11.5px;color:var(--mute);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.ak-pill{display:inline-flex;gap:5px;align-items:center;font-size:10.5px;font-weight:800;padding:3px 9px;border-radius:20px;white-space:nowrap;background:var(--soft);color:var(--mute)}',
    '.ak-pill::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}',
    '.ak-pill.green{color:var(--green);background:rgba(31,164,99,.12)}.ak-pill.amber{color:#b57b00;background:rgba(240,180,41,.18)}',
    '.ak-pill.red{color:var(--red);background:rgba(214,54,74,.12)}.ak-pill.blue{color:var(--blue);background:rgba(47,111,208,.12)}',
    '.ak-pill.violet{color:#7b3fb0;background:rgba(123,63,176,.12)}.ak-pill.grey{color:var(--mute)}',
    '.ak-row{display:flex;justify-content:space-between;gap:8px;align-items:center;margin-top:10px;font-size:12px;color:var(--mute)}',
    '.ak-row b{color:var(--ink)}',
    '.ak-chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}',
    '.ak-chip{font-size:11.5px;border-radius:9px;padding:3px 9px;background:var(--soft);border:1px solid var(--line)}',
    '.ak-chip.warn{color:var(--red);border-color:rgba(214,54,74,.4);background:rgba(214,54,74,.08)}',
    '.ak-route{margin-top:10px;display:flex;gap:8px;align-items:flex-start;font-size:12.5px}',
    '.ak-route i{font-style:normal;flex:none}',
    '.ak-tblw{overflow-x:auto;border:1.5px solid var(--line);border-radius:16px;background:var(--card);box-shadow:var(--shadow)}',
    '.ak-tbl{width:100%;border-collapse:collapse;font-size:13px}',
    '.ak-tbl th{position:sticky;top:0;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--mute);padding:10px 12px;background:var(--soft);white-space:nowrap}',
    '.ak-tbl td{padding:10px 12px;border-top:1px solid var(--line);white-space:nowrap}',
    '.ak-tbl tr[data-open]{cursor:pointer}.ak-tbl tr[data-open]:hover td{background:var(--soft)}',
    '.ak-empty{text-align:center;padding:46px 16px;color:var(--mute);border:1.5px dashed var(--line);border-radius:18px}',
    '.ak-empty b{display:block;font-size:34px;margin-bottom:6px}',
    '.ak-skel{height:130px;border-radius:18px;background:linear-gradient(100deg,var(--soft) 30%,var(--card) 50%,var(--soft) 70%);background-size:300% 100%;animation:ak-sh 1.3s infinite}',
    '@keyframes ak-sh{0%{background-position:100% 0}100%{background-position:-100% 0}}',
    '.ak-ov{position:fixed;inset:0;background:rgba(10,8,25,.55);backdrop-filter:blur(3px);z-index:9998;opacity:0;transition:opacity .2s}',
    '.ak-ov.in{opacity:1}',
    '.ak-dr{position:fixed;top:0;right:0;bottom:0;width:min(520px,100vw);background:var(--card);z-index:9999;box-shadow:-12px 0 40px rgba(0,0,0,.35);transform:translateX(100%);transition:transform .25s;display:flex;flex-direction:column;overflow:hidden}',
    '.ak-dr.in{transform:none}',
    '.ak-dr-head{display:flex;gap:12px;align-items:center;padding:16px 18px;border-bottom:1px solid var(--line);background:linear-gradient(120deg,rgba(212,175,55,.14),transparent)}',
    '.ak-dr-head .ak-nm b{font-size:16px}',
    '.ak-x{border:0;background:var(--soft);color:var(--ink);width:34px;height:34px;border-radius:50%;cursor:pointer;font-size:15px;flex:none}',
    '.ak-dr-body{flex:1;overflow-y:auto;padding:16px 18px 24px}',
    '.ak-sec{margin-top:18px}.ak-sec:first-child{margin-top:0}',
    '.ak-sec h4{margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:.07em;color:var(--mute)}',
    '.ak-kv{display:grid;grid-template-columns:120px 1fr;gap:6px 10px;font-size:13px}',
    '.ak-kv dt{color:var(--mute)}.ak-kv dd{margin:0;word-break:break-word}',
    '.ak-acts{display:flex;gap:8px;flex-wrap:wrap}',
    '.ak-stat3{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}',
    '.ak-stat3 div{background:var(--soft);border:1px solid var(--line);border-radius:12px;padding:9px 10px}',
    '.ak-stat3 b{display:block;font-size:18px}.ak-stat3 span{font-size:11px;color:var(--mute)}',
    '.ak-tl{list-style:none;margin:0;padding:0 0 0 14px;border-left:2px solid var(--line)}',
    '.ak-tl li{position:relative;padding:0 0 12px 12px;font-size:12.5px}',
    '.ak-tl li::before{content:"";position:absolute;left:-20px;top:3px;width:10px;height:10px;border-radius:50%;background:var(--gold);border:2px solid var(--card)}',
    '.ak-tl small{display:block;color:var(--mute)}',
    '.ak-items{width:100%;border-collapse:collapse;font-size:13px}.ak-items td{padding:6px 0;border-bottom:1px solid var(--line)}.ak-items td:last-child{text-align:right}',
    '.ak-modal{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(10,8,25,.6)}',
    '.ak-box{width:min(420px,100%);background:var(--card);color:var(--ink);border-radius:20px;padding:20px;box-shadow:0 20px 60px rgba(0,0,0,.5)}',
    '.ak-box h3{margin:0 0 6px;font-size:17px}.ak-box p{margin:0 0 12px;font-size:13px;color:var(--mute)}',
    '.ak-box .ak-acts{justify-content:flex-end;margin-top:14px}',
    '.ak-toast{position:fixed;left:50%;bottom:26px;transform:translateX(-50%);background:#1A1A2E;color:#fff;padding:11px 18px;border-radius:14px;font-size:13px;z-index:10001;box-shadow:0 10px 30px rgba(0,0,0,.4);transition:opacity .4s}',
    '.ak-toast.bad{background:var(--red,#d6364a)}.ak-toast.out{opacity:0}',
    '.ak-fg{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}',
    '.ak-fl{display:block;font-size:12px;font-weight:700;color:var(--mute)}',
    '.ak-fl .ak-in{margin-top:5px;color:var(--ink)}',
    '.ak-fh{font-size:11px;font-weight:400;color:var(--mute);margin-top:4px}',
    '.ak-opts{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px}',
    '.ak-opt{border:1.5px solid var(--line);background:var(--card);color:var(--ink);border-radius:14px;padding:11px 10px;text-align:left;font:inherit;cursor:pointer}',
    '.ak-opt:hover{border-color:var(--gold)}.ak-opt.on{border-color:var(--gold);background:linear-gradient(160deg,rgba(212,175,55,.18),var(--card))}',
    '.ak-opt i{font-style:normal;font-size:20px;display:block}.ak-opt b{display:block;font-size:13px;margin-top:3px}.ak-opt span{display:block;font-size:11px;color:var(--mute);margin-top:2px}',
    '.ak-sw{display:flex;gap:10px;align-items:center;padding:10px 12px;border:1.5px solid var(--line);border-radius:12px;background:var(--soft);font-size:13px;cursor:pointer}',
    '.ak-sw input{width:auto;margin:0}',
    '.ak-prev{border:1.5px dashed var(--gold);border-radius:14px;padding:12px 14px;background:rgba(212,175,55,.08);font-size:13px}',
    '.ak-prev b{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--mute);margin-bottom:4px}',
    '.ak-dr-foot{display:flex;gap:8px;justify-content:flex-end;align-items:center;padding:12px 18px;border-top:1px solid var(--line);background:var(--card)}',
    '.ak-err{color:var(--red);font-size:12.5px;min-height:18px;margin-right:auto}',
    '.ak-live{display:inline-block;width:8px;height:8px;border-radius:50%;background:#1fa463;margin-right:6px;animation:ak-pulse 1.6s infinite}',
    '@keyframes ak-pulse{0%{box-shadow:0 0 0 0 rgba(31,164,99,.6)}100%{box-shadow:0 0 0 9px rgba(31,164,99,0)}}',
    '@media(max-width:600px){.ak-hero{padding:15px}.ak-kv{grid-template-columns:100px 1fr}.ak-grid{grid-template-columns:1fr}}',
    '@media(prefers-reduced-motion:reduce){.ak *{animation:none!important;transition:none!important}.ak-dr,.ak-ov{transition:none}}'
  ].join('\n');
  document.head.appendChild(s);
}

/* ---------- building blocks (all return HTML strings; callers escape their own values) ---------- */
function pill(text, tone) { return '<span class="ak-pill ' + esc(tone || '') + '">' + esc(text) + '</span>'; }
function hero(title, sub, bigs) {
  var h = '<div class="ak-hero"><div class="ak-hero-row"><div><h2>' + esc(title) + '</h2><p>' + esc(sub) + '</p></div></div>';
  if (bigs && bigs.length) {
    h += '<div class="ak-hero-big">' + bigs.map(function (b) { return '<div><b>' + esc(b[1]) + '</b><span>' + esc(b[0]) + '</span></div>'; }).join('') + '</div>';
  }
  return h + '</div>';
}
/* items: [{key, icon, label, value, warn}] ; activeKey highlights one; static tiles are not filters */
function kpis(items, activeKey, isStatic) {
  return '<div class="ak-kpis">' + items.map(function (k) {
    return '<button type="button" class="ak-kpi' + (k.key === activeKey ? ' on' : '') + (k.warn && Number(k.value) > 0 ? ' warn' : '') + (isStatic ? ' static' : '') + '" data-kpi="' + esc(k.key) + '">' +
      '<i>' + esc(k.icon) + '</i><b>' + esc(k.value) + '</b><span>' + esc(k.label) + '</span></button>';
  }).join('') + '</div>';
}
function seg(name, options, cur) {
  return '<div class="ak-seg" role="group">' + options.map(function (o) {
    return '<button type="button" data-seg="' + esc(name) + '" data-val="' + esc(o[0]) + '" class="' + (String(cur) === String(o[0]) ? 'on' : '') + '">' + esc(o[1]) + '</button>';
  }).join('') + '</div>';
}
function skeleton(n) { var h = '<div class="ak-grid">'; for (var i = 0; i < (n || 6); i++) h += '<div class="ak-skel"></div>'; return h + '</div>'; }
function empty(icon, title, sub) { return '<div class="ak-empty"><b>' + esc(icon) + '</b><div style="font-weight:700;color:var(--ink)">' + esc(title) + '</div><div style="font-size:12.5px;margin-top:4px">' + esc(sub || '') + '</div></div>'; }
function kv(rows) {
  return '<dl class="ak-kv">' + rows.filter(function (r) { return r && r[1] !== '' && r[1] != null; }).map(function (r) { return '<dt>' + esc(r[0]) + '</dt><dd>' + (r[2] ? r[1] : esc(r[1])) + '</dd>'; }).join('') + '</dl>';
}

/* ---------- drawer ---------- */
var DR = null;
function closeDrawer() {
  if (!DR) return;
  var d = DR; DR = null;
  d.dr.classList.remove('in'); d.ov.classList.remove('in');
  document.removeEventListener('keydown', d.key);
  setTimeout(function () { if (d.dr.parentNode) d.dr.parentNode.removeChild(d.dr); if (d.ov.parentNode) d.ov.parentNode.removeChild(d.ov); }, 260);
  if (d.prev && d.prev.focus) { try { d.prev.focus(); } catch (e) { /* element gone */ } }
}
/* openDrawer(headHtml, bodyHtml) -> the drawer element; click [data-close] or the dimmed area or press Esc to close */
function openDrawer(headHtml, bodyHtml, footHtml) {
  css();
  if (DR) closeDrawer();
  var ov = document.createElement('div'); ov.className = 'ak-ov';
  var dr = document.createElement('aside'); dr.className = 'ak ak-dr'; dr.setAttribute('role', 'dialog'); dr.setAttribute('aria-modal', 'true');
  dr.innerHTML = '<div class="ak-dr-head">' + headHtml + '<button type="button" class="ak-x" data-close aria-label="Close">✕</button></div><div class="ak-dr-body">' + bodyHtml + '</div>' + (footHtml ? '<div class="ak-dr-foot">' + footHtml + '</div>' : '');
  document.body.appendChild(ov); document.body.appendChild(dr);
  var key = function (e) { if (e.key === 'Escape') closeDrawer(); };
  document.addEventListener('keydown', key);
  ov.addEventListener('click', closeDrawer);
  dr.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('[data-close]')) closeDrawer(); });
  DR = { dr: dr, ov: ov, key: key, prev: document.activeElement };
  requestAnimationFrame(function () { ov.classList.add('in'); dr.classList.add('in'); var x = dr.querySelector('.ak-x'); if (x) x.focus(); });
  return dr;
}
function drawerBody() { return DR ? DR.dr.querySelector('.ak-dr-body') : null; }
function drawerEl() { return DR ? DR.dr : null; }

/* ---------- confirm dialog; opts: {title, text, label, danger, reason:'Why?' (asks for text), required, choices:[[v,l]]} ->
   Promise resolving {ok:true, reason, choice} or null when cancelled ---------- */
function confirmBox(opts) {
  css();
  return new Promise(function (resolve) {
    var m = document.createElement('div'); m.className = 'ak ak-modal'; m.setAttribute('role', 'alertdialog'); m.setAttribute('aria-modal', 'true');
    var h = '<div class="ak-box"><h3>' + esc(opts.title) + '</h3><p>' + esc(opts.text || '') + '</p>';
    if (opts.choices) {
      h += '<select class="ak-in" data-choice>' + opts.choices.map(function (c) { return '<option value="' + esc(c[0]) + '">' + esc(c[1]) + '</option>'; }).join('') + '</select><div style="height:8px"></div>';
    }
    if (opts.reason) h += '<textarea class="ak-in" data-reason rows="3" maxlength="240" placeholder="' + esc(opts.reason) + '"></textarea>';
    h += '<div class="ak-acts"><button type="button" class="ak-btn" data-no>Cancel</button><button type="button" class="ak-btn ' + (opts.danger ? 'danger solid' : 'gold') + '" data-yes>' + esc(opts.label || 'Confirm') + '</button></div></div>';
    m.innerHTML = h;
    document.body.appendChild(m);
    var done = function (v) { document.removeEventListener('keydown', key); if (m.parentNode) m.parentNode.removeChild(m); resolve(v); };
    var key = function (e) { if (e.key === 'Escape') done(null); };
    document.addEventListener('keydown', key);
    m.addEventListener('click', function (e) {
      if (e.target === m || (e.target.closest && e.target.closest('[data-no]'))) return done(null);
      if (e.target.closest && e.target.closest('[data-yes]')) {
        var r = m.querySelector('[data-reason]'), c = m.querySelector('[data-choice]');
        var reason = r ? r.value.trim() : '';
        if (opts.reason && opts.required && !reason) { r.focus(); r.style.borderColor = '#d6364a'; return; }
        done({ ok: true, reason: reason, choice: c ? c.value : '' });
      }
    });
    var first = m.querySelector('[data-reason],[data-choice],[data-yes]'); if (first) first.focus();
  });
}

/* Form pieces for drawers. field(label, inputHtml, hint?) ; opts(name, [[value,icon,title,sub]], current) -> option cards ; sw(id, label, checked) */
function field(label, inputHtml, hint) { return '<label class="ak-fl">' + esc(label) + inputHtml + (hint ? '<div class="ak-fh">' + esc(hint) + '</div>' : '') + '</label>'; }
function opts(name, list, cur) {
  return '<div class="ak-opts" role="radiogroup">' + list.map(function (o) {
    return '<button type="button" role="radio" aria-checked="' + (String(cur) === String(o[0]) ? 'true' : 'false') + '" class="ak-opt' + (String(cur) === String(o[0]) ? ' on' : '') + '" data-opt="' + esc(name) + '" data-val="' + esc(o[0]) + '"><i>' + esc(o[1]) + '</i><b>' + esc(o[2]) + '</b>' + (o[3] ? '<span>' + esc(o[3]) + '</span>' : '') + '</button>';
  }).join('') + '</div>';
}
function sw(id, label, checked) { return '<label class="ak-sw"><input type="checkbox" id="' + esc(id) + '"' + (checked ? ' checked' : '') + '><span>' + esc(label) + '</span></label>'; }
/* Wire option cards inside a container: clicking one marks it on and calls fn(name, value). */
function wireOpts(root, fn) {
  root.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-opt]') : null; if (!b) return;
    var name = b.getAttribute('data-opt');
    Array.prototype.forEach.call(root.querySelectorAll('[data-opt="' + name + '"]'), function (x) { var on = x === b; x.classList.toggle('on', on); x.setAttribute('aria-checked', on ? 'true' : 'false'); });
    fn(name, b.getAttribute('data-val'));
  });
}

/* Debounce for search boxes. */
function debounce(fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms || 250); }; }

/* Install one of the premium pages: makes sure the panel exists (optionally with a nav item), then hands over. */
function ensurePanel(id, navPage, navIcon, navLabel, afterPage) {
  var p = document.getElementById('panel-' + id);
  if (!p) {
    var host = document.getElementById('panel-orders') || document.getElementById('panel-support');
    if (!host) return null;
    p = document.createElement('div'); p.className = 'panel'; p.id = 'panel-' + id; p.style.display = 'none';
    host.parentNode.appendChild(p);
  }
  if (navPage && !document.querySelector('.nav-item[data-page="' + navPage + '"]')) {
    var after = document.querySelector('.nav-item[data-page="' + afterPage + '"]');
    if (after) {
      var nav = document.createElement('div'); nav.className = 'nav-item'; nav.setAttribute('data-page', navPage);
      nav.setAttribute('onclick', "switchPage('" + navPage + "',this)");
      nav.innerHTML = '<span class="nav-icon">' + navIcon + '</span><span>' + navLabel + '</span>';
      after.parentNode.insertBefore(nav, after.nextSibling);
    }
  }
  return p;
}

window.AdminKit = {
  esc: esc, num: num, money: money, ago: ago, minutes: minutes, fullDate: fullDate, initials: initials,
  api: api, okRes: okRes, data: data, failText: failText, toast: toast, css: css,
  pill: pill, hero: hero, kpis: kpis, seg: seg, skeleton: skeleton, empty: empty, kv: kv,
  openDrawer: openDrawer, closeDrawer: closeDrawer, drawerBody: drawerBody, drawerEl: drawerEl,
  confirm: confirmBox, debounce: debounce, ensurePanel: ensurePanel, field: field, opts: opts, sw: sw, wireOpts: wireOpts
};
})();
