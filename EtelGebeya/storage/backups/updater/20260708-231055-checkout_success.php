<?php
declare(strict_types=1);
use App\Core\View;

$order  = $order ?? null;
$items  = $items ?? [];
$code   = $code ?? '--';
$locale = $locale ?? 'en';
ob_start();
?>
<style>
.success-page {
  min-height: 70vh; display: flex; align-items: center; justify-content: center;
  padding: 3rem 1.25rem;
  background: radial-gradient(ellipse 70% 60% at 50% 0%, rgba(16,185,129,.06), transparent);
}
.success-card {
  width: 100%; max-width: 560px;
  background: linear-gradient(145deg,#1c1208,#130d05);
  border: 1px solid rgba(16,185,129,.3);
  border-radius: 24px; padding: 2.5rem 2rem;
  box-shadow: 0 24px 60px rgba(0,0,0,.5);
  text-align: center;
  animation: fadeUp .6s cubic-bezier(.22,1,.36,1) both;
}
@keyframes fadeUp { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:none} }

.success-icon {
  width: 80px; height: 80px;
  background: rgba(16,185,129,.15);
  border: 2px solid rgba(16,185,129,.4);
  border-radius: 50%; display: flex; align-items: center; justify-content: center;
  font-size: 2.2rem; margin: 0 auto 1.25rem;
  animation: popIn .5s .2s cubic-bezier(.34,1.56,.64,1) both;
}
@keyframes popIn { from{opacity:0;transform:scale(.5)} to{opacity:1;transform:scale(1)} }

.success-title {
  font-size: 1.6rem; font-weight: 900; color: #4ADE80; margin-bottom: .4rem;
}
.success-sub { color: #7A6840; font-size: .9rem; margin-bottom: 2rem; }

.order-code {
  display: inline-block;
  background: rgba(212,175,55,.1); border: 1px solid rgba(212,175,55,.25);
  border-radius: 10px; padding: .6rem 1.25rem;
  font-family: monospace; font-size: 1.1rem; font-weight: 900; letter-spacing: .1em;
  color: #FFE066; margin-bottom: 1.75rem;
}

.order-detail-grid {
  display: grid; grid-template-columns: 1fr 1fr; gap: .6rem;
  text-align: left; margin-bottom: 1.75rem;
}
.detail-box {
  background: rgba(255,255,255,.03); border: 1px solid rgba(212,175,55,.1);
  border-radius: 10px; padding: .75rem;
}
.detail-label { font-size: .72rem; color: #7A6840; text-transform: uppercase; letter-spacing: .06em; margin-bottom: .2rem; }
.detail-val   { font-size: .9rem; font-weight: 700; color: #C9B88A; }

.order-items-list { text-align: left; margin-bottom: 1.75rem; }
.order-items-list h4 { font-size: .85rem; font-weight: 700; color: #7A6840; text-transform: uppercase; letter-spacing: .06em; margin-bottom: .75rem; }
.success-item {
  display: flex; justify-content: space-between; align-items: center;
  padding: .5rem 0; border-bottom: 1px solid rgba(212,175,55,.07); font-size: .88rem;
}
.success-item:last-child { border-bottom: none; }
.success-item-name { color: #C9B88A; }
.success-item-price { color: #D4AF37; font-weight: 700; white-space: nowrap; }

.total-row {
  display: flex; justify-content: space-between; align-items: center;
  font-size: 1rem; font-weight: 900;
  border-top: 1px solid rgba(212,175,55,.2); padding-top: .75rem; margin-top: .5rem;
  color: #F7EFD9;
}
.total-row span:last-child { color: #FFE066; }

.action-btns { display: flex; flex-direction: column; gap: .75rem; margin-top: 1rem; }
.btn-gold {
  display: flex; align-items: center; justify-content: center; gap: .5rem;
  padding: .85rem; border-radius: 12px;
  background: linear-gradient(135deg,#FFE066,#D4AF37,#B8902E);
  color: #080604; font-weight: 800; text-decoration: none;
  transition: opacity .2s, transform .15s;
}
.btn-gold:hover { opacity:.9; transform:translateY(-1px); }
.btn-outline {
  display: flex; align-items: center; justify-content: center; gap: .5rem;
  padding: .75rem; border-radius: 12px;
  border: 1px solid rgba(212,175,55,.25); color: #C9B88A; text-decoration: none;
  font-size: .9rem; transition: border-color .2s, color .2s;
}
.btn-outline:hover { border-color: #D4AF37; color: #FFE066; }

.payment-notice {
  background: rgba(245,158,11,.08); border: 1px solid rgba(245,158,11,.25);
  border-radius: 10px; padding: .75rem 1rem; margin-bottom: 1.5rem;
  font-size: .82rem; color: #FDE68A; text-align: left;
  display: flex; gap: .5rem; align-items: flex-start;
}
</style>

<div class="success-page">
  <div class="success-card">

    <?php if ($order): ?>

      <div class="success-icon">?</div>
      <h1 class="success-title">Order Confirmed!</h1>
      <p class="success-sub">Thank you for your order. We'll process it shortly.</p>

      <div class="order-code"># <?= e($order['code']) ?></div>

      <div class="order-detail-grid">
        <div class="detail-box">
          <div class="detail-label">Status</div>
          <div class="detail-val" style="color:#4ADE80">Pending</div>
        </div>
        <div class="detail-box">
          <div class="detail-label">Payment</div>
          <div class="detail-val"><?= e(ucfirst($order['payment_method'] ?? 'cod')) ?></div>
        </div>
        <div class="detail-box">
          <div class="detail-label">City</div>
          <div class="detail-val"><?= e($order['city'] ?? '--') ?></div>
        </div>
        <div class="detail-box">
          <div class="detail-label">Date</div>
          <div class="detail-val"><?= date('d M Y', strtotime($order['placed_at'] ?? 'now')) ?></div>
        </div>
      </div>

      <?php if (($order['payment_method'] ?? '') !== 'cash' && ($order['payment_status'] ?? '') !== 'paid'): ?>
        <div class="payment-notice">
          <i class="bi bi-info-circle" style="flex-shrink:0;margin-top:.1rem"></i>
          Payment integration coming soon. Your order is saved. We'll contact you at <?= e($order['phone'] ?? '') ?> to complete payment.
        </div>
      <?php endif; ?>

      <?php if (!empty($items)): ?>
        <div class="order-items-list">
          <h4>Items Ordered</h4>
          <?php foreach ($items as $it): ?>
            <div class="success-item">
              <span class="success-item-name"><?= e($it['name_en']) ?> ? <?= (int)$it['qty'] ?></span>
              <span class="success-item-price">Br <?= number_format($it['subtotal'], 2) ?></span>
            </div>
          <?php endforeach; ?>
          <div class="success-item" style="border-bottom:none;">
            <span style="color:#C9B88A">Shipping</span>
            <span class="success-item-price">Br <?= number_format($order['shipping_fee'] ?? 30, 2) ?></span>
          </div>
          <div class="total-row">
            <span>Total Paid</span>
            <span>Br <?= number_format($order['total'] ?? 0, 2) ?></span>
          </div>
        </div>
      <?php endif; ?>

      <div class="action-btns">
        <a href="<?= View::url('/account/orders') ?>" class="btn-gold">
          <i class="bi bi-bag-check"></i> View My Orders
        </a>
        <a href="<?= View::url('/') ?>" class="btn-outline">
          <i class="bi bi-shop"></i> Continue Shopping
        </a>
      </div>

    <?php else: ?>
      <!-- Order not found -->
      <div class="success-icon" style="border-color:rgba(239,68,68,.4);background:rgba(239,68,68,.1)">?</div>
      <h1 class="success-title" style="color:#FCA5A5">Order Not Found</h1>
      <p class="success-sub">We couldn't find order #<?= e($code) ?>.</p>
      <div class="action-btns">
        <a href="<?= View::url('/') ?>" class="btn-gold"><i class="bi bi-house"></i> Go Home</a>
      </div>
    <?php endif; ?>

  </div>
</div>
<?php
$__content = ob_get_clean();
echo View::capture('_layouts/public', [
    '__content' => $__content,
    'pageTitle'  => $pageTitle ?? 'Order Confirmed -- Etel Gebeya',
    'locale'     => $locale,
]);
