<?php
use App\Core\View;
$locale = $locale ?? 'en';
$o      = $order ?? [];
function img_url_cust(string $p): string { return str_starts_with($p,'http')?$p:'/EtelGebeya/public/uploads/products/'.ltrim($p,'/'); }

$statusSteps = ['pending'=>0,'processing'=>1,'shipped'=>2,'delivered'=>3,'completed'=>3];
$currentStep = $statusSteps[$o['status']??'pending'] ?? 0;
if (in_array($o['status']??'',['cancelled','refunded'])) $currentStep = -1;
ob_start();
?>
<style>
.sp{display:inline-flex;padding:.18rem .55rem;border-radius:99px;font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em}
.sp-pending,.sp-processing{background:rgba(212,175,55,.1);color:var(--g2,#D4AF37);border:1px solid rgba(212,175,55,.2)}
.sp-completed,.sp-delivered{background:rgba(16,185,129,.1);color:#10b981;border:1px solid rgba(16,185,129,.2)}
.sp-cancelled,.sp-refunded{background:rgba(239,68,68,.1);color:#ef4444;border:1px solid rgba(239,68,68,.2)}
.sp-shipped{background:rgba(96,165,250,.1);color:#60a5fa;border:1px solid rgba(96,165,250,.2)}

.od-section{background:var(--card,#130d05);border:1.5px solid var(--border-dim,rgba(212,175,55,.08));border-radius:18px;overflow:hidden;margin-bottom:1.1rem}
.od-section-head{display:flex;align-items:center;gap:.6rem;padding:.85rem 1.25rem;border-bottom:1px solid rgba(212,175,55,.07);font-weight:800;font-size:.88rem;color:var(--text,#F7EFD9);background:rgba(212,175,55,.03)}
.od-body{padding:1.1rem 1.25rem}

/* Progress tracker */
.progress-track{display:flex;align-items:flex-start;gap:0;padding:1.25rem 1.5rem;overflow-x:auto}
.pt-step{display:flex;flex-direction:column;align-items:center;flex:1;position:relative;min-width:70px}
.pt-step::before{content:'';position:absolute;top:15px;left:50%;right:-50%;height:2px;background:rgba(212,175,55,.1);z-index:0}
.pt-step:last-child::before{display:none}
.pt-step.done::before,.pt-step.active::before{background:var(--g2,#D4AF37)}
.pt-dot{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.85rem;border:2px solid rgba(212,175,55,.15);background:rgba(212,175,55,.05);color:var(--text-dim,#C9B88A);position:relative;z-index:1;transition:all .3s}
.pt-step.done .pt-dot{background:var(--g2,#D4AF37);border-color:var(--g2,#D4AF37);color:#1a0e00}
.pt-step.active .pt-dot{background:rgba(212,175,55,.15);border-color:var(--g2,#D4AF37);color:var(--g2,#D4AF37);box-shadow:0 0 0 5px rgba(212,175,55,.12)}
.pt-label{font-size:.67rem;font-weight:700;color:var(--text-muted,#7A6840);margin-top:.4rem;text-align:center;text-transform:uppercase;letter-spacing:.04em}
.pt-step.done .pt-label,.pt-step.active .pt-label{color:var(--g2,#D4AF37)}

/* Item row */
.item-row{display:flex;align-items:center;gap:1rem;padding:.75rem 0;border-bottom:1px solid rgba(212,175,55,.05)}
.item-row:last-child{border-bottom:none}
.item-thumb{width:54px;height:54px;border-radius:12px;object-fit:cover;border:1.5px solid rgba(212,175,55,.12);flex-shrink:0}
.item-thumb-ph{width:54px;height:54px;border-radius:12px;background:rgba(212,175,55,.06);display:flex;align-items:center;justify-content:center;color:var(--text-muted,#7A6840);font-size:1.1rem;flex-shrink:0}
.item-name{font-weight:700;font-size:.88rem;color:var(--text,#F7EFD9)}
.item-meta{font-size:.75rem;color:var(--text-muted,#7A6840);margin-top:.15rem}
.item-total{margin-left:auto;font-weight:800;font-size:.9rem;color:var(--text,#F7EFD9);white-space:nowrap}

/* Summary */
.sum-row{display:flex;justify-content:space-between;padding:.5rem 0;border-bottom:1px solid rgba(212,175,55,.05);font-size:.88rem}
.sum-row:last-child{border-bottom:none;padding-top:.8rem;font-size:1rem;font-weight:900;color:var(--text,#F7EFD9)}
.sum-label{color:var(--text-muted,#7A6840)}
</style>

<!-- Header -->
<div style="margin-bottom:1.25rem">
  <h1 class="cp-page-title" style="margin-bottom:.25rem">
    <i class="bi bi-receipt"></i>
    Order <span style="color:var(--g2,#D4AF37);font-family:monospace">#<?= e($o['code']??'') ?></span>
  </h1>
  <p style="font-size:.8rem;color:var(--text-muted,#7A6840);margin:0;display:flex;align-items:center;gap:.6rem">
    Placed <?= date('F j, Y \a\t g:i A', strtotime($o['created_at']??'now')) ?>
    <span class="sp sp-<?= $o['status']??'pending' ?>"><?= $o['status']??'pending' ?></span>
  </p>
</div>

<!-- Progress tracker -->
<?php if ($currentStep >= 0): ?>
<div class="od-section">
  <div class="od-section-head"><i class="bi bi-truck" style="color:#60a5fa"></i> Order Progress</div>
  <div class="progress-track">
    <?php
    $steps = [['bi-check-circle','Confirmed'],['bi-gear','Processing'],['bi-truck','Shipped'],['bi-house-check','Delivered']];
    foreach ($steps as $i => [$icon,$label]):
      $cls = $i < $currentStep ? 'done' : ($i === $currentStep ? 'active' : '');
    ?>
    <div class="pt-step <?= $cls ?>">
      <div class="pt-dot"><i class="bi <?= $i < $currentStep ? 'bi-check-lg' : $icon ?>"></i></div>
      <div class="pt-label"><?= $label ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Items -->
<div class="od-section">
  <div class="od-section-head"><i class="bi bi-box-seam" style="color:var(--g2,#D4AF37)"></i> Items (<?= count($items) ?>)</div>
  <div class="od-body">
    <?php foreach ($items as $it): ?>
      <div class="item-row">
        <?php if (!empty($it['thumb'])): ?>
          <img src="<?= e(img_url_cust($it['thumb'])) ?>" class="item-thumb" alt="">
        <?php else: ?>
          <div class="item-thumb-ph"><i class="bi bi-image"></i></div>
        <?php endif; ?>
        <div style="flex:1;min-width:0">
          <div class="item-name">
            <a href="<?= View::url('/product/'.e($it['slug']??'')) ?>" style="color:inherit;text-decoration:none;transition:color .2s" onmouseover="this.style.color='var(--g2,#D4AF37)'" onmouseout="this.style.color='inherit'">
              <?= e(html_entity_decode($locale==='am'?($it['name_am']??$it['name_en']):$it['name_en'],ENT_QUOTES,'UTF-8')) ?>
            </a>
          </div>
          <div class="item-meta">Qty: <?= (int)($it['qty']??$it['quantity']??1) ?> * Br <?= number_format((float)($it['price']??$it['unit_price']??0),2) ?> each</div>
        </div>
        <div class="item-total">Br <?= number_format((float)($it['subtotal']??((($it['qty']??1)*($it['price']??0)))),2) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Summary + Delivery -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
  <div class="od-section" style="margin-bottom:0">
    <div class="od-section-head"><i class="bi bi-calculator" style="color:#10b981"></i> Summary</div>
    <div class="od-body">
      <div class="sum-row"><span class="sum-label">Subtotal</span><span>Br <?= number_format((float)($o['subtotal']??$o['total']??0),2) ?></span></div>
      <?php if (!empty($o['shipping_fee'])): ?>
      <div class="sum-row"><span class="sum-label">Shipping</span><span>Br <?= number_format((float)$o['shipping_fee'],2) ?></span></div>
      <?php endif; ?>
      <?php if (!empty($o['discount'])): ?>
      <div class="sum-row"><span class="sum-label">Discount</span><span style="color:#10b981">-Br <?= number_format((float)$o['discount'],2) ?></span></div>
      <?php endif; ?>
      <div class="sum-row"><span>Total</span><span style="color:var(--g2,#D4AF37)">Br <?= number_format((float)($o['total']??0),2) ?></span></div>
    </div>
  </div>
  <div class="od-section" style="margin-bottom:0">
    <div class="od-section-head"><i class="bi bi-geo-alt" style="color:#a78bfa"></i> Delivery</div>
    <div class="od-body" style="font-size:.85rem;line-height:1.9;color:var(--text-dim,#C9B88A)">
      <?php if (!empty($o['shipping_address'])): ?>
        <?= nl2br(e($o['shipping_address'])) ?>
      <?php else: ?>
        <span style="color:var(--text-muted,#7A6840)">No delivery address on record.</span>
      <?php endif; ?>
      <?php if (!empty($o['payment_method'])): ?>
        <div style="margin-top:.8rem;font-size:.78rem;border-top:1px solid rgba(212,175,55,.07);padding-top:.8rem">
          <span style="color:var(--text-muted,#7A6840)">Payment:</span>
          <span style="color:var(--text,#F7EFD9);font-weight:700"> <?= e(ucfirst($o['payment_method'])) ?></span>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div style="text-align:center;margin-top:1.5rem">
  <a href="<?= View::url('/') ?>" style="display:inline-flex;align-items:center;gap:.5rem;background:var(--grad-gold,linear-gradient(135deg,#FFE066,#D4AF37,#B8902E));color:#1a0e00;font-weight:800;padding:.65rem 1.6rem;border-radius:12px;text-decoration:none;box-shadow:0 6px 20px rgba(212,175,55,.3)">
    <i class="bi bi-shop"></i> Continue Shopping
  </a>
</div>

<?php
$__content = ob_get_clean();
ob_start();
require __DIR__ . '/_layout.php';
$__panel = ob_get_clean();
echo View::capture('_layouts/public', [
    '__content' => $__panel,
    'pageTitle'  => 'Order #'.e($o['code']??'').' -- Etel Gebeya',
    'locale'     => $locale,
]);
