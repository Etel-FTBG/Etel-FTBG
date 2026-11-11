/* Etel Group master page - applies the texts an admin saved under Admin > Site content.
   Only fields that have a saved value change; everything else keeps the built-in text. Fails quiet if the API is down. */
(function () {
  'use strict';
  function apiBase() { return window.ETEL_GROUP_API || 'EtelGroup/api/boot.php'; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function q(sel, root) { return (root || document).querySelector(sel); }

  // Replace the text of an element; keeps the English / Amharic switch working.
  function setText(el, en, am) {
    if (!el) return;
    if (am) {
      if (!el.hasAttribute('data-en')) el.setAttribute('data-en', en || el.textContent.trim());
      if (!el.hasAttribute('data-am')) el.setAttribute('data-am', am);
      if (en) el.setAttribute('data-en', en);
      el.setAttribute('data-am', am);
    } else if (en) {
      if (el.hasAttribute('data-en')) el.setAttribute('data-en', en);
      else el.textContent = en;
    }
  }

  function apply(c) {
    var key;
    // plain fields: every element that carries data-sc="key"
    var els = document.querySelectorAll('[data-sc]');
    for (var i = 0; i < els.length; i++) {
      key = els[i].getAttribute('data-sc');
      if (c[key]) setText(els[i], c[key].en, c[key].am);
    }
    // headline = two lines, the second one gold
    var h1 = q('[data-sc-h1]');
    if (h1 && (c.hero_h1_a || c.hero_h1_b)) {
      var a = c.hero_h1_a || {}, b = c.hero_h1_b || {};
      var curA = (h1.getAttribute('data-en') || '').split('<br>'), curB = (h1.getAttribute('data-am') || '').split('<br>');
      function build(first, second, fallback) {
        var f1 = first || (fallback[0] || ''), f2 = second ? '<span class=\'accent\'>' + esc(second) + '</span>' : (fallback[1] || '');
        return (first ? esc(first) : f1) + '<br>' + f2;
      }
      h1.setAttribute('data-en', build(a.en, b.en, curA));
      h1.setAttribute('data-am', build(a.am, b.am, curB));
    }
    // social links (contact block + footer share the same button labels)
    var socials = { telegram: 'social_telegram', tiktok: 'social_tiktok', instagram: 'social_instagram', linkedin: 'social_linkedin', youtube: 'social_youtube' };
    var btns = document.querySelectorAll('.social-btn');
    for (var j = 0; j < btns.length; j++) {
      var label = (btns[j].getAttribute('aria-label') || '').toLowerCase(), k = socials[label];
      if (k && c[k] && c[k].en) { btns[j].setAttribute('href', c[k].en); if (/^https?:/i.test(c[k].en)) { btns[j].setAttribute('target', '_blank'); btns[j].setAttribute('rel', 'noopener'); } }
    }
    // leadership cards
    for (var n = 1; n <= 3; n++) {
      var card = q('[data-lead="' + n + '"]'); if (!card) continue;
      var name = c['lead' + n + '_name'], title = c['lead' + n + '_title'], bio = c['lead' + n + '_bio'], photo = c['lead' + n + '_photo'];
      if (!(name && name.en)) continue;               // no name saved: the card stays as the "coming soon" placeholder
      var nameEl = q('.ldr-name', card); if (nameEl) nameEl.textContent = name.en;
      var titleEl = q('.ldr-title', card); if (titleEl && title && title.en) titleEl.textContent = title.en;
      if (bio) {
        var bioEl = q('.ldr-bio', card), bioAm = q('.ldr-bio-eth', card);
        if (bioEl && bio.en) bioEl.textContent = bio.en;
        if (bioAm) { if (bio.am) bioAm.textContent = bio.am; else if (bio.en) bioAm.style.display = 'none'; }
      }
      var tba = q('.ldr-tba', card); if (tba) tba.style.display = 'none';
      var av = q('.ldr-avatar', card);
      if (av) {
        if (photo && photo.en) { av.textContent = ''; av.style.backgroundImage = 'url("' + photo.en.replace(/"/g, '%22') + '")'; av.style.backgroundSize = 'cover'; av.style.backgroundPosition = 'center'; }
        else av.textContent = name.en.split(/\s+/).filter(Boolean).slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('');
      }
    }
    // re-render the English / Amharic texts with the new attributes
    if (typeof window.setLang === 'function') { try { window.setLang(typeof currentLang !== 'undefined' ? currentLang : 'en'); } catch (e) { /* keep page as is */ } }
  }

  function init() {
    fetch(apiBase() + '?action=get_site_content').then(function (r) { return r.json(); }).then(function (d) {
      if (d && (d.ok || d.success) && d.data && d.data.content) apply(d.data.content);
    }).catch(function () { /* fail quiet: built-in texts stay */ });
  }
  if (document.readyState !== 'loading') init(); else document.addEventListener('DOMContentLoaded', init);
})();
