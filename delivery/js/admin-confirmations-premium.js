/* ============================================================
   ETEL Admin — Pending Confirmations: contrast fix + premium redesign
   Overrides loadConfirmations() from admin.js (loaded after it, same
   safe technique used for renderCourierTable earlier this session) -
   the original rendered pending-confirmation cards on a plain white
   background (#fff) that, on this device/browser, was rendering
   washed-out and barely readable. Rebuilt with the same dark-glass
   premium style used everywhere else in this admin panel.

   Also adds a one-time "Repair Historical Orders" button, calling
   the new admin_backfill_order_service_giver_ids action - fixes
   orders created before orders.service_giver_id was reliably set,
   which is why some pre-existing test orders' pending confirmations
   never surfaced on the natural courier/service-giver side even
   though "Force Confirm" here always worked (it bypasses that
   matching entirely).
   ============================================================ */

/* ============================================================
   GLOBAL MODAL FIX — applies to every modal in the entire system.
   Root cause: #modal-box (the one shared container reused by every
   openModal() call anywhere in admin.js/admin-*.js) has a hardcoded
   inline style="background:#fff" directly in admin.html, plus a
   matching .modal-box{background:#fff} rule in admin-group1.min.css.
   Every individual modal's own text then assumes a white background
   (dark navy/gray label colors meant for light text), so simply
   patching one modal at a time (as done earlier this session for the
   investor portal, courier edit modal, etc.) never fixes the next
   one that shows up. This fixes the shared container ONCE, globally,
   for every current and future modal - not scoped to any specific
   panel, since #modal-box is a page-level element outside all of them.
   ============================================================ */
(function () {
  if (document.getElementById('global-modal-fix-css')) return;
  var css = document.createElement('style');
  css.id = 'global-modal-fix-css';
  css.textContent = `
    #modal-box, .modal-box {
      background: linear-gradient(165deg, #1c1204, #0a0703) !important;
      border: 1px solid rgba(212,175,55,.35) !important;
      color: #f8f1e2;
      box-shadow: 0 30px 80px rgba(0,0,0,.6) !important;
    }
    #modal-box::before, .modal-box::before {
      content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; border-radius: 16px 16px 0 0;
      background: linear-gradient(90deg, transparent, #D4AF37, #FFE9A8, #D4AF37, transparent);
    }
    #modal-box .modal-title, .modal-box .modal-title { color: #F2CC6B; font-weight: 700; }
    #modal-box label, .modal-box label { color: #8a7f66; }
    #modal-box select, .modal-box select, #modal-box input, .modal-box input, #modal-box textarea, .modal-box textarea {
      background: rgba(255,255,255,.05) !important;
      border: 1px solid rgba(212,175,55,.3) !important;
      color: #f8f1e2 !important;
    }
    #modal-box .modal-submit, .modal-box .modal-submit {
      background: linear-gradient(135deg,#C8960C,#D4AF37) !important;
      color: #0d0d0d !important;
    }
  `;
  document.head.appendChild(css);
})();

// Fix the mislabeling bug: any non-'confirmed' truthy status (which
// includes 'rejected') was being shown generically as "⏳ Pending" -
// misleading when a request was actually rejected/cancelled, not
// genuinely awaiting anyone. Overrides openOrderDetail() from
// admin.js (loaded after it, same safe technique used elsewhere this
// session) with identical logic except this one fix.
function openOrderDetail(id, code, status) {
    const o = ordersData.find(x => x.id === id);
    if (!o) { openUpdateStatus(id, code, status); return; }
    function confLabel(conf, at) {
        if (!conf) return '<span style="color:#8a7f66">Not requested</span>';
        if (conf === 'confirmed') return `<span style="font-weight:600;color:#3fce7a">✅ Confirmed</span>${at ? ' at ' + fmtTime(at) : ''}`;
        if (conf === 'pending') return `<span style="font-weight:600;color:#ffce54">⏳ Pending — waiting for response</span>`;
        if (conf === 'rejected') return `<span style="font-weight:600;color:#ff5d6c">✕ Rejected/cancelled</span>${at ? ' at ' + fmtTime(at) : ''}`;
        return `<span style="color:#8a7f66">${conf}</span>`;
    }
    const pickupConf = confLabel(o.pickup_conf, o.pickup_conf_at);
    const delivConf = confLabel(o.delivery_conf, o.delivery_conf_at);
    const opts = ['pending','assigned','picked_up','in_transit','delivered','cancelled'].map(s => `<option value="${s}" ${s===status?'selected':''}>${s.replace(/_/g,' ')}</option>`).join('');
    openModal('custom', `
        <div class="modal-title">Order ${code}</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px;font-size:13px">
            <div style="background:rgba(255,255,255,.06);border-radius:8px;padding:10px">
                <div style="color:#8a7f66;font-size:10px;text-transform:uppercase;font-weight:700;margin-bottom:4px">Route</div>
                <div style="color:#f8f1e2">${o.from_zone||'—'} → ${o.to_zone||'—'}</div>
            </div>
            <div style="background:rgba(255,255,255,.06);border-radius:8px;padding:10px">
                <div style="color:#8a7f66;font-size:10px;text-transform:uppercase;font-weight:700;margin-bottom:4px">Amount</div>
                <div style="font-weight:700;color:#F2CC6B">ETB ${parseFloat(o.fare_total||0).toFixed(2)}</div>
            </div>
            <div style="background:rgba(255,255,255,.06);border-radius:8px;padding:10px">
                <div style="color:#8a7f66;font-size:10px;text-transform:uppercase;font-weight:700;margin-bottom:4px">🛵 Pickup Confirmation</div>
                <div style="font-size:12px">${pickupConf}</div>
            </div>
            <div style="background:rgba(255,255,255,.06);border-radius:8px;padding:10px">
                <div style="color:#8a7f66;font-size:10px;text-transform:uppercase;font-weight:700;margin-bottom:4px">🚪 Delivery Confirmation</div>
                <div style="font-size:12px">${delivConf}</div>
            </div>
        </div>
        <div class="modal-field"><label>Update Status</label><select id="new-status">${opts}</select></div>
        <div class="modal-msg" id="upd-msg"></div>
        <button class="modal-submit" onclick="updateOrderStatus(${id},document.getElementById('new-status').value)">Update Status</button>
        <button class="modal-submit" style="background:rgba(255,255,255,.1);color:#e8e2d4;margin-top:8px" onclick="printOrderReceiptUI(${id})">🖨️ Print Receipt</button>
    `);
}

async function loadConfirmations() {
  const res = await adm('admin_get_confirmations', {}, true);
  if (!res.success) return;
  window.confsData = res.data.confirmations || [];
  const pendingPickup = res.data.pending_pickup || 0;
  const pendingDelivery = res.data.pending_delivery || 0;

  const pb = document.getElementById('conf-pickup-badge');
  const db = document.getElementById('conf-delivery-badge');
  if (pb) { pb.textContent = pendingPickup + ' pickup'; pb.style.display = pendingPickup ? 'inline' : 'none'; }
  if (db) { db.textContent = pendingDelivery + ' delivery'; db.style.display = pendingDelivery ? 'inline' : 'none'; }

  ensureRepairButton();

  const pending = confsData.filter(c => c.status === 'pending');
  const cards = document.getElementById('conf-cards');
  if (!cards) return;
  if (!pending.length) {
    cards.innerHTML = '<div style="color:#8a7f66;font-size:13px;padding:10px;grid-column:1/-1">No pending confirmations — all clear ✓</div>';
  } else {
    cards.innerHTML = pending.map(c => {
      const isPickup = c.type === 'pickup';
      const mins = Math.floor((Date.now() - new Date(c.requested_at).getTime()) / 60000);
      const waitLabel = mins < 1 ? 'just now' : mins + ' min waiting';
      const accent = isPickup ? '#ff8c42' : '#c084fc';
      const grad = isPickup ? 'linear-gradient(135deg,#ff8c00,#e65100)' : 'linear-gradient(135deg,#9B59B6,#6a1b9a)';
      const icon = isPickup ? '🛵' : '🚪';
      const lbl = isPickup ? 'Pickup Confirmation' : 'Delivery Confirmation';
      const who = isPickup ? 'Awaiting service giver' : 'Awaiting customer';
      return `<div style="position:relative;background:linear-gradient(165deg, rgba(28,18,4,.94), rgba(10,6,0,.97));
          border:1px solid ${accent}55;border-radius:14px;padding:16px;
          box-shadow:0 16px 34px -10px rgba(0,0,0,.5), 0 0 0 1px rgba(255,255,255,.03) inset;overflow:hidden">
        <div style="position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg, transparent, ${accent}, transparent);box-shadow:0 0 12px ${accent}88"></div>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
          <span style="font-size:20px">${icon}</span>
          <div style="flex:1">
            <div style="font-weight:700;font-size:13px;color:#f8f1e2">${lbl}</div>
            <div style="font-size:11px;color:#8a7f66">${who}</div>
          </div>
          <span style="padding:3px 9px;border-radius:20px;font-size:10px;font-weight:800;background:${accent}22;color:${accent};border:1px solid ${accent}55;white-space:nowrap">${waitLabel}</span>
        </div>
        <div style="font-size:13px;margin-bottom:4px;color:#f8f1e2"><strong>${c.order_code}</strong> · <span style="color:${accent}">ETB ${parseFloat(c.fare_total||0).toFixed(2)}</span></div>
        <div style="font-size:12px;color:#8a7f66;margin-bottom:12px">Courier: ${c.courier_name||'—'} · ${c.from_zone||'—'} → ${c.to_zone||'—'}</div>
        <div style="display:flex;gap:6px">
          <button onclick="adminForceConfirm(${c.id},'${c.order_code}')" style="flex:1;padding:8px;background:${grad};color:#fff;border:none;border-radius:9px;font-size:12px;font-weight:700;cursor:pointer;box-shadow:0 6px 16px -4px ${accent}66">✅ Force Confirm</button>
          <button onclick="adminRejectConf(${c.id},'${c.order_code}')" style="padding:8px 13px;background:rgba(255,93,108,.1);color:#ff9aa4;border:1px solid rgba(255,93,108,.35);border-radius:9px;font-size:12px;cursor:pointer">✕</button>
        </div>
      </div>`;
    }).join('');
  }

  if (typeof confHistoryLoaded !== 'undefined' && confHistoryLoaded && typeof renderConfHistory === 'function') renderConfHistory();
}

function ensureRepairButton() {
  if (document.getElementById('conf-repair-btn')) return;
  const refreshBtn = document.querySelector('button[onclick="loadConfirmations()"]');
  if (!refreshBtn || !refreshBtn.parentElement) return;
  const btn = document.createElement('button');
  btn.id = 'conf-repair-btn';
  btn.className = 'btn';
  btn.style.cssText = 'padding:6px 12px;font-size:12px;margin-right:8px;background:rgba(212,175,55,.12);color:#F2CC6B;border:1px solid rgba(212,175,55,.4);border-radius:8px;cursor:pointer';
  btn.textContent = '🔧 Repair Historical Orders';
  btn.title = 'One-time fix for older orders missing their shop link, which can hide real pending confirmations from the courier/service-giver apps';
  btn.onclick = runBackfillServiceGiverIds;
  refreshBtn.parentElement.insertBefore(btn, refreshBtn);
}

async function runBackfillServiceGiverIds() {
  if (!confirm('Repair historical orders? This links any older orders missing their shop reference, using their existing courier assignment record. Safe to run any time - only fixes orders that are currently broken.')) return;
  const btn = document.getElementById('conf-repair-btn');
  if (btn) { btn.disabled = true; btn.textContent = 'Repairing…'; }
  const res = await adm('admin_backfill_order_service_giver_ids', {});
  if (btn) { btn.disabled = false; btn.textContent = '🔧 Repair Historical Orders'; }
  if (!res.success) { alert(res.message || 'Repair failed'); return; }
  alert('Repaired ' + res.data.orders_repaired + ' order(s). ' + res.data.orders_missing_after + ' still unlinked (likely had no assignment record at all).');
  loadConfirmations();
}
