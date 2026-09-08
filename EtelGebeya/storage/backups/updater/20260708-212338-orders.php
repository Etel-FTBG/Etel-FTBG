<?php
use App\Core\View;
require __DIR__.'/_layout.php';
ob_start();
?>
<div>
  <div class="dash-header">
    <div>
      <h1 class="dash-title">My Orders</h1>
      <p class="dash-sub">Orders containing your products</p>
    </div>
  </div>

  <?php if (empty($orders)): ?>
    <div style="text-align:center;padding:4rem 2rem;color:var(--eg-text-dim)">
      <div style="font-size:3rem;margin-bottom:1rem">/* U+1F6D2 */</div>
      <h3>No orders yet</h3>
      <p>Orders will appear here when customers buy your products.</p>
    </div>
  <?php else: ?>
  <div class="dash-card" style="overflow:auto">
    <table class="dash-table" style="width:100%">
      <thead>
        <tr>
          <th>Order #</th>
          <th>Customer</th>
          <th>Items</th>
          <th>My Revenue</th>
          <th>Status</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td><span style="color:var(--eg-gold);font-family:monospace"><?= e($o['code']) ?></span></td>
          <td><?= e($o['customer_name']) ?></td>
          <td><?= (int)$o['item_count'] ?></td>
          <td>Br <?= number_format((float)$o['my_total'],2) ?></td>
          <td><span class="badge-status badge-<?= $o['status'] ?>"><?= $o['status'] ?></span></td>
          <td style="font-size:.82rem;color:var(--eg-text-dim)"><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
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
.badge-active,.badge-completed,.badge-delivered{background:rgba(16,185,129,.15);color:#10b981}
.badge-pending,.badge-processing{background:rgba(212,175,55,.15);color:#D4AF37}
.badge-cancelled,.badge-refunded{background:rgba(239,68,68,.15);color:#ef4444}
</style>
<?php
$__content = ob_get_clean();
echo View::capture('_layouts/dark', array_merge(get_defined_vars(), ['__content'=>$__content,'navItems'=>$navItems,'role'=>'vendor','pageTitle'=>$pageTitle]));
