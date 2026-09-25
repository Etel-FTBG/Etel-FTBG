<?php
use App\Core\View;
require __DIR__.'/_layout.php';
$pageTitle = t('admin.nav.orders');
$perPage   = 20;
$pages     = $total > 0 ? (int)ceil($total / $perPage) : 1;
ob_start();
?>
<style>
.ag-bar{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px}
.ag-bar-title{font-size:18px;font-weight:800;color:var(--dark-text)}
.ag-bar-sub{font-size:12px;color:var(--dark-muted);margin-top:2px}
.ag-filter-row{display:flex;gap:9px;flex-wrap:wrap;align-items:center}
.ag-filter-row form{display:flex;gap:9px;flex-wrap:wrap;align-items:center}
.ag-inp{background:rgba(255,255,255,.05);border:1px solid var(--dark-border);color:var(--dark-text);font-family:inherit;font-size:12.5px;padding:8px 12px;border-radius:var(--r-sm);outline:none;min-width:200px}
.ag-inp:focus{border-color:var(--gold)}
.ag-sel{background:rgba(255,255,255,.05);border:1px solid var(--dark-border);color:var(--dark-text);font-family:inherit;font-size:12.5px;padding:8px 12px;border-radius:var(--r-sm);cursor:pointer;outline:none}
.ag-sel option{background:#0A0A1A}
.ag-card{background:var(--dark-card);border:1px solid var(--dark-border);border-radius:var(--r-md);overflow:hidden}
.ag-tbl{width:100%;border-collapse:collapse}
.ag-tbl th{background:rgba(255,255,255,.03);padding:10px 14px;text-align:left;font-size:10px;font-weight:700;color:var(--dark-muted);text-transform:uppercase;letter-spacing:.8px;border-bottom:1px solid var(--dark-border);white-space:nowrap}
.ag-tbl td{padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);font-size:12.5px;color:var(--dark-muted);vertical-align:middle}
.ag-tbl tr:last-child td{border-bottom:none}
.ag-tbl tr:hover td{background:rgba(255,255,255,.02)}
.badge{display:inline-block;padding:3px 8px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;white-space:nowrap}
.badge-new,.badge-pending{background:rgba(96,180,255,.15);color:var(--blue);border:1px solid rgba(96,180,255,.25)}
.badge-processing{background:rgba(255,149,0,.15);color:var(--orange);border:1px solid rgba(255,149,0,.25)}
.badge-shipped{background:rgba(155,89,182,.15);color:var(--purple);border:1px solid rgba(155,89,182,.25)}
.badge-delivered{background:rgba(46,204,113,.15);color:var(--green);border:1px solid rgba(46,204,113,.25)}
.badge-cancelled,.badge-rejected{background:rgba(231,76,60,.15);color:var(--red);border:1px solid rgba(231,76,60,.25)}
.badge-paid{background:rgba(46,204,113,.15);color:var(--green);border:1px solid rgba(46,204,113,.25)}
.badge-unpaid{background:rgba(212,175,55,.12);color:var(--gold);border:1px solid rgba(212,175,55,.2)}
.btn-xs{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;border:none;transition:opacity .12s;text-decoration:none}
.btn-gold-xs{background:linear-gradient(135deg,var(--gold-br),var(--gold));color:#1A1A2E}
.btn-ghost-xs{background:rgba(212,175,55,.1);color:var(--gold);border:1px solid rgba(212,175,55,.2)}
.btn-xs:hover{opacity:.8}
.ag-pagination{display:flex;gap:5px;align-items:center;justify-content:center;padding:14px;border-top:1px solid var(--dark-border)}
.pg-link{padding:5px 11px;border-radius:6px;border:1px solid var(--dark-border);color:var(--dark-muted);font-size:12px;text-decoration:none;transition:background .12s}
.pg-link:hover,.pg-link.cur{background:rgba(212,175,55,.12);color:var(--gold);border-color:rgba(212,175,55,.3)}
.flash-success{background:rgba(46,204,113,.12);border:1px solid rgba(46,204,113,.25);color:var(--green);padding:11px 15px;border-radius:var(--r-sm);margin-bottom:16px;font-size:13px;font-weight:500}
.flash-error{background:rgba(231,76,60,.12);border:1px solid rgba(231,76,60,.25);color:var(--red);padding:11px 15px;border-radius:var(--r-sm);margin-bottom:16px;font-size:13px;font-weight:500}
.st-sel{background:#0d0d1a;border:1px solid var(--dark-border);color:var(--dark-text);font-family:inherit;font-size:11px;padding:3px 7px;border-radius:5px;cursor:pointer}
.st-sel option{background:#0A0A1A}
.empty-s{text-align:center;padding:48px 20px;color:var(--dark-muted);font-size:13px}
</style>

<?php if (!empty($flash)): ?>
  <div class="flash-<?= e($flash['type'] ?? 'success') ?>"><?= e($flash['msg'] ?? '') ?></div>
<?php endif; ?>

<div class="ag-bar">
  <div>
    <div class="ag-bar-title"><?= e(t('admin.nav.orders')) ?></div>
    <div class="ag-bar-sub"><?= e(t('admin.orders.found_count', ['n' => number_format($total)])) ?></div>
  </div>
  <div class="ag-filter-row">
    <form method="GET" action="<?= View::url('/admin/orders') ?>">
      <select name="filter" class="ag-sel" onchange="this.form.submit()">
        <option value="all"        <?= $filter==='all'       ?'selected':'' ?>><?= e(t('admin.orders.status_all')) ?></option>
        <option value="new"        <?= $filter==='new'       ?'selected':'' ?>><?= e(t('admin.orders.status_new')) ?></option>
        <option value="processing" <?= $filter==='processing'?'selected':'' ?>><?= e(t('admin.orders.status_processing')) ?></option>
        <option value="shipped"    <?= $filter==='shipped'   ?'selected':'' ?>><?= e(t('admin.orders.status_shipped')) ?></option>
        <option value="delivered"  <?= $filter==='delivered' ?'selected':'' ?>><?= e(t('admin.orders.status_delivered')) ?></option>
        <option value="cancelled"  <?= $filter==='cancelled' ?'selected':'' ?>><?= e(t('admin.orders.status_cancelled')) ?></option>
      </select>
      <input type="text" name="q" class="ag-inp" placeholder="<?= e(t('admin.orders.search_placeholder')) ?>" value="<?= e($search) ?>">
      <button type="submit" class="btn-xs btn-gold-xs"><?= e(t('admin.orders.search')) ?></button>
      <?php if ($search || $filter !== 'all'): ?>
        <a href="<?= View::url('/admin/orders') ?>" class="btn-xs btn-ghost-xs"><?= e(t('admin.orders.clear')) ?></a>
      <?php endif; ?>
    </form>
  </div>
</div>

<div class="ag-card">
  <?php if (empty($orders)): ?>
    <div class="empty-s"><i class="bi bi-receipt" style="font-size:40px;display:block;margin-bottom:10px;opacity:.3"></i><?= e(t('admin.orders.no_orders_found')) ?></div>
  <?php else: ?>
  <table class="ag-tbl">
    <thead>
      <tr>
        <th><?= e(t('admin.dashboard.col_ref')) ?></th><th><?= e(t('admin.dashboard.col_customer')) ?></th><th><?= e(t('admin.orders.col_city')) ?></th><th><?= e(t('admin.dashboard.col_total')) ?></th><th><?= e(t('admin.nav.payments')) ?></th><th><?= e(t('common.status')) ?></th><th><?= e(t('common.date')) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td style="font-family:monospace;font-weight:700;color:var(--gold);font-size:11px"><?= e($o['code']) ?></td>
          <td>
            <div style="font-weight:600;color:var(--dark-text)"><?= e($o['full_name']) ?></div>
            <?php if (!empty($o['user_email'])): ?>
              <div style="font-size:10.5px;color:var(--dark-muted)"><?= e($o['user_email']) ?></div>
            <?php endif; ?>
          </td>
          <td style="font-size:11.5px"><?= e($o['city'] ?? '-') ?></td>
          <td style="white-space:nowrap">
            <span style="color:var(--gold-lt);font-weight:700">ETB <?= number_format((float)$o['total'], 2) ?></span><br>
            <span class="badge badge-<?= $o['payment_status']==='paid'?'paid':'unpaid' ?>"><?= ucfirst($o['payment_status'] ?? 'pending') ?></span>
          </td>
          <td style="font-size:11px;color:var(--dark-muted)"><?= e(str_replace('_',' ', $o['payment_method'] ?? '-')) ?></td>
          <td><span class="badge badge-<?= e($o['status']) ?>"><?= ucfirst($o['status']) ?></span></td>
          <td style="font-size:11px;color:var(--dark-muted);white-space:nowrap"><?= date('d M Y', strtotime($o['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($pages > 1): ?>
    <div class="ag-pagination">
      <?php if ($page > 1): ?>
        <a href="?filter=<?= urlencode($filter) ?>&q=<?= urlencode($search) ?>&page=<?= $page-1 ?>" class="pg-link">&laquo;</a>
      <?php endif; ?>
      <?php for ($i = max(1,$page-2); $i <= min($pages,$page+2); $i++): ?>
        <a href="?filter=<?= urlencode($filter) ?>&q=<?= urlencode($search) ?>&page=<?= $i ?>" class="pg-link<?= $i===$page?' cur':'' ?>"><?= $i ?></a>
      <?php endfor; ?>
      <?php if ($page < $pages): ?>
        <a href="?filter=<?= urlencode($filter) ?>&q=<?= urlencode($search) ?>&page=<?= $page+1 ?>" class="pg-link">&raquo;</a>
      <?php endif; ?>
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
