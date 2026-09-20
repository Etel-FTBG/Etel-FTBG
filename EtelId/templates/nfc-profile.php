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
          <p class="nfc-role-label">Collect Payment</p>
          <button type="button" class="nfc-btn primary" data-stage="etelpay_charge">Charge via EtelPay</button>
          <p class="nfc-role-label">Pay Driver / Cardholder</p>
          <button type="button" class="nfc-btn primary" data-stage="etelpay_payout">Pay Out via EtelPay</button>
        </div>

        <div id="nfc-step-charge-amount" class="nfc-step">
          <div class="nfc-field">
            <label>Amount (ETB)</label>
            <input type="number" id="nfc-charge-amount" placeholder="0.00" min="0.01" step="0.01" inputmode="decimal">
          </div>
          <div class="nfc-field">
            <label>Note (optional)</label>
            <input type="text" id="nfc-charge-note" placeholder="What is this for?">
          </div>
          <button type="button" class="nfc-btn primary" id="nfc-charge-amount-next">Continue</button>
          <span class="nfc-back" data-back="menu">Cancel</span>
          <div id="nfc-charge-amount-msg" class="nfc-msg"></div>
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
      document.getElementById('nfc-charge-amount').value = '';
      document.getElementById('nfc-charge-note').value = '';
      document.getElementById('nfc-charge-amount-msg').textContent = '';
      showStep('nfc-step-menu');
    });
  }

  var menuBtns = document.querySelectorAll('#nfc-step-menu .nfc-btn');
  for (var m = 0; m < menuBtns.length; m++) {
    menuBtns[m].addEventListener('click', function () {
      stage = this.getAttribute('data-stage');
      // Both EtelPay stages need an amount first, then whoever is
      // filling in this form types their own EtelID login - that
      // login both identifies AND authorizes it, since it's their own
      // money leaving either way (etelpay_charge: they're paying the
      // tapped card's owner; etelpay_payout: same mechanism, different
      // real-world scenario).
      var etelpayStages = (stage === 'etelpay_charge' || stage === 'etelpay_payout');
      showStep(etelpayStages ? 'nfc-step-charge-amount' : 'nfc-step-login');
    });
  }

  var chargeAmount = 0, chargeNote = '';
  document.getElementById('nfc-charge-amount-next').addEventListener('click', function () {
    var amtEl = document.getElementById('nfc-charge-amount');
    var msg = document.getElementById('nfc-charge-amount-msg');
    var amt = parseFloat(amtEl.value);
    if (!amt || amt <= 0) { msg.className = 'nfc-msg error'; msg.textContent = 'Enter an amount greater than zero.'; return; }
    chargeAmount = amt;
    chargeNote = document.getElementById('nfc-charge-note').value.trim();
    msg.className = 'nfc-msg';
    msg.textContent = '';
    showStep('nfc-step-login');
  });

  document.getElementById('nfc-login-submit').addEventListener('click', function () {
    var ident = document.getElementById('nfc-ident').value.trim();
    var pass = document.getElementById('nfc-pass').value;
    var msg = document.getElementById('nfc-login-msg');
    msg.className = 'nfc-msg';
    msg.textContent = '';
    if (!ident || !pass) { msg.className = 'nfc-msg error'; msg.textContent = 'Enter your phone/Etel-ID and password.'; return; }

    this.disabled = true;
    var self = this;
    var postData = { token: TOKEN, stage: stage, identifier: ident, password: pass };
    if (stage === 'etelpay_charge' || stage === 'etelpay_payout') { postData.amount = chargeAmount; postData.note = chargeNote; }
    post('etelid_nfc_start', postData)
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

        if (res.data.payout_done || res.data.charge_done) {
          var payMsg = document.getElementById('nfc-done-msg');
          payMsg.className = 'nfc-msg ok';
          payMsg.textContent = res.data.message || 'Payment sent.';
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
    function buildReceiptUrl(title, rows, badgeText, badgeKind) {
      // Full gold/carbon branded receipt - same design language as the
      // card itself (holo strip, gold gradient title, badge pills),
      // not a bare unstyled table - this is handed to a traffic
      // officer or kept by the driver as a real record.
      var badgeColors = {
        ok:  ['rgba(46,204,113,.14)', '#7ee8a8', 'rgba(46,204,113,.4)'],
        bad: ['rgba(231,76,60,.14)',  '#ff8a7a', 'rgba(231,76,60,.4)'],
        warn:['rgba(242,204,107,.14)','#F2CC6B', 'rgba(242,204,107,.4)']
      };
      var bc = badgeColors[badgeKind] || badgeColors.warn;
      var badgeHtml = badgeText
        ? '<div style="display:inline-block;padding:8px 20px;border-radius:30px;background:' + bc[0] + ';color:' + bc[1] + ';border:1px solid ' + bc[2] + ';font-weight:800;font-size:14px;letter-spacing:.4px;text-transform:uppercase;margin-bottom:22px">' + badgeText + '</div>'
        : '';
      var html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
        + '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        + '<title>' + title + ' Receipt</title>'
        + '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500;700&family=JetBrains+Mono:wght@500;700&display=swap">'
        + '<style>'
        + '*{box-sizing:border-box;margin:0;padding:0}'
        + 'body{font-family:"DM Sans",system-ui,sans-serif;background:'
        +   'radial-gradient(1100px 600px at 15% -10%, rgba(212,175,55,.14), transparent 60%),'
        +   'radial-gradient(900px 500px at 110% 10%, rgba(212,175,55,.1), transparent 55%),#000;'
        +   'min-height:100vh;padding:36px 18px;color:#fff}'
        + '.receipt{max-width:560px;margin:0 auto;background:linear-gradient(165deg,#161616,#0d0d0d);'
        +   'border:1px solid rgba(212,175,55,.32);border-radius:22px;overflow:hidden;'
        +   'box-shadow:0 1px 0 rgba(255,255,255,.04) inset, 0 30px 60px -20px rgba(0,0,0,.7)}'
        + '.holo{height:4px;background:linear-gradient(90deg,#ff6b6b,#ffd93d,#6bcb77,#4d96ff,#ff6b6b,#ffd93d,#6bcb77,#4d96ff);background-size:300%;animation:holo 3s linear infinite}'
        + '@keyframes holo{to{background-position:300% 0}}'
        + '.body{padding:40px 36px 32px;text-align:center}'
        + '.mark{font-family:"Syne",sans-serif;font-weight:800;font-size:13px;letter-spacing:3px;color:#8B6914;text-transform:uppercase;margin-bottom:6px}'
        + 'h1{font-family:"Syne",sans-serif;font-weight:800;font-size:26px;'
        +   'background:linear-gradient(100deg,#C8960C,#D4AF37 45%,#fff6d9 55%,#D4AF37);'
        +   '-webkit-background-clip:text;background-clip:text;color:transparent;margin-bottom:22px}'
        + 'table{width:100%;border-collapse:collapse;text-align:left}'
        + 'td{padding:14px 4px;border-bottom:1px solid rgba(212,175,55,.16);font-size:15px}'
        + 'td:first-child{color:#9a9a9a;font-weight:600;width:42%}'
        + 'td:last-child{font-family:"JetBrains Mono",monospace;font-weight:700;letter-spacing:.2px;text-align:right}'
        + 'tr:last-child td{border-bottom:none}'
        + '.foot{padding:18px 36px 26px;text-align:center;font-size:11px;color:#7a7a7a;border-top:1px solid rgba(212,175,55,.16)}'
        + '.foot strong{color:#D4AF37}'
        + '</style></head><body>'
        + '<div class="receipt"><div class="holo"></div><div class="body">'
        + '<div class="mark">ETEL GROUP &middot; OFFICIAL RECEIPT</div>'
        + '<h1>' + title + '</h1>'
        + badgeHtml
        + '<table>' + rows.map(function (r) { return '<tr><td>' + r[0] + '</td><td>' + (r[1] || '&mdash;') + '</td></tr>'; }).join('') + '</table>'
        + '</div><div class="foot">ethelmarket.com &middot; Printed <strong>' + new Date().toLocaleString() + '</strong></div></div>'
        + '</body></html>';
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
      var badgeKind = a.status === 'active' ? 'ok' : (a.status === 'overdue' ? 'bad' : 'warn');
      assocReceiptUrl = buildReceiptUrl('Association Due', [
        ['Association', (a.association ? (a.association.name_en || a.association.name_am) : null)],
        ['Period', lp.period_month],
        ['Amount', 'ETB ' + parseFloat(lp.amount || 0).toFixed(2) + (lp.penalty_amount > 0 ? ' + ETB ' + parseFloat(lp.penalty_amount).toFixed(2) + ' penalty' : '')],
        ['Method', lp.payment_method],
        ['Reference', lp.reference_number],
        ['Verified', lp.verified_at],
      ], assocText, badgeKind);
    }
    row('Association Due', assocText, assocClass, assocReceiptUrl);

    // EtelPay wallet - live from EtelPay's own database, same pattern
    // as Association Due. Deliberately shows status only, never a
    // balance (see etelpay_bridge.php's own comment on why) - this is
    // an identity/compliance check, not a financial one.
    var w = d.wallet || {};
    var walletText, walletClass;
    if (w.status === 'active') { walletText = w.kyc_status === 'verified' ? 'Active - KYC Verified' : 'Active - KYC Pending'; walletClass = w.kyc_status === 'verified' ? 'nfc-badge-ok' : 'nfc-badge-unverified'; }
    else if (w.status === 'not_registered') { walletText = 'No EtelPay wallet'; walletClass = 'nfc-badge-unverified'; }
    else if (w.status === 'unavailable') { walletText = 'Unavailable'; walletClass = 'nfc-badge-unverified'; }
    else { walletText = w.status ? w.status.charAt(0).toUpperCase() + w.status.slice(1) : 'Unavailable'; walletClass = 'nfc-badge-bad'; }
    row('EtelPay Wallet', walletText, walletClass);

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
