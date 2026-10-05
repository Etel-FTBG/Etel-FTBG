/* ============================================================
   ETEL Delivery & Ride - "Pay with Chapa" buttons  v1.0
   Standalone, defensive add-on (same pattern as ride-extra-features.js):
   loaded by its own <script defer> tag on ride/index.html and
   customer.html, so no versioned release bundle is touched.

   - Ride app: adds a Chapa button under the Etel Pay button on the
     "you've arrived" payment card (#arrived-ride-content).
   - Customer app: adds a Chapa button under the delivery-confirmation
     card (#cust-delivery-banner) for each delivered order.
   Both call the backend's chapa_init (amount always computed
   server-side) and go to Chapa's hosted page. When Chapa sends the
   browser back, chapa_status verifies the payment and the page shows
   the result. Does nothing if Chapa isn't configured (the backend
   answers 503 and the rider/customer sees the message).
   ============================================================ */
(function () {
  'use strict';

  function apiUrl() {
    try { if (typeof ETEL !== 'undefined' && ETEL.api) return ETEL.api; } catch (e) {}
    return '/delivery/api/boot.php';
  }
  function token() {
    try { return localStorage.getItem('etel_token') || ''; } catch (e) { return ''; }
  }
  function call(action, payload) {
    var body = { action: action };
    for (var k in payload) body[k] = payload[k];
    return fetch(apiUrl() + (apiUrl().indexOf('?') === -1 ? '?' : '&') + 'action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); });
  }

  function toast(msg, type) {
    try { if (typeof window.showToast === 'function') { window.showToast(msg, type); return; } } catch (e) {}
    var el = document.createElement('div');
    el.textContent = msg;
    el.style.cssText = 'position:fixed;left:50%;bottom:90px;transform:translateX(-50%);z-index:99999;' +
      'padding:12px 18px;border-radius:12px;font:600 14px Outfit,system-ui,sans-serif;max-width:86vw;text-align:center;' +
      'background:' + (type === 'err' ? '#7f1d1d' : '#1a1a2e') + ';color:#fff;border:1px solid rgba(212,175,55,.4)';
    document.body.appendChild(el);
    setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 5000);
  }

  var BTN_STYLE = 'width:100%;padding:14px;border-radius:12px;border:1px solid rgba(212,175,55,.4);' +
    'background:linear-gradient(135deg,rgba(6,42,26,.9),rgba(16,74,50,.8));color:#8BE9B0;font-size:15px;' +
    'font-weight:700;cursor:pointer;margin-top:10px';

  function makeButton(kind, id) {
    var b = document.createElement('button');
    b.type = 'button';
    b.setAttribute('data-chapa-btn', '1');
    b.style.cssText = BTN_STYLE;
    b.textContent = '💳 Pay with Chapa — Telebirr, CBE Birr, bank or card';
    b.addEventListener('click', function () {
      b.disabled = true;
      var old = b.textContent;
      b.textContent = 'Opening Chapa…';
      call('chapa_init', { kind: kind, id: id }).then(function (d) {
        var url = d && d.data && d.data.checkout_url;
        if ((d.success || d.ok) && url) { window.location.href = url; return; }
        throw new Error((d && (d.message || (d.data && d.data.message))) || 'Chapa is not available right now.');
      }).catch(function (err) {
        b.disabled = false;
        b.textContent = old;
        toast(err.message || 'Could not open Chapa.', 'err');
      });
    });
    return b;
  }

  // Ride: card rendered into #arrived-ride-content with an Etel Pay button
  // whose onclick is openEtelPayWallet(<rideId>, <fare>).
  function addRideButton() {
    var host = document.getElementById('arrived-ride-content');
    if (!host || host.querySelector('[data-chapa-btn]')) return;
    var etel = host.querySelector('button[onclick^="openEtelPayWallet"]');
    if (!etel) return;
    var m = /openEtelPayWallet\((\d+)/.exec(etel.getAttribute('onclick') || '');
    if (!m) return;
    etel.parentNode.insertBefore(makeButton('ride', parseInt(m[1], 10)), etel.nextSibling);
  }

  // Customer: each delivered order's card has <div id="wallet-pay-status-<orderId>">.
  function addOrderButtons() {
    var host = document.getElementById('cust-delivery-banner');
    if (!host) return;
    var slots = host.querySelectorAll('[id^="wallet-pay-status-"]');
    for (var i = 0; i < slots.length; i++) {
      var id = parseInt(slots[i].id.replace('wallet-pay-status-', ''), 10);
      if (!id || slots[i].parentNode.querySelector('[data-chapa-btn]')) continue;
      var btn = makeButton('order', id);
      btn.style.marginBottom = '8px';
      slots[i].parentNode.insertBefore(btn, slots[i].nextSibling);
    }
  }

  function scan() { try { addRideButton(); addOrderButtons(); } catch (e) {} }

  // Back from Chapa (chapa-return.php adds ?chapa=<result>&tx_ref=...).
  function handleReturn() {
    var q = new URLSearchParams(window.location.search);
    var ref = q.get('tx_ref');
    if (!q.has('chapa') || !ref) return;
    try {
      q.delete('chapa'); q.delete('tx_ref');
      var qs = q.toString();
      history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : '') + window.location.hash);
    } catch (e) {}
    var tries = 0;
    (function check() {
      call('chapa_status', { tx_ref: ref }).then(function (d) {
        var r = d && d.data && d.data.result;
        if (r === 'completed' || r === 'already') { toast('Payment received through Chapa. Thank you!', 'success'); return; }
        if (r === 'failed') { toast('Your Chapa payment was not completed.', 'err'); return; }
        if (tries++ < 5) setTimeout(check, 4000);
        else toast('Still waiting for Chapa to confirm. It will update automatically.');
      }).catch(function () { if (tries++ < 5) setTimeout(check, 4000); });
    })();
  }

  function start() {
    scan();
    try {
      new MutationObserver(scan).observe(document.body, { childList: true, subtree: true });
    } catch (e) { setInterval(scan, 1500); }
    if (token()) handleReturn();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
