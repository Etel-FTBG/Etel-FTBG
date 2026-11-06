/* ETEL Ride - agent referral capture (rider side).
   When a rider opens the Ride page from an agent's QR / link (?ref=CODE) this remembers the code on the phone, then tells the server
   once the rider is signed in (action fa_claim_ref). It is silent (no screen) and uses ONLY its own storage key, so the existing
   customer "Referral & Rewards" flow is not touched. The server decides everything: a rider who is the agent, who has already
   ridden, or who uses a wrong or expired code is simply not counted. The code is forgotten after the answer, or after 30 days. */
(function () {
'use strict';
var KEY = 'etel_fa_ref', DEV = 'etel_dev', TOK = 'etel_token', DAYS = 30, tries = 0;
function get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
function set(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* private window: skip */ } }
function del(k) { try { localStorage.removeItem(k); } catch (e) { /* skip */ } }
var src = document.currentScript && document.currentScript.src;
var API = src ? new URL('../api/boot.php', src).href : '/delivery/api/boot.php';
function device() {
  var d = get(DEV);
  if (!d) { var a = new Uint8Array(16); try { crypto.getRandomValues(a); } catch (e) { for (var i = 0; i < 16; i++) a[i] = Math.floor(Math.random() * 256); } d = ''; for (var j = 0; j < 16; j++) d += ('0' + a[j].toString(16)).slice(-2); set(DEV, d); }
  return d;
}
try {
  var q = new URLSearchParams(location.search), c = (q.get('ref') || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
  if (c.length >= 5 && c.length <= 12) set(KEY, JSON.stringify({ c: c, t: Date.now() }));
} catch (e) { /* old browser: nothing to remember */ }
function claim() {
  var raw = get(KEY); if (!raw || tries >= 3) return;
  var o; try { o = JSON.parse(raw); } catch (e) { del(KEY); return; }
  if (!o || !o.c || Date.now() - o.t > DAYS * 864e5) { del(KEY); return; }
  var tok = get(TOK); if (!tok) return;                                  // not signed in yet: try again on the next page load
  tries++;
  fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + tok }, body: JSON.stringify({ action: 'fa_claim_ref', code: o.c, device: device() }) })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      if (!j || !j.success || !j.data) return;                           // 401 / 429 / server trouble: keep the code, retry later
      if (j.data.applied === true || ['ALREADY_CLAIMED', 'BAD_CODE', 'NOT_NEW'].indexOf(j.data.reason) !== -1) del(KEY);
    })
    .catch(function () { /* offline: keep the code */ });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', claim); else claim();
window.addEventListener('storage', function (e) { if (e.key === TOK && e.newValue) claim(); });
})();
