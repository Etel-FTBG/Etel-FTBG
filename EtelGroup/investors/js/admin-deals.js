/* Investor Relations admin - Deal Sheets (review, accept/reject, enroll as investor, print).
   Uses irApi/irEsc/irMoney/irCloseModal from admin.js and EtelDeal from deal-sheet.js. */
(function () {
'use strict';
var D = window.EtelDeal, CFG = { pool_units: 2000, pool_percent: 20, boost: 2.5 }, SHEETS = [], CUR = null;
var STATUS = { submitted: ['New', 'new'], accepted: ['Accepted', 'warn'], rejected: ['Rejected', 'read'], enrolled: ['Enrolled', 'replied'] };
function $(id) { return document.getElementById(id); }
function base() { return new URL('./', location.href).href; }

window.irLoadDeals = async function () {
  var res = await irApi('admin_deal_list');
  var tb = document.querySelector('#ir-deals-table tbody');
  if (!tb) return;
  if (!res.ok) { tb.innerHTML = '<tr><td colspan="8" style="color:var(--bad)">' + irEsc(res.error || 'Could not load deal sheets') + '</td></tr>'; return; }
  SHEETS = res.data.sheets || []; CFG = res.data.config || CFG;
  var L = (CFG.limits || {}), C = L.collateral || {};
  if ($('lim-min') && document.activeElement && !document.activeElement.closest('.ir-limits')) {
    $('lim-min').value = L.min_units != null ? L.min_units : 10; $('lim-max').value = L.max_units != null ? L.max_units : 100; $('lim-lock').value = L.lock_months != null ? L.lock_months : 12;
    $('lim-cpct').value = C.pct != null ? C.pct : 30; $('lim-cmon').value = C.months != null ? C.months : 24; $('lim-con').checked = !!C.on; $('lim-cval').value = C.value || ''; $('lim-cuntil').checked = !!C.until_repaid;
  }
  var en = base() + 'deal.html', am = base() + 'deal.html?lang=am';
  $('deal-links').innerHTML = 'Share with investors: <a href="' + irEsc(en) + '" target="_blank" rel="noopener" style="color:var(--gold2)">English link</a> &middot; <a href="' + irEsc(am) + '" target="_blank" rel="noopener" style="color:var(--gold2)">&#x12A0;&#x121B;&#x122D;&#x129B; link</a> &middot; ' +
    '<button class="btn btn-outline btn-sm" onclick="irCopyDealLink(\'' + irEsc(en) + '\')">Copy English</button> <button class="btn btn-outline btn-sm" onclick="irCopyDealLink(\'' + irEsc(am) + '\')">Copy Amharic</button> &middot; ' +
    'Blank forms: <a href="forms/ETEL_Investor_Deal_Sheet_EN.pdf" target="_blank" style="color:var(--gold2)">EN PDF</a> &middot; <a href="forms/ETEL_Investor_Deal_Sheet_AM.pdf" target="_blank" style="color:var(--gold2)">AM PDF</a> &middot; ' +
    '<span style="color:var(--text-dim)">' + irEsc(CFG.available) + ' Profit Shares open</span>';
  tb.innerHTML = SHEETS.length ? SHEETS.map(function (r) {
    var st = STATUS[r.status] || [r.status, 'read'];
    return '<tr data-status="' + irEsc(r.status) + '"><td><code>' + irEsc(r.ref_code) + '</code></td><td>' + irEsc(String(r.created_at).slice(0, 16)) + '</td><td>' + irEsc(r.investor_name) + '<div style="font-size:11px;color:var(--text-dim)">' + irEsc(r.phone) + '</div></td>' +
      '<td class="ir-num">' + irEsc(r.units) + '</td><td class="ir-num">' + irMoney(r.amount) + '</td><td>' + (r.lang === 'am' ? 'AM' : 'EN') + '</td><td><span class="pill ' + st[1] + '">' + irEsc(st[0]) + '</span></td>' +
      '<td><button class="btn btn-outline btn-sm" onclick="irOpenDeal(' + Number(r.id) + ')">Open</button></td></tr>';
  }).join('') : '<tr><td colspan="8" style="color:var(--text-dim)">No deal sheets yet. Share the link above with an investor.</td></tr>';
  var fresh = SHEETS.filter(function (r) { return r.status === 'submitted'; }).length;
  window.IR_DEALS_NEW = fresh;
  var bd = $('nav-deals-badge'); if (bd) bd.textContent = fresh ? String(fresh) : '';
  window.irLimitsCover();
  if (typeof window.irApplyDealFilter === 'function') window.irApplyDealFilter();
  document.dispatchEvent(new CustomEvent('ir:deals'));
};

// Shows whether the registered assets cover the money raised in the current stage at the chosen percentage.
window.irLimitsCover = function () {
  var el = $('lim-cover'); if (!el) return;
  var stages = CFG.stages || [], cur = CFG.stage || (stages[0] && stages[0].stage) || 1;
  var raise = stages.filter(function (x) { return x.stage <= cur; }).reduce(function (a, x) { return a + x.raise; }, 0);   // everything raised or being raised up to the current stage
  var val = +$('lim-cval').value || 0, pct = +$('lim-cpct').value || 0;
  if (!raise) { el.textContent = ''; return; }
  var need = raise * pct / 100, label = cur > 1 ? 'Stages 1-' + cur : 'Stage 1';
  if (!val) { el.style.color = ''; el.textContent = label + ' raise: ETB ' + Number(raise).toLocaleString() + '. Enter the value of the registered assets (after any bank loan on them) to see if they cover ' + pct + '% of it.'; return; }
  var ok = val >= need;
  el.style.color = ok ? 'var(--ok, #2fb36b)' : 'var(--bad)';
  el.textContent = (ok ? 'Covered: ' : 'NOT covered: ') + 'assets ETB ' + val.toLocaleString() + ' vs ' + pct + '% of the ' + label + ' raise (ETB ' + Number(need).toLocaleString() + '). ' + (ok ? 'Forced sales often fetch less than value, so keep a margin.' : 'Add assets or lower the percentage before switching security on.');
};
document.addEventListener('input', function (e) { if (e.target && /^lim-(cval|cpct)$/.test(e.target.id)) window.irLimitsCover(); });

window.irSaveLimits = async function () {
  var m = $('lim-msg'); m.style.color = ''; m.textContent = 'Saving...';
  var res = await irApi('admin_deal_limits_set', { min_units: +$('lim-min').value, max_units: +$('lim-max').value, lock_months: +$('lim-lock').value,
    collateral_pct: +$('lim-cpct').value, collateral_months: +$('lim-cmon').value, collateral_on: $('lim-con').checked ? 1 : 0,
    collateral_value: +$('lim-cval').value || 0, collateral_until_repaid: $('lim-cuntil').checked ? 1 : 0 });
  if (!res.ok) { m.style.color = 'var(--bad)'; m.textContent = res.error || 'Could not save'; return; }
  m.style.color = 'var(--ok, #2fb36b)'; m.textContent = 'Saved. The deal sheet and pitch now show these numbers.';
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
