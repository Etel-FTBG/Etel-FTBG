/* ETEL - one branded confirmation dialog for every app (customer, shop, courier, driver, ride).
   The browser's own "The page at https://... says" box shows the web address and looks foreign. This file replaces window.confirm: when a tap would
   open that box, the tap is paused, the ETEL dialog asks (title, details, a clear action button, Cancel), and on OK the same tap is replayed with the
   answer "yes" already given. No call site has to change, so every existing confirm in every app (and every versioned bundle) gets it.
   A confirm that does not come from a tap (a timer, an old page) still uses the browser box. English / Amharic (etel_lang). window.EtelConfirm(text, opts) is a promise version. */
(function () {
  'use strict';
  if (window.__etelDialog) return;
  window.__etelDialog = true;
  var nativeConfirm = window.confirm ? window.confirm.bind(window) : function () { return true; };
  var AM = { 'Cancel': 'ተው', 'Confirm': 'አረጋግጥ', 'Log out': 'ውጣ', 'Remove': 'አስወግድ', 'Yes, cancel': 'አዎ፣ ሰርዝ', 'Go offline': 'ከመስመር ውጣ', 'Accept job': 'ሥራውን ተቀበል',
    'Send request': 'ጥያቄ ላክ', 'Continue': 'ቀጥል', 'Import': 'አስገባ', 'Please confirm': 'እባክዎ ያረጋግጡ', 'Send SOS': 'SOS ላክ', 'Start': 'ጀምር', 'Clear': 'አጽዳ', 'Go online': 'መስመር ላይ ግባ' };
  var approved = {};   // messages the person already said yes to for the tap being replayed
  var ctx = null, open = false, last = null;

  function lang() { try { return localStorage.getItem('etel_lang') === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
  function T(s) { return lang() === 'am' && AM[s] ? AM[s] : s; }
  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

  /* what the action button says, and whether it is a "danger" action */
  function kind(msg) {
    var m = String(msg).toLowerCase();
    if (/log ?out|sign out|logout/.test(m)) return { label: 'Log out', danger: true, icon: 'out' };
    if (/sos|emergency/.test(m)) return { label: 'Send SOS', danger: true, icon: 'warn' };
    if (/go offline/.test(m)) return { label: 'Go offline', danger: false, icon: 'power' };
    if (/go back online|go online/.test(m)) return { label: 'Go online', danger: false, icon: 'power' };
    if (/clears everything|clear/.test(m)) return { label: 'Clear', danger: true, icon: 'warn' };
    if (/delete|remove/.test(m)) return { label: 'Remove', danger: true, icon: 'trash' };
    if (/\bcancel\b/.test(m)) return { label: 'Yes, cancel', danger: true, icon: 'warn' };
    if (/accept job/.test(m)) return { label: 'Accept job', danger: false, icon: 'check' };
    if (/request .*confirmation|send a request/.test(m)) return { label: 'Send request', danger: false, icon: 'send' };
    if (/import/.test(m)) return { label: 'Import', danger: false, icon: 'check' };
    if (/start charging|arrived|override|confirm that/.test(m)) return { label: 'Confirm', danger: false, icon: 'check' };
    return { label: 'Confirm', danger: false, icon: 'ask' };
  }
  var ICON = {
    ask: '<path d="M9.5 9a2.5 2.5 0 1 1 3.6 2.2c-.7.4-1.1.9-1.1 1.8M12 17h.01" />', check: '<path d="M5 12.5l4.5 4.5L19 7.5" />', warn: '<path d="M12 8v5M12 16.5h.01M10.3 4l-8 14a2 2 0 0 0 1.7 3h16a2 2 0 0 0 1.7-3l-8-14a2 2 0 0 0-3.4 0z" />',
    trash: '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13" />', out: '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 8l-4 4 4 4M6 12h10" />', power: '<path d="M12 3v8M6.3 6.5a8 8 0 1 0 11.4 0" />',
    send: '<path d="M4 12l16-8-6 16-3-7z" />'
  };

  function css() {
    if (document.getElementById('ed-css')) return;
    var s = document.createElement('style'); s.id = 'ed-css';
    s.textContent =
      '.ed-wrap{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(8,10,20,.62);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);animation:edf .16s ease-out}' +
      '.ed-card{width:100%;max-width:380px;border-radius:22px;padding:26px 22px 20px;background:linear-gradient(180deg,#171B2E 0%,#0F1222 100%);color:#F4F1E8;border:1px solid rgba(212,175,55,.45);box-shadow:0 24px 60px rgba(0,0,0,.55),0 0 0 1px rgba(255,255,255,.04) inset;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;animation:edu .2s cubic-bezier(.2,.9,.3,1.2);text-align:center;position:relative;overflow:hidden}' +
      '.ed-card:before{content:"";position:absolute;left:0;right:0;top:0;height:3px;background:linear-gradient(90deg,#8a6d1b,#F0D26A,#8a6d1b)}' +
      '.ed-brand{display:flex;align-items:center;justify-content:center;gap:8px;font:800 11px system-ui,sans-serif;letter-spacing:.28em;color:#E5C76B;margin-bottom:14px}' +
      '.ed-ico{width:60px;height:60px;border-radius:50%;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;background:radial-gradient(circle at 30% 25%,#F6DD84,#C99A1E 70%);box-shadow:0 8px 22px rgba(212,175,55,.35)}' +
      '.ed-card.danger .ed-ico{background:radial-gradient(circle at 30% 25%,#FF8A8A,#D63A3A 70%);box-shadow:0 8px 22px rgba(230,57,70,.35)}' +
      '.ed-ico svg{width:28px;height:28px;fill:none;stroke:#1a1200;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}.ed-card.danger .ed-ico svg{stroke:#fff}' +
      '.ed-title{font-size:18px;font-weight:800;line-height:1.3;margin:0 0 8px;color:#fff;word-break:break-word}' +
      '.ed-body{font-size:14px;line-height:1.5;color:#B9B7C9;margin:0 0 20px;white-space:pre-line;word-break:break-word}' +
      '.ed-row{display:flex;gap:10px}.ed-row button{flex:1;min-height:48px;border-radius:14px;font:800 15px system-ui,sans-serif;cursor:pointer;border:0}' +
      '.ed-no{background:rgba(255,255,255,.08);color:#F4F1E8;border:1px solid rgba(255,255,255,.18)!important}' +
      '.ed-yes{background:linear-gradient(180deg,#F0D26A,#C99A1E);color:#1a1200;box-shadow:0 6px 18px rgba(212,175,55,.35)}' +
      '.ed-card.danger .ed-yes{background:linear-gradient(180deg,#F26A6A,#D63A3A);color:#fff;box-shadow:0 6px 18px rgba(230,57,70,.35)}' +
      '.ed-row button:focus-visible{outline:3px solid #fff;outline-offset:2px}.ed-row button:active{transform:scale(.98)}' +
      '@keyframes edf{from{opacity:0}to{opacity:1}}@keyframes edu{from{opacity:0;transform:translateY(14px) scale(.96)}to{opacity:1;transform:none}}' +
      '@media (prefers-reduced-motion:reduce){.ed-wrap,.ed-card{animation:none}}';
    document.head.appendChild(s);
  }

  /* show the dialog; done(true|false) is called once */
  function show(msg, opts, done) {
    css(); open = true;
    opts = opts || {};
    var k = kind(msg), parts = String(msg).replace(/\r/g, '').split(/\n\s*\n/), title = parts.shift().trim(), body = parts.join('\n\n').trim();
    if (!body && title.length > 90) { var cut = title.indexOf('. '); if (cut > 20) { body = title.substring(cut + 2); title = title.substring(0, cut + 1); } }
    var label = opts.confirmLabel || T(k.label), danger = opts.danger != null ? !!opts.danger : k.danger;
    var w = document.createElement('div'); w.className = 'ed-wrap'; w.setAttribute('role', 'presentation');
    w.innerHTML = '<div class="ed-card' + (danger ? ' danger' : '') + '" role="alertdialog" aria-modal="true" aria-labelledby="ed-t"' + (body ? ' aria-describedby="ed-b"' : '') + '>' +
      '<div class="ed-brand">ETEL</div><div class="ed-ico"><svg viewBox="0 0 24 24" aria-hidden="true">' + (ICON[opts.icon || k.icon] || ICON.ask) + '</svg></div>' +
      '<h2 class="ed-title" id="ed-t">' + esc(title).replace(/\b([A-Z]{2,4}-[A-Z0-9]{4,})\b/g, '<span style="white-space:nowrap">$1</span>') + '</h2>' + (body ? '<p class="ed-body" id="ed-b">' + esc(body) + '</p>' : '<div style="height:6px"></div>') +
      '<div class="ed-row"><button type="button" class="ed-no">' + esc(opts.cancelLabel || T('Cancel')) + '</button><button type="button" class="ed-yes">' + esc(label) + '</button></div></div>';
    var prevFocus = document.activeElement, finished = false;
    function finish(ok) {
      if (finished) return; finished = true; open = false;
      document.removeEventListener('keydown', onKey, true);
      if (w.parentNode) w.parentNode.removeChild(w);
      try { if (prevFocus && prevFocus.focus && document.contains(prevFocus)) prevFocus.focus(); } catch (e) {}
      done(ok);
    }
    function onKey(e) {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); finish(false); }
      else if (e.key === 'Tab') { var b = w.querySelectorAll('button'), f = document.activeElement; if (e.shiftKey && f === b[0]) { e.preventDefault(); b[b.length - 1].focus(); } else if (!e.shiftKey && f === b[b.length - 1]) { e.preventDefault(); b[0].focus(); } }
    }
    w.querySelector('.ed-no').addEventListener('click', function (e) { e.stopPropagation(); finish(false); });
    w.querySelector('.ed-yes').addEventListener('click', function (e) { e.stopPropagation(); finish(true); });
    w.addEventListener('click', function (e) { if (e.target === w) finish(false); });
    document.addEventListener('keydown', onKey, true);
    document.body.appendChild(w);
    setTimeout(function () { var y = w.querySelector(danger ? '.ed-no' : '.ed-yes'); if (y) y.focus(); }, 30);   // a dangerous action starts on Cancel
  }

  /* remember the tap that is being handled right now */
  document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? (e.target.closest('button,a,[onclick],[data-act],input,label,summary') || e.target) : e.target;
    if (t && t.closest && t.closest('.ed-wrap')) return;   // a tap inside the dialog itself changes nothing
    if (e.isTrusted) approved = {};                          // a new real tap on the page: earlier yes answers no longer count
    ctx = { target: t, at: Date.now() };
  }, true);

  window.confirm = function (msg) {
    msg = String(msg == null ? '' : msg);
    var now = Date.now(), a = approved[msg];
    if (a && now < a) return true;                      // the person already said yes to this one for the tap being replayed
    var c = ctx;
    if (open || !c || now - c.at > 300 || !c.target || !c.target.isConnected || !document.body) return nativeConfirm(msg);   // not from a tap: browser box
    var target = c.target;
    show(msg, null, function (ok) {
      if (!ok) return;
      approved[msg] = Date.now() + 8000;
      try { target.click(); } catch (e) { /* the button is gone: nothing to replay */ }
    });
    return false;                                       // pause this tap; it runs again after OK
  };

  /* promise version for new code: await EtelConfirm('Remove this?', {confirmLabel:'Remove', danger:true}) */
  window.EtelConfirm = function (msg, opts) {
    return new Promise(function (res) { if (!document.body) return res(nativeConfirm(String(msg))); show(String(msg), opts, res); });
  };
})();
