<?php
declare(strict_types=1);
use App\Core\View;
use App\Core\Auth;

$locale    = $locale ?? ($GLOBALS['__i18n']->locale());
$user      = $user ?? Auth::getInstance()->user() ?? [];
$cart      = $cart ?? [];
$total     = $total ?? 0.0;
$shipping  = $shipping ?? 30.0;
$grand     = $total + $shipping;
$flash     = $flash ?? null;
$token     = $csrfToken ?? Auth::getInstance()->csrfToken();
$addresses = $addresses ?? [];
$payments  = $GLOBALS['__config']['payments'] ?? [];
ob_start();
?>
<style>
.checkout-page { padding: 2rem 0 4rem; }
.checkout-heading {
  font-size: 1.6rem; font-weight: 900; margin-bottom: 1.75rem;
  background: linear-gradient(135deg,#FFE066,#D4AF37);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.checkout-grid { display: grid; grid-template-columns: 1fr 360px; gap: 2rem; align-items: start; }
@media(max-width:960px){ .checkout-grid { grid-template-columns: 1fr; } }

.checkout-section {
  background: linear-gradient(145deg,#1c1208,#130d05);
  border: 1px solid rgba(212,175,55,.18);
  border-radius: 16px; padding: 1.5rem 1.75rem; margin-bottom: 1.25rem;
}
.section-head {
  display: flex; align-items: center; gap: .65rem;
  font-size: 1rem; font-weight: 800; color: #D4AF37; margin-bottom: 1.25rem;
  padding-bottom: .75rem; border-bottom: 1px solid rgba(212,175,55,.12);
}
.section-head i { font-size: 1.1rem; }

.form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
@media(max-width:520px){ .form-row-2 { grid-template-columns: 1fr; } }

.form-group { margin-bottom: 1rem; }
.form-label {
  display: block; font-size: .78rem; font-weight: 700; color: #C9B88A;
  margin-bottom: .4rem; text-transform: uppercase; letter-spacing: .05em;
}
.form-label span { color: #EF4444; }
.input-wrap { position: relative; }
.input-wrap i.icon {
  position: absolute; left: .85rem; top: 50%; transform: translateY(-50%);
  color: #7A6840; font-size: .9rem; pointer-events: none;
}
.form-input, .form-select {
  width: 100%; padding: .65rem .9rem .65rem 2.4rem;
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(212,175,55,.18); border-radius: 12px;
  color: #F7EFD9; font-size: .9rem; font-family: inherit; outline: none;
  transition: border-color .2s, box-shadow .2s;
}
.form-select { padding-left: .9rem; appearance: none; }
.form-input::placeholder { color: #5C4209; }
.form-input:focus, .form-select:focus {
  border-color: #D4AF37; box-shadow: 0 0 0 3px rgba(212,175,55,.12);
}
.form-input.no-icon { padding-left: .9rem; }
textarea.form-input { min-height: 80px; resize: vertical; padding-top: .65rem; }

/* Payment methods */
.payment-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .65rem; }
@media(max-width:520px){ .payment-grid { grid-template-columns: 1fr; } }
.payment-opt { position: relative; }
.payment-opt input { position: absolute; opacity: 0; pointer-events: none; }
.payment-label {
  display: flex; align-items: center; gap: .75rem; padding: .9rem 1rem;
  background: rgba(255,255,255,.03); border: 1.5px solid rgba(212,175,55,.15);
  border-radius: 12px; cursor: pointer; transition: all .2s;
}
.payment-label:hover { border-color: rgba(212,175,55,.4); background: rgba(212,175,55,.05); }
.payment-opt input:checked + .payment-label {
  border-color: #D4AF37; background: rgba(212,175,55,.1);
  box-shadow: 0 0 0 3px rgba(212,175,55,.1);
}
.payment-icon { font-size: 1.5rem; line-height: 1; }
.payment-name { font-weight: 700; font-size: .88rem; color: #F7EFD9; }
.payment-sub  { font-size: .72rem; color: #7A6840; }
.payment-disabled { opacity: .4; pointer-events: none; }

/* Order summary sidebar */
.order-box {
  background: linear-gradient(145deg,#1c1208,#130d05);
  border: 1px solid rgba(212,175,55,.22); border-radius: 16px;
  padding: 1.5rem; position: sticky; top: 80px;
}
.order-box h3 { font-size:1rem; font-weight:800; color:#D4AF37; margin-bottom:1.25rem; }
.order-item {
  display: flex; gap: .75rem; align-items: center;
  padding: .6rem 0; border-bottom: 1px solid rgba(212,175,55,.07);
}
.order-item:last-of-type { border-bottom: none; }
.order-item-img {
  width: 48px; height: 48px; border-radius: 8px; overflow: hidden;
  background: #1a1208; flex-shrink: 0;
}
.order-item-img img { width:100%; height:100%; object-fit:cover; }
.order-item-name { font-size: .85rem; font-weight: 600; color: #C9B88A; line-height: 1.3; }
.order-item-meta { font-size: .75rem; color: #7A6840; margin-top: .1rem; }
.order-item-price { margin-left: auto; font-weight: 700; color: #D4AF37; font-size: .88rem; white-space: nowrap; }

.sum-row {
  display: flex; justify-content: space-between; align-items: center;
  font-size: .88rem; color: #C9B88A; padding: .45rem 0;
}
.sum-row.total {
  font-size: 1.05rem; font-weight: 900; color: #F7EFD9;
  border-top: 1px solid rgba(212,175,55,.2); margin-top: .5rem; padding-top: .9rem;
}
.sum-row.total span:last-child { color: #FFE066; }

.place-btn {
  width: 100%; padding: .9rem; margin-top: 1.25rem;
  background: linear-gradient(135deg,#FFE066,#D4AF37,#B8902E);
  color: #080604; font-weight: 800; font-size: 1rem;
  border: none; border-radius: 12px; cursor: pointer;
  transition: opacity .2s, transform .15s, box-shadow .2s;
  display: flex; align-items: center; justify-content: center; gap: .5rem;
}
.place-btn:hover { opacity:.92; transform:translateY(-1px); box-shadow:0 8px 28px rgba(212,175,55,.35); }
.place-btn:disabled { opacity:.5; cursor:not-allowed; transform:none; }

.secure-note {
  display: flex; align-items: center; gap: .4rem; justify-content: center;
  font-size: .75rem; color: #5C4209; margin-top: .75rem;
}

.flash-msg {
  display: flex; align-items: flex-start; gap: .6rem;
  padding: .8rem 1rem; border-radius: 10px; font-size: .88rem; margin-bottom: 1.25rem;
  border: 1px solid transparent;
}
.flash-error { background: rgba(239,68,68,.1); border-color: rgba(239,68,68,.3); color: #FCA5A5; }
</style>

<div class="container checkout-page">
  <h1 class="checkout-heading"><i class="bi bi-bag-check"></i> Checkout</h1>

  <?php if ($flash && $flash['type'] === 'error'): ?>
    <div class="flash-msg flash-error">
      <i class="bi bi-exclamation-circle"></i> <?= e($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <form method="POST" action="<?= View::url('/checkout/place') ?>" id="checkout-form">
    <?= csrf() ?>

    <div class="checkout-grid">
      <!-- Left: forms -->
      <div>

        <!-- Shipping Address -->
        <div class="checkout-section">
          <div class="section-head" style="display:flex;align-items:center;justify-content:space-between">
            <span><i class="bi bi-geo-alt"></i> Shipping Address</span>
            <?php if (!empty($addresses)): ?>
              <select onchange="autofillAddress(this)" style="background:rgba(212,175,55,.06);border:1.5px solid rgba(212,175,55,.2);border-radius:10px;padding:.3rem .75rem;color:var(--text-dim,#C9B88A);font-size:.78rem;font-family:inherit;outline:none">
                <option value="">Use saved address...</option>
                <?php foreach ($addresses as $i => $a): ?>
                  <option value="<?= $i ?>"><?= e($a['label'].' -- '.$a['city']) ?></option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
          </div>
          <?php if (!empty($addresses)): ?>
          <script>
          const __savedAddrs = <?= json_encode(array_values($addresses)) ?>;
          function autofillAddress(sel){
            const a=__savedAddrs[sel.value]; if(!a)return;
            document.getElementById('full_name').value=a.full_name||'';
            document.getElementById('phone').value=a.phone||'';
            const city=document.getElementById('city');
            [...city.options].forEach(o=>{o.selected=o.value===a.city});
            document.getElementById('address').value=[a.sub_city,a.wereda?'Wereda '+a.wereda:'',a.address_detail].filter(Boolean).join(', ');
          }
          </script>
          <?php endif; ?>
          <div class="form-row-2">
            <div class="form-group">
              <label class="form-label" for="full_name">Full Name <span>*</span></label>
              <div class="input-wrap">
                <i class="bi bi-person icon"></i>
                <input type="text" id="full_name" name="full_name" class="form-input" required
                       value="<?= e($user['full_name'] ?? '') ?>" placeholder="Abebe Kebede">
              </div>
            </div>
            <div class="form-group">
              <label class="form-label" for="phone">Phone <span>*</span></label>
              <div class="input-wrap">
                <i class="bi bi-phone icon"></i>
                <input type="tel" id="phone" name="phone" class="form-input" required
                       value="<?= e($user['phone'] ?? '') ?>" placeholder="+251 9X XXX XXXX">
              </div>
            </div>
          </div>
          <div class="form-row-2">
            <div class="form-group">
              <label class="form-label" for="city">City <span>*</span></label>
              <div class="input-wrap">
                <i class="bi bi-building icon"></i>
                <select id="city" name="city" class="form-input no-icon" style="padding-left:.9rem" required>
                  <option value="">Select city...</option>
                  <?php
                  $cities = ['Addis Ababa','Dire Dawa','Mekelle','Gondar','Bahir Dar','Hawassa','Adama','Jimma','Dessie','Jijiga'];
                  foreach ($cities as $c):
                  ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label" for="address">Specific Address <span>*</span></label>
              <div class="input-wrap">
                <i class="bi bi-map icon"></i>
                <input type="text" id="address" name="address" class="form-input" required placeholder="Kebele, area, landmark...">
              </div>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label" for="notes">Order Notes (optional)</label>
            <textarea id="notes" name="notes" class="form-input no-icon" placeholder="Any special instructions for delivery..."></textarea>
          </div>
        </div>

        <!-- Payment Method -->
        <div class="checkout-section">
          <div class="section-head"><i class="bi bi-credit-card"></i> Payment Method</div>
          <div class="payment-grid">

            <!-- ኢE Pay wallet (real, verified transfer) -->
            <label class="payment-opt <?= empty($etelPayMerchantPhone) ? 'payment-disabled' : '' ?>">
              <input type="radio" name="payment" id="pay-etelpay" value="etel_pay"
                     <?= !empty($etelPayMerchantPhone) ? 'checked' : '' ?>
                     <?= empty($etelPayMerchantPhone) ? 'disabled' : '' ?>>
              <span class="payment-label">
                <span class="payment-icon">🅴</span>
                <span>
                  <div class="payment-name">ኢE Pay Wallet</div>
                  <div class="payment-sub">Held safely until you confirm receipt -- <a href="<?= View::url('/protection-policy') ?>" target="_blank" style="color:var(--eg-gold,#D4AF37);text-decoration:underline">buyer protection</a></div>
                </span>
              </span>
            </label>

            <!-- Chapa (credit/debit cards) -->
            <label class="payment-opt <?= !($payments['chapa']['enabled'] ?? false) ? 'payment-disabled' : '' ?>">
              <input type="radio" name="payment" value="chapa"
                     <?= (($payments['chapa']['enabled'] ?? false) && empty($etelPayMerchantPhone)) ? 'checked' : '' ?>
                     <?= !($payments['chapa']['enabled'] ?? false) ? 'disabled' : '' ?>>
              <span class="payment-label">
                <span class="payment-icon">💳</span>
                <span>
                  <div class="payment-name">Chapa</div>
                  <div class="payment-sub">Cards / Bank transfer</div>
                </span>
              </span>
            </label>

            <!-- Telebirr -->
            <label class="payment-opt <?= !($payments['telebirr']['enabled'] ?? false) ? 'payment-disabled' : '' ?>">
              <input type="radio" name="payment" value="telebirr"
                     <?= !($payments['telebirr']['enabled'] ?? false) ? 'disabled' : '' ?>>
              <span class="payment-label">
                <span class="payment-icon">📱</span>
                <span>
                  <div class="payment-name">Telebirr</div>
                  <div class="payment-sub">Ethio Telecom wallet</div>
                </span>
              </span>
            </label>

            <!-- CBE Birr -->
            <label class="payment-opt <?= !($payments['cbe']['enabled'] ?? false) ? 'payment-disabled' : '' ?>">
              <input type="radio" name="payment" value="cbe"
                     <?= !($payments['cbe']['enabled'] ?? false) ? 'disabled' : '' ?>>
              <span class="payment-label">
                <span class="payment-icon">🏦</span>
                <span>
                  <div class="payment-name">CBE Birr</div>
                  <div class="payment-sub">Commercial Bank wallet</div>
                </span>
              </span>
            </label>

            <!-- Cash on Delivery -->
            <label class="payment-opt">
              <input type="radio" name="payment" value="cod"
                     <?= (!($payments['chapa']['enabled'] ?? false) && empty($etelPayMerchantPhone)) ? 'checked' : '' ?>>
              <span class="payment-label">
                <span class="payment-icon">💵</span>
                <span>
                  <div class="payment-name">Cash on Delivery</div>
                  <div class="payment-sub">Pay when you receive</div>
                </span>
              </span>
            </label>

          </div>
        </div>

      </div>

      <!-- Right: order summary -->
      <div class="order-box">
        <h3><i class="bi bi-receipt"></i> Your Order</h3>

        <?php foreach ($cart as $item): ?>
          <div class="order-item">
            <div class="order-item-img">
              <?php if ($item['image']): ?>
                <img src="<?= e($item['image']) ?>" alt="<?= e($item['name_en']) ?>">
              <?php else: ?>
                <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#5C4209;font-size:1.4rem">📦</div>
              <?php endif; ?>
            </div>
            <div style="flex:1">
              <div class="order-item-name"><?= e($locale === 'am' && $item['name_am'] ? $item['name_am'] : $item['name_en']) ?></div>
              <div class="order-item-meta">Qty: <?= (int)$item['qty'] ?> · Br <?= number_format($item['price'], 2) ?></div>
            </div>
            <div class="order-item-price">Br <?= number_format($item['price'] * $item['qty'], 2) ?></div>
          </div>
        <?php endforeach; ?>

        <div style="display:flex;gap:8px;margin:1rem 0 .75rem">
          <input type="text" id="coupon-code" name="coupon_code" class="form-input" style="flex:1;text-transform:uppercase" placeholder="Promo code">
          <button type="button" class="btn-outline" id="coupon-apply-btn" onclick="applyCoupon()" style="padding:0 1rem;white-space:nowrap">Apply</button>
        </div>
        <div id="coupon-msg" style="font-size:.78rem;margin-bottom:.5rem;min-height:1rem"></div>
        <input type="hidden" name="coupon_discount" id="coupon-discount-input" value="0">

        <div style="margin-top:1rem;padding-top:.75rem;border-top:1px solid rgba(212,175,55,.1)">
          <div class="sum-row"><span>Subtotal</span><span>Br <?= number_format($total, 2) ?></span></div>
          <div class="sum-row"><span>Shipping</span><span>Br <?= number_format($shipping, 2) ?></span></div>
          <div class="sum-row" id="coupon-discount-row" style="display:none;color:#6ee7a0"><span>Discount</span><span id="coupon-discount-amt">-Br 0.00</span></div>
          <div class="sum-row total"><span>Total</span><span id="order-total-display">Br <?= number_format($grand, 2) ?></span></div>
        </div>

        <input type="hidden" name="wallet_escrow_refs" id="wallet-escrow-refs" value="">
        <input type="hidden" name="wallet_shipping_tx_ref" id="wallet-shipping-tx-ref" value="">
        <div id="etelpay-status" style="font-size:.8rem;margin-bottom:.5rem;min-height:1.1rem"></div>
        <button type="submit" class="place-btn" id="place-btn">
          <i class="bi bi-lock-fill"></i> Place Order -- Br <?= number_format($grand, 2) ?>
        </button>
        <div class="secure-note"><i class="bi bi-shield-check"></i> Secure & encrypted checkout</div>
      </div>

    </div><!-- .checkout-grid -->
  </form>
</div>

<script>
// Etel Pay wallet's own live API -- the customer's PIN goes straight from
// this browser to Etel Pay, never through EtelGebeya's backend. Only the
// resulting tx_ref is sent here, and the backend independently verifies
// it with Etel Pay before ever marking the order paid.
const ETEL_PAY_API = 'https://ethelmarket.com/Etel.Pay/wallet/api/v2_verify.php';
const ETELPAY_MERCHANT_PHONE = <?= json_encode($etelPayMerchantPhone ?? '') ?>;
const ETELGEBEYA_USER_PHONE  = <?= json_encode($user['phone'] ?? '') ?>;
const CHECKOUT_SUBTOTAL = <?= json_encode($total) ?>;
const CHECKOUT_SHIPPING_BASE = <?= json_encode($shipping) ?>;
let CHECKOUT_GRAND_TOTAL   = <?= json_encode($grand) ?>;
let CHECKOUT_DISCOUNT = 0;
// Per-seller subtotal + destination phone, in the SAME deterministic
// order (seller_id ascending) the server uses to re-derive amounts --
// see CheckoutController::sellerBreakdown()/splitDiscount(). The wallet
// escrow send debits the customer and holds a "Pending" line for each
// seller directly, rather than paying one combined merchant total.
const CHECKOUT_SELLERS = <?= json_encode($sellerBreakdown ?? []) ?>;

function splitDiscountPerSeller(sellers, totalSubtotal, discount) {
  const out = {};
  if (totalSubtotal <= 0 || discount <= 0) {
    sellers.forEach(s => out[s.seller_id] = Math.round(s.subtotal * 100) / 100);
    return out;
  }
  let remaining = discount;
  sellers.forEach((s, i) => {
    let share;
    if (i === sellers.length - 1) { share = remaining; }
    else { share = Math.round(discount * s.subtotal / totalSubtotal * 100) / 100; remaining -= share; }
    out[s.seller_id] = Math.round((s.subtotal - share) * 100) / 100;
  });
  return out;
}

async function applyCoupon() {
  const codeEl = document.getElementById('coupon-code');
  const msgEl = document.getElementById('coupon-msg');
  const btn = document.getElementById('coupon-apply-btn');
  const code = codeEl.value.trim();
  if (!code) { msgEl.style.color = '#ef4444'; msgEl.textContent = 'Enter a code.'; return; }

  btn.disabled = true;
  msgEl.style.color = '#999'; msgEl.textContent = 'Checking…';

  try {
    const res = await fetch('<?= View::url('/checkout/apply-coupon') ?>', {
      method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'code=' + encodeURIComponent(code),
    }).then(r => r.json());

    if (!res.ok) {
      msgEl.style.color = '#ef4444'; msgEl.textContent = res.message || 'Invalid coupon.';
      document.getElementById('coupon-discount-row').style.display = 'none';
      document.getElementById('coupon-discount-input').value = '0';
      CHECKOUT_DISCOUNT = 0;
      CHECKOUT_GRAND_TOTAL = CHECKOUT_SUBTOTAL + CHECKOUT_SHIPPING_BASE;
    } else {
      msgEl.style.color = '#6ee7a0'; msgEl.textContent = res.message || 'Coupon applied!';
      const shipping = res.free_shipping ? 0 : CHECKOUT_SHIPPING_BASE;
      CHECKOUT_DISCOUNT = res.discount;
      CHECKOUT_GRAND_TOTAL = Math.max(0, CHECKOUT_SUBTOTAL + shipping - res.discount);
      document.getElementById('coupon-discount-input').value = res.discount;
      document.getElementById('coupon-discount-row').style.display = 'flex';
      document.getElementById('coupon-discount-amt').textContent = '-Br ' + res.discount.toFixed(2) + (res.free_shipping ? ' + free shipping' : '');
    }
    document.getElementById('order-total-display').textContent = 'Br ' + CHECKOUT_GRAND_TOTAL.toFixed(2);
    document.getElementById('place-btn').innerHTML = '<i class="bi bi-lock-fill"></i> Place Order -- Br ' + CHECKOUT_GRAND_TOTAL.toFixed(2);
  } catch (e) {
    msgEl.style.color = '#ef4444'; msgEl.textContent = 'Network error, try again.';
  } finally {
    btn.disabled = false;
  }
}

document.getElementById('checkout-form').addEventListener('submit', function(e) {
  const payment = document.querySelector('input[name=payment]:checked')?.value;
  const btn = document.getElementById('place-btn');
  const statusEl = document.getElementById('etelpay-status');

  if (payment === 'etel_pay' && !document.getElementById('wallet-escrow-refs').value) {
    e.preventDefault();
    payWithEtelPay(btn, statusEl);
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Placing order...';
});

async function payWithEtelPay(btn, statusEl) {
  if (!ETELGEBEYA_USER_PHONE) { statusEl.textContent = 'Could not find your phone number on file.'; statusEl.style.color = '#ef4444'; return; }
  if (!CHECKOUT_SELLERS.length) { statusEl.textContent = 'Your cart has no items to pay for.'; statusEl.style.color = '#ef4444'; return; }
  const pin = prompt('Enter your ኢE Pay PIN to pay Br ' + CHECKOUT_GRAND_TOTAL.toFixed(2));
  if (!pin) return;

  btn.disabled = true;
  statusEl.style.color = '';
  try {
    statusEl.textContent = 'Signing in to ኢE Pay…';
    // Uses the shared EtelID password (not a separate Etel Pay PIN) --
    // most shoppers never set up a dedicated Etel Pay wallet PIN, only
    // their one EtelID, and this endpoint auto-provisions/uses their
    // wallet via that same identity (same pattern as ETEL Delivery).
    const loginRes = await fetch(ETEL_PAY_API, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'ep_login_etelid', phone: ETELGEBEYA_USER_PHONE, pin: pin }),
    }).then(r => r.json());
    if (!loginRes.success || !loginRes.data || !loginRes.data.token) {
      throw new Error((loginRes.data && loginRes.data.message) || 'ኢE Pay sign-in failed. Check your EtelID password.');
    }
    const authHeader = { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + loginRes.data.token };

    // One escrow hold per seller in the cart -- each is debited from the
    // customer now and shows "Pending" in both wallets until the seller's
    // items are confirmed delivered. This is what actually makes the
    // payment a real hold instead of an instant transfer.
    const perSellerAmounts = splitDiscountPerSeller(CHECKOUT_SELLERS, CHECKOUT_SUBTOTAL, CHECKOUT_DISCOUNT);
    const escrowRefs = [];
    for (const s of CHECKOUT_SELLERS) {
      const amount = perSellerAmounts[s.seller_id] || 0;
      if (amount <= 0) continue;
      statusEl.textContent = 'Holding payment for ' + amount.toFixed(2) + ' ETB…';
      const holdRes = await fetch(ETEL_PAY_API, {
        method: 'POST', headers: authHeader,
        body: JSON.stringify({ action: 'ep_escrow_send', receiver: s.phone, amount: amount, note: 'Etel Gebeya order' }),
      }).then(r => r.json());
      if (!holdRes.success || !holdRes.data || !holdRes.data.tx_ref) {
        throw new Error((holdRes.data && holdRes.data.message) || 'ኢE Pay hold failed for one of the sellers in your cart.');
      }
      escrowRefs.push({ seller_id: s.seller_id, tx_ref: holdRes.data.tx_ref });
    }
    document.getElementById('wallet-escrow-refs').value = JSON.stringify(escrowRefs);

    // Shipping isn't owed to any one seller, so it's still a normal
    // completed transfer to the platform's own wallet (unless a coupon
    // made it free).
    const shippingDue = CHECKOUT_GRAND_TOTAL - Object.values(perSellerAmounts).reduce((a,b) => a+b, 0);
    if (shippingDue > 0.009) {
      statusEl.textContent = 'Sending shipping fee…';
      const sendRes = await fetch(ETEL_PAY_API, {
        method: 'POST', headers: authHeader,
        body: JSON.stringify({ action: 'ep_send', receiver: ETELPAY_MERCHANT_PHONE, amount: Math.round(shippingDue*100)/100, currency: 'ETB', note: 'Etel Gebeya order shipping' }),
      }).then(r => r.json());
      if (!sendRes.success || !sendRes.data || !sendRes.data.tx_ref) {
        throw new Error((sendRes.data && sendRes.data.message) || 'ኢE Pay transfer failed.');
      }
      document.getElementById('wallet-shipping-tx-ref').value = sendRes.data.tx_ref;
    }

    statusEl.style.color = '#10b981';
    statusEl.textContent = 'Payment held -- placing your order…';
    document.getElementById('checkout-form').submit();
  } catch (err) {
    statusEl.style.color = '#ef4444';
    statusEl.textContent = err.message || 'Payment failed. Please try again.';
    btn.disabled = false;
  }
}
</script>
<?php
$__content = ob_get_clean();
echo View::capture('_layouts/public', [
    '__content' => $__content,
    'pageTitle'  => $pageTitle ?? 'Checkout -- Etel Gebeya',
    'locale'     => $locale,
]);
