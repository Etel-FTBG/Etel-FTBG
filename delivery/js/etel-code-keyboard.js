/* Letters allowed in "phone or code" boxes.
   The Street Hail box in the driver app is created by driver.js as type="tel", which gives a number-only keypad and
   cannot take an Etel Code such as E1616. This small add-on switches such boxes to a normal text keyboard (letters
   and numbers) and mentions the Etel Code in the placeholder. No change to driver.js or its bundle is needed. */
(function () {
  'use strict';
  var IDS = ['hail-phone'];                       // inputs that take a phone number OR an Etel Code / EtelID / FIN / card number
  function fix(el) {
    if (!el || el.getAttribute('data-ec-kb')) return;
    el.setAttribute('data-ec-kb', '1');
    try { el.type = 'text'; } catch (e) { el.setAttribute('type', 'text'); }
    el.setAttribute('inputmode', 'text');
    el.setAttribute('autocapitalize', 'characters');
    el.setAttribute('autocomplete', 'off');
    el.setAttribute('spellcheck', 'false');
    var ph = el.getAttribute('placeholder') || '';
    if (ph.indexOf('Etel Code') < 0) el.setAttribute('placeholder', 'Phone, Etel Code (E1616), EtelID, FIN or card no.');
  }
  function scan() { for (var i = 0; i < IDS.length; i++) fix(document.getElementById(IDS[i])); }
  scan();
  try { new MutationObserver(scan).observe(document.documentElement, { childList: true, subtree: true }); } catch (e) { setInterval(scan, 1000); }
})();
