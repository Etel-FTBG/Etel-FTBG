/* ETEL Admin - Support desk. ONE inbox for every Etel app (customers write from delivery/help/, or from the Help button inside any Etel app).
   Tabs: Queue, Stats, Canned replies, Settings. Built on AdminKit (same look as Agents, Pricing, Rides). Data: admin_sup_* in api/controllers/SupportAdminController.php.
   Capabilities: support.view (read), support.manage (reply, assign, settings). Telegram is OFF until the owner switches it on in Settings. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K || window.ETELSupport) return;
var esc = K.esc;
var D = { tab: 'queue', caps: [], st: 'active', app: '', prio: '', asg: '', q: '', unread: false, overdue: false, rows: null, counts: null, apps: {}, stats: null, days: 30, canned: null, cfg: null, meta: null, tgTok: false, err: '', open: null, sel: 0 };
var PR = { urgent: 'red', high: 'amber', normal: '', low: '' };
var STL = { open: 'Open', pending: 'Waiting for customer', solved: 'Solved', closed: 'Closed' };
var CATL = { payment: 'Payments', account: 'Account', trip: 'Trip', order: 'Order', safety: 'Safety', app: 'App problem', other: 'Other' };
function can(c) { return D.caps.indexOf('*') !== -1 || D.caps.indexOf(c) !== -1; }
function root() { return document.getElementById('panel-supportdesk'); }
function run(a, b) { return K.api(a, b || {}); }
function ok(r) { return K.okRes(r); }
function dat(r) { return K.data(r); }
function pill(txt, tone) { return '<span class="sd-pill ' + (tone || '') + '">' + esc(txt) + '</span>'; }
function stPill(s) { return pill(STL[s] || s, s === 'solved' ? 'green' : (s === 'pending' ? 'blue' : (s === 'closed' ? '' : 'amber'))); }

function css() {
  K.css();
  if (document.getElementById('sd-css')) return;
  var s = document.createElement('style'); s.id = 'sd-css';
  s.textContent = [
    '.sd-pill{display:inline-block;font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:99px;white-space:nowrap;background:var(--soft);color:var(--ink);border:1px solid var(--line)}',
    '.sd-pill.green{color:var(--green);border-color:var(--green)}.sd-pill.red{color:var(--red);border-color:var(--red)}.sd-pill.amber{color:var(--amber);border-color:var(--amber)}.sd-pill.blue{color:var(--blue);border-color:var(--blue)}',
    '.sd-top{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin:2px 0 12px}',
    '.sd-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 12px}.sd-bar select,.sd-bar input[type=search]{min-height:44px;padding:0 12px;border-radius:12px;border:1.5px solid var(--line);background:var(--card);color:var(--ink);font:inherit}',
    '.sd-bar label{display:inline-flex;gap:6px;align-items:center;min-height:44px;font-size:13px}',
    '.sd-box{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px;margin-bottom:14px}.sd-box h3{margin:0 0 6px;font-size:16px}.sd-box .sub{font-size:12.5px;color:var(--mute);margin-bottom:8px}',
    '.sd-row{cursor:pointer}.sd-row:hover td{background:var(--soft)}.sd-row.un td:first-child{box-shadow:inset 3px 0 0 var(--gold)}',
    '.sd-row b{font-weight:700}.sd-sub{display:block;font-size:12px;color:var(--mute)}',
    '.sd-th{display:flex;flex-direction:column;gap:10px;margin:8px 0}',
    '.sd-m{max-width:92%;padding:10px 14px;border-radius:14px;border:1px solid var(--line);background:var(--soft);white-space:pre-wrap;overflow-wrap:anywhere;font-size:14px;line-height:1.5}',
    '.sd-m.user{align-self:flex-start}.sd-m.staff{align-self:flex-end;border-color:var(--gold)}.sd-m.note{align-self:flex-end;background:transparent;border-style:dashed;color:var(--amber)}',
    '.sd-m small{display:block;font-size:11px;color:var(--mute);margin-bottom:3px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}',
    '.sd-sys{align-self:center;font-size:12px;color:var(--mute)}',
    '.sd-comp textarea{width:100%;min-height:96px;padding:10px 12px;border-radius:12px;border:1.5px solid var(--line);background:var(--card);color:var(--ink);font:inherit}',
    '.sd-acts{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px}.sd-acts select{min-height:44px;padding:0 10px;border-radius:12px;border:1.5px solid var(--line);background:var(--card);color:var(--ink);font:inherit}',
    '.sd-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}',
    '.sd-bars{display:grid;gap:8px}.sd-bar2{display:grid;grid-template-columns:110px 1fr 40px;gap:8px;align-items:center;font-size:13px}.sd-bar2 i{display:block;height:10px;border-radius:5px;background:var(--gold)}',
    '@media(max-width:700px){.sd-hide-sm{display:none}}'
  ].join('\n');
  document.head.appendChild(s);
}

/* ---------- screens ---------- */
function topHtml() {
  var tabs = [['queue', 'Queue'], ['stats', 'Stats'], ['canned', 'Canned replies'], ['settings', 'Settings']];
  return '<div class="sd-top"><div class="ak-seg" role="tablist" aria-label="Support desk sections">' + tabs.map(function (t) { return '<button type="button" role="tab" aria-selected="' + (D.tab === t[0]) + '" class="' + (D.tab === t[0] ? 'on' : '') + '" data-sd-tab="' + t[0] + '">' + esc(t[1]) + '</button>'; }).join('') + '</div>' +
    '<button type="button" class="ak-btn" data-sd-refresh>🔄 Refresh</button></div>';
}
function kpiStrip() {
  var c = D.counts || {};
  return K.kpis([{ key: 'k:active', icon: '📨', label: 'Open now', value: (c.open || 0) }, { key: 'k:pending', icon: '⏳', label: 'Waiting for customer', value: c.pending || 0 },
    { key: 'k:overdue', icon: '⏰', label: 'Late to reply', value: c.overdue || 0, warn: true }, { key: 'k:unread', icon: '🔔', label: 'Unread', value: c.unread || 0, warn: true }], D.overdue ? 'k:overdue' : (D.unread ? 'k:unread' : (D.st === 'pending' ? 'k:pending' : 'k:active')));
}
function filterBar() {
  var opt = function (v, l, cur) { return '<option value="' + esc(v) + '"' + (String(cur) === String(v) ? ' selected' : '') + '>' + esc(l) + '</option>'; };
  var apps = opt('', 'All apps', D.app) + Object.keys(D.apps).map(function (k) { return opt(k, D.apps[k], D.app); }).join('');
  return '<div class="sd-bar">' + K.seg('sd-st', [['active', 'Active'], ['open', 'Open'], ['pending', 'Waiting'], ['solved', 'Solved'], ['closed', 'Closed'], ['all', 'All']], D.st) +
    '<select data-sd-f="app" aria-label="App">' + apps + '</select>' +
    '<select data-sd-f="prio" aria-label="Priority">' + opt('', 'Any priority', D.prio) + opt('urgent', 'Urgent', D.prio) + opt('high', 'High', D.prio) + opt('normal', 'Normal', D.prio) + opt('low', 'Low', D.prio) + '</select>' +
    '<select data-sd-f="asg" aria-label="Assigned">' + opt('', 'Anyone', D.asg) + opt('me', 'Mine', D.asg) + opt('none', 'Unassigned', D.asg) + '</select>' +
    '<input type="search" data-sd-f="q" value="' + esc(D.q) + '" placeholder="Search number, title, name, phone" aria-label="Search" style="min-width:210px;flex:1"></div>';
}
function queueHtml() {
  var h = kpiStrip() + filterBar();
  if (D.err) return h + '<div class="ak-empty"><b>⚠️</b><div>' + esc(D.err) + '</div></div>';
  if (!D.rows) return h + K.skeleton(4);
  if (!D.rows.length) return h + K.empty('🎧', 'Nothing here', 'No requests match these filters.');
  return h + '<div class="ak-tblw"><table class="ak-tbl"><thead><tr><th scope="col">Request</th><th scope="col" class="sd-hide-sm">App</th><th scope="col">Status</th><th scope="col" class="sd-hide-sm">Priority</th><th scope="col">Updated</th></tr></thead><tbody>' + D.rows.map(function (t) {
    return '<tr class="sd-row' + (t.unread ? ' un' : '') + '" tabindex="0" role="button" data-sd-open="' + esc(t.id) + '" aria-label="Open ' + esc(t.ref) + '"><td><b>' + esc(t.subject) + '</b><span class="sd-sub">' + esc(t.ref) + ' · ' + esc(t.user_name || 'Customer') + ' · ' + esc(CATL[t.category] || t.category) + '</span></td><td class="sd-hide-sm">' + esc(D.apps[t.app] || t.app) + '</td><td>' + stPill(t.status) + (t.overdue ? ' ' + pill('Late', 'red') : '') + '</td><td class="sd-hide-sm">' + pill(t.priority, PR[t.priority]) + '</td><td>' + esc(K.ago(t.updated_at)) + '</td></tr>'; }).join('') + '</tbody></table></div>';
}
function statsHtml() {
  var s = D.stats; if (!s) return K.skeleton(4);
  var bars = function (rows, label) { var max = 1; rows.forEach(function (r) { max = Math.max(max, +r.n); }); return rows.length ? '<div class="sd-bars">' + rows.map(function (r) { return '<div class="sd-bar2"><span>' + esc(label(r.k)) + '</span><i style="width:' + Math.round(r.n / max * 100) + '%"></i><b>' + esc(r.n) + '</b></div>'; }).join('') + '</div>' : '<p class="sd-sub">No data yet.</p>'; };
  return '<div class="sd-bar">' + K.seg('sd-days', [['7', '7 days'], ['30', '30 days'], ['90', '90 days']], String(D.days)) + '</div>' +
    K.kpis([{ key: 'a', icon: '📨', label: 'New requests', value: s.created }, { key: 'b', icon: '✅', label: 'Solved', value: s.solved }, { key: 'c', icon: '⏱️', label: 'First reply (hours)', value: s.avg_first_response_hours == null ? '-' : s.avg_first_response_hours }, { key: 'd', icon: '⭐', label: 'Rating (' + s.rated + ')', value: s.csat == null ? '-' : s.csat }], '', true) +
    '<div class="sd-grid"><section class="sd-box"><h3>By app</h3>' + bars(s.by_app, function (k) { return s.apps[k] || k; }) + '</section><section class="sd-box"><h3>By topic</h3>' + bars(s.by_category, function (k) { return CATL[k] || k; }) + '</section></div>';
}
function cannedHtml() {
  var edit = can('support.manage');
  var h = '<p class="sd-sub" style="margin:0 0 10px">Ready-made answers. In a reply, pick one and it fills the box; you can still change it before sending.</p>';
  if (!D.canned) return h + K.skeleton(2);
  h += D.canned.map(function (c) { return '<section class="sd-box"><h3>' + esc(c.title) + '</h3><p style="margin:0 0 6px;white-space:pre-wrap">' + esc(c.body_en) + '</p>' + (c.body_am ? '<p style="margin:0 0 8px;white-space:pre-wrap">' + esc(c.body_am) + '</p>' : '') + (edit ? '<button type="button" class="ak-btn" data-sd-cedit="' + esc(c.id) + '">Edit</button> <button type="button" class="ak-btn danger" data-sd-cdel="' + esc(c.id) + '">Delete</button>' : '') + '</section>'; }).join('');
  if (edit) h += '<section class="sd-box" id="sd-cform"><h3>' + (D.sel ? 'Edit reply' : 'New canned reply') + '</h3><label class="ak-fl">Title<input class="ak-in" id="sd-ct" maxlength="80"></label><label class="ak-fl">English text<textarea class="ak-in" id="sd-ce" rows="3" maxlength="2000"></textarea></label><label class="ak-fl">Amharic text (optional)<textarea class="ak-in" id="sd-ca" rows="3" maxlength="2000"></textarea></label><p><button type="button" class="ak-btn gold" data-sd-csave>Save</button></p></section>';
  return h;
}
var NUMS = [['desk_enabled', 'Accept new requests (turn off to pause the desk)', 'switch'], ['sla_first_hours', 'Reply target for normal requests (hours)'], ['sla_urgent_hours', 'Reply target for urgent requests (hours)'], ['max_open_per_user', 'Most open requests per customer'], ['max_new_per_hour', 'Most new requests per customer per hour'], ['auto_close_days', 'Close solved requests after (days)'], ['csat_enabled', 'Ask customers to rate the help (stars)', 'switch']];
function settingsHtml() {
  if (!D.cfg) return K.skeleton(3);
  var edit = can('support.manage'), meta = D.meta || {};
  var f = function (d) {
    var k = d[0], v = D.cfg[k], m = meta[k] || {};
    if (d[2] === 'switch') return '<label class="ak-sw"><input type="checkbox" data-sd-set="' + k + '"' + (+v ? ' checked' : '') + (edit ? '' : ' disabled') + '><span>' + esc(d[1]) + '</span></label>';
    return '<label class="ak-fl">' + esc(d[1]) + '<input class="ak-in" type="number" inputmode="numeric" data-sd-set="' + k + '" min="' + esc(m.min) + '" max="' + esc(m.max) + '" value="' + esc(v) + '"' + (edit ? '' : ' disabled') + '><div class="ak-fh">Default ' + esc(m['default']) + '. Allowed ' + esc(m.min) + ' to ' + esc(m.max) + '</div></label>';
  };
  var tg = '<section class="sd-box"><h3>Telegram notices (optional)</h3><p class="sub">Off until you switch it on. Only a short notice is sent: the request number and a link. Never the message text, never a phone number. ' + (D.tgTok ? 'The bot token is set on the server.' : '<b>No bot token is set on the server yet (TG_BOT_TOKEN), so nothing can be sent.</b>') + '</p>' +
    '<label class="ak-sw"><input type="checkbox" data-sd-set="tg_enabled"' + (+D.cfg.tg_enabled ? ' checked' : '') + (edit ? '' : ' disabled') + '><span>Send Telegram notices</span></label>' +
    '<label class="ak-sw"><input type="checkbox" data-sd-set="tg_notify_user"' + (+D.cfg.tg_notify_user ? ' checked' : '') + (edit ? '' : ' disabled') + '><span>Tell customers on Telegram when we reply (only if they linked Telegram to EtelID)</span></label>' +
    '<label class="ak-fl">Staff Telegram chat id (new-request alerts)<input class="ak-in" data-sd-set="tg_staff_chat_id" value="' + esc(D.cfg.tg_staff_chat_id) + '" placeholder="for example -1001234567890"' + (edit ? '' : ' disabled') + '></label></section>';
  return (edit ? '' : '<div class="ak-empty"><div>View only: you can see these numbers but not change them.</div></div>') + '<section class="sd-box"><h3>Support desk</h3><div style="display:grid;gap:10px">' + NUMS.map(f).join('') + '</div></section>' + tg +
    (edit ? '<div style="position:sticky;bottom:10px;display:flex;justify-content:flex-end"><button type="button" class="ak-btn gold" data-sd-saveset style="box-shadow:var(--shadow)">Save changes</button></div>' : '');
}
function paint() {
  var el = root(); if (!el) return; css();
  var body = D.tab === 'queue' ? queueHtml() : (D.tab === 'stats' ? statsHtml() : (D.tab === 'canned' ? cannedHtml() : settingsHtml()));
  var keepQ = document.activeElement && document.activeElement.getAttribute && document.activeElement.getAttribute('data-sd-f') === 'q';
  el.innerHTML = '<div class="ak">' + topHtml() + '<div id="sd-screen">' + body + '</div></div>';
  if (keepQ) { var i = el.querySelector('[data-sd-f=q]'); if (i) { i.focus(); try { i.setSelectionRange(i.value.length, i.value.length); } catch (e) { /* not supported */ } } }
}

/* ---------- data ---------- */
function loadQueue() {
  var b = { status: D.st === 'all' ? 'all' : D.st, app: D.app, priority: D.prio, assigned: D.asg, q: D.q, unread: D.unread ? 1 : 0, overdue: D.overdue ? 1 : 0 };
  return run('admin_sup_list', b).then(function (r) {
    if (!ok(r)) { D.err = K.failText(r, 'Could not load the queue.'); D.rows = null; } else { D.err = ''; var x = dat(r); D.rows = x.tickets; D.counts = x.counts; D.apps = x.apps; D.counts.open = (x.counts.open || 0); }
    if (D.tab === 'queue') paint();
  });
}
function loadStats() { return run('admin_sup_stats', { days: D.days }).then(function (r) { if (ok(r)) D.stats = dat(r); if (D.tab === 'stats') paint(); }); }
function loadCanned() { return run('admin_sup_canned_list').then(function (r) { if (ok(r)) D.canned = dat(r).canned; if (D.tab === 'canned') paint(); }); }
function loadSettings() { return run('admin_sup_settings_get').then(function (r) { if (ok(r)) { var x = dat(r); D.cfg = x.settings; D.meta = x.meta; D.tgTok = !!x.tg_token_configured; } if (D.tab === 'settings') paint(); }); }
function openTab(t) { D.tab = t; paint(); return t === 'queue' ? loadQueue() : (t === 'stats' ? loadStats() : (t === 'canned' ? (D.sel = 0, loadCanned()) : loadSettings())); }

/* ---------- ticket drawer ---------- */
function openTicket(id) {
  var pre = (can('support.manage') && !D.canned) ? loadCanned() : Promise.resolve();
  return pre.then(function () { return run('admin_sup_get', { id: id }); }).then(function (r) {
    if (!ok(r)) { K.toast(K.failText(r, 'Could not open this request.'), true); return; }
    var d = dat(r), t = d.ticket, u = d.user || {}, manage = can('support.manage');
    var head = '<div class="ak-top"><div class="ak-av lg">' + esc(K.initials(u.name || 'C')) + '</div><div class="ak-nm"><b>' + esc(t.subject) + '</b><small>' + esc(t.ref) + ' · ' + esc(D.apps[t.app] || t.app) + '</small></div></div>';
    var th = d.messages.map(function (m) {
      if (m.sender === 'system') return '<div class="sd-sys">' + esc(m.body) + ' · ' + esc(K.ago(m.created_at)) + '</div>';
      var who = m.sender === 'user' ? (u.name || 'Customer') : (m.sender === 'note' ? 'Internal note' : (m.staff_name || 'Staff'));
      return '<div class="sd-m ' + esc(m.sender) + '"><small>' + esc(who) + ' · ' + esc(K.ago(m.created_at)) + '</small>' + esc(m.body) + '</div>'; }).join('');
    var other = (d.other || []).map(function (o) { return esc(o.ref) + ' (' + esc(o.status) + ')'; }).join(', ');
    var body = '<div class="ak-sec">' + K.kv([['Status', stPill(t.status) + (t.overdue ? ' ' + pill('Late', 'red') : ''), true], ['Priority', pill(t.priority, PR[t.priority]), true], ['Topic', CATL[t.category] || t.category], ['Customer', u.name || '-'], ['Phone', u.phone || '-'], ['Etel ID', u.etel_id || '-'], ['Trip / order', t.related || '-'], ['Customer language', t.lang === 'en' ? 'English' : 'Amharic'], ['Other requests', other || '-'], ['Rating', t.rating ? t.rating + ' of 5' + (t.rating_note ? ' - ' + t.rating_note : '') : '-']]) + '</div>' +
      '<div class="ak-sec"><h4>Conversation</h4><div class="sd-th">' + th + '</div></div>';
    var foot = '';
    if (manage && t.status !== 'closed') {
      var canned = (D.canned || []).map(function (c) { return '<option value="' + esc(c.id) + '">' + esc(c.title) + '</option>'; }).join('');
      foot = '<div class="sd-comp" style="width:100%"><textarea id="sd-body" maxlength="4000" placeholder="Write to the customer (they see this) or an internal note" aria-label="Message"></textarea>' +
        '<div class="sd-acts"><select id="sd-canned" aria-label="Canned reply"><option value="">Canned reply...</option>' + canned + '</select><select id="sd-newst" aria-label="After sending"><option value="pending">Then: waiting for customer</option><option value="solved">Then: solved</option><option value="open">Then: keep open</option></select>' +
        '<button type="button" class="ak-btn gold" data-sd-send="' + esc(t.id) + '">Send reply</button><button type="button" class="ak-btn" data-sd-note="' + esc(t.id) + '">Add internal note</button></div>' +
        '<div class="sd-acts"><button type="button" class="ak-btn" data-sd-take="' + esc(t.id) + '">' + (t.assigned_to ? 'Take over' : 'Take it') + '</button><select id="sd-prio" aria-label="Priority">' + ['urgent', 'high', 'normal', 'low'].map(function (p) { return '<option' + (t.priority === p ? ' selected' : '') + '>' + p + '</option>'; }).join('') + '</select><button type="button" class="ak-btn" data-sd-prio="' + esc(t.id) + '">Set priority</button><button type="button" class="ak-btn danger" data-sd-close="' + esc(t.id) + '">Close</button></div></div>';
    } else if (manage) foot = '<button type="button" class="ak-btn" data-sd-reopen="' + esc(t.id) + '">Re-open</button>';
    var dr = K.openDrawer(head, body, foot);
    var bodyEl = dr.querySelector('.ak-dr-body'); if (bodyEl) bodyEl.scrollTop = bodyEl.scrollHeight;
    dr.addEventListener('change', function (e) {
      if (e.target.id === 'sd-canned') { var c = (D.canned || []).filter(function (x) { return String(x.id) === e.target.value; })[0]; if (c) { var tx = (t.lang === 'am' && c.body_am) ? c.body_am : (c.body_en || c.body_am); document.getElementById('sd-body').value = tx; } }
    });
    dr.addEventListener('click', function (e) { drawerClick(e, t); });
  });
}
function drawerClick(e, t) {
  var b = e.target.closest('button'); if (!b) return;
  var txt = function () { return (document.getElementById('sd-body') || {}).value || ''; };
  var after = function (r, msg) { if (!ok(r)) { K.toast(K.failText(r, 'Could not save.'), true); return; } K.toast(msg); return openTicket(t.id).then(loadQueue); };
  if (b.hasAttribute('data-sd-send')) { if (!txt().trim()) { K.toast('Write a message first', true); return; } b.disabled = true; run('admin_sup_reply', { id: t.id, body: txt(), status: document.getElementById('sd-newst').value }).then(function (r) { b.disabled = false; return after(r, 'Reply sent'); }); }
  else if (b.hasAttribute('data-sd-note')) { if (!txt().trim()) { K.toast('Write a note first', true); return; } b.disabled = true; run('admin_sup_reply', { id: t.id, body: txt(), note: 1 }).then(function (r) { b.disabled = false; return after(r, 'Note added'); }); }
  else if (b.hasAttribute('data-sd-take')) run('admin_sup_update', { id: t.id, assign: 'me' }).then(function (r) { return after(r, 'Assigned to you'); });
  else if (b.hasAttribute('data-sd-prio')) run('admin_sup_update', { id: t.id, priority: document.getElementById('sd-prio').value }).then(function (r) { return after(r, 'Priority changed'); });
  else if (b.hasAttribute('data-sd-close')) K.confirm({ title: 'Close ' + t.ref + '?', text: 'The customer can no longer reply. They can open a new request.', label: 'Close' }).then(function (res) { if (res) run('admin_sup_update', { id: t.id, status: 'closed' }).then(function (r) { return after(r, 'Closed'); }); });
  else if (b.hasAttribute('data-sd-reopen')) run('admin_sup_update', { id: t.id, status: 'open' }).then(function (r) { return after(r, 'Re-opened'); });
}

/* ---------- events ---------- */
function onClick(e) {
  var t = e.target, b;
  if ((b = t.closest('[data-sd-tab]'))) { openTab(b.getAttribute('data-sd-tab')); return; }
  if (t.closest('[data-sd-refresh]')) { openTab(D.tab); return; }
  if ((b = t.closest('[data-seg="sd-st"]'))) { D.st = b.getAttribute('data-val'); D.unread = false; D.overdue = false; D.rows = null; paint(); loadQueue(); return; }
  if ((b = t.closest('[data-seg="sd-days"]'))) { D.days = parseInt(b.getAttribute('data-val'), 10) || 30; D.stats = null; paint(); loadStats(); return; }
  if ((b = t.closest('[data-kpi]'))) { var k = b.getAttribute('data-kpi'); if (k.indexOf('k:') !== 0 || D.tab !== 'queue') return; D.unread = k === 'k:unread'; D.overdue = k === 'k:overdue'; D.st = k === 'k:pending' ? 'pending' : 'active'; D.rows = null; paint(); loadQueue(); return; }
  if ((b = t.closest('[data-sd-open]'))) { openTicket(b.getAttribute('data-sd-open')); return; }
  if ((b = t.closest('[data-sd-cedit]'))) { var c = (D.canned || []).filter(function (x) { return String(x.id) === b.getAttribute('data-sd-cedit'); })[0]; if (c) { D.sel = c.id; paint(); document.getElementById('sd-ct').value = c.title; document.getElementById('sd-ce').value = c.body_en; document.getElementById('sd-ca').value = c.body_am; document.getElementById('sd-cform').scrollIntoView({ block: 'center' }); } return; }
  if ((b = t.closest('[data-sd-cdel]'))) { K.confirm({ title: 'Delete this canned reply?', text: 'Replies already sent are not changed.', label: 'Delete', danger: true }).then(function (res) { if (res) run('admin_sup_canned_delete', { id: b.getAttribute('data-sd-cdel') }).then(function () { loadCanned(); }); }); return; }
  if (t.closest('[data-sd-csave]')) { run('admin_sup_canned_save', { id: D.sel || 0, title: document.getElementById('sd-ct').value, body_en: document.getElementById('sd-ce').value, body_am: document.getElementById('sd-ca').value }).then(function (r) { if (!ok(r)) { K.toast(K.failText(r, 'Could not save.'), true); return; } K.toast('Saved'); D.sel = 0; loadCanned(); }); return; }
  if (t.closest('[data-sd-saveset]')) {
    var s = {}; root().querySelectorAll('[data-sd-set]').forEach(function (i) { s[i.getAttribute('data-sd-set')] = i.type === 'checkbox' ? (i.checked ? 1 : 0) : i.value; });
    run('admin_sup_settings_save', { settings: s }).then(function (r) { if (!ok(r)) { K.toast(K.failText(r, 'Could not save.'), true); return; } D.cfg = dat(r).settings; K.toast('Saved'); paint(); });
  }
}
var qTimer = null;
function onInput(e) {
  var f = e.target.getAttribute && e.target.getAttribute('data-sd-f'); if (!f) return;
  var v = e.target.value; if (f === 'app') D.app = v; else if (f === 'prio') D.prio = v; else if (f === 'asg') D.asg = v; else D.q = v;
  clearTimeout(qTimer); qTimer = setTimeout(function () { loadQueue(); }, f === 'q' ? 350 : 0);
}
function onKey(e) { if (e.key === 'Enter' && e.target.closest && e.target.closest('.sd-row')) openTicket(e.target.closest('.sd-row').getAttribute('data-sd-open')); }

function load() {
  var el = root(); if (!el) return; css();
  if (!el._sd) { el._sd = true; el.addEventListener('click', onClick); el.addEventListener('input', onInput); el.addEventListener('change', onInput); el.addEventListener('keydown', onKey); }
  el.innerHTML = '<div class="ak">' + K.skeleton(4) + '</div>';
  var caps = window.ETEL_CAPS ? Promise.resolve(window.ETEL_CAPS) : (window.ETEL_CAPS_PROMISE || Promise.resolve([]));
  caps.then(function (c) {
    D.caps = c || [];
    if (!can('support.view')) { el.innerHTML = '<div class="ak">' + K.empty('🔒', 'You do not have access to the Support desk', 'Ask the owner to give your role the "support.view" permission.') + '</div>'; return; }
    return openTab(D.tab);
  });
}
function hook() {
  if (window.__sdHooked || typeof window.switchPage !== 'function') return;
  window.__sdHooked = true;
  var orig = window.switchPage;
  window.switchPage = function (page, elm) {
    var r = orig.apply(this, arguments);
    if (page === 'supportdesk') {
      var ttl = document.getElementById('page-title'); if (ttl) ttl.textContent = 'Support desk';
      var ab = document.getElementById('action-buttons'); if (ab) ab.innerHTML = '';
      load();
    }
    return r;
  };
}
function install() {
  K.ensurePanel('supportdesk', 'supportdesk', '🎧', 'Support desk', 'support');
  hook();
  window.ETELSupport = { refresh: function () { return openTab(D.tab); }, open: openTicket };
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
