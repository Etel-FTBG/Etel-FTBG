/* ETEL - language switch for all Ethiopian languages (v1.0, 28 Nov 2026; shared, additive: no bundle release)
 *
 * One picker replaces the old two-language (EN / Amharic) buttons on the Delivery apps: English, Amharic, Afaan Oromoo, Tigrinya, Somali, Afar.
 * - The choice is stored under the same key as before (etel_lang) as a two-letter code: en, am, om, ti, so, aa. One choice carries across ETEL apps.
 * - Picking a language saves it (and, when signed in, on the account: api set_user_language) and reloads the page, so every app engine starts clean in that language.
 * - English and Amharic stay with the apps' own engines (nothing changes for them). The new languages (om, ti, so, aa) are done HERE: every piece of visible English text is
 *   looked up in that language's dictionary (i18n/<code>.js, English text -> translated text) and swapped; text drawn later is translated too. Text with no entry stays English.
 *   The new languages are BETA: first drafts that a native reader should check; the picker says so.
 * - Never touches typed values, scripts, styles, maps, anything marked data-no-i18n.
 * - The picker sits where the old EN / Amharic button was (that button is hidden); a page with none gets a small floating button.
 */
(function () {
  'use strict';
  if (window.EtelLangs) return;

  var KEY = 'etel_lang';
  var LANGS = [
    { c: 'en', n: 'English', e: 'English', s: 'EN' },
    { c: 'am', n: '\u12a0\u121b\u122d\u129b', e: 'Amharic', s: '\u12a0\u121b' },
    { c: 'om', n: 'Afaan Oromoo', e: 'Oromo', s: 'OM', beta: 1 },
    { c: 'ti', n: '\u1275\u130d\u122d\u129b', e: 'Tigrinya', s: '\u1275\u130d', beta: 1 },
    { c: 'so', n: 'Soomaali', e: 'Somali', s: 'SO', beta: 1 },
    { c: 'aa', n: 'Qafar af', e: 'Afar', s: 'AA', beta: 1, soon: 1 }   /* soon: listed but not selectable until a native translator fills the 'aa' column of tools/i18n/strings.tsv; then delete soon:1 */
  ];
  var DICTS = {};
  var me = document.currentScript, mySrc = (me && me.src) || '';

  function find(c) { for (var i = 0; i < LANGS.length; i++) if (LANGS[i].c === c) return LANGS[i]; return null; }
  function get() { try { var v = localStorage.getItem(KEY); return find(v) && !find(v).soon ? v : 'en'; } catch (e) { return 'en'; } }
  function isNew(c) { return c !== 'en' && c !== 'am' && !!find(c); }

  function saveToAccount(c) {
    try {
      var tok = (window.Auth && Auth.getToken) ? Auth.getToken() : localStorage.getItem('etel_token');
      if (!tok || !window.ETEL || !ETEL.api) return;
      fetch(ETEL.api, { method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + tok }, body: JSON.stringify({ action: 'set_user_language', lang: c }) }).catch(function () {});
    } catch (e) { /* not signed in: the phone keeps it */ }
  }
  function set(c) {
    if (!find(c) || find(c).soon) return;
    var same = get() === c;
    try { localStorage.setItem(KEY, c); } catch (e) { /* private window: still applies for this visit */ }
    saveToAccount(c);
    if (same) return;
    try { document.documentElement.setAttribute('lang', c); } catch (e) {}
    try { window.dispatchEvent(new CustomEvent('etel:lang', { detail: c })); } catch (e) {}
    setTimeout(function () { location.reload(); }, 60);
  }

  /* ---------- translating what is on screen (new languages only) ---------- */
  var cur = get(), table = null, obs = null, started = false;
  var SKIP = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, SELECT: 1, OPTION: 1, NOSCRIPT: 1 };
  function skipEl(el) {
    for (; el && el.nodeType === 1; el = el.parentNode) {
      if (SKIP[el.tagName]) return true;
      if (el.classList && (el.classList.contains('pac-container') || el.id === 'map') || el.hasAttribute('data-no-i18n')) return true;
    }
    return false;
  }
  function strip(s) { var m = /^([^A-Za-z0-9'"~]*)([\s\S]*?)([^A-Za-z0-9'"\u2026).?]*)$/.exec(s); return m ? m : [s, '', s, '']; }
  function look(raw) {
    var t = raw.replace(/\s+/g, ' ').trim();
    if (!t) return null;
    if (Object.prototype.hasOwnProperty.call(table, t)) return table[t];
    var p = strip(t);
    if (p[2] && p[2] !== t && Object.prototype.hasOwnProperty.call(table, p[2])) return p[1] + table[p[2]] + p[3];
    return null;
  }
  var seen = new WeakMap();    // text node -> the translated text we put there (to leave it alone)
  function textNode(n) {
    if (seen.get(n) === n.nodeValue) return;
    var src = n.nodeValue, tr = look(src);
    if (!tr) return;
    var out = /^\s*/.exec(src)[0] + tr + /\s*$/.exec(src)[0];
    if (n.nodeValue !== out) n.nodeValue = out;
    seen.set(n, out);
  }
  function attrs(el) {
    ['placeholder', 'title', 'aria-label'].forEach(function (a) {
      var v = el.getAttribute(a);
      if (v == null || el.hasAttribute('data-i18n-done-' + a)) return;
      var tr = look(v);
      if (tr) { el.setAttribute(a, tr); el.setAttribute('data-i18n-done-' + a, '1'); }
    });
  }
  function walk(root) {
    if (!root || !table) return;
    if (root.nodeType === 3) { if (!skipEl(root.parentNode)) textNode(root); return; }
    if (root.nodeType !== 1 || skipEl(root)) return;
    attrs(root);
    var tw = document.createTreeWalker(root, 1 | 4, { acceptNode: function (n) {
      if (n.nodeType === 1) return skipEl(n) ? 2 : 1;      // 2 = reject the whole subtree
      return 1;
    } });
    for (var n = tw.nextNode(); n; n = tw.nextNode()) { if (n.nodeType === 3) textNode(n); else attrs(n); }
  }
  function run(roots) { roots.forEach(walk); if (obs) obs.takeRecords(); }
  function startTranslator() {
    if (started) return; started = true;
    document.documentElement.setAttribute('lang', cur);
    var pending = [], timer = 0;
    obs = new MutationObserver(function (recs) {
      for (var i = 0; i < recs.length; i++) {
        var r = recs[i];
        if (r.type === 'childList') { for (var j = 0; j < r.addedNodes.length; j++) pending.push(r.addedNodes[j]); }
        else pending.push(r.target);
      }
      if (!timer) timer = setTimeout(function () { timer = 0; var b = pending; pending = []; run(b.filter(function (n) { return n && n.isConnected; })); }, 30);
    });
    obs.observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['placeholder', 'title', 'aria-label'] });
    // dictionary file: i18n/<code>.js next to js/
    var base = mySrc ? mySrc.replace(/js\/[^\/?#]*(\?.*)?$/, '') : '/delivery/';
    var s = document.createElement('script');
    s.src = base + 'i18n/' + cur + '.js?v=1'; s.async = true;
    document.head.appendChild(s);
  }
  function add(code, dict) {
    DICTS[code] = dict || {};
    if (code === cur) { table = DICTS[code]; if (document.body) run([document.body]); }
  }

  /* ---------- the picker ---------- */
  var OLD = ['#ride-lang-btn', '#drv-lang-btn', '#cl8-lang', '.sg-lang', '.del-lang-switch', '[data-act="toggleCustomerLanguage"]'];
  var btn = null, sheet = null;
  function css() {
    if (document.getElementById('etel-lang-css')) return;
    var st = document.createElement('style'); st.id = 'etel-lang-css';
    st.textContent = '[data-etel-oldlang]{display:none!important}' +
      '#etel-lang-btn{display:inline-flex;align-items:center;gap:5px;min-height:34px;min-width:44px;padding:0 10px;border-radius:999px;border:1px solid rgba(212,175,55,.7);background:rgba(17,19,28,.9);color:#F7F5EE;font:700 12px system-ui,-apple-system,sans-serif;cursor:pointer;flex:0 0 auto;line-height:1}' +
      '#etel-lang-btn.fl{position:fixed;top:calc(env(safe-area-inset-top,0px) + 8px);right:8px;z-index:9300}' +
      '#etel-lang-btn svg{width:15px;height:15px;display:block}' +
      '#etel-lang-sheet{position:fixed;inset:0;z-index:2147483000;background:rgba(0,0,0,.6);display:none;align-items:flex-end;justify-content:center;font-family:system-ui,-apple-system,sans-serif}' +
      '#etel-lang-sheet.open{display:flex}' +
      '#etel-lang-sheet .box{width:100%;max-width:440px;max-height:86vh;overflow:auto;background:#11131C;color:#F7F5EE;border:1px solid rgba(212,175,55,.4);border-radius:20px 20px 0 0;padding:14px 12px calc(14px + env(safe-area-inset-bottom,0px))}' +
      '@media(min-width:560px){#etel-lang-sheet{align-items:center}#etel-lang-sheet .box{border-radius:20px}}' +
      '#etel-lang-sheet h2{margin:0 4px 8px;font-size:16px;font-weight:800}' +
      '#etel-lang-sheet .opt{display:flex;align-items:center;gap:10px;width:100%;min-height:52px;margin:0 0 6px;padding:0 14px;border-radius:14px;border:1px solid rgba(255,255,255,.14);background:transparent;color:inherit;font:inherit;text-align:left;cursor:pointer}' +
      '#etel-lang-sheet .opt.on{border-color:#D4AF37;background:rgba(212,175,55,.14)}' +
      '#etel-lang-sheet .opt b{font-size:16px}#etel-lang-sheet .opt small{display:block;font-size:12px;color:#B9BDC8;margin-top:1px}' +
      '#etel-lang-sheet .opt .grow{flex:1}#etel-lang-sheet .beta{font-size:10px;font-weight:800;letter-spacing:.4px;padding:2px 7px;border-radius:999px;background:#F8C467;color:#16120A}' +
      '#etel-lang-sheet .tick{width:20px;text-align:center;color:#FFE27A;font-weight:800}' +
      '#etel-lang-sheet .note{margin:6px 6px 0;font-size:12px;color:#9A9AAE}';
    document.head.appendChild(st);
  }
  var GLOBE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.2 3 14.8 0 18M12 3c-3 3.2-3 14.8 0 18"/></svg>';
  function paintBtn() {
    if (!btn) return;
    var l = find(get());
    btn.innerHTML = GLOBE + '<span>' + l.s + '</span>';
    btn.setAttribute('aria-label', 'Language: ' + l.e);
    btn.title = l.e;
  }
  function closeSheet() { if (sheet) sheet.classList.remove('open'); if (btn) btn.focus && btn.focus(); }
  function buildSheet() {
    if (sheet) return;
    sheet = document.createElement('div'); sheet.id = 'etel-lang-sheet'; sheet.setAttribute('data-no-i18n', '1');
    sheet.setAttribute('role', 'dialog'); sheet.setAttribute('aria-modal', 'true'); sheet.setAttribute('aria-label', 'Language');
    var box = document.createElement('div'); box.className = 'box'; sheet.appendChild(box);
    var h = document.createElement('h2'); h.textContent = 'Language \u00b7 \u124b\u1295\u124b'; box.appendChild(h);
    LANGS.forEach(function (l) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'opt'; b.setAttribute('data-code', l.c);
      b.setAttribute('role', 'menuitemradio');
      var g = document.createElement('span'); g.className = 'grow';
      var n = document.createElement('b'); n.textContent = l.n; g.appendChild(n);
      var s = document.createElement('small'); s.textContent = l.e; g.appendChild(s);
      b.appendChild(g);
      if (l.soon) { var w = document.createElement('span'); w.className = 'beta'; w.textContent = 'COMING SOON'; b.appendChild(w); b.disabled = true; b.style.opacity = '.55'; b.style.cursor = 'default'; }
      else if (l.beta) { var t = document.createElement('span'); t.className = 'beta'; t.textContent = 'BETA'; b.appendChild(t); }
      var k = document.createElement('span'); k.className = 'tick'; b.appendChild(k);
      b.addEventListener('click', function () { sheet.classList.remove('open'); set(l.c); });
      box.appendChild(b);
    });
    var note = document.createElement('p'); note.className = 'note';
    note.textContent = 'BETA languages are first drafts. Anything not translated yet stays in English.';
    box.appendChild(note);
    sheet.addEventListener('click', function (e) { if (e.target === sheet) closeSheet(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sheet.classList.contains('open')) closeSheet(); });
    document.body.appendChild(sheet);
  }
  function openSheet() {
    buildSheet();
    var cc = get();
    [].forEach.call(sheet.querySelectorAll('.opt'), function (b) {
      var on = b.getAttribute('data-code') === cc;
      b.classList.toggle('on', on); b.setAttribute('aria-checked', on ? 'true' : 'false');
      b.querySelector('.tick').textContent = on ? '\u2713' : '';
    });
    sheet.classList.add('open');
    var first = sheet.querySelector('.opt.on') || sheet.querySelector('.opt');
    if (first && first.focus) first.focus();
  }
  function mount() {
    if (!document.body) return false;
    css();
    var host = null, old = null;
    for (var i = 0; i < OLD.length; i++) {
      var el = document.querySelector(OLD[i]);
      if (el) { old = el; break; }
    }
    if (old) { old.setAttribute('data-etel-oldlang', '1'); host = old.parentNode; }
    if (!btn) {
      btn = document.createElement('button'); btn.type = 'button'; btn.id = 'etel-lang-btn'; btn.setAttribute('data-no-i18n', '1');
      btn.setAttribute('aria-haspopup', 'dialog');
      btn.addEventListener('click', openSheet);
    }
    if (old && host) {
      if (btn.parentNode !== host || btn.nextSibling !== old) host.insertBefore(btn, old);
      btn.classList.remove('fl');
      try {
        var cs = getComputedStyle(old);
        if (cs.position === 'absolute' || cs.position === 'fixed') {      // e.g. the login page's corner switch: take over its corner
          btn.style.position = cs.position; btn.style.top = cs.top; btn.style.right = cs.right; btn.style.left = cs.left === 'auto' ? '' : cs.left;
          btn.style.bottom = cs.bottom === 'auto' ? '' : cs.bottom; btn.style.zIndex = cs.zIndex === 'auto' ? '5' : cs.zIndex;
        }
      } catch (e) {}
    } else if (!btn.parentNode) {
      btn.classList.add('fl'); document.body.appendChild(btn);
    }
    paintBtn();
    return !!old;
  }
  function start() {
    mount();
    if (isNew(cur)) startTranslator();
    var tries = 0, iv = setInterval(function () {
      var hadOld = mount();
      // a late-created old button (the app add-ons build theirs after load) moves the picker into its place
      if (hadOld || ++tries > 40) clearInterval(iv);
    }, 750);
  }

  window.EtelLangs = { list: LANGS, get: get, set: set, add: add, open: openSheet, _walk: function () { run([document.body]); } };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
