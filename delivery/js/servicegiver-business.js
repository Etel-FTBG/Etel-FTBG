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
    receipt: 'Receipt', share: 'Share', print: 'Print', close: 'Close', synced: 'Everything is saved to Etel.', waiting: 'entries waiting to be sent', offline: 'No connection. Your work is saved on this phone.',
    send: 'Send now', discard: 'Discard', backup: 'Download backup', sold: 'sold out', left: 'left', name: 'What was sold', amount: 'Amount (ETB)', add: 'Add', thanks: 'Thank you!',
    needname: 'Write the customer name for a sale to pay later.', bad: 'Check the amount.', confirmvoid: 'Cancel this sale and put the items back in stock?', unsent: 'not sent yet', sentok: 'sent',
    stock: 'Stock', stocksub: 'What you have on the shelf. Deliveries, waste and counts work without internet.', lowlimit: 'Warn me when an item is down to', low: 'low', outlbl: 'out',
    untracked: 'not tracked', received: 'Received', waste: 'Waste', count: 'Count', history: 'History', qty: 'How many', save: 'Save', cancel: 'Cancel', kdelivery: 'Delivery received',
    kwaste: 'Waste or damage', kcount: 'I counted', lowitems: 'Low or out of stock:', allgood: 'Nothing is running low.', nohist: 'No movements yet.', loadfail: 'Could not load (needs internet).',
    r_sale: 'Counter sale', r_online_order: 'Online order', r_online_cancel: 'Online order cancelled', r_delivery: 'Delivery', r_waste: 'Waste', r_count: 'Count', r_void: 'Sale cancelled',
    expenses: 'Expenses', expsub: 'Write down what the shop spends. Works without internet.', category: 'What for', c_supplies: 'Supplies', c_rent: 'Rent', c_electricity: 'Electricity', c_wages: 'Wages',
    c_transport: 'Transport', c_other: 'Other', paidwith: 'Paid with', note: 'Note (optional)', addexp: 'Save expense', exptoday: 'Spent today', noexp: 'No expenses yet today.',
    cashup: 'Cash-up', cashsub: 'Count the till at the end of the day and see if it matches the books.', openfloat: 'Cash in the till this morning (ETB)', cashsales: 'Cash sales', cashexp: 'Cash expenses',
    expected: 'The till should have', counted: 'Cash you counted (ETB)', diff: 'Difference', over: 'more than expected', under: 'less than expected', match: 'It matches.', closeday: 'Close the day',
    dayclosed: 'The day is closed', estimate: 'This is an estimate from this phone; Etel recalculates it when it receives your count.', unsentwarn: 'Some entries have not been sent yet, so the total may be incomplete. Close the day anyway?', closedat: 'Counted',
    rep: 'Report', repsub: 'How the shop is doing: sales, spending and what is left.', r7: 'Last 7 days', r30: 'Last 30 days', r90: 'Last 90 days', rmonth: 'This month', by: 'Show by', gday: 'Day', gweek: 'Week', gmonth: 'Month',
    income: 'Money in', spent: 'Money out', net: 'What is left', netnote: 'Sales minus expenses. It does not subtract what the goods cost you.', counter: 'Counter sales', online: 'Online orders', paylater: 'of it still to be paid (pay later)',
    nosales: 'Sales', period: 'Period', best: 'Best sellers', slow: 'Slowest on your menu', sold_n: 'sold', byexp: 'Where the money went', csv: 'Download CSV', loading: 'Loading…', saved: 'Showing the report saved on this phone from',
    norep: 'Nothing recorded in this period.', repfail: 'Could not load the report (needs internet).', vdisc: 'Discounts given', vvoid: 'Cancelled sales', repnote: 'Online orders count item prices of delivered orders only.', orders_n: 'orders',
    activity: 'Activity log', activitysub: 'What was cancelled, discounted, counted or refused. Entries cannot be edited or deleted.', noact: 'Nothing to show yet.', by_: 'by',
    a_sale_void: 'Sale cancelled', a_big_discount: 'Big discount', a_stock_waste: 'Waste recorded', a_stock_count: 'Stock counted', a_stock_delivery: 'Delivery received', a_cashup: 'Day closed', a_big_expense: 'Big expense',
    a_late_entry: 'Added to a counted day', a_denied: 'Not allowed', a_limit: 'Daily limit reached', a_tools_on: 'Etel Business switched on', a_tools_off: 'Etel Business switched off', a_expense_void: 'Expense cancelled',
    cancelexp: 'Cancel', fixexp: 'Correct', confirmexpvoid: 'Cancel this expense? It stays in the activity log.', fixing: 'You are correcting the expense of', fixing2: 'Saving replaces it.', stopfix: 'Keep the old one',
    exprecent: 'Recent expenses' },
  am: { sell: 'ሽያጭ', sub: 'ለቆጣሪ ሽያጭ። ያለ ኢንተርኔት ይሰራል።', search: 'ዕቃ ፈልግ', other: 'ሌላ መጠን', cart: 'አሁን ያለው ሽያጭ', empty: 'ለመጨመር ዕቃ ይንኩ።',
    subtotal: 'ንዑስ ድምር', discount: 'ቅናሽ (ብር)', total: 'ጠቅላላ', pay: 'ክፍያ', cash: 'ጥሬ ገንዘብ', mobile: 'ሞባይል ገንዘብ', credit: 'በኋላ የሚከፈል', cname: 'የደንበኛ ስም',
    cphone: 'የደንበኛ ስልክ (አማራጭ)', charge: 'ክፈል', clear: 'አጥፋ', recent: 'የዛሬ ሽያጮች', none: 'ዛሬ ገና ሽያጭ የለም።', void: 'ሽያጩን ሰርዝ', voided: 'ተሰርዟል',
    receipt: 'ደረሰኝ', share: 'አጋራ', print: 'አትም', close: 'ዝጋ', synced: 'ሁሉም ነገር ተቀምጧል።', waiting: 'ግቤቶች ለመላክ እየጠበቁ ናቸው', offline: 'ግንኙነት የለም። ሥራዎ በዚህ ስልክ ላይ ተቀምጧል።',
    send: 'አሁን ላክ', discard: 'ጣል', backup: 'ቅጂ አውርድ', sold: 'አልቋል', left: 'ቀርቷል', name: 'የተሸጠው ምን ነበር', amount: 'መጠን (ብር)', add: 'ጨምር', thanks: 'እናመሰግናለን!',
    needname: 'በኋላ ለሚከፈል ሽያጭ የደንበኛውን ስም ይጻፉ።', bad: 'መጠኑን ያረጋግጡ።', confirmvoid: 'ይህን ሽያጭ ሰርዘው ዕቃዎቹን ወደ ክምችት ይመልሱ?', unsent: 'ገና አልተላከም', sentok: 'ተልኳል',
    stock: 'ክምችት', stocksub: 'በመደርደሪያ ላይ ያለዎት። መቀበል፣ ብክነት እና ቆጠራ ያለ ኢንተርኔት ይሰራሉ።', lowlimit: 'ዕቃ ሲቀነስ አስጠንቅቀኝ እስከ', low: 'ዝቅተኛ', outlbl: 'አልቋል',
    untracked: 'አይከታተልም', received: 'ተቀብያለሁ', waste: 'ብክነት', count: 'ቆጠራ', history: 'ታሪክ', qty: 'ስንት', save: 'አስቀምጥ', cancel: 'ተው', kdelivery: 'የተቀበልኩት ዕቃ',
    kwaste: 'ብክነት ወይም ጉዳት', kcount: 'ቆጠርኩ', lowitems: 'ዝቅተኛ ወይም ያለቁ ዕቃዎች:', allgood: 'ምንም እየተቀነሰ አይደለም።', nohist: 'ገና እንቅስቃሴ የለም።', loadfail: 'መጫን አልተቻለም (ኢንተርኔት ያስፈልጋል)።',
    r_sale: 'የቆጣሪ ሽያጭ', r_online_order: 'የመስመር ላይ ትዕዛዝ', r_online_cancel: 'የመስመር ላይ ትዕዛዝ ተሰርዟል', r_delivery: 'መቀበል', r_waste: 'ብክነት', r_count: 'ቆጠራ', r_void: 'ሽያጭ ተሰርዟል',
    expenses: 'ወጪዎች', expsub: 'ሱቁ የሚያወጣውን ይጻፉ። ያለ ኢንተርኔት ይሰራል።', category: 'ለምን', c_supplies: 'ዕቃዎች', c_rent: 'ኪራይ', c_electricity: 'መብራት', c_wages: 'ደመወዝ', c_transport: 'መጓጓዣ', c_other: 'ሌላ',
    paidwith: 'የተከፈለበት', note: 'ማስታወሻ (አማራጭ)', addexp: 'ወጪውን አስቀምጥ', exptoday: 'የዛሬ ወጪ', noexp: 'ዛሬ ገና ወጪ የለም።',
    cashup: 'የገንዘብ ቆጠራ', cashsub: 'በቀኑ መጨረሻ ሣጥኑን ቆጥረው ከመዝገቡ ጋር እንደሚስማማ ይመልከቱ።', openfloat: 'ጠዋት በሣጥኑ ውስጥ የነበረ ገንዘብ (ብር)', cashsales: 'የጥሬ ገንዘብ ሽያጭ', cashexp: 'የጥሬ ገንዘብ ወጪ',
    expected: 'ሣጥኑ ውስጥ መኖር ያለበት', counted: 'የቆጠሩት ገንዘብ (ብር)', diff: 'ልዩነት', over: 'ከሚጠበቀው በላይ', under: 'ከሚጠበቀው ያነሰ', match: 'ይስማማል።', closeday: 'ቀኑን ዝጋ',
    dayclosed: 'ቀኑ ተዘግቷል', estimate: 'ይህ ከዚህ ስልክ የተገመተ ነው፤ ቆጠራዎን ሲቀበል ኢትዮ እንደገና ያሰላል።', unsentwarn: 'አንዳንድ ግቤቶች ገና አልተላኩም፤ ድምሩ ያልተሟላ ሊሆን ይችላል። ቀኑን ቢሆንም ይዝጉ?', closedat: 'የተቆጠረ',
    rep: 'ሪፖርት', repsub: 'ሱቁ እንዴት እየሄደ ነው፦ ሽያጭ፣ ወጪ እና የቀረው።', r7: 'ያለፉት 7 ቀናት', r30: 'ያለፉት 30 ቀናት', r90: 'ያለፉት 90 ቀናት', rmonth: 'ይህ ወር', by: 'በ… አሳይ', gday: 'ቀን', gweek: 'ሳምንት', gmonth: 'ወር',
    income: 'የገባ ገንዘብ', spent: 'የወጣ ገንዘብ', net: 'የቀረው', netnote: 'ሽያጭ ሲቀነስ ወጪ። የዕቃዎቹን ግዢ ዋጋ አይቀንስም።', counter: 'የቆጣሪ ሽያጭ', online: 'የመስመር ላይ ትዕዛዞች', paylater: 'ከዚህ ውስጥ ገና ያልተከፈለ (በኋላ የሚከፈል)',
    nosales: 'ሽያጭ', period: 'ጊዜ', best: 'በደንብ የሚሸጡ', slow: 'በዝግታ የሚሸጡ', sold_n: 'ተሸጧል', byexp: 'ገንዘቡ የት ሄደ', csv: 'CSV አውርድ', loading: 'በመጫን ላይ…', saved: 'በዚህ ስልክ ላይ የተቀመጠውን ሪፖርት እያሳየ ነው፣ ከ',
    norep: 'በዚህ ጊዜ ምንም አልተመዘገበም።', repfail: 'ሪፖርቱን መጫን አልተቻለም (ኢንተርኔት ያስፈልጋል)።', vdisc: 'የተሰጠ ቅናሽ', vvoid: 'የተሰረዙ ሽያጮች', repnote: 'የመስመር ላይ ትዕዛዞች የደረሱትን ትዕዛዝ የዕቃ ዋጋ ብቻ ይቆጥራሉ።', orders_n: 'ትዕዛዞች',
    activity: 'የእንቅስቃሴ መዝገብ', activitysub: 'የተሰረዘ፣ የተቀነሰ፣ የተቆጠረ ወይም ውድቅ የተደረገ። ግቤቶች ሊስተካከሉ ወይም ሊሰረዙ አይችሉም።', noact: 'ገና የሚታይ ነገር የለም።', by_: 'በ',
    a_sale_void: 'ሽያጭ ተሰርዟል', a_big_discount: 'ትልቅ ቅናሽ', a_stock_waste: 'ብክነት ተመዝግቧል', a_stock_count: 'ክምችት ተቆጥሯል', a_stock_delivery: 'ዕቃ ተቀብሏል', a_cashup: 'ቀኑ ተዘግቷል', a_big_expense: 'ትልቅ ወጪ',
    a_late_entry: 'ወደ ተቆጠረ ቀን ተጨመረ', a_denied: 'አልተፈቀደም', a_limit: 'የቀን ገደብ ደርሷል', a_tools_on: 'Etel Business ተከፍቷል', a_tools_off: 'Etel Business ተዘግቷል', a_expense_void: 'ወጪ ተሰርዟል',
    cancelexp: 'ሰርዝ', fixexp: 'አስተካክል', confirmexpvoid: 'ይህን ወጪ ይሰርዙ? በእንቅስቃሴ መዝገብ ውስጥ ይቀራል።', fixing: 'እያስተካከሉ ያሉት የወጪ መጠን', fixing2: 'ሲያስቀምጡ ይተካዋል።', stopfix: 'አሮጌውን ይተው',
    exprecent: 'የቅርብ ጊዜ ወጪዎች' }
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

var S = { cart: [], pay: 'cash', discount: '', cname: '', cphone: '', q: '', flushing: false, offset: 0, msg: '', other: false, sq: '', stockEdit: null, hist: null, ex: { cat: 'supplies', amount: '', paid: 'cash', note: '' }, fixOf: '', cf: '', cc: '', rep: { range: '7', group: 'day', data: null, at: 0, loading: false, err: '', stale: false } };
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
  var ob = outbox(), saleLines = {};
  ob.forEach(function (e) { if (e.type === 'sale') saleLines[e.req_id] = e.data.items || []; });
  function move(id, d) { var i = id && byId[String(id)]; if (i && i.stock_quantity !== null) i.stock_quantity = Math.max(0, Number(i.stock_quantity) + d); }
  // in the order they were made: a count replaces the level, a delivery adds, waste and sales take away, a cancelled sale gives back
  ob.forEach(function (e) {
    if (e.type === 'sale') (e.data.items || []).forEach(function (l) { move(l.item_id, -l.qty); });
    else if (e.type === 'void') (saleLines[e.data.sale_req_id] || e._restore || []).forEach(function (l) { move(l.item_id, l.qty); });
    else if (e.type === 'stock') {
      var i = byId[String(e.data.item_id)]; if (!i) return;
      if (e.data.kind === 'delivery') { i.stock_quantity = Number(i.stock_quantity || 0) + e.data.qty; }
      else if (e.data.kind === 'waste') move(e.data.item_id, -e.data.qty);
      else if (e.data.kind === 'count') i.stock_quantity = e.data.qty;
    }
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
      if (r.status === 403 || r.status === 400 || r.status === 429) S.msg = (r.json && r.json.message) || '';
      paint(); return;
    }
    S.msg = '';
    var res = {}; (r.json.data.results || []).forEach(function (x) { res[x.req_id] = x; });
    var ob = outbox().filter(function (e) {
      var x = res[e.req_id]; if (!x) return true;
      if (x.status === 'ok' || x.status === 'dup') { onAck(e, x); return false; }
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
    '.sgb-rec{display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;padding:8px 0;border-bottom:1px solid var(--border);font-size:13.5px;align-items:center}.sgb-rec.v{color:var(--text-dim);text-decoration:line-through}' +
    '#sgb-rc{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px}.sgb-rcbox{background:#1c1204;color:var(--text);border:1px solid var(--border-lt);border-radius:14px;padding:18px;max-width:380px;width:100%;max-height:90vh;overflow:auto}' +
    '.sgb-srow{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:10px 0;border-bottom:1px solid var(--border)}.sgb-srow .n{flex:1;min-width:140px}.sgb-srow .n small{display:block;color:var(--text-dim)}.sgb-srow .lv{min-width:90px}' +
    '.sgb-sed{padding:8px 0 12px;border-bottom:1px solid var(--border)}.sgb-chip{font-size:11.5px;border:1px solid var(--border-lt);border-radius:99px;padding:1px 8px}.sgb-chip.low{border-color:var(--warning);color:var(--warning)}.sgb-chip.out{border-color:var(--danger);color:var(--danger)}.sgb-lv{font-size:18px}.sgb-lv.low{color:var(--warning)}.sgb-lv.out{color:var(--danger)}' +
    '.sgb-tbl{width:100%;border-collapse:collapse;font-size:13px}.sgb-tbl th,.sgb-tbl td{padding:6px 4px;text-align:left;border-bottom:1px solid var(--border)}.sgb-tbl td:nth-child(2),.sgb-tbl td:nth-child(3),.sgb-tbl td:nth-child(4){text-align:right}.sgb-tbl td:last-child{width:28%}.sgb-bar1{display:block;height:6px;border-radius:3px;margin:2px 0;min-width:1px}.sgb-bar1.in{background:var(--gold-lt)}.sgb-bar1.out{background:var(--danger)}' +
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
function paintSell() {
  var root = document.getElementById('sgb-root'); if (!root) return;
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
}

/* ---- the stock screen ---- */
function lowLimit() { var v = load('low', 5); return isFinite(Number(v)) ? Number(v) : 5; }
function lowItems() { var lim = lowLimit(); return itemsNow().filter(function (i) { return Number(i.is_available) && i.stock_quantity !== null && Number(i.stock_quantity) <= lim; }); }
function levelOf(i) {
  if (i.stock_quantity === null) return '<span class="sgb-chip">' + esc(t('untracked')) + '</span>';
  var n = Number(i.stock_quantity);
  return '<b class="sgb-lv' + (n <= 0 ? ' out' : (n <= lowLimit() ? ' low' : '')) + '">' + esc(n) + '</b>' + (n <= 0 ? ' <span class="sgb-chip out">' + esc(t('outlbl')) + '</span>' : (n <= lowLimit() ? ' <span class="sgb-chip low">' + esc(t('low')) + '</span>' : ''));
}
function paintStock() {
  var root = document.getElementById('sgb-stock'); if (!root) return;
  css();
  var q = S.sq.toLowerCase(), all = itemsNow(), items = all.filter(function (i) { return !q || String(i.name).toLowerCase().indexOf(q) !== -1; }), low = lowItems().length;
  var rows = items.map(function (i) {
    var ed = S.stockEdit && String(S.stockEdit.id) === String(i.id) ? S.stockEdit : null, h = S.hist && String(S.hist.id) === String(i.id) ? S.hist : null;
    var r = '<div class="sgb-srow"><div class="n"><b>' + esc(i.name) + '</b><small>ETB ' + esc(money(i.price)) + '</small></div><div class="lv">' + levelOf(i) + '</div><div class="sgb-row">' +
      '<button type="button" class="btn btn-secondary sgb-btn" data-sgb="st-open" data-kind="delivery" data-id="' + esc(i.id) + '">' + esc(t('received')) + '</button>' +
      '<button type="button" class="btn btn-secondary sgb-btn" data-sgb="st-open" data-kind="waste" data-id="' + esc(i.id) + '">' + esc(t('waste')) + '</button>' +
      '<button type="button" class="btn btn-secondary sgb-btn" data-sgb="st-open" data-kind="count" data-id="' + esc(i.id) + '">' + esc(t('count')) + '</button>' +
      '<button type="button" class="btn btn-secondary sgb-btn" data-sgb="st-hist" data-id="' + esc(i.id) + '">' + esc(t('history')) + '</button></div></div>';
    if (ed) r += '<div class="sgb-sed sgb-row"><label for="sgb-sqty">' + esc(t('k' + ed.kind)) + ' · ' + esc(t('qty')) + '</label><input id="sgb-sqty" class="field-input" style="max-width:120px" inputmode="numeric" value="' + esc(ed.qty) + '">' +
      '<button type="button" class="btn btn-primary sgb-btn" data-sgb="st-save">' + esc(t('save')) + '</button><button type="button" class="btn btn-secondary sgb-btn" data-sgb="st-cancel">' + esc(t('cancel')) + '</button></div>';
    if (h) r += '<div class="sgb-sed">' + (h.rows === null ? '…' : (h.rows === false ? '<span class="sgb-x">' + esc(t('loadfail')) + '</span>' : (h.rows.length ? h.rows.map(function (m) {
      return '<div class="sgb-rec"><span>' + esc(new Date(String(m.occurred_at).replace(' ', 'T')).toLocaleString()) + ' · ' + esc(t('r_' + m.reason)) + '</span><b>' + (Number(m.delta) > 0 ? '+' : '') + esc(m.delta) + '</b></div>';
    }).join('') : '<p class="empty-msg">' + esc(t('nohist')) + '</p>'))) + '</div>';
    return r;
  }).join('') || '<p class="empty-msg">—</p>';
  root.innerHTML = syncBar() + '<div class="card"><div class="card-body"><div class="sgb-bar ' + (low ? 'warn' : '') + '" role="status"><span>' + (low ? esc(t('lowitems') + ' ' + low) : '✓ ' + esc(t('allgood'))) + '</span>' +
    '<span class="sgb-row"><label for="sgb-lim">' + esc(t('lowlimit')) + '</label><input id="sgb-lim" class="field-input" style="width:80px" inputmode="numeric" value="' + esc(lowLimit()) + '"></span></div>' +
    '<label class="sr-only" for="sgb-sf" style="position:absolute;left:-9999px">' + esc(t('search')) + '</label><input id="sgb-sf" class="field-input" type="search" placeholder="' + esc(t('search')) + '" value="' + esc(S.sq) + '">' + rows + '</div></div>';
}
function openStock(id, kind) {
  var it = itemsNow().filter(function (i) { return String(i.id) === String(id); })[0];
  S.stockEdit = { id: id, kind: kind, qty: kind === 'count' && it && it.stock_quantity !== null ? String(it.stock_quantity) : '' }; S.hist = null; paint();
  var f = document.getElementById('sgb-sqty'); if (f) { f.focus(); try { f.select(); } catch (e) {} }
}
function saveStock() {
  var ed = S.stockEdit; if (!ed) return;
  var f = document.getElementById('sgb-sqty'), n = Number(f ? f.value : ed.qty);
  if (!isFinite(n) || n < 0 || Math.floor(n) !== n || n > 1000000 || (ed.kind !== 'count' && n < 1)) { S.msg = t('bad'); paint(); return; }
  var ob = outbox(); ob.push({ type: 'stock', req_id: uid(), occurred_at: nowIso(), data: { item_id: Number(ed.id), kind: ed.kind, qty: n } }); save('outbox', ob);
  S.stockEdit = null; S.msg = ''; paint(); flush(false);
}
function showHist(id) {
  if (S.hist && String(S.hist.id) === String(id)) { S.hist = null; paint(); return; }
  S.hist = { id: id, rows: null }; S.stockEdit = null; paint();
  post('sg_stock_history', { item_id: Number(id) }).then(function (r) {
    if (!S.hist || String(S.hist.id) !== String(id)) return;
    S.hist.rows = r.json && r.json.success ? (r.json.data.movements || []) : false; paint();
  }, function () { if (S.hist && String(S.hist.id) === String(id)) { S.hist.rows = false; paint(); } });
}

/* ---- expenses and the day's cash count ---- */
function localDate() { var d = new Date(Date.now() + S.offset); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
function expList() { return load('exp', []); }
function onAck(e, x) {
  if (e.type === 'expense') { var l = expList(); l.forEach(function (r) { if (r.req_id === e.req_id) r.synced = 1; }); save('exp', l); }
  if (e.type === 'cashup') { var d = load('day', null); if (d && d.req_id === e.req_id) { if (x.expected_cash !== undefined) { d.expected = Number(x.expected_cash); d.diff = Number(x.difference); } d.synced = 1; save('day', d); } }
}
function saleTotal(e) { return (e.data.items || []).reduce(function (a, l) { return a + l.unit_price * l.qty; }, 0) - (Number(e.data.discount) || 0); }
/* what the till should hold: Etel's figures for today + what this phone has not sent yet */
function cashNow() {
  var st = cache().today || {}, cash = Number(st.cash_sales) || 0, exp = Number(st.cash_expenses) || 0, today = new Date().toDateString();
  outbox().forEach(function (e) {
    if (e.type === 'sale' && e.data.payment_method === 'cash') cash += saleTotal(e);
    else if (e.type === 'expense' && e.data.paid_with === 'cash') exp += Number(e.data.amount) || 0;
  });
  outbox().forEach(function (e) {
    if (e.type !== 'expense_void') return;
    var r = expList().filter(function (x) { return x.req_id === e.data.expense_req_id; })[0];
    if (r && r.paid === 'cash' && new Date(r.at).toDateString() === today) exp -= r.amount;
  });
  outbox().forEach(function (e) {
    if (e.type !== 'void') return;
    var s = recent().filter(function (r) { return r.req_id === e.data.sale_req_id; })[0];
    if (s && s.method === 'cash' && new Date(s.at).toDateString() === today) cash -= s.total;
  });
  return { cash: Math.round(cash * 100) / 100, exp: Math.round(exp * 100) / 100 };
}
function closedDay() {
  var st = cache().today || {}, d = load('day', null);
  if (st.cash_day && st.cash_day.business_date === localDate()) return { float: st.cash_day.opening_float, expected: st.cash_day.expected_cash, counted: st.cash_day.counted_cash, diff: st.cash_day.difference, synced: 1 };
  if (d && d.date === localDate()) return { float: d.float, expected: d.expected, counted: d.counted, diff: d.diff, synced: d.synced };
  return null;
}
function diffLine(diff) {
  var n = Math.round(diff * 100) / 100;
  return n === 0 ? '<b>✓ ' + esc(t('match')) + '</b>' : '<b class="sgb-lv ' + (n < 0 ? 'out' : 'low') + '">ETB ' + esc(money(Math.abs(n))) + '</b> ' + esc(n < 0 ? t('under') : t('over'));
}
function paintExpenses() {
  var root = document.getElementById('sgb-exp'); if (!root) return;
  css();
  var cats = ['supplies', 'rent', 'electricity', 'wages', 'transport', 'other'], today = new Date().toDateString();
  var all = expList().filter(function (r) { return Date.now() - r.at < 3 * 86400000; }), total = all.filter(function (r) { return !r.voided && new Date(r.at).toDateString() === today; }).reduce(function (a, r) { return a + r.amount; }, 0);
  var fixRow = S.fixOf ? expList().filter(function (r) { return r.req_id === S.fixOf; })[0] : null;
  root.innerHTML = syncBar() + '<div class="sgb-wrap"><div class="card"><div class="card-body">' +
    '<label for="sgb-ec">' + esc(t('category')) + '</label><select id="sgb-ec" class="field-input sgb-field">' + cats.map(function (c) { return '<option value="' + c + '"' + (S.ex.cat === c ? ' selected' : '') + '>' + esc(t('c_' + c)) + '</option>'; }).join('') + '</select>' +
    '<label for="sgb-ea">' + esc(t('amount')) + '</label><input id="sgb-ea" class="field-input sgb-field" inputmode="decimal" value="' + esc(S.ex.amount) + '">' +
    '<div class="sgb-pay" role="radiogroup" aria-label="' + esc(t('paidwith')) + '">' + ['cash', 'mobile'].map(function (m) { return '<label><input type="radio" name="sgb-ep" value="' + m + '"' + (S.ex.paid === m ? ' checked' : '') + '><span>' + esc(t(m)) + '</span></label>'; }).join('') + '</div>' +
    '<label for="sgb-en">' + esc(t('note')) + '</label><input id="sgb-en" class="field-input sgb-field" maxlength="200" value="' + esc(S.ex.note) + '">' +
    (S.msg && !outbox().length ? '<p class="sgb-x" role="alert">' + esc(S.msg) + '</p>' : '') +
    (fixRow ? '<p class="sgb-bar warn" role="status">' + esc(t('fixing') + ' ETB ' + money(fixRow.amount) + '. ' + t('fixing2')) + ' <button type="button" class="btn btn-secondary sgb-btn" data-sgb="ex-nofix">' + esc(t('stopfix')) + '</button></p>' : '') +
    '<div class="sgb-row" style="margin-top:12px"><button type="button" class="btn btn-primary" style="flex:1;min-height:48px" data-sgb="ex-add">' + esc(t('addexp')) + '</button></div></div></div>' +
    '<div class="card"><div class="card-body"><h3 style="margin:0 0 8px">' + esc(t('exptoday')) + ' · ETB ' + esc(money(total)) + '</h3><p class="sgb-x" style="color:var(--text-dim);margin:0 0 6px">' + esc(t('exprecent')) + '</p>' +
    (all.length ? all.map(function (r) {
      var day = new Date(r.at).toDateString() === today ? '' : new Date(r.at).toLocaleDateString() + ' ';
      return '<div class="sgb-rec' + (r.voided ? ' v' : '') + '"><span>' + esc(day + hhmm(r.at)) + ' · ' + esc(t('c_' + r.cat)) + ' · ' + esc(t(r.paid)) + (r.note ? ' · ' + esc(r.note) : '') + ' · ' + esc(r.voided ? t('voided') : (r.synced ? t('sentok') : t('unsent'))) + '</span><span><b>ETB ' + esc(money(r.amount)) + '</b>' +
        (r.voided ? '' : ' <button type="button" class="btn btn-secondary sgb-btn" data-sgb="ex-fix" data-id="' + esc(r.req_id) + '">' + esc(t('fixexp')) + '</button> <button type="button" class="btn btn-secondary sgb-btn" data-sgb="ex-void" data-id="' + esc(r.req_id) + '">' + esc(t('cancelexp')) + '</button>') + '</span></div>';
    }).join('') : '<p class="empty-msg">' + esc(t('noexp')) + '</p>') +
    '</div></div></div>';
}
function addExpense() {
  var a = Number(S.ex.amount);
  if (!isFinite(a) || a <= 0 || a > 10000000) { S.msg = t('bad'); paint(); return; }
  var ev = { type: 'expense', req_id: uid(), occurred_at: nowIso(), data: { category: S.ex.cat, amount: Math.round(a * 100) / 100, paid_with: S.ex.paid, note: S.ex.note.trim() } };
  var ob = outbox(); ob.push(ev); save('outbox', ob);
  var l = expList(); l.unshift({ req_id: ev.req_id, at: Date.now(), cat: ev.data.category, amount: ev.data.amount, paid: ev.data.paid_with, note: ev.data.note, synced: 0 }); save('exp', l.slice(0, 80));
  if (S.fixOf) { voidExpense(S.fixOf, true); S.fixOf = ''; }
  S.ex.amount = ''; S.ex.note = ''; S.msg = ''; paint(); flush(false);
}
/* cancels an expense. One that was never sent just disappears from the phone; a sent one is cancelled in Etel's books (and stays in the activity log) */
function voidExpense(reqId, quiet) {
  var l = expList(), row = l.filter(function (r) { return r.req_id === reqId; })[0]; if (!row || row.voided) return;
  if (!quiet && !window.confirm(t('confirmexpvoid'))) return;
  var ob = outbox(), pending = ob.filter(function (e) { return e.type === 'expense' && e.req_id === reqId; })[0];
  if (pending && (!S.flushing || pending.failed)) {
    save('outbox', ob.filter(function (e) { return e.req_id !== reqId; })); save('exp', l.filter(function (r) { return r.req_id !== reqId; }));
  } else {
    ob.push({ type: 'expense_void', req_id: uid(), occurred_at: nowIso(), data: { expense_req_id: reqId } }); save('outbox', ob);
    row.voided = 1; save('exp', l);
  }
  if (!quiet) { paint(); flush(false); }
}
function paintCashup() {
  var root = document.getElementById('sgb-cash'); if (!root) return;
  css();
  var day = closedDay(), c = cashNow(), st = cache().today || {};
  if (S.cf === '' && st.last_counted !== null && st.last_counted !== undefined) S.cf = String(st.last_counted);
  var fl = Number(S.cf) || 0, expected = Math.round((fl + c.cash - c.exp) * 100) / 100, counted = S.cc === '' ? null : Number(S.cc);
  var body;
  if (day) {
    body = '<h3 style="margin:0 0 8px">✓ ' + esc(t('dayclosed')) + '</h3>' + row2(t('openfloat'), day.float) + row2(t('expected'), day.expected) + row2(t('closedat'), day.counted) + '<p>' + diffLine(day.diff) + '</p>' + (day.synced ? '' : '<p class="sgb-x">' + esc(t('estimate')) + '</p>');
  } else {
    body = '<label for="sgb-cf">' + esc(t('openfloat')) + '</label><input id="sgb-cf" class="field-input sgb-field" inputmode="decimal" value="' + esc(S.cf) + '">' +
      row2(t('cashsales'), c.cash) + row2(t('cashexp'), -c.exp) + '<div class="sgb-tot"><span>' + esc(t('expected')) + '</span><span>ETB ' + esc(money(expected)) + '</span></div>' +
      '<label for="sgb-cc">' + esc(t('counted')) + '</label><input id="sgb-cc" class="field-input sgb-field" inputmode="decimal" value="' + esc(S.cc) + '">' +
      (counted !== null && isFinite(counted) ? '<p aria-live="polite">' + esc(t('diff')) + ': ' + diffLine(counted - expected) + '</p>' : '') +
      '<p class="sgb-x" style="color:var(--text-dim)">' + esc(t('estimate')) + '</p>' + (S.msg && !outbox().length ? '<p class="sgb-x" role="alert">' + esc(S.msg) + '</p>' : '') +
      '<div class="sgb-row" style="margin-top:12px"><button type="button" class="btn btn-primary" style="flex:1;min-height:48px" data-sgb="close-day"' + (counted === null || !isFinite(counted) ? ' disabled' : '') + '>' + esc(t('closeday')) + '</button></div>';
  }
  root.innerHTML = syncBar() + '<div class="card"><div class="card-body">' + body + '</div></div>';
}
function row2(label, v) { return '<div class="sgb-rec"><span>' + esc(label) + '</span><b>ETB ' + esc(money(v)) + '</b></div>'; }
function closeDay() {
  var fl = Number(S.cf) || 0, counted = Number(S.cc);
  if (!isFinite(counted) || counted < 0 || !isFinite(fl) || fl < 0) { S.msg = t('bad'); paint(); return; }
  if (outbox().some(function (e) { return !e.failed; }) && !window.confirm(t('unsentwarn'))) return;
  var c = cashNow(), expected = Math.round((fl + c.cash - c.exp) * 100) / 100;
  var ev = { type: 'cashup', req_id: uid(), occurred_at: nowIso(), data: { business_date: localDate(), opening_float: fl, counted_cash: counted } };
  var ob = outbox(); ob.push(ev); save('outbox', ob);
  save('day', { req_id: ev.req_id, date: localDate(), float: fl, expected: expected, counted: counted, diff: Math.round((counted - expected) * 100) / 100, synced: 0 });
  S.cc = ''; S.msg = ''; paint(); flush(false);
}

/* ---- the report ---- */
function ymd(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
function repRange() {
  var now = new Date(Date.now() + S.offset), to = ymd(now), r = S.rep.range, from;
  if (r === 'month') from = ymd(new Date(now.getFullYear(), now.getMonth(), 1));
  else { var d = new Date(now.getFullYear(), now.getMonth(), now.getDate() - (Number(r) - 1)); from = ymd(d); }
  return { from: from, to: to };
}
function loadReport() {
  var rg = repRange(), ck = 'rep_' + S.rep.range;
  S.rep.loading = true; S.rep.err = ''; paintReport();
  return post('sg_business_report', rg).then(function (r) {
    S.rep.loading = false;
    if (r.json && r.json.success) { S.rep.data = r.json.data; S.rep.at = Date.now(); S.rep.stale = false; save(ck, { at: S.rep.at, data: r.json.data }); }
    else { S.rep.err = (r.json && r.json.message) || t('repfail'); useSaved(ck); }
    paintReport();
  }, function () { S.rep.loading = false; S.rep.err = t('repfail'); useSaved(ck); paintReport(); });
}
function useSaved(ck) { var c = load(ck, null); if (c && c.data) { S.rep.data = c.data; S.rep.at = c.at; S.rep.stale = true; } else { S.rep.data = null; } }
/* the day rows grouped by day, week (Monday) or month */
function groupRows(days, g) {
  var out = {}, order = [];
  days.forEach(function (d) {
    var k = d.date;
    if (g !== 'day') {
      var dt = new Date(d.date + 'T00:00:00');
      if (g === 'month') k = d.date.slice(0, 7);
      else { var back = (dt.getDay() + 6) % 7; k = ymd(new Date(dt.getFullYear(), dt.getMonth(), dt.getDate() - back)); }
    }
    if (!out[k]) { out[k] = { key: k, sales: 0, counter: 0, online: 0, expenses: 0 }; order.push(k); }
    out[k].sales += d.sales + d.online_orders; out[k].counter += d.counter; out[k].online += d.online; out[k].expenses += d.expenses;
  });
  return order.sort().map(function (k) { return out[k]; });
}
function csvCell(v) { var x = String(v == null ? '' : v); if (/^[=+\-@\t\r]/.test(x)) x = "'" + x; return '"' + x.replace(/"/g, '""') + '"'; }
function downloadCsv() {
  var d = S.rep.data; if (!d) return;
  var rows = [['Period', 'Sales', 'Counter sales', 'Online orders', 'Money in', 'Expenses', 'Left']];
  groupRows(d.days, S.rep.group).forEach(function (r) { rows.push([r.key, r.sales, r.counter.toFixed(2), r.online.toFixed(2), (r.counter + r.online).toFixed(2), r.expenses.toFixed(2), (r.counter + r.online - r.expenses).toFixed(2)]); });
  rows.push([]); rows.push(['Item', 'Sold', 'Revenue']);
  d.best.forEach(function (b) { rows.push([b.name, b.qty, b.revenue.toFixed(2)]); });
  rows.push([]); rows.push(['Expense', 'Amount']);
  d.expense_categories.forEach(function (c) { rows.push([c.category, c.amount.toFixed(2)]); });
  var blob = new Blob(['﻿' + rows.map(function (r) { return r.map(csvCell).join(','); }).join('\r\n')], { type: 'text/csv;charset=utf-8' });
  var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'etel-report-' + d.from + '_' + d.to + '.csv';
  document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); if (a.parentNode) a.parentNode.removeChild(a); }, 500);
}
function activityCard(rows) {
  return '<div class="card"><div class="card-body"><h3 style="margin:0 0 4px">' + esc(t('activity')) + '</h3><p class="sgb-x" style="color:var(--text-dim);margin:0 0 8px">' + esc(t('activitysub')) + '</p>' +
    (rows.length ? rows.map(function (a) {
      var when = new Date(String(a.occurred_at).replace(' ', 'T'));
      return '<div class="sgb-rec"><span><b>' + esc(t('a_' + a.action)) + '</b>' + (a.who ? ' · ' + esc(t('by_')) + ' ' + esc(a.who) : '') + (a.detail ? '<br><small>' + esc(a.detail) + '</small>' : '') + '</span><span>' + esc(isNaN(when) ? '' : when.toLocaleString()) + '</span></div>';
    }).join('') : '<p class="empty-msg">' + esc(t('noact')) + '</p>') + '</div></div>';
}
function paintReport() {
  var root = document.getElementById('sgb-rep'); if (!root) return;
  css();
  var R = S.rep, d = R.data, tabs = '';
  tabs = '<div class="sgb-row" role="group" aria-label="' + esc(t('period')) + '">' + [['7', 'r7'], ['30', 'r30'], ['90', 'r90'], ['month', 'rmonth']].map(function (x) {
    return '<button type="button" class="btn ' + (R.range === x[0] ? 'btn-primary' : 'btn-secondary') + ' sgb-btn" data-sgb="rep-range" data-v="' + x[0] + '" aria-pressed="' + (R.range === x[0]) + '">' + esc(t(x[1])) + '</button>';
  }).join('') + '</div><div class="sgb-row" style="margin-top:8px" role="group" aria-label="' + esc(t('by')) + '"><span>' + esc(t('by')) + ':</span>' + [['day', 'gday'], ['week', 'gweek'], ['month', 'gmonth']].map(function (x) {
    return '<button type="button" class="btn ' + (R.group === x[0] ? 'btn-primary' : 'btn-secondary') + ' sgb-btn" data-sgb="rep-group" data-v="' + x[0] + '" aria-pressed="' + (R.group === x[0]) + '">' + esc(t(x[1])) + '</button>';
  }).join('') + '</div>';
  var body = '';
  if (R.loading && !d) body = '<p class="empty-msg">' + esc(t('loading')) + '</p>';
  else if (!d) body = '<p class="sgb-x" role="alert">' + esc(R.err || t('repfail')) + '</p>';
  else {
    var T = d.totals, rows = groupRows(d.days, R.group), max = rows.reduce(function (m, r) { return Math.max(m, r.counter + r.online, r.expenses); }, 0) || 1;
    var empty = !T.sales && !T.online_orders && !T.expenses;
    body = (R.stale ? '<div class="sgb-bar warn" role="status">' + esc(t('saved')) + ' ' + esc(hhmm(R.at)) + '</div>' : '') +
      '<div class="sgb-wrap"><div class="card"><div class="card-body"><div class="sgb-rec"><span>' + esc(t('income')) + '</span><b>ETB ' + esc(money(T.income)) + '</b></div>' +
      '<div class="sgb-rec"><span>' + esc(t('spent')) + '</span><b>ETB ' + esc(money(T.expenses)) + '</b></div>' +
      '<div class="sgb-tot"><span>' + esc(t('net')) + '</span><span>ETB ' + esc(money(T.net)) + '</span></div><p class="sgb-x" style="color:var(--text-dim)">' + esc(t('netnote')) + '</p></div></div>' +
      '<div class="card"><div class="card-body"><div class="sgb-rec"><span>' + esc(t('counter')) + ' (' + esc(T.sales) + ')</span><b>ETB ' + esc(money(T.gross)) + '</b></div>' +
      '<div class="sgb-rec"><span>· ' + esc(t('cash')) + '</span><span>ETB ' + esc(money(T.cash)) + '</span></div><div class="sgb-rec"><span>· ' + esc(t('mobile')) + '</span><span>ETB ' + esc(money(T.mobile)) + '</span></div>' +
      '<div class="sgb-rec"><span>· ' + esc(t('credit')) + '</span><span>ETB ' + esc(money(T.credit)) + '</span></div>' +
      '<div class="sgb-rec"><span>' + esc(t('online')) + ' (' + esc(T.online_orders) + ')</span><b>ETB ' + esc(money(T.online)) + '</b></div>' +
      '<div class="sgb-rec"><span>' + esc(t('vdisc')) + '</span><span>ETB ' + esc(money(T.discounts)) + '</span></div>' +
      (T.voided ? '<div class="sgb-rec"><span>' + esc(t('vvoid')) + '</span><span>' + esc(T.voided) + '</span></div>' : '') + '<p class="sgb-x" style="color:var(--text-dim)">' + esc(t('repnote')) + '</p></div></div></div>';
    if (empty) body += '<p class="empty-msg">' + esc(t('norep')) + '</p>';
    else {
      body += '<div class="card"><div class="card-body"><table class="sgb-tbl"><caption class="sr-only" style="position:absolute;left:-9999px">' + esc(t('period')) + '</caption><thead><tr><th scope="col">' + esc(t('period')) + '</th><th scope="col">' + esc(t('nosales')) + '</th><th scope="col">' + esc(t('income')) + '</th><th scope="col">' + esc(t('spent')) + '</th><th scope="col" aria-hidden="true"></th></tr></thead><tbody>' +
        rows.map(function (r) {
          var inc = r.counter + r.online;
          return '<tr><th scope="row">' + esc(r.key) + '</th><td>' + esc(r.sales) + '</td><td>' + esc(money(inc)) + '</td><td>' + esc(money(r.expenses)) + '</td><td aria-hidden="true"><span class="sgb-bar1 in" style="width:' + Math.round(inc / max * 100) + '%"></span><span class="sgb-bar1 out" style="width:' + Math.round(r.expenses / max * 100) + '%"></span></td></tr>';
        }).join('') + '</tbody></table></div></div>';
      body += '<div class="sgb-wrap"><div class="card"><div class="card-body"><h3 style="margin:0 0 8px">' + esc(t('best')) + '</h3>' + (d.best.length ? d.best.map(function (b) { return '<div class="sgb-rec"><span>' + esc(b.name) + ' · ' + esc(b.qty) + ' ' + esc(t('sold_n')) + '</span><b>ETB ' + esc(money(b.revenue)) + '</b></div>'; }).join('') : '<p class="empty-msg">—</p>') +
        '<h3 style="margin:14px 0 8px">' + esc(t('slow')) + '</h3>' + (d.slow.length ? d.slow.map(function (b) { return '<div class="sgb-rec"><span>' + esc(b.name) + '</span><span>' + esc(b.qty) + ' ' + esc(t('sold_n')) + '</span></div>'; }).join('') : '<p class="empty-msg">—</p>') + '</div></div>' +
        '<div class="card"><div class="card-body"><h3 style="margin:0 0 8px">' + esc(t('byexp')) + '</h3>' + (d.expense_categories.length ? d.expense_categories.map(function (c) { return '<div class="sgb-rec"><span>' + esc(t('c_' + c.category)) + '</span><b>ETB ' + esc(money(c.amount)) + '</b></div>'; }).join('') : '<p class="empty-msg">—</p>') + '</div></div></div>';
      body += activityCard(d.audit || []);
      body += '<div class="sgb-row" style="margin-top:12px"><button type="button" class="btn btn-secondary" data-sgb="rep-csv">' + esc(t('csv')) + '</button></div>';
    }
    if (R.err && !R.stale) body += '<p class="sgb-x" role="alert">' + esc(R.err) + '</p>';
  }
  root.innerHTML = '<div class="card"><div class="card-body">' + tabs + '</div></div>' + body;
}

function paint() {
  var keep = document.activeElement && document.activeElement.id;
  paintSell(); paintStock(); paintExpenses(); paintCashup(); paintReport();
  if (keep) { var el = document.getElementById(keep); if (el && el.focus) { el.focus(); try { el.setSelectionRange(el.value.length, el.value.length); } catch (e) {} } }
  var bd = document.getElementById('sgb-badge'), n = outbox().length;
  if (bd) { bd.textContent = n; bd.style.display = n ? '' : 'none'; }
  var sb = document.getElementById('sgb-badge-stock'), lo = lowItems().length;
  if (sb) { sb.textContent = lo; sb.style.display = lo ? '' : 'none'; }
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
  else if (a === 'st-open') openStock(id, b.getAttribute('data-kind'));
  else if (a === 'st-save') saveStock();
  else if (a === 'st-cancel') { S.stockEdit = null; paint(); }
  else if (a === 'st-hist') showHist(id);
  else if (a === 'ex-add') addExpense();
  else if (a === 'ex-void') voidExpense(id);
  else if (a === 'ex-nofix') { S.fixOf = ''; paint(); }
  else if (a === 'ex-fix') {
    var fr = expList().filter(function (r) { return r.req_id === id; })[0];
    if (fr) { S.fixOf = id; S.ex = { cat: fr.cat, amount: String(fr.amount), paid: fr.paid, note: fr.note || '' }; paint(); var fa = document.getElementById('sgb-ea'); if (fa) { fa.focus(); try { fa.select(); } catch (x) {} } }
  }
  else if (a === 'close-day') closeDay();
  else if (a === 'rep-range') { S.rep.range = b.getAttribute('data-v'); loadReport(); }
  else if (a === 'rep-group') { S.rep.group = b.getAttribute('data-v'); paintReport(); }
  else if (a === 'rep-csv') downloadCsv();
  else if (a === 'rc-close') closeReceipt();
  else if (a === 'rc-print') printText(document.getElementById('sgb-rc')._text);
  else if (a === 'rc-share') { try { navigator.share({ text: document.getElementById('sgb-rc')._text }); } catch (x) {} }
}
function onInput(e) {
  var t_ = e.target;
  if (t_.id === 'sgb-q') { S.q = t_.value; paint(); }
  else if (t_.id === 'sgb-disc') { S.discount = t_.value; paintTotalsOnly(); }
  else if (t_.id === 'sgb-sf') { S.sq = t_.value; paint(); }
  else if (t_.id === 'sgb-ec') S.ex.cat = t_.value;
  else if (t_.id === 'sgb-ea') S.ex.amount = t_.value;
  else if (t_.id === 'sgb-en') S.ex.note = t_.value;
  else if (t_.name === 'sgb-ep') S.ex.paid = t_.value;
  else if (t_.id === 'sgb-cf') { S.cf = t_.value; paint(); }
  else if (t_.id === 'sgb-cc') { S.cc = t_.value; paint(); }
  else if (t_.id === 'sgb-lim') { var v = Number(t_.value); if (isFinite(v) && v >= 0) { save('low', Math.floor(v)); paint(); } }
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

var PAGES = [
  { id: 'sell', cap: 'pos.sell', icon: '🧾', label: 'sell', sub: 'sub', root: 'sgb-root', menu: 'sgb-menu', badge: 'sgb-badge' },
  { id: 'stock', cap: 'stock.manage', icon: '📦', label: 'stock', sub: 'stocksub', root: 'sgb-stock', menu: 'sgb-menu-stock', badge: 'sgb-badge-stock' },
  { id: 'exp', cap: 'expenses.manage', icon: '🧮', label: 'expenses', sub: 'expsub', root: 'sgb-exp', menu: 'sgb-menu-exp', badge: 'sgb-badge-exp' },
  { id: 'cash', cap: 'cashup.manage', icon: '💵', label: 'cashup', sub: 'cashsub', root: 'sgb-cash', menu: 'sgb-menu-cash', badge: 'sgb-badge-cash' },
  { id: 'rep', cap: 'reports.view', cap2: 'payments.view', icon: '📊', label: 'rep', sub: 'repsub', root: 'sgb-rep', menu: 'sgb-menu-rep', badge: 'sgb-badge-rep' }
];
var wired = false;
function mountPage(def) {
  var m = document.getElementById(def.menu);
  if (!enabled() || !can(def.cap) || (def.cap2 && !can(def.cap2))) { if (m) m.style.display = 'none'; return; }
  if (m) { m.style.display = ''; return; }
  var orders = document.querySelector('.menu-item[data-page="orders"]'); var host = document.getElementById('page-orders'); if (!orders || !host) return;
  var mi = document.createElement('div'); mi.className = 'menu-item'; mi.id = def.menu; mi.setAttribute('data-page', 'biz-' + def.id); mi.setAttribute('role', 'button'); mi.tabIndex = 0;
  mi.innerHTML = '<div class="menu-icon" aria-hidden="true">' + def.icon + '</div><div class="menu-label">' + esc(t(def.label)) + '</div><div class="menu-badge" id="' + def.badge + '" style="display:none">0</div>';
  orders.parentNode.insertBefore(mi, orders);
  var pg = document.createElement('div'); pg.id = 'page-biz-' + def.id; pg.style.display = 'none';
  pg.innerHTML = '<div class="page-title">' + esc(t(def.label)) + '</div><div class="page-subtitle">' + esc(t(def.sub)) + '</div><div id="' + def.root + '"></div>';
  host.parentNode.insertBefore(pg, host);
  var show = function () { document.querySelectorAll('[id^="page-"]').forEach(function (p) { p.style.display = 'none'; }); pg.style.display = 'block'; };
  var go = function () {
    if (typeof switchPage === 'function') { try { switchPage('biz-' + def.id); } catch (x) { show(); } } else show();
    if (typeof closeSidebar === 'function') { try { closeSidebar(); } catch (x) {} }
    openScreen(def);
  };
  mi.addEventListener('click', go);
  mi.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
  var root = pg.querySelector('#' + def.root);
  root.addEventListener('click', onClick); root.addEventListener('input', onInput); root.addEventListener('change', onInput);
  root.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target && e.target.id === 'sgb-sqty') { e.preventDefault(); saveStock(); } });
}
function mount() {
  PAGES.forEach(mountPage);
  if (!wired) { wired = true; document.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('#sgb-rc')) onClick(e); }); }
  paint();
}
function openScreen(def) { paint(); flush(false); refreshState(); if (def && def.id === 'rep') loadReport(); }

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
