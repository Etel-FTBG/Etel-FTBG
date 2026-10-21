/* ── Generic modal helpers ── */
function _eapOpen(id){ document.getElementById(id).style.display='flex'; document.body.style.overflow='hidden'; }
function _eapClose(id){ document.getElementById(id).style.display='none'; document.body.style.overflow=''; }
['eap-members-modal','eap-pages-modal','eap-media-modal','eap-menus-modal','eap-branding-modal','eap-resetpw-modal','eap-nfc-modal','eap-company-roles-modal','eap-bio-register-modal','eap-2fa-modal','eap-biometric-modal','eap-platform-logos-modal'].forEach(function(id){
  document.getElementById(id).addEventListener('click', function(e){ if (e.target === this) _eapClose(id); });
});

/* ════════ NFC CARD WRITER ════════ */
function openNfcModal(e){
  if(e)e.preventDefault();
  _eapOpen('eap-nfc-modal');
  document.getElementById('eap-nfc-selected').style.display = 'none';
  document.getElementById('eap-nfc-search').value = '';
  eapNfcSearch();
}
function closeNfcModal(){ _eapClose('eap-nfc-modal'); }

var _eapNfcDebounce = null;
function eapNfcSearchDebounced(){
  clearTimeout(_eapNfcDebounce);
  _eapNfcDebounce = setTimeout(eapNfcSearch, 350);
}

async function eapNfcSearch(){
  var box = document.getElementById('eap-nfc-results');
  var q = document.getElementById('eap-nfc-search').value.trim();
  box.innerHTML = '<div style="color:#666;font-size:13px;padding:10px;">Loading…</div>';
  var res = await _eidPanel('etelid_panel_members_list', { source: 'etelid', page: 1, search: q, status: '' });
  if (!res.success || !res.data.members || !res.data.members.length) {
    box.innerHTML = '<div style="color:#666;font-size:13px;padding:10px;">No members found.</div>';
    return;
  }
  var html = '';
  res.data.members.forEach(function(m){
    html += '<div class="eap-row" onclick=\'eapNfcSelect(' + JSON.stringify(m.id) + ',' + JSON.stringify(m.name) + ',' + JSON.stringify(m.etel_id) + ')\'>'
      + '<div style="flex:1;"><div style="font-weight:700;color:#e8e2d4;">' + (m.name||'—') + '</div>'
      + '<div style="font-size:11px;color:#998;">' + (m.etel_id||'') + '</div></div>'
      + (m.status && m.status!=='active' ? '<span class="eap-pill ' + m.status + '">' + m.status + '</span>' : '')
      + '</div>';
  });
  if (res.data.total > res.data.members.length) {
    html += '<div style="color:#666;font-size:11px;padding:8px;text-align:center;">Showing first ' + res.data.members.length + ' of ' + res.data.total + ' — refine search to narrow down.</div>';
  }
  box.innerHTML = html;
}

async function eapNfcSelect(memberId, name, etelId){
  document.getElementById('eap-nfc-sel-name').textContent = name;
  document.getElementById('eap-nfc-sel-id').textContent = etelId;
  document.getElementById('eap-nfc-url').value = 'Generating link...';
  document.getElementById('eap-nfc-status').textContent = '';
  document.getElementById('eap-nfc-selected').style.display = 'block';
  document.getElementById('eap-nfc-results').innerHTML = '';

  // Issues a real card token (etelid_nfc_cards row) and returns the
  // opaque /m/{token} link the NFC service-selector page resolves -
  // this used to just build a /verify/{etel_id} link client-side,
  // which pointed at the public read-only verify page (no service
  // buttons, and the member's real Etel-ID printed in the clear on
  // the physical tag) instead of ever actually issuing a card.
  var res = await _eidPanel('etelid_nfc_issue', { member_id: memberId });
  if (!res.success) {
    document.getElementById('eap-nfc-url').value = '';
    document.getElementById('eap-nfc-status').textContent = 'Error: ' + (res.data && res.data.message ? res.data.message : 'Could not issue card.');
    return;
  }
  var url = res.data.url;
  document.getElementById('eap-nfc-url').value = url;
  document.getElementById('eap-nfc-test-link').href = url;
}

function eapNfcCopy(){
  var f = document.getElementById('eap-nfc-url');
  f.select();
  document.execCommand('copy');
}

var _eapNfcWriteController = null;

async function eapNfcWrite(evt){
  var statusEl = document.getElementById('eap-nfc-status');
  var url = document.getElementById('eap-nfc-url').value;

  if (typeof NDEFReader === 'undefined') {
    statusEl.textContent = 'Web NFC not available here — use Chrome on Android over HTTPS, or write the tag with an app like "NFC Tools" using the URL above.';
    return;
  }

  /* Abort any previous in-flight write before starting a new one - this is
     what causes "Push is cancelled due to a new push request" on Android:
     two overlapping write() calls fighting over the NFC radio. */
  if (_eapNfcWriteController) {
    _eapNfcWriteController.abort();
  }
  _eapNfcWriteController = new AbortController();

  var btn = evt ? evt.currentTarget : null;
  if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }

  statusEl.textContent = 'Hold a blank NFC tag steady on the back of your phone — do not move it until this message changes.';

  try {
    var writer = new NDEFReader();
    await writer.write(
      { records: [{ recordType: 'url', data: url }] },
      { signal: _eapNfcWriteController.signal }
    );
    statusEl.textContent = 'Tag written successfully.';
  } catch (err) {
    if (err.name === 'AbortError') {
      statusEl.textContent = 'Write cancelled.';
    } else {
      statusEl.textContent = 'Write failed: ' + err.message + '. Make sure no other app (Google Pay, Nearby Share) is using NFC, only tap "Write to NFC Tag" once, and keep the tag still and touching the phone the whole time.';
    }
  } finally {
    if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
  }
}

/* ════════ MEMBERS CONTROLLER ════════ */
var _eapMemSrc = 'etelid', _eapMemPage = 1;
function openMembersModal(e){ if(e)e.preventDefault(); _eapOpen('eap-members-modal'); eapMemLoad(1); }
function closeMembersModal(){ _eapClose('eap-members-modal'); }

/* ════════ DEVICE BIOMETRIC (Face ID / Touch ID / fingerprint via WebAuthn) ════════ */
function _waB64urlToBuf(s){
  s = s.replace(/-/g,'+').replace(/_/g,'/');
  while (s.length % 4) s += '=';
  var bin = atob(s), buf = new Uint8Array(bin.length);
  for (var i=0;i<bin.length;i++) buf[i] = bin.charCodeAt(i);
  return buf.buffer;
}
function _waBufToB64url(buf){
  var bytes = new Uint8Array(buf), bin = '';
  for (var i=0;i<bytes.length;i++) bin += String.fromCharCode(bytes[i]);
  return btoa(bin).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
}

async function openBioRegisterModal(e){
  if(e)e.preventDefault();
  _eapOpen('eap-bio-register-modal');
  var statusEl = document.getElementById('eap-bio-status');
  var btn = document.getElementById('eap-bio-register-btn');
  statusEl.style.color = '';
  if (!window.PublicKeyCredential) {
    statusEl.style.color = '#f66';
    statusEl.textContent = 'Your browser does not support device biometrics. Try Safari on iPhone, Chrome on Android, or a recent desktop browser.';
    btn.style.display = 'none';
    return;
  }
  try {
    var available = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
    if (!available) {
      statusEl.style.color = '#f66';
      statusEl.textContent = 'No Face ID, Touch ID or fingerprint sensor was found on this device.';
      btn.style.display = 'none';
      return;
    }
  } catch(e) {}
  btn.style.display = '';
  statusEl.textContent = '';
  eapBioLoadDevices();
}
function closeBioRegisterModal(){ _eapClose('eap-bio-register-modal'); }

/* My devices: every phone or laptop that can sign in with Face / Fingerprint */
async function eapBioLoadDevices(){
  var box = document.getElementById('eap-bio-devices');
  if (!box) return;
  box.textContent = '';
  try {
    var res = await _eidPanel('etelid_webauthn_devices', {});
    if (!res.success) return;
    var list = (res.data && res.data.devices) || [];
    if (!list.length) return;
    var head = document.createElement('div');
    head.style.cssText = 'font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#C8922A;margin-bottom:6px;';
    head.textContent = 'Your devices (' + list.length + ' of ' + ((res.data && res.data.max) || 5) + ')';
    box.appendChild(head);
    list.forEach(function(d){
      var row = document.createElement('div');
      row.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 10px;border:1px solid rgba(212,175,55,.18);border-radius:8px;margin-bottom:6px;font-size:13px;';
      var info = document.createElement('div');
      var name = document.createElement('div'); name.textContent = d.label || 'Device';
      var sub = document.createElement('div'); sub.style.cssText = 'font-size:11px;color:#999;';
      sub.textContent = d.last_used_at ? ('Last used ' + d.last_used_at) : (d.created_at ? ('Added ' + d.created_at) : '');
      info.appendChild(name); info.appendChild(sub);
      var del = document.createElement('button');
      del.className = 'eap-btn-ghost'; del.style.cssText = 'padding:4px 10px;font-size:11px;'; del.textContent = 'Remove';
      del.onclick = function(){ eapBioRemoveDevice(d.id); };
      row.appendChild(info); row.appendChild(del); box.appendChild(row);
    });
  } catch(e) {}
}
async function eapBioRemoveDevice(id){
  if (!confirm('Remove this device? It will no longer be able to sign in with Face / Fingerprint.')) return;
  var statusEl = document.getElementById('eap-bio-status');
  try {
    var res = await _eidPanel('etelid_webauthn_remove', { credential_id: id });
    statusEl.style.color = res.success ? '#6ee7a0' : '#f66';
    statusEl.textContent = (res.data && res.data.message) || (res.success ? 'Removed.' : 'Could not remove.');
  } catch(e) { statusEl.style.color = '#f66'; statusEl.textContent = 'Could not remove.'; }
  eapBioLoadDevices();
}

async function eapBioRegister(){
  var statusEl = document.getElementById('eap-bio-status');
  var btn = document.getElementById('eap-bio-register-btn');
  btn.disabled = true;
  statusEl.style.color = '#999';
  statusEl.textContent = 'Requesting options…';
  try {
    var optRes = await _eidPanel('etelid_webauthn_register_options', {});
    if (!optRes.success) throw new Error((optRes.data && optRes.data.message) || 'Could not start registration.');
    var opt = optRes.data;

    var publicKey = {
      challenge: _waB64urlToBuf(opt.challenge),
      rp: opt.rp,
      user: { id: _waB64urlToBuf(opt.user.id), name: opt.user.name, displayName: opt.user.displayName },
      pubKeyCredParams: opt.pubKeyCredParams,
      authenticatorSelection: opt.authenticatorSelection,
      attestation: opt.attestation,
      timeout: opt.timeout,
      excludeCredentials: (opt.excludeCredentials||[]).map(function(c){ return { type: c.type, id: _waB64urlToBuf(c.id) }; }),
    };

    statusEl.textContent = 'Follow the prompt on your device…';
    var cred = await navigator.credentials.create({ publicKey: publicKey });

    var payload = {
      id: cred.id,
      rawId: _waBufToB64url(cred.rawId),
      type: cred.type,
      response: {
        clientDataJSON: _waBufToB64url(cred.response.clientDataJSON),
        attestationObject: _waBufToB64url(cred.response.attestationObject),
      },
    };

    statusEl.textContent = 'Saving…';
    var verifyRes = await _eidPanel('etelid_webauthn_register_verify', { credential: JSON.stringify(payload) });
    if (!verifyRes.success) throw new Error((verifyRes.data && verifyRes.data.message) || 'Could not save credential.');

    statusEl.style.color = '#6ee7a0';
    statusEl.textContent = (verifyRes.data && verifyRes.data.message) || 'Registered!';
    eapBioLoadDevices();
    setTimeout(closeBioRegisterModal, 1600);
  } catch (err) {
    statusEl.style.color = '#f66';
    statusEl.textContent = err.name === 'NotAllowedError' ? 'Cancelled or timed out.' : (err.message || 'Registration failed.');
  } finally {
    btn.disabled = false;
  }
}

/* ════════ BIOMETRIC CENTER ════════ */
function openBiometricCenterModal(e){
  if(e)e.preventDefault();
  _eapOpen('eap-biometric-modal');
  document.getElementById('eap-bio-search').value = '';
  document.getElementById('eap-bio-search-results').innerHTML = '';
  document.getElementById('eap-bio-detail').innerHTML = '<div style="color:#666;font-size:13px;padding:14px;text-align:center;">Search for a member to view their biometric enrollment.</div>';
}
function closeBiometricCenterModal(){ _eapClose('eap-biometric-modal'); }

async function eapBioSearch(){
  var q = document.getElementById('eap-bio-search').value.trim();
  var resultsEl = document.getElementById('eap-bio-search-results');
  if (!q) { resultsEl.innerHTML = ''; return; }
  resultsEl.innerHTML = '<div style="color:#666;font-size:13px;">Searching…</div>';
  var res = await _eidPanel('etelid_panel_biometric_search', { q: q });
  if (!res.success || !(res.data.members||[]).length) { resultsEl.innerHTML = '<div style="color:#888;font-size:13px;">No members found.</div>'; return; }
  resultsEl.innerHTML = res.data.members.map(function(m){
    return '<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 10px;border:1px solid rgba(212,175,55,.12);border-radius:8px;margin-bottom:6px;cursor:pointer;" onclick="eapBioLoad(' + m.id + ')">' +
      '<span style="font-size:13px;color:#eee;">' + m.full_name + ' <span style="color:#888;font-family:monospace;font-size:11px;">' + m.etel_id + '</span></span>' +
      '<span style="font-size:11px;color:#888;font-family:monospace;">' + m.phone + '</span>' +
    '</div>';
  }).join('');
}

async function eapBioLoad(memberId){
  var detailEl = document.getElementById('eap-bio-detail');
  detailEl.innerHTML = '<div style="color:#666;font-size:13px;padding:14px;">Loading…</div>';
  var res = await _eidPanel('etelid_panel_biometric_get', { member_id: memberId });
  if (!res.success) { detailEl.innerHTML = '<div style="color:#f66;font-size:13px;padding:14px;">' + ((res.data && res.data.message) || 'Failed to load.') + '</div>'; return; }

  var d = res.data;
  var wa = d.webauthn;
  var waHtml = wa.enrolled
    ? '<span style="color:#6ee7a0;font-weight:700;">✓ Registered</span> — enrolled by ' + (wa.enrolled_by || 'self') +
      ' <button class="eap-btn-ghost" style="margin-left:10px;padding:4px 10px;font-size:11px;" onclick="eapBioRevoke(' + memberId + ')">Revoke</button>'
    : '<span style="color:#888;">Not registered</span> — member can register from their own portal (“Face / Fingerprint” tile)';

  var logHtml = (d.log||[]).length
    ? '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:8px;">' +
        '<thead><tr style="text-align:left;color:#888;font-size:10px;text-transform:uppercase;"><th style="padding:4px 8px;">Method</th><th style="padding:4px 8px;">Result</th><th style="padding:4px 8px;">When</th></tr></thead>' +
        '<tbody>' + d.log.map(function(l){
          return '<tr style="border-top:1px solid rgba(255,255,255,.06);"><td style="padding:4px 8px;color:#ddd;">' + l.method + '</td>' +
            '<td style="padding:4px 8px;color:' + (l.result==='success'?'#6ee7a0':'#f66') + ';">' + l.result + '</td>' +
            '<td style="padding:4px 8px;color:#888;">' + l.created_at + '</td></tr>';
        }).join('') + '</tbody></table>'
    : '<div style="color:#888;font-size:12px;margin-top:8px;">No check-ins yet.</div>';

  detailEl.innerHTML =
    '<div style="border:1px solid rgba(212,175,55,.15);border-radius:10px;padding:14px;margin-bottom:12px;">' +
      '<div style="font-weight:700;color:#eee;margin-bottom:2px;">' + d.member.full_name + '</div>' +
      '<div style="font-size:11px;color:#888;font-family:monospace;margin-bottom:12px;">' + d.member.etel_id + ' · ' + d.member.phone + '</div>' +

      '<div style="font-size:12px;font-weight:700;color:#D4AF37;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">🆔 Device Biometric (Face / Fingerprint)</div>' +
      '<div style="font-size:13px;margin-bottom:14px;">' + waHtml + '</div>' +

      '<div style="font-size:12px;font-weight:700;color:#D4AF37;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">📡 NFC / RFID Card</div>' +
      '<div style="display:flex;gap:8px;margin-bottom:6px;">' +
        '<input type="text" id="eap-bio-nfc-input" class="eap-input" style="flex:1;font-family:monospace" placeholder="e.g. A3:F1:04:BC:9E" value="' + (d.nfc_uid||'') + '">' +
        '<button class="eap-btn-gold" onclick="eapBioSaveNfc(' + memberId + ')">Save</button>' +
      '</div>' +
      '<div id="eap-bio-nfc-msg" style="font-size:12px;min-height:16px;"></div>' +
    '</div>' +
    '<div style="font-size:12px;font-weight:700;color:#D4AF37;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">Recent Check-ins</div>' +
    logHtml;
}

async function eapBioSaveNfc(memberId){
  var msgEl = document.getElementById('eap-bio-nfc-msg');
  var uid = document.getElementById('eap-bio-nfc-input').value.trim();
  msgEl.style.color = '#999'; msgEl.textContent = 'Saving…';
  var res = await _eidPanel('etelid_panel_biometric_nfc_save', { member_id: memberId, nfc_uid: uid });
  msgEl.style.color = res.success ? '#6ee7a0' : '#f66';
  msgEl.textContent = res.success ? (res.data.message || 'Saved.') : ((res.data && res.data.message) || 'Failed.');
}

async function eapBioRevoke(memberId){
  if (!confirm('Revoke this member\'s registered device biometric? They will need to register again.')) return;
  var res = await _eidPanel('etelid_panel_biometric_webauthn_revoke', { member_id: memberId });
  if (res.success) eapBioLoad(memberId);
}

/* ════════ COMPANY ROLES ════════ */
function openCompanyRolesModal(e){ if(e)e.preventDefault(); _eapOpen('eap-company-roles-modal'); eapCrLoad(); }
function closeCompanyRolesModal(){ _eapClose('eap-company-roles-modal'); }

function eapCrRoleBadge(role){
  var priv = ['admin','owner','creator'].indexOf(String(role).toLowerCase()) !== -1;
  return '<span style="padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;' +
    (priv ? 'background:rgba(212,175,55,.18);color:#D4AF37' : 'background:rgba(255,255,255,.08);color:#999') +
    '">' + role.replace(/[<>&]/g, function(c){ return {'<':'&lt;','>':'&gt;','&':'&amp;'}[c]; }) + '</span>';
}

async function eapCrLoad(filterSlug){
  var listEl = document.getElementById('eap-cr-list');
  listEl.innerHTML = '<div style="color:#666;font-size:13px;padding:14px;">Loading…</div>';
  var res = await _eidPanel('etelid_panel_company_roles_list', { company_slug: filterSlug || '' });
  if (!res.success) { listEl.innerHTML = '<div style="color:#f66;font-size:13px;padding:14px;">' + ((res.data && res.data.message) || 'Failed to load.') + '</div>'; return; }

  var dl = document.getElementById('eap-cr-known-slugs');
  dl.innerHTML = (res.data.known_slugs || []).map(function(s){ return '<option value="' + s + '">'; }).join('');

  var links = res.data.links || [];
  if (!links.length) { listEl.innerHTML = '<div style="color:#666;font-size:13px;padding:14px;">No company links yet.</div>'; return; }

  var rows = links.map(function(l){
    return '<tr style="border-bottom:1px solid rgba(255,255,255,.06);">' +
      '<td style="padding:8px 10px;color:#eee;">' + l.full_name + '</td>' +
      '<td style="padding:8px 10px;font-family:monospace;font-size:12px;color:#ddd;">' + l.etel_id + '</td>' +
      '<td style="padding:8px 10px;font-family:monospace;font-size:12px;color:#ddd;">' + l.phone + '</td>' +
      '<td style="padding:8px 10px;font-family:monospace;font-size:12px;color:#D4AF37;">' + l.company_slug + '</td>' +
      '<td style="padding:8px 10px;">' + eapCrRoleBadge(l.role_at) + '</td>' +
      '<td style="padding:8px 10px;font-size:11px;color:#888;">' + l.linked_at + '</td>' +
      '<td style="padding:8px 10px;"><button class="eap-btn-ghost" style="padding:4px 10px;font-size:11px;" onclick="eapCrRemove(' + l.id + ')">Remove</button></td>' +
    '</tr>';
  }).join('');

  listEl.innerHTML = '<table style="width:100%;border-collapse:collapse;font-size:13px;">' +
    '<thead><tr style="text-align:left;border-bottom:1px solid rgba(212,175,55,.15);color:#999;font-size:11px;text-transform:uppercase;">' +
      '<th style="padding:8px 10px;">Member</th><th style="padding:8px 10px;">Etel-ID</th><th style="padding:8px 10px;">Phone</th>' +
      '<th style="padding:8px 10px;">Company Slug</th><th style="padding:8px 10px;">Role</th><th style="padding:8px 10px;">Linked</th><th></th>' +
    '</tr></thead><tbody>' + rows + '</tbody></table>';
}

async function eapCrSet(){
  var msgEl = document.getElementById('eap-cr-msg');
  var identifier = document.getElementById('eap-cr-identifier').value.trim();
  var slug = document.getElementById('eap-cr-slug').value.trim();
  var role = document.getElementById('eap-cr-role').value;
  if (!identifier || !slug || !role) { msgEl.style.color = '#f66'; msgEl.textContent = 'Fill in member, company slug and role.'; return; }

  msgEl.style.color = '#999'; msgEl.textContent = 'Saving…';
  var res = await _eidPanel('etelid_panel_company_roles_set', { member_identifier: identifier, company_slug: slug, role_at: role });
  if (res.success) {
    msgEl.style.color = '#6ee7a0'; msgEl.textContent = (res.data && res.data.message) || 'Saved.';
    document.getElementById('eap-cr-identifier').value = '';
    document.getElementById('eap-cr-slug').value = '';
    document.getElementById('eap-cr-role').value = '';
    eapCrLoad();
  } else {
    msgEl.style.color = '#f66'; msgEl.textContent = (res.data && res.data.message) || 'Failed to save.';
  }
}

async function eapCrRemove(id){
  if (!confirm('Remove this company link?')) return;
  var res = await _eidPanel('etelid_panel_company_roles_remove', { id: id });
  if (res.success) eapCrLoad();
}
async function eapExtUserStatus(id, status) {
  var msgEl = document.getElementById('eap-ext-msg-' + id);
  if (msgEl) msgEl.textContent = 'Saving…';
  var res = await _eidPanel('etelid_panel_ext_user_status', { source: _eapMemSrc, id: id, status: status });
  if (msgEl) msgEl.textContent = res.success ? (status === 'active' ? '✓ Activated' : '⊘ Suspended') : '⚠ ' + ((res.data && res.data.message) || 'Failed');
  if (res.success) { setTimeout(function(){ eapMemLoad(_eapMemPage); }, 800); }
}
function eapMemSwitch(src){
  _eapMemSrc = src;
  document.querySelectorAll('#eap-mem-tabs .eap-tab').forEach(function(t){ t.classList.toggle('active', t.getAttribute('data-src')===src); });
  document.getElementById('eap-mem-search-row').style.display = 'flex';
  var searchEl = document.getElementById('eap-mem-search');
  var roleEl = document.getElementById('eap-mem-role');
  var statusEl = document.getElementById('eap-mem-status');
  if (src === 'etelid') {
    searchEl.placeholder = 'Search name, phone, Etel-ID, email…';
    roleEl.style.display = 'none';
    statusEl.style.display = '';
  } else if (src === 'pay') {
    searchEl.placeholder = 'Search name, phone, email…';
    roleEl.style.display = 'none';
    statusEl.style.display = '';
  } else {
    searchEl.placeholder = 'Search name or phone…';
    roleEl.style.display = '';
    statusEl.style.display = 'none';
  }
  document.getElementById('eap-mem-detail').innerHTML = '<div style="color:#666;font-size:13px;text-align:center;padding:40px 20px;">← Select a member from the list to view full details</div>';
  eapMemLoad(1);
}
async function eapMemLoad(page){
  _eapMemPage = page || 1;
  var listEl = document.getElementById('eap-mem-list');
  listEl.innerHTML = '<div style="color:#666;font-size:13px;">Loading…</div>';
  document.getElementById('eap-mem-pager').innerHTML = '';
  var res = await _eidPanel('etelid_panel_members_list', {
    source: _eapMemSrc, page: _eapMemPage,
    search: document.getElementById('eap-mem-search').value,
    status: document.getElementById('eap-mem-status').value,
    role:   document.getElementById('eap-mem-role').value
  });
  if (!res.success) { listEl.innerHTML = '<div style="color:#ef9a9a;font-size:13px;">' + (res.data && res.data.message || 'Failed to load.') + '</div>'; return; }
  var d = res.data;
  if (d.configured === false) {
    listEl.innerHTML = '<div style="color:#998;font-size:13px;padding:20px;text-align:center;">' + d.message + '</div>';
    return;
  }
  if (!d.members.length) {
    if (d.message) {
      listEl.innerHTML = '<div style="color:#ef9a9a;font-size:13px;padding:20px;text-align:center;">⚠️ ' + d.message + '</div>';
    } else {
      listEl.innerHTML = '<div style="color:#666;font-size:13px;padding:20px;text-align:center;">No members found.</div>';
    }
    return;
  }
  var html = '';
  d.members.forEach(function(m, idx){
    var statusPill = m.status ? '<span class="eap-pill ' + m.status + '">' + m.status + '</span>' : '';
    var sub = '', verifyBadge = '';
    if (_eapMemSrc === 'etelid') {
      sub = (m.etel_id||'') + ' · ' + (m.phone||'') + (m.company ? ' · ' + m.company : '');
      if (m.fayda_fin) {
        verifyBadge = (m.kyc_level >= 2)
          ? '<span class="eap-pill active" style="margin-left:6px;">✓ Pass 2 · FIN ' + m.fayda_fin + '</span>'
          : '<span class="eap-pill suspended" style="margin-left:6px;">Pass 1 only · FIN ' + m.fayda_fin + '</span>';
      }
      if (m.pass2_status === 'pending') {
        verifyBadge += '<span class="eap-pill suspended" style="margin-left:6px;background:rgba(212,175,55,.18);color:#F2CC6B;">⏳ Pass 2 review pending</span>';
      } else if (m.pass2_status === 'rejected') {
        verifyBadge += '<span class="eap-pill suspended" style="margin-left:6px;">Pass 2 rejected</span>';
      }
    } else if (_eapMemSrc === 'pay') {
      sub = (m.phone||'') + (m.email ? ' · ' + m.email : '') + (m.balance_etb !== undefined ? ' · ' + parseFloat(m.balance_etb||0).toLocaleString() + ' ETB' : '');
      statusPill = m.kyc_status ? '<span class="eap-pill ' + (m.kyc_status==='verified'?'active':'suspended') + '" style="font-size:9px;">' + m.kyc_status.toUpperCase() + '</span>' : statusPill;
    } else {
      sub = (m.phone||'') + (m.role ? ' · ' + m.role : '');
      if (m.courier_code) sub += ' · ' + m.courier_code;
      if (m.rating) sub += ' · ★' + m.rating;
      if (m.total_deliveries) sub += ' · ' + m.total_deliveries + ' trips';
      var roleColor = {customer:'#60B4FF',driver:'#F2CC6B',service_giver:'#6ee7a0',courier:'#F2A25C',admin:'#ef9a9a'}[m.role]||'#998';
      verifyBadge = m.role ? '<span style="font-size:9px;padding:2px 7px;border-radius:10px;background:rgba(255,255,255,.06);color:' + roleColor + ';text-transform:uppercase;letter-spacing:.04em;margin-left:6px;">' + m.role + '</span>' : '';
    }
    var initials = (m.name||m.full_name||'?').split(' ').slice(0,2).map(function(w){return w[0]||'';}).join('').toUpperCase();
    var avatarBg = _eapMemSrc==='pay' ? '#1a120a' : (_eapMemSrc==='delivery' ? '#0a1a10' : '#0a0d1a');
    html += '<div class="eap-row" onclick="eapMemOpenDetail(' + (m.id||0) + ')" style="gap:12px;">'
      + '<div style="width:36px;height:36px;border-radius:50%;background:' + avatarBg + ';border:1px solid rgba(212,175,55,.3);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#D4AF37;flex-shrink:0;">' + initials + '</div>'
      + '<div style="flex:1;min-width:0;"><div style="font-weight:700;color:#e8e2d4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + (m.name||m.full_name||'—') + '</div>'
      + '<div style="font-size:11px;color:#998;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + sub + '</div></div>'
      + '<div style="display:flex;flex-direction:column;align-items:flex-end;gap:3px;">' + statusPill + verifyBadge + '</div>'
      + '</div>';
  });
  listEl.innerHTML = html;
  if (d.pages > 1) {
    var p = '';
    if (d.page > 1) p += '<button class="eap-btn-ghost" onclick="eapMemLoad(' + (d.page-1) + ')">← Prev</button>';
    p += '<span style="font-size:12px;color:#998;align-self:center;">Page ' + d.page + ' of ' + d.pages + '</span>';
    if (d.page < d.pages) p += '<button class="eap-btn-ghost" onclick="eapMemLoad(' + (d.page+1) + ')">Next →</button>';
    document.getElementById('eap-mem-pager').innerHTML = p;
  }
}
async function eapMemOpenExternalDetail(id){
  var det = document.getElementById('eap-mem-detail');
  det.innerHTML = '<div style="color:#666;font-size:13px;">Loading…</div>';
  var res = await _eidPanel('etelid_panel_members_external_detail', { source: _eapMemSrc, id: id });
  if (!res.success) { det.innerHTML = '<div style="color:#ef9a9a;font-size:13px;">⚠️ ' + (res.data && res.data.message || 'Failed to load.') + '</div>'; return; }
  var d = res.data.detail || {};
  var html = '';

  /* ── Shared avatar helper ── */
  function _memAvatar(name, bg, size) {
    var ini = (name||'?').split(' ').slice(0,2).map(function(w){return(w[0]||'').toUpperCase();}).join('');
    return '<div style="width:'+size+'px;height:'+size+'px;border-radius:50%;background:'+bg+';border:2px solid rgba(212,175,55,.4);display:flex;align-items:center;justify-content:center;font-size:'+(size/3)+'px;font-weight:700;color:#D4AF37;flex-shrink:0;">'+ini+'</div>';
  }
  function _memStatBox(label, val, color) {
    return '<div style="flex:1;min-width:80px;background:rgba(255,255,255,.04);border:1px solid rgba(212,175,55,.12);border-radius:8px;padding:10px 12px;">'
      +'<div style="font-size:10px;color:#998;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">'+label+'</div>'
      +'<div style="font-size:14px;font-weight:700;color:'+(color||'#e8e2d4')+';">'+val+'</div></div>';
  }
  function _memSection(title) {
    return '<div style="font-size:10px;font-weight:700;color:#998;text-transform:uppercase;letter-spacing:.06em;margin:18px 0 8px;border-top:1px solid rgba(212,175,55,.08);padding-top:14px;">'+title+'</div>';
  }
  function _statusPill(s) {
    var c={'active':'active','verified':'active','online':'active','approved':'active','suspended':'suspended','offline':'suspended','rejected':'suspended','pending':''}[s]||'';
    return '<span class="eap-pill '+c+'">'+s+'</span>';
  }

  if (_eapMemSrc === 'pay') {
    var u = d.user || {};
    var uName = u.full_name || u.name || '—';
    html += '<div style="display:flex;align-items:center;gap:14px;margin-bottom:18px;">'
      + _memAvatar(uName,'#1a0d05',52)
      + '<div><div style="font-size:16px;font-weight:700;color:#e8e2d4;">'+uName+'</div>'
      + '<div style="font-size:12px;color:#998;margin-top:2px;">'+(u.phone||'—')+(u.email?' · '+u.email:'')+'</div>'
      + '<div style="margin-top:6px;">'+_statusPill(u.kyc_status||'none')+'</div></div>'
      + '<div style="margin-left:auto;text-align:right;">'
      + '<div style="font-size:22px;font-weight:800;color:#F2CC6B;">'+parseFloat(u.balance_etb||0).toLocaleString()+' <span style="font-size:13px;font-weight:400;">ETB</span></div>'
      + (u.balance_usd ? '<div style="font-size:11px;color:#998;">'+parseFloat(u.balance_usd).toFixed(2)+' USD</div>' : '')
      + '</div></div>';

    html += '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">'
      + _memStatBox('Joined',(u.created_at||'—').substring(0,10),'#e8e2d4')
      + _memStatBox('KYC Level',u.kyc_level||'0','#F2CC6B')
      + (u.fayda_fin ? _memStatBox('FAYDA FIN',u.fayda_fin,'#6ee7a0') : '')
      + '</div>';

    if (d.kyc) {
      html += _memSection('KYC Documents');
      html += '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:8px;">'
        + (d.kyc.doc_front ? '<div style="position:relative;"><img src="'+d.kyc.doc_front+'" style="width:130px;height:85px;object-fit:cover;border-radius:8px;border:1px solid rgba(212,175,55,.25);cursor:pointer;" onclick="window.open(this.src)"><div style="font-size:9px;color:#998;text-align:center;margin-top:2px;">Front</div></div>' : '')
        + (d.kyc.doc_back  ? '<div><img src="'+d.kyc.doc_back+'" style="width:130px;height:85px;object-fit:cover;border-radius:8px;border:1px solid rgba(212,175,55,.25);cursor:pointer;" onclick="window.open(this.src)"><div style="font-size:9px;color:#998;text-align:center;margin-top:2px;">Back</div></div>' : '')
        + (d.kyc.selfie    ? '<div><img src="'+d.kyc.selfie+'" style="width:85px;height:85px;object-fit:cover;border-radius:50%;border:2px solid rgba(212,175,55,.4);cursor:pointer;" onclick="window.open(this.src)"><div style="font-size:9px;color:#998;text-align:center;margin-top:2px;">Selfie</div></div>' : '')
        + '</div>';
      html += '<div style="font-size:11px;color:#998;margin-bottom:4px;">Type: <strong style="color:#e8e2d4;">'+(d.kyc.doc_type||'—')+'</strong> · Status: '+_statusPill(d.kyc.status||'pending')
        + (d.kyc.submitted_at ? ' · Submitted '+d.kyc.submitted_at.substring(0,10) : '')+'</div>';
    } else {
      html += _memSection('KYC Documents');
      html += '<div style="font-size:12px;color:#666;padding:12px;background:rgba(255,255,255,.03);border-radius:6px;text-align:center;">No KYC documents submitted.</div>';
    }

    html += _memSection('Recent Transactions');
    var txs = d.transactions || [];
    if (!txs.length) {
      html += '<div style="font-size:12px;color:#666;padding:12px;background:rgba(255,255,255,.03);border-radius:6px;text-align:center;">No transactions yet.</div>';
    } else {
      html += '<div style="display:flex;flex-direction:column;gap:4px;">';
      txs.forEach(function(t){
        var isOut = (t.sender_ep_id == id);
        var dir = isOut ? '↑' : '↓';
        var dirColor = isOut ? '#ef9a9a' : '#6ee7a0';
        var dirLabel = isOut ? 'Sent' : 'Received';
        html += '<div style="display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:7px;background:rgba(255,255,255,.03);border:1px solid rgba(212,175,55,.07);">'
          + '<div style="width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.06);display:flex;align-items:center;justify-content:center;font-size:13px;color:'+dirColor+';">'+dir+'</div>'
          + '<div style="flex:1;"><div style="font-size:12px;color:#e8e2d4;">'+dirLabel+' · '+(t.type||'transfer')+'</div><div style="font-size:10px;color:#666;">'+(t.tx_ref||'—')+' · '+(t.created_at||'').substring(0,10)+'</div></div>'
          + '<div style="text-align:right;"><div style="font-weight:700;color:'+dirColor+';">'+(isOut?'-':'+')+parseFloat(t.amount||0).toLocaleString()+' '+(t.currency||'ETB')+'</div><div style="font-size:10px;">'+_statusPill(t.status||'')+'</div></div>'
          + '</div>';
      });
      html += '</div>';
    }

  } else {
    /* ── Delivery & Ride detail ── */
    var du = d.user || {};
    var duName = du.name || '—';
    var roleColor = {customer:'#60B4FF',driver:'#F2CC6B',service_giver:'#6ee7a0',courier:'#F2A25C',admin:'#ef9a9a'}[du.role]||'#998';
    var driverOnline = d.driver && d.driver.online == 1;

    html += '<div style="display:flex;align-items:center;gap:14px;margin-bottom:18px;">'
      + _memAvatar(duName,'#0a1a10',52)
      + '<div style="flex:1;"><div style="font-size:16px;font-weight:700;color:#e8e2d4;">'+duName+'</div>'
      + '<div style="font-size:12px;color:#998;margin-top:2px;">'+(du.phone||'—')+'</div>'
      + '<div style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap;">'
      + (du.role ? '<span style="font-size:10px;padding:2px 8px;border-radius:10px;background:rgba(255,255,255,.07);color:'+roleColor+';text-transform:uppercase;letter-spacing:.05em;">'+du.role.replace('_',' ')+'</span>' : '')
      + (du.status ? _statusPill(du.status) : '')
      + (d.driver ? '<span class="eap-pill '+(driverOnline?'active':'suspended')+'">'+(driverOnline?'🟢 Online':'⚫ Offline')+'</span>' : '')
      + '</div></div>'
      + '<div style="text-align:right;font-size:11px;color:#666;">Joined<br><span style="color:#e8e2d4;">'+(du.created_at||'—').substring(0,10)+'</span></div>'
      + '</div>';

    /* Stats row */
    html += '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">';
    if (d.driver) {
      html += _memStatBox('Driver Rating','★ '+(d.driver.rating||'0.0'),'#F2CC6B')
        + _memStatBox('Trips Rated',d.driver.rating_count||0,'#e8e2d4');
    }
    if (d.courier) {
      html += _memStatBox('Courier Code',d.courier.courier_code||'—','#e8e2d4')
        + _memStatBox('Deliveries',d.courier.completed_deliveries||d.courier.total_deliveries||0,'#e8e2d4')
        + _memStatBox('Rating','★ '+(d.courier.average_rating||d.courier.rating||0),'#F2CC6B');
      if (d.courier.total_earnings) html += _memStatBox('Earnings',parseFloat(d.courier.total_earnings).toLocaleString()+' ETB','#6ee7a0');
    }
    if (!d.driver && !d.courier) {
      var rideCount = (d.rides||[]).length;
      var orderCount = (d.orders||[]).length;
      html += _memStatBox('Rides',rideCount,'#e8e2d4') + _memStatBox('Orders',orderCount,'#e8e2d4');
    }
    html += '</div>';

    /* Action buttons */
    html += '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px;padding:12px;background:rgba(255,255,255,.03);border-radius:8px;border:1px solid rgba(212,175,55,.1);">'
      + '<button class="eap-btn-ghost" style="font-size:12px;" onclick="eapExtUserStatus('+id+',\'active\')">✓ Activate</button>'
      + '<button class="eap-btn-ghost" style="font-size:12px;" onclick="eapExtUserStatus('+id+',\'suspended\')">⊘ Suspend</button>'
      + '<div id="eap-ext-msg-'+id+'" style="font-size:11px;color:#D4AF37;align-self:center;margin-left:8px;"></div>'
      + '</div>';

    /* Recent Orders */
    html += _memSection('Recent Orders');
    var orders = d.orders || [];
    if (!orders.length) {
      html += '<div style="font-size:12px;color:#666;padding:12px;background:rgba(255,255,255,.03);border-radius:6px;text-align:center;margin-bottom:4px;">No orders yet.</div>';
    } else {
      html += '<div style="display:flex;flex-direction:column;gap:4px;margin-bottom:4px;">';
      orders.forEach(function(o){
        var sc = {completed:'#6ee7a0',cancelled:'#ef9a9a',pending:'#F2CC6B'}[o.status]||'#998';
        html += '<div style="display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:7px;background:rgba(255,255,255,.03);border:1px solid rgba(212,175,55,.07);">'
          + '<div style="flex:1;"><div style="font-size:12px;color:#e8e2d4;">'+(o.order_code||'#'+o.id)+' · '+(o.from_zone||'—')+' → '+(o.to_zone||'—')+'</div>'
          + '<div style="font-size:10px;color:#666;">'+(o.placed_at||'').substring(0,10)+'</div></div>'
          + '<div style="text-align:right;"><div style="font-size:12px;font-weight:700;color:#e8e2d4;">'+parseFloat(o.fare_total||0).toLocaleString()+' ETB</div>'
          + '<div style="font-size:10px;color:'+sc+';">'+o.status+'</div></div>'
          + '</div>';
      });
      html += '</div>';
    }

    /* Recent Rides */
    html += _memSection('Recent Rides');
    var rides = d.rides || [];
    if (!rides.length) {
      html += '<div style="font-size:12px;color:#666;padding:12px;background:rgba(255,255,255,.03);border-radius:6px;text-align:center;">No rides yet.</div>';
    } else {
      html += '<div style="display:flex;flex-direction:column;gap:4px;">';
      rides.forEach(function(r){
        var sc = {completed:'#6ee7a0',cancelled:'#ef9a9a',in_progress:'#F2CC6B'}[r.status]||'#998';
        html += '<div style="display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:7px;background:rgba(255,255,255,.03);border:1px solid rgba(212,175,55,.07);">'
          + '<div style="font-size:18px;">🚖</div>'
          + '<div style="flex:1;"><div style="font-size:12px;color:#e8e2d4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+(r.pickup_address||'—')+' → '+(r.dropoff_address||'—')+'</div>'
          + '<div style="font-size:10px;color:#666;">'+(r.created_at||'').substring(0,10)+(r.distance_km?' · '+parseFloat(r.distance_km).toFixed(1)+'km':'')+'</div></div>'
          + '<div style="text-align:right;"><div style="font-size:12px;font-weight:700;color:#e8e2d4;">'+parseFloat(r.fare_final||0).toLocaleString()+' ETB'+(r.rating?' <span style="color:#F2CC6B;">★'+r.rating+'</span>':'')+'</div>'
          + '<div style="font-size:10px;color:'+sc+';">'+r.status+'</div></div>'
          + '</div>';
      });
      html += '</div>';
    }
  }

  det.innerHTML = html;
}
async function eapMemOpenDetail(id){
  if (_eapMemSrc !== 'etelid') { eapMemOpenExternalDetail(id); return; }
  var det = document.getElementById('eap-mem-detail');
  det.style.display = 'block';
  det.innerHTML = '<div style="color:#666;font-size:13px;">Loading…</div>';
  var res = await _eidPanel('etelid_panel_members_get', { id: id });
  if (!res.success) { det.innerHTML = '<div style="color:#ef9a9a;font-size:13px;">' + res.data.message + '</div>'; return; }
  var m = res.data.member;
  var kycLevel = parseInt(m.kyc_level || 0, 10);
  var verifyHtml = eapFaydaSectionHtml(m, id);
  det.innerHTML =
    '<div class="eap-title" style="font-size:14px;margin-bottom:10px;">Editing ' + m.full_name + ' · ' + m.etel_id + '</div>'
    + verifyHtml
    + '<div class="eap-grid2">'
    + '<div class="eap-field"><label>Full Name</label><input class="eap-input" id="eap-em-name" value="' + (m.full_name||'').replace(/"/g,'&quot;') + '"></div>'
    + '<div class="eap-field"><label>Full Name (Amharic)</label><input class="eap-input" id="eap-em-name-am" value="' + (m.full_name_am||'').replace(/"/g,'&quot;') + '"></div>'
    + '<div class="eap-field"><label>Phone</label><input class="eap-input" id="eap-em-phone" value="' + (m.phone||'') + '"></div>'
    + '<div class="eap-field"><label>Email</label><input class="eap-input" id="eap-em-email" value="' + (m.email||'') + '"></div>'
    + '<div class="eap-field"><label>Company</label><input class="eap-input" id="eap-em-company" value="' + (m.primary_company||'') + '"></div>'
    + '<div class="eap-field"><label>Role</label><input class="eap-input" id="eap-em-role" value="' + (m.role||'') + '"></div>'
    + '<div class="eap-field"><label>Department</label><input class="eap-input" id="eap-em-dept" value="' + (m.department||'') + '"></div>'
    + '<div class="eap-field"><label>Tier</label><select class="eap-input" id="eap-em-tier">'
    + ['standard','staff','manager','executive','vip'].map(function(t){ return '<option value="' + t + '"' + (m.tier===t?' selected':'') + '>' + t + '</option>'; }).join('')
    + '</select></div></div>'
    + '<div class="eap-field"><label>Notes</label><textarea class="eap-input" id="eap-em-notes" rows="2">' + (m.notes||'') + '</textarea></div>'
    + '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
    + '<button class="eap-btn-gold" onclick="eapMemSave(' + id + ')">Save Changes</button>'
    + '<button class="eap-btn-ghost" onclick="eapMemStatus(' + id + ',\'active\')">Activate</button>'
    + '<button class="eap-btn-ghost" onclick="eapMemStatus(' + id + ',\'suspended\')">Suspend</button>'
    + (m.phone_verified_at && String(m.phone_verified_at).indexOf('0000') !== 0
        ? '<span class="eap-btn-ghost" style="cursor:default;opacity:.8">Phone verified</span>'
        : '<button class="eap-btn-ghost" onclick="eapMemVerifyPhone(' + id + ')">Verify phone</button>')
    + '<button class="eap-btn-ghost" onclick="openResetPwModal(null,' + id + ',\'' + m.full_name.replace(/'/g,"\\'") + '\')">Reset Password</button>'
    + '<button class="eap-btn-danger" onclick="eapMemDelete(' + id + ')">Delete</button>'
    + '</div><div id="eap-em-msg"></div>';
}
function eapFaydaSectionHtml(m, id) {
  var kycLevel = parseInt(m.kyc_level || 0, 10);
  var hasPhotos = m.fayda_front_image && m.fayda_back_image;
  var html = '<div style="background:rgba(212,175,55,.06);border:1px solid rgba(212,175,55,.2);border-radius:8px;padding:14px;margin-bottom:14px;">';

  /* Status row */
  if (m.fayda_fin || hasPhotos) {
    var levelLabel = kycLevel >= 2 ? 'Pass 2 complete - verified' : (kycLevel === 1 ? 'Pass 1 only - Fayda quick-register' : 'No verification');
    var levelClass = kycLevel >= 2 ? 'active' : 'suspended';
    html += '<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">'
      + (m.fayda_fin ? '<div><div style="font-size:10px;color:#998;text-transform:uppercase;">Fayda FIN</div><div style="font-family:monospace;color:#e8e2d4;">' + m.fayda_fin + '</div></div>' : '')
      + '<div><div style="font-size:10px;color:#998;text-transform:uppercase;">Verification</div><span class="eap-pill ' + levelClass + '">' + levelLabel + '</span></div>'
      + (m.pass2_completed_at ? '<div><div style="font-size:10px;color:#998;text-transform:uppercase;">Pass 2 Completed</div><div style="color:#e8e2d4;font-size:12px;">' + m.pass2_completed_at + '</div></div>' : '')
      + '</div>';
  } else {
    html += '<div style="font-size:12px;color:#998;margin-bottom:12px;">No Fayda ID on file for this member yet.</div>';
  }

  /* Existing photos, if any */
  if (hasPhotos) {
    html += '<div style="display:flex;gap:10px;margin-bottom:12px;flex-wrap:wrap;">'
      + '<div><div style="font-size:10px;color:#998;margin-bottom:4px;">FRONT</div><img src="' + m.fayda_front_image + '" style="max-width:160px;max-height:110px;border-radius:6px;border:1px solid rgba(212,175,55,.25);object-fit:cover;"></div>'
      + '<div><div style="font-size:10px;color:#998;margin-bottom:4px;">BACK</div><img src="' + m.fayda_back_image + '" style="max-width:160px;max-height:110px;border-radius:6px;border:1px solid rgba(212,175,55,.25);object-fit:cover;"></div>'
      + '</div>';
  }

  /* Pending member self-submission - approve/reject */
  if (m.pass2_status === 'pending') {
    html += '<div style="background:rgba(212,175,55,.1);border:1px solid rgba(212,175,55,.3);border-radius:8px;padding:10px 12px;margin-bottom:12px;">'
      + '<div style="font-size:12px;color:#F2CC6B;margin-bottom:8px;">⏳ ' + m.full_name + ' submitted these photos themselves - awaiting your review.</div>'
      + '<input type="text" class="eap-input" id="eap-fayda-note-' + id + '" placeholder="Optional note (shown to member if rejected)" style="margin-bottom:8px;">'
      + '<div style="display:flex;gap:8px;">'
      + '<button class="eap-btn-gold" onclick="eapMemFaydaReview(' + id + ',\'approve\')">✓ Approve - Pass 2</button>'
      + '<button class="eap-btn-danger" onclick="eapMemFaydaReview(' + id + ',\'reject\')">✕ Reject</button>'
      + '</div></div>';
  } else if (m.pass2_status === 'rejected' && m.pass2_review_note) {
    html += '<div style="font-size:12px;color:#ef9a9a;margin-bottom:12px;">Last rejection note: ' + m.pass2_review_note + '</div>';
  }

  /* Admin capture/upload - always available, instant approve */
  html += '<div style="border-top:1px solid rgba(212,175,55,.15);padding-top:12px;">'
    + '<div style="font-size:11px;color:#998;text-transform:uppercase;margin-bottom:8px;">' + (hasPhotos ? 'Replace' : 'Capture') + ' Fayda ID (instant Pass 2 approval)</div>'
    + '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;">'
    + '<label class="eap-btn-ghost" style="cursor:pointer;"><input type="file" accept="image/*" capture="environment" id="eap-fayda-front-' + id + '" style="display:none;" onchange="eapFaydaPreview(' + id + ',\'front\')">📷 Front Photo</label>'
    + '<label class="eap-btn-ghost" style="cursor:pointer;"><input type="file" accept="image/*" capture="environment" id="eap-fayda-back-' + id + '" style="display:none;" onchange="eapFaydaPreview(' + id + ',\'back\')">📷 Back Photo</label>'
    + '</div>'
    + '<div id="eap-fayda-preview-' + id + '" style="display:flex;gap:8px;margin-bottom:8px;"></div>'
    + '<button class="eap-btn-gold" id="eap-fayda-submit-' + id + '" onclick="eapMemFaydaUpload(' + id + ')" disabled>Save &amp; Approve Pass 2</button>'
    + '<div id="eap-fayda-msg-' + id + '"></div>'
    + '</div></div>';
  return html;
}

var _eapFaydaData = {};
function eapFaydaPreview(id, side) {
  var input = document.getElementById('eap-fayda-' + side + '-' + id);
  var f = input.files[0];
  if (!f) return;
  var reader = new FileReader();
  reader.onload = function(e) {
    _eapFaydaData[id] = _eapFaydaData[id] || {};
    _eapFaydaData[id][side] = e.target.result;
    var prev = document.getElementById('eap-fayda-preview-' + id);
    var existing = document.getElementById('eap-fayda-prev-img-' + id + '-' + side);
    var imgHtml = '<img id="eap-fayda-prev-img-' + id + '-' + side + '" src="' + e.target.result + '" style="max-width:120px;max-height:80px;border-radius:6px;border:1px solid rgba(212,175,55,.3);">';
    if (existing) { existing.outerHTML = imgHtml; } else { prev.insertAdjacentHTML('beforeend', imgHtml); }
    var btn = document.getElementById('eap-fayda-submit-' + id);
    if (_eapFaydaData[id].front && _eapFaydaData[id].back) btn.disabled = false;
  };
  reader.readAsDataURL(f);
}
async function eapMemFaydaUpload(id) {
  var d = _eapFaydaData[id];
  if (!d || !d.front || !d.back) return;
  var btn = document.getElementById('eap-fayda-submit-' + id);
  btn.disabled = true; btn.textContent = 'Saving...';
  var res = await _eidPanel('etelid_panel_fayda_id_upload', { id: id, front_image: d.front, back_image: d.back });
  _eidPanelMsg('eap-fayda-msg-' + id, res.data.message || '', res.success);
  btn.textContent = 'Save & Approve Pass 2';
  if (res.success) { delete _eapFaydaData[id]; eapMemOpenDetail(id); eapMemLoad(_eapMemPage); }
  else btn.disabled = false;
}
async function eapMemFaydaReview(id, decision) {
  var noteEl = document.getElementById('eap-fayda-note-' + id);
  var res = await _eidPanel('etelid_panel_fayda_id_review', { id: id, decision: decision, note: noteEl ? noteEl.value : '' });
  _eidPanelMsg('eap-fayda-msg-' + id, res.data.message || '', res.success);
  if (res.success) { eapMemOpenDetail(id); eapMemLoad(_eapMemPage); }
}
async function eapMemSave(id){
  var res = await _eidPanel('etelid_panel_members_update', {
    id: id,
    full_name: document.getElementById('eap-em-name').value,
    full_name_am: document.getElementById('eap-em-name-am').value,
    phone: document.getElementById('eap-em-phone').value,
    email: document.getElementById('eap-em-email').value,
    primary_company: document.getElementById('eap-em-company').value,
    role: document.getElementById('eap-em-role').value,
    department: document.getElementById('eap-em-dept').value,
    tier: document.getElementById('eap-em-tier').value,
    notes: document.getElementById('eap-em-notes').value
  });
  _eidPanelMsg('eap-em-msg', res.data.message || (res.success ? 'Saved.' : 'Failed.'), res.success);
  if (res.success) eapMemLoad(_eapMemPage);
}
async function eapMemStatus(id, status){
  var res = await _eidPanel('etelid_panel_members_status', { id: id, status: status });
  _eidPanelMsg('eap-em-msg', res.data.message || '', res.success);
  if (res.success) eapMemLoad(_eapMemPage);
}
async function eapMemVerifyPhone(id){
  if (!confirm('Mark this phone number as verified? Only do this after you have confirmed the person owns it.')) return;
  var res = await _eidPanel('etelid_panel_members_verify_phone', { id: id });
  _eidPanelMsg('eap-em-msg', (res.data && res.data.message) || '', res.success);
  if (res.success) eapMemOpenDetail(id);
}
async function eapMemDelete(id){
  if (!confirm('Permanently delete this member? This cannot be undone.')) return;
  var res = await _eidPanel('etelid_panel_members_delete', { id: id });
  if (res.success) { document.getElementById('eap-mem-detail').style.display='none'; eapMemLoad(_eapMemPage); }
  else _eidPanelMsg('eap-em-msg', res.data.message || 'Failed.', false);
}

/* ════════ PAGES ════════ */
function openPagesModal(e, mode){ if(e)e.preventDefault(); _eapOpen('eap-pages-modal'); if (mode==='new') eapPageEdit(0); else eapPagesShowList(); }
function closePagesModal(){ _eapClose('eap-pages-modal'); }
function eapPagesShowList(){
  document.getElementById('eap-pages-list-view').style.display = 'block';
  document.getElementById('eap-pages-edit-view').style.display = 'none';
  eapPagesLoad();
}
async function eapPagesLoad(){
  var el = document.getElementById('eap-pages-list');
  el.innerHTML = '<div style="color:#666;font-size:13px;">Loading…</div>';
  var res = await _eidPanel('etelid_panel_pages_list', {});
  if (!res.success || !res.data.pages.length) { el.innerHTML = '<div style="color:#666;font-size:13px;padding:14px;">No pages yet.</div>'; return; }
  var html = '';
  res.data.pages.forEach(function(p){
    html += '<div class="eap-row">'
      + '<div style="flex:1;cursor:pointer;" onclick="eapPageEdit(' + p.id + ')"><div style="font-weight:700;color:#e8e2d4;">' + p.title + '</div>'
      + '<div style="font-size:11px;color:#998;">' + p.status + ' · modified ' + p.modified + '</div></div>'
      + '<button class="eap-btn-danger" style="padding:4px 10px;" onclick="event.stopPropagation();eapPageDelete(' + p.id + ')">Delete</button></div>';
  });
  el.innerHTML = html;
}
async function eapPageEdit(id){
  document.getElementById('eap-pages-list-view').style.display = 'none';
  document.getElementById('eap-pages-edit-view').style.display = 'block';
  document.getElementById('eap-page-id').value = id;
  document.getElementById('eap-page-msg').innerHTML = '';
  if (!id) {
    document.getElementById('eap-page-title').value = '';
    document.getElementById('eap-page-content').value = '';
    document.getElementById('eap-page-status').value = 'publish';
    return;
  }
  var res = await _eidPanel('etelid_panel_pages_get', { id: id });
  if (res.success) {
    document.getElementById('eap-page-title').value = res.data.title;
    document.getElementById('eap-page-content').value = res.data.content;
    document.getElementById('eap-page-status').value = res.data.status;
  }
}
async function eapPageSave(){
  var res = await _eidPanel('etelid_panel_pages_save', {
    id: document.getElementById('eap-page-id').value,
    title: document.getElementById('eap-page-title').value,
    content: document.getElementById('eap-page-content').value,
    status: document.getElementById('eap-page-status').value
  });
  _eidPanelMsg('eap-page-msg', res.data.message || '', res.success);
  if (res.success) document.getElementById('eap-page-id').value = res.data.id;
}
async function eapPageDelete(id){
  if (!confirm('Move this page to trash?')) return;
  var res = await _eidPanel('etelid_panel_pages_delete', { id: id });
  if (res.success) eapPagesLoad();
}

/* ════════ MEDIA ════════ */
function openMediaModal(e){ if(e)e.preventDefault(); _eapOpen('eap-media-modal'); eapMediaLoad(); }
function closeMediaModal(){ _eapClose('eap-media-modal'); }
async function eapMediaLoad(){
  var el = document.getElementById('eap-media-grid');
  el.innerHTML = '<div style="color:#666;font-size:13px;">Loading…</div>';
  var res = await _eidPanel('etelid_panel_media_list', {});
  if (!res.success || !res.data.media.length) { el.innerHTML = '<div style="color:#666;font-size:13px;">No media yet.</div>'; return; }
  var html = '';
  res.data.media.forEach(function(m){
    html += '<div class="eap-media-item"><img src="' + m.thumb + '" loading="lazy"><button class="eap-media-del" onclick="eapMediaDelete(' + m.id + ')">✕</button></div>';
  });
  el.innerHTML = html;
}
async function eapMediaUpload(){
  var f = document.getElementById('eap-media-file').files[0];
  if (!f) return;
  document.getElementById('eap-media-msg').textContent = 'Uploading…';
  var res = await _eidPanel('etelid_panel_media_upload', { file: f }, true);
  document.getElementById('eap-media-msg').textContent = res.success ? 'Uploaded.' : (res.data.message || 'Failed.');
  if (res.success) eapMediaLoad();
  document.getElementById('eap-media-file').value = '';
}
async function eapMediaDelete(id){
  if (!confirm('Delete this file permanently?')) return;
  var res = await _eidPanel('etelid_panel_media_delete', { id: id });
  if (res.success) eapMediaLoad();
}

/* ════════ MENUS ════════ */
function openMenusModal(e){ if(e)e.preventDefault(); _eapOpen('eap-menus-modal'); eapMenusLoad(); }
function closeMenusModal(){ _eapClose('eap-menus-modal'); }
async function eapMenusLoad(){
  var sel = document.getElementById('eap-menu-select');
  sel.innerHTML = '<option>Loading…</option>';
  var res = await _eidPanel('etelid_panel_menus_list', {});
  if (!res.success || !res.data.menus.length) { sel.innerHTML = '<option>No menus found</option>'; return; }
  sel.innerHTML = res.data.menus.map(function(m){ return '<option value="' + m.id + '">' + m.name + ' (' + m.count + ' items)</option>'; }).join('');
  eapMenuLoadItems();
}
async function eapMenuLoadItems(){
  var menuId = document.getElementById('eap-menu-select').value;
  var wrap = document.getElementById('eap-menu-items');
  wrap.innerHTML = '<div style="color:#666;font-size:13px;">Loading…</div>';
  if (!menuId) { wrap.innerHTML = ''; return; }
  var res = await _eidPanel('etelid_panel_menu_items', { menu_id: menuId });
  wrap.innerHTML = '';
  if (res.success && res.data.items.length) {
    res.data.items.forEach(function(it){ eapMenuAddRow(it.id, it.title, it.url); });
  }
}
function eapMenuAddRow(id, title, url){
  var wrap = document.getElementById('eap-menu-items');
  var row = document.createElement('div');
  row.style.cssText = 'display:flex;gap:8px;margin-bottom:8px;align-items:center;';
  row.innerHTML = '<input type="hidden" class="eap-mi-id" value="' + (id||0) + '">'
    + '<input type="text" class="eap-input eap-mi-title" placeholder="Label" style="flex:1;" value="' + (title||'').replace(/"/g,'&quot;') + '">'
    + '<input type="text" class="eap-input eap-mi-url" placeholder="URL" style="flex:2;" value="' + (url||'') + '">'
    + '<button class="eap-btn-danger" style="padding:8px 10px;" onclick="this.parentNode.remove()">✕</button>';
  wrap.appendChild(row);
}
async function eapMenuSave(){
  var menuId = document.getElementById('eap-menu-select').value;
  var rows = document.querySelectorAll('#eap-menu-items > div');
  var items = [];
  rows.forEach(function(r, idx){
    items.push({
      id: r.querySelector('.eap-mi-id').value,
      title: r.querySelector('.eap-mi-title').value,
      url: r.querySelector('.eap-mi-url').value,
      order: idx + 1
    });
  });
  var res = await _eidPanel('etelid_panel_menu_save', { menu_id: menuId, items: JSON.stringify(items) });
  _eidPanelMsg('eap-menu-msg', res.data.message || '', res.success);
  if (res.success) eapMenuLoadItems();
}

/* ════════ BRANDING ════════ */
function openBrandingModal(e){ if(e)e.preventDefault(); _eapOpen('eap-branding-modal'); eapBrandingLoad(); }
function closeBrandingModal(){ _eapClose('eap-branding-modal'); }
async function eapBrandingLoad(){
  var res = await _eidPanel('etelid_panel_branding_get', {});
  if (!res.success) return;
  var d = res.data;
  document.getElementById('eap-b-title').value = d.site_title || '';
  document.getElementById('eap-b-tagline').value = d.tagline || '';
  document.getElementById('eap-b-org').value = d.org_name || '';
  document.getElementById('eap-b-website').value = d.org_website || '';
  document.getElementById('eap-b-email').value = d.org_email || '';
  document.getElementById('eap-b-gold').value = d.gold_accent || '#D4AF37';
  document.getElementById('eap-b-css').value = d.custom_css || '';
  var prev = document.getElementById('eap-b-logo-preview');
  if (d.logo_url) { prev.src = d.logo_url; prev.style.display = 'inline-block'; prev.setAttribute('data-id', d.logo_id); }
}
async function eapBrandingUploadLogo(){
  var f = document.getElementById('eap-b-logo-file').files[0];
  if (!f) return;
  var res = await _eidPanel('etelid_panel_media_upload', { file: f }, true);
  if (res.success) {
    var prev = document.getElementById('eap-b-logo-preview');
    prev.src = res.data.url; prev.style.display = 'inline-block'; prev.setAttribute('data-id', res.data.id);
  }
}
async function eapBrandingSave(){
  var logoEl = document.getElementById('eap-b-logo-preview');
  var res = await _eidPanel('etelid_panel_branding_save', {
    site_title: document.getElementById('eap-b-title').value,
    tagline: document.getElementById('eap-b-tagline').value,
    org_name: document.getElementById('eap-b-org').value,
    org_website: document.getElementById('eap-b-website').value,
    org_email: document.getElementById('eap-b-email').value,
    gold_accent: document.getElementById('eap-b-gold').value,
    custom_css: document.getElementById('eap-b-css').value,
    logo_id: logoEl.getAttribute('data-id') || 0
  });
  _eidPanelMsg('eap-branding-msg', res.data.message || '', res.success);
}

/* ════════ RESET PASSWORD ════════ */
function openResetPwModal(e, presetId, presetName){
  if(e)e.preventDefault();
  _eapOpen('eap-resetpw-modal');
  document.getElementById('eap-rp-results').innerHTML = '';
  document.getElementById('eap-rp-form').style.display = 'none';
  document.getElementById('eap-rp-msg').innerHTML = '';
  if (presetId) {
    document.getElementById('eap-rp-id').value = presetId;
    document.getElementById('eap-rp-who').textContent = presetName || ('Member #' + presetId);
    document.getElementById('eap-rp-form').style.display = 'block';
  }
}
function closeResetPwModal(){ _eapClose('eap-resetpw-modal'); }
async function eapRpSearch(){
  var q = document.getElementById('eap-rp-search').value;
  var el = document.getElementById('eap-rp-results');
  el.innerHTML = 'Searching…';
  var res = await _eidPanel('etelid_panel_members_list', { source: 'etelid', page: 1, search: q, status: '' });
  if (!res.success || !res.data.members.length) { el.innerHTML = '<div style="color:#666;font-size:12px;">No matches.</div>'; return; }
  el.innerHTML = res.data.members.slice(0,8).map(function(m){
    return '<div class="eap-row" onclick="eapRpPick(' + m.id + ',\'' + m.name.replace(/'/g,"\\'") + ' · ' + m.etel_id + '\')">' + m.name + ' <span style="color:#998;font-size:11px;">' + m.etel_id + ' · ' + m.phone + '</span></div>';
  }).join('');
}
function eapRpPick(id, label){
  document.getElementById('eap-rp-id').value = id;
  document.getElementById('eap-rp-who').textContent = label;
  document.getElementById('eap-rp-form').style.display = 'block';
}
async function eapRpSubmit(){
  var res = await _eidPanel('etelid_panel_reset_password', {
    id: document.getElementById('eap-rp-id').value,
    new_password: document.getElementById('eap-rp-pass').value,
    confirm_password: document.getElementById('eap-rp-confirm').value
  });
  _eidPanelMsg('eap-rp-msg', res.data.message || '', res.success);
  if (res.success) { document.getElementById('eap-rp-pass').value=''; document.getElementById('eap-rp-confirm').value=''; }
}


/* ════════ TWO-STEP SIGN-IN (authenticator app) ════════ */
function _tf(id){ return document.getElementById(id); }
function _tfView(which){
  ['off','setup','codes','on'].forEach(function(v){ _tf('eap-2fa-'+v).style.display = (v===which) ? '' : 'none'; });
}
function _tfMsg(text, ok){
  var el = _tf('eap-2fa-status'); el.style.color = ok ? '#6ee7a0' : '#f66'; el.textContent = text || '';
}
async function openTwoFaModal(e){
  if (e) e.preventDefault();
  _eapOpen('eap-2fa-modal');
  _tfMsg('', true);
  var res = await _eidPanel('etelid_2fa_status', {});
  if (!res.success) { _tfMsg((res.data && res.data.message) || 'Could not load.', false); _tfView(''); return; }
  if (res.data.enabled) {
    _tf('eap-2fa-left').textContent = res.data.backup_left + ' of 8 backup codes left.';
    _tfView('on');
  } else { _tfView('off'); }
}
function closeTwoFaModal(){ _eapClose('eap-2fa-modal'); _tf('eap-2fa-pw').value=''; _tf('eap-2fa-code').value=''; _tf('eap-2fa-manage-code').value=''; _tf('eap-2fa-secret').textContent=''; _tf('eap-2fa-qr').textContent=''; }
async function eapTwoFaBegin(){
  var res = await _eidPanel('etelid_2fa_begin', { password: _tf('eap-2fa-pw').value });
  if (!res.success) { _tfMsg((res.data && res.data.message) || 'Could not start.', false); return; }
  _tf('eap-2fa-pw').value = '';
  _tfMsg('', true);
  /* the QR is drawn here in the browser: the key never goes to any outside service */
  var box = _tf('eap-2fa-qr'); box.textContent = '';
  try { var qr = qrcode(0, 'M'); qr.addData(res.data.uri); qr.make(); box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2 }); } catch (e) {}
  _tf('eap-2fa-secret').textContent = res.data.secret.replace(/(.{4})/g, '$1 ').trim();
  _tfView('setup');
}
function _tfShowCodes(codes){
  _tf('eap-2fa-codes-list').textContent = codes.join('\n');
  _tfView('codes');
}
async function eapTwoFaEnable(){
  var res = await _eidPanel('etelid_2fa_enable', { code: _tf('eap-2fa-code').value });
  if (!res.success) { _tfMsg((res.data && res.data.message) || 'That code is not right.', false); return; }
  _tf('eap-2fa-code').value = ''; _tfMsg('Two-step sign-in is on.', true);
  _tfShowCodes(res.data.backup_codes);
}
function eapTwoFaCodesDone(){
  _tf('eap-2fa-codes-list').textContent = '';
  _tf('eap-2fa-left').textContent = '8 of 8 backup codes left.';
  _tfView('on');
}
async function eapTwoFaNewCodes(){
  var res = await _eidPanel('etelid_2fa_backup_codes', { code: _tf('eap-2fa-manage-code').value });
  if (!res.success) { _tfMsg((res.data && res.data.message) || 'That code is not right.', false); return; }
  _tf('eap-2fa-manage-code').value = ''; _tfMsg('New backup codes created. The old ones no longer work.', true);
  _tfShowCodes(res.data.backup_codes);
}
async function eapTwoFaDisable(){
  if (!confirm('Turn off two-step sign-in? Your account will be protected by the password only.')) return;
  var res = await _eidPanel('etelid_2fa_disable', { code: _tf('eap-2fa-manage-code').value });
  if (!res.success) { _tfMsg((res.data && res.data.message) || 'That code is not right.', false); return; }
  _tf('eap-2fa-manage-code').value = ''; _tfMsg('Two-step sign-in is off.', true);
  _tfView('off');
}
/* admin, in the Reset Password panel: member lost phone AND backup codes */
async function eapRpReset2fa(){
  var id = _tf('eap-rp-id').value;
  if (!id || !confirm('Reset this member\'s two-step sign-in? They will sign in with the password only until they set it up again.')) return;
  var res = await _eidPanel('etelid_panel_reset_2fa', { member_id: id });
  _eidPanelMsg('eap-rp-msg', (res.data && res.data.message) || '', res.success);
}
