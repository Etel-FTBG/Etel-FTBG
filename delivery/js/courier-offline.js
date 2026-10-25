/* ETEL Courier - works through a dropped connection (release v12: runs on the shared engine js/offline-kit.js).
   What it does, and what it deliberately does not:
   - The list of the courier's orders is kept on the phone each time it loads. With no signal the app shows that saved list,
     marked "saved at HH:MM", instead of "Could not load".
   - Status changes (picked up / in transit / delivered) and fuel fill-ups made with no signal are saved on the phone and sent,
     in the order they were made, as soon as the connection is back. The real time of the action is sent with it, so a delivery
     made in a dead zone is not stamped with the time the signal returned.
   - A saved action the server refuses (order cancelled, reassigned...) is NOT dropped quietly: it stays in the waiting list
     with the server's reason until the courier discards it.
   - Anything that moves money or needs another person (accepting a job, PIN / QR confirmations, wallet, going online) stays online-only.
   The queue, the saved reads, the sending and the bar live in offline-kit.js (the same engine the shop app uses); this file only says
   which calls to keep and how to word them. Standalone: wraps window.fetch for the API address, so courier.js is not edited. */
(function () {
'use strict';
if (window.__courierOffline || typeof window.fetch !== 'function' || !window.EtelOffline) return;
window.__courierOffline = true;

var API = (window.ETEL && window.ETEL.api) || 'boot.php';
var QUEUE_ACTIONS = { courier_update_order_status: 1, courier_add_fuel_log: 1 };
var LABEL = { picked_up: 'Picked up', in_transit: 'In transit', delivered: 'Delivered' };

/* The waiting list keeps release v11's storage key, so a phone that updates while it holds unsent work loses nothing, and going back to v11
   would still find it. */
/* Auth is the app's global const, not a property of window: reading it off window gives undefined (release v11 did, so it sent an empty token). */
function userKey() { try { var u = typeof Auth !== 'undefined' && Auth.getUser && Auth.getUser(); return u && u.id ? String(u.id) : ''; } catch (e) { return ''; } }
function reload() { if (typeof window.loadActiveOrders === 'function') { try { window.loadActiveOrders(); } catch (x) {} } }

window.CourierOffline = window.EtelOffline.install({
  name: 'courier',
  queueKey: 'etel_courier_outbox_v1',
  savedKey: 'etel_courier_orders_v1',
  api: API,
  savedWhat: 'saved orders',
  isApi: function (u) { return typeof u === 'string' && u.split('?')[0].indexOf('boot.php') !== -1; },
  getAction: function (u) { return /[?&]action=courier_get_my_orders(&|$)/.test(u) ? 'courier_get_my_orders' : null; },
  userKey: userKey,
  token: function () { try { return typeof Auth !== 'undefined' && Auth.getToken ? Auth.getToken() : ''; } catch (e) { return ''; } },
  reads: function (action) { return action === 'courier_get_my_orders'; },
  queueable: function (action) { return !!QUEUE_ACTIONS[action]; },
  /* what the saved list would look like once the waiting status changes are applied */
  overlay: function (action, data, waiting) {
    var pend = waiting.filter(function (e) { return e.action === 'courier_update_order_status'; });
    if (!pend.length || !data || !data.orders) return data;
    data.orders.forEach(function (o) {
      pend.forEach(function (e) { if (String(o.id) === String(e.body.order_id)) o.status = e.body.status; });
    });
    return data;
  },
  describe: function (e) {
    if (e.action === 'courier_add_fuel_log') return 'Fuel fill-up ' + (e.body.liters || '') + ' L';
    return (LABEL[e.body.status] || e.body.status) + ' · order #' + e.body.order_id;
  },
  onSent: reload,
  onReconnect: reload,
  toast: function (msg, isErr) { if (typeof window.showToast === 'function') window.showToast(msg, isErr ? 'err' : undefined); }
});
})();
