/* Investor Relations admin - Deal Sheets (review, accept/reject, enroll as investor, print).
   Uses irApi/irEsc/irMoney/irCloseModal from admin.js and EtelDeal from deal-sheet.js. */
(function () {
'use strict';
var D = window.EtelDeal, CFG = { pool_units: 2000, pool_percent: 20, boost: 2.5 }, SHEETS = [], CUR = null;
var STATUS = { submitted: ['New', '#e5c76b'], accepted: ['Accepted', '#7ee8a8'], rejected: ['Rejected', '#ff8a7a'], enrolled: ['Enrolled', '#7fb8ff'] };
function $(id) { return document.getElementById(id); }
function base() { return new URL('./', location.href).href; }

window.irLoadDeals = async function () {
  var res = await irApi('admin_deal_list');
  var tb = document.querySelector('#ir-deals-table tbody');
  if (!tb) return;
  if (!res.ok) { tb.innerHTML = '<tr><td colspan="8" style="color:#ff8a7a">' + irEsc(res.error || 'Could not load deal sheets') + '</td></tr>'; return; }
  SHEETS = res.data.sheets || []; CFG = res.data.config || CFG;
  var en = base() + 'deal.html', am = base() + 'deal.html?lang=am';
  $('deal-links').innerHTML = 'Share with investors: <a href="' + irEsc(en) + '" target="_blank" rel="noopener" style="color:#ffcf80">English link</a> &middot; <a href="' + irEsc(am) + '" target="_blank" rel="noopener" style="color:#ffcf80">&#x12A0;&#x121B;&#x122D;&#x129B; link</a> &middot; ' +
    '<button class="btn btn-outline" style="padding:2px 10px" onclick="irCopyDealLink(\'' + irEsc(en) + '\')">Copy English</button> <button class="btn btn-outline" style="padding:2px 10px" onclick="irCopyDealLink(\'' + irEsc(am) + '\')">Copy Amharic</button> &middot; ' +
    'Blank forms: <a href="forms/ETEL_Investor_Deal_Sheet_EN.pdf" target="_blank" style="color:#ffcf80">EN PDF</a> &middot; <a href="forms/ETEL_Investor_Deal_Sheet_AM.pdf" target="_blank" style="color:#ffcf80">AM PDF</a> &middot; ' +
    '<span style="color:var(--text-dim)">' + irEsc(CFG.available) + ' Profit Shares open</span>';
  tb.innerHTML = SHEETS.length ? SHEETS.map(function (r) {
    var st = STATUS[r.status] || [r.status, '#ccc'];
    return '<tr><td><code>' + irEsc(r.ref_code) + '</code></td><td>' + irEsc(String(r.created_at).slice(0, 16)) + '</td><td>' + irEsc(r.investor_name) + '<div style="font-size:11px;color:var(--text-dim)">' + irEsc(r.phone) + '</div></td>' +
      '<td class="ir-num">' + irEsc(r.units) + '</td><td class="ir-num">' + irMoney(r.amount) + '</td><td>' + (r.lang === 'am' ? 'AM' : 'EN') + '</td><td style="color:' + st[1] + '">' + irEsc(st[0]) + '</td>' +
      '<td><button class="btn btn-outline" onclick="irOpenDeal(' + Number(r.id) + ')">Open</button></td></tr>';
  }).join('') : '<tr><td colspan="8" style="color:var(--text-dim)">No deal sheets yet. Share the link above with an investor.</td></tr>';
};

window.irCopyDealLink = function (u) {
  if (navigator.clipboard) navigator.clipboard.writeText(u).then(function () { alert('Link copied'); }, function () { prompt('Copy this link', u); });
  else prompt('Copy this link', u);
};

window.irOpenDeal = async function (id) {
  $('deal-modal-err').textContent = '';
  var res = await irApi('admin_deal_get', { id: id });
  if (!res.ok) { alert(res.error || 'Could not open the sheet'); return; }
  CUR = res.data;
  $('deal-modal-title').textContent = 'Deal Sheet ' + CUR.ref_code;
  $('deal-modal-body').innerHTML = D.renderSheet(CUR, CUR.lang, CFG) + (CUR.review_note ? '<p style="margin-top:8px;font-size:12px;color:var(--text-dim)">Note by ' + irEsc(CUR.reviewed_by) + ': ' + irEsc(CUR.review_note) + '</p>' : '');
  var a = '<button class="btn btn-outline" onclick="irPrintDeal()">Print / save PDF</button> ';
  if (CUR.status === 'submitted' || CUR.status === 'rejected') {
    a += '<button class="btn btn-primary" onclick="irReviewDeal(\'accepted\')">Accept</button> ';
  }
  if (CUR.status === 'submitted') {
    a += '<button class="btn btn-danger" onclick="irReviewDeal(\'rejected\')">Reject</button> ';
  }
  if (CUR.status === 'submitted' || CUR.status === 'rejected') {
    a += '<div class="cf-field" style="margin-top:10px"><label>Note (required to reject)</label><input type="text" id="deal-note" maxlength="500"></div>';
  }
  if (CUR.status === 'accepted') {
    a += '<div style="border-top:1px solid var(--line);padding-top:12px;margin-top:6px"><b>Enroll as investor</b>' +
      '<div class="cf-field"><label>Portal login (email or phone)</label><input type="text" id="deal-login" value="' + irEsc(CUR.email || CUR.phone) + '"></div>' +
      '<label style="display:flex;gap:8px;align-items:center;margin:8px 0"><input type="checkbox" id="deal-paid"> The money has been received: record the purchase of ' + irEsc(CUR.units) + ' Profit Shares (ETB ' + irMoney(CUR.amount) + ')</label>' +
      '<div style="display:flex;gap:10px;flex-wrap:wrap"><div class="cf-field"><label>Tranche</label><select id="deal-tranche"><option value="1">1</option><option value="2">2</option></select></div>' +
      '<div class="cf-field"><label>Payment date</label><input type="date" id="deal-date" value="' + new Date().toISOString().slice(0, 10) + '"></div></div>' +
      '<button class="btn btn-primary" onclick="irEnrollDeal()">Create investor</button></div>';
  }
  if (CUR.status === 'enrolled') a += '<span style="color:#7fb8ff">Enrolled as investor #' + irEsc(CUR.investor_id) + '.</span>';
  $('deal-actions').innerHTML = a;
  $('deal-modal-bg').classList.add('open');
};

window.irPrintDeal = function () { if (CUR) D.printSheet(D.renderSheet(CUR, CUR.lang, CFG), CUR.lang); };

window.irReviewDeal = async function (decision) {
  var note = ($('deal-note') || {}).value || '';
  var res = await irApi('admin_deal_review', { id: CUR.id, decision: decision, note: note });
  if (!res.ok) { $('deal-modal-err').textContent = res.error || 'Could not save'; return; }
  await window.irLoadDeals(); window.irOpenDeal(CUR.id);
};

window.irEnrollDeal = async function () {
  var paid = $('deal-paid').checked;
  var res = await irApi('admin_deal_enroll', { id: CUR.id, login_identifier: $('deal-login').value.trim(), record_payment: paid, tranche_number: Number($('deal-tranche').value), purchase_date: $('deal-date').value });
  if (!res.ok) { $('deal-modal-err').textContent = res.error || 'Could not enroll'; return; }
  alert('Investor created.\n\nLogin: ' + res.data.login_identifier + '\nTemporary password: ' + res.data.temp_password + '\n\nThis password is shown only once. Give it to the investor and ask them to keep it safe.' + (res.data.purchase_recorded ? '' : '\n\nNo purchase was recorded yet: add it from Investors once the money is received.'));
  await window.irLoadDeals();
  if (typeof irLoadDashboard === 'function') irLoadDashboard();
  window.irOpenDeal(CUR.id);
};
})();
