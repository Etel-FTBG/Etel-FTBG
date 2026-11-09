/* Investor Deal Sheet - the investor fills in, signs (drawn + typed name) and submits.  Public page: no login. */
(function () {
'use strict';
var D = window.EtelDeal, $ = function (id) { return document.getElementById(id); };
var API = 'api/boot.php', cfg = { unit_price: 1000, pool_units: 2000, pool_percent: 20, boost: 2.5, available: 0 };
var lang = 'en', form = $('dl-form'), last = null;
try { lang = (new URLSearchParams(location.search).get('lang') || localStorage.getItem('etel_deal_lang') || 'en') === 'am' ? 'am' : 'en'; } catch (e) { /* keep en */ }

function api(action, body) {
  return fetch(API + '?action=' + encodeURIComponent(action), { method: body ? 'POST' : 'GET', headers: { 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined })
    .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected server response' }; }); })
    .catch(function () { return { ok: false, error: 'Network error - check your connection' }; });
}

function setText(id, v) { var e = $(id); if (e) e.textContent = v; }
function paint() {
  var t = D.T(lang);
  document.documentElement.lang = lang;
  document.body.classList.toggle('dl-am', lang === 'am');
  setText('t-company', t.company); setText('t-title', t.title); setText('t-sub', t.sub); setText('t-h2', t.h2); setText('t-h5', t.h5); setText('t-agree', t.agree);
  [['l-name', 'f_name'], ['l-id', 'f_id'], ['l-phone', 'f_phone'], ['l-email', 'f_email'], ['l-units', 'f_units'], ['l-amt', 'f_amt'], ['l-pct', 'f_pct'], ['l-close', 'f_close'], ['l-payout', 'f_payout'],
   ['l-days', 'f_days'], ['l-bank', 'f_bank'], ['l-signame', 'sig_name'], ['t-draw', 'sig_draw'], ['dl-clear', 'sig_clear'], ['r-monthly', 'monthly'], ['r-quarterly', 'quarterly'], ['r-other', 'other']].forEach(function (p) { setText(p[0], t[p[1]]); });
  setText('dl-submit', t.submit);
  var b = $('dl-blank'); b.textContent = t.blank; b.href = 'forms/ETEL_Investor_Deal_Sheet_' + (lang === 'am' ? 'AM' : 'EN') + '.pdf';
  Array.prototype.forEach.call(document.querySelectorAll('.dl-lang button'), function (x) { x.classList.toggle('on', x.getAttribute('data-lang') === lang); });
  $('dl-how').innerHTML =
    '<h2>' + D.esc(t.h1) + '</h2><div class="dl-flow">' +
    '<div class="dl-b"><b>' + D.esc(t.pe) + '</b><span>' + D.esc(t.pe_s) + '</span></div><i>&minus;</i><div class="dl-b"><b>' + D.esc(t.rc) + '</b><span>' + D.esc(t.rc_s) + '</span></div><i>=</i>' +
    '<div class="dl-b dl-k"><b>' + D.esc(t.np) + '</b><span>' + D.esc(t.np_s) + '</span></div><i>&rarr;</i><div class="dl-b dl-k"><b>' + D.esc(t.ip) + '</b><span>' + D.esc(t.ip_s) + '</span></div></div>' +
    '<div class="dl-stats"><div><big>' + D.num(cfg.pool_units) + '</big><b>' + D.esc(t.s1b) + '</b><span>' + D.esc(t.s1s) + '</span></div><div><big>' + cfg.boost + '&times;</big><b>' + D.esc(t.s2b) + '</b><span>' + D.esc(t.s2s) + '</span></div><div><big>1&times;</big><b>' + D.esc(t.s3b) + '</b><span>' + D.esc(t.s3s) + '</span></div></div>' +
    '<h3>' + D.esc(t.h3) + '</h3><div class="dl-scroll"><table class="dl-ex"><tr><th>' + D.esc(t.ex_h) + '</th><th>' + D.esc(t.std) + '</th><th>' + D.esc(t.boost) + '</th></tr>' +
    [100, 400, 2000].map(function (u, i) { var p = D.payout(u, 100000, cfg); return '<tr><td>' + D.esc(t['r' + (i + 1)]) + '</td><td>ETB ' + D.num(p.std) + '</td><td>ETB ' + D.num(p.boost) + '</td></tr>'; }).join('') + '</table></div>' +
    '<h3>' + D.esc(t.h4) + '</h3><div class="dl-pts"><div><b>' + D.esc(t.g1b) + '</b><span>' + D.esc(t.g1s) + '</span></div><div><b>' + D.esc(t.g2b) + '</b><span>' + D.esc(t.g2s) + '</span></div><div><b>' + D.esc(t.g3b) + '</b><span>' + D.esc(t.g3s) + '</span></div></div>';
  openLine(); calc();
}
function openLine() {
  var t = D.T(lang), e = $('dl-open');
  e.innerHTML = cfg.available > 0 ? '<b>' + D.num(cfg.available) + '</b> ' + D.esc(t.open_now) : D.esc(t.closed);
  $('dl-submit').disabled = cfg.available <= 0;
  form.units.max = cfg.available;
}
function calc() {
  var t = D.T(lang), u = parseInt(form.units.value, 10) || 0;
  $('o-amt').textContent = 'ETB ' + D.num(u * cfg.unit_price);
  $('o-pct').textContent = (u / cfg.pool_units * 100).toFixed(2) + ' %';
  var c = $('dl-calc');
  if (u > 0) { var p = D.payout(u, 100000, cfg); c.innerHTML = '<b>' + D.esc(t.calc_h) + '</b><span>' + D.esc(t.calc_p) + ': <b>ETB ' + D.num(p.std) + '</b> (' + D.esc(t.calc_std) + ') &middot; <b>ETB ' + D.num(p.boost) + '</b> (' + D.esc(t.calc_boost) + ')</span>'; c.hidden = false; } else { c.hidden = true; }
}

/* ---- signature pad (mouse, touch and pen) ---- */
var cv = $('dl-canvas'), ctx = cv.getContext('2d'), drawing = false, inked = false;
function resetPad() { ctx.clearRect(0, 0, cv.width, cv.height); ctx.lineWidth = 4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0a1d4d'; inked = false; $('t-draw').style.display = ''; }
function pos(e) { var r = cv.getBoundingClientRect(); return { x: (e.clientX - r.left) * cv.width / r.width, y: (e.clientY - r.top) * cv.height / r.height }; }
cv.addEventListener('pointerdown', function (e) { drawing = true; inked = true; $('t-draw').style.display = 'none'; var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(p.x + .1, p.y + .1); ctx.stroke(); try { cv.setPointerCapture(e.pointerId); } catch (x) { /* old webview */ } e.preventDefault(); });
cv.addEventListener('pointermove', function (e) { if (!drawing) return; var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); });
['pointerup', 'pointercancel', 'pointerleave'].forEach(function (n) { cv.addEventListener(n, function () { drawing = false; }); });
$('dl-clear').addEventListener('click', resetPad);

/* ---- submit ---- */
form.addEventListener('input', function (e) { if (e.target === form.units) calc(); });
Array.prototype.forEach.call(document.querySelectorAll('.dl-lang button'), function (b) {
  b.addEventListener('click', function () { lang = b.getAttribute('data-lang'); try { localStorage.setItem('etel_deal_lang', lang); } catch (e) { /* not saved */ } paint(); });
});
form.addEventListener('submit', function (e) {
  e.preventDefault();
  var t = D.T(lang), err = $('dl-err'), f = form.elements;
  err.textContent = '';
  if (!inked) { err.textContent = t.sig_draw; return; }
  var btn = $('dl-submit'); btn.disabled = true; btn.textContent = t.sending;
  api('deal_submit', {
    lang: lang, investor_name: f.investor_name.value, id_number: f.id_number.value, phone: f.phone.value, email: f.email.value, units: parseInt(f.units.value, 10) || 0,
    payout_period: (form.querySelector('input[name=payout_period]:checked') || {}).value || 'monthly', payout_days: parseInt(f.payout_days.value, 10) || 0, closing_date: f.closing_date.value,
    bank_account: f.bank_account.value, signature_name: f.signature_name.value, signature_png: cv.toDataURL('image/png'), agree: f.agree.checked, website: f.website.value
  }).then(function (res) {
    btn.disabled = false; btn.textContent = t.submit;
    if (!res.ok) { err.textContent = res.error || res.message || 'Could not submit.'; return; }
    last = { lang: lang, ref_code: res.data.ref_code, signed_at: res.data.signed_at, doc_hash: res.data.doc_hash, investor_name: f.investor_name.value, id_number: f.id_number.value, phone: f.phone.value, email: f.email.value,
      units: res.data.units, amount: res.data.amount, pool_share_pct: res.data.pool_share_pct, payout_period: (form.querySelector('input[name=payout_period]:checked') || {}).value || 'monthly',
      payout_days: parseInt(f.payout_days.value, 10) || 0, closing_date: f.closing_date.value, bank_account: f.bank_account.value, signature_name: f.signature_name.value, signature_png: cv.toDataURL('image/png') };
    showDone();
  });
});
function showDone() {
  var t = D.T(lang), box = $('dl-done');
  $('dl-form').hidden = true; $('dl-how').hidden = true; box.hidden = false;
  box.innerHTML = '<div class="dl-ok"><h2>' + D.esc(t.ok_h) + '</h2><p>' + D.esc(t.ok_p) + '</p><div class="dl-refbig">' + D.esc(t.ref) + ': <b>' + D.esc(last.ref_code) + '</b></div>' +
    '<div class="dl-actions"><button type="button" class="dl-btn" id="dl-print">' + D.esc(t.print) + '</button><button type="button" class="dl-link" id="dl-again">' + D.esc(t.another) + '</button></div></div>' + D.renderSheet(last, lang, cfg);
  $('dl-print').addEventListener('click', function () { D.printSheet(D.renderSheet(last, lang, cfg), lang); });
  $('dl-again').addEventListener('click', function () { location.reload(); });
  window.scrollTo(0, 0);
}

resetPad(); paint();
api('deal_config').then(function (res) { if (res.ok && res.data) { cfg = res.data; paint(); } });
})();
