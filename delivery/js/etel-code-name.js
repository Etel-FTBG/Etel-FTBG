/* Shows the NAME of the person behind an Etel Code (E1616) under the box where it was typed, so staff can see at once
   that the code is right. Works on the agent "Book a ride" box, the driver Street Hail box and the admin phone boxes.
   It only asks the server when the text looks like a code (starts with a letter E, 4 to 15 letters or digits). The
   server answers only for signed-in staff and limits how often. Read-only; it never changes what was typed.
   English text only (Amharic is not translated yet). */
(function () {
  'use strict';
  if (window.__etelCodeName) return;
  window.__etelCodeName = true;
  var IDS = ['bk-phone', 'hail-phone', 'pd-phone', 'eqb-add-phone', 'eqb-grant-phone', 'eq-i-link-phone', 'eqp-phone', 'etelpay-override-phone'];
  var RE = /^[Ee][A-Za-z0-9 _-]{3,14}$/;
  var base = '';
  try { var cs = document.currentScript; base = new URL('../api/boot.php', cs ? cs.src : location.href).pathname; } catch (e) { base = '/delivery/api/boot.php'; }
  var timers = {};

  function line(el) {
    var id = el.id + '-ecname';
    var n = document.getElementById(id);
    if (!n) {
      n = document.createElement('div');
      n.id = id; n.setAttribute('role', 'status'); n.setAttribute('aria-live', 'polite');
      n.style.cssText = 'margin:6px 2px 0;padding:6px 10px;border-radius:8px;font-size:13px;font-weight:600;line-height:1.35;display:none;';
      if (el.parentNode) el.parentNode.insertBefore(n, el.nextSibling);
    }
    return n;
  }
  function paint(el, kind, text) {
    var n = line(el);
    if (!kind) { n.style.display = 'none'; n.textContent = ''; return; }
    n.style.display = 'block';
    n.textContent = text;
    // dark text on light box, or light text on dark box: always readable in both themes
    if (kind === 'ok') { n.style.background = '#DFF5E3'; n.style.color = '#0B3D1A'; n.style.border = '1px solid #2E9B4F'; }
    else if (kind === 'bad') { n.style.background = '#FDE3E1'; n.style.color = '#6B1410'; n.style.border = '1px solid #C0392B'; }
    else { n.style.background = '#EEE8D4'; n.style.color = '#3A3217'; n.style.border = '1px solid #B8962E'; }
  }
  function check(el) {
    var v = (el.value || '').trim();
    if (!RE.test(v)) { paint(el, null); return; }
    paint(el, 'wait', 'Checking Etel Code...');
    var tok = ''; try { tok = localStorage.getItem('etel_token') || ''; } catch (e) {}
    var asked = v;
    fetch(base, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + tok },
      body: JSON.stringify({ action: 'etel_code_whois', code: v }) })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if ((el.value || '').trim() !== asked) return;               // the box changed meanwhile
        if (j && j.success && j.data && j.data.found) paint(el, 'ok', 'Etel Code ' + asked.toUpperCase() + ': ' + j.data.name + '. Is this the right person?');
        else if (j && j.success) paint(el, 'bad', 'No member has this Etel Code. Check it, or use the phone number.');
        else paint(el, 'bad', 'Could not check the code now (' + ((j && j.error) || 'try again') + ').');
      })
      .catch(function () { paint(el, 'bad', 'Could not check the code. Check your internet.'); });
  }
  function onInput(e) {
    var el = e.target;
    if (!el || !el.id || IDS.indexOf(el.id) < 0) return;
    clearTimeout(timers[el.id]);
    if (!RE.test((el.value || '').trim())) { paint(el, null); return; }
    timers[el.id] = setTimeout(function () { lastAsked[el.id] = (el.value || '').trim(); check(el); }, 700);   // wait until typing pauses
  }
  document.addEventListener('input', onInput, true);

  /* Some screens (the agent app) rebuild their boxes when something changes, which would wipe the name line.
     When a box holds a code and the line is gone, show it again (one check per box and text). */
  var lastAsked = {};
  function rescan() {
    for (var i = 0; i < IDS.length; i++) {
      var el = document.getElementById(IDS[i]);
      if (!el) continue;
      var v = (el.value || '').trim();
      if (!RE.test(v)) { lastAsked[el.id] = ''; continue; }
      var n = document.getElementById(el.id + '-ecname');
      if (n && n.style.display !== 'none' && lastAsked[el.id] === v) continue;
      if (lastAsked[el.id] === v && n) continue;
      lastAsked[el.id] = v;
      check(el);
    }
  }
  var rt = null;
  try { new MutationObserver(function () { clearTimeout(rt); rt = setTimeout(rescan, 400); }).observe(document.documentElement, { childList: true, subtree: true }); } catch (e) {}
})();
