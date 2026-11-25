/* ETEL - on the sign-in pages: one line that says why the person was signed out (additive script).
   js/etel-idle-logout.js leaves a note in sessionStorage (etel_logout_reason = idle) just before it sends the person here. Shown once, can be closed. English / Amharic. */
(function () {
  'use strict';
  /* nobody is signed in on a sign-in page: forget the last-use time of the earlier sign-in (js/etel-idle-logout.js), so a new sign-in starts a fresh clock */
  try { localStorage.removeItem('etel_last_active'); localStorage.removeItem('etel_last_active_tok'); } catch (e) { }
  var r = '';
  try { r = sessionStorage.getItem('etel_logout_reason') || ''; sessionStorage.removeItem('etel_logout_reason'); } catch (e) {}
  if (r !== 'idle') return;
  function lang() { try { return localStorage.getItem('etel_lang') === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
  var txt = lang() === 'am' ? 'መተግበሪያው ለተወሰነ ጊዜ ስላልተጠቀመ ከመለያዎ ወጥተዋል። እባክዎ እንደገና ይግቡ።' : 'You were signed out because the app was not used for a while. Please sign in again.';
  function show() {
    var b = document.createElement('div'); b.setAttribute('role', 'status'); b.id = 'etel-idle-note';
    b.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:2147483000;padding:12px 46px 12px 14px;background:#1b1608;color:#F5D77A;border-bottom:2px solid #D4AF37;font:600 14px/1.4 system-ui,sans-serif';
    b.textContent = txt;
    var x = document.createElement('button'); x.type = 'button'; x.textContent = '×'; x.setAttribute('aria-label', 'Close');
    x.style.cssText = 'position:absolute;right:8px;top:7px;width:30px;height:30px;border:0;border-radius:50%;background:rgba(255,255,255,.12);color:#F5D77A;font-size:20px;line-height:1;cursor:pointer';
    x.addEventListener('click', function () { if (b.parentNode) b.parentNode.removeChild(b); });
    b.appendChild(x); document.body.appendChild(b);
  }
  if (document.body) show(); else document.addEventListener('DOMContentLoaded', show);
})();
