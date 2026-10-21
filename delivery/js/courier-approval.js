/* ETEL Courier app - "waiting for approval" screen.
   A courier who signed up on their own cannot go online or take jobs until an admin approves them. The server refuses their
   job actions; this file explains why instead of showing an app that does nothing. Checks courier_get_profile (always
   allowed), shows a full-screen card while the account is pending or was not approved, and reloads once approved. */
(function () {
'use strict';
var TIMER = null;
function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
function role() { try { var u = Auth.getUser(); return u && u.role; } catch (e) { return null; } }
function profile() {
  return fetch(ETEL.api + '?action=courier_get_profile', { headers: { 'Authorization': 'Bearer ' + Auth.getToken() } })
    .then(function (r) { return r.json(); }).catch(function () { return null; });
}
function card(status, note, code) {
  var rejected = status === 'rejected';
  var box = document.getElementById('cap-box');
  if (!box) {
    box = document.createElement('div'); box.id = 'cap-box';
    box.setAttribute('role', 'alertdialog'); box.setAttribute('aria-modal', 'true'); box.setAttribute('aria-labelledby', 'cap-title');
    box.style.cssText = 'position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:20px;background:linear-gradient(160deg,#121021,#2b2147 60%,#5a4510);color:#f4efe0;font-family:system-ui,sans-serif';
    document.body.appendChild(box);
  }
  box.innerHTML = '<div style="max-width:380px;width:100%;text-align:center">' +
    '<div style="font-size:54px;margin-bottom:8px">' + (rejected ? '✖️' : '⏳') + '</div>' +
    '<h2 id="cap-title" style="margin:0 0 8px;font-size:22px;color:#F2CC6B">' + (rejected ? 'Application not approved' : 'Waiting for approval') + '</h2>' +
    '<p style="margin:0 0 14px;line-height:1.5;opacity:.88">' + (rejected
      ? 'Your courier application was not approved.' + (note ? ' Reason: ' + esc(note) : '') + ' Please contact ETEL support if you think this is a mistake.'
      : 'Thank you for signing up. An ETEL admin is checking your details. You can start working as soon as you are approved. We check again automatically.') + '</p>' +
    (code ? '<p style="margin:0 0 16px;font-size:12px;opacity:.7">Your courier ID: <b>' + esc(code) + '</b></p>' : '') +
    '<button type="button" id="cap-again" style="width:100%;padding:13px;border:0;border-radius:12px;background:linear-gradient(135deg,#D4AF37,#8B7230);color:#1c1500;font-weight:700;font-size:15px;cursor:pointer">Check again</button>' +
    '<button type="button" id="cap-out" style="width:100%;margin-top:10px;padding:12px;border:1px solid rgba(255,255,255,.3);border-radius:12px;background:transparent;color:inherit;font-size:14px;cursor:pointer">Sign out</button></div>';
  document.getElementById('cap-again').addEventListener('click', check);
  document.getElementById('cap-out').addEventListener('click', function () { Auth.logout(); });
}
function check() {
  profile().then(function (res) {
    var d = res && res.success && res.data;
    if (!d) return;
    var st = d.approval_status || 'approved';
    var box = document.getElementById('cap-box');
    if (st === 'approved') { if (box) { location.reload(); } return; }
    card(st, d.approval_note, d.courier_code);
    if (st === 'pending' && !TIMER) TIMER = setInterval(check, 45000);
    if (st !== 'pending' && TIMER) { clearInterval(TIMER); TIMER = null; }
  });
}
function start() { if (typeof Auth === 'undefined' || !Auth.isLoggedIn() || role() !== 'courier') return; check(); }
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
