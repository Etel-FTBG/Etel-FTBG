/* ============================================================
   ETEL Delivery — Premium Couriers Management
   Overrides admin.js's plain renderCourierTable() with the same
   3D glass/gold visual language as Equity/Platform Economics/Equb.
   Loaded AFTER admin.js so this definition wins (later <script> tags
   override earlier function declarations of the same name) - same
   safe technique used all session for Roles/Equity/Equb, never
   editing the giant admin.js directly.
   ============================================================ */

const COURIER_VEHICLE_ICON = { motorcycle: '🛵', bicycle: '🚲', car: '🚗', on_foot: '🚶' };
const COURIER_STATUS_STYLE = {
  online: { grad: 'linear-gradient(135deg,#3fce7a,#1f8f52)', glow: 'rgba(63,206,122,.45)', label: 'ONLINE' },
  busy: { grad: 'linear-gradient(135deg,#ffce54,#d4941f)', glow: 'rgba(255,206,84,.45)', label: 'BUSY' },
  offline: { grad: 'linear-gradient(135deg,#6a6a78,#40404a)', glow: 'rgba(120,120,130,.35)', label: 'OFFLINE' },
};

function cpEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }

function courierRingHtml(onlinePct) {
  const deg = Math.max(0, Math.min(100, onlinePct)) * 3.6;
  return `<div class="ring-wrap" style="position:relative;width:64px;height:64px;flex:none">
    <div style="position:absolute;inset:0;border-radius:50%;animation:eqSpin3d 12s linear infinite;background:conic-gradient(#3fce7a ${deg}deg, rgba(255,255,255,.08) ${deg}deg)"></div>
    <div style="position:absolute;inset:6px;border-radius:50%;background:radial-gradient(circle at 35% 30%,#1a2230,#080a0e 75%);box-shadow:inset 0 3px 8px rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:800;color:#3fce7a">${Math.round(onlinePct)}%</div>
  </div>`;
}

// Override the plain table renderer entirely.
function renderCourierTable(data) {
  if (typeof ensureEquityPremiumCss === 'function') ensureEquityPremiumCss();
  COURIER_DATA_CACHE = data;
  const panel = document.getElementById('panel-couriers');
  const section = panel ? panel.querySelector('.data-section') : null;
  if (!section) return;

  // admin.js's loadCouriers() unconditionally does
  // document.getElementById('couriers-tbody').innerHTML = '...loading...'
  // on every call, with no null-check. Our render below replaces
  // .data-section's entire contents (which originally contained the
  // real #couriers-tbody), so without this, the very next loadCouriers()
  // call - e.g. right after a successful edit save - throws
  // "Cannot set properties of null" and the refresh silently fails.
  // Keeping a permanent, hidden, unused tbody as a sibling of
  // .data-section (never touched by our re-renders) gives admin.js a
  // real element to write into harmlessly.
  if (panel && !panel.querySelector('table[data-courier-tbody-shim]')) {
    const dummy = document.createElement('table');
    dummy.setAttribute('data-courier-tbody-shim', '1');
    dummy.style.display = 'none';
    dummy.innerHTML = '<tbody id="couriers-tbody"></tbody>';
    panel.appendChild(dummy);
  }

  const total = data.length;
  const online = data.filter(c => (c.status || 'offline') === 'online').length;
  const busy = data.filter(c => (c.status || 'offline') === 'busy').length;
  const offline = total - online - busy;
  const onlinePct = total > 0 ? (online / total) * 100 : 0;

  const cardsHtml = data.length ? data.map(c => {
    const vehicleIcon = COURIER_VEHICLE_ICON[c.vehicle_type] || '📦';
    const statusKey = (c.status || 'offline');
    const st = COURIER_STATUS_STYLE[statusKey] || COURIER_STATUS_STYLE.offline;
    const inactiveTag = c.active == 0 ? '<span style="padding:3px 9px;border-radius:20px;font-size:9px;font-weight:800;color:#ff9aa4;background:rgba(255,93,108,.15);border:1px solid rgba(255,93,108,.3);margin-left:6px">SUSPENDED</span>' : '';
    return `
    <div class="eq-card" style="margin-bottom:0;${c.active == 0 ? 'opacity:.55' : ''}">
      <div style="display:flex;align-items:center;gap:14px;margin-bottom:14px">
        <div style="width:52px;height:52px;border-radius:16px;flex:none;background:linear-gradient(135deg, rgba(90,169,255,.18), rgba(90,169,255,.03));border:1px solid rgba(90,169,255,.3);display:flex;align-items:center;justify-content:center;font-size:24px;box-shadow:0 8px 18px -4px rgba(0,0,0,.4)">${vehicleIcon}</div>
        <div style="min-width:0;flex:1">
          <div style="font-size:15px;font-weight:700;color:#f8f1e2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${cpEsc(c.name)}${inactiveTag}</div>
          <div style="font-size:11px;color:#8a7f66;font-family:'JetBrains Mono',monospace">${cpEsc(c.courier_code || '—')}</div>
        </div>
        <span style="padding:4px 11px;border-radius:20px;font-size:9.5px;font-weight:800;letter-spacing:.04em;color:#0d0d0d;background:${st.grad};box-shadow:0 4px 12px ${st.glow};flex:none">${st.label}</span>
      </div>
      <table style="width:100%"><tbody>
        <tr><td style="color:#8a7f66;padding:6px 0;font-size:11.5px">Phone</td><td style="text-align:right;color:#cabf9f;font-family:'JetBrains Mono',monospace;font-size:12px">${cpEsc(c.phone)}</td></tr>
        <tr><td style="color:#8a7f66;padding:6px 0;font-size:11.5px">Vehicle</td><td style="text-align:right;color:#cabf9f;font-size:12.5px">${cpEsc(c.vehicle_type || '—')}${c.plate_number ? ' · ' + cpEsc(c.plate_number) : ''}</td></tr>
        <tr><td style="color:#8a7f66;padding:6px 0;font-size:11.5px">Zone</td><td style="text-align:right;color:#cabf9f;font-size:12.5px">${cpEsc(c.zone || c.home_zone || '—')}</td></tr>
        <tr><td style="color:#8a7f66;padding:6px 0;font-size:11.5px">Rating</td><td style="text-align:right;color:#F2CC6B;font-size:12.5px">⭐ ${parseFloat(c.rating || 0).toFixed(2)}</td></tr>
        <tr><td style="color:#8a7f66;padding:6px 0;font-size:11.5px">Deliveries</td><td style="text-align:right;color:#f8f1e2;font-weight:700;font-size:12.5px">${c.total_deliveries || 0}</td></tr>
      </tbody></table>
      <div style="display:flex;gap:8px;margin-top:12px">
        <button class="eq-btn" style="flex:1;font-size:12px" onclick="openEditCourierModal(${c.id})">✏️ Edit</button>
        <button class="eq-btn" style="flex:1;font-size:12px" onclick="printCourierSlip('${cpEsc(c.courier_code || '').replace(/'/g, "\\'")}')">🖨️ Slip</button>
      </div>
    </div>`;
  }).join('') : '<div class="eq-empty" style="grid-column:1/-1"><span class="eq-emoji">🛵</span>No couriers found</div>';

  section.innerHTML = `
    <div class="eq-grid" style="margin-bottom:22px">
      <div class="eq-card eq-kpi"><div class="eq-icon">👥</div><div class="eq-label">Total Couriers</div><div class="eq-value">${total}</div></div>
      <div class="eq-card eq-kpi"><div class="eq-icon">🟢</div><div class="eq-label">Online</div><div class="eq-value">${online}</div></div>
      <div class="eq-card eq-kpi"><div class="eq-icon">🟡</div><div class="eq-label">Busy</div><div class="eq-value">${busy}</div></div>
      <div class="eq-card eq-kpi" style="display:flex;align-items:center;gap:14px;margin-bottom:0">
        ${courierRingHtml(onlinePct)}
        <div><div class="eq-label">Availability</div><div style="font-size:11.5px;color:#8a7f66;margin-top:4px">${offline} offline</div></div>
      </div>
    </div>
    <div class="eq-card" style="margin-bottom:22px">
      <div class="eq-card-head" style="cursor:pointer" onclick="toggleCourierLiveMap()">
        <h2>🗺️ Live Courier Map</h2>
        <span class="eq-tag" id="courier-map-toggle-tag">Show</span>
      </div>
      <div id="courier-live-map-wrap" style="display:none">
        <div id="courier-live-map" style="width:100%;height:360px;border-radius:14px;overflow:hidden;background:#191926"></div>
        <div style="font-size:11px;color:#8a7f66;margin-top:8px" id="courier-map-updated">—</div>
      </div>
    </div>
    <div class="section-title" style="margin-bottom:14px">All Couriers</div>
    <div class="eq-grid" style="grid-template-columns:repeat(auto-fill,minmax(270px,1fr))" id="courier-cards-grid">
      ${cardsHtml}
    </div>`;
}

// filterCourierTable() in admin.js already calls renderCourierTable(filtered)
// on the same global couriersData array, so overriding the render
// function alone is sufficient - the search box keeps working as-is.

let COURIER_DATA_CACHE = [];

// ── Edit modal: admin directly assigns vehicle type, zone, plate, and suspends/reactivates ──
function openEditCourierModal(courierId) {
  const c = COURIER_DATA_CACHE.find(x => x.id === courierId);
  if (!c) return;
  openModal('custom', eqModalWrap(`
    <h2 style="margin-bottom:16px;color:#F2CC6B;font-family:'Syne',sans-serif">✏️ Edit ${cpEsc(c.name)}</h2>
    <label style="display:block;font-size:11px;color:#8a7f66;text-transform:uppercase;letter-spacing:.06em;font-weight:700;margin-bottom:6px">Vehicle Type</label>
    <select id="ec-vehicle" style="width:100%;padding:11px;border-radius:10px;border:1px solid rgba(212,175,55,.3);background:#191926;color:#EDEDF5;margin-bottom:14px;box-sizing:border-box">
      ${['motorcycle', 'bicycle', 'car', 'on_foot'].map(v => `<option value="${v}" ${c.vehicle_type === v ? 'selected' : ''}>${COURIER_VEHICLE_ICON[v]} ${v.replace('_', ' ')}</option>`).join('')}
    </select>
    <label style="display:block;font-size:11px;color:#8a7f66;text-transform:uppercase;letter-spacing:.06em;font-weight:700;margin-bottom:6px">Plate Number</label>
    <input id="ec-plate" value="${cpEsc(c.plate_number || '')}" style="width:100%;padding:11px;border-radius:10px;border:1px solid rgba(212,175,55,.3);background:#191926;color:#EDEDF5;margin-bottom:14px;box-sizing:border-box">
    <label style="display:block;font-size:11px;color:#8a7f66;text-transform:uppercase;letter-spacing:.06em;font-weight:700;margin-bottom:6px">Assigned Zone</label>
    <input id="ec-zone" value="${cpEsc(c.zone || c.home_zone || '')}" style="width:100%;padding:11px;border-radius:10px;border:1px solid rgba(212,175,55,.3);background:#191926;color:#EDEDF5;margin-bottom:16px;box-sizing:border-box">
    <label style="display:flex;align-items:center;gap:8px;font-weight:400;text-transform:none;letter-spacing:0;font-size:13px;color:#cabf9f;margin-bottom:18px">
      <input type="checkbox" id="ec-active" ${c.active != 0 ? 'checked' : ''} style="width:auto;margin:0"> Active (uncheck to suspend this courier)
    </label>
    <button class="eq-btn primary" style="width:100%" onclick="saveCourierEdit(${c.id})">💾 Save Changes</button>
  `));
}

async function saveCourierEdit(courierId) {
  const payload = {
    courier_id: courierId,
    vehicle_type: document.getElementById('ec-vehicle').value,
    plate_number: document.getElementById('ec-plate').value.trim(),
    zone: document.getElementById('ec-zone').value.trim(),
    active: document.getElementById('ec-active').checked ? 1 : 0,
  };
  const res = await adm('admin_update_courier', payload);
  if (!res || !res.success) { toast((res && res.message) || 'Could not save', 'err'); return; }
  toast('✓ Courier updated');
  closeModal();
  loadCouriers();
}

// ── Live map ──
let COURIER_MAP_INSTANCE = null;
let COURIER_MAP_MARKERS = [];
let COURIER_MAP_TIMER = null;
let COURIER_MAP_INIT_STARTED = false;

function toggleCourierLiveMap() {
  const wrap = document.getElementById('courier-live-map-wrap');
  const tag = document.getElementById('courier-map-toggle-tag');
  if (!wrap) return;
  const showing = wrap.style.display !== 'none';
  wrap.style.display = showing ? 'none' : 'block';
  if (tag) tag.textContent = showing ? 'Show' : 'Hide';
  if (!showing) {
    initCourierLiveMapIfNeeded();
    if (COURIER_MAP_TIMER) clearInterval(COURIER_MAP_TIMER);
    COURIER_MAP_TIMER = setInterval(refreshCourierMapMarkers, 20000);
  } else if (COURIER_MAP_TIMER) {
    clearInterval(COURIER_MAP_TIMER);
    COURIER_MAP_TIMER = null;
  }
}

function initCourierLiveMapIfNeeded() {
  if (COURIER_MAP_INIT_STARTED) { if (COURIER_MAP_INSTANCE) refreshCourierMapMarkers(); return; }
  COURIER_MAP_INIT_STARTED = true;
  if (typeof loadGoogleMaps !== 'function') {
    const el = document.getElementById('courier-live-map');
    if (el) el.innerHTML = '<div style="padding:20px;color:#8a7f66;text-align:center">Map unavailable on this page.</div>';
    return;
  }
  window.courierMapCallback = function () {
    const el = document.getElementById('courier-live-map');
    if (!el || typeof google === 'undefined' || !google.maps) return;
    COURIER_MAP_INSTANCE = new google.maps.Map(el, {
      center: { lat: 7.0621, lng: 38.4761 }, // Hawassa
      zoom: 13,
      disableDefaultUI: true,
      zoomControl: true,
    });
    refreshCourierMapMarkers();
  };
  loadGoogleMaps('marker', 'courierMapCallback');
}

function refreshCourierMapMarkers() {
  if (!COURIER_MAP_INSTANCE) return;
  adm('admin_get_live_couriers', {}, true).then(res => {
    if (!res || !res.success) return;
    COURIER_MAP_MARKERS.forEach(m => { m.setMap(null); });
    COURIER_MAP_MARKERS = [];
    const couriers = (res.data && res.data.couriers) || [];
    couriers.forEach(c => {
      const st = COURIER_STATUS_STYLE[c.status] || COURIER_STATUS_STYLE.offline;
      const marker = new google.maps.Marker({
        position: { lat: parseFloat(c.last_lat), lng: parseFloat(c.last_lng) },
        map: COURIER_MAP_INSTANCE,
        title: `${c.name} — ${c.vehicle_type || ''} · ${c.zone || ''} (${c.status})`,
        label: { text: COURIER_VEHICLE_ICON[c.vehicle_type] || '📦', fontSize: '16px' },
        icon: {
          path: google.maps.SymbolPath.CIRCLE,
          fillColor: c.status === 'online' ? '#3fce7a' : '#ffce54',
          fillOpacity: 1,
          strokeColor: '#fff',
          strokeWeight: 2,
          scale: 16,
        },
      });
      COURIER_MAP_MARKERS.push(marker);
    });
    const updEl = document.getElementById('courier-map-updated');
    if (updEl) updEl.textContent = couriers.length + ' courier(s) live · updated ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }).catch(() => {});
}
