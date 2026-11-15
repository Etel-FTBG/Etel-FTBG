/* Etel Taxi admin - Activity Log panel and payment reminders (one driver, or everyone unpaid).
   Talks to admin_list_audit / admin_send_reminder / admin_send_reminders_unpaid. Reminders go out as push notifications
   to drivers who turned them on; a driver is reminded by hand at most once every 12 hours. */
(function () {
  'use strict';
  var LABELS = {
    payment_verified: 'Payment verified', payment_rejected: 'Payment rejected', driver_registered: 'Driver registered',
    association_created: 'Association created', association_updated: 'Association updated', bank_account_updated: 'Bank account updated',
    officer_registered: 'Officer registered', role_permissions_updated: 'Role permissions changed', role_deleted: 'Role deleted',
    reminder_sent: 'Reminder', reminders_bulk: 'Reminders to all unpaid'
  };
  function when(t) { return String(t || '').replace('T', ' ').slice(0, 16); }

  window.loadAudit = async function () {
    var el = document.getElementById('audit-table'); if (!el) return;
    var res = await taxiApi('admin_list_audit', { limit: 300 }, 'admin');
    if (!res.ok) { el.innerHTML = '<div class="card">' + taxiEsc(res.error || 'Could not load the log.') + '</div>'; return; }
    var rows = res.data.entries || [];
    if (!rows.length) { el.innerHTML = '<div class="card">Nothing recorded yet. Actions you take from now on appear here.</div>'; return; }
    el.innerHTML = '<table><thead><tr><th>When</th><th>Who</th><th>Action</th><th>Target</th><th>Details</th><th>IP</th></tr></thead><tbody>' +
      rows.map(function (r) {
        return '<tr><td>' + taxiEsc(when(r.created_at)) + '</td><td>' + taxiEsc(r.admin_label || ('#' + r.admin_id)) + '</td><td>' + taxiEsc(LABELS[r.action] || r.action) +
          '</td><td>' + taxiEsc(r.target_type ? (r.target_type + (r.target_id ? ' #' + r.target_id : '')) : '') + '</td><td>' + taxiEsc(r.detail || '') + '</td><td>' + taxiEsc(r.ip || '') + '</td></tr>';
      }).join('') + '</tbody></table>';
  };

  async function remindOne(btn) {
    var id = Number(btn.getAttribute('data-driver')); if (!id) return;
    btn.disabled = true;
    var res = await taxiApi('admin_send_reminder', { driver_id: id }, 'admin');
    btn.disabled = false;
    if (!res.ok) { taxiToast(res.error || 'Could not send the reminder.', 'err'); return; }
    var r = res.data.result;
    taxiToast(res.data.message, r === 'sent' ? 'ok' : 'err');
    if (r === 'sent' || r === 'too_soon') { btn.textContent = 'Reminded'; btn.disabled = true; }
  }

  async function remindAll(btn) {
    if (!confirm('Send a payment reminder to every driver who has not paid this period?\nOnly drivers who turned on reminders in the app receive it. Each driver is reminded at most once every 12 hours.')) return;
    btn.disabled = true;
    var res = await taxiApi('admin_send_reminders_unpaid', {}, 'admin');
    btn.disabled = false;
    if (!res.ok) { taxiToast(res.error || 'Could not send reminders.', 'err'); return; }
    var c = res.data.counts;
    taxiToast('Sent ' + c.sent + '. No reminders turned on: ' + c.no_push + '. Failed: ' + c.failed + '. Already reminded: ' + c.too_soon + '.', c.sent ? 'ok' : 'err');
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('.tx-remind'); if (b) { remindOne(b); return; }
    var all = e.target.closest && e.target.closest('.tx-remind-all'); if (all) remindAll(all);
  });

  function addBulkButton() {
    var tb = document.querySelector('.tx-tools[data-for="drivers-table"]');
    if (!tb || tb.querySelector('.tx-remind-all')) return;
    var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-primary tx-remind-all'; b.style.padding = '8px 14px'; b.style.fontSize = '12px';
    b.textContent = 'Remind all unpaid';
    var csv = tb.querySelector('.tx-csv'); tb.insertBefore(b, csv || null);
  }
  function boot() { addBulkButton(); setTimeout(addBulkButton, 400); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
