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

      <div class="success-icon">✅</div>
      <h1 class="success-title"><?= e(t('storefront.success.order_confirmed')) ?></h1>
      <p class="success-sub"><?= e(t('storefront.success.thank_you')) ?></p>

      <div class="order-code"># <?= e($order['code']) ?></div>

      <div class="order-detail-grid">
        <div class="detail-box">
          <div class="detail-label"><?= e(t('common.status')) ?></div>
          <div class="detail-val" style="color:#4ADE80"><?= e(t('status.pending')) ?></div>
        </div>
        <?php $payLabels = ['etel_pay'=>'ኢE Pay Wallet','telebirr'=>'Telebirr','cbe_birr'=>'CBE Birr','bank_transfer'=>'Chapa','cash'=>t('storefront.footer.cash_on_delivery')]; ?>
        <div class="detail-box">
          <div class="detail-label"><?= e(t('admin.nav.payments')) ?></div>
          <div class="detail-val"><?= e($payLabels[$order['payment_method'] ?? 'cash'] ?? ucfirst($order['payment_method'] ?? 'cod')) ?></div>
        </div>
        <div class="detail-box">
          <div class="detail-label"><?= e(t('admin.orders.col_city')) ?></div>
          <div class="detail-val"><?= e($order['city'] ?? '--') ?></div>
        </div>
        <div class="detail-box">
          <div class="detail-label"><?= e(t('common.date')) ?></div>
          <div class="detail-val"><?= date('d M Y', strtotime($order['placed_at'] ?? 'now')) ?></div>
        </div>
      </div>

      <?php
        $payMethod = $order['payment_method'] ?? '';
        $payments  = $GLOBALS['__config']['payments'] ?? [];
        $orderTotal = number_format((float)($order['total'] ?? 0), 2);
      ?>
      <?php if ($payMethod === 'etel_pay' && ($order['payment_status'] ?? '') === 'paid'): ?>
        <div class="payment-notice" style="background:rgba(16,185,129,.08);border-color:rgba(16,185,129,.25);color:#6EE7A0">
          <i class="bi bi-check-circle" style="flex-shrink:0;margin-top:.1rem"></i>
          <?= e(t('storefront.success.paid_via_etelpay')) ?>
        </div>
      <?php elseif ($payMethod === 'telebirr' && ($order['payment_status'] ?? '') !== 'paid'): ?>
        <div class="payment-notice">
          <i class="bi bi-phone" style="flex-shrink:0;margin-top:.1rem"></i>
          <div>
            <?= e(t('storefront.success.complete_via_telebirr')) ?>
            <ol style="margin:.5rem 0 0 1.1rem;padding:0">
              <li><?= e(t('storefront.success.dial_prefix')) ?> <strong><?= e($payments['telebirr']['short_code'] ?? '*127*6*2#') ?></strong> <?= e(t('storefront.success.or_open_app')) ?></li>
              <li><?= e(t('storefront.success.send_prefix')) ?> <strong>Br <?= $orderTotal ?></strong> <?= e(t('storefront.success.using_reference')) ?> <strong><?= e($order['code']) ?></strong></li>
              <li><?= e(t('storefront.success.admin_will_confirm')) ?></li>
            </ol>
          </div>
        </div>
      <?php elseif ($payMethod === 'cbe_birr' && ($order['payment_status'] ?? '') !== 'paid'): ?>
        <div class="payment-notice">
          <i class="bi bi-bank" style="flex-shrink:0;margin-top:.1rem"></i>
          <div>
            <?= e(t('storefront.success.complete_via_cbe')) ?>
            <ol style="margin:.5rem 0 0 1.1rem;padding:0">
              <li><?= e(t('storefront.success.transfer_prefix')) ?> <strong>Br <?= $orderTotal ?></strong> <?= e(t('storefront.success.to_account')) ?> <strong><?= e($payments['cbe']['account'] ?? '1000123456789') ?></strong> (<?= e($payments['cbe']['account_name'] ?? 'ETEL GROUP PLC') ?>)</li>
              <li><?= e(t('storefront.success.use_as_reference_prefix')) ?> <strong><?= e($order['code']) ?></strong> <?= e(t('storefront.success.as_reference_suffix')) ?></li>
              <li><?= e(t('storefront.success.admin_will_confirm')) ?></li>
            </ol>
          </div>
        </div>
      <?php elseif ($payMethod !== 'cash' && ($order['payment_status'] ?? '') !== 'paid'): ?>
        <div class="payment-notice">
          <i class="bi bi-info-circle" style="flex-shrink:0;margin-top:.1rem"></i>
          <?= e(t('storefront.success.order_saved_prefix')) ?> <?= e($order['phone'] ?? '') ?> <?= e(t('storefront.success.order_saved_suffix')) ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($items)): ?>
        <div class="order-items-list">
          <h4><?= e(t('storefront.success.items_ordered')) ?></h4>
          <?php foreach ($items as $it): ?>
            <div class="success-item">
              <span class="success-item-name"><?= e($it['name_en']) ?> × <?= (int)$it['qty'] ?></span>
              <span class="success-item-price">Br <?= number_format((float)$it['subtotal'], 2) ?></span>
            </div>
          <?php endforeach; ?>
          <div class="success-item" style="border-bottom:none;">
            <span style="color:#C9B88A"><?= e(t('storefront.cart.shipping')) ?></span>
            <span class="success-item-price">Br <?= number_format((float)($order['shipping_fee'] ?? 30), 2) ?></span>
          </div>
          <div class="total-row">
            <span><?= e(t('storefront.success.total_paid')) ?></span>
            <span>Br <?= number_format((float)($order['total'] ?? 0), 2) ?></span>
          </div>
        </div>
      <?php endif; ?>

      <div class="action-btns">
        <a href="<?= View::url('/account/orders') ?>" class="btn-gold">
          <i class="bi bi-bag-check"></i> <?= e(t('storefront.success.view_my_orders')) ?>
        </a>
        <a href="<?= View::url('/') ?>" class="btn-outline">
          <i class="bi bi-shop"></i> <?= e(t('storefront.cart.continue_shopping')) ?>
        </a>
      </div>

    <?php else: ?>
      <!-- Order not found -->
      <div class="success-icon" style="border-color:rgba(239,68,68,.4);background:rgba(239,68,68,.1)">❌</div>
      <h1 class="success-title" style="color:#FCA5A5"><?= e(t('storefront.success.order_not_found')) ?></h1>
      <p class="success-sub"><?= e(t('storefront.success.could_not_find_prefix')) ?> #<?= e($code) ?>.</p>
      <div class="action-btns">
        <a href="<?= View::url('/') ?>" class="btn-gold"><i class="bi bi-house"></i> <?= e(t('storefront.success.go_home')) ?></a>
      </div>
    <?php endif; ?>

  </div>
</div>
<?php
$__content = ob_get_clean();
echo View::capture('_layouts/public', [
    '__content' => $__content,
    'pageTitle'  => $pageTitle ?? t('storefront.success.order_confirmed') . ' -- Etel Gebeya',
    'locale'     => $locale,
]);
