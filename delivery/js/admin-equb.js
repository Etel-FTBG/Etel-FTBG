/* ============================================================
   ETEL Delivery — Equb admin oversight tab
   Reuses the .eq-* premium CSS classes injected by admin-equity.js
   (loaded first) under the broadened :is(#panel-equity,
   #panel-platform-econ) scope - extending that scope once more here.
   ============================================================ */

function eqbFmt(n) { return 'ETB ' + Math.round(Number(n) || 0).toLocaleString(); }
function eqbEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }

async function loadEqubAdminPanel() {
  if (typeof ensureEquityPremiumCss === 'function') ensureEquityPremiumCss();
  const el = document.getElementById('eqb-body');
  if (!el) return;
  el.innerHTML = `
    <div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap">
      <button class="eq-btn primary" data-eqb-tab="groups" onclick="switchEqbAdminTab('groups', this)">Groups</button>
      <button class="eq-btn" data-eqb-tab="packages" onclick="switchEqbAdminTab('packages', this)">Packages</button>
      <button class="eq-btn" data-eqb-tab="participants" onclick="switchEqbAdminTab('participants', this)">Participants</button>
      <button class="eq-btn" style="margin-left:auto;background:linear-gradient(135deg,#5aa9ff,#1f6fd1);color:#fff;border:none" onclick="openCreateEqubGroupModal()">➕ Create Custom Group</button>
    </div>
    <div id="eqb-tab-body"></div>
  `;
  switchEqbAdminTab('groups');
}

function switchEqbAdminTab(tab, btnEl) {
  document.querySelectorAll('[data-eqb-tab]').forEach(b => b.classList.remove('primary'));
  if (btnEl) btnEl.classList.add('primary');
  else { const m = document.querySelector(`[data-eqb-tab="${tab}"]`); if (m) m.classList.add('primary'); }
  if (tab === 'groups') loadEqubGroups();
  else if (tab === 'packages') loadEqubPackagesAdmin();
  else loadEqubParticipants();
}

const EQB_TIER_STYLE = {
  bronze:   { grad: 'linear-gradient(135deg,#CD7F32,#8B5A2B)', glow: 'rgba(205,127,50,.4)',  label: 'BRONZE' },
  silver:   { grad: 'linear-gradient(135deg,#E8E8E8,#A8A8A8)', glow: 'rgba(200,200,210,.4)', label: 'SILVER' },
  gold:     { grad: 'linear-gradient(135deg,#F2CC6B,#C8960C)', glow: 'rgba(242,204,107,.45)', label: 'GOLD' },
  platinum: { grad: 'linear-gradient(135deg,#E0F7FF,#7DC8E8 45%,#3E7C99)', glow: 'rgba(125,200,232,.45)', label: 'PLATINUM' },
};

async function loadEqubPackagesAdmin() {
  const el = document.getElementById('eqb-tab-body');
  el.innerHTML = '<div class="eq-empty">Loading…</div>';
  const res = await api('equb_admin_list_packages');
  if (!res.success) { el.innerHTML = `<div class="eq-card" style="color:#ff9aa4">${eqbEsc(res.message || 'Could not load — has migration 042 been applied?')}</div>`; return; }
  const packages = res.data.packages;
  let html = `<div style="display:flex;justify-content:flex-end;margin-bottom:14px">
    <button class="eq-btn primary" onclick="openCreateEqubPackageModal()">➕ New Package</button>
  </div>`;
  if (!packages.length) {
    html += '<div class="eq-card eq-empty"><span class="eq-emoji">📦</span>No packages yet — create tiers users can pick from based on what they can afford.</div>';
  } else {
    html += `<div class="eq-grid" style="grid-template-columns:repeat(auto-fill,minmax(230px,1fr))">
      ${packages.map(p => {
        const t = EQB_TIER_STYLE[p.tier] || EQB_TIER_STYLE.bronze;
        return `<div class="eq-card" style="margin-bottom:0;opacity:${p.active == 1 ? 1 : .45}">
          <div style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:10px;font-weight:800;letter-spacing:.06em;background:${t.grad};color:#0d0d0d;box-shadow:0 4px 14px ${t.glow};margin-bottom:10px">${t.label}</div>
          <div style="font-size:16px;font-weight:700;color:#f8f1e2;margin-bottom:4px">${eqbEsc(p.name)}</div>
          <div style="font-size:12px;color:#8a7f66;margin-bottom:12px">${eqbEsc(p.description || '')}</div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:20px;font-weight:700;background:${t.grad};-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:4px">${eqbFmt(p.contribution_amount)}</div>
          <div style="font-size:11px;color:#8a7f66;margin-bottom:12px">per ${eqbEsc(p.frequency)} · up to ${p.max_members} members · ${eqbEsc(p.groups_spawned)} group(s) spawned</div>
          <button class="eq-btn ${p.active == 1 ? 'danger' : ''}" style="width:100%;font-size:12px;padding:9px" onclick="toggleEqubPackage(${p.id}, ${p.active == 1 ? 0 : 1})">${p.active == 1 ? 'Deactivate' : 'Reactivate'}</button>
        </div>`;
      }).join('')}
    </div>`;
  }
  el.innerHTML = html;
}

function openCreateEqubPackageModal() {
  openModal('custom', eqModalWrap(`
      <h2 style="margin-bottom:16px;color:#F2CC6B;font-family:'Syne',sans-serif">📦 New Equb Package</h2>
      <label>Package name</label>
      <input id="eqb-p-name" placeholder="e.g. Starter Saver">
      <label>Tier</label>
      <select id="eqb-p-tier">
        <option value="bronze">🥉 Bronze — entry level</option>
        <option value="silver">🥈 Silver — mid tier</option>
        <option value="gold">🥇 Gold — higher commitment</option>
        <option value="platinum">💎 Platinum — premium</option>
      </select>
      <label>Description (shown to users)</label>
      <input id="eqb-p-desc" placeholder="e.g. Great for getting started">
      <label>Contribution amount per round (ETB)</label>
      <input id="eqb-p-amount" type="number" min="1" placeholder="e.g. 200">
      <label>Frequency</label>
      <select id="eqb-p-freq"><option value="weekly">Weekly</option><option value="biweekly">Biweekly</option><option value="monthly">Monthly</option></select>
      <label>Max members per group</label>
      <input id="eqb-p-max" type="number" min="2" max="50" placeholder="e.g. 10">
      <label>Payout order</label>
      <select id="eqb-p-order"><option value="lottery">Random lottery (fair, no favoritism)</option><option value="join_order">Join order (first to join, first paid)</option></select>
      <button class="eq-btn primary" style="width:100%;margin-top:6px" onclick="createEqubPackage()">Create Package</button>
  `));
}

async function createEqubPackage() {
  const payload = {
    name: document.getElementById('eqb-p-name').value.trim(),
    tier: document.getElementById('eqb-p-tier').value,
    description: document.getElementById('eqb-p-desc').value.trim(),
    contribution_amount: Number(document.getElementById('eqb-p-amount').value),
    frequency: document.getElementById('eqb-p-freq').value,
    max_members: Number(document.getElementById('eqb-p-max').value),
    payout_order: document.getElementById('eqb-p-order').value,
  };
  if (!payload.name || !payload.contribution_amount || !payload.max_members) { alert('Fill in all fields'); return; }
  const res = await api('equb_admin_create_package', payload);
  if (!res.success) { alert(res.message || 'Could not create package'); return; }
  closeModal();
  loadEqubPackagesAdmin();
}

async function toggleEqubPackage(id, active) {
  const res = await api('equb_admin_update_package', { id, active });
  if (!res.success) { alert(res.message || 'Could not update'); return; }
  loadEqubPackagesAdmin();
}

async function loadEqubGroups() {
  const el = document.getElementById('eqb-tab-body');
  el.innerHTML = '<div class="eq-empty">Loading…</div>';
  const res = await api('equb_admin_list_groups');
  if (!res.success) { el.innerHTML = `<div class="eq-card" style="color:#ff9aa4">${eqbEsc(res.message || 'Could not load — has migration 040 been applied?')}</div>`; return; }
  const groups = res.data.groups;
  if (!groups.length) { el.innerHTML = '<div class="eq-card eq-empty"><span class="eq-emoji">🤝</span>No Equb groups yet.</div>'; return; }
  el.innerHTML = `<div class="eq-card" style="overflow-x:auto"><table><thead><tr><th>Name</th><th>Creator</th><th>Contribution</th><th>Members</th><th>Round</th><th>Status</th><th>Next Due</th><th>Actions</th></tr></thead><tbody>
    ${groups.map(g => `<tr>
      <td class="hi">${eqbEsc(g.name)}</td>
      <td>${eqbEsc(g.creator_name)}</td>
      <td class="mono">${eqbFmt(g.contribution_amount)} / ${eqbEsc(g.frequency)}</td>
      <td class="mono">${g.members_count} / ${g.max_members}</td>
      <td class="mono">${g.current_round || '—'} / ${g.total_rounds || '—'}</td>
      <td><span class="eq-badge ${g.status === 'at_risk' ? 'unpaid' : g.status === 'active' ? 'paid' : g.status === 'completed' ? 'signed' : 'pending'}">${eqbEsc(g.status).replace('_',' ')}</span></td>
      <td class="mono">${eqbEsc(g.next_contribution_date || '—')}</td>
      <td style="white-space:nowrap">
        ${g.status === 'forming' ? `<button class="eq-btn" style="padding:7px 12px;font-size:12px" onclick="openAddEqubMemberModal(${g.id})">➕ Add</button> <button class="eq-btn primary" style="padding:7px 12px;font-size:12px" onclick="startEqubGroup(${g.id}, this)">🚀 Start</button>` : ''}
        ${g.status === 'active' || g.status === 'at_risk' ? `<button class="eq-btn" style="padding:7px 12px;font-size:12px" onclick="forceProcessEqubRound(${g.id}, this)">⚙️ Process Now</button>` : ''}
      </td>
    </tr>`).join('')}
  </tbody></table></div>`;
}

function openCreateEqubGroupModal() {
  openModal('custom', eqModalWrap(`
      <h2 style="margin-bottom:16px;color:#F2CC6B;font-family:'Syne',sans-serif">➕ Create Equb Group</h2>
      <label>Group name</label>
      <input id="eqb-c-name" placeholder="e.g. Merkato Traders Equb">
      <label>Contribution amount per round (ETB)</label>
      <input id="eqb-c-amount" type="number" min="1" placeholder="e.g. 500">
      <label>Frequency</label>
      <select id="eqb-c-freq"><option value="weekly">Weekly</option><option value="biweekly">Biweekly</option><option value="monthly">Monthly</option></select>
      <label>Max members</label>
      <input id="eqb-c-max" type="number" min="2" max="50" placeholder="e.g. 10">
      <label>Payout order</label>
      <select id="eqb-c-order"><option value="lottery">Random lottery (fair, no favoritism)</option><option value="join_order">Join order (first to join, first paid)</option></select>
      <button class="eq-btn primary" style="width:100%;margin-top:6px" onclick="createEqubGroup()">Create</button>
  `));
}

async function createEqubGroup() {
  const payload = {
    name: document.getElementById('eqb-c-name').value.trim(),
    contribution_amount: Number(document.getElementById('eqb-c-amount').value),
    frequency: document.getElementById('eqb-c-freq').value,
    max_members: Number(document.getElementById('eqb-c-max').value),
    payout_order: document.getElementById('eqb-c-order').value,
  };
  if (!payload.name || !payload.contribution_amount || !payload.max_members) { alert('Fill in all fields'); return; }
  const res = await api('equb_create_group', payload);
  if (!res.success) { alert(res.message || 'Could not create group'); return; }
  closeModal();
  loadEqubGroups();
}

function openAddEqubMemberModal(groupId) {
  openModal('custom', eqModalWrap(`
      <h2 style="margin-bottom:16px;color:#F2CC6B;font-family:'Syne',sans-serif">➕ Add Member</h2>
      <label>EtelID phone number (must already have Equb access granted)</label>
      <input id="eqb-add-phone" placeholder="Phone number">
      <button class="eq-btn primary" style="width:100%;margin-top:6px" onclick="addEqubMember(${groupId})">Add to Group</button>
  `));
}

async function addEqubMember(groupId) {
  const phone = document.getElementById('eqb-add-phone').value.trim();
  if (!phone) { alert('Enter a phone number'); return; }
  const res = await api('equb_admin_add_member', { group_id: groupId, phone });
  if (!res.success) { alert(res.message || 'Could not add member'); return; }
  closeModal();
  loadEqubGroups();
}

async function startEqubGroup(groupId, btnEl) {
  if (!confirm('Start this group now? Payout order will be assigned and contributions begin immediately. No new members can join after this.')) return;
  if (btnEl) { btnEl.disabled = true; btnEl.textContent = 'Starting…'; }
  const res = await api('equb_start_group', { group_id: groupId });
  if (!res.success) { alert(res.message || 'Could not start group'); if (btnEl) { btnEl.disabled = false; btnEl.textContent = '🚀 Start'; } return; }
  loadEqubGroups();
}

async function forceProcessEqubRound(groupId, btnEl) {
  if (!confirm('Manually process this group\'s current round right now? This will attempt to collect contributions from every member and pay out the recipient if everyone has paid — the same as what a scheduled cron run would do.')) return;
  if (btnEl) { btnEl.disabled = true; btnEl.textContent = 'Processing…'; }
  const res = await api('equb_admin_force_process_round', { group_id: groupId });
  if (!res.success) { alert(res.message || 'Could not process'); if (btnEl) { btnEl.disabled = false; btnEl.textContent = '⚙️ Process Now'; } return; }
  alert('Result:\n\n' + res.data.log.join('\n'));
  loadEqubGroups();
}

async function loadEqubParticipants() {
  const el = document.getElementById('eqb-tab-body');
  el.innerHTML = '<div class="eq-empty">Loading…</div>';
  const res = await api('equb_admin_list_participants');
  if (!res.success) { el.innerHTML = `<div class="eq-card" style="color:#ff9aa4">${eqbEsc(res.message || 'Could not load')}</div>`; return; }
  const participants = res.data.participants;
  el.innerHTML = `
    <div class="eq-card">
      <div class="eq-card-head"><h2>Grant Equb Access</h2></div>
      <div style="font-size:12px;color:#8a7f66;margin-bottom:12px">Enter a real EtelID phone number to permit that person to create or join Equb groups.</div>
      <div style="display:flex;gap:8px">
        <input id="eqb-grant-phone" placeholder="Phone number" style="flex:1;padding:11px 13px;border-radius:11px;background:linear-gradient(160deg, rgba(255,255,255,.05), rgba(255,255,255,.01));border:1px solid rgba(212,175,55,.3);color:#f8f1e2">
        <button class="eq-btn primary" onclick="grantEqubAccess()">✅ Grant</button>
      </div>
    </div>
    <div class="eq-card" style="overflow-x:auto">
      <div class="eq-card-head"><h2>Permitted Participants</h2></div>
      <table><thead><tr><th>Name</th><th>Phone</th><th>Status</th><th>Active Groups</th><th>Granted</th><th>Actions</th></tr></thead><tbody>
        ${participants.map(p => `<tr>
          <td class="hi">${eqbEsc(p.name)}</td>
          <td class="mono">${eqbEsc(p.phone)}</td>
          <td><span class="eq-badge ${p.status === 'permitted' ? 'paid' : 'unpaid'}">${eqbEsc(p.status)}</span></td>
          <td class="mono">${p.active_groups}</td>
          <td class="mono">${eqbEsc((p.permitted_at || '').slice(0,10))}</td>
          <td>${p.status === 'permitted' ? `<button class="eq-btn danger" style="padding:7px 12px;font-size:12px" onclick="revokeEqubAccess(${p.user_id})">Revoke</button>` : `<button class="eq-btn" style="padding:7px 12px;font-size:12px" onclick="regrantEqubAccess('${eqbEsc(p.phone)}')">Re-grant</button>`}</td>
        </tr>`).join('') || '<tr><td colspan="6" style="text-align:center;color:#8a7f66;padding:16px">No one has been granted access yet.</td></tr>'}
      </tbody></table>
    </div>
  `;
}

async function grantEqubAccess() {
  const phone = document.getElementById('eqb-grant-phone').value.trim();
  if (!phone) { alert('Enter a phone number'); return; }
  const res = await api('equb_admin_grant_access', { phone });
  if (!res.success) { alert(res.message || 'Could not grant access'); return; }
  loadEqubParticipants();
}

async function regrantEqubAccess(phone) {
  const res = await api('equb_admin_grant_access', { phone });
  if (!res.success) { alert(res.message || 'Could not re-grant'); return; }
  loadEqubParticipants();
}

async function revokeEqubAccess(userId) {
  if (!confirm('Revoke this person\'s Equb access? They will keep any groups they\'re already in, but cannot create or join new ones.')) return;
  const res = await api('equb_admin_revoke_access', { user_id: userId });
  if (!res.success) { alert(res.message || 'Could not revoke'); return; }
  loadEqubParticipants();
}

// Hide this tab for anyone without equb.view
(async function hideEqubNavIfNoCapability() {
  try {
    const res = await api('admin_get_my_capabilities');
    if (!res.success) return;
    const caps = res.data.capabilities || [];
    if (caps.includes('*') || caps.includes('equb.view')) return;
    const nav = document.getElementById('nav-item-equb');
    if (nav) nav.style.display = 'none';
  } catch (e) { /* fail open on network hiccup - server-side gating is the real boundary */ }
})();
