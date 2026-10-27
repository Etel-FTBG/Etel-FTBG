/* ETEL - the owner of a pre-registered business takes it over (claim.html).
   Data: sg_claim_info and sg_claim_complete (api/controllers/SgClaimController.php). No inline handlers; every server value is set with textContent. */
(function () {
'use strict';
var API = 'api/boot.php';
function $(id) { return document.getElementById(id); }
function say(id, text, kind) { var m = $(id); m.textContent = text || ''; m.className = 'msg' + (kind ? ' ' + kind : ''); }
function post(action, body) {
  return fetch(API + '?action=' + action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ action: action }, body)) })
    .then(function (r) { return r.json().catch(function () { return {}; }); })
    .catch(function () { return { success: false, message: 'No connection. Check your internet and try again.' }; });
}
var code = '';

function checkCode() {
  var v = $('code').value.trim();
  if (!v) { say('msg-code', 'Enter your claim code.', 'err'); return; }
  $('go-code').disabled = true; say('msg-code', 'Checking…');
  post('sg_claim_info', { code: v }).then(function (res) {
    $('go-code').disabled = false;
    if (!res.success) { say('msg-code', res.message || 'This code is not valid.', 'err'); return; }
    var d = res.data || {}; code = v;
    $('biz-name').textContent = d.business_name || 'Your business';
    $('biz-meta').textContent = [d.service_type, d.zone].filter(Boolean).join(' · ');
    $('owner').value = d.owner_name && d.owner_name !== d.business_name ? d.owner_name : '';
    $('step-code').hidden = true; $('step-set').hidden = false; $('owner').focus();
  });
}

function save() {
  var phone = $('phone').value.trim(), pw = $('pw').value;
  if (phone.replace(/[^0-9]/g, '').length < 9) { say('msg-set', 'Enter your full phone number.', 'err'); return; }
  if (pw.length < 8) { say('msg-set', 'The password needs at least 8 characters.', 'err'); return; }
  if (pw !== $('pw2').value) { say('msg-set', 'The two passwords are not the same.', 'err'); return; }
  $('go-set').disabled = true; say('msg-set', 'Saving…');
  post('sg_claim_complete', { code: code, name: $('owner').value.trim(), phone: phone, password: pw }).then(function (res) {
    $('go-set').disabled = false;
    if (!res.success) { say('msg-set', res.message || 'Could not save. Try again.', 'err'); return; }
    $('done-text').textContent = (res.data && res.data.message) || 'Your details are saved.';
    $('step-set').hidden = true; $('step-done').hidden = false;
  });
}

$('go-code').addEventListener('click', checkCode);
$('code').addEventListener('keydown', function (e) { if (e.key === 'Enter') checkCode(); });
$('go-set').addEventListener('click', save);
var q = new URLSearchParams(location.search).get('code');
if (q) { $('code').value = q; checkCode(); }
})();
