/* ETEL Admin - Roles & Permissions.
   Each role is a card that says, in plain words, what its holders can do (grouped by area, with View / Manage chips) and
   flags the powerful ones. Editing opens a side panel with a permission matrix: one row per area with a View and a Manage
   switch (turning Manage on also turns View on; turning View off turns Manage off), starting-point templates
   (Dispatcher, Support agent, Finance, Viewer), an "unrestricted" switch that dims the matrix, and a confirmation before
   saving a role that includes owner-level or danger permissions. New roles are created from the same panel.
   Data (owner only, AdminController.php): admin_list_role_permissions, admin_update_role_permissions, admin_delete_role_permissions.
   Standalone: replaces loadRolesPanel() / editRolePermissions() so admin-roles.js is not edited (its sign-in menu hiding keeps working).
   Every server value goes through AdminKit.esc; no inline handlers. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;
/* area: [label, icon, hint, viewCap, manageCap] */
var AREAS = [
  ['Orders', '🧾', 'Delivery orders, dispatch, cancellations', 'orders.view', 'orders.manage'],
  ['Rides', '🚕', 'Trips, the review desk, driver checks', 'rides.view', 'rides.manage'],
  ['Users & drivers', '👥', 'Accounts, couriers, drivers, approvals', 'users.view', 'users.manage'],
  ['Service givers', '🏪', 'Shops and businesses', 'service_givers.view', 'service_givers.manage'],
  ['Items & menus', '🏷️', 'Products, prices, menu catalog', 'items.view', 'items.manage'],
  ['Zones, tiers & settings', '⚙️', 'Fares, zones, delivery tiers, announcements', 'settings.view', 'settings.manage'],
  ['Bank accounts', '🏦', 'Where customers pay in', 'finance.view', 'finance.manage'],
  ['SOS alerts', '🆘', 'Safety alerts from riders and drivers', 'sos.view', 'sos.manage'],
  ['Analytics', '📊', 'Reports and charts', 'analytics.view', ''],
  ['Equity & investors', '📈', 'Cap table, round settings, distributions', 'equity.view', 'equity.manage'],
  ['Platform economics', '💹', 'Real revenue against cost', 'platform_economics.view', 'platform_economics.manage'],
  ['Business plan', '🗺️', 'Hiring plan, capital requirement', 'planning.view', 'planning.manage'],
  ['Equb', '🤝', 'Equb groups and rounds', 'equb.view', 'equb.manage'],
  ['Documents', '📄', 'The documents library', 'documents.view', 'documents.manage']
];
var SPECIAL = [
  ['owner.only', '🔑', 'Owner-level tools', 'Code audit, migrations, this Roles page, updater, releases, Etel Pay switches', true],
  ['system.reset', '☠️', 'Full system reset', 'Permanently deletes real data. Almost nobody should have this.', true]
];
var TEMPLATES = [
  ['Dispatcher', '🧭', ['orders.view', 'orders.manage', 'rides.view', 'users.view', 'service_givers.view']],
  ['Support agent', '🎧', ['orders.view', 'rides.view', 'users.view', 'service_givers.view', 'sos.view', 'sos.manage']],
  ['Finance', '💼', ['finance.view', 'finance.manage', 'orders.view', 'analytics.view', 'settings.view']],
  ['Viewer', '👀', ['orders.view', 'rides.view', 'users.view', 'service_givers.view', 'items.view', 'analytics.view']]
];
var DANGER = { 'owner.only': 1, 'system.reset': 1, 'equity.manage': 1, 'finance.manage': 1 };
var LABELS = (typeof ROLE_CAP_LABELS !== 'undefined') ? ROLE_CAP_LABELS : {};
var V = { rows: [], loaded: false, busy: false, q: '' };

function root() { return document.getElementById('roles-pro'); }
function load(quiet) {
  var el = root(); if (!el || V.busy) return Promise.resolve();
  K.css(); V.busy = true;
  if (!quiet && !V.loaded) el.innerHTML = '<div class="ak">' + K.skeleton(3) + '</div>';
  return K.api('admin_list_role_permissions', {}).then(function (res) {
    V.busy = false;
    if (!K.okRes(res)) { el.innerHTML = '<div class="ak">' + K.empty('⚠️', 'Could not load roles', K.failText(res, 'Has the permissions migration run yet?')) + '</div>'; return; }
    V.rows = K.data(res).roles || []; V.loaded = true;
    try { ROLES_CACHE = V.rows; } catch (e) { /* admin-roles.js missing */ }
    paint();
  });
}

function level(caps) {
  if (caps.indexOf('*') >= 0) return ['Unrestricted', 'red'];
  if (!caps.length) return ['No access', 'grey'];
  return caps.some(function (c) { return /\.manage$|owner\.only|system\.reset/.test(c); }) ? ['Can change things', 'amber'] : ['View only', 'green'];
}
function summary(caps) {
  if (caps.indexOf('*') >= 0) return '<span class="ak-chip warn">Everything, with no limits</span>';
  var chips = '';
  AREAS.forEach(function (a) {
    var v = caps.indexOf(a[3]) >= 0, m = a[4] && caps.indexOf(a[4]) >= 0;
    if (v || m) chips += '<span class="ak-chip">' + esc(a[1]) + ' ' + esc(a[0]) + ' · <b>' + (m ? 'Manage' : 'View') + '</b></span>';
  });
  SPECIAL.forEach(function (s) { if (caps.indexOf(s[0]) >= 0) chips += '<span class="ak-chip warn">' + esc(s[1]) + ' ' + esc(s[2]) + '</span>'; });
  caps.forEach(function (c) { if (!known(c)) chips += '<span class="ak-chip">' + esc(LABELS[c] || c) + '</span>'; });
  return chips || '<span class="ak-chip">Nothing yet</span>';
}
function known(c) { return AREAS.some(function (a) { return a[3] === c || a[4] === c; }) || SPECIAL.some(function (s) { return s[0] === c; }) || c === '*'; }

function paint() {
  var el = root(); if (!el) return;
  var focus = document.activeElement && document.activeElement.id === 'rol-q';
  var q = V.q.toLowerCase(), rows = V.rows.filter(function (r) { return !q || String(r.role_name || '').toLowerCase().indexOf(q) >= 0; });
  var un = V.rows.filter(function (r) { return (r.capabilities || []).indexOf('*') >= 0; }).length;
  var h = '<div class="ak">' + K.hero('Roles & permissions', 'Staff who sign in with EtelID get the access of the role Etel Group gave them. Define here what each role name may see and do. A role that is not listed starts with view-only access to Orders and Rides.', [
      ['Roles', K.num(V.rows.length)], ['Unrestricted', K.num(un)], ['Areas you can control', K.num(AREAS.length + SPECIAL.length)]]) +
    '<div class="ak-tools"><label class="ak-search"><u>🔎</u><input id="rol-q" type="search" placeholder="Find a role…" value="' + esc(V.q) + '" autocomplete="off"></label><button type="button" class="ak-btn" data-act="refresh" aria-label="Refresh">🔄</button><button type="button" class="ak-btn gold" data-act="new">➕ New role</button></div>';
  h += rows.length ? '<div class="ak-grid">' + rows.map(card).join('') + '</div>' : K.empty('🔐', V.rows.length ? 'No role matches' : 'No roles yet', V.rows.length ? 'Try a different name.' : 'Use "New role" to add the first one.');
  el.innerHTML = h + '</div>';
  if (focus) { var i = document.getElementById('rol-q'); if (i) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); } }
}
function card(r) {
  var caps = r.capabilities || [], lv = level(caps);
  return '<div class="ak-card" style="cursor:default"><div class="ak-top"><div class="ak-av" style="--a:#E8C14A;--b:#9a7a1c">🔐</div><div class="ak-nm"><b>' + esc(r.role_name) + '</b><small>' + (r.updated_at ? 'Updated ' + esc(K.ago(r.updated_at)) : '') + '</small></div>' + K.pill(lv[0], lv[1]) + '</div>' +
    '<div class="ak-chips">' + summary(caps) + '</div><div class="ak-acts" style="margin-top:12px"><button type="button" class="ak-btn" data-edit="' + esc(r.id) + '">✏ Edit access</button><button type="button" class="ak-btn danger" data-del="' + esc(r.id) + '">🗑 Delete</button></div></div>';
}

/* ---------- editor ---------- */
function openEditor(r) {
  var edit = !!r, caps = r ? (r.capabilities || []).slice() : [], wild = caps.indexOf('*') >= 0;
  var st = { caps: {}, wild: wild };
  caps.forEach(function (c) { st.caps[c] = true; });
  var head = '<div class="ak-av lg" style="--a:#E8C14A;--b:#9a7a1c">🔐</div><div class="ak-nm"><b>' + (edit ? esc(r.role_name) : 'New role') + '</b><small>' + (edit ? 'Change what this role can do.' : 'Name it, pick a starting point, then adjust.') + '</small></div>';
  var body = '<div class="ak-sec">' + K.field('Role name (exactly as Etel Group assigns it)', '<input id="rl-name" class="ak-in" maxlength="80" placeholder="e.g. Dispatcher" value="' + esc(r ? r.role_name : '') + '"' + (edit ? ' readonly' : '') + '>', edit ? 'The name cannot be changed. To rename, make a new role and delete this one.' : 'Must match the role name in the Employees panel.') + '</div>' +
    '<div class="ak-sec"><h4>Starting points</h4><div class="ak-opts">' + TEMPLATES.map(function (t) { return '<button type="button" class="ak-opt" data-tpl="' + esc(t[0]) + '"><i>' + esc(t[1]) + '</i><b>' + esc(t[0]) + '</b></button>'; }).join('') + '</div><div class="ak-fh">A starting point only sets the switches below. You can change every one.</div></div>' +
    '<div class="ak-sec">' + K.sw('rl-wild', 'Unrestricted access — same as Owner and Admin (every permission, now and in future)', wild) + '</div>' +
    '<div class="ak-sec" id="rl-matrix"><h4>What this role can do</h4>' + AREAS.map(function (a) {
      return '<div class="ak-row" style="border-top:1px solid var(--line);padding:9px 0;margin:0"><span style="min-width:0"><b style="color:var(--ink)">' + esc(a[1]) + ' ' + esc(a[0]) + '</b><br><small>' + esc(a[2]) + '</small></span><span style="display:flex;gap:12px;flex:none">' +
        '<label class="ak-fl" style="display:flex;gap:5px;align-items:center;font-weight:600"><input type="checkbox" data-cap="' + esc(a[3]) + '" data-kind="view" style="width:auto;margin:0"> View</label>' +
        (a[4] ? '<label class="ak-fl" style="display:flex;gap:5px;align-items:center;font-weight:600"><input type="checkbox" data-cap="' + esc(a[4]) + '" data-kind="manage" data-pair="' + esc(a[3]) + '" style="width:auto;margin:0"> Manage</label>' : '') + '</span></div>';
    }).join('') + '<h4 style="margin-top:16px">Owner-level and danger</h4>' + SPECIAL.map(function (s) {
      return '<div class="ak-row" style="border-top:1px solid var(--line);padding:9px 0;margin:0"><span style="min-width:0"><b style="color:var(--red)">' + esc(s[1]) + ' ' + esc(s[2]) + '</b><br><small>' + esc(s[3]) + '</small></span><label class="ak-fl" style="display:flex;gap:5px;align-items:center;font-weight:600;flex:none"><input type="checkbox" data-cap="' + esc(s[0]) + '" style="width:auto;margin:0"> Allow</label></div>';
    }).join('') + '</div>';
  var dr = K.openDrawer(head, body, '<span class="ak-err" id="rl-err" role="alert"></span><button type="button" class="ak-btn" data-close>Cancel</button><button type="button" class="ak-btn gold" data-save>' + (edit ? 'Save access' : 'Create role') + '</button>');
  function sync() {
    var m = dr.querySelector('#rl-matrix'); m.style.opacity = st.wild ? '.35' : '1'; m.style.pointerEvents = st.wild ? 'none' : '';
    Array.prototype.forEach.call(dr.querySelectorAll('[data-cap]'), function (cb) { cb.checked = !!st.caps[cb.getAttribute('data-cap')]; });
    dr.querySelector('#rl-wild').checked = st.wild;
  }
  dr.addEventListener('change', function (e) {
    var t = e.target;
    if (t.id === 'rl-wild') { st.wild = t.checked; sync(); return; }
    var cap = t.getAttribute && t.getAttribute('data-cap'); if (!cap) return;
    st.caps[cap] = t.checked;
    if (t.getAttribute('data-kind') === 'manage' && t.checked) st.caps[t.getAttribute('data-pair')] = true;
    if (t.getAttribute('data-kind') === 'view' && !t.checked) { AREAS.forEach(function (a) { if (a[3] === cap && a[4]) st.caps[a[4]] = false; }); }
    sync();
  });
  dr.addEventListener('click', function (e) {
    var tp = e.target.closest ? e.target.closest('[data-tpl]') : null;
    if (tp) { var n = tp.getAttribute('data-tpl'); TEMPLATES.forEach(function (t) { if (t[0] === n) { st.wild = false; st.caps = {}; t[2].forEach(function (c) { st.caps[c] = true; }); var ni = dr.querySelector('#rl-name'); if (!edit && !ni.value.trim()) ni.value = n; sync(); } }); return; }
    if (e.target.closest && e.target.closest('[data-save]')) save(r, st, dr);
  });
  sync();
  var f = dr.querySelector('#rl-name'); if (f && !edit) f.focus();
}
function save(r, st, dr) {
  var err = dr.querySelector('#rl-err'), name = dr.querySelector('#rl-name').value.trim();
  if (!name) { err.textContent = 'Enter the role name first.'; dr.querySelector('#rl-name').focus(); return; }
  var caps = st.wild ? ['*'] : Object.keys(st.caps).filter(function (c) { return st.caps[c]; });
  var risky = st.wild || caps.some(function (c) { return DANGER[c]; });
  var go = function () {
    var btn = dr.querySelector('[data-save]'); btn.disabled = true; err.textContent = '';
    K.api('admin_update_role_permissions', { role_name: name, capabilities: caps }).then(function (res) {
      btn.disabled = false;
      if (K.okRes(res)) { K.closeDrawer(); K.toast('Role saved'); V.busy = false; load(true); } else err.textContent = K.failText(res, 'Could not save the role.');
    });
  };
  if (risky) K.confirm({ title: 'Give "' + name + '" powerful access?', text: st.wild ? 'Unrestricted means every permission, including money, owner tools and the system reset.' : 'This role includes owner-level, danger or money-changing permissions.', label: 'Yes, save it', danger: true }).then(function (ok) { if (ok) go(); });
  else if (!caps.length) K.confirm({ title: 'Save with no access at all?', text: 'People with this role will not be able to open anything.', label: 'Save anyway' }).then(function (ok) { if (ok) go(); });
  else go();
}

var search = K.debounce(function () { paint(); }, 200);
function wire(el) {
  if (el._rolWired) return; el._rolWired = true;
  el.addEventListener('click', function (e) {
    var t = e.target, b;
    if (t.closest('[data-act="refresh"]')) { load(true); return; }
    if (t.closest('[data-act="new"]')) { openEditor(null); return; }
    if ((b = t.closest('[data-edit]'))) { var r = find(b.getAttribute('data-edit')); if (r) openEditor(r); return; }
    if ((b = t.closest('[data-del]'))) {
      var d = find(b.getAttribute('data-del')); if (!d) return;
      K.confirm({ title: 'Delete the "' + d.role_name + '" role?', text: 'Anyone with this exact role name falls back to view-only access on their next sign-in.', label: 'Delete role', danger: true }).then(function (ok) {
        if (ok) K.api('admin_delete_role_permissions', { id: parseInt(d.id, 10) }).then(function (res) { if (K.okRes(res)) { K.toast('Role deleted'); V.busy = false; load(true); } else K.toast(K.failText(res), true); });
      });
    }
  });
  el.addEventListener('input', function (e) { if (e.target.id === 'rol-q') { V.q = e.target.value.trim(); search(); } });
}
function find(id) { var f = null; V.rows.forEach(function (r) { if (String(r.id) === String(id)) f = r; }); return f; }

function install() {
  var el = root(); if (!el) return;
  wire(el);
  window.loadRolesPanel = function () { return load(V.loaded); };
  window.editRolePermissions = function (i) { openEditor(i === null || i === undefined ? null : V.rows[i] || null); };
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
