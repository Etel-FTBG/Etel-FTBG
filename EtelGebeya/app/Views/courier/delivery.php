<?php
use App\Core\View;
require __DIR__.'/_layout.php';
$items = $items ?? [];
$order = $order ?? [];
ob_start();
?>
<style>
.cd-card{background:var(--dark-card);border:1.5px solid var(--dark-border);border-radius:var(--r-md);overflow:hidden;margin-bottom:1.25rem}
.cd-card-head{padding:1rem 1.25rem;border-bottom:1px solid var(--dark-border);font-size:.9rem;font-weight:800;color:#fff}
.cd-card-body{padding:1.25rem}
.cd-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.cd-info-field{margin-bottom:.9rem}
.cd-info-label{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--dark-muted);margin-bottom:.25rem}
.cd-info-val{font-size:.9rem;color:var(--dark-text);font-weight:600}
.cd-item-row{display:flex;justify-content:space-between;align-items:center;padding:.9rem 1.25rem;border-bottom:1px solid rgba(255,255,255,.04)}
.cd-item-row:last-child{border-bottom:none}
.cd-flash{display:flex;align-items:center;gap:.6rem;padding:.75rem 1.1rem;border-radius:var(--r-sm);margin-bottom:1.25rem;font-size:.88rem;border:1px solid currentColor}
.cd-flash-error{background:rgba(231,76,60,.1);color:var(--red)}
.cd-flash-ok{background:rgba(46,204,113,.1);color:var(--green)}
.badge-status{display:inline-flex;align-items:center;gap:.35rem;padding:.2rem .6rem;border-radius:99px;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.badge-shipped{background:rgba(96,180,255,.12);color:var(--blue)}
.badge-delivered{background:rgba(46,204,113,.12);color:var(--green)}
.cd-btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;border-radius:var(--r-sm);border:none;background:var(--gold);color:#1A1A2E;font-weight:800;font-size:.78rem;cursor:pointer}
.cd-btn:hover{filter:brightness(1.08)}
@media (max-width:640px){ .cd-info-grid{grid-template-columns:1fr} }
</style>

<?php if (!empty($flash)): ?>
  <div class="cd-flash cd-flash-<?= $flash['type']==='error'?'error':'ok' ?>">
    <i class="bi <?= $flash['type']==='error'?'bi-exclamation-circle':'bi-check-circle' ?>"></i>
    <?= e($flash['msg']) ?>
  </div>
<?php endif; ?>

<div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.25rem">
  <a href="<?= View::url('/courier/deliveries') ?>" style="color:var(--dark-muted);text-decoration:none;font-size:1.1rem"><i class="bi bi-arrow-left"></i></a>
  <h1 style="font-size:1.4rem;font-weight:900;color:#fff;margin:0">Delivery #<?= e($order['order_ref'] ?? '') ?></h1>
</div>

<div class="cd-card">
  <div class="cd-card-head">Delivery Address</div>
  <div class="cd-card-body cd-info-grid">
    <div class="cd-info-field"><div class="cd-info-label">Customer</div><div class="cd-info-val"><?= e($order['delivery_name'] ?? '') ?></div></div>
    <div class="cd-info-field"><div class="cd-info-label">Phone</div><div class="cd-info-val"><?= e($order['delivery_phone'] ?? '') ?></div></div>
    <div class="cd-info-field"><div class="cd-info-label">City</div><div class="cd-info-val"><?= e($order['delivery_city'] ?? '') ?></div></div>
    <div class="cd-info-field"><div class="cd-info-label">Address</div><div class="cd-info-val"><?= e($order['delivery_address'] ?? '') ?></div></div>
    <?php if (!empty($order['delivery_notes'])): ?>
    <div class="cd-info-field" style="grid-column:1/-1"><div class="cd-info-label">Notes</div><div class="cd-info-val"><?= e($order['delivery_notes']) ?></div></div>
    <?php endif; ?>
  </div>
</div>

<div class="cd-card">
  <div class="cd-card-head">Items assigned to you</div>
  <?php foreach ($items as $it): ?>
    <div class="cd-item-row">
      <div>
        <div style="font-weight:700;color:#fff"><?= e($it['product_name']) ?> &times; <?= (int)$it['quantity'] ?></div>
        <?php if (!empty($it['tracking_number'])): ?>
          <div style="font-size:.75rem;color:var(--dark-muted);margin-top:.2rem">Tracking: <?= e($it['tracking_number']) ?></div>
        <?php endif; ?>
      </div>
      <div style="display:flex;align-items:center;gap:.75rem">
        <span class="badge-status badge-<?= e($it['status']) ?>"><?= e($it['status']) ?></span>
        <?php if ($it['status'] === 'shipped'): ?>
        <form method="POST" action="<?= View::url('/courier/deliveries/'.($order['order_ref'] ?? '').'/status') ?>" style="margin:0">
          <?= csrf() ?>
          <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
          <input type="hidden" name="status" value="delivered">
          <button type="submit" class="cd-btn"><i class="bi bi-check2"></i> Mark Delivered</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php
$__content = ob_get_clean();
echo View::capture('_layouts/dark', array_merge(get_defined_vars(), ['__content'=>$__content,'navItems'=>$navItems,'role'=>'courier','pageTitle'=>$pageTitle]));
