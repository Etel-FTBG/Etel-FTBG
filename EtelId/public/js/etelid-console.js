/* Etel-ID standalone Admin Console - views: overview, members, roles, audit, tools.
   Talks to the same admin-only endpoints as the portal's Admin Tools (etelid_panel_*). */
(function () {
'use strict';
var C = window.EtelIDConsole || {}, $ = function (id) { return document.getElementById(id); };
var VIEW = $('ec-view'), root = document.documentElement;
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
function num(n) { return Number(n || 0).toLocaleString('en-US'); }

/* ---------- theme ---------- */
function readTheme() { var t = null; try { t = localStorage.getItem('theme'); } catch (e) { /* none */ } return (t === 'dark' || t === 'light') ? t : root.getAttribute('data-eid-theme') || 'light'; }
function paint(t) { root.setAttribute('data-eid-theme', t); var b = $('ec-theme'); if (b) { b.textContent = t === 'dark' ? '☀️' : '🌙'; b.title = t === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'; } }
paint(readTheme());
$('ec-theme').addEventListener('click', function () { var t = root.getAttribute('data-eid-theme') === 'dark' ? 'light' : 'dark'; try { localStorage.setItem('theme', t); } catch (e) { /* none */ } paint(t); });
window.addEventListener('storage', function (e) { if (e.key === 'theme') paint(readTheme()); });
$('ec-burger').addEventListener('click', function () { document.body.classList.toggle('nav-open'); });
$('ec-scrim').addEventListener('click', function () { document.body.classList.remove('nav-open'); });

/* ---------- api ---------- */
function api(action, data) {
  var fd = new FormData(); fd.append('action', action); fd.append('nonce', C.nonce);
  Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
  return fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); })
    .catch(function () { return { success: false, data: { message: 'Network error - check your connection.' } }; });
}
function err(res) { return (res && res.data && res.data.message) || 'Something went wrong.'; }
var tt; function toast(msg, bad) { var t = $('ec-toast'); t.textContent = msg; t.className = 'ec-toast show' + (bad ? ' err' : ''); clearTimeout(tt); tt = setTimeout(function () { t.className = 'ec-toast' + (bad ? ' err' : ''); }, 3200); }

/* ---------- navigation ---------- */
var TITLES = { overview: ['Dashboard', 'Your Etel-ID system at a glance'], members: ['Members', 'Search, review and manage every Etel-ID'], roles: ['Company roles', 'Who is admin or staff at each Etel company'], audit: ['Audit log', 'Every identity event, newest first'], tools: ['Tools & settings', 'Everything else, one tap away'] };
var VIEWS = {};
function show(name) {
  if (!VIEWS[name]) name = 'overview';
  var navs = document.querySelectorAll('#ec-nav a[data-view]');
  for (var i = 0; i < navs.length; i++) navs[i].classList.toggle('on', navs[i].getAttribute('data-view') === name);
  $('ec-title').textContent = TITLES[name][0]; $('ec-sub').textContent = TITLES[name][1];
  document.body.classList.remove('nav-open'); try { history.replaceState(null, '', '#' + name); } catch (e) { /* none */ }
  VIEW.innerHTML = '<div class="ec-loading">Loading…</div>'; window.scrollTo(0, 0);
  VIEWS[name]();
}
document.addEventListener('click', function (e) { var a = e.target.closest && e.target.closest('[data-view]'); if (a) { e.preventDefault(); show(a.getAttribute('data-view')); } });

/* ---------- overview ---------- */
var COLORS = { active: '#1fa463', suspended: '#d4af37', revoked: '#d6364a', expired: '#8a8aa0' };
VIEWS.overview = function () {
  Promise.all([api('etelid_panel_members_list', { page: 1 }), api('etelid_panel_audit_list', { limit: 6 })]).then(function (r) {
    var m = r[0], a = r[1];
    if (!m.success) { VIEW.innerHTML = '<div class="ec-empty">' + esc(err(m)) + '</div>'; return; }
    var st = m.data.stats || {}, total = (st.active || 0) + (st.suspended || 0) + (st.revoked || 0) + (st.expired || 0);
    var tot = m.data.total || total;
    var h = '<section class="ec-hero"><div class="ec-hero-row"><div class="logos"><img src="' + esc(C.url) + 'public/images/etel-master-coin-128.webp" alt=""><img src="' + esc(C.url) + 'public/images/etel-delivery-coin.webp" alt=""></div>' +
      '<div><div class="eyebrow">Etel-ID · Corporate identity</div><h2>Welcome, ' + esc(C.name) + '</h2><p>' + num(tot) + ' Etel-IDs issued across every Etel company.</p></div>' +
      '<div class="ec-hero-actions"><a class="btn btn-primary btn-sm" href="' + esc(C.wp.issue) + '">+ Issue new ID</a><a class="btn btn-outline btn-sm" data-view="members">Open members</a></div></div></section>';
    h += '<div class="ec-stats">' +
      stat('gold', '🪪', 'Total issued', num(tot), 'All time') + stat('', '✅', 'Active', num(st.active), 'Valid IDs') + stat('', '⏸️', 'Suspended', num(st.suspended), 'Under review') +
      stat('', '🚫', 'Revoked', num(st.revoked), 'Cancelled') + stat('', '⌛', 'Expired', num(st.expired), 'Need renewal') + '</div>';
    h += '<div class="ec-grid2"><div class="ec-card"><h2>🆕 Latest members</h2><div class="desc">Tap a row to open the full profile and actions.</div>' + membersTable((m.data.members || []).slice(0, 6), true) + '</div>' +
      '<div><div class="ec-card"><h2>📊 Status mix</h2><div style="margin-top:12px">' + donut(st) + '</div></div>' +
      '<div class="ec-card"><h2>🧾 Recent activity</h2><div class="ec-act" style="margin-top:12px">' + activity(a) + '</div></div></div></div>';
    VIEW.innerHTML = h;
  });
};
function stat(cls, ic, l, v, s) { return '<div class="ec-stat ' + cls + '"><div class="ic">' + ic + '</div><div class="l">' + l + '</div><div class="v">' + v + '</div><div class="s">' + s + '</div></div>'; }
function donut(st) {
  var keys = ['active', 'suspended', 'revoked', 'expired'], tot = 0; keys.forEach(function (k) { tot += st[k] || 0; });
  var R = 54, Cc = 2 * Math.PI * R, off = 0, arcs = '';
  if (tot > 0) keys.forEach(function (k) { var len = (st[k] || 0) / tot * Cc; if (len > 0) arcs += '<circle cx="75" cy="75" r="' + R + '" fill="none" stroke="' + COLORS[k] + '" stroke-width="18" stroke-dasharray="' + len.toFixed(1) + ' ' + (Cc - len).toFixed(1) + '" stroke-dashoffset="' + (-off).toFixed(1) + '" transform="rotate(-90 75 75)"/>'; off += len; });
  var leg = keys.map(function (k) { return '<span><i style="background:' + COLORS[k] + '"></i>' + k.charAt(0).toUpperCase() + k.slice(1) + '<b>' + num(st[k]) + '</b></span>'; }).join('');
  return '<div class="ec-donut"><svg viewBox="0 0 150 150"><circle cx="75" cy="75" r="' + R + '" fill="none" stroke="var(--line)" stroke-width="18"/>' + arcs + '<text class="c1" x="75" y="77" text-anchor="middle">' + num(tot) + '</text><text class="c2" x="75" y="94" text-anchor="middle">members</text></svg><div class="ec-legend">' + leg + '</div></div>';
}
function activity(a) {
  if (!a || !a.success || !a.data.entries.length) return '<div class="ec-empty" style="padding:14px">No activity recorded yet.</div>';
  return a.data.entries.map(function (e) { return '<div class="ec-act-i"><div><b>' + esc(e.event) + '</b> · <code>' + esc(e.etel_id) + '</code><small>' + esc(e.detail || '') + ' ' + esc(e.time) + '</small></div></div>'; }).join('');
}

/* ---------- members ---------- */
var MS = { page: 1, q: '', status: '' };
function membersTable(rows, compact) {
  if (!rows.length) return '<div class="ec-empty">No members found.</div>';
  var h = '<div class="ec-tablewrap"><table class="ec-table"><thead><tr><th>Etel-ID</th><th>Name</th>' + (compact ? '' : '<th>Company / role</th><th>Phone</th>') + '<th>Tier</th><th>Status</th>' + (compact ? '' : '<th>KYC</th>') + '</tr></thead><tbody>';
  rows.forEach(function (m) {
    h += '<tr class="click" data-open="' + m.id + '"><td><code>' + esc(m.etel_id) + '</code></td><td class="ec-name"><b>' + esc(m.name) + '</b>' + (m.name_am ? '<small>' + esc(m.name_am) + '</small>' : '') + '</td>' +
      (compact ? '' : '<td>' + esc(m.company) + (m.role ? '<br><small style="color:var(--mute)">' + esc(m.role) + '</small>' : '') + '</td><td class="mono">' + esc(m.phone) + '</td>') +
      '<td><span class="ec-pill tier">' + esc(m.tier || 'standard') + '</span></td><td><span class="ec-pill ' + esc(m.status) + '">' + esc(m.status) + '</span></td>' +
      (compact ? '' : '<td>' + (m.kyc_level != null ? 'L' + esc(m.kyc_level) : '') + '</td>') + '</tr>';
  });
  return h + '</tbody></table></div>';
}
VIEWS.members = function () {
  VIEW.innerHTML = '<div class="ec-card"><div class="ec-tools"><input class="ec-search" id="ms-q" placeholder="Search name, Etel-ID, phone, FIN…" value="' + esc(MS.q) + '">' +
    '<div class="ec-seg" id="ms-seg">' + ['', 'active', 'suspended', 'revoked', 'expired'].map(function (s) { return '<button data-s="' + s + '" class="' + (MS.status === s ? 'on' : '') + '">' + (s ? s.charAt(0).toUpperCase() + s.slice(1) : 'All') + '</button>'; }).join('') + '</div>' +
    '<a class="btn btn-primary btn-sm" href="' + esc(C.wp.issue) + '">+ Issue ID</a></div><div id="ms-body"><div class="ec-loading">Loading…</div></div></div>';
  var t; $('ms-q').addEventListener('input', function (e) { clearTimeout(t); t = setTimeout(function () { MS.q = e.target.value.trim(); MS.page = 1; loadMembers(); }, 300); });
  $('ms-seg').addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b) return; MS.status = b.getAttribute('data-s'); MS.page = 1; var bs = this.querySelectorAll('button'); for (var i = 0; i < bs.length; i++) bs[i].classList.toggle('on', bs[i] === b); loadMembers(); });
  loadMembers();
};
function loadMembers() {
  api('etelid_panel_members_list', { page: MS.page, search: MS.q, status: MS.status }).then(function (r) {
    var el = $('ms-body'); if (!el) return;
    if (!r.success) { el.innerHTML = '<div class="ec-empty">' + esc(err(r)) + '</div>'; return; }
    el.innerHTML = membersTable(r.data.members || [], false) + pager(r.data.page, r.data.pages, r.data.total);
  });
}
function pager(page, pages, total) {
  return '<div class="ec-pager"><button data-pg="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>‹ Prev</button><span>Page ' + page + ' of ' + pages + ' · ' + num(total) + '</span><button data-pg="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>Next ›</button></div>';
}
document.addEventListener('click', function (e) {
  var p = e.target.closest && e.target.closest('[data-pg]');
  if (p && !p.disabled) { var n = parseInt(p.getAttribute('data-pg'), 10); if (p.closest('#ms-body')) { MS.page = n; loadMembers(); } else if (p.closest('#au-body')) { AU.page = n; loadAudit(); } return; }
  var r = e.target.closest && e.target.closest('[data-open]'); if (r) openMember(parseInt(r.getAttribute('data-open'), 10));
});

/* ---------- member drawer ---------- */
function drawer(html) { $('ec-drawer').innerHTML = html; $('ec-drawer-bg').classList.add('open'); }
function closeDrawer() { $('ec-drawer-bg').classList.remove('open'); }
$('ec-drawer-bg').addEventListener('click', function (e) { if (e.target === this || e.target.closest('[data-close]')) closeDrawer(); });
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDrawer(); });
function openMember(id) {
  drawer('<div class="ec-loading">Loading…</div>');
  api('etelid_panel_members_get', { id: id }).then(function (r) {
    if (!r.success) { drawer('<div class="ec-empty">' + esc(err(r)) + '</div><button class="btn btn-outline" data-close>Close</button>'); return; }
    var m = r.data.member || {}, init = String(m.full_name || '?').split(/\s+/).map(function (w) { return w.charAt(0); }).join('').slice(0, 2).toUpperCase();
    var kv = function (l, v) { return '<div><small>' + l + '</small><span>' + (v ? esc(v) : '—') + '</span></div>'; };
    drawer('<div class="ec-d-head"><div class="ec-av">' + (m.photo_url ? '<img src="' + esc(m.photo_url) + '" alt="">' : esc(init)) + '</div><div><h3>' + esc(m.full_name) + '</h3><div><code>' + esc(m.etel_id) + '</code> <span class="ec-pill ' + esc(m.status) + '">' + esc(m.status) + '</span></div></div><button class="btn btn-outline btn-sm ec-d-close" data-close>✕</button></div>' +
      '<div class="ec-kv">' + kv('Amharic name', m.full_name_am) + kv('Tier', m.tier) + kv('Phone', m.phone) + kv('Email', m.email) + kv('Company', m.primary_company) + kv('Role', m.role) + kv('FIN', m.fayda_fin || m.fin) + kv('KYC level', m.kyc_level) + kv('Date of birth', m.dob) + kv('Region', m.region) + kv('Issued', m.created_at) + kv('Expires', m.expiry_date) + '</div>' +
      '<div class="ec-sec">Status</div><div class="ec-actions">' + ['active', 'suspended', 'revoked'].map(function (s) { return '<button class="btn ' + (m.status === s ? 'btn-primary' : 'btn-outline') + ' btn-sm" data-st="' + s + '" data-id="' + id + '">' + s.charAt(0).toUpperCase() + s.slice(1) + '</button>'; }).join('') + '</div>' +
      '<div class="ec-sec">Quick actions</div><div class="ec-actions"><a class="btn btn-outline btn-sm" target="_blank" href="' + esc(C.print + encodeURIComponent(m.etel_id || '')) + '/">🖨 Open card</a><button class="btn btn-outline btn-sm" data-vp="' + id + '">📞 Mark phone verified</button></div>' +
      '<div class="ec-sec">Reset password</div><div class="ec-field"><input class="ec-in" id="rp1" type="password" placeholder="New password (8+ characters)" autocomplete="new-password"></div><div class="ec-field"><input class="ec-in" id="rp2" type="password" placeholder="Confirm password" autocomplete="new-password"></div><button class="btn btn-outline btn-sm" data-rp="' + id + '">🔑 Reset password</button>' +
      '<div class="ec-sec">Danger zone</div><button class="btn btn-danger btn-sm" data-del="' + id + '" data-name="' + esc(m.full_name) + '">🗑 Delete member</button>');
  });
}
document.addEventListener('click', function (e) {
  var t = e.target.closest && e.target.closest('[data-st],[data-vp],[data-rp],[data-del]'); if (!t) return;
  var id = parseInt(t.getAttribute('data-id') || t.getAttribute('data-vp') || t.getAttribute('data-rp') || t.getAttribute('data-del'), 10);
  var done = function (r, again) { if (r.success) { toast(r.data.message || 'Done'); if (again) openMember(id); refresh(); } else toast(err(r), true); };
  if (t.hasAttribute('data-st')) api('etelid_panel_members_status', { id: id, status: t.getAttribute('data-st') }).then(function (r) { done(r, true); });
  else if (t.hasAttribute('data-vp')) api('etelid_panel_members_verify_phone', { id: id }).then(function (r) { done(r, false); });
  else if (t.hasAttribute('data-rp')) api('etelid_panel_reset_password', { id: id, new_password: $('rp1').value, confirm_password: $('rp2').value }).then(function (r) { done(r, false); if (r.success) { $('rp1').value = ''; $('rp2').value = ''; } });
  else if (t.hasAttribute('data-del')) { if (!confirm('Permanently delete ' + t.getAttribute('data-name') + '? This cannot be undone.')) return; api('etelid_panel_members_delete', { id: id }).then(function (r) { if (r.success) closeDrawer(); done(r, false); }); }
});
function refresh() { var on = document.querySelector('#ec-nav a.on'); var v = on ? on.getAttribute('data-view') : 'overview'; if (v === 'members') loadMembers(); else if (v === 'overview') VIEWS.overview(); }

/* ---------- company roles ---------- */
VIEWS.roles = function () {
  api('etelid_panel_company_roles_list', {}).then(function (r) {
    if (!r.success) { VIEW.innerHTML = '<div class="ec-empty">' + esc(err(r)) + '</div>'; return; }
    var links = r.data.links || [], slugs = r.data.known_slugs || [];
    var rows = links.length ? '<div class="ec-tablewrap"><table class="ec-table"><thead><tr><th>Person</th><th>Etel-ID</th><th>Phone</th><th>Company</th><th>Role</th><th></th></tr></thead><tbody>' +
      links.map(function (l) { return '<tr><td class="ec-name"><b>' + esc(l.full_name) + '</b></td><td><code>' + esc(l.etel_id) + '</code></td><td class="mono">' + esc(l.phone) + '</td><td>' + esc(l.company_slug) + '</td><td><span class="ec-pill active">' + esc(l.role_at) + '</span></td><td><button class="btn btn-danger btn-sm" data-rm="' + l.id + '">Remove</button></td></tr>'; }).join('') + '</tbody></table></div>' : '<div class="ec-empty">No company roles assigned yet.</div>';
    VIEW.innerHTML = '<div class="ec-card"><h2>➕ Assign a role</h2><div class="desc">Link a member to a company as admin, manager, staff… This is who receives payments and payouts for that company.</div><div class="ec-form">' +
      '<div class="ec-field"><label>Member Etel-ID or phone</label><input class="ec-in" id="cr-id" placeholder="EG-2026-00001 or +2519…"></div>' +
      '<div class="ec-field"><label>Company</label><input class="ec-in" id="cr-slug" list="cr-slugs" placeholder="delivery, gebeya, pay…"><datalist id="cr-slugs">' + slugs.map(function (s) { return '<option value="' + esc(s) + '">'; }).join('') + '</datalist></div>' +
      '<div class="ec-field"><label>Role</label><input class="ec-in" id="cr-role" list="cr-roles" placeholder="Admin, Manager…"><datalist id="cr-roles">' + ['Admin', 'Owner', 'Manager', 'Dispatcher', 'Finance', 'Support', 'Traffic Officer'].map(function (s) { return '<option value="' + s + '">'; }).join('') + '</datalist></div>' +
      '<div class="ec-field"><button class="btn btn-primary" id="cr-go">Assign role</button></div></div></div><div class="ec-card"><h2>🏢 Current roles</h2><div style="margin-top:12px">' + rows + '</div></div>';
    $('cr-go').addEventListener('click', function () { api('etelid_panel_company_roles_set', { member_identifier: $('cr-id').value.trim(), company_slug: $('cr-slug').value.trim(), role_at: $('cr-role').value.trim() }).then(function (x) { if (x.success) { toast(x.data.message || 'Saved'); VIEWS.roles(); } else toast(err(x), true); }); });
  });
};
document.addEventListener('click', function (e) { var b = e.target.closest && e.target.closest('[data-rm]'); if (!b) return; if (!confirm('Remove this role?')) return; api('etelid_panel_company_roles_remove', { id: b.getAttribute('data-rm') }).then(function (x) { if (x.success) { toast('Removed'); VIEWS.roles(); } else toast(err(x), true); }); });

/* ---------- audit ---------- */
var AU = { page: 1, q: '' };
VIEWS.audit = function () {
  VIEW.innerHTML = '<div class="ec-card"><div class="ec-tools"><input class="ec-search" id="au-q" placeholder="Filter by Etel-ID…" value="' + esc(AU.q) + '"></div><div id="au-body"><div class="ec-loading">Loading…</div></div></div>';
  var t; $('au-q').addEventListener('input', function (e) { clearTimeout(t); t = setTimeout(function () { AU.q = e.target.value.trim(); AU.page = 1; loadAudit(); }, 300); });
  loadAudit();
};
function loadAudit() {
  api('etelid_panel_audit_list', { page: AU.page, q: AU.q }).then(function (r) {
    var el = $('au-body'); if (!el) return;
    if (!r.success) { el.innerHTML = '<div class="ec-empty">' + esc(err(r)) + '</div>'; return; }
    var rows = r.data.entries; if (!rows.length) { el.innerHTML = '<div class="ec-empty">No log entries found.</div>'; return; }
    el.innerHTML = '<div class="ec-tablewrap"><table class="ec-table"><thead><tr><th>Etel-ID</th><th>Event</th><th>IP</th><th>Detail</th><th>Time</th></tr></thead><tbody>' + rows.map(function (e) { return '<tr><td><code>' + esc(e.etel_id) + '</code></td><td><span class="ec-pill tier">' + esc(e.event) + '</span></td><td class="mono">' + esc(e.ip) + '</td><td>' + esc(e.detail) + '</td><td class="mono">' + esc(e.time) + '</td></tr>'; }).join('') + '</tbody></table></div>' + pager(r.data.page, r.data.pages, r.data.total);
  });
}

/* ---------- tools ---------- */
VIEWS.tools = function () {
  var W = C.wp, T = function (ic, l, s, href) { return '<a class="ec-tile" href="' + esc(href) + '"><i>' + ic + '</i>' + l + '<small>' + s + '</small></a>'; };
  VIEW.innerHTML = '<div class="ec-card"><h2>🧰 Identity tools</h2><div class="desc">These open the full Etel-ID tools in WordPress. The day-to-day work (members, roles, audit) is right here in the console.</div><div class="ec-tiles">' +
    T('➕', 'Issue new ID', 'Create a card', W.issue) + T('🪪', 'Fayda quick register', 'Register with ID photos', W.fayda) + T('🖨', 'View & print', 'Card preview and print', W.print) +
    T('🔏', 'Biometric', 'Face / fingerprint', W.biometric) + T('📋', 'Check-in log', 'Scan history', W.checkin) + T('🔐', 'Password reset', 'Bulk and manual', W.password) + '</div></div>' +
    '<div class="ec-card"><h2>⚙️ System</h2><div class="ec-tiles" style="margin-top:12px">' + T('⚙️', 'Settings', 'Organisation, apps, SMTP', W.settings) + T('🔄', 'Updater', 'Plugin versions', W.updater) + T('🔳', 'QR cache', 'Backfill QR images', W.qr) + T('🧑‍💼', 'WordPress members', 'Classic member list', W.members) + '</div></div>' +
    '<div class="ec-card"><h2>🔗 Other consoles</h2><div class="ec-tiles" style="margin-top:12px">' + T('👤', 'My portal', 'Member view', C.portal) + T('🏢', 'Group admin', 'Companies & messages', '/admin') + T('🚚', 'Delivery admin', 'Rides & deliveries', '/delivery/admin.html') + T('🤝', 'Investor admin', 'Profit-share desk', '/EtelGroup/investors/admin.html') + '</div></div>';
};

/* boot */
var h = (location.hash || '').replace('#', ''); show(VIEWS[h] ? h : 'overview');
})();
