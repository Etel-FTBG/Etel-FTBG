<?php
use App\Core\View;
require __DIR__.'/_layout.php';
$recent = $recent ?? [];
ob_start();
?>
<style>
.cd-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem}
.cd-stat{background:var(--dark-card);border:1.5px solid var(--dark-border);border-radius:var(--r-md);padding:1.25rem 1.4rem}
.cd-stat-val{font-size:1.8rem;font-weight:900;color:#fff;font-family:'Sora',sans-serif;letter-spacing:-.03em;line-height:1}
.cd-stat-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--dark-muted);margin-top:.4rem}
.cd-card{background:var(--dark-card);border:1.5px solid var(--dark-border);border-radius:var(--r-md);overflow:hidden}
.cd-card-head{padding:1rem 1.25rem;border-bottom:1px solid var(--dark-border);font-size:.9rem;font-weight:800;color:#fff}
.cd-table{width:100%;border-collapse:collapse}
.cd-table th{font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--gold);padding:.65rem 1rem;background:rgba(255,255,255,.025);border-bottom:1px solid rgba(255,255,255,.06);text-align:left}
.cd-table td{padding:.7rem 1rem;border-bottom:1px solid rgba(255,255,255,.04);font-size:.83rem;color:var(--dark-text)}
.cd-table tr:last-child td{border-bottom:none}
.cd-empty{padding:2.5rem;text-align:center;color:var(--dark-muted);font-size:.85rem}
.cd-flash{display:flex;align-items:center;gap:.6rem;padding:.75rem 1.1rem;border-radius:var(--r-sm);margin-bottom:1.25rem;font-size:.88rem;border:1px solid currentColor}
.cd-flash-error{background:rgba(231,76,60,.1);color:var(--red)}
.cd-flash-ok{background:rgba(46,204,113,.1);color:var(--green)}
.badge-status{display:inline-flex;align-items:center;gap:.35rem;padding:.2rem .6rem;border-radius:99px;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.badge-shipped{background:rgba(96,180,255,.12);color:var(--blue)}
.badge-delivered{background:rgba(46,204,113,.12);color:var(--green)}
@media (max-width:760px){ .cd-stats{grid-template-columns:1fr} }
</style>

<?php if (!empty($flash)): ?>
  <div class="cd-flash cd-flash-<?= $flash['type']==='error'?'error':'ok' ?>">
    <i class="bi <?= $flash['type']==='error'?'bi-exclamation-circle':'bi-check-circle' ?>"></i>
    <?= e($flash['msg']) ?>
  </div>
<?php endif; ?>

<h1 style="font-size:1.4rem;font-weight:900;color:#fff;margin:0 0 1.25rem">Welcome back, <?= e($courier['name'] ?? 'Courier') ?></h1>

<div class="cd-stats">
  <div class="cd-stat">
    <div class="cd-stat-val"><?= number_format($assigned) ?></div>
    <div class="cd-stat-label">Assigned Deliveries</div>
  </div>
  <div class="cd-stat">
    <div class="cd-stat-val"><?= number_format($delivered) ?></div>
    <div class="cd-stat-label">Total Delivered</div>
  </div>
  <div class="cd-stat">
    <div class="cd-stat-val"><?= number_format($today) ?></div>
    <div class="cd-stat-label">Delivered Today</div>
  </div>
</div>

<div class="cd-card">
  <div class="cd-card-head">Recent Activity</div>
  <?php if (empty($recent)): ?>
    <div class="cd-empty"><i class="bi bi-truck" style="font-size:1.8rem;display:block;margin-bottom:.5rem;opacity:.4"></i>No deliveries assigned to you yet.</div>
  <?php else: ?>
  <table class="cd-table">
    <thead><tr><th>Order</th><th>Item</th><th>Customer</th><th>City</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($recent as $r): ?>
      <tr>
        <td><a href="<?= View::url('/courier/deliveries/'.$r['code']) ?>" style="color:var(--gold);text-decoration:none;font-weight:700">#<?= e($r['code']) ?></a></td>
        <td><?= e($r['product_name']) ?> x<?= (int)$r['quantity'] ?></td>
        <td><?= e($r['customer_name'] ?? '') ?></td>
        <td><?= e($r['city'] ?? '') ?></td>
        <td><span class="badge-status badge-<?= e($r['item_status']) ?>"><?= e($r['item_status']) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php
$__content = ob_get_clean();
echo View::capture('_layouts/dark', array_merge(get_defined_vars(), ['__content'=>$__content,'navItems'=>$navItems,'role'=>'courier','pageTitle'=>$pageTitle]));
