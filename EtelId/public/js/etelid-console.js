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
var TITLES = { overview: ['Dashboard', 'Your Etel-ID system at a glance'], members: ['Members', 'Search, review and manage every Etel-ID'], roles: ['Company roles', 'Who is admin or staff at each Etel company'], audit: ['Audit log', 'Every identity event, newest first'], tools: ['Other consoles', 'Jump to the rest of the Etel system'],
  issue: ['Issue new ID', 'Create a corporate Etel-ID card'], fayda: ['Fayda quick register', 'Register a person from their Fayda ID photos'], print: ['Print cards', 'Find a member and open the print-ready card'],
  biometric: ['Biometric', 'Device biometrics, NFC cards and two-step sign-in'], checkin: ['Check-in log', 'Fingerprint, face, NFC and device scans'], password: ['Password reset', 'Set a new password for a member'],
  settings: ['Settings', 'Organisation, ID rules, messaging and app links'], updater: ['Updater', 'Deploy a plugin patch (.zip)'], qr: ['QR cache', 'Pre-generate QR images so cards load instantly'] };
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
      '<div class="ec-hero-actions"><a class="btn btn-primary btn-sm" data-view="issue">+ Issue new ID</a><a class="btn btn-outline btn-sm" data-view="members">Open members</a></div></div></section>';
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
    '<a class="btn btn-primary btn-sm" data-view="issue">+ Issue ID</a></div><div id="ms-body"><div class="ec-loading">Loading…</div></div></div>';
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

/* ---------- other consoles ---------- */
VIEWS.tools = function () {
  var T = function (ic, l, s, href) { return '<a class="ec-tile" href="' + esc(href) + '"><i>' + ic + '</i>' + l + '<small>' + s + '</small></a>'; };
  VIEW.innerHTML = '<div class="ec-card"><h2>🔗 Other consoles</h2><div class="ec-tiles" style="margin-top:12px">' + T('👤', 'My portal', 'Member view', C.portal) + T('🏢', 'Group admin', 'Companies & messages', '/admin') + T('🚚', 'Delivery admin', 'Rides & deliveries', '/delivery/admin.html') + T('🤝', 'Investor admin', 'Profit-share desk', '/EtelGroup/investors/admin.html') + T('🧱', 'WordPress admin', 'Classic Etel-ID pages', C.wp.members) + '</div></div>';
};

/* ---------- shared form helpers ---------- */
function fld(id, label, type, ph, extra) { return '<div class="ec-field"><label for="' + id + '">' + label + '</label><input class="ec-in" id="' + id + '" type="' + (type || 'text') + '" placeholder="' + esc(ph || '') + '" ' + (extra || '') + '></div>'; }
function sel(id, label, opts, first) { return '<div class="ec-field"><label for="' + id + '">' + label + '</label><select class="ec-in" id="' + id + '">' + (first ? '<option value="">' + first + '</option>' : '') + opts.map(function (o) { return '<option value="' + esc(o[0]) + '">' + esc(o[1]) + '</option>'; }).join('') + '</select></div>'; }
function val(id) { var e = $(id); return e ? e.value.trim() : ''; }
function collect(ids) { var o = {}; ids.forEach(function (k) { o[k] = val('f-' + k); }); return o; }
function busy(btn, on, txt) { if (!btn) return; btn.disabled = on; if (txt) btn.dataset.t = btn.dataset.t || btn.textContent; btn.textContent = on ? (txt || 'Working…') : (btn.dataset.t || btn.textContent); }

/* ---------- issue new ID ---------- */
VIEWS.issue = function () {
  api('etelid_console_issue_options', {}).then(function (r) {
    if (!r.success) { VIEW.innerHTML = '<div class="ec-empty">' + esc(err(r)) + '</div>'; return; }
    var o = r.data;
    VIEW.innerHTML = '<div class="ec-card"><h2>🪪 Member details</h2><div class="desc">Fields marked * are required. The number is marked verified because staff issue it face to face.</div>' +
      '<div class="ec-sec">Personal</div><div class="ec-form">' + fld('f-full_name', 'Full name (Latin) *', 'text', 'e.g. Dagmawi Bekele') + fld('f-full_name_am', 'Full name (Amharic)', 'text', 'ዳግማዊ በቀለ') + fld('f-dob', 'Date of birth *', 'date') +
      sel('f-sex', 'Sex', [['M', 'Male'], ['F', 'Female']], '—') + sel('f-blood_type', 'Blood type', o.blood.map(function (b) { return [b, b]; }), '—') + fld('f-nationality', 'Nationality', 'text', 'Ethiopian') + fld('f-phone', 'Phone *', 'tel', '+251 9XX XXX XXX') + fld('f-email', 'Email', 'email', 'member@etelgroup.com') + '</div>' +
      '<div class="ec-sec">Address</div><div class="ec-form">' + sel('f-region', 'Region', o.regions.map(function (x) { return [x, x]; }), '—') + fld('f-zone', 'Zone / city') + fld('f-woreda', 'Woreda / sub-city') + '</div>' +
      '<div class="ec-sec">Company &amp; access</div><div class="ec-form">' + sel('f-primary_company_slug', 'Primary company', o.companies.map(function (c) { return [c.slug, c.name]; }), '') + fld('f-role', 'Role', 'text', 'Dispatcher, Manager…') + fld('f-department', 'Department') + fld('f-employee_number', 'Employee number') + sel('f-tier', 'Tier', o.tiers.map(function (t) { return [t.slug, t.label]; }), '') + '</div>' +
      '<div class="ec-field" style="margin-top:12px"><label for="f-notes">Notes</label><textarea class="ec-in" id="f-notes" rows="2"></textarea></div>' +
      '<div class="ec-actions"><button class="btn btn-primary" id="is-go">Issue Etel-ID</button></div><div id="is-out"></div></div>';
    $('f-nationality').value = 'Ethiopian';
    $('is-go').addEventListener('click', function () {
      var d = collect(['full_name', 'full_name_am', 'dob', 'sex', 'blood_type', 'nationality', 'phone', 'email', 'region', 'zone', 'woreda', 'primary_company_slug', 'role', 'department', 'employee_number', 'tier', 'notes']);
      busy(this, true, 'Issuing…'); var btn = this;
      api('etelid_console_issue', d).then(function (x) {
        busy(btn, false);
        if (!x.success) { toast(err(x), true); return; }
        $('is-out').innerHTML = '<div class="ec-ok">✅ <b>' + esc(x.data.name) + '</b> now has <code>' + esc(x.data.etel_id) + '</code>. <a href="' + esc(C.print + encodeURIComponent(x.data.etel_id)) + '/" target="_blank">Open the card →</a> · <a data-open="' + x.data.id + '" style="cursor:pointer;text-decoration:underline">View profile</a></div>';
        toast('Etel-ID issued'); ['full_name', 'full_name_am', 'dob', 'phone', 'email', 'zone', 'woreda', 'role', 'department', 'employee_number', 'notes'].forEach(function (k) { $('f-' + k).value = ''; });
      });
    });
  });
};

/* ---------- Fayda quick register ---------- */
var FY = { front: '', back: '' };
function readImage(file, cb) {
  var rd = new FileReader();
  rd.onload = function () { var img = new Image(); img.onload = function () { var M = 1400, k = Math.min(1, M / Math.max(img.width, img.height)), c = document.createElement('canvas'); c.width = Math.round(img.width * k); c.height = Math.round(img.height * k); c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); cb(c.toDataURL('image/jpeg', 0.82)); }; img.onerror = function () { toast('Could not read that image', true); }; img.src = rd.result; };
  rd.readAsDataURL(file);
}
VIEWS.fayda = function () {
  FY = { front: '', back: '' };
  api('etelid_console_issue_options', {}).then(function (r) {
    var o = r.success ? r.data : { companies: [], regions: [] };
    var shot = function (k, l) { return '<div class="ec-field"><label>' + l + '</label><label class="ec-shot" id="sh-' + k + '"><input type="file" accept="image/*" capture="environment" data-shot="' + k + '" hidden><span>📷 Tap to add photo</span></label></div>'; };
    VIEW.innerHTML = '<div class="ec-card"><h2>🆔 Fayda ID photos</h2><div class="desc">Photograph both sides of the person’s Fayda ID. The front photo becomes their profile picture. Then confirm the details below.</div><div class="ec-form">' + shot('front', 'Front of ID') + shot('back', 'Back of ID') + '</div>' +
      '<div class="ec-sec">Details</div><div class="ec-form">' + fld('f-full_name', 'Full name *') + fld('f-full_name_am', 'Full name (Amharic)') + fld('f-dob', 'Date of birth *', 'date') + sel('f-sex', 'Sex', [['M', 'Male'], ['F', 'Female']], '—') + fld('f-phone', 'Phone *', 'tel', '+251 9XX XXX XXX') + fld('f-email', 'Email', 'email') + fld('f-fayda_fin', 'Fayda FIN') + fld('f-nationality', 'Nationality', 'text', 'Ethiopian') +
      sel('f-region', 'Region', (o.regions || []).map(function (x) { return [x, x]; }), '—') + fld('f-zone', 'Zone / city') + fld('f-woreda', 'Woreda') + sel('f-primary_company_slug', 'Primary company', (o.companies || []).map(function (c) { return [c.slug, c.name]; }), '') + fld('f-role', 'Role') + fld('f-department', 'Department') + fld('f-employee_number', 'Employee number') +
      sel('f-notify_channel', 'Send login details by', [['auto', 'Automatic'], ['whatsapp', 'WhatsApp'], ['telegram', 'Telegram'], ['none', 'Do not send']], '') + '</div>' +
      '<div class="ec-actions"><button class="btn btn-primary" id="fy-go">Register member</button></div><div id="fy-out"></div></div>';
    $('f-nationality').value = 'Ethiopian';
    VIEW.addEventListener('change', function onch(e) {
      var inp = e.target; if (!inp || !inp.getAttribute || !inp.getAttribute('data-shot')) return;
      var k = inp.getAttribute('data-shot'); if (!inp.files[0]) return;
      readImage(inp.files[0], function (d) { FY[k] = d; $('sh-' + k).innerHTML = '<img src="' + d + '" alt=""><input type="file" accept="image/*" capture="environment" data-shot="' + k + '" hidden>'; });
    });
    $('fy-go').addEventListener('click', function () {
      var d = collect(['full_name', 'full_name_am', 'dob', 'sex', 'phone', 'email', 'fayda_fin', 'nationality', 'region', 'zone', 'woreda', 'primary_company_slug', 'role', 'department', 'employee_number', 'notify_channel']);
      if (!d.full_name || !d.dob || !d.phone) { toast('Name, date of birth and phone are required', true); return; }
      d.fayda_front_image = FY.front; d.fayda_back_image = FY.back; d.notes = '';
      busy(this, true, 'Registering…'); var btn = this;
      api('etelid_fayda_quick_register', d).then(function (x) {
        busy(btn, false);
        if (!x.success) { toast(err(x), true); return; }
        var n = x.data.notify || {}, h = '<div class="ec-ok">✅ <b>' + esc(x.data.etel_id) + '</b> registered.<br>Temporary password: <code>' + esc(x.data.temp_password) + '</code> (they must change it at first sign-in).';
        if (n.auto_sent) h += '<br>Login details were sent automatically.'; else { if (n.whatsapp_link) h += '<br><a href="' + esc(n.whatsapp_link) + '" target="_blank">Send via WhatsApp →</a>'; if (n.telegram_link) h += '<br><a href="' + esc(n.telegram_link) + '" target="_blank">Send via Telegram →</a>'; if (n.auto_error) h += '<br><small>' + esc(n.auto_error) + '</small>'; }
        $('fy-out').innerHTML = h + '</div>'; toast('Member registered');
      });
    });
  });
};

/* ---------- find a member (print cards / password reset) ---------- */
function picker(mode) {
  VIEW.innerHTML = '<div class="ec-card"><div class="ec-tools"><input class="ec-search" id="pk-q" placeholder="Search name, Etel-ID or phone…"></div><div id="pk-body"><div class="ec-empty">Type a name, Etel-ID or phone to find a member.</div></div></div>';
  var t; $('pk-q').addEventListener('input', function (e) { clearTimeout(t); var q = e.target.value.trim(); t = setTimeout(function () {
    if (q.length < 2) { $('pk-body').innerHTML = '<div class="ec-empty">Type at least 2 characters.</div>'; return; }
    api('etelid_panel_members_list', { page: 1, search: q }).then(function (r) {
      if (!r.success) { $('pk-body').innerHTML = '<div class="ec-empty">' + esc(err(r)) + '</div>'; return; }
      var rows = r.data.members || []; if (!rows.length) { $('pk-body').innerHTML = '<div class="ec-empty">No members found.</div>'; return; }
      $('pk-body').innerHTML = '<div class="ec-tablewrap"><table class="ec-table"><thead><tr><th>Etel-ID</th><th>Name</th><th>Status</th><th></th></tr></thead><tbody>' + rows.map(function (m) { return '<tr><td><code>' + esc(m.etel_id) + '</code></td><td class="ec-name"><b>' + esc(m.name) + '</b><small>' + esc(m.phone) + '</small></td><td><span class="ec-pill ' + esc(m.status) + '">' + esc(m.status) + '</span></td><td>' + (mode === 'print' ? '<a class="btn btn-primary btn-sm" target="_blank" href="' + esc(C.print + encodeURIComponent(m.etel_id)) + '/">🖨 Open card</a>' : '<button class="btn btn-primary btn-sm" data-open="' + m.id + '">🔑 Reset password</button>') + '</td></tr>'; }).join('') + '</tbody></table></div>';
    });
  }, 300); });
}
VIEWS.print = function () { picker('print'); };
VIEWS.password = function () { picker('password'); };

/* ---------- biometric ---------- */
VIEWS.biometric = function () {
  VIEW.innerHTML = '<div class="ec-card"><h2>🔏 Find a member</h2><div class="ec-tools"><input class="ec-search" id="bm-q" placeholder="Search name, Etel-ID or phone…"></div><div id="bm-list"></div></div><div id="bm-detail"></div>';
  var t; $('bm-q').addEventListener('input', function (e) { clearTimeout(t); var q = e.target.value.trim(); t = setTimeout(function () {
    if (q.length < 2) { $('bm-list').innerHTML = ''; return; }
    api('etelid_panel_biometric_search', { q: q }).then(function (r) {
      var rows = r.success ? r.data.members : []; $('bm-list').innerHTML = rows.length ? '<div class="ec-tablewrap"><table class="ec-table"><tbody>' + rows.map(function (m) { return '<tr class="click" data-bio="' + m.id + '"><td><code>' + esc(m.etel_id) + '</code></td><td class="ec-name"><b>' + esc(m.full_name) + '</b></td><td class="mono">' + esc(m.phone) + '</td></tr>'; }).join('') + '</tbody></table></div>' : '<div class="ec-empty">No members found.</div>';
    });
  }, 300); });
};
function loadBio(id) {
  var el = $('bm-detail'); if (!el) return; el.innerHTML = '<div class="ec-loading">Loading…</div>';
  api('etelid_panel_biometric_get', { member_id: id }).then(function (r) {
    if (!r.success) { el.innerHTML = '<div class="ec-empty">' + esc(err(r)) + '</div>'; return; }
    var d = r.data, m = d.member, w = d.webauthn || {};
    el.innerHTML = '<div class="ec-card"><h2>' + esc(m.full_name) + ' <code>' + esc(m.etel_id) + '</code></h2>' +
      '<div class="ec-kv" style="margin-top:12px"><div><small>Device biometric</small><span>' + (w.enrolled ? '✅ Enrolled · ' + num(w.sign_count) + ' sign-ins' : 'Not enrolled') + '</span></div><div><small>NFC card</small><span>' + (d.nfc_uid ? esc(d.nfc_uid) : 'Not linked') + '</span></div></div>' +
      '<div class="ec-actions">' + (w.enrolled ? '<button class="btn btn-danger btn-sm" data-wr="' + m.id + '">Revoke device biometric</button>' : '') + '<button class="btn btn-outline btn-sm" data-r2="' + m.id + '">Reset two-step sign-in</button></div>' +
      '<div class="ec-sec">Link NFC card</div><div class="ec-form"><div class="ec-field"><input class="ec-in" id="bm-uid" placeholder="NFC card UID (e.g. 04:A2:3B:…)" value="' + esc(d.nfc_uid || '') + '"></div><div class="ec-field"><button class="btn btn-primary" data-nfc="' + m.id + '">Save card</button></div></div>' +
      '<div class="ec-sec">Recent scans</div>' + (d.log && d.log.length ? '<div class="ec-tablewrap"><table class="ec-table"><thead><tr><th>Method</th><th>Result</th><th>Confidence</th><th>Where</th><th>Time</th></tr></thead><tbody>' + d.log.map(function (l) { return '<tr><td>' + esc(l.method) + '</td><td><span class="ec-pill ' + (l.result === 'success' ? 'active' : 'revoked') + '">' + esc(l.result) + '</span></td><td>' + esc(l.confidence) + '%</td><td>' + esc(l.location) + '</td><td class="mono">' + esc(l.created_at) + '</td></tr>'; }).join('') + '</tbody></table></div>' : '<div class="ec-empty" style="padding:14px">No scans yet.</div>') + '</div>';
  });
}
document.addEventListener('click', function (e) {
  var t = e.target.closest && e.target.closest('[data-bio],[data-wr],[data-r2],[data-nfc]'); if (!t) return;
  var id = parseInt(t.getAttribute('data-bio') || t.getAttribute('data-wr') || t.getAttribute('data-r2') || t.getAttribute('data-nfc'), 10);
  var done = function (x) { if (x.success) { toast(x.data.message || 'Done'); loadBio(id); } else toast(err(x), true); };
  if (t.hasAttribute('data-bio')) loadBio(id);
  else if (t.hasAttribute('data-wr')) { if (confirm('Revoke this member’s device biometric? They will need to register again.')) api('etelid_panel_biometric_webauthn_revoke', { member_id: id }).then(done); }
  else if (t.hasAttribute('data-r2')) { if (confirm('Reset two-step sign-in for this member?')) api('etelid_panel_reset_2fa', { member_id: id }).then(done); }
  else if (t.hasAttribute('data-nfc')) api('etelid_panel_biometric_nfc_save', { member_id: id, nfc_uid: val('bm-uid') }).then(done);
});

/* ---------- check-in log ---------- */
VIEWS.checkin = function () {
  api('etelid_console_checkin', {}).then(function (r) {
    if (!r.success) { VIEW.innerHTML = '<div class="ec-empty">' + esc(err(r)) + '</div>'; return; }
    var s = r.data.stats || {}, rows = r.data.rows || [];
    VIEW.innerHTML = '<div class="ec-stats">' + stat('gold', '📅', 'Today', num(s.today), 'Scans') + stat('', '🗓️', 'This week', num(s.week), 'Scans') + stat('', '✅', 'Success', num(s.success), 'All time') + stat('', '❌', 'Failed', num(s.failed), 'All time') + stat('', '🔏', 'Fingerprint', num(s.fingerprint), '') + stat('', '👤', 'Face', num(s.face), '') + stat('', '📡', 'NFC', num(s.nfc), '') + stat('', '🔑', 'Device', num(s.webauthn), '') + '</div>' +
      '<div class="ec-card"><h2>📋 Latest scans</h2><div style="margin-top:12px">' + (rows.length ? '<div class="ec-tablewrap"><table class="ec-table"><thead><tr><th>Etel-ID</th><th>Method</th><th>Result</th><th>Confidence</th><th>Time</th></tr></thead><tbody>' + rows.map(function (l) { return '<tr><td><code>' + esc(l.etel_id) + '</code></td><td>' + esc(l.method) + '</td><td><span class="ec-pill ' + (l.result === 'success' ? 'active' : 'revoked') + '">' + esc(l.result) + '</span></td><td>' + esc(l.confidence) + '%</td><td class="mono">' + esc(l.time) + '</td></tr>'; }).join('') + '</tbody></table></div>' : '<div class="ec-empty">No check-in events yet. Events appear here when members use NFC cards, fingerprint readers or the device terminal.</div>') + '</div></div>';
  });
};

/* ---------- QR cache ---------- */
VIEWS.qr = function () {
  VIEW.innerHTML = '<div class="ec-card"><h2>🔳 QR cache backfill</h2><div class="desc">Generates and stores the QR image for every member, so cards and the portal never wait on an outside service. Safe to run again at any time.</div><div class="ec-prog"><div id="qr-bar"></div></div><div id="qr-msg" class="desc" style="margin-top:8px"></div><button class="btn btn-primary" id="qr-go">Start backfill</button></div>';
  $('qr-go').addEventListener('click', function () {
    var btn = this; busy(btn, true, 'Running…');
    (function step(off, done) {
      api('etelid_console_qr_batch', { offset: off }).then(function (r) {
        if (!r.success) { toast(err(r), true); busy(btn, false); return; }
        var d = r.data, total = d.total || 1, pc = Math.min(100, Math.round(d.next_offset / total * 100)); done += d.processed;
        $('qr-bar').style.width = pc + '%'; $('qr-msg').textContent = pc + '% · ' + done + ' of ' + total + ' processed';
        if (d.finished) { toast('QR cache complete'); busy(btn, false); } else step(d.next_offset, done);
      });
    })(0, 0);
  });
};

/* ---------- settings ---------- */
VIEWS.settings = function () {
  api('etelid_console_settings_get', {}).then(function (r) {
    if (!r.success) { VIEW.innerHTML = '<div class="ec-empty">' + esc(err(r)) + '</div>'; return; }
    var s = r.data.settings;
    VIEW.innerHTML = '<div class="ec-card"><h2>🏛 Organisation</h2><div class="ec-form" style="margin-top:12px">' + fld('f-org_name', 'Organisation name') + fld('f-org_website', 'Website', 'url') + fld('f-org_email', 'Contact email', 'email') + fld('f-admin_phone', 'Admin phone') + '</div></div>' +
      '<div class="ec-card"><h2>🪪 ID rules</h2><div class="ec-form" style="margin-top:12px">' + fld('f-id_prefix', 'Etel-ID prefix', 'text', 'EG') + fld('f-expiry_years', 'Valid for (years)', 'number', '5', 'min="1"') + sel('f-require_approval', 'New applications', [['0', 'Approved automatically'], ['1', 'Need admin approval']], '') + '</div></div>' +
      '<div class="ec-card"><h2>📨 Credential delivery</h2><div class="desc">Used to send new members their login details. Leave a secret blank to keep the current one.</div><div class="ec-form">' + fld('f-tg_bot_username', 'Telegram bot username') + fld('f-tg_bot_token', 'Telegram bot token' + (s.tg_bot_token_set ? ' (set)' : ''), 'password', '', 'autocomplete="new-password"') + fld('f-wa_phone_id', 'WhatsApp phone ID') + fld('f-wa_token', 'WhatsApp token' + (s.wa_token_set ? ' (set)' : ''), 'password', '', 'autocomplete="new-password"') + '</div></div>' +
      '<div class="ec-card"><h2>🚀 Portal app links</h2><div class="ec-form" style="margin-top:12px">' + fld('f-driver_app_url', 'Driver app URL', 'url') + fld('f-ride_app_url', 'Rider app URL', 'url') + fld('f-customer_app_url', 'Customer app URL', 'url') + '</div></div>' +
      '<div class="ec-actions"><button class="btn btn-primary" id="st-go">Save settings</button><span class="desc" style="margin:0">Plugin v' + esc(r.data.version) + '</span></div>';
    ['org_name', 'org_website', 'org_email', 'admin_phone', 'id_prefix', 'expiry_years', 'tg_bot_username', 'wa_phone_id', 'driver_app_url', 'ride_app_url', 'customer_app_url'].forEach(function (k) { $('f-' + k).value = s[k] == null ? '' : s[k]; });
    $('f-require_approval').value = String(parseInt(s.require_approval, 10) || 0);
    $('st-go').addEventListener('click', function () {
      var d = collect(['org_name', 'org_website', 'org_email', 'admin_phone', 'id_prefix', 'expiry_years', 'require_approval', 'tg_bot_username', 'tg_bot_token', 'wa_phone_id', 'wa_token', 'driver_app_url', 'ride_app_url', 'customer_app_url']);
      busy(this, true, 'Saving…'); var btn = this;
      api('etelid_console_settings_save', d).then(function (x) { busy(btn, false); if (x.success) toast(x.data.message || 'Saved'); else toast(err(x), true); });
    });
  });
};

/* ---------- updater ---------- */
VIEWS.updater = function () {
  VIEW.innerHTML = '<div class="ec-card"><h2>🔄 Deploy a plugin patch</h2><div class="desc">Upload a patch <b>.zip</b> prepared for the Etel-ID plugin. Its files are placed into the plugin folder. Only the site admin can do this.</div>' +
    '<label class="ec-shot" id="up-drop" style="max-width:420px"><input type="file" id="up-file" accept=".zip,application/zip" hidden><span>📦 Choose patch .zip</span></label><div class="ec-actions"><button class="btn btn-primary" id="up-go" disabled>Deploy patch</button></div><div id="up-out"></div></div>';
  $('up-file').addEventListener('change', function () { var f = this.files[0]; $('up-drop').querySelector('span').textContent = f ? '📦 ' + f.name + ' (' + Math.round(f.size / 1024) + ' KB)' : '📦 Choose patch .zip'; $('up-go').disabled = !f; });
  $('up-go').addEventListener('click', function () {
    var f = $('up-file').files[0]; if (!f) return; if (!confirm('Deploy ' + f.name + ' to the live plugin?')) return;
    var btn = this; busy(btn, true, 'Deploying…');
    var fd = new FormData(); fd.append('action', 'etelid_portal_patch'); fd.append('nonce', C.patch_nonce); fd.append('patch_zip', f, f.name);
    var ck = document.cookie.split(';').map(function (c) { return c.trim(); }).filter(function (c) { return c.indexOf('etelid_session=') === 0; })[0];
    if (ck) fd.append('eid_token', decodeURIComponent(ck.split('=').slice(1).join('=')));
    fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      busy(btn, false); var o = $('up-out');
      o.innerHTML = '<div class="' + (j.success ? 'ec-ok' : 'ec-bad') + '">' + (j.success ? '✅ ' : '⚠️ ') + esc((j.data && j.data.message) || (j.success ? 'Deployed.' : 'Deploy failed.')) + '</div>';
      if (j.success) { toast('Patch deployed'); $('up-file').value = ''; btn.disabled = true; }
    }).catch(function () { busy(btn, false); toast('Network error', true); });
  });
};

/* boot */
var h = (location.hash || '').replace('#', ''); show(VIEWS[h] ? h : 'overview');
})();
