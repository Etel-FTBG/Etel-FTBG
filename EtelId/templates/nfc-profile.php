<?php
/**
 * Public NFC profile template.
 * Rendered by Etelid_Nfc::maybe_render_profile() - $member and $card are
 * already in scope (may be null if the token was invalid/revoked).
 */

if (!defined('ABSPATH')) {
    exit;
}

$has_member = !empty($member);
$name = $has_member ? esc_html($member->full_name) : '';
$etelid_number = $has_member ? esc_html($member->etel_id) : '';
$tier = $has_member ? esc_html($member->tier) : '';
$photo = $has_member && !empty($member->photo_url) ? esc_url($member->photo_url) : '';
$nfc_token = (!empty($card) && isset($card->token)) ? esc_attr($card->token) : '';
$nfc_ajax_url = esc_url(admin_url('admin-ajax.php'));
$nfc_nonce = wp_create_nonce('etelid_public');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $has_member ? $name . ' - EtelID' : 'EtelID'; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Cormorant+Garamond:ital,wght@0,500;0,600;1,500&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<style>
  :root {
    --carbon-1: #030205;
    --carbon-2: #0d0d0d;
    --carbon-3: #0a0703;
    --carbon-4: #130d05;
    --gold-1: #C8960C;
    --gold-2: #D4AF37;
    --gold-3: #F2CC6B;
    --gold-deep-1: #A07820;
    --gold-deep-2: #C9A227;
  }
  * { box-sizing: border-box; }
  html, body {
    margin: 0;
    padding: 0;
    min-height: 100%;
    background: linear-gradient(155deg,#2A1600 0%,#5A3A08 15%,#9A6818 30%,#C8922A 44%,#D4AF37 50%,#C8922A 56%,#9A6818 70%,#5A3A08 85%,#2A1600 100%);
    font-family: 'JetBrains Mono', monospace;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
  }
  .card {
    position: relative;
    width: 100%;
    max-width: 380px;
    background: linear-gradient(165deg, var(--carbon-4) 0%, var(--carbon-3) 100%);
    border: 1px solid rgba(212,175,55,0.35);
    border-radius: 18px;
    overflow: hidden;
    padding: 32px 28px 28px;
    color: #f2e8d0;
    text-align: center;
  }
  .card::before {
    content: "";
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--gold-deep-1), var(--gold-2), var(--gold-3), var(--gold-2), var(--gold-deep-1));
  }
  .card::after {
    content: "";
    position: absolute;
    top: 0; bottom: 0; right: 28px;
    width: 6px;
    background: linear-gradient(180deg, transparent, rgba(212,175,55,0.5), transparent);
    opacity: 0.5;
  }
  .mark {
    font-family: 'Syne', sans-serif;
    font-weight: 800;
    font-size: 20px;
    letter-spacing: 1px;
    color: var(--gold-3);
    margin-bottom: 18px;
  }
  .mark span { color: #fff; }
  .photo {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid var(--gold-2);
    margin-bottom: 16px;
    background: var(--carbon-2);
  }
  .photo-placeholder {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    border: 2px solid rgba(212,175,55,0.4);
    margin: 0 auto 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Syne', sans-serif;
    font-size: 28px;
    font-weight: 800;
    color: var(--gold-2);
    background: var(--carbon-2);
  }
  .name {
    font-family: 'Cormorant Garamond', serif;
    font-weight: 600;
    font-size: 26px;
    color: #fff;
    margin: 0 0 4px;
  }
  .etelid {
    font-size: 13px;
    letter-spacing: 1px;
    color: var(--gold-2);
    margin-bottom: 14px;
  }
  .badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    letter-spacing: 0.5px;
    padding: 6px 14px;
    border-radius: 999px;
    border: 1px solid rgba(212,175,55,0.4);
    background: rgba(212,175,55,0.08);
    color: var(--gold-3);
    margin-bottom: 18px;
  }
  .tier {
    font-size: 12px;
    color: rgba(242,232,208,0.65);
    letter-spacing: 1px;
    text-transform: uppercase;
  }
  .invalid {
    font-family: 'Cormorant Garamond', serif;
    font-size: 22px;
    color: rgba(242,232,208,0.8);
    padding: 20px 0;
  }
  .footer {
    margin-top: 24px;
    font-size: 10px;
    color: rgba(242,232,208,0.35);
    letter-spacing: 1px;
  }
  .nfc-services {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid rgba(212,175,55,0.25);
    text-align: left;
  }
  .nfc-services h2 {
    font-family: 'Syne', sans-serif;
    font-size: 12px;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: rgba(242,232,208,0.6);
    margin: 0 0 12px;
    text-align: center;
  }
  .nfc-btn {
    display: block;
    width: 100%;
    padding: 13px 14px;
    margin-bottom: 10px;
    border-radius: 10px;
    border: 1px solid rgba(212,175,55,0.4);
    background: rgba(212,175,55,0.08);
    color: var(--gold-3);
    font-family: 'JetBrains Mono', monospace;
    font-size: 13px;
    letter-spacing: 0.3px;
    cursor: pointer;
    text-align: center;
  }
  .nfc-btn:hover { background: rgba(212,175,55,0.16); }
  .nfc-role-label {
    font-family: 'Syne', sans-serif;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: rgba(242,232,208,0.45);
    margin: 0 0 6px;
  }
  .nfc-btn.primary {
    background: linear-gradient(135deg,#C8960C,#D4AF37);
    color: #0d0d0d;
    font-weight: 700;
    border: none;
  }
  .nfc-step { display: none; }
  .nfc-step.active { display: block; }
  .nfc-field {
    margin-bottom: 12px;
    text-align: left;
  }
  .nfc-field label {
    display: block;
    font-size: 10px;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    color: rgba(242,232,208,0.55);
    margin-bottom: 5px;
  }
  .nfc-field input, .nfc-order-list select {
    width: 100%;
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid rgba(212,175,55,0.35);
    background: rgba(0,0,0,0.25);
    color: #f2e8d0;
    font-family: 'JetBrains Mono', monospace;
    font-size: 13px;
  }
  .nfc-order {
    display: block;
    width: 100%;
    text-align: left;
    padding: 10px 12px;
    margin-bottom: 8px;
    border-radius: 8px;
    border: 1px solid rgba(212,175,55,0.3);
    background: rgba(0,0,0,0.2);
    color: #f2e8d0;
    font-family: 'JetBrains Mono', monospace;
    font-size: 12px;
    cursor: pointer;
  }
  .nfc-order:hover { border-color: var(--gold-2); }
  .nfc-msg {
    font-size: 12px;
    margin-top: 10px;
    text-align: center;
  }
  .nfc-msg.error { color: #e8a; }
  .nfc-msg.ok { color: #9e9; }
  .nfc-back {
    display: inline-block;
    margin-top: 6px;
    font-size: 11px;
    color: rgba(242,232,208,0.5);
    cursor: pointer;
    text-decoration: underline;
  }
  .nfc-driver-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    font-size: 12px;
    padding: 7px 0;
    border-bottom: 1px solid rgba(212,175,55,0.12);
    text-align: left;
  }
  .nfc-driver-row:last-child { border-bottom: none; }
  .nfc-driver-row span:first-child {
    color: rgba(242,232,208,0.55);
    letter-spacing: 0.3px;
  }
  .nfc-driver-row span:last-child {
    color: #f2e8d0;
    font-weight: 600;
    text-align: right;
  }
  .nfc-badge-ok, .nfc-badge-matched { color: #9e9; }
  .nfc-badge-bad, .nfc-badge-mismatch { color: #e88; }
  .nfc-badge-unverified { color: #F2CC6B; }
  .nfc-license-photo {
    display: block;
    width: 100%;
    max-height: 220px;
    object-fit: contain;
    border-radius: 8px;
    border: 1px solid rgba(212,175,55,0.3);
    background: rgba(0,0,0,0.25);
    margin-bottom: 10px;
  }
  .nfc-receipt-link {
    display: block;
    font-size: 11px;
    color: #4ECDC4;
    text-decoration: underline;
    text-align: right;
    margin: -6px 0 6px;
  }
</style>
</head>
<body>
  <div class="card">
    <div class="mark">&#4773;<span>E</span> ETELID</div>

    <?php if ($has_member) : ?>
      <?php if ($photo) : ?>
        <img class="photo" src="<?php echo $photo; ?>" alt="<?php echo $name; ?>">
      <?php else : ?>
        <div class="photo-placeholder"><?php echo strtoupper(substr($name, 0, 1)); ?></div>
      <?php endif; ?>

      <p class="name"><?php echo $name; ?></p>
      <p class="etelid"><?php echo $etelid_number; ?></p>
      <div class="badge">&#10003; Verified Member</div>
      <?php if ($tier) : ?>
        <p class="tier"><?php echo $tier; ?> Tier</p>
      <?php endif; ?>

      <div class="nfc-services">
        <h2>Card Services</h2>

        <div id="nfc-step-menu" class="nfc-step active">
          <p class="nfc-role-label">Service Giver</p>
          <button type="button" class="nfc-btn primary" data-stage="pickup">Confirm Pickup</button>
          <p class="nfc-role-label">Customer</p>
          <button type="button" class="nfc-btn primary" data-stage="delivery">Confirm Delivery Complete</button>
          <p class="nfc-role-label">Driver</p>
          <button type="button" class="nfc-btn primary" data-stage="ride_start">Confirm Rider &amp; Start Trip</button>
          <p class="nfc-role-label">Traffic Officer</p>
          <button type="button" class="nfc-btn primary" data-stage="driver_status">Driver Info</button>
        </div>

        <div id="nfc-step-login" class="nfc-step">
          <div class="nfc-field">
            <label>Your Phone or Etel-ID</label>
            <input type="text" id="nfc-ident" placeholder="09... or EG-2026-00001" autocomplete="username">
          </div>
          <div class="nfc-field">
            <label>Your EtelID Password</label>
            <input type="password" id="nfc-pass" placeholder="Password" autocomplete="current-password">
          </div>
          <button type="button" class="nfc-btn primary" id="nfc-login-submit">Continue</button>
          <span class="nfc-back" data-back="menu">Cancel</span>
          <div id="nfc-login-msg" class="nfc-msg"></div>
        </div>

        <div id="nfc-step-orders" class="nfc-step">
          <div id="nfc-order-list"></div>
          <span class="nfc-back" data-back="menu">Cancel</span>
        </div>

        <div id="nfc-step-done" class="nfc-step">
          <div id="nfc-done-msg" class="nfc-msg"></div>
          <span class="nfc-back" data-back="menu">Back</span>
        </div>
      </div>
    <?php else : ?>
      <p class="invalid">This card is not active.</p>
    <?php endif; ?>

    <div class="footer">ethelmarket.com / EtelId</div>
  </div>
<!-- ETEL AI -- cross-company assistant widget -->
<script src="https://ethelmarket.com/EtelAi/etel-ai-widget.js" data-project="etel-id"></script>
<?php if ($has_member) : ?>
<script>
(function () {
  var AJAX_URL = <?php echo wp_json_encode($nfc_ajax_url); ?>;
  var NONCE = <?php echo wp_json_encode($nfc_nonce); ?>;
  var TOKEN = <?php echo wp_json_encode($nfc_token); ?>;
  var stage = '';
  var actionToken = '';

  function showStep(id) {
    var steps = document.querySelectorAll('.nfc-step');
    for (var i = 0; i < steps.length; i++) steps[i].classList.remove('active');
    document.getElementById(id).classList.add('active');
  }

  function post(action, data) {
    var body = 'action=' + encodeURIComponent(action) + '&nonce=' + encodeURIComponent(NONCE);
    for (var k in data) {
      if (Object.prototype.hasOwnProperty.call(data, k)) {
        body += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
      }
    }
    return fetch(AJAX_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body
    }).then(function (r) { return r.json(); });
  }

  var backs = document.querySelectorAll('.nfc-back');
  for (var b = 0; b < backs.length; b++) {
    backs[b].addEventListener('click', function () {
      document.getElementById('nfc-ident').value = '';
      document.getElementById('nfc-pass').value = '';
      document.getElementById('nfc-login-msg').textContent = '';
      showStep('nfc-step-menu');
    });
  }

  var menuBtns = document.querySelectorAll('#nfc-step-menu .nfc-btn');
  for (var m = 0; m < menuBtns.length; m++) {
    menuBtns[m].addEventListener('click', function () {
      stage = this.getAttribute('data-stage');
      showStep('nfc-step-login');
    });
  }

  document.getElementById('nfc-login-submit').addEventListener('click', function () {
    var ident = document.getElementById('nfc-ident').value.trim();
    var pass = document.getElementById('nfc-pass').value;
    var msg = document.getElementById('nfc-login-msg');
    msg.className = 'nfc-msg';
    msg.textContent = '';
    if (!ident || !pass) { msg.className = 'nfc-msg error'; msg.textContent = 'Enter your phone/Etel-ID and password.'; return; }

    this.disabled = true;
    var self = this;
    post('etelid_nfc_start', { token: TOKEN, stage: stage, identifier: ident, password: pass })
      .then(function (res) {
        self.disabled = false;
        if (!res.success) {
          msg.className = 'nfc-msg error';
          msg.textContent = res.data && res.data.message ? res.data.message : 'Could not continue.';
          return;
        }
        if (res.data.ride_started) {
          var startMsg = document.getElementById('nfc-done-msg');
          startMsg.className = 'nfc-msg ok';
          startMsg.textContent = res.data.message || 'Trip started.';
          showStep('nfc-step-done');
          return;
        }

        if (res.data.driver_status) {
          renderDriverStatus(res.data.driver_status);
          showStep('nfc-step-done');
          return;
        }

        actionToken = res.data.action_token;
        var orders = res.data.orders || [];
        var list = document.getElementById('nfc-order-list');
        list.innerHTML = '';
        if (!orders.length) {
          var p = document.createElement('p');
          p.className = 'nfc-msg';
          p.textContent = 'No matching active orders found for this courier.';
          list.appendChild(p);
        } else {
          orders.forEach(function (o) {
            var el = document.createElement('button');
            el.type = 'button';
            el.className = 'nfc-order';
            el.textContent = (o.order_code || ('#' + o.id)) + '  -  ' + o.from_zone + ' to ' + o.to_zone;
            el.addEventListener('click', function () { confirmOrder(o.id); });
            list.appendChild(el);
          });
        }
        showStep('nfc-step-orders');
      })
      .catch(function () {
        self.disabled = false;
        msg.className = 'nfc-msg error';
        msg.textContent = 'Network error, please try again.';
      });
  });

  function renderDriverStatus(d) {
    var doneMsg = document.getElementById('nfc-done-msg');
    doneMsg.className = 'nfc-msg';
    doneMsg.innerHTML = '';

    if (!d.is_driver) {
      doneMsg.textContent = 'This card is not linked to a driver account.';
      return;
    }

    var wrap = document.createElement('div');

    // Print-style receipt built entirely client-side from data the
    // bridge already returned - EtelTaxi has no uploaded receipt photo
    // for association dues (drivers submit a bank/Telebirr reference
    // number, an admin verifies it - see ServiceController.php's
    // last_payment), so this constructs a real text receipt on the fly
    // instead of linking to an image that doesn't exist.
    function buildReceiptUrl(title, rows) {
      var html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
        + '<title>' + title + ' Receipt</title><style>'
        + 'body{font-family:system-ui,sans-serif;max-width:420px;margin:40px auto;padding:0 20px;color:#111}'
        + 'h1{font-size:18px;border-bottom:2px solid #D4AF37;padding-bottom:10px}'
        + 'table{width:100%;border-collapse:collapse;margin-top:14px}'
        + 'td{padding:8px 0;border-bottom:1px solid #eee;font-size:14px}'
        + 'td:first-child{color:#666;width:45%}'
        + '.foot{margin-top:20px;font-size:11px;color:#999}'
        + '</style></head><body><h1>' + title + '</h1><table>'
        + rows.map(function (r) { return '<tr><td>' + r[0] + '</td><td>' + (r[1] || '-') + '</td></tr>'; }).join('')
        + '</table><div class="foot">ethelmarket.com &middot; Printed ' + new Date().toLocaleString() + '</div></body></html>';
      // A blob: URL, not data: - Chrome blocks top-level/new-tab
      // navigation to data: URLs as an anti-phishing measure, which on
      // some builds tears down the whole tab/browser instead of just
      // failing quietly. blob: URLs open normally.
      var blob = new Blob([html], { type: 'text/html' });
      return URL.createObjectURL(blob);
    }

    if (d.license_photo_url) {
      row('License Photo', 'On file', null, d.license_photo_url, 'View license');
    }

    function row(label, valueText, badgeClass, receiptUrl, receiptLabel) {
      var r = document.createElement('div');
      r.className = 'nfc-driver-row';
      var l = document.createElement('span');
      l.textContent = label;
      var v = document.createElement('span');
      if (badgeClass) v.className = badgeClass;
      v.textContent = valueText;
      r.appendChild(l);
      r.appendChild(v);
      wrap.appendChild(r);
      if (receiptUrl) {
        var link = document.createElement('a');
        link.href = receiptUrl;
        link.target = '_blank';
        link.rel = 'noopener';
        link.className = 'nfc-receipt-link';
        link.textContent = receiptLabel || 'View receipt';
        wrap.appendChild(link);
      }
    }

    row('Vehicle', (d.vehicle_type || '') + (d.vehicle_plate ? ' - ' + d.vehicle_plate : ''));
    if (d.license_name || d.license_masked) {
      row('License', [d.license_name, d.license_masked].filter(Boolean).join(' '));
    }
    row('Identity', d.identity_match ? d.identity_match.charAt(0).toUpperCase() + d.identity_match.slice(1) : 'Unverified',
      'nfc-badge-' + (d.identity_match || 'unverified'));

    // Association dues - live from EtelTaxi's own database, not a
    // Delivery-side field, so this can also legitimately say
    // "unavailable" (EtelTaxi unreachable) or "not registered" (this
    // driver has no EtelTaxi account at all), not just paid/unpaid.
    var a = d.association || {};
    var assocText, assocClass;
    if (a.status === 'active') { assocText = 'Paid / Active'; assocClass = 'nfc-badge-ok'; }
    else if (a.status === 'grace_period') { assocText = 'Due (grace period until ' + (a.window_end || '?') + ')'; assocClass = 'nfc-badge-unverified'; }
    else if (a.status === 'overdue') { assocText = 'OVERDUE' + (a.penalty_owed ? ' - ETB ' + parseFloat(a.penalty_owed).toFixed(2) + ' penalty' : ''); assocClass = 'nfc-badge-bad'; }
    else if (a.status === 'not_registered') { assocText = 'Not registered with EtelTaxi'; assocClass = 'nfc-badge-unverified'; }
    else { assocText = 'Unavailable'; assocClass = 'nfc-badge-unverified'; }
    var assocReceiptUrl = null;
    if (a.last_payment) {
      var lp = a.last_payment;
      assocReceiptUrl = buildReceiptUrl('Association Due', [
        ['Association', (a.name_en || a.name_am || '-')],
        ['Period', lp.period_month],
        ['Amount', 'ETB ' + parseFloat(lp.amount || 0).toFixed(2) + (lp.penalty_amount > 0 ? ' + ETB ' + parseFloat(lp.penalty_amount).toFixed(2) + ' penalty' : '')],
        ['Method', lp.payment_method],
        ['Reference', lp.reference_number],
        ['Verified', lp.verified_at],
      ]);
    }
    row('Association Due', assocText, assocClass, assocReceiptUrl);

    function docRow(label, doc) {
      doc = doc || {};
      var text, cls;
      if (doc.status === 'valid') { text = 'Valid until ' + doc.expiry_date; cls = 'nfc-badge-ok'; }
      else if (doc.status === 'expired') { text = 'EXPIRED (' + doc.expiry_date + ')'; cls = 'nfc-badge-bad'; }
      else { text = 'Not on file'; cls = 'nfc-badge-unverified'; }
      row(label, text, cls, doc.receipt_url);
    }
    docRow('Insurance', d.insurance);
    docRow('Technical Inspection', d.technical_inspection);
    docRow('Road Fund', d.road_fund);

    doneMsg.appendChild(wrap);
  }

  function confirmOrder(orderId) {
    post('etelid_nfc_confirm', { action_token: actionToken, order_id: orderId })
      .then(function (res) {
        var doneMsg = document.getElementById('nfc-done-msg');
        if (res.success) {
          doneMsg.className = 'nfc-msg ok';
          doneMsg.textContent = res.data && res.data.message ? res.data.message : 'Confirmed.';
        } else {
          doneMsg.className = 'nfc-msg error';
          doneMsg.textContent = res.data && res.data.message ? res.data.message : 'Could not confirm.';
        }
        showStep('nfc-step-done');
      })
      .catch(function () {
        var doneMsg = document.getElementById('nfc-done-msg');
        doneMsg.className = 'nfc-msg error';
        doneMsg.textContent = 'Network error, please try again.';
        showStep('nfc-step-done');
      });
  }
})();
</script>
<?php endif; ?>
</body>
</html>
