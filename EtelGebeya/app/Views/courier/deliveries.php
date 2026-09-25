<?php
use App\Core\View;
require __DIR__.'/_layout.php';
$items = $items ?? [];
ob_start();
?>
<style>
.cd-card{background:var(--dark-card);border:1.5px solid var(--dark-border);border-radius:var(--r-md);overflow:hidden}
.cd-card-head{padding:1rem 1.25rem;border-bottom:1px solid var(--dark-border);font-size:.9rem;font-weight:800;color:#fff}
.cd-table{width:100%;border-collapse:collapse}
.cd-table th{font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--gold);padding:.65rem 1rem;background:rgba(255,255,255,.025);border-bottom:1px solid rgba(255,255,255,.06);text-align:left}
.cd-table td{padding:.7rem 1rem;border-bottom:1px solid rgba(255,255,255,.04);font-size:.83rem;color:var(--dark-text)}
.cd-table tr:last-child td{border-bottom:none}
.cd-table tr:hover td{background:rgba(212,175,55,.04)}
.cd-empty{padding:2.5rem;text-align:center;color:var(--dark-muted);font-size:.85rem}
.cd-flash{display:flex;align-items:center;gap:.6rem;padding:.75rem 1.1rem;border-radius:var(--r-sm);margin-bottom:1.25rem;font-size:.88rem;border:1px solid currentColor}
.cd-flash-error{background:rgba(231,76,60,.1);color:var(--red)}
.cd-flash-ok{background:rgba(46,204,113,.1);color:var(--green)}
.badge-status{display:inline-flex;align-items:center;gap:.35rem;padding:.2rem .6rem;border-radius:99px;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.badge-shipped{background:rgba(96,180,255,.12);color:var(--blue)}
.badge-delivered{background:rgba(46,204,113,.12);color:var(--green)}

@media (max-width:760px){
  .cd-card{background:none;border:none;overflow:visible}
  .cd-table thead{display:none}
  .cd-table, .cd-table tbody, .cd-table tr, .cd-table td{display:block;width:100%}
  .cd-table tbody tr{background:var(--dark-card);border:1.5px solid var(--dark-border);border-radius:var(--r-md);margin-bottom:.85rem;padding:.9rem 1rem}
  .cd-table td{padding:.4rem 0;border-bottom:1px dashed rgba(255,255,255,.06);display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;text-align:right}
  .cd-table td:last-child{border-bottom:none}
  .cd-table td::before{content:attr(data-label);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--dark-muted);text-align:left;flex-shrink:0}
}
</style>

<?php if (!empty($flash)): ?>
  <div class="cd-flash cd-flash-<?= $flash['type']==='error'?'error':'ok' ?>">
    <i class="bi <?= $flash['type']==='error'?'bi-exclamation-circle':'bi-check-circle' ?>"></i>
    <?= e($flash['msg']) ?>
  </div>
<?php endif; ?>

<h1 style="font-size:1.4rem;font-weight:900;color:#fff;margin:0 0 1.25rem">My Deliveries</h1>

<div class="cd-card">
  <?php if (empty($items)): ?>
    <div class="cd-empty"><i class="bi bi-truck" style="font-size:1.8rem;display:block;margin-bottom:.5rem;opacity:.4"></i>No deliveries assigned to you yet. A seller assigns you when they mark an order item as shipped.</div>
  <?php else: ?>
  <table class="cd-table">
    <thead><tr><th>Order</th><th>Item</th><th>Customer</th><th>Phone</th><th>City</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): ?>
      <tr>
        <td data-label="Order"><a href="<?= View::url('/courier/deliveries/'.$it['code']) ?>" style="color:var(--gold);text-decoration:none;font-weight:700">#<?= e($it['code']) ?></a></td>
        <td data-label="Item"><?= e($it['product_name']) ?> x<?= (int)$it['quantity'] ?></td>
        <td data-label="Customer"><?= e($it['customer_name'] ?? '') ?></td>
        <td data-label="Phone"><?= e($it['customer_phone'] ?? '') ?></td>
        <td data-label="City"><?= e($it['city'] ?? '') ?></td>
        <td data-label="Status"><span class="badge-status badge-<?= e($it['item_status']) ?>"><?= e($it['item_status']) ?></span></td>
        <td data-label=""><a href="<?= View::url('/courier/deliveries/'.$it['code']) ?>" style="color:var(--gold);text-decoration:none;font-weight:700;font-size:.78rem">View &rarr;</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php
$__content = ob_get_clean();
echo View::capture('_layouts/dark', array_merge(get_defined_vars(), ['__content'=>$__content,'navItems'=>$navItems,'role'=>'courier','pageTitle'=>$pageTitle]));
