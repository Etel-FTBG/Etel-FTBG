/* ETEL Admin - Payments hub (premium).
   One controller screen for every way money reaches Etel: an overview with revenue and the queue of things waiting on a person,
   online (Chapa) payments with problem flags, Telebirr and bank-transfer confirmations, delivered orders whose cash was never recorded,
   business payout-detail change approvals, and the payment-method logos. Money decisions use the kit dialogs (rejections ask for a reason
   on the record where the server keeps one). Data: admin_financial_report, admin_list_chapa_payments, admin_list_telebirr_payments,
   admin_confirm_telebirr_payment, admin_list_bank_payments, admin_confirm_bank_payment, admin_unpaid_delivered, admin_mark_order_paid,
   admin_list_payout_requests, admin_review_payout_request, get_payment_method_logos, admin_set_payment_logo.
   Replaces the old stacked sections: the loaders admin-ui.js calls on opening the page now refresh this hub. Every server value goes through AdminKit.esc. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;
var V = { tab: 'overview', range: '30', chapaDays: 14, q: '', loaded: false, busy: false, d: {} };
var TABS = [['overview', 'Overview'], ['online', 'Online'], ['confirm', 'Confirmations'], ['cash', 'Cash'], ['payout', 'Payout changes'], ['logos', 'Logos']];
var RANGES = [['7', '7 days'], ['30', '30 days'], ['90', '90 days'], ['all', 'All time']];
var BANK = { abysinia: 'Abysinia Bank', cbe: 'Commercial Bank of Ethiopia' };
var FLAG = { paid_but_cancelled: 'Paid at Chapa but the order was cancelled — refund this customer', retry_unused: 'Customer paid on another attempt — nothing to do',
  stuck_pending: 'Waiting over 30 minutes — check with Chapa', paid_but_order_unpaid: 'Paid at Chapa but the order is not marked paid — needs attention' };
var LOGO_METHODS = [['telebirr', 'Telebirr', '#a8500c'], ['cbe', 'CBE', '#14703a'], ['abysinia', 'Abysinia', '#1f618d'], ['wallet', 'Etel Pay Wallet', '#6a3f94'], ['cash', 'Cash', '#6b6200']];

function root() { return document.getElementById('pay-hub'); }
function f(n) { return parseFloat(n) || 0; }
function etb(n) { return K.money(f(n)); }
function plural(n, w) { n = parseInt(n, 10) || 0; return n + ' ' + w + (n === 1 ? '' : 's'); }
function arr(res, key) { return K.okRes(res) ? (K.data(res)[key] || []) : []; }
function safeUrl(u) { u = String(u || ''); return /^(https?:)?\/\//i.test(u) || /^\/?[\w.\-\/]+\.(jpe?g|png|webp|gif|svg)$/i.test(u) ? u : ''; }

function css() {
  K.css();
  if (document.getElementById('ph-css')) return;
  var s = document.createElement('style'); s.id = 'ph-css';
  s.textContent = [
    '.ph-tabs{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0 12px}',
    '.ph-tabs .ak-seg button b{display:inline-block;min-width:18px;margin-left:6px;padding:0 6px;border-radius:9px;background:var(--red);color:var(--on-red);font-size:10.5px;line-height:18px}',
    '.ph-box{background:var(--card);border:1.5px solid var(--line);border-radius:18px;padding:16px;box-shadow:var(--shadow);margin-bottom:12px}',
    '.ph-box h3{margin:0 0 2px;font-size:14.5px;color:var(--ink)}.ph-box .sub{font-size:12px;color:var(--mute);margin-bottom:12px}',
    '.ph-cols{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);gap:12px}@media(max-width:900px){.ph-cols{grid-template-columns:1fr}}',
    '.ph-m{display:grid;grid-template-columns:1fr auto;gap:4px 10px;align-items:center;margin-top:12px}',
    '.ph-m .n{font-size:13px;font-weight:700;color:var(--ink)}.ph-m .n small{display:block;font-weight:500;color:var(--mute);font-size:11.5px}.ph-m .v{font-size:13px;font-weight:800;text-align:right}',
    '.ph-track{grid-column:1 / 3;height:7px;border-radius:7px;background:var(--line);overflow:hidden}.ph-track div{height:100%;border-radius:7px;background:linear-gradient(90deg,var(--gold),var(--gold2))}',
    '.ph-queue{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px}',
    '.ph-q{text-align:left;cursor:pointer;border:1.5px solid var(--line);background:var(--soft);color:var(--ink);font:inherit;border-radius:14px;padding:12px 14px}',
    '.ph-q:hover,.ph-q:focus-visible{border-color:var(--gold)}.ph-q b{display:block;font-size:22px;font-weight:800}.ph-q span{font-size:12px;color:var(--mute)}.ph-q.hot b{color:var(--red)}',
    '.ph-item{display:flex;gap:12px;justify-content:space-between;align-items:center;flex-wrap:wrap;background:var(--card);border:1.5px solid var(--line);border-radius:16px;padding:13px 15px;box-shadow:var(--shadow);margin-bottom:10px}',
    '.ph-item .t{font-weight:800;font-size:14px;color:var(--ink)}.ph-item .s{font-size:12px;color:var(--mute);margin-top:2px;word-break:break-word}',
    '.ph-h{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--gold2);font-weight:800;margin:16px 0 8px}',
    '.ph-diff{display:flex;justify-content:space-between;gap:10px;font-size:12.5px;padding:5px 0;border-bottom:1px solid var(--line)}.ph-diff s{color:var(--red)}.ph-diff b{color:var(--green)}',
    '.ph-logos{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}',
    '.ph-logo{background:var(--card);border:1.5px solid var(--line);border-radius:16px;padding:14px;text-align:center;box-shadow:var(--shadow)}',
    '.ph-lg{width:60px;height:60px;border-radius:14px;margin:0 auto 10px;display:flex;align-items:center;justify-content:center;overflow:hidden;color:#fff;font-weight:800;font-size:12px}.ph-lg img{width:100%;height:100%;object-fit:contain;padding:5px}',
    '.ph-r{text-align:right}.ak-tbl th.ph-r{text-align:right}.ph-warn{color:var(--red);font-weight:700}.ph-mono{font-family:ui-monospace,Menlo,monospace}',
    '.ph-vh{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}'
  ].join('\n');
  document.head.appendChild(s);
}

/* ---------- loading ---------- */
function setBadge() {
  var n = counts().all, b = document.getElementById('telebirr-badge');
  if (b) { b.style.display = n ? 'inline-block' : 'none'; b.textContent = n; }
}
function counts() {
  var d = V.d, tb = (d.telebirr || []).length, bk = (d.bank || []).length, po = (d.payouts || []).length, cash = (d.unpaid && d.unpaid.orders || []).length;
  var chapa = (d.chapa && d.chapa.summary && parseInt(d.chapa.summary.needs_attention, 10)) || 0;
  return { tb: tb, bk: bk, po: po, cash: cash, chapa: chapa, confirm: tb + bk, all: tb + bk + po + chapa };
}
function load(quiet) {
  var el = root(); if (!el || V.busy) return Promise.resolve();
  css(); V.busy = true;
  if (!quiet && !V.loaded) el.innerHTML = '<div class="ak">' + K.skeleton(4) + '</div>';
  return Promise.all([
    K.api('admin_financial_report', { days: V.range }), K.api('admin_list_chapa_payments', { days: V.chapaDays }),
    K.api('admin_list_telebirr_payments', {}), K.api('admin_list_bank_payments', {}), K.api('admin_unpaid_delivered', {}),
    K.api('admin_list_payout_requests', {})
  ]).then(function (r) {
    V.busy = false;
    var d = V.d = { report: K.okRes(r[0]) ? K.data(r[0]) : null, reportErr: K.okRes(r[0]) ? '' : K.failText(r[0]),
      chapa: K.okRes(r[1]) ? K.data(r[1]) : null, chapaErr: K.okRes(r[1]) ? '' : K.failText(r[1]),
      telebirr: arr(r[2], 'payments'), tbErr: K.okRes(r[2]) ? '' : K.failText(r[2]),
      bank: arr(r[3], 'payments'), bkErr: K.okRes(r[3]) ? '' : K.failText(r[3]),
      unpaid: K.okRes(r[4]) ? K.data(r[4]) : null, unpaidErr: K.okRes(r[4]) ? '' : K.failText(r[4]),
      payouts: arr(r[5], 'requests'), poErr: K.okRes(r[5]) ? '' : K.failText(r[5]), logos: V.d.logos };
    if (r.every(function (x) { return !K.okRes(x); })) {
      if (!V.loaded) { el.innerHTML = '<div class="ak">' + K.empty('⚠️', 'Could not load Payments', K.failText(r[0])) + '<div style="text-align:center;margin-top:12px"><button type="button" class="ak-btn gold" data-act="refresh">Try again</button></div></div>'; return; }
    }
    V.loaded = true; setBadge(); paint();
  });
}
function loadLogos() {
  return K.api('get_payment_method_logos', {}).then(function (res) { V.d.logos = K.okRes(res) ? (K.data(res).logos || {}) : {}; if (V.tab === 'logos') paint(); });
}
function again() { V.busy = false; return load(true); }

/* ---------- views ---------- */
function section(err, body) { return err ? K.empty('⚠️', 'Could not load this part', err) : body; }

function overview() {
  var d = V.d, r = d.report, c = counts();
  var q = '<div class="ph-box"><h3>Waiting on you</h3><div class="sub">Everything below needs a person to decide or check it.</div><div class="ph-queue">' +
    queueBtn('confirm', 'Telebirr & bank confirmations', c.confirm) + queueBtn('payout', 'Payout-detail changes', c.po) +
    queueBtn('cash', 'Delivered orders not marked paid', c.cash) + queueBtn('online', 'Online payments needing attention', c.chapa) + '</div></div>';
  var split = '';
  if (r) {
    var tb = r.telebirr || {}, rows = [['Cash orders', f((r.cash || {}).total), plural((r.cash || {}).count || 0, 'order')],
      ['Telebirr confirmed', f((tb.completed || {}).total), plural((tb.completed || {}).count || 0, 'payment')],
      ['Telebirr pending', f((tb.pending || {}).total), plural((tb.pending || {}).count || 0, 'payment')]];
    Object.keys(r.bank || {}).forEach(function (k) {
      var s = r.bank[k], v = s.verified || { count: 0, total: 0 }, p = s.pending || { count: 0, total: 0 };
      rows.push([(BANK[k] || k) + ' verified', f(v.total), plural(v.count, 'transfer')]); rows.push([(BANK[k] || k) + ' pending', f(p.total), plural(p.count, 'transfer')]);
    });
    var max = 0; rows.forEach(function (x) { if (x[1] > max) max = x[1]; });
    split = '<div class="ph-box"><h3>Where the money is</h3><div class="sub">Orders in the chosen window, by how they were paid</div>' + rows.map(function (x) {
      var pct = max ? Math.round(x[1] / max * 100) : 0;
      return '<div class="ph-m"><div class="n">' + esc(x[0]) + '<small>' + esc(x[2]) + '</small></div><div class="v">' + esc(etb(x[1])) + '</div><div class="ph-track" role="img" aria-label="' + esc(x[0] + ', ' + pct + ' percent of the largest') + '"><div style="width:' + pct + '%"></div></div></div>';
    }).join('') + '</div>';
  } else split = '<div class="ph-box">' + K.empty('⚠️', 'Could not load the report', d.reportErr) + '</div>';
  return '<div class="ph-cols"><div>' + q + '</div><div>' + split + '</div></div>';
}
function queueBtn(tab, label, n) {
  return '<button type="button" class="ph-q' + (n ? ' hot' : '') + '" data-goto="' + tab + '"><b>' + K.num(n) + '</b><span>' + esc(label) + '</span></button>';
}

function online() {
  var d = V.d.chapa; if (!d) return K.empty('⚠️', 'Could not load online payments', V.d.chapaErr);
  var s = d.summary || {}, rows = d.payments || [];
  var h = '<div class="ak-tools"><label class="ak-fl" style="margin:0"><span class="ph-vh">Period</span><select class="ak-sel" id="ph-cdays" aria-label="Period"><option value="7">Last 7 days</option><option value="14">Last 14 days</option><option value="30">Last 30 days</option></select></label></div>';
  if (!d.enabled) h += '<div class="ph-box"><span class="ph-warn">Chapa is not switched on on this server yet</span> — see the Chapa setup guide.</div>';
  h += K.kpis([{ key: 'p', icon: '✅', label: 'Paid', value: K.num(s.completed || 0) }, { key: 'pa', icon: '💰', label: 'Paid amount', value: etb(s.completed_amount) },
    { key: 'w', icon: '⏳', label: 'Waiting', value: K.num(s.pending || 0) }, { key: 'f', icon: '❌', label: 'Failed', value: K.num(s.failed || 0) },
    { key: 'a', icon: '🚩', label: 'Needs attention', value: K.num(s.needs_attention || 0), warn: true }], '', true);
  if (!rows.length) return h + K.empty('🌐', 'No online payments in this period', 'Chapa payments appear here as customers pay.');
  var tone = { completed: 'green', failed: 'red', pending: 'amber' };
  return h + '<div class="ak-tblw"><table class="ak-tbl"><thead><tr><th>When</th><th>For</th><th>Customer</th><th class="ph-r">Amount</th><th>Status</th><th>Reference</th></tr></thead><tbody>' + rows.map(function (r) {
    var target = r.kind === 'order' ? 'Order ' + (r.order_code || ('#' + r.target_id)) : 'Ride #' + (parseInt(r.target_id, 10) || '');
    var flag = r.flag ? '<div style="white-space:normal;max-width:300px;font-size:11.5px" class="' + (r.flag === 'retry_unused' ? '' : 'ph-warn') + '">' + esc(FLAG[r.flag] || r.flag) + '</div>' : '';
    return '<tr><td>' + esc(K.fullDate(r.created_at)) + '</td><td>' + esc(target) + '</td><td>' + esc(r.customer_name || '') + '<div style="font-size:11.5px;color:var(--mute)">' + esc(r.customer_phone || '') + '</div></td><td class="ph-r">' + esc(etb(r.amount)) + '</td><td>' + K.pill(r.status, tone[r.status] || 'grey') + flag + '</td><td class="ph-mono">' + esc(r.tx_ref) + '</td></tr>';
  }).join('') + '</tbody></table></div>';
}

function confirmItem(kind, p) {
  var tb = kind === 'tb';
  var sub = tb ? esc(p.customer_name || '') + ' · Telebirr number ' + esc(p.phone || '') : esc(p.customer_name || '') + ' · ' + esc(BANK[p.bank] || p.bank || '') + ' · Ref ' + esc(p.reference_no || '');
  return '<div class="ph-item"><div><div class="t">Order ' + esc(p.order_code) + ' — ' + esc(etb(p.amount)) + '</div><div class="s">' + sub + ' · ' + esc(K.ago(p.created_at)) + '</div></div>' +
    '<div class="ak-acts"><button type="button" class="ak-btn good" data-' + kind + '-ok="' + esc(p.id) + '" data-amt="' + esc(f(p.amount)) + '" data-code="' + esc(p.order_code) + '">✔ Confirm</button>' +
    '<button type="button" class="ak-btn danger" data-' + kind + '-no="' + esc(p.id) + '" data-code="' + esc(p.order_code) + '">✖ Reject</button></div></div>';
}
function confirmView() {
  var d = V.d;
  return '<div class="ph-h">📱 Telebirr — check your merchant SMS</div>' + section(d.tbErr, d.telebirr.length ? d.telebirr.map(function (p) { return confirmItem('tb', p); }).join('') : K.empty('✅', 'No Telebirr payments waiting', 'New ones appear when a customer says they paid.')) +
    '<div class="ph-h">🏦 Bank transfers — check your bank statement</div>' + section(d.bkErr, d.bank.length ? d.bank.map(function (p) { return confirmItem('bk', p); }).join('') : K.empty('✅', 'No bank transfers waiting', 'New ones appear when a customer sends a reference.'));
}

function cashView() {
  var d = V.d.unpaid; if (!d) return K.empty('⚠️', 'Could not load delivered orders', V.d.unpaidErr);
  var rows = d.orders || [];
  if (!rows.length) return K.empty('✅', 'Nothing to reconcile', 'Every delivered order has a payment record.');
  return '<div class="ph-box"><h3>Delivered orders not marked paid</h3><div class="sub">Mostly cash the courier collected. Count the money, then mark it received — each change is logged with your name.</div>' +
    '<div class="ak-chips"><span class="ak-chip"><b>' + K.num(d.count || rows.length) + '</b> orders</span><span class="ak-chip"><b>' + esc(etb(d.total)) + '</b> total</span>' +
    (d.by_courier || []).map(function (c) { return '<span class="ak-chip">' + esc(c.courier) + ' (' + K.num(c.orders) + '): <b>' + esc(etb(c.amount)) + '</b></span>'; }).join('') + '</div>' +
    '<div class="ak-acts" style="margin-top:12px"><button type="button" class="ak-btn gold" data-cash-all>Mark all shown as received</button></div></div>' +
    '<div class="ak-tblw"><table class="ak-tbl"><thead><tr><th>Delivered</th><th>Order</th><th>Customer</th><th>Courier</th><th>Method</th><th class="ph-r">Amount</th><th><span class="ph-vh">Action</span></th></tr></thead><tbody>' + rows.map(function (r) {
      return '<tr><td>' + esc(String(r.delivered_at || r.placed_at || '').slice(0, 16)) + '</td><td><b>' + esc(r.order_code) + '</b></td><td>' + esc(r.customer_name || '') + '</td><td>' + esc(r.courier_name || '—') + '</td><td>' + esc(r.payment_method || 'cash') + '</td><td class="ph-r">' + esc(etb(r.fare_total)) + '</td>' +
        '<td><button type="button" class="ak-btn" data-cash-one="' + esc(r.id) + '">Received</button></td></tr>';
    }).join('') + '</tbody></table></div>';
}

function payoutView() {
  var d = V.d;
  if (d.poErr) return K.empty('⚠️', 'Could not load payout changes', d.poErr);
  if (!d.payouts.length) return K.empty('🔐', 'No payout changes waiting', 'A business\'s bank or Telebirr details never change by themselves — every change waits here for you.');
  var diff = function (label, o, n) { return o === n ? '' : '<div class="ph-diff"><span style="color:var(--mute)">' + esc(label) + '</span><span><s>' + esc(o || '—') + '</s> → <b>' + esc(n || '—') + '</b></span></div>'; };
  return d.payouts.map(function (r) {
    return '<div class="ph-box"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:flex-start"><div><div class="t" style="font-weight:800;font-size:14px">' + esc(r.business_name) + '</div>' +
      '<div style="font-size:12px;color:var(--mute)">Requested by ' + esc(r.requested_by_name) + ' · ' + esc(r.requested_by_phone) + ' · ' + esc(K.ago(r.requested_at)) + '</div></div>' +
      '<div class="ak-acts"><button type="button" class="ak-btn good" data-po-ok="' + esc(r.id) + '" data-name="' + esc(r.business_name) + '">✔ Approve</button><button type="button" class="ak-btn danger" data-po-no="' + esc(r.id) + '" data-name="' + esc(r.business_name) + '">✖ Reject</button></div></div>' +
      '<div style="margin-top:10px">' + diff('Bank', BANK[r.old_payout_bank] || r.old_payout_bank, BANK[r.new_payout_bank] || r.new_payout_bank) + diff('Bank account', r.old_bank_account, r.new_bank_account) +
      diff('CBE holder', r.old_cbe_account_name, r.new_cbe_account_name) + diff('CBE account', r.old_cbe_account, r.new_cbe_account) +
      diff('Abysinia holder', r.old_abysinia_account_name, r.new_abysinia_account_name) + diff('Abysinia account', r.old_abysinia_account, r.new_abysinia_account) +
      diff('Telebirr holder', r.old_telebirr_account_name, r.new_telebirr_account_name) + diff('Telebirr number', r.old_telebirr_number, r.new_telebirr_number) + '</div></div>';
  }).join('');
}

function logosView() {
  var logos = V.d.logos;
  if (!logos) return K.skeleton(2);
  return '<div class="ph-box"><h3>Payment method logos</h3><div class="sub">Upload a real logo for each method — every screen showing that method\'s icon switches to it. Methods without a logo keep their coloured badge.</div>' +
    '<div class="ph-logos">' + LOGO_METHODS.map(function (m) {
      var u = safeUrl(logos[m[0]]);
      return '<div class="ph-logo"><div class="ph-lg" style="background:' + (u ? '#fff' : m[2]) + '">' + (u ? '<img src="' + esc(u) + '" alt="' + esc(m[1]) + ' logo">' : esc(m[1].slice(0, 3).toUpperCase())) + '</div>' +
        '<div style="font-size:12.5px;font-weight:700;margin-bottom:8px">' + esc(m[1]) + '</div><input type="file" id="pmlogo-file-' + m[0] + '" accept="image/*" style="display:none" data-logo="' + m[0] + '" aria-label="Choose a logo for ' + esc(m[1]) + '">' +
        '<button type="button" class="ak-btn" style="width:100%" data-logo-pick="' + m[0] + '">📤 Upload</button></div>';
    }).join('') + '</div></div>';
}

function paint() {
  var el = root(); if (!el || !V.loaded) return;
  var d = V.d, c = counts(), r = d.report || {};
  var badge = { online: c.chapa, confirm: c.confirm, cash: c.cash, payout: c.po };
  var segs = '<div class="ak-seg" role="group" aria-label="Section">' + TABS.map(function (x) {
    var n = badge[x[0]] || 0;
    return '<button type="button" data-seg="tab" data-val="' + x[0] + '" aria-pressed="' + (V.tab === x[0]) + '" class="' + (V.tab === x[0] ? 'on' : '') + '">' + esc(x[1]) + (n ? '<b aria-label="' + n + ' waiting">' + n + '</b>' : '') + '</button>';
  }).join('') + '</div>';
  var body = V.tab === 'online' ? online() : V.tab === 'confirm' ? confirmView() : V.tab === 'cash' ? cashView() : V.tab === 'payout' ? payoutView() : V.tab === 'logos' ? logosView() : overview();
  el.innerHTML = '<div class="ak">' + K.hero('Payments', 'Every way money reaches Etel — online, Telebirr, bank transfer and cash — and what is still waiting on you.', [
      ['Confirmed revenue', etb(r.confirmed_total)], ['Awaiting confirmation', etb(r.pending_total)], ['Waiting on you', K.num(c.all + c.cash)]]) +
    '<div class="ph-tabs">' + segs + '<span style="flex:1"></span>' + (V.tab === 'overview' ? K.seg('range', RANGES, V.range) : '') +
    '<button type="button" class="ak-btn" data-act="refresh" aria-label="Refresh">🔄</button></div>' + body + '</div>';
  var sel = document.getElementById('ph-cdays'); if (sel) sel.value = String(V.chapaDays);
}

/* ---------- actions ---------- */
function decide(action, body, done) {
  return K.api(action, body).then(function (res) {
    if (K.okRes(res)) { K.toast(res.message || done); } else K.toast(K.failText(res, 'Could not save the decision.'), true);
    return again();                           /* also refreshes when someone else already decided it */
  });
}
function markPaid(ids) {
  return K.api('admin_mark_order_paid', { order_ids: ids, note: 'Marked received in Payments' }).then(function (res) {
    K.toast(K.okRes(res) ? (res.message || 'Marked as received') : K.failText(res), !K.okRes(res)); return again();
  });
}
function uploadLogo(key) {
  if (typeof uploadImageForAdmin !== 'function') { K.toast('Upload is not available on this page.', true); return; }
  uploadImageForAdmin('pmlogo-file-' + key, null).then(function (url) {
    if (!url) { K.toast('Logo upload failed', true); return; }
    return K.api('admin_set_payment_logo', { method_key: key, logo_url: url }).then(function (res) { K.toast(K.okRes(res) ? (res.message || 'Logo saved') : K.failText(res), !K.okRes(res)); return loadLogos(); });
  }).catch(function () { K.toast('Logo upload failed', true); });
}

function wire(el) {
  if (el._ph) return; el._ph = true;
  el.addEventListener('click', function (e) {
    var t = e.target, b, id;
    if ((b = t.closest('[data-seg]'))) {
      var n = b.getAttribute('data-seg'), v = b.getAttribute('data-val');
      if (n === 'tab') { V.tab = v; if (v === 'logos' && !V.d.logos) loadLogos(); paint(); }
      else if (n === 'range') { V.range = v; again(); }
      return;
    }
    if ((b = t.closest('[data-goto]'))) { V.tab = b.getAttribute('data-goto'); paint(); return; }
    if (t.closest('[data-act="refresh"]')) { again(); if (V.tab === 'logos') loadLogos(); return; }
    if ((b = t.closest('[data-tb-ok],[data-bk-ok]'))) {
      var tb = b.hasAttribute('data-tb-ok'); id = parseInt(b.getAttribute(tb ? 'data-tb-ok' : 'data-bk-ok'), 10);
      K.confirm({ title: 'Confirm this payment?', text: 'Only confirm if ' + etb(b.getAttribute('data-amt')) + ' for order ' + b.getAttribute('data-code') + ' really reached your ' + (tb ? 'Telebirr merchant account' : 'bank account') + '. The order is then marked paid.', label: 'Confirm payment' })
        .then(function (r) { if (r) decide(tb ? 'admin_confirm_telebirr_payment' : 'admin_confirm_bank_payment', { payment_id: id, decision: 'confirm' }, 'Payment confirmed'); });
      return;
    }
    if ((b = t.closest('[data-tb-no],[data-bk-no]'))) {
      var tb2 = b.hasAttribute('data-tb-no'); id = parseInt(b.getAttribute(tb2 ? 'data-tb-no' : 'data-bk-no'), 10);
      K.confirm({ title: 'Reject this payment?', text: 'Order ' + b.getAttribute('data-code') + ' stays unpaid.', label: 'Reject', danger: true })
        .then(function (r) { if (r) decide(tb2 ? 'admin_confirm_telebirr_payment' : 'admin_confirm_bank_payment', { payment_id: id, decision: 'reject' }, 'Payment rejected'); });
      return;
    }
    if ((b = t.closest('[data-po-ok]'))) {
      id = parseInt(b.getAttribute('data-po-ok'), 10);
      K.confirm({ title: 'Approve the new payout details?', text: b.getAttribute('data-name') + ' will be paid to the new account from now on. Check the changes with the owner if unsure.', label: 'Approve' })
        .then(function (r) { if (r) decide('admin_review_payout_request', { request_id: id, decision: 'approve' }, 'Payout details approved'); });
      return;
    }
    if ((b = t.closest('[data-po-no]'))) {
      id = parseInt(b.getAttribute('data-po-no'), 10);
      K.confirm({ title: 'Reject this payout change?', text: b.getAttribute('data-name') + ' keeps its current payout details.', label: 'Reject', danger: true })
        .then(function (r) { if (r) decide('admin_review_payout_request', { request_id: id, decision: 'reject' }, 'Payout change rejected'); });
      return;
    }
    if ((b = t.closest('[data-cash-one]'))) { markPaid([parseInt(b.getAttribute('data-cash-one'), 10)]); return; }
    if (t.closest('[data-cash-all]')) {
      var rows = (V.d.unpaid && V.d.unpaid.orders || []).slice(0, 50);
      K.confirm({ title: 'Mark ' + rows.length + ' order' + (rows.length === 1 ? '' : 's') + ' as received?', text: 'Only do this after you have counted the money. Each order is logged with your name.', label: 'Mark as received' })
        .then(function (r) { if (r) markPaid(rows.map(function (x) { return x.id; })); });
      return;
    }
    if ((b = t.closest('[data-logo-pick]'))) { var inp = document.getElementById('pmlogo-file-' + b.getAttribute('data-logo-pick')); if (inp) inp.click(); }
  });
  el.addEventListener('change', function (e) {
    if (e.target.id === 'ph-cdays') { V.chapaDays = parseInt(e.target.value, 10) || 14; again(); return; }
    if (e.target.getAttribute && e.target.getAttribute('data-logo')) uploadLogo(e.target.getAttribute('data-logo'));
  });
}

function install() {
  var el = root(); if (!el) return;
  wire(el);
  /* admin-ui.js calls these by name when the page opens or its Refresh button is pressed: one hub refresh covers them all. */
  window.loadTelebirrPayments = function () { return load(V.loaded); };
  ['loadBankPayments', 'loadPaymentsReport', 'loadPayoutRequests', 'loadPaymentLogosEditor', 'loadChapaPayments', 'loadCashReconcile'].forEach(function (n) { window[n] = function () {}; });
  window.updatePaymentsBadge = function () { return load(true); };
  load(false);                                /* fills the nav badge before the page is ever opened */
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
