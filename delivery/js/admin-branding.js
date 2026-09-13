/* ============================================================
   ETEL Delivery — Admin Branding Panel
   Lets an admin replace the site favicon, every app's PWA icon set,
   the shared splash-screen logo, and every app's login-page header
   logo directly - no code deploy needed for any of it. Posts to the
   standalone api/admin-branding.php (not boot.php - boot.php only
   ever parses JSON bodies, it can't accept a multipart file upload
   at all, same reason api/upload.php already had to be its own
   script rather than a boot.php action).
   ============================================================ */

const BRANDING_SLOTS = [
  { group: 'PWA App Icons', items: [
    { slot: 'icon_driver',  label: '🚖 Driver',   preview: 'icons/driver-icon-192.png' },
    { slot: 'icon_ride',    label: '🛺 Ride',     preview: 'icons/ride-icon-192.png' },
    { slot: 'icon_courier', label: '📦 Courier',  preview: 'icons/courier-icon-192.png' },
    { slot: 'icon_service', label: '🏪 Service',  preview: 'icons/service-icon-192.png' },
    { slot: 'icon_admin',   label: '🛡️ Admin',    preview: 'icons/admin-icon-192.png' },
    { slot: 'icon_default', label: '🛍️ Customer', preview: 'icons/icon-192.png' },
  ]},
  { group: 'Login Page Header Logos', items: [
    { slot: 'logo_driver',  label: '🚖 Driver',   preview: 'assets/images/logo-driver.png' },
    { slot: 'logo_ride',    label: '🛺 Ride',     preview: 'assets/images/logo-ride.png' },
    { slot: 'logo_courier', label: '📦 Courier',  preview: 'assets/images/logo-courier.png' },
    { slot: 'logo_service', label: '🏪 Service',  preview: 'assets/images/logo-service.png' },
    { slot: 'logo_admin',   label: '🛡️ Admin',    preview: 'assets/images/logo-admin.png' },
    { slot: 'logo_default', label: '🛍️ Customer', preview: 'assets/images/logo.png' },
  ]},
  { group: 'Splash & Favicon', items: [
    { slot: 'splash',  label: '✨ Splash logo (all apps, shown briefly on open)', preview: 'assets/images/master-logo-splash.png' },
    { slot: 'favicon', label: '🌐 Browser tab favicon', preview: 'favicon.png' },
  ]},
];

function brandEsc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}

function loadBrandingPanel() {
  const wrap = document.querySelector('#panel-branding .data-section');
  if (!wrap) return;
  if (wrap.dataset.built === '1') return; // built once, slots refresh themselves on upload
  wrap.dataset.built = '1';

  const base = (typeof ETEL !== 'undefined' && ETEL.api) ? ETEL.api.replace('api/boot.php', '') : '';
  const bust = '?v=' + Date.now();

  let html = `
    <div style="background:rgba(212,175,55,.06);border:1px solid rgba(212,175,55,.2);border-radius:12px;padding:14px 16px;margin-bottom:20px;font-size:12.5px;color:var(--text-dim,#9A9AAE);line-height:1.6">
      Upload an image for any slot below and it replaces the real file that app already uses - no code deploy, no manifest edit. PWA icon slots automatically generate every size Android/iOS needs from whatever you upload (best results with a square image). Header logo slots keep your image's natural aspect ratio.
    </div>`;

  BRANDING_SLOTS.forEach(section => {
    html += `<div style="margin-bottom:24px">
      <div style="font-size:13px;font-weight:700;color:var(--gold-lt,#D4AF37);text-transform:uppercase;letter-spacing:.04em;margin-bottom:12px">${brandEsc(section.group)}</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px">`;

    section.items.forEach(item => {
      html += `
        <div class="brand-slot" data-slot="${item.slot}" style="background:var(--sheet2,#191926);border:1px solid rgba(212,175,55,.15);border-radius:14px;padding:14px;text-align:center">
          <div style="width:72px;height:72px;margin:0 auto 10px;border-radius:12px;overflow:hidden;background:rgba(255,255,255,.04);display:flex;align-items:center;justify-content:center">
            <img id="brand-preview-${item.slot}" src="${base}${item.preview}${bust}" alt=""
                 style="max-width:100%;max-height:100%;object-fit:contain"
                 onerror="this.style.opacity='0.25';this.src='';this.alt='no image yet'">
          </div>
          <div style="font-size:12.5px;font-weight:700;color:var(--text,#EDEDF5);margin-bottom:10px">${brandEsc(item.label)}</div>
          <input type="file" id="brand-file-${item.slot}" accept="image/png,image/jpeg,image/webp,image/gif" style="display:none" onchange="brandFileChosen('${item.slot}')">
          <button class="btn btn-secondary" style="width:100%;padding:8px;font-size:12px" onclick="document.getElementById('brand-file-${item.slot}').click()">📤 Choose image</button>
          <div id="brand-status-${item.slot}" style="font-size:11px;margin-top:8px;min-height:14px"></div>
        </div>`;
    });

    html += `</div></div>`;
  });

  wrap.innerHTML = html;
}

function brandFileChosen(slot) {
  const input = document.getElementById('brand-file-' + slot);
  if (!input.files || !input.files[0]) return;
  brandUpload(slot, input.files[0]);
}

function brandUpload(slot, file) {
  const statusEl = document.getElementById('brand-status-' + slot);
  const previewEl = document.getElementById('brand-preview-' + slot);
  const btnEl = document.querySelector(`.brand-slot[data-slot="${slot}"] button`);

  if (statusEl) { statusEl.style.color = '#D4AF37'; statusEl.textContent = 'Uploading…'; }
  if (btnEl) btnEl.disabled = true;

  // Instant local preview while the real upload/resize happens server-side.
  if (previewEl) {
    const reader = new FileReader();
    reader.onload = e => { previewEl.style.opacity = '1'; previewEl.src = e.target.result; };
    reader.readAsDataURL(file);
  }

  const url = (typeof ETEL !== 'undefined' && ETEL.api) ? ETEL.api.replace('boot.php', 'admin-branding.php') : 'api/admin-branding.php';
  const fd = new FormData();
  fd.append('image', file);
  fd.append('slot', slot);

  fetch(url, {
    method: 'POST',
    headers: { 'Authorization': 'Bearer ' + (typeof Auth !== 'undefined' ? Auth.getToken() : '') },
    body: fd,
  })
    .then(r => r.json())
    .then(res => {
      if (btnEl) btnEl.disabled = false;
      if (res && res.success) {
        if (statusEl) { statusEl.style.color = '#2ECC71'; statusEl.textContent = '✓ Updated'; }
        // Re-fetch the real saved file (not just the local preview) with a
        // fresh cache-buster, so what's shown here matches exactly what
        // every real page will now load.
        if (previewEl) {
          const base = (typeof ETEL !== 'undefined' && ETEL.api) ? ETEL.api.replace('api/boot.php', '') : '';
          const slotCfg = BRANDING_SLOTS.flatMap(s => s.items).find(i => i.slot === slot);
          if (slotCfg) previewEl.src = base + slotCfg.preview + '?v=' + Date.now();
        }
        if (res.data && res.data.note && statusEl) {
          statusEl.title = res.data.note;
        }
        if (typeof toast === 'function') toast(res.data && res.data.message ? res.data.message : 'Branding updated', 'ok');
      } else {
        if (statusEl) { statusEl.style.color = '#E63946'; statusEl.textContent = '✗ ' + ((res && res.message) || 'Upload failed'); }
        if (typeof toast === 'function') toast((res && res.message) || 'Upload failed', 'err');
      }
    })
    .catch(err => {
      if (btnEl) btnEl.disabled = false;
      if (statusEl) { statusEl.style.color = '#E63946'; statusEl.textContent = '✗ Network error'; }
      if (typeof toast === 'function') toast('Network error: ' + (err && err.message ? err.message : 'unknown'), 'err');
    });
}
