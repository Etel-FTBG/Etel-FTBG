<?php
use App\Core\View;
require __DIR__.'/_layout.php';
$pageTitle = ucfirst('payments');
ob_start();
?>
<style>
.ag-bar-title{font-size:18px;font-weight:800;color:var(--dark-text);margin-bottom:16px}
.ag-card{background:var(--dark-card);border:1px solid var(--dark-border);border-radius:var(--r-md);overflow:hidden}
.ag-tbl{width:100%;border-collapse:collapse}
.ag-tbl th{background:rgba(255,255,255,.03);padding:10px 14px;text-align:left;font-size:10px;font-weight:700;color:var(--dark-muted);text-transform:uppercase;letter-spacing:.8px;border-bottom:1px solid var(--dark-border);white-space:nowrap}
.ag-tbl td{padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);font-size:12.5px;color:var(--dark-muted);vertical-align:middle}
.ag-tbl tr:last-child td{border-bottom:none}
.ag-tbl tr:hover td{background:rgba(255,255,255,.02)}
.flash-success{background:rgba(46,204,113,.12);border:1px solid rgba(46,204,113,.25);color:var(--green);padding:11px 15px;border-radius:var(--r-sm);margin-bottom:16px;font-size:13px}
.flash-error{background:rgba(231,76,60,.12);border:1px solid rgba(231,76,60,.25);color:var(--red);padding:11px 15px;border-radius:var(--r-sm);margin-bottom:16px;font-size:13px}
.badge{display:inline-block;padding:3px 8px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;white-space:nowrap}
.badge-paid,.badge-active,.badge-admin{background:rgba(46,204,113,.15);color:var(--green);border:1px solid rgba(46,204,113,.25)}
.badge-unpaid,.badge-customer{background:rgba(212,175,55,.12);color:var(--gold);border:1px solid rgba(212,175,55,.2)}
.badge-pending{background:rgba(96,180,255,.15);color:var(--blue);border:1px solid rgba(96,180,255,.25)}
.badge-vendor{background:rgba(155,89,182,.15);color:var(--purple);border:1px solid rgba(155,89,182,.25)}
.badge-courier{background:rgba(78,205,196,.15);color:var(--teal);border:1px solid rgba(78,205,196,.25)}
.ag-inp{background:rgba(255,255,255,.05);border:1px solid var(--dark-border);color:var(--dark-text);font-family:inherit;font-size:12.5px;padding:8px 12px;border-radius:var(--r-sm);outline:none;width:100%;max-width:340px}
.ag-inp:focus{border-color:var(--gold)}
.ag-sel{background:rgba(255,255,255,.05);border:1px solid var(--dark-border);color:var(--dark-text);font-family:inherit;font-size:12.5px;padding:8px 12px;border-radius:var(--r-sm);cursor:pointer;outline:none}
.ag-sel option{background:#0A0A1A}
.btn-xs{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:6px;font-size:11.5px;font-weight:700;cursor:pointer;border:none;transition:opacity .12s;text-decoration:none}
.btn-gold-xs{background:linear-gradient(135deg,var(--gold-br),var(--gold));color:#1A1A2E}
.btn-ghost-xs{background:rgba(212,175,55,.1);color:var(--gold);border:1px solid rgba(212,175,55,.2)}
.btn-xs:hover{opacity:.8}
.ag-pagination{display:flex;gap:5px;align-items:center;justify-content:center;padding:14px;border-top:1px solid var(--dark-border)}
.pg-link{padding:5px 11px;border-radius:6px;border:1px solid var(--dark-border);color:var(--dark-muted);font-size:12px;text-decoration:none}
.pg-link:hover,.pg-link.cur{background:rgba(212,175,55,.12);color:var(--gold);border-color:rgba(212,175,55,.3)}
.empty-s{text-align:center;padding:48px 20px;color:var(--dark-muted);font-size:13px}
</style>

<?php if (!empty($flash)): ?>
  <div class="flash-<?= e($flash['type'] ?? 'success') ?>"><?= e($flash['msg'] ?? '') ?></div>
<?php endif; ?>

<div class="ag-bar-title"><?= ucfirst('payments') ?></div>


<?php $perPage = 20; $pages = isset($total) && $total > 0 ? (int)ceil($total / $perPage) : 1; ?>
<?php if (!empty($summary)): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:20px">
  <div style="background:var(--dark-card);border:1px solid var(--dark-border);border-radius:var(--r-md);padding:18px 16px">
    <div style="font-size:10px;color:var(--dark-muted);text-transform:uppercase;letter-spacing:1px;font-weight:700">Paid Revenue</div>
    <div style="font-size:24px;font-weight:800;color:var(--gold-lt);margin:7px 0 4px">ETB <?= number_format((float)($summary['paid_total']??0), 0) ?></div>
    <div style="font-size:11.5px;color:var(--dark-muted)"><?= (int)($summary['paid_count']??0) ?> orders</div>
  </div>
  <div style="background:var(--dark-card);border:1px solid var(--dark-border);border-radius:var(--r-md);padding:18px 16px">
    <div style="font-size:10px;color:var(--dark-muted);text-transform:uppercase;letter-spacing:1px;font-weight:700">Pending Payment</div>
    <div style="font-size:24px;font-weight:800;color:var(--gold);margin:7px 0 4px">ETB <?= number_format((float)($summary['pending_total']??0), 0) ?></div>
    <div style="font-size:11.5px;color:var(--dark-muted)"><?= (int)($summary['pending_count']??0) ?> orders</div>
  </div>
</div>
<?php endif; ?>
<div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;align-items:center">
  <form method="GET" action="<?= View::url('/admin/payments') ?>" style="display:flex;gap:9px">
    <select name="filter" class="ag-sel" onchange="this.form.submit()">
      <option value="all"           <?= ($filter??'')==='all'          ?'selected':'' ?>>All Payments</option>
      <option value="paid"          <?= ($filter??'')==='paid'         ?'selected':'' ?>>Paid</option>
      <option value="pending"       <?= ($filter??'')==='pending'      ?'selected':'' ?>>Pending</option>
      <option value="telebirr"      <?= ($filter??'')==='telebirr'     ?'selected':'' ?>>Telebirr</option>
      <option value="cbe_birr"      <?= ($filter??'')==='cbe_birr'     ?'selected':'' ?>>CBE Birr</option>
      <option value="bank_transfer" <?= ($filter??'')==='bank_transfer'?'selected':'' ?>>Bank Transfer</option>
      <option value="cash"          <?= ($filter??'')==='cash'         ?'selected':'' ?>>Cash</option>
    </select>
  </form>
  <span style="font-size:12px;color:var(--dark-muted)"><?= number_format($total??0) ?> orders</span>
</div>
<div class="ag-card">
  <?php if (empty($orders)): ?>
    <div class="empty-s">No payment records found.</div>
  <?php else: ?>
  <table class="ag-tbl">
    <thead><tr><th>Ref</th><th>Customer</th><th>Total</th><th>Method</th><th>Pay Status</th><th>Order Status</th><th>Date</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td style="font-family:monospace;color:var(--gold);font-size:11px;font-weight:700"><?= e($o['code']) ?></td>
          <td style="color:var(--dark-text)"><?= e($o['full_name']) ?></td>
          <td style="color:var(--gold-lt);font-weight:700;white-space:nowrap">ETB <?= number_format((float)$o['total'], 2) ?></td>
          <td style="font-size:11px"><?= e(str_replace('_',' ', $o['payment_method'] ?? '-')) ?></td>
          <td><span class="badge badge-<?= $o['payment_status']==='paid'?'paid':'unpaid' ?>"><?= ucfirst($o['payment_status'] ?? 'pending') ?></span></td>
          <td><span class="badge badge-<?= e($o['status']) ?>"><?= ucfirst($o['status']) ?></span></td>
          <td style="font-size:11px;color:var(--dark-muted);white-space:nowrap"><?= date('d M Y', strtotime($o['created_at'])) ?></td>
          <td>
            <?php if (($o['payment_status'] ?? '') !== 'paid' && ($o['payment_method'] ?? '') !== 'etel_pay'): ?>
            <form method="POST" action="<?= View::url('/admin/payments/mark-paid') ?>" style="margin:0" onsubmit="return confirm('Confirm this order was actually paid? This cannot be undone from here.');">
              <?= csrf() ?>
              <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
              <input type="hidden" name="filter" value="<?= e($filter ?? 'all') ?>">
              <input type="hidden" name="page" value="<?= (int)($page ?? 1) ?>">
              <button type="submit" class="btn-xs btn-gold-xs">Mark Paid</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($pages > 1): ?>
    <div class="ag-pagination">
      <?php if (($page??1) > 1): ?><a href="?filter=<?= urlencode($filter??'all') ?>&page=<?= ($page??1)-1 ?>" class="pg-link">&laquo;</a><?php endif; ?>
      <?php for ($i=max(1,($page??1)-2); $i<=min($pages,($page??1)+2); $i++): ?>
        <a href="?filter=<?= urlencode($filter??'all') ?>&page=<?= $i ?>" class="pg-link<?= $i===($page??1)?' cur':'' ?>"><?= $i ?></a>
      <?php endfor; ?>
      <?php if (($page??1) < $pages): ?><a href="?filter=<?= urlencode($filter??'all') ?>&page=<?= ($page??1)+1 ?>" class="pg-link">&raquo;</a><?php endif; ?>
    </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php
$__content = ob_get_clean();
echo View::capture('_layouts/dark', array_merge(get_defined_vars(), [
    '__content' => $__content,
    'navItems'  => $navItems,
    'role'      => 'admin',
    'pageTitle' => $pageTitle,
]));
