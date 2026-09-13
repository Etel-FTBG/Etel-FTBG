/* ============================================================
   ETEL Delivery — Roles & Permissions panel
   Lets the owner define what each EtelGroup-assigned staff role
   (Dispatcher, Support, Finance, ...) is actually allowed to do here,
   instead of every role collapsing into full admin access.
   ============================================================ */

const ROLE_CAP_LABELS = {
  'orders.view': 'View orders', 'orders.manage': 'Manage orders',
  'rides.view': 'View rides', 'rides.manage': 'Manage rides',
  'users.view': 'View users/drivers', 'users.manage': 'Create/manage users',
  'service_givers.view': 'View service givers', 'service_givers.manage': 'Manage service givers',
  'items.view': 'View items', 'items.manage': 'Manage items',
  'settings.view': 'View zones/tiers/settings', 'settings.manage': 'Manage zones/tiers/settings',
  'finance.view': 'View bank accounts', 'finance.manage': 'Manage bank accounts',
  'sos.view': 'View SOS alerts', 'sos.manage': 'Resolve SOS alerts',
  'analytics.view': 'View analytics',
  'owner.only': 'Owner-level tools (code audit, this Roles panel)',
  'equity.view': 'View Equity & Investors tab',
  'equity.manage': 'Manage investors, round settings, profit distributions',
  'system.reset': '☠️ DANGER: Full system reset (permanently deletes real data)',
  'platform_economics.view': 'View Platform Economics (real revenue vs cost)',
  'platform_economics.manage': 'Edit Platform Economics cost assumptions',
  'equb.view': 'View Equb groups (admin oversight)',
  'equb.manage': 'Grant/revoke Equb access, force-process rounds',
  'planning.view': 'View Business Plan & Capital Requirement',
  'planning.manage': 'Edit hiring plan and planning notes',
};

let ROLES_CACHE = [];

async function loadRolesPanel() {
  const el = document.getElementById('roles-list');
  el.innerHTML = '<div style="color:#B8B0A0;padding:20px">Loading…</div>';
  const res = await api('admin_list_role_permissions');
  if (!res.success) {
    el.innerHTML = `<div style="color:#e57373;padding:20px">${esc(res.message || 'Could not load roles')}</div>`;
    return;
  }
  ROLES_CACHE = res.data.roles;
  if (!ROLES_CACHE.length) {
    el.innerHTML = '<div style="color:#B8B0A0;padding:20px">No roles configured yet. Add one above.</div>';
    return;
  }
  el.innerHTML = ROLES_CACHE.map((r, i) => `
    <div style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:16px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <div>
        <div style="font-weight:700;font-size:15px">${esc(r.role_name)}</div>
        <div style="font-size:12px;color:#B8B0A0;margin-top:3px">
          ${r.capabilities.includes('*') ? 'Unrestricted access' : (r.capabilities.map(c => esc(ROLE_CAP_LABELS[c] || c)).join(', ') || 'No permissions set')}
        </div>
      </div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-secondary" onclick="editRolePermissions(${i})">✏️ Edit</button>
        <button class="btn btn-secondary" style="color:#e57373" onclick="deleteRolePermissions(${r.id}, '${esc(r.role_name).replace(/'/g, "\\'")}')">🗑 Delete</button>
      </div>
    </div>
  `).join('');
}

function editRolePermissions(index) {
  const existing = index !== null ? ROLES_CACHE[index] : null;
  const roleName = existing ? existing.role_name : (document.getElementById('role-name-input').value.trim());
  if (!roleName) { alert('Enter a role name first (in the box above), or click Edit on an existing role.'); return; }
  const isWildcard = existing && existing.capabilities.includes('*');
  const allCaps = ['orders.view','orders.manage','rides.view','rides.manage','users.view','users.manage',
                    'service_givers.view','service_givers.manage','items.view','items.manage',
                    'settings.view','settings.manage','finance.view','finance.manage',
                    'sos.view','sos.manage','analytics.view','owner.only','equity.view','equity.manage','system.reset','platform_economics.view','platform_economics.manage','equb.view','equb.manage','planning.view','planning.manage'];
  const current = existing ? existing.capabilities : [];

  openModal('custom', `
    <h2 style="margin-bottom:16px">Permissions for "${esc(roleName)}"</h2>
    <label style="display:flex;align-items:center;gap:8px;margin-bottom:14px;font-weight:700;cursor:pointer">
      <input type="checkbox" id="role-wildcard" ${isWildcard ? 'checked' : ''} onchange="document.getElementById('role-cap-list').style.opacity=this.checked?'.35':'1'">
      Unrestricted access (same as Owner/Admin)
    </label>
    <div id="role-cap-list" style="${isWildcard ? 'opacity:.35' : ''};display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:18px">
      ${allCaps.map(c => `
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
          <input type="checkbox" class="role-cap-cb" value="${c}" ${current.includes(c) ? 'checked' : ''}>
          ${esc(ROLE_CAP_LABELS[c])}
        </label>
      `).join('')}
    </div>
    <input type="hidden" id="role-edit-name" value="${esc(roleName)}">
    <button class="btn btn-primary" onclick="saveRolePermissions()">💾 Save</button>
  `);
}

async function saveRolePermissions() {
  const roleName = document.getElementById('role-edit-name').value;
  const wildcard = document.getElementById('role-wildcard').checked;
  let caps = [];
  if (wildcard) {
    caps = ['*'];
  } else {
    document.querySelectorAll('.role-cap-cb:checked').forEach(cb => caps.push(cb.value));
  }
  const res = await api('admin_update_role_permissions', { role_name: roleName, capabilities: caps });
  if (!res.success) { alert(res.message || 'Could not save'); return; }
  closeModal();
  document.getElementById('role-name-input').value = '';
  loadRolesPanel();
}

async function deleteRolePermissions(id, roleName) {
  if (!confirm(`Delete the "${roleName}" role definition? Anyone with this exact role name will fall back to view-only access on their next sign-in.`)) return;
  const res = await api('admin_delete_role_permissions', { id });
  if (!res.success) { alert(res.message || 'Could not delete'); return; }
  loadRolesPanel();
}

function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}

// ── Hide nav tabs AND in-panel sections the current signed-in staff
// member can't use ───────────────────────────────────────────────
// Runs once on load; legacy/unrestricted accounts (capabilities
// includes '*') see everything, same as always. This is the single
// place nav-level and section-level visibility is decided for every
// role - other panels (admin-finance-hub.js, admin-equb.js, etc.)
// still do their OWN capability fetch+check for their own internal
// tables/buttons, which is fine and stays as defense-in-depth, but
// the nav tab and the big sub-sections inside composite panels
// (Settings, Pricing) must never even render for a role that can't
// use them - showing the tab and then dumping a wall of red "you
// don't have permission" text per section on click is the bug this
// fixes.
//
// window.ETEL_CAPS is exposed afterward so any other script can check
// capabilities synchronously without a second network round-trip.
window.ETEL_CAPS = null;
// A promise any module can await, instead of racing to read
// window.ETEL_CAPS directly - a plain variable is null until this
// resolves, and modules on the *default landing tab* (Rides) run their
// own init before this async fetch below finishes, so a synchronous
// read of window.ETEL_CAPS on page load was silently seeing null and
// failing open (firing the call anyway) even after gating was added.
let _etelCapsResolve;
window.ETEL_CAPS_PROMISE = new Promise(res => { _etelCapsResolve = res; });

function etelHasCap(caps, required) {
  if (!required) return true; // no capability required - always visible
  if (caps.includes('*')) return true;
  const list = Array.isArray(required) ? required : [required];
  return list.some(c => caps.includes(c));
}

(async function hideRestrictedNavForCurrentUser() {
  try {
    const res = await api('admin_get_my_capabilities');
    if (!res.success) { _etelCapsResolve([]); return; }
    const caps = res.data.capabilities || [];
    window.ETEL_CAPS = caps;
    _etelCapsResolve(caps);
    if (caps.includes('*')) return; // full access - nothing to hide

    // Injects a CSS rule strong enough to beat admin.html's mobile
    // media-query rule `.nav-item{display:inline-flex!important}`
    // (see @media max-width:768px in admin.css) - that rule's !important
    // wins over any inline style.display set from JS on this element,
    // which is exactly why nav tabs kept showing on phones even though
    // this same function's data-cap section-hiding (plain divs, no
    // competing !important rule) worked correctly. A two-class selector
    // has higher specificity than the single-class mobile rule, so an
    // !important rule here wins regardless of stylesheet load order.
    if (!document.getElementById('etel-cap-hide-style')) {
      const style = document.createElement('style');
      style.id = 'etel-cap-hide-style';
      style.textContent = '.nav-item.etel-cap-hidden{display:none!important}';
      document.head.appendChild(style);
    }

    // Every top-level nav tab, mapped to the capability (or list of
    // capabilities, any one of which is enough) that gates it.
    // 'dashboard' and 'settings' are intentionally absent - dashboard
    // is a safe landing page for any signed-in staff member, and
    // Settings' own Account/Printer/System-Info sections (own-account
    // self-service, nothing company-wide) stay visible for everyone;
    // Settings' sensitive sub-sections are gated individually below.
    const navMap = {
      orders: 'orders.view', users: 'users.view', couriers: 'users.view',
      servicegivers: 'service_givers.view', items: 'items.view',
      rides: ['rides.view', 'settings.view'],
      pricing: ['pricing.manage', 'settings.view', 'finance.view'],
      reports: 'analytics.view',
      telebirr: ['finance.view', 'finance.manage'],
      updater: 'owner.only',
      support: 'orders.view',
      launch: 'settings.view',
      releases: 'owner.only',
      branding: 'settings.manage',
      finance: ['finance.view', 'finance.manage'],
      equb: ['equb.view', 'equb.manage'],
      danger: 'system.reset',
    };
    Object.keys(navMap).forEach(page => {
      if (!etelHasCap(caps, navMap[page])) {
        const navEl = document.querySelector(`.nav-item[data-page="${page}"]`);
        if (navEl) navEl.classList.add('etel-cap-hidden');
      }
    });
    // Roles panel is owner-only (separate id, not a plain data-page lookup).
    const rolesNav = document.getElementById('nav-item-roles');
    if (rolesNav && !etelHasCap(caps, 'owner.only')) rolesNav.classList.add('etel-cap-hidden');

    // Section-level gating inside composite panels that mix sensitive
    // and universally-safe content (Settings, Pricing). Markup carries
    // data-cap="capability" or data-cap="cap1,cap2" (any one is enough).
    // These plain divs have no competing !important rule, so a normal
    // inline style is fine here.
    document.querySelectorAll('[data-cap]').forEach(el => {
      const required = el.getAttribute('data-cap').split(',').map(s => s.trim());
      if (!etelHasCap(caps, required)) el.style.display = 'none';
    });
  } catch (e) { _etelCapsResolve([]); /* fail open on network hiccup - server-side gating is the real boundary */ }
})();
