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
    { c: 'aa', n: 'Qafar af', e: 'Afar', s: 'AA', beta: 1, soon: 1 }   /* built-in state until the owner's settings load; Afar stays coming soon until a native translator fills the 'aa' column of tools/i18n/strings.tsv, then set it to beta in Admin > Branding > Languages */
  ];
  var DICTS = {};
  var me = document.currentScript, mySrc = (me && me.src) || '';
  var WALK_AM = !!window.ETEL_LANGS_WALK_AM || !!(me && me.getAttribute('data-walk-am'));

  /* The owner controls each language in Admin > Branding > Languages: live (no tag), beta, soon (listed, cannot be chosen), off (hidden), and which one new visitors start in.
     get_language_config is read in the background and kept for 6 hours; until it is read the built-in states above apply. */
  var CFGKEY = 'etel_lang_cfg', CFG = null;
  try { CFG = JSON.parse(localStorage.getItem(CFGKEY) || 'null'); if (CFG && (!CFG.t || !CFG.langs)) CFG = null; } catch (e) { CFG = null; }
  function find(c) { for (var i = 0; i < LANGS.length; i++) if (LANGS[i].c === c) return LANGS[i]; return null; }
  function status(l) {
    if (CFG && CFG.langs && CFG.langs[l.c]) return CFG.langs[l.c];
    return l.soon ? 'soon' : (l.beta ? 'beta' : 'live');
  }
  function usable(c) { var l = find(c); if (!l) return false; var s = status(l); return s === 'live' || s === 'beta'; }
  function stored() { try { return localStorage.getItem(KEY); } catch (e) { return null; } }
  function get() { var v = stored(); if (usable(v)) return v; return (!v && CFG && usable(CFG.def)) ? CFG.def : 'en'; }
  /* English and Amharic stay with an app's own engine; a page that has none (Admin, Pay, ...) puts data-walk-am="1" on this script tag and gets Amharic from i18n/am.js like the other languages. */
  function isNew(c) { return c !== 'en' && (c !== 'am' || WALK_AM) && !!find(c); }

  function saveToAccount(c) {
    try {
      var tok = (window.Auth && Auth.getToken) ? Auth.getToken() : localStorage.getItem('etel_token');
      if (!tok || !window.ETEL || !ETEL.api) return;
      fetch(ETEL.api, { method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + tok }, body: JSON.stringify({ action: 'set_user_language', lang: c }) }).catch(function () {});
    } catch (e) { /* not signed in: the phone keeps it */ }
  }
  function set(c) {
    if (!usable(c)) return;
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
    s.src = base + 'i18n/' + cur + '.js?v=3'; s.async = true;
    document.head.appendChild(s);
  }
  function add(code, dict) {
    DICTS[code] = dict || {};
    if (code === cur) { table = DICTS[code]; if (document.body) run([document.body]); }
  }

  /* ---------- the picker ---------- */
  var OLD = ['#ride-lang-btn', '#drv-lang-btn', '#cl8-lang', '.sg-lang', '.del-lang-switch', '[data-act="toggleCustomerLanguage"]'];
  var btn = null, sheet = null;

  /* Each language has its own flag (30 x 20, drawn here so it works offline). The Ethiopian regional flags are contested: if a community
     sends the flag it wants, only its entry here changes. */
  function star(cx, cy, r, fill) {
    var p = [], i, a, rr;
    for (i = 0; i < 10; i++) { a = -Math.PI / 2 + i * Math.PI / 5; rr = i % 2 ? r * 0.382 : r; p.push((cx + rr * Math.cos(a)).toFixed(2) + ',' + (cy + rr * Math.sin(a)).toFixed(2)); }
    return '<polygon points="' + p.join(' ') + '" fill="' + fill + '"/>';
  }
  var FLAGS = {
    en: '<rect width="30" height="20" fill="#012169"/><path d="M0 0L30 20M30 0L0 20" stroke="#fff" stroke-width="4"/><path d="M0 0L30 20M30 0L0 20" stroke="#C8102E" stroke-width="1.5"/><path d="M15 0V20M0 10H30" stroke="#fff" stroke-width="6"/><path d="M15 0V20M0 10H30" stroke="#C8102E" stroke-width="3.4"/>',
    am: '<rect width="30" height="7" fill="#078930"/><rect y="6.67" width="30" height="6.67" fill="#FCDD09"/><rect y="13.33" width="30" height="6.67" fill="#DA121A"/><circle cx="15" cy="10" r="4.8" fill="#0F47AF"/>' + star(15, 10, 3.9, '#FCDD09'),
    om: '<rect width="30" height="20" fill="#fff"/><rect width="30" height="6.67" fill="#D62718"/><rect y="13.33" width="30" height="6.67" fill="#141414"/><ellipse cx="15" cy="8.6" rx="4.2" ry="2.5" fill="#0A7A3C"/><rect x="14.4" y="9.4" width="1.2" height="3.8" fill="#0A7A3C"/>',
    ti: '<rect width="30" height="20" fill="#D9261C"/>' + star(15, 10.4, 5.6, '#FFD200'),
    so: '<rect width="30" height="20" fill="#4189DD"/>' + star(15, 10.6, 5.6, '#fff'),
    aa: '<rect width="30" height="6.67" fill="#2F6FD0"/><rect y="6.67" width="30" height="6.67" fill="#fff"/><rect y="13.33" width="30" height="6.67" fill="#1E9B4A"/><path d="M0 0L12.5 10L0 20z" fill="#D9261C"/>'
  };
  function flag(c, w) {
    var h = Math.round(w * 2 / 3);
    return '<svg class="elg-flag" width="' + w + '" height="' + h + '" viewBox="0 0 30 20" preserveAspectRatio="none" aria-hidden="true" focusable="false">' + (FLAGS[c] || FLAGS.en) + '</svg>';
  }

  function css() {
    if (document.getElementById('etel-lang-css')) return;
    var st = document.createElement('style'); st.id = 'etel-lang-css';
    var I = '!important';
    st.textContent = '[data-etel-oldlang]{display:none!important}' +
      /* the button: always a navy pill with a gold ring and light text, so it reads on the light and the dark theme alike */
      '#etel-lang-btn{all:unset;box-sizing:border-box;display:inline-flex' + I + ';align-items:center;gap:7px;min-height:36px;min-width:46px;padding:0 11px 0 8px;border-radius:999px;border:1.5px solid #D4AF37' + I + ';background:linear-gradient(180deg,#1B2140,#0E1226)' + I + ';color:#FFFFFF' + I + ';font:800 12.5px/1 system-ui,-apple-system,"Segoe UI",sans-serif' + I + ';letter-spacing:.4px;cursor:pointer;flex:0 0 auto;box-shadow:0 2px 10px rgba(8,10,24,.35),inset 0 1px 0 rgba(255,255,255,.12);-webkit-tap-highlight-color:transparent}' +
      /* some pages (Admin) force a text fill colour on every span: ours must follow the text colour */
      '#etel-lang-btn,#etel-lang-btn *,#etel-lang-sheet,#etel-lang-sheet *{-webkit-text-fill-color:currentColor' + I + ';text-shadow:none' + I + '}' +
      '#etel-lang-btn:focus-visible{outline:3px solid #FFE27A;outline-offset:2px}' +
      '#etel-lang-btn:active{transform:scale(.96)}' +
      '#etel-lang-btn.fl{position:fixed' + I + ';top:calc(env(safe-area-inset-top,0px) + 8px);right:8px;z-index:9300}' +
      '#etel-lang-btn .elg-flag{border-radius:3px;box-shadow:0 0 0 1px rgba(255,255,255,.35);display:block;flex:0 0 auto}' +
      '#etel-lang-btn span{color:#FFFFFF' + I + ';font:inherit}' +
      '#etel-lang-btn i{font-style:normal;color:#FFE27A' + I + ';font-size:9px;line-height:1}' +
      /* the sheet */
      '#etel-lang-sheet{position:fixed;inset:0;z-index:2147483000;background:rgba(5,7,18,.72);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);display:none;align-items:flex-end;justify-content:center;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}' +
      '#etel-lang-sheet.open{display:flex;animation:elgfade .18s ease-out}' +
      '#etel-lang-sheet *{box-sizing:border-box}' +
      '#etel-lang-sheet .box{position:relative;width:100%;max-width:460px;max-height:90vh;overflow:auto;background:radial-gradient(120% 70% at 50% 0%,#1E2650 0%,#121733 45%,#0B0E20 100%)' + I + ';color:#FFFFFF' + I + ';border:1px solid rgba(212,175,55,.55);border-bottom:0;border-radius:26px 26px 0 0;padding:10px 16px calc(18px + env(safe-area-inset-bottom,0px));box-shadow:0 -18px 60px rgba(0,0,0,.55),inset 0 1px 0 rgba(255,226,122,.35);animation:elgup .26s cubic-bezier(.2,.8,.2,1)}' +
      '@media(min-width:560px){#etel-lang-sheet{align-items:center}#etel-lang-sheet .box{border-radius:26px;border-bottom:1px solid rgba(212,175,55,.55)}}' +
      '#etel-lang-sheet .grab{width:42px;height:4px;border-radius:4px;background:rgba(255,226,122,.5);margin:0 auto 12px}' +
      '#etel-lang-sheet .hd{display:flex;align-items:center;gap:12px;margin:0 0 4px}' +
      '#etel-lang-sheet .hdi{width:42px;height:42px;border-radius:14px;flex:0 0 auto;display:flex;align-items:center;justify-content:center;background:linear-gradient(145deg,#FFE27A,#B88A10);color:#14110A' + I + ';box-shadow:0 6px 16px rgba(212,175,55,.35)}' +
      '#etel-lang-sheet .hdi svg{width:23px;height:23px}' +
      '#etel-lang-sheet .hdt{flex:1;min-width:0}' +
      '#etel-lang-sheet .hdt b{display:block;font-size:19px;font-weight:800;color:#FFFFFF' + I + ';letter-spacing:.2px}' +
      '#etel-lang-sheet .hdt small{display:block;font-size:12.5px;color:#FFE27A' + I + ';margin-top:2px;font-weight:600}' +
      '#etel-lang-sheet .x{all:unset;box-sizing:border-box;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#FFFFFF' + I + ';background:rgba(255,255,255,.14)' + I + ';border:1px solid rgba(255,255,255,.3)' + I + ';cursor:pointer;font-size:18px;line-height:1}' +
      '#etel-lang-sheet .x:focus-visible,#etel-lang-sheet .opt:focus-visible{outline:3px solid #FFE27A;outline-offset:2px}' +
      '#etel-lang-sheet .list{display:grid;gap:9px;margin:14px 0 0}' +
      '#etel-lang-sheet .opt{all:unset;box-sizing:border-box;display:flex' + I + ';align-items:center;gap:14px;width:100%;min-height:68px;padding:10px 14px;border-radius:18px;border:1.5px solid rgba(255,255,255,.2)' + I + ';background:rgba(255,255,255,.07)' + I + ';color:#FFFFFF' + I + ';cursor:pointer;position:relative;-webkit-tap-highlight-color:transparent;transition:transform .12s,border-color .12s,background .12s}' +
      '#etel-lang-sheet .opt:active{transform:scale(.985)}' +
      '#etel-lang-sheet .opt.on{border-color:#FFD76A' + I + ';background:linear-gradient(135deg,rgba(255,215,106,.28),rgba(212,175,55,.1))' + I + ';box-shadow:0 0 0 3px rgba(255,215,106,.18),0 8px 22px rgba(0,0,0,.35)}' +
      '#etel-lang-sheet .opt.soon{cursor:default;background:rgba(255,255,255,.04)' + I + ';border-style:dashed' + I + '}' +
      '#etel-lang-sheet .opt.soon .elg-flag{filter:grayscale(.75) brightness(.8)}' +
      '#etel-lang-sheet .fl{flex:0 0 auto;display:block;line-height:0;border-radius:7px;overflow:hidden;box-shadow:0 0 0 1.5px rgba(255,255,255,.55),0 5px 12px rgba(0,0,0,.45)}' +
      '#etel-lang-sheet .elg-flag{display:block}' +
      '#etel-lang-sheet .tx{flex:1;min-width:0;display:block}' +
      '#etel-lang-sheet .tx b{display:block;font-size:18px;line-height:1.2;font-weight:800;color:#FFFFFF' + I + '}' +
      '#etel-lang-sheet .tx em{display:block;font-style:normal;font-size:13px;margin-top:3px;font-weight:600;color:#C9CFE4' + I + '}' +
      '#etel-lang-sheet .opt.soon .tx b{color:#DDE1F0' + I + '}' +
      '#etel-lang-sheet .tag{flex:0 0 auto;font-size:10.5px;font-weight:800;letter-spacing:.6px;padding:4px 9px;border-radius:999px;white-space:nowrap}' +
      '#etel-lang-sheet .tag.beta{background:#FFD76A' + I + ';color:#1A1405' + I + '}' +
      '#etel-lang-sheet .tag.live{background:rgba(66,214,135,.18)' + I + ';color:#7BF0B0' + I + ';border:1px solid rgba(123,240,176,.55)}' +
      '#etel-lang-sheet .tag.soon{background:transparent' + I + ';color:#E8EBF7' + I + ';border:1px solid rgba(255,255,255,.55)}' +
      '#etel-lang-sheet .tick{flex:0 0 auto;width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:1.5px solid rgba(255,255,255,.4);color:transparent}' +
      '#etel-lang-sheet .opt.on .tick{background:#FFD76A;border-color:#FFD76A;color:#14110A' + I + '}' +
      '#etel-lang-sheet .tick svg{width:15px;height:15px}' +
      '#etel-lang-sheet .note{margin:14px 4px 0;font-size:12.5px;line-height:1.45;color:#C9CFE4' + I + ';text-align:center}' +
      '#etel-lang-sheet .brand{margin:10px 0 0;text-align:center;font-size:10.5px;letter-spacing:2px;font-weight:800;color:#FFE27A' + I + ';opacity:.85}' +
      '@keyframes elgup{from{transform:translateY(40px);opacity:0}to{transform:none;opacity:1}}@keyframes elgfade{from{opacity:0}to{opacity:1}}' +
      '@media(prefers-reduced-motion:reduce){#etel-lang-sheet.open,#etel-lang-sheet .box{animation:none}}' +
      '@media(forced-colors:active){#etel-lang-sheet .opt.on{outline:3px solid Highlight}}';
    document.head.appendChild(st);
  }
  var CARET = '<svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="9" height="9"><path d="M2 4l4 4 4-4"/></svg>';
  var GLOBE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.2 3 14.8 0 18M12 3c-3 3.2-3 14.8 0 18"/></svg>';
  var CHECK = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8.5l3.2 3.2L13 4.8"/></svg>';
  function paintBtn() {
    if (!btn) return;
    var l = find(get());
    btn.innerHTML = flag(l.c, 22) + '<span>' + l.s + '</span><i>' + CARET + '</i>';
    btn.setAttribute('aria-label', 'Language: ' + l.e);
    btn.title = l.e;
  }
  function closeSheet() { if (sheet) sheet.classList.remove('open'); if (btn && btn.focus) btn.focus(); }
  function tagText(s) { return s === 'soon' ? 'COMING SOON' : (s === 'beta' ? 'BETA' : 'LIVE'); }
  function buildSheet() {
    if (sheet) { sheet.parentNode && sheet.parentNode.removeChild(sheet); sheet = null; }
    sheet = document.createElement('div'); sheet.id = 'etel-lang-sheet'; sheet.setAttribute('data-no-i18n', '1');
    sheet.setAttribute('role', 'dialog'); sheet.setAttribute('aria-modal', 'true'); sheet.setAttribute('aria-label', 'Language');
    var cc = get(), any = false;
    var h = '<div class="box"><div class="grab"></div><div class="hd"><span class="hdi">' + GLOBE + '</span><span class="hdt"><b>Language</b><small>\u124b\u1295\u124b \u00b7 Afaan \u00b7 Luuqad</small></span>' +
      '<button type="button" class="x" aria-label="Close">\u2715</button></div><div class="list" role="radiogroup" aria-label="Language">';
    LANGS.forEach(function (l) {
      var s = status(l); if (s === 'off') return;
      if (s === 'beta') any = true;
      var on = l.c === cc && s !== 'soon';
      h += '<button type="button" class="opt' + (on ? ' on' : '') + (s === 'soon' ? ' soon' : '') + '" role="radio" aria-checked="' + (on ? 'true' : 'false') + '" data-code="' + l.c + '"' + (s === 'soon' ? ' aria-disabled="true"' : '') + '>' +
        '<span class="fl">' + flag(l.c, 46) + '</span><span class="tx"><b>' + l.n + '</b><em>' + l.e + '</em></span>' +
        (s === 'live' ? '' : '<span class="tag ' + s + '">' + tagText(s) + '</span>') +
        '<span class="tick">' + (on ? CHECK : '') + '</span></button>';
    });
    h += '</div>' + (any ? '<p class="note">BETA languages are first drafts. Anything not translated yet stays in English.</p>' : '') + '<p class="brand">ETEL GROUP</p></div>';
    sheet.innerHTML = h;
    sheet.addEventListener('click', function (e) {
      if (e.target === sheet) { closeSheet(); return; }
      var x = e.target.closest && e.target.closest('.x'); if (x) { closeSheet(); return; }
      var o = e.target.closest && e.target.closest('.opt');
      if (o && !o.classList.contains('soon')) { sheet.classList.remove('open'); set(o.getAttribute('data-code')); }
    });
    sheet.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
      var o = [].slice.call(sheet.querySelectorAll('.opt:not(.soon)')), i = o.indexOf(document.activeElement);
      if (!o.length) return; e.preventDefault();
      o[(i + (e.key === 'ArrowDown' ? 1 : o.length - 1)) % o.length].focus();
    });
    document.body.appendChild(sheet);
  }
  var escBound = false;
  function openSheet() {
    buildSheet();
    if (!escBound) { escBound = true; document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sheet && sheet.classList.contains('open')) closeSheet(); }); }
    sheet.classList.add('open');
    var first = sheet.querySelector('.opt.on') || sheet.querySelector('.opt:not(.soon)');
    if (first && first.focus) first.focus();
  }

  /* ---------- the owner's settings ---------- */
  function applyConfig(d) {
    var langs = {}, def = 'en';
    (d.langs || []).forEach(function (x) { langs[x.code] = x.status; });
    if (d.default) def = d.default;
    CFG = { t: Date.now(), langs: langs, def: def };
    try { localStorage.setItem(CFGKEY, JSON.stringify(CFG)); } catch (e) {}
    var v = stored();
    if (v && !usable(v)) { try { localStorage.setItem(KEY, 'en'); } catch (e) {} setTimeout(function () { location.reload(); }, 30); return; }   // the owner switched that language off
    if (!v && def !== 'en' && usable(def)) { set(def); return; }                                                                                 // first visit: start in the owner's default
    paintBtn();
    if (sheet && sheet.classList.contains('open')) openSheet();
  }
  function loadConfig() {
    try {
      if (CFG && Date.now() - CFG.t < 6 * 3600 * 1000) return;
      if (!window.ETEL || !ETEL.api) return;
      fetch(ETEL.api, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'get_language_config' }) })
        .then(function (r) { return r.json(); })
        .then(function (j) { var d = j && (j.data || j); if (d && d.langs) applyConfig(d); })
        .catch(function () {});
    } catch (e) {}
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
      var slot = document.querySelector('[data-etel-lang-host]');   // a page can reserve a place for the picker (the Admin header)
      if (slot) { btn.classList.remove('fl'); slot.appendChild(btn); } else { btn.classList.add('fl'); document.body.appendChild(btn); }
    }
    paintBtn();
    return !!old;
  }
  function start() {
    if (window.ETEL_LANGS_NO_PICKER) return;   // the Admin page only borrows the flags
    mount();
    loadConfig();
    if (isNew(cur)) startTranslator();
    var tries = 0, iv = setInterval(function () {
      var hadOld = mount();
      // a late-created old button (the app add-ons build theirs after load) moves the picker into its place
      if (hadOld || ++tries > 40) clearInterval(iv);
    }, 750);
  }

  window.EtelLangs = { flag: flag, list: LANGS, get: get, set: set, add: add, open: openSheet, _walk: function () { run([document.body]); } };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
