/* Etel Delivery & Ride - shared translation engine, used across all apps.
   English text stays hardcoded in each page's HTML/JS as the fallback -
   only Amharic (or a future language) needs an explicit dictionary entry,
   so English never breaks even if a key is missing from a locale file. */
(function (global) {
  var STORAGE_KEY = 'etel_lang';
  var DICT = { en: {}, am: {} };

  function getLang() {
    try {
      var saved = localStorage.getItem(STORAGE_KEY);
      if (saved === 'am' || saved === 'en') return saved;
    } catch (e) { /* localStorage unavailable - fall through to default */ }
    return 'en';
  }

  function persistToAccount(lang) {
    try {
      if (typeof Auth === 'undefined' || !Auth.isLoggedIn || !Auth.isLoggedIn()) return;
      if (typeof ETEL === 'undefined' || !ETEL.api) return;
      var headers = { 'Content-Type': 'application/json' };
      if (Auth.getToken) headers['Authorization'] = 'Bearer ' + Auth.getToken();
      fetch(ETEL.api, {
        method: 'POST', headers: headers,
        body: JSON.stringify({ action: 'set_user_language', lang: lang }),
      }).catch(function () { /* best-effort - localStorage already has it */ });
    } catch (e) { /* Auth/ETEL not loaded on this page yet - localStorage still works */ }
  }

  function setLang(lang) {
    lang = (lang === 'am') ? 'am' : 'en';
    try { localStorage.setItem(STORAGE_KEY, lang); } catch (e) {}
    persistToAccount(lang);
    applyTranslations();
    try {
      document.dispatchEvent(new CustomEvent('etel:langchange', { detail: { lang: lang } }));
    } catch (e) { /* CustomEvent unsupported on ancient browsers - non-fatal */ }
  }

  function t(key, fallback) {
    var lang = getLang();
    if (lang !== 'en' && DICT[lang] && Object.prototype.hasOwnProperty.call(DICT[lang], key)) {
      return DICT[lang][key];
    }
    if (fallback !== undefined) return fallback;
    if (DICT.en && Object.prototype.hasOwnProperty.call(DICT.en, key)) return DICT.en[key];
    return key;
  }

  function applyTranslations(root) {
    root = root || document;
    var lang = getLang();
    try { document.documentElement.setAttribute('lang', lang); } catch (e) {}

    var nodes = root.querySelectorAll('[data-i18n]');
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      var key = el.getAttribute('data-i18n');
      var attr = el.getAttribute('data-i18n-attr');
      var fallback = el.getAttribute('data-i18n-fallback');
      var val = t(key, fallback === null ? undefined : fallback);
      if (attr) el.setAttribute(attr, val);
      else el.textContent = val;
    }

    var phNodes = root.querySelectorAll('[data-i18n-placeholder]');
    for (var j = 0; j < phNodes.length; j++) {
      var pel = phNodes[j];
      var pkey = pel.getAttribute('data-i18n-placeholder');
      var pfallback = pel.getAttribute('placeholder');
      pel.placeholder = t(pkey, pfallback);
    }

    // Let any page-specific code re-render dynamic strings (e.g. labels
    // built from JS logic, not static DOM) after a language switch.
    try {
      document.dispatchEvent(new CustomEvent('etel:translated', { detail: { lang: lang } }));
    } catch (e) {}
  }

  function registerDict(lang, entries) {
    if (!DICT[lang]) DICT[lang] = {};
    for (var k in entries) {
      if (Object.prototype.hasOwnProperty.call(entries, k)) DICT[lang][k] = entries[k];
    }
  }

  global.I18N = {
    t: t,
    getLang: getLang,
    setLang: setLang,
    applyTranslations: applyTranslations,
    registerDict: registerDict,
  };
})(window);
