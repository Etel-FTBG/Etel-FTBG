/* ETEL Courier - photo proof of delivery (additive script, no bundle release).
   After a package is handed over with the recipient's PIN, the green "Delivered" screen gets a button: "Add photo proof (optional)".
   The photo is taken with the phone camera, shrunk on the phone (about 1000 px, JPEG) and sent to api action courier_package_proof; the sender can then
   see it on their tracking screen. It can be added for 2 hours after delivery. The courier app's own code is not touched: this script
   notes the order of a successful courier_package_deliver call (it watches fetch) and adds the button when #pkg-done-overlay appears.
   A SHOP order has no PIN screen: its card on the Active tab (while it is on the way, at the customer's door) gets the same button, so the courier can add
   the photo before or after the customer confirms. English / Amharic (etel_lang). Fails quietly: with no order known the button is simply not shown. */
(function () {
  'use strict';
  if (window.__etelPkgProof) return;
  window.__etelPkgProof = true;
  var AM = { 'Add photo proof (optional)': 'የፎቶ ማረጋገጫ ጨምር (አማራጭ)', 'Saving the photo...': 'ፎቶውን በማስቀመጥ ላይ...', 'Photo saved. The sender can see it.': 'ፎቶው ተቀምጧል። ላኪው ማየት ይችላል።',
    'Could not save the photo. Try again.': 'ፎቶውን ማስቀመጥ አልተቻለም። እንደገና ይሞክሩ።', 'Retake photo': 'ፎቶውን እንደገና ያንሱ' };
  var LAST = 0, LAST_AT = 0;

  function lang() { try { return localStorage.getItem('etel_lang') === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
  function T(s) { return lang() === 'am' && AM[s] ? AM[s] : s; }
  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

  // remember the package the courier has just delivered
  var realFetch = window.fetch;
  if (typeof realFetch === 'function') {
    window.fetch = function (input, init) {
      var p = realFetch.apply(this, arguments);
      try {
        var body = init && init.body;
        if (typeof body === 'string' && body.indexOf('courier_package_deliver') !== -1) {
          var d = JSON.parse(body);
          if (d && d.action === 'courier_package_deliver') {
            p.then(function (r) { return r.clone().json(); }).then(function (j) {
              if (j && j.success) { LAST = parseInt(d.order_id, 10) || 0; LAST_AT = Date.now(); }
            }).catch(function () {});
          }
        }
      } catch (e) {}
      return p;
    };
  }

  // shrink a photo: longest side 1000 px, JPEG, quality down from 0.7 until it is under about 450 KB
  function shrink(file, done) {
    var fr = new FileReader();
    fr.onerror = function () { done(''); };
    fr.onload = function () {
      var img = new Image();
      img.onerror = function () { done(''); };
      img.onload = function () {
        var w = img.naturalWidth || img.width, h = img.naturalHeight || img.height, k = Math.min(1, 1000 / Math.max(w, h));
        var c = document.createElement('canvas'); c.width = Math.max(16, Math.round(w * k)); c.height = Math.max(16, Math.round(h * k));
        try { c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); } catch (e) { done(''); return; }
        var q = 0.7, out = c.toDataURL('image/jpeg', q);
        while (out.length > 600000 && q > 0.35) { q -= 0.1; out = c.toDataURL('image/jpeg', q); }
        done(out.length <= 650000 ? out : '');
      };
      img.src = fr.result;
    };
    fr.readAsDataURL(file);
  }

  var SAVED = {};   // order id -> true once a photo was saved (the Active card is redrawn every few seconds)

  // one box: the button, the hidden camera input and the message. getId() says which order the photo is for.
  function buildBox(getId, savedId) {
    var box = document.createElement('div'); box.className = 'cpp-box'; box.style.cssText = 'margin-top:14px';
    box.innerHTML = '<button type="button" class="cpp-btn" style="width:100%;padding:12px;border-radius:12px;border:1.5px solid #D4AF37;background:transparent;color:#D4AF37;font-size:14px;font-weight:800;cursor:pointer">&#128247; ' + esc(T(savedId && SAVED[savedId] ? 'Retake photo' : 'Add photo proof (optional)')) + '</button>' +
      '<input class="cpp-file" type="file" accept="image/*" capture="environment" style="display:none">' +
      '<div class="cpp-msg" role="status" style="min-height:18px;margin-top:6px;font-size:12.5px;color:' + (savedId && SAVED[savedId] ? '#2ECC71' : '#A8A8B8') + '">' + (savedId && SAVED[savedId] ? esc(T('Photo saved. The sender can see it.')) : '') + '</div>';
    var btn = box.querySelector('.cpp-btn'), file = box.querySelector('.cpp-file'), msg = box.querySelector('.cpp-msg');
    btn.addEventListener('click', function () { file.value = ''; file.click(); });
    file.addEventListener('change', function () {
      var f = file.files && file.files[0]; if (!f) return;
      btn.disabled = true; msg.style.color = '#A8A8B8'; msg.textContent = T('Saving the photo...');
      shrink(f, function (data) {
        var id = getId();
        if (!data || !id || typeof window.api !== 'function') { btn.disabled = false; msg.style.color = '#ff7a7a'; msg.textContent = T('Could not save the photo. Try again.'); return; }
        window.api('courier_package_proof', { order_id: id, photo: data }).then(function (r) {
          btn.disabled = false;
          if (r && r.success) { SAVED[id] = true; msg.style.color = '#2ECC71'; msg.textContent = T('Photo saved. The sender can see it.'); btn.innerHTML = '&#128247; ' + esc(T('Retake photo')); }
          else { msg.style.color = '#ff7a7a'; msg.textContent = (r && r.message) || T('Could not save the photo. Try again.'); }
        }).catch(function () { btn.disabled = false; msg.style.color = '#ff7a7a'; msg.textContent = T('Could not save the photo. Try again.'); });
      });
    });
    return box;
  }

  // the green "Delivered" screen of a package
  function addUi(ov) {
    if (ov.querySelector('.cpp-box')) return;
    var okBtn = ov.querySelector('#pkg-done-ok'); if (!okBtn) return;
    okBtn.parentNode.insertBefore(buildBox(function () { return LAST; }, 0), okBtn);
  }

  // a shop order that is on the way: its card on the Active tab (the customer's door)
  function decorateActive() {
    var cards = document.querySelectorAll('#active-content .job-card.accepted'), mine = window._activeOrders || [];
    for (var i = 0; i < cards.length; i++) {
      var card = cards[i]; if (card.querySelector('.cpp-box')) continue;
      var codeEl = card.querySelector('.job-id'), code = codeEl ? codeEl.textContent.replace(/\s+/g, '') : '';
      for (var q = 0; q < mine.length; q++) {
        var o = mine[q];
        if (o && o.id && String(o.order_code || '').replace(/\s+/g, '') === code) {
          if (o.order_kind !== 'package' && (o.status === 'picked_up' || o.status === 'in_transit')) { var id = parseInt(o.id, 10); card.appendChild(buildBox(function () { return id; }, id)); }
          break;
        }
      }
    }
  }
  setInterval(function () { try { decorateActive(); } catch (e) {} }, 1500);

  function onOverlay(ov) {
    // the delivery call may still be answering when the screen appears: wait a moment for the order
    var tries = 0;
    (function wait() {
      if (LAST && Date.now() - LAST_AT < 60000) { addUi(ov); return; }
      if (++tries < 15 && document.body.contains(ov)) setTimeout(wait, 200);
    })();
  }

  new MutationObserver(function (list) {
    list.forEach(function (m) {
      Array.prototype.forEach.call(m.addedNodes || [], function (n) {
        if (n && n.nodeType === 1 && n.id === 'pkg-done-overlay') onOverlay(n);
      });
    });
  }).observe(document.body || document.documentElement, { childList: true });
})();
