/* ETEL Admin - Items & products.
   Counts that double as filters (available, hidden, low stock, out of stock), category chips, shop filter, search, sort,
   card or table view, a one-tap available / hidden switch, and quick edit and delete. Items show their photo, price,
   old price and the discount, and stock with a warning when it runs low.
   Data: admin_get_all_items (AdminController.php); controls reuse admin_toggle_item, admin_delete_item and the existing
   Add / Edit item forms (openAdminAddItem, openAdminEditItem in admin.js). Standalone: replaces loadItems() so admin.js
   is not edited. Every server value goes through AdminKit.esc; no inline handlers. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;
var LOW = 5, SHOW = 120;
var CATS = { food: ['🍲', 'Food'], grocery: ['🛒', 'Grocery'], pharmacy: ['💊', 'Pharmacy'], package: ['📦', 'Package'], flower: ['💐', 'Flower'] };
var V = { filter: '', cat: '', shop: '', q: '', sort: 'recent', mode: 'cards', rows: [], loaded: false, busy: false, more: 1 };
try { var m = localStorage.getItem('itm-mode'); if (m === 'table' || m === 'cards') V.mode = m; } catch (e) { /* private window */ }

function root() { return document.getElementById('items-pro'); }
function avail(r) { return r.is_available == 1 || r.is_available === true || r.is_available === '1'; }
function stock(r) { return parseInt(r.stock_quantity, 10) || 0; }
function price(r) { return parseFloat(r.price) || 0; }
function cat(r) { return CATS[r.category] || ['🏷️', r.category ? String(r.category) : 'Other']; }
function pic(u) { u = String(u || ''); return (/^(https?:)?\/\//i.test(u) || u.charAt(0) === '/') ? u : ''; }
function discount(r) { var o = parseFloat(r.original_price) || 0, p = price(r); return o > p && p > 0 ? Math.round((1 - p / o) * 100) : 0; }

function load(quiet) {
  var el = root(); if (!el || V.busy) return Promise.resolve();
  K.css(); V.busy = true;
  if (typeof loadSGListForFilter === 'function') { try { loadSGListForFilter(); } catch (e) { /* the Add form fills its own list */ } }
  if (!quiet && !V.loaded) el.innerHTML = '<div class="ak">' + K.skeleton(6) + '</div>';
  return K.api('admin_get_all_items', {}).then(function (res) {
    V.busy = false;
    if (!K.okRes(res)) { if (!V.loaded) el.innerHTML = '<div class="ak">' + K.empty('⚠️', 'Could not load items', K.failText(res)) + '</div>'; else K.toast(K.failText(res), true); return; }
    V.rows = K.data(res).items || []; V.loaded = true;
    try { itemsData = V.rows; } catch (e) { /* admin.js missing: the Edit form has no data */ }
    paint();
  });
}

function counts() {
  var c = { all: V.rows.length, available: 0, hidden: 0, low: 0, out: 0 };
  V.rows.forEach(function (r) { if (avail(r)) c.available++; else c.hidden++; if (stock(r) <= 0) c.out++; else if (stock(r) <= LOW) c.low++; });
  return c;
}
function shops() {
  var seen = {}; V.rows.forEach(function (r) { if (r.service_giver_id != null) seen[r.service_giver_id] = r.business_name || ('Shop ' + r.service_giver_id); });
  return Object.keys(seen).map(function (k) { return [k, seen[k]]; }).sort(function (a, b) { return String(a[1]).localeCompare(String(b[1])); });
}
function visibleRows() {
  var q = V.q.toLowerCase(), rows = V.rows.filter(function (r) {
    if (V.filter === 'available' && !avail(r)) return false;
    if (V.filter === 'hidden' && avail(r)) return false;
    if (V.filter === 'low' && !(stock(r) > 0 && stock(r) <= LOW)) return false;
    if (V.filter === 'out' && stock(r) > 0) return false;
    if (V.cat && r.category !== V.cat) return false;
    if (V.shop && String(r.service_giver_id) !== V.shop) return false;
    if (q && (String(r.name || '') + ' ' + String(r.item_code || '') + ' ' + String(r.business_name || '')).toLowerCase().indexOf(q) < 0) return false;
    return true;
  });
  var by = { recent: function (a, b) { return (parseInt(b.id, 10) || 0) - (parseInt(a.id, 10) || 0); }, name: function (a, b) { return String(a.name || '').localeCompare(String(b.name || '')); },
             priceUp: function (a, b) { return price(a) - price(b); }, priceDown: function (a, b) { return price(b) - price(a); }, stock: function (a, b) { return stock(a) - stock(b); } };
  return rows.sort(by[V.sort] || by.recent);
}

function paint() {
  var el = root(); if (!el) return;
  var c = counts(), focus = document.activeElement && document.activeElement.id === 'itm-q';
  var kp = [
    { key: '', icon: '🏷️', label: 'All items', value: K.num(c.all) },
    { key: 'available', icon: '✅', label: 'Available', value: K.num(c.available) },
    { key: 'hidden', icon: '🙈', label: 'Hidden', value: K.num(c.hidden) },
    { key: 'low', icon: '⚠️', label: 'Low stock', value: K.num(c.low), warn: true },
    { key: 'out', icon: '⛔', label: 'Out of stock', value: K.num(c.out), warn: true }
  ];
  var cats = [['', 'All']].concat(Object.keys(CATS).map(function (k) { return [k, CATS[k][0] + ' ' + CATS[k][1]]; }));
  var sopts = [['recent', 'Newest'], ['name', 'Name A–Z'], ['priceUp', 'Price: low to high'], ['priceDown', 'Price: high to low'], ['stock', 'Lowest stock']].map(function (o) { return '<option value="' + o[0] + '"' + (V.sort === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('');
  var shopOpts = '<option value="">All shops</option>' + shops().map(function (s) { return '<option value="' + esc(s[0]) + '"' + (V.shop === String(s[0]) ? ' selected' : '') + '>' + esc(s[1]) + '</option>'; }).join('');
  var rows = visibleRows(), shown = rows.slice(0, SHOW * V.more);
  var h = '<div class="ak">' + K.hero('Items & products', 'Everything shops sell on ETEL. Switch an item on or off, fix a price, and see what is running out.', [
      ['Items', K.num(c.all)], ['Available now', K.num(c.available)], ['Need restocking', K.num(c.low + c.out)]]) +
    K.kpis(kp, V.filter) +
    '<div class="ak-tools"><label class="ak-search"><u>🔎</u><input id="itm-q" type="search" placeholder="Item, code or shop…" value="' + esc(V.q) + '" autocomplete="off"></label>' +
    '<select class="ak-sel" data-sel="shop" aria-label="Shop">' + shopOpts + '</select><select class="ak-sel" data-sel="sort" aria-label="Sort">' + sopts + '</select>' +
    K.seg('mode', [['cards', '🎴 Cards'], ['table', '☰ Table']], V.mode) + '<button type="button" class="ak-btn" data-act="refresh" aria-label="Refresh">🔄</button><button type="button" class="ak-btn gold" data-act="add">➕ Add item</button></div>' +
    K.seg('cat', cats, V.cat) +
    '<div class="ak-count" style="margin-top:10px">' + K.num(rows.length) + ' of ' + K.num(V.rows.length) + ' items</div>';
  if (!rows.length) h += K.empty('🏷️', V.rows.length ? 'No items match' : 'No items yet', V.rows.length ? 'Try clearing the filters.' : 'Items appear here when shops add them.');
  else h += (V.mode === 'table' ? table(shown) : '<div class="ak-grid">' + shown.map(card).join('') + '</div>') +
    (rows.length > shown.length ? '<div style="text-align:center;margin-top:14px"><button type="button" class="ak-btn" data-act="more">Show more (' + K.num(rows.length - shown.length) + ' left)</button></div>' : '');
  el.innerHTML = h + '</div>';
  if (focus) { var i = document.getElementById('itm-q'); if (i) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); } }
}

function thumb(r) {
  var u = pic(r.image_url), cl = cat(r);
  return u ? '<div class="ak-av" style="overflow:hidden;--a:#E8C14A;--b:#9a7a1c"><img src="' + esc(u) + '" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover"></div>'
           : '<div class="ak-av" style="--a:#E8C14A;--b:#9a7a1c;font-size:22px">' + esc(cl[0]) + '</div>';
}
function stockChip(r) {
  var s = stock(r);
  return s <= 0 ? '<span class="ak-chip warn">Out of stock</span>' : s <= LOW ? '<span class="ak-chip warn">Only ' + esc(s) + ' left</span>' : '<span class="ak-chip">' + esc(K.num(s)) + ' in stock</span>';
}
function sw(r) {
  var on = avail(r);
  return '<label class="ak-row" style="margin:0;gap:6px;cursor:pointer"><input type="checkbox" data-toggle="' + esc(r.id) + '"' + (on ? ' checked' : '') + ' style="width:auto;margin:0" aria-label="Available to customers"><span>' + (on ? 'Available' : 'Hidden') + '</span></label>';
}
function card(r) {
  var d = discount(r), cl = cat(r);
  return '<div class="ak-card' + (avail(r) ? '' : ' off') + '" style="cursor:default"><div class="ak-top">' + thumb(r) + '<div class="ak-nm"><b>' + esc(r.name) + '</b><small>' + esc(r.business_name || '—') + (r.item_code ? ' · ' + esc(r.item_code) : '') + '</small></div></div>' +
    '<div class="ak-chips"><span class="ak-chip">' + esc(cl[0]) + ' ' + esc(cl[1]) + '</span>' + stockChip(r) + (d ? '<span class="ak-chip" style="color:var(--green)">−' + esc(d) + '%</span>' : '') + '</div>' +
    '<div class="ak-row"><span><b style="font-size:16px">' + esc(K.money(price(r))) + '</b>' + (d ? ' <s style="opacity:.6">' + esc(K.money(r.original_price)) + '</s>' : '') + '</span>' + sw(r) + '</div>' +
    '<div class="ak-acts" style="margin-top:10px"><button type="button" class="ak-btn" data-edit="' + esc(r.id) + '">✏ Edit</button><button type="button" class="ak-btn danger" data-del="' + esc(r.id) + '">🗑 Delete</button></div></div>';
}
function table(rows) {
  return '<div class="ak-tblw"><table class="ak-tbl"><thead><tr><th>Item</th><th>Shop</th><th>Category</th><th>Price</th><th>Stock</th><th>Available</th><th></th></tr></thead><tbody>' + rows.map(function (r) {
    var cl = cat(r);
    return '<tr><td><b>' + esc(r.name) + '</b><br><small>' + esc(r.item_code || '') + '</small></td><td>' + esc(r.business_name || '—') + '</td><td>' + esc(cl[0] + ' ' + cl[1]) + '</td><td>' + esc(K.money(price(r))) + '</td><td>' + stockChip(r) + '</td><td>' + sw(r) +
      '</td><td><button type="button" class="ak-btn" data-edit="' + esc(r.id) + '">✏</button> <button type="button" class="ak-btn danger" data-del="' + esc(r.id) + '">🗑</button></td></tr>';
  }).join('') + '</tbody></table></div>';
}

function find(id) { var f = null; V.rows.forEach(function (r) { if (String(r.id) === String(id)) f = r; }); return f; }

var search = K.debounce(function () { V.more = 1; paint(); }, 200);
function wire(el) {
  if (el._itmWired) return; el._itmWired = true;
  el.addEventListener('click', function (e) {
    var t = e.target, b;
    if ((b = t.closest('[data-kpi]'))) { var k = b.getAttribute('data-kpi'); V.filter = (V.filter === k ? '' : k); V.more = 1; paint(); return; }
    if ((b = t.closest('[data-seg]'))) {
      var n = b.getAttribute('data-seg'), v = b.getAttribute('data-val');
      if (n === 'cat') V.cat = v; else { V.mode = v; try { localStorage.setItem('itm-mode', v); } catch (x) { /* ignore */ } }
      V.more = 1; paint(); return;
    }
    if (t.closest('[data-act="refresh"]')) { load(true); return; }
    if (t.closest('[data-act="more"]')) { V.more++; paint(); return; }
    if (t.closest('[data-act="add"]')) { if (typeof openAdminAddItem === 'function') openAdminAddItem(); else K.toast('The Add form is not available here.', true); return; }
    if ((b = t.closest('[data-edit]'))) { if (typeof openAdminEditItem === 'function') openAdminEditItem(b.getAttribute('data-edit')); return; }
    if ((b = t.closest('[data-del]'))) {
      var r = find(b.getAttribute('data-del')); if (!r) return;
      K.confirm({ title: 'Delete "' + (r.name || 'this item') + '"?', text: 'It disappears from the shop for good. To keep it but stop selling it, switch it to Hidden instead.', label: 'Delete', danger: true }).then(function (ok) {
        if (ok) K.api('admin_delete_item', { item_id: parseInt(r.id, 10) }).then(function (res) { if (K.okRes(res)) { K.toast('Item deleted'); V.busy = false; load(true); } else K.toast(K.failText(res), true); });
      });
    }
  });
  el.addEventListener('input', function (e) { if (e.target.id === 'itm-q') { V.q = e.target.value.trim(); search(); } });
  el.addEventListener('change', function (e) {
    var t = e.target, id = t.getAttribute && t.getAttribute('data-toggle'), s = t.getAttribute && t.getAttribute('data-sel');
    if (id) {
      var r = find(id); if (!r) return;
      var on = t.checked;
      K.api('admin_toggle_item', { item_id: parseInt(id, 10), is_available: on ? 1 : 0 }).then(function (res) {
        if (K.okRes(res)) { r.is_available = on ? 1 : 0; K.toast(on ? 'Item is available' : 'Item hidden'); paint(); }
        else { t.checked = !on; K.toast(K.failText(res), true); }
      });
    } else if (s === 'shop') { V.shop = t.value; V.more = 1; paint(); }
    else if (s === 'sort') { V.sort = t.value; paint(); }
  });
}

function install() {
  var el = root(); if (!el) return;
  wire(el);
  window.loadItems = function () { return load(V.loaded); };
  window.renderItemTable = function () { if (V.loaded) paint(); };
  window.filterItemTable = window.renderItemTable;
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
