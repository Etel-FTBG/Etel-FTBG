/* ETEL Service Giver - keeps the shop working through a dropped connection (uses js/offline-kit.js).
   Saved on the phone and shown when there is no signal: the orders list, order details, the menu, profile and role.
   Saved when sent offline and delivered in order on reconnect: accept an order, start preparing / ready for pickup,
   "sold out today", and the on/off switch of a menu item.
   Deliberately NOT queued (they change what a courier or customer sees, or move money): declining an order, QR pickup/delivery
   scans, completing an order, editing prices/stock, payouts, staff. Those say "no connection" as before. */
(function () {
'use strict';
if (!window.EtelOffline || typeof ETEL === 'undefined') return;

var READS = { sg_orders: 1, sg_order_detail: 1, sg_get_my_items: 1, sg_profile: 1, sg_my_role: 1, sg_stats: 1 };
var WRITES = { sg_accept_order: 1, sg_update_order_status: 1, sg_sold_out: 1 };

function queueable(action, body) {
  if (WRITES[action]) return true;
  // only the on/off switch of an item; a full edit also carries price and stock, which must not be overwritten late
  if (action === 'sg_update_item') return Object.keys(body).every(function (k) { return k === 'action' || k === 'item_id' || k === 'is_available'; });
  return false;
}
function orderList(data) { return data && (data.orders || data.assignments) || null; }
function overlay(action, data, waiting) {
  if (!data || !waiting.length) return data;
  if (action === 'sg_orders') {
    var list = orderList(data); if (!list) return data;
    waiting.forEach(function (e) {
      var to = e.action === 'sg_accept_order' ? 'accepted' : (e.action === 'sg_update_order_status' ? e.body.new_status : null);
      if (!to) return;
      list.forEach(function (o) { if (String(o.order_id || o.id) === String(e.body.order_id)) o.status = to; });
    });
  } else if (action === 'sg_get_my_items' && data.items) {
    waiting.forEach(function (e) {
      data.items.forEach(function (it) {
        if (String(it.id) !== String(e.body.item_id)) return;
        if (e.action === 'sg_sold_out') it.sold_out = e.body.sold_out ? 1 : 0;
        if (e.action === 'sg_update_item') it.is_available = e.body.is_available ? 1 : 0;
      });
    });
  }
  return data;
}
function describe(e) {
  var b = e.body || {};
  if (e.action === 'sg_accept_order') return 'Accept order #' + b.order_id;
  if (e.action === 'sg_update_order_status') return (b.new_status === 'preparing' ? 'Start preparing' : 'Ready for pickup') + ' · order #' + b.order_id;
  if (e.action === 'sg_sold_out') return (b.sold_out ? 'Sold out today' : 'Back on sale') + ' · item #' + b.item_id;
  if (e.action === 'sg_update_item') return (b.is_available ? 'Show item' : 'Hide item') + ' #' + b.item_id;
  return e.action;
}
function refresh() {
  if (typeof loadOrders === 'function') { try { loadOrders(); } catch (e) {} }
  if (typeof loadDashboardData === 'function') { try { loadDashboardData(); } catch (e) {} }
  if (typeof loadServices === 'function' && typeof currentSgPage !== 'undefined' && currentSgPage === 'services') { try { loadServices(); } catch (e) {} }
}

window.SgOffline = window.EtelOffline.install({
  name: 'servicegiver',
  api: ETEL.api,
  userKey: function () { try { var u = Auth.getUser(); return u && u.id; } catch (e) { return ''; } },
  token: function () { return Auth.getToken(); },
  reads: function (a) { return !!READS[a]; },
  queueable: queueable,
  overlay: overlay,
  describe: describe,
  onSent: refresh,
  onReconnect: refresh,
  toast: function (msg, err) { if (typeof showNotification === 'function') showNotification(msg, err ? 'error' : 'success'); }
});
})();
