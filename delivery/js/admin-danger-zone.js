/* ============================================================
   ETEL Delivery — Danger Zone: Full System Reset
   Every step here is deliberately gated: preview is read-only,
   backup must be downloaded before the confirm step even unlocks,
   and the final execute requires typing an exact phrase. The backend
   (SystemResetController.php) enforces the same sequence
   independently - this UI enforcement is a second layer, not the
   real security boundary.
   ============================================================ */

let DZ_KEEP_PHONES = [];
let DZ_BACKUP_TOKEN = null;
let DZ_PREVIEW_DONE = false;

function dzEsc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}

function loadDangerZone() {
  const el = document.getElementById('danger-zone-body');
  if (!el) return;
  DZ_KEEP_PHONES = [];
  DZ_BACKUP_TOKEN = null;
  DZ_PREVIEW_DONE = false;
  el.innerHTML = `
    <div style="background:#191926;border:1px solid rgba(255,93,108,.25);border-radius:12px;padding:18px;margin-bottom:16px">
      <label style="display:block;font-size:12px;color:#B8B0A0;margin-bottom:6px">Real users' phone numbers to KEEP (comma or newline separated)</label>
      <textarea id="dz-keep-phones" placeholder="e.g. 0911223344, 0922334455" style="width:100%;min-height:70px;padding:11px;border-radius:10px;border:1px solid rgba(255,93,108,.3);background:#0d0d14;color:#EDEDF5;box-sizing:border-box;font-family:monospace"></textarea>
      <div style="font-size:11.5px;color:#8a7f66;margin-top:6px">Everyone else's account will be permanently deleted. Leave blank to delete ALL user accounts including your own admin login — not recommended.</div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px">
      <button class="btn btn-secondary" onclick="dzRunPreview()">1️⃣ Preview (read-only)</button>
      <button class="btn btn-secondary" id="dz-backup-btn" onclick="dzRunBackup()" disabled>2️⃣ Download Backup</button>
      <button class="btn" id="dz-execute-btn" onclick="dzOpenConfirm()" disabled style="background:#ff5d6c;color:#fff;border:none">3️⃣ Execute Reset</button>
    </div>
    <div id="dz-preview-output"></div>
  `;
}

async function dzRunPreview() {
  const raw = document.getElementById('dz-keep-phones').value;
  DZ_KEEP_PHONES = raw.split(/[,\n]/).map(s => s.trim()).filter(Boolean);
  const out = document.getElementById('dz-preview-output');
  out.innerHTML = '<div style="color:#B8B0A0;padding:14px">Loading preview…</div>';
  const res = await api('system_reset_preview', { keep_phones: DZ_KEEP_PHONES });
  if (!res.success) {
    out.innerHTML = `<div style="color:#e57373;padding:14px">${dzEsc(res.message || 'Preview failed')}</div>`;
    return;
  }
  const d = res.data;
  DZ_PREVIEW_DONE = true;
  document.getElementById('dz-backup-btn').disabled = false;
  let html = `<div style="background:#191926;border-radius:12px;padding:16px;margin-bottom:14px">
    <div style="font-size:14px;color:#EDEDF5;margin-bottom:10px"><b>${d.total_users}</b> total user accounts —
      <b style="color:#3fce7a">${d.users_to_keep.length} will be kept</b>,
      <b style="color:#ff5d6c">${d.users_to_delete} will be deleted</b></div>`;
  if (d.users_to_keep.length) {
    html += '<table><thead><tr><th>Kept user</th><th>Phone</th><th>Role</th></tr></thead><tbody>' +
      d.users_to_keep.map(u => `<tr><td>${dzEsc(u.name)}</td><td class="mono">${dzEsc(u.phone)}</td><td>${dzEsc(u.role)}</td></tr>`).join('') +
      '</tbody></table>';
  }
  if (d.phones_not_found && d.phones_not_found.length) {
    html += `<div style="color:#ffce54;margin-top:10px;font-size:13px">⚠️ These phone numbers didn't match any account and won't keep anyone: ${d.phones_not_found.map(dzEsc).join(', ')}</div>`;
  }
  html += '</div>';
  html += '<div style="background:#191926;border-radius:12px;padding:16px"><div style="font-size:13px;color:#B8B0A0;margin-bottom:8px">Rows that would be deleted from each table:</div><table><thead><tr><th>Table</th><th>Rows</th></tr></thead><tbody>' +
    Object.entries(d.table_counts).map(([t, c]) => `<tr><td>${dzEsc(t)}</td><td class="mono">${c === null ? '(not present)' : c}</td></tr>`).join('') +
    '</tbody></table></div>';
  out.innerHTML = html;
}

async function dzRunBackup() {
  const btn = document.getElementById('dz-backup-btn');
  btn.disabled = true;
  btn.textContent = 'Downloading backup…';
  try {
    const token = Auth.getToken();
    const resp = await fetch(ETEL.api + '?action=system_reset_backup', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
      body: JSON.stringify({}),
    });
    if (!resp.ok) throw new Error('Backup request failed (HTTP ' + resp.status + ')');
    DZ_BACKUP_TOKEN = resp.headers.get('X-Backup-Token');
    const blob = await resp.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'etel-full-backup-' + Date.now() + '.zip';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
    if (DZ_BACKUP_TOKEN) {
      document.getElementById('dz-execute-btn').disabled = false;
      btn.textContent = '✓ Backup downloaded';
      alert('Backup downloaded. IMPORTANT: save this file somewhere safe (e.g. Google Drive) before proceeding - it is your only way to recover this data if the reset needs to be undone.');
    } else {
      btn.textContent = '2️⃣ Download Backup';
      btn.disabled = false;
      alert('Backup file downloaded but no token was returned - Execute will not unlock. Try again.');
    }
  } catch (e) {
    btn.textContent = '2️⃣ Download Backup';
    btn.disabled = false;
    alert('Backup failed: ' + e.message);
  }
}

function dzOpenConfirm() {
  if (!DZ_BACKUP_TOKEN) { alert('Download a backup first.'); return; }
  openModal('custom', `
    <h2 style="color:#ff5d6c;margin-bottom:10px">☠️ Final Confirmation</h2>
    <p style="font-size:13px;color:#B8B0A0;margin-bottom:14px">
      This will permanently delete ${dzEsc(String(document.querySelectorAll('#dz-preview-output tbody tr').length))} rows worth of data
      across the system, keeping only <b>${DZ_KEEP_PHONES.length}</b> user account(s).
      Your backup has been downloaded — make sure it's saved somewhere safe before continuing.
    </p>
    <label style="display:block;font-size:12px;color:#B8B0A0;margin-bottom:5px">Type exactly: <span class="mono" style="color:#ff5d6c">DELETE EVERYTHING</span></label>
    <input id="dz-confirm-phrase" style="width:100%;padding:11px;border-radius:10px;border:1px solid #ff5d6c;background:#191926;color:#EDEDF5;margin-bottom:16px;box-sizing:border-box">
    <button class="btn" style="width:100%;background:#ff5d6c;color:#fff;border:none" onclick="dzExecuteReset()">☠️ Permanently Delete Everything</button>
  `);
}

async function dzExecuteReset() {
  const phrase = document.getElementById('dz-confirm-phrase').value.trim();
  if (phrase !== 'DELETE EVERYTHING') { alert('Phrase does not match exactly.'); return; }
  const res = await api('system_reset_execute', {
    keep_phones: DZ_KEEP_PHONES,
    backup_token: DZ_BACKUP_TOKEN,
    confirm_phrase: phrase,
  });
  if (!res.success) { alert(res.message || 'Reset failed'); return; }
  closeModal();
  alert('Reset complete. ' + JSON.stringify(res.data.log, null, 2));
  loadDangerZone();
}
