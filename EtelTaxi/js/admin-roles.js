/* ============================================================
   ETEL Taxi — Roles & Permissions panel
   Lets the super admin define what each EtelGroup-assigned staff role
   (Manager, Dispatcher, Finance, ...) is actually allowed to do here,
   instead of every role collapsing into full super_admin access.
   ============================================================ */

const TAXI_ROLE_CAP_LABELS = {
  'drivers.view': 'View drivers/officers', 'drivers.manage': 'Register drivers/officers',
  'associations.view': 'View associations', 'associations.manage': 'Manage associations',
  'payments.view': 'View payments', 'payments.manage': 'Verify/reject payments',
  'finance.view': 'View bank accounts', 'finance.manage': 'Manage bank accounts',
  'owner.only': 'Owner-level tools (this Roles panel)',
};

let TAXI_ROLES_CACHE = [];

function taxiRoleModalHtml(inner) {
  return `
    <div id="role-modal-bg" style="display:flex;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.6);align-items:center;justify-content:center;padding:20px" onclick="if(event.target.id==='role-modal-bg')closeRoleModal()">
      <div style="background:var(--bg2);border:1px solid var(--line2);border-radius:16px;padding:26px;width:100%;max-width:480px;max-height:85vh;overflow-y:auto;position:relative">
        <button onclick="closeRoleModal()" style="position:absolute;top:14px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-dim)">✕</button>
        ${inner}
      </div>
    </div>`;
}
function closeRoleModal() {
  const el = document.getElementById('role-modal-bg');
  if (el) el.remove();
}

async function loadRolesPanel() {
  const el = document.getElementById('roles-list');
  el.innerHTML = '<div style="color:var(--text-dim);padding:20px">Loading…</div>';
  const res = await taxiApi('admin_list_role_permissions', {}, 'admin');
  if (!res.ok) { el.innerHTML = `<div style="color:var(--red);padding:20px">${taxiEsc(res.error)}</div>`; return; }
  TAXI_ROLES_CACHE = res.data.roles;
  if (!TAXI_ROLES_CACHE.length) { el.innerHTML = '<div style="color:var(--text-dim);padding:20px">No roles configured yet. Add one above.</div>'; return; }
  el.innerHTML = TAXI_ROLES_CACHE.map((r, i) => `
    <div class="card" style="margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <div>
        <div style="font-weight:700;font-size:15px">${taxiEsc(r.role_name)}</div>
        <div style="font-size:12px;color:var(--text-dim);margin-top:3px">
          ${r.capabilities.includes('*') ? 'Unrestricted access' : (r.capabilities.map(c => taxiEsc(TAXI_ROLE_CAP_LABELS[c] || c)).join(', ') || 'No permissions set')}
        </div>
      </div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-outline" style="padding:6px 12px;font-size:12px" onclick="editRolePermissions(${i})">✏️ Edit</button>
        <button class="btn btn-outline" style="padding:6px 12px;font-size:12px;color:var(--red)" onclick="deleteRolePermissions(${r.id}, '${taxiEsc(r.role_name).replace(/'/g, "\\'")}')">🗑 Delete</button>
      </div>
    </div>
  `).join('');
}

function editRolePermissions(index) {
  const existing = index !== null ? TAXI_ROLES_CACHE[index] : null;
  const roleName = existing ? existing.role_name : document.getElementById('role-name-input').value.trim();
  if (!roleName) { alert('Enter a role name first (in the box above), or click Edit on an existing role.'); return; }
  const isWildcard = existing && existing.capabilities.includes('*');
  const allCaps = ['drivers.view', 'drivers.manage', 'associations.view', 'associations.manage',
                    'payments.view', 'payments.manage', 'finance.view', 'finance.manage', 'owner.only'];
  const current = existing ? existing.capabilities : [];

  document.body.insertAdjacentHTML('beforeend', taxiRoleModalHtml(`
    <h2 style="margin-bottom:16px;font-family:var(--f-display);font-size:17px">Permissions for "${taxiEsc(roleName)}"</h2>
    <label style="display:flex;align-items:center;gap:8px;margin-bottom:14px;font-weight:700;cursor:pointer">
      <input type="checkbox" id="role-wildcard" ${isWildcard ? 'checked' : ''} onchange="document.getElementById('role-cap-list').style.opacity=this.checked?'.35':'1'">
      Unrestricted access (same as Owner/Admin/Manager)
    </label>
    <div id="role-cap-list" style="${isWildcard ? 'opacity:.35' : ''};display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:18px">
      ${allCaps.map(c => `
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
          <input type="checkbox" class="role-cap-cb" value="${c}" ${current.includes(c) ? 'checked' : ''}>
          ${taxiEsc(TAXI_ROLE_CAP_LABELS[c])}
        </label>
      `).join('')}
    </div>
    <input type="hidden" id="role-edit-name" value="${taxiEsc(roleName)}">
    <button class="btn btn-primary" style="width:100%" onclick="saveRolePermissions()">💾 Save</button>
  `));
}

async function saveRolePermissions() {
  const roleName = document.getElementById('role-edit-name').value;
  const wildcard = document.getElementById('role-wildcard').checked;
  let caps = wildcard ? ['*'] : Array.from(document.querySelectorAll('.role-cap-cb:checked')).map(cb => cb.value);
  const res = await taxiApi('admin_update_role_permissions', { role_name: roleName, capabilities: caps }, 'admin');
  if (!res.ok) { alert(res.error || 'Could not save'); return; }
  closeRoleModal();
  document.getElementById('role-name-input').value = '';
  loadRolesPanel();
}

async function deleteRolePermissions(id, roleName) {
  if (!confirm(`Delete the "${roleName}" role definition? Anyone with this exact role name will fall back to view-only access on their next sign-in.`)) return;
  const res = await taxiApi('admin_delete_role_permissions', { id }, 'admin');
  if (!res.ok) { alert(res.error || 'Could not delete'); return; }
  loadRolesPanel();
}

// ── Hide nav items the current signed-in staff member can't use ──
async function hideRestrictedNavForCurrentTaxiUser() {
  try {
    const res = await taxiApi('admin_get_my_capabilities', {}, 'admin');
    if (!res.ok) { console.warn('[Roles] admin_get_my_capabilities failed:', res.error); return; }
    const caps = res.data.capabilities || [];
    if (caps.includes('*')) return;

    const navMap = { drivers: 'drivers.view', pending: 'payments.view', overdue: 'payments.view', bank: 'finance.view', associations: 'associations.view', officers: 'drivers.view' };
    Object.keys(navMap).forEach(page => {
      if (!caps.includes(navMap[page])) {
        const navEl = document.querySelector(`.side-link[data-panel="${page}"]`);
        if (navEl) navEl.style.display = 'none';
        else console.warn('[Roles] nav item not found for page:', page);
      }
    });
    const rolesNav = document.getElementById('nav-item-roles');
    if (rolesNav && !caps.includes('owner.only')) rolesNav.style.display = 'none';
  } catch (e) { console.error('[Roles] hideRestrictedNav failed:', e); }
}
// Run once immediately (script is at end of body, DOM should already be
// parsed by this point) and again after full page load as a fallback,
// in case anything above delays when the sidebar actually exists.
hideRestrictedNavForCurrentTaxiUser();
window.addEventListener('load', hideRestrictedNavForCurrentTaxiUser);
