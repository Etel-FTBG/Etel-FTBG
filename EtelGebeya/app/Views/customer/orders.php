<?php
use App\Core\View;
$locale     = $locale ?? 'en';
$totalPages = $total > 0 ? (int)ceil($total / $per) : 1;
ob_start();
?>
<style>
.order-card{background:var(--card,#130d05);border:1.5px solid var(--border-dim,rgba(212,175,55,.08));border-radius:18px;margin-bottom:.9rem;overflow:hidden;transition:border-color .25s,transform .2s}
.order-card:hover{border-color:var(--gold-line,rgba(212,175,55,.22));transform:translateY(-2px)}
.order-card-head{display:flex;align-items:center;justify-content:space-between;padding:.85rem 1.25rem;border-bottom:1px solid rgba(212,175,55,.07);background:rgba(212,175,55,.03)}
.oc-code{font-family:monospace;color:var(--g2,#D4AF37);font-weight:800;font-size:.9rem}
.oc-date{font-size:.72rem;color:var(--text-muted,#7A6840);margin-top:.1rem}
.sp{display:inline-flex;padding:.18rem .55rem;border-radius:99px;font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em}
.sp-pending,.sp-processing{background:rgba(212,175,55,.1);color:var(--g2,#D4AF37);border:1px solid rgba(212,175,55,.2)}
.sp-completed,.sp-delivered{background:rgba(16,185,129,.1);color:#10b981;border:1px solid rgba(16,185,129,.2)}
.sp-cancelled,.sp-refunded{background:rgba(239,68,68,.1);color:#ef4444;border:1px solid rgba(239,68,68,.2)}
.sp-shipped{background:rgba(96,165,250,.1);color:#60a5fa;border:1px solid rgba(96,165,250,.2)}
.order-card-body{display:flex;align-items:center;justify-content:space-between;padding:.85rem 1.25rem;gap:1rem;flex-wrap:wrap}
.oc-fields{display:flex;gap:2rem;flex-wrap:wrap}
.oc-field label{font-size:.68rem;color:var(--text-muted,#7A6840);text-transform:uppercase;letter-spacing:.06em;font-weight:700;display:block;margin-bottom:.2rem}
.oc-field span{font-size:.88rem;color:var(--text,#F7EFD9);font-weight:700}
.btn-view{display:inline-flex;align-items:center;gap:.4rem;padding:.48rem 1.05rem;border-radius:11px;background:rgba(212,175,55,.08);border:1.5px solid rgba(212,175,55,.2);color:var(--g2,#D4AF37);text-decoration:none;font-size:.78rem;font-weight:700;transition:all .2s;white-space:nowrap}
.btn-view:hover{background:rgba(212,175,55,.15);border-color:rgba(212,175,55,.35)}
.empty-state{text-align:center;padding:3.5rem 1rem;background:var(--card,#130d05);border:1.5px solid var(--border-dim,rgba(212,175,55,.08));border-radius:18px}
.pager{display:flex;justify-content:center;gap:.4rem;margin-top:1.5rem}
.pager a{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:8px;border:1.5px solid var(--border-dim,rgba(212,175,55,.08));color:var(--text-muted,#7A6840);text-decoration:none;font-size:.85rem;font-weight:700;transition:all .15s}
.pager a.on{background:rgba(212,175,55,.12);border-color:rgba(212,175,55,.3);color:var(--g2,#D4AF37)}
</style>

<div style="margin-bottom:1.25rem">
  <h1 class="cp-page-title"><i class="bi bi-receipt"></i> <?= e(t('customer.nav.my_orders')) ?></h1>
  <p style="font-size:.82rem;color:var(--text-muted,#7A6840);margin:-.5rem 0 0"><?= e(t('customer.orders.n_placed', ['n' => number_format($total)])) ?></p>
</div>

<?php if (empty($orders)): ?>
  <div class="empty-state">
    <i class="bi bi-cart-x" style="font-size:2.8rem;color:rgba(212,175,55,.15);display:block;margin-bottom:1rem"></i>
    <h3 style="font-size:1.1rem;font-weight:800;color:var(--text,#F7EFD9);margin:0 0 .5rem"><?= e(t('customer.dashboard.no_orders_yet')) ?></h3>
    <p style="font-size:.85rem;color:var(--text-muted,#7A6840);margin:0 0 1.25rem"><?= e(t('customer.orders.browse_and_place')) ?></p>
    <a href="<?= View::url('/') ?>" style="display:inline-flex;align-items:center;gap:.4rem;background:var(--grad-gold,linear-gradient(135deg,#FFE066,#D4AF37,#B8902E));color:#1a0e00;font-weight:800;padding:.65rem 1.4rem;border-radius:12px;text-decoration:none;box-shadow:0 6px 20px rgba(212,175,55,.3)">
      <i class="bi bi-shop"></i> <?= e(t('customer.dashboard.shop_now')) ?>
    </a>
  </div>
<?php else: ?>
  <?php foreach ($orders as $o): ?>
    <div class="order-card">
      <div class="order-card-head">
        <div>
          <div class="oc-code">#<?= e($o['code']) ?></div>
          <div class="oc-date"><?= date('M j, Y', strtotime($o['created_at'])) ?></div>
        </div>
        <span class="sp sp-<?= $o['status'] ?>"><?= $o['status'] ?></span>
      </div>
      <div class="order-card-body">
        <div class="oc-fields">
          <div class="oc-field">
            <label><?= e(t('storefront.cart.total')) ?></label>
            <span style="color:var(--g2,#D4AF37)">Br <?= number_format((float)($o['total']??0),0) ?></span>
          </div>
          <?php if (!empty($o['payment_method'])): ?>
          <div class="oc-field">
            <label><?= e(t('admin.nav.payments')) ?></label>
            <span><?= e(ucfirst($o['payment_method'])) ?></span>
          </div>
          <?php endif; ?>
        </div>
        <a href="<?= View::url('/account/orders/'.$o['code']) ?>" class="btn-view">
          <i class="bi bi-eye"></i> <?= e(t('customer.orders.view_details')) ?>
        </a>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ($totalPages > 1): ?>
    <div class="pager">
      <?php for ($i=1;$i<=$totalPages;$i++): ?>
        <a href="?page=<?=$i?>" class="<?=$i===$page?'on':''?>"><?=$i?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php
$__content = ob_get_clean();
ob_start();
require __DIR__ . '/_layout.php';
$__panel = ob_get_clean();
echo View::capture('_layouts/public', [
    '__content' => $__panel,
    'pageTitle'  => t('customer.nav.my_orders') . ' -- Etel Gebeya',
    'locale'     => $locale,
]);
