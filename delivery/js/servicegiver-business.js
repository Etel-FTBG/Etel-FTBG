/* ETEL Service Giver - "Sell": the walk-in counter of Etel Business (shown only when Etel switched the business tools on for this shop
   and the signed-in person has the pos.sell right).
   - Tap items, set payment, charge. Works with no internet: every sale is saved on the phone first and sent to Etel (sg_pos_sync)
     when the connection is back. A sale can never be lost by closing the app; only by clearing the browser data, which is why the
     page always shows how many sales are not sent yet and offers a backup file.
   - Stock shown here already counts the sales waiting to be sent. Etel's own figures replace it after each successful send.
   - A sale that the server refuses stays in the list with the reason until the person discards it.
   Standalone: loaded after servicegiver.js, builds its own menu entry and page, no inline handlers, every value goes through esc(). */
(function () {
'use strict';
if (typeof ETEL === 'undefined' || typeof Auth === 'undefined') return;

var I18N = {
  en: { sell: 'Sell', sub: 'Walk-in sales. Works without internet.', search: 'Search items', other: 'Other amount', cart: 'Current sale', empty: 'Tap an item to add it.',
    subtotal: 'Subtotal', discount: 'Discount (ETB)', total: 'Total', pay: 'Payment', cash: 'Cash', mobile: 'Mobile money', credit: 'Pay later', cname: 'Customer name',
    cphone: 'Customer phone (optional)', charge: 'Charge', clear: 'Clear', recent: 'Sales today', none: 'No sales yet today.', void: 'Cancel sale', voided: 'cancelled',
    receipt: 'Receipt', share: 'Share', print: 'Print', close: 'Close', synced: 'All sales are saved to Etel.', waiting: 'sales waiting to be sent', offline: 'No connection. Sales are saved on this phone.',
    send: 'Send now', discard: 'Discard', backup: 'Download backup', sold: 'sold out', left: 'left', name: 'What was sold', amount: 'Amount (ETB)', add: 'Add', thanks: 'Thank you!',
    needname: 'Write the customer name for a sale to pay later.', bad: 'Check the amount.', confirmvoid: 'Cancel this sale and put the items back in stock?', unsent: 'not sent yet', sentok: 'sent' },
  am: { sell: 'ሽያጭ', sub: 'ለቆጣሪ ሽያጭ። ያለ ኢንተርኔት ይሰራል።', search: 'ዕቃ ፈልግ', other: 'ሌላ መጠን', cart: 'አሁን ያለው ሽያጭ', empty: 'ለመጨመር ዕቃ ይንኩ።',
    subtotal: 'ንዑስ ድምር', discount: 'ቅናሽ (ብር)', total: 'ጠቅላላ', pay: 'ክፍያ', cash: 'ጥሬ ገንዘብ', mobile: 'ሞባይል ገንዘብ', credit: 'በኋላ የሚከፈል', cname: 'የደንበኛ ስም',
    cphone: 'የደንበኛ ስልክ (አማራጭ)', charge: 'ክፈል', clear: 'አጥፋ', recent: 'የዛሬ ሽያጮች', none: 'ዛሬ ገና ሽያጭ የለም።', void: 'ሽያጩን ሰርዝ', voided: 'ተሰርዟል',
    receipt: 'ደረሰኝ', share: 'አጋራ', print: 'አትም', close: 'ዝጋ', synced: 'ሁሉም ሽያጮች ኢትዮ ላይ ተቀምጠዋል።', waiting: 'ሽያጮች ለመላክ እየጠበቁ ናቸው', offline: 'ግንኙነት የለም። ሽያጮች በዚህ ስልክ ላይ ተቀምጠዋል።',
    send: 'አሁን ላክ', discard: 'ጣል', backup: 'ቅጂ አውርድ', sold: 'አልቋል', left: 'ቀርቷል', name: 'የተሸጠው ምን ነበር', amount: 'መጠን (ብር)', add: 'ጨምር', thanks: 'እናመሰግናለን!',
    needname: 'በኋላ ለሚከፈል ሽያጭ የደንበኛውን ስም ይጻፉ።', bad: 'መጠኑን ያረጋግጡ።', confirmvoid: 'ይህን ሽያጭ ሰርዘው ዕቃዎቹን ወደ ክምችት ይመልሱ?', unsent: 'ገና አልተላከም', sentok: 'ተልኳል' }
};
function t(k) { var l = 'en'; try { l = (typeof ETEL_I18N !== 'undefined' && ETEL_I18N.current()) || 'en'; } catch (e) {} return (I18N[l] && I18N[l][k]) || I18N.en[k] || k; }
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
function money(n) { n = Number(n) || 0; return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function hhmm(ms) { var d = new Date(ms); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
function uid() { var r = ''; try { var a = new Uint8Array(8); crypto.getRandomValues(a); for (var i = 0; i < a.length; i++) r += ('0' + a[i].toString(16)).slice(-2); } catch (e) { r = Math.random().toString(16).slice(2, 18); } return 'b' + Date.now().toString(36) + r; }

/* ---- storage: localStorage with an in-memory fallback, one set per signed-in person ---- */
var mem = {};
function me() { try { var u = Auth.getUser(); return u && u.id ? String(u.id) : '0'; } catch (e) { return '0'; } }
function key(n) { return 'etel_biz_' + n + '_' + me(); }
function load(n, dflt) { var k = key(n); if (mem[k] !== undefined) return mem[k]; try { var v = localStorage.getItem(k); if (v) { mem[k] = JSON.parse(v); return mem[k]; } } catch (e) {} mem[k] = dflt; return dflt; }
function save(n, v) { var k = key(n); mem[k] = v; try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }

var S = { cart: [], pay: 'cash', discount: '', cname: '', cphone: '', q: '', flushing: false, offset: 0, msg: '', other: false };
function outbox() { return load('outbox', []); }
function recent() { return load('recent', []); }
function cache() { return load('state', { at: 0, items: [], today: null }); }

/* ---- permissions ---- */
function caps() { try { return (typeof currentCapabilities !== 'undefined' && currentCapabilities) || []; } catch (e) { return []; } }
function can(c) { var k = caps(); return k.indexOf('*') !== -1 || k.indexOf(c) !== -1; }
function enabled() { try { return typeof currentServiceGiver !== 'undefined' && currentServiceGiver && Number(currentServiceGiver.business_tools) === 1; } catch (e) { return false; } }
function shopName() { try { return (currentServiceGiver && currentServiceGiver.business_name) || 'Shop'; } catch (e) { return 'Shop'; } }

/* ---- stock shown = server stock with the not-yet-sent events applied ---- */
function itemsNow() {
  var items = JSON.parse(JSON.stringify(cache().items || []));
  var byId = {}; items.forEach(function (i) { byId[String(i.id)] = i; });
  var voidedBy = {};
  outbox().forEach(function (e) {
    if (e.type === 'sale') (e.data.items || []).forEach(function (l) { var i = l.item_id && byId[String(l.item_id)]; if (i && i.stock_quantity !== null) i.stock_quantity = Math.max(0, Number(i.stock_quantity) - l.qty); });
    if (e.type === 'void') voidedBy[e.data.sale_req_id] = true;
  });
  var inBox = {}; outbox().forEach(function (e) { if (e.type === 'sale') inBox[e.req_id] = true; });
  outbox().forEach(function (e) {
    // a void whose sale was already sent: put its items back on the shelf here until Etel's own figures arrive
    if (e.type === 'void' && !inBox[e.data.sale_req_id]) (e._restore || []).forEach(function (l) { var i = byId[String(l.item_id)]; if (i && i.stock_quantity !== null) i.stock_quantity = Number(i.stock_quantity) + l.qty; });
    if (e.type === 'sale' && voidedBy[e.req_id]) (e.data.items || []).forEach(function (l) { var i = l.item_id && byId[String(l.item_id)]; if (i && i.stock_quantity !== null) i.stock_quantity = Number(i.stock_quantity) + l.qty; });
  });
  return items;
}

/* ---- talking to Etel ---- */
function post(action, body) {
  return fetch(ETEL.api, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + Auth.getToken() }, body: JSON.stringify(Object.assign({ action: action }, body || {})) })
    .then(function (r) { return r.json().then(function (j) { return { status: r.status, json: j }; }, function () { return { status: r.status, json: null }; }); });
}
function refreshState() {
  return post('sg_business_state', {}).then(function (r) {
    if (r.json && r.json.success) {
      var d = r.json.data; save('state', { at: Date.now(), items: d.items || [], today: d.today || null });
      if (d.server_now) S.offset = Date.parse(d.server_now) - Date.now();
      paint();
    }
  }, function () {});
}
function flush(manual) {
  if (S.flushing) return Promise.resolve();
  var pending = outbox().filter(function (e) { return !e.failed; });
  if (!pending.length) { return manual ? refreshState() : Promise.resolve(); }
  S.flushing = true;
  var batch = pending.slice(0, 100).map(function (e) { return { type: e.type, req_id: e.req_id, occurred_at: e.occurred_at, data: e.data }; });
  return post('sg_pos_sync', { events: batch }).then(function (r) {
    S.flushing = false;
    if (r.status === 401) return;
    if (!r.json || !r.json.success) {
      if (r.status === 403 || r.status === 400) S.msg = (r.json && r.json.message) || '';
      paint(); return;
    }
    S.msg = '';
    var res = {}; (r.json.data.results || []).forEach(function (x) { res[x.req_id] = x; });
    var ob = outbox().filter(function (e) {
      var x = res[e.req_id]; if (!x) return true;
      if (x.status === 'ok' || x.status === 'dup') return false;
      e.failed = x.message || 'Not accepted'; return true;
    });
    save('outbox', ob);
    var rc = recent(); rc.forEach(function (s) { var x = res[s.req_id]; if (x && (x.status === 'ok' || x.status === 'dup')) s.synced = 1; });
    save('recent', rc);
    paint();
    if (outbox().some(function (e) { return !e.failed; })) return flush(false);
    return refreshState();
  }, function () { S.flushing = false; paint(); });
}

/* ---- the sale ---- */
function nowIso() { return new Date(Date.now() + S.offset - 1000).toISOString(); }
function subtotal() { return S.cart.reduce(function (a, l) { return a + l.price * l.qty; }, 0); }
function addToCart(id) {
  var it = itemsNow().filter(function (i) { return String(i.id) === String(id); })[0]; if (!it) return;
  if (Number(it.sold_out) || !Number(it.is_available)) return;
  var line = S.cart.filter(function (l) { return String(l.item_id) === String(id); })[0];
  if (line) line.qty += 1; else S.cart.push({ item_id: it.id, name: it.name, price: Number(it.price), qty: 1 });
  paint();
}
function charge() {
  if (!S.cart.length) return;
  var sub = subtotal(), disc = S.discount === '' ? 0 : Number(S.discount);
  if (!isFinite(disc) || disc < 0 || disc > sub) { S.msg = t('bad'); paint(); return; }
  if (S.pay === 'credit' && !S.cname.trim()) { S.msg = t('needname'); paint(); return; }
  var ev = { type: 'sale', req_id: uid(), occurred_at: nowIso(), data: {
    items: S.cart.map(function (l) { return { item_id: l.item_id, name: l.name, unit_price: l.price, qty: l.qty }; }),
    discount: disc, payment_method: S.pay, customer_name: S.cname.trim(), customer_phone: S.cphone.trim() } };
  var ob = outbox(); ob.push(ev); save('outbox', ob);
  var rc = recent(); rc.unshift({ req_id: ev.req_id, at: Date.now(), lines: ev.data.items.filter(function (l) { return l.item_id; }).map(function (l) { return { item_id: l.item_id, qty: l.qty }; }), total: Math.round((sub - disc) * 100) / 100, n: S.cart.reduce(function (a, l) { return a + l.qty; }, 0), method: S.pay, synced: 0, voided: 0 }); save('recent', rc.slice(0, 80));
  var rec = receiptFor(ev, sub, disc);
  S.cart = []; S.discount = ''; S.cname = ''; S.cphone = ''; S.msg = ''; S.pay = 'cash';
  paint(); showReceipt(rec); flush(false);
}
function voidSale(reqId) {
  if (!can('pos.void') || !window.confirm(t('confirmvoid'))) return;
  var sale = recent().filter(function (s) { return s.req_id === reqId; })[0];
  var ob = outbox(); ob.push({ type: 'void', req_id: uid(), occurred_at: nowIso(), data: { sale_req_id: reqId }, _restore: (sale && sale.lines) || [] }); save('outbox', ob);
  var rc = recent(); rc.forEach(function (s) { if (s.req_id === reqId) s.voided = 1; }); save('recent', rc);
  paint(); flush(false);
}

/* ---- receipt ---- */
function receiptFor(ev, sub, disc) {
  var lines = ev.data.items.map(function (l) { return l.qty + ' × ' + l.name + '  ' + money(l.unit_price * l.qty); });
  var tx = [shopName(), new Date(Date.parse(ev.occurred_at)).toLocaleString(), '#' + ev.req_id.slice(-6).toUpperCase(), ''].concat(lines, ['']);
  if (disc > 0) tx.push(t('discount').replace(' (ETB)', '') + ': -' + money(disc));
  tx.push(t('total') + ': ETB ' + money(sub - disc));
  tx.push(t(ev.data.payment_method) + (ev.data.customer_name ? ' · ' + ev.data.customer_name : ''));
  tx.push('', t('thanks'));
  return { text: tx.join('\n'), total: sub - disc };
}
function showReceipt(rec) {
  closeReceipt();
  var d = document.createElement('div'); d.id = 'sgb-rc'; d.setAttribute('role', 'dialog'); d.setAttribute('aria-modal', 'true'); d.setAttribute('aria-labelledby', 'sgb-rc-t');
  d.innerHTML = '<div class="sgb-rcbox"><h3 id="sgb-rc-t">' + esc(t('receipt')) + ' · ETB ' + esc(money(rec.total)) + '</h3><pre>' + esc(rec.text) + '</pre><div class="sgb-row">' +
    (navigator.share ? '<button type="button" class="btn btn-secondary" data-sgb="rc-share">' + esc(t('share')) + '</button>' : '') +
    '<button type="button" class="btn btn-secondary" data-sgb="rc-print">' + esc(t('print')) + '</button><button type="button" class="btn btn-primary" data-sgb="rc-close">' + esc(t('close')) + '</button></div></div>';
  d._text = rec.text;
  document.body.appendChild(d);
  var c = d.querySelector('[data-sgb="rc-close"]'); if (c) c.focus();
}
function closeReceipt() { var d = document.getElementById('sgb-rc'); if (d && d.parentNode) d.parentNode.removeChild(d); }
function printText(text) {
  var f = document.createElement('iframe'); f.style.cssText = 'position:fixed;width:0;height:0;border:0;right:0;bottom:0'; document.body.appendChild(f);
  var w = f.contentWindow; w.document.open(); w.document.write('<pre style="font:14px/1.4 monospace;margin:0;white-space:pre-wrap">' + esc(text) + '</pre>'); w.document.close();
  setTimeout(function () { try { w.focus(); w.print(); } catch (e) {} setTimeout(function () { if (f.parentNode) f.parentNode.removeChild(f); }, 1500); }, 150);
}

/* ---- backup file ---- */
function downloadBackup() {
  var data = { shop: shopName(), saved_at: new Date().toISOString(), not_sent: outbox(), recent_sales: recent() };
  var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
  var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'etel-sales-backup-' + new Date().toISOString().slice(0, 10) + '.json';
  document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); if (a.parentNode) a.parentNode.removeChild(a); }, 500);
}

/* ---- screen ---- */
function css() {
  if (document.getElementById('sgb-css')) return;
  var s = document.createElement('style'); s.id = 'sgb-css';
  s.textContent = '.sgb-wrap{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(0,1fr);gap:16px;align-items:start}@media(max-width:900px){.sgb-wrap{grid-template-columns:1fr}}' +
    '.sgb-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin-top:12px}' +
    '.sgb-item{background:var(--surface-lt);border:1.5px solid var(--border-lt);color:var(--text);border-radius:var(--r-sm);padding:12px 10px;text-align:left;cursor:pointer;min-height:76px;font:inherit}' +
    '.sgb-item:hover:not(:disabled){border-color:var(--gold)}.sgb-item:disabled{opacity:.45;cursor:not-allowed}.sgb-item b{display:block;font-size:14px}.sgb-item span{font-size:12px;color:var(--text-dim)}' +
    '.sgb-item:focus-visible,.sgb-btn:focus-visible,#sgb-rc button:focus-visible{outline:2px solid var(--gold-lt);outline-offset:2px}' +
    '.sgb-line{display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--border)}.sgb-line .n{flex:1;min-width:0}.sgb-line .n b{display:block;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
    '.sgb-q{display:inline-flex;align-items:center;gap:4px}.sgb-q button{width:34px;height:34px;border-radius:9px;border:1px solid var(--border-lt);background:var(--surface-lt);color:var(--text);font-size:18px;cursor:pointer}' +
    '.sgb-tot{display:flex;justify-content:space-between;font-size:20px;font-weight:800;margin:12px 0;color:var(--gold-lt)}.sgb-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}' +
    '.sgb-bar{border:1.5px solid var(--border-lt);border-radius:var(--r-sm);padding:10px 12px;margin-bottom:14px;font-size:13px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between}' +
    '.sgb-bar.warn{border-color:var(--warning)}.sgb-bar.bad{border-color:var(--danger)}.sgb-x{color:#ff8a98;font-size:12.5px}' +
    '.sgb-field{display:block;width:100%;margin:6px 0}.sgb-pay{display:flex;gap:6px;margin:8px 0}.sgb-pay label{flex:1;text-align:center;border:1.5px solid var(--border-lt);border-radius:var(--r-sm);padding:9px 4px;cursor:pointer;font-size:13px}' +
    '.sgb-pay input{position:absolute;opacity:0}.sgb-pay input:checked+span{color:var(--gold-lt);font-weight:800}.sgb-pay label:focus-within{outline:2px solid var(--gold-lt)}' +
    '.sgb-rec{display:flex;justify-content:space-between;gap:8px;padding:8px 0;border-bottom:1px solid var(--border);font-size:13.5px;align-items:center}.sgb-rec.v{opacity:.55;text-decoration:line-through}' +
    '#sgb-rc{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px}.sgb-rcbox{background:#1c1204;color:var(--text);border:1px solid var(--border-lt);border-radius:14px;padding:18px;max-width:380px;width:100%;max-height:90vh;overflow:auto}' +
    '.sgb-rcbox pre{white-space:pre-wrap;font:13px/1.45 monospace;margin:10px 0}';
  document.head.appendChild(s);
}
function syncBar() {
  var ob = outbox(), waiting = ob.filter(function (e) { return !e.failed; }), failed = ob.filter(function (e) { return e.failed; }), off = navigator.onLine === false;
  var cls = failed.length ? 'bad' : (waiting.length || off ? 'warn' : '');
  var text = failed.length ? failed.length + ' ✖' : (waiting.length ? waiting.length + ' ' + t('waiting') : (off ? t('offline') : '✓ ' + t('synced')));
  if (waiting.length && off) text += ' · ' + t('offline');
  var h = '<div class="sgb-bar ' + cls + '" role="status" aria-live="polite"><span>' + esc(text) + (S.msg ? ' · ' + esc(S.msg) : '') + '</span><span class="sgb-row">' +
    (waiting.length ? '<button type="button" class="btn btn-secondary sgb-btn" data-sgb="send">' + esc(t('send')) + '</button>' : '') +
    '<button type="button" class="btn btn-secondary sgb-btn" data-sgb="backup">' + esc(t('backup')) + '</button></span></div>';
  failed.forEach(function (e) {
    h += '<div class="sgb-bar bad"><span class="sgb-x">' + esc(e.type) + ' #' + esc(e.req_id.slice(-6).toUpperCase()) + ' — ' + esc(e.failed) + '</span><button type="button" class="btn btn-secondary sgb-btn" data-sgb="drop" data-id="' + esc(e.req_id) + '">' + esc(t('discard')) + '</button></div>';
  });
  return h;
}
function paint() {
  var root = document.getElementById('sgb-root'); if (!root) return;
  css();
  var q = S.q.toLowerCase(), items = itemsNow().filter(function (i) { return !q || String(i.name).toLowerCase().indexOf(q) !== -1; });
  var sub = subtotal(), disc = S.discount === '' ? 0 : Math.min(Number(S.discount) || 0, sub);
  var grid = items.map(function (i) {
    var out = Number(i.sold_out) || !Number(i.is_available) || (i.stock_quantity !== null && Number(i.stock_quantity) <= 0);
    return '<button type="button" class="sgb-item" data-sgb="add" data-id="' + esc(i.id) + '"' + (out ? ' disabled' : '') + '><b>' + esc(i.name) + '</b><span>ETB ' + esc(money(i.price)) + '</span><br><span>' +
      (out ? esc(t('sold')) : (i.stock_quantity !== null ? esc(i.stock_quantity) + ' ' + esc(t('left')) : '&nbsp;')) + '</span></button>';
  }).join('');
  var cart = S.cart.length ? S.cart.map(function (l, ix) {
    return '<div class="sgb-line"><div class="n"><b>' + esc(l.name) + '</b><small>ETB ' + esc(money(l.price)) + '</small></div><div class="sgb-q"><button type="button" data-sgb="dec" data-ix="' + ix + '" aria-label="−">−</button><b aria-live="polite">' + esc(l.qty) + '</b><button type="button" data-sgb="inc" data-ix="' + ix + '" aria-label="+">+</button></div></div>';
  }).join('') : '<p class="empty-msg">' + esc(t('empty')) + '</p>';
  var rc = recent().filter(function (s) { return new Date(s.at).toDateString() === new Date().toDateString(); });
  var rec = rc.length ? rc.map(function (s) {
    return '<div class="sgb-rec' + (s.voided ? ' v' : '') + '"><span>' + esc(hhmm(s.at)) + ' · ' + esc(s.n) + ' · ' + esc(t(s.method)) + ' · ' + esc(s.synced ? t('sentok') : t('unsent')) + '</span><span><b>ETB ' + esc(money(s.total)) + '</b> ' +
      (s.voided ? esc(t('voided')) : (can('pos.void') ? '<button type="button" class="btn btn-secondary sgb-btn" data-sgb="void" data-id="' + esc(s.req_id) + '">' + esc(t('void')) + '</button>' : '')) + '</span></div>';
  }).join('') : '<p class="empty-msg">' + esc(t('none')) + '</p>';
  var keep = document.activeElement && document.activeElement.id;
  root.innerHTML = syncBar() + '<div class="sgb-wrap"><div class="card"><div class="card-body"><label class="sr-only" for="sgb-q" style="position:absolute;left:-9999px">' + esc(t('search')) + '</label>' +
    '<input id="sgb-q" class="field-input" type="search" placeholder="' + esc(t('search')) + '" value="' + esc(S.q) + '"><div class="sgb-grid">' + grid +
    '<button type="button" class="sgb-item" data-sgb="other"><b>＋ ' + esc(t('other')) + '</b><span>&nbsp;</span></button></div>' +
    (S.other ? '<div class="sgb-row" style="margin-top:12px"><input id="sgb-on" class="field-input" style="flex:2" placeholder="' + esc(t('name')) + '" aria-label="' + esc(t('name')) + '"><input id="sgb-oa" class="field-input" style="flex:1" inputmode="decimal" placeholder="' + esc(t('amount')) + '" aria-label="' + esc(t('amount')) + '"><button type="button" class="btn btn-primary sgb-btn" data-sgb="other-add">' + esc(t('add')) + '</button></div>' : '') +
    '</div></div><div class="card"><div class="card-body"><h3 style="margin:0 0 8px">' + esc(t('cart')) + '</h3>' + cart +
    '<div class="sgb-tot"><span>' + esc(t('total')) + '</span><span>ETB ' + esc(money(sub - disc)) + '</span></div>' +
    '<label for="sgb-disc">' + esc(t('discount')) + '</label><input id="sgb-disc" class="field-input sgb-field" inputmode="decimal" value="' + esc(S.discount) + '">' +
    '<div class="sgb-pay" role="radiogroup" aria-label="' + esc(t('pay')) + '">' + ['cash', 'mobile', 'credit'].map(function (m) { return '<label><input type="radio" name="sgb-pay" value="' + m + '"' + (S.pay === m ? ' checked' : '') + '><span>' + esc(t(m)) + '</span></label>'; }).join('') + '</div>' +
    (S.pay === 'credit' ? '<input id="sgb-cn" class="field-input sgb-field" placeholder="' + esc(t('cname')) + '" aria-label="' + esc(t('cname')) + '" value="' + esc(S.cname) + '"><input id="sgb-cp" class="field-input sgb-field" inputmode="tel" placeholder="' + esc(t('cphone')) + '" aria-label="' + esc(t('cphone')) + '" value="' + esc(S.cphone) + '">' : '') +
    (S.msg && !outbox().length ? '<p class="sgb-x" role="alert">' + esc(S.msg) + '</p>' : '') +
    '<div class="sgb-row" style="margin-top:12px"><button type="button" class="btn btn-primary" style="flex:1;min-height:48px" data-sgb="charge"' + (S.cart.length ? '' : ' disabled') + '>' + esc(t('charge')) + ' · ETB ' + esc(money(sub - disc)) + '</button><button type="button" class="btn btn-secondary" data-sgb="clear">' + esc(t('clear')) + '</button></div></div></div></div>' +
    '<div class="card"><div class="card-body"><h3 style="margin:0 0 8px">' + esc(t('recent')) + '</h3>' + rec + '</div></div>';
  if (keep) { var el = document.getElementById(keep); if (el && el.focus) { el.focus(); try { el.setSelectionRange(el.value.length, el.value.length); } catch (e) {} } }
  var bd = document.getElementById('sgb-badge'); var n = outbox().filter(function (e) { return !e.failed; }).length + outbox().filter(function (e) { return e.failed; }).length;
  if (bd) { bd.textContent = n; bd.style.display = n ? '' : 'none'; }
}

/* ---- wiring ---- */
function onClick(e) {
  var b = e.target.closest && e.target.closest('[data-sgb]'); if (!b) return;
  var a = b.getAttribute('data-sgb'), id = b.getAttribute('data-id'), ix = Number(b.getAttribute('data-ix'));
  if (a === 'add') addToCart(id);
  else if (a === 'inc') { S.cart[ix].qty += 1; paint(); }
  else if (a === 'dec') { S.cart[ix].qty -= 1; if (S.cart[ix].qty < 1) S.cart.splice(ix, 1); paint(); }
  else if (a === 'clear') { S.cart = []; S.discount = ''; S.msg = ''; paint(); }
  else if (a === 'charge') charge();
  else if (a === 'other') { S.other = !S.other; paint(); var o = document.getElementById('sgb-on'); if (o) o.focus(); }
  else if (a === 'other-add') {
    var nm = (document.getElementById('sgb-on').value || '').trim() || t('other'), am = Number(document.getElementById('sgb-oa').value);
    if (!isFinite(am) || am <= 0) { S.msg = t('bad'); paint(); return; }
    S.cart.push({ item_id: null, name: nm, price: am, qty: 1 }); S.other = false; S.msg = ''; paint();
  }
  else if (a === 'send') flush(true);
  else if (a === 'backup') downloadBackup();
  else if (a === 'void') voidSale(id);
  else if (a === 'drop') { save('outbox', outbox().filter(function (x) { return x.req_id !== id; })); paint(); refreshState(); }
  else if (a === 'rc-close') closeReceipt();
  else if (a === 'rc-print') printText(document.getElementById('sgb-rc')._text);
  else if (a === 'rc-share') { try { navigator.share({ text: document.getElementById('sgb-rc')._text }); } catch (x) {} }
}
function onInput(e) {
  var t_ = e.target;
  if (t_.id === 'sgb-q') { S.q = t_.value; paint(); }
  else if (t_.id === 'sgb-disc') { S.discount = t_.value; paintTotalsOnly(); }
  else if (t_.id === 'sgb-cn') S.cname = t_.value;
  else if (t_.id === 'sgb-cp') S.cphone = t_.value;
  else if (t_.name === 'sgb-pay') { S.pay = t_.value; paint(); }
}
function paintTotalsOnly() { var keepId = 'sgb-disc'; paint(); var el = document.getElementById(keepId); if (el) { el.focus(); el.setSelectionRange(el.value.length, el.value.length); } }
document.addEventListener('keydown', function (e) {
  var d = document.getElementById('sgb-rc'); if (!d) return;
  if (e.key === 'Escape') { closeReceipt(); return; }
  if (e.key !== 'Tab') return;
  var f = d.querySelectorAll('button'); if (!f.length) return;
  var first = f[0], last = f[f.length - 1];
  if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
  else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
});

function mount() {
  if (!enabled() || !can('pos.sell')) {
    var m = document.getElementById('sgb-menu'); if (m) m.style.display = 'none';
    return;
  }
  if (document.getElementById('sgb-menu')) { document.getElementById('sgb-menu').style.display = ''; return; }
  var orders = document.querySelector('.menu-item[data-page="orders"]'); var host = document.getElementById('page-orders'); if (!orders || !host) return;
  var mi = document.createElement('div'); mi.className = 'menu-item'; mi.id = 'sgb-menu'; mi.setAttribute('data-page', 'biz-sell'); mi.setAttribute('role', 'button'); mi.tabIndex = 0;
  mi.innerHTML = '<div class="menu-icon" aria-hidden="true">🧾</div><div class="menu-label">' + esc(t('sell')) + '</div><div class="menu-badge" id="sgb-badge" style="display:none">0</div>';
  orders.parentNode.insertBefore(mi, orders);
  var pg = document.createElement('div'); pg.id = 'page-biz-sell'; pg.style.display = 'none';
  pg.innerHTML = '<div class="page-title">' + esc(t('sell')) + '</div><div class="page-subtitle">' + esc(t('sub')) + '</div><div id="sgb-root"></div>';
  host.parentNode.insertBefore(pg, host);
  var go = function () {
    if (typeof switchPage === 'function') { try { switchPage('biz-sell'); } catch (x) { show(); } } else show();
    if (typeof closeSidebar === 'function') { try { closeSidebar(); } catch (x) {} }
    openScreen();
  };
  var show = function () { document.querySelectorAll('[id^="page-"]').forEach(function (p) { p.style.display = 'none'; }); pg.style.display = 'block'; };
  mi.addEventListener('click', go);
  mi.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
  var root = pg.querySelector('#sgb-root');
  root.addEventListener('click', onClick); root.addEventListener('input', onInput); root.addEventListener('change', onInput);
  document.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('#sgb-rc')) onClick(e); });
  paint();
}
function openScreen() { paint(); flush(false); refreshState(); }

var origApply = window.applyStaffRoleUI;
if (typeof origApply === 'function') {
  window.applyStaffRoleUI = function () { var r = origApply.apply(this, arguments); try { mount(); if (enabled()) { refreshState(); flush(false); } } catch (e) {} return r; };
}
window.addEventListener('online', function () { paint(); flush(false); });
window.addEventListener('offline', function () { paint(); });
setInterval(function () { if (enabled() && navigator.onLine !== false && outbox().some(function (e) { return !e.failed; })) flush(false); }, 20000);
function start() { try { mount(); } catch (e) {} }
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
window.SgBiz = { flush: function () { return flush(true); }, pending: function () { return outbox().length; }, mount: mount };
})();
