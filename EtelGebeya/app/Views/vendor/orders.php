<?php
use App\Core\View;
require __DIR__.'/_layout.php';
$items = $items ?? [];
ob_start();
?>
<div>
  <div class="dash-header">
    <div>
      <h1 class="dash-title">My Orders</h1>
      <p class="dash-sub">Items containing your products — mark them confirmed, shipped or delivered as you fulfill them</p>
    </div>
  </div>

  <?php if (!empty($flash)): ?>
    <div style="padding:.8rem 1.2rem;border-radius:10px;margin-bottom:1rem;background:<?= $flash['type']==='error'?'rgba(239,68,68,.15)':'rgba(16,185,129,.15)' ?>;color:<?= $flash['type']==='error'?'#ef4444':'#10b981' ?>;border:1px solid currentColor">
      <?= e($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <?php if (empty($items)): ?>
    <div style="text-align:center;padding:4rem 2rem;color:var(--eg-text-dim)">
      <div style="font-size:3rem;margin-bottom:1rem;color:var(--gold,#D4AF37)"><i class="bi bi-cart-x"></i></div>
      <h3>No orders yet</h3>
      <p>Orders will appear here when customers buy your products.</p>
    </div>
  <?php else: ?>
  <div class="dash-card" style="overflow:auto">
    <table class="dash-table" style="width:100%">
      <thead>
        <tr>
          <th>Order #</th>
          <th>Product</th>
          <th>Customer</th>
          <th>Qty</th>
          <th>My Revenue</th>
          <th>Status</th>
          <th>Date</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php
        $nextStatus = ['pending' => 'confirmed', 'confirmed' => 'shipped', 'shipped' => 'delivered'];
        $nextLabel  = ['confirmed' => '<i class="bi bi-check-circle"></i> Confirm', 'shipped' => '<i class="bi bi-box-seam"></i> Mark Shipped', 'delivered' => '<i class="bi bi-house-check"></i> Mark Delivered'];
      ?>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><span style="color:var(--eg-gold);font-family:monospace"><?= e($it['code']) ?></span></td>
          <td><?= e($it['product_name']) ?></td>
          <td><?= e($it['customer_name']) ?><div style="font-size:.72rem;color:var(--eg-text-dim)"><?= e($it['customer_phone']) ?></div></td>
          <td><?= (int)$it['quantity'] ?></td>
          <td>Br <?= number_format((float)$it['total_price'], 2) ?></td>
          <td><span class="badge-status badge-<?= e($it['item_status']) ?>"><?= e($it['item_status']) ?></span></td>
          <td style="font-size:.82rem;color:var(--eg-text-dim)"><?= date('M j, Y', strtotime($it['created_at'])) ?></td>
          <td>
            <?php if (isset($nextStatus[$it['item_status']])): $ns = $nextStatus[$it['item_status']]; ?>
              <form method="POST" action="<?= View::url('/vendor-panel/orders/item-status') ?>" style="display:inline">
                <input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>">
                <input type="hidden" name="status" value="<?= e($ns) ?>">
                <button type="submit" class="dash-btn-sm"><?= $nextLabel[$ns] ?></button>
              </form>
            <?php elseif (!in_array($it['item_status'], ['delivered','cancelled'], true)): ?>
              <span style="color:var(--eg-text-dim);font-size:.78rem">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<style>
.dash-card{background:var(--eg-glass,rgba(255,255,255,.04));border:1.5px solid var(--eg-border,rgba(255,255,255,.08));border-radius:16px;padding:1.25rem}
.badge-status{display:inline-block;padding:.2rem .6rem;border-radius:6px;font-size:.75rem;font-weight:700;text-transform:uppercase}
.badge-confirmed,.badge-delivered{background:rgba(16,185,129,.15);color:#10b981}
.badge-pending{background:rgba(212,175,55,.15);color:#D4AF37}
.badge-shipped{background:rgba(59,130,246,.15);color:#3b82f6}
.badge-cancelled,.badge-returned{background:rgba(239,68,68,.15);color:#ef4444}
.dash-btn-sm{background:linear-gradient(135deg,#FFE066,#D4AF37);color:#080604;font-weight:700;border:none;border-radius:8px;padding:.4rem .8rem;font-size:.78rem;cursor:pointer;white-space:nowrap}
</style>
<?php
$__content = ob_get_clean();
echo View::capture('_layouts/dark', array_merge(get_defined_vars(), ['__content'=>$__content,'navItems'=>$navItems,'role'=>'vendor','pageTitle'=>$pageTitle]));
