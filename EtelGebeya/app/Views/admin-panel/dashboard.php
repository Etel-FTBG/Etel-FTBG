<?php
use App\Core\View;
require __DIR__.'/_layout.php';
$pageTitle = t('admin.nav.dashboard');
ob_start();
?>

<style>
.ag-stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:24px}
.ag-stat{background:var(--dark-card);border:1px solid var(--dark-border);border-radius:var(--r-md);padding:18px 16px;position:relative;overflow:hidden}
.ag-stat::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--gold-br),var(--gold))}
.ag-stat-lbl{font-size:10px;color:var(--dark-muted);text-transform:uppercase;letter-spacing:1.2px;font-weight:700}
.ag-stat-val{font-size:28px;font-weight:800;color:var(--gold-lt);line-height:1.1;margin:7px 0 4px}
.ag-stat-hint{font-size:11.5px;color:var(--dark-muted)}
.ag-stat-ico{position:absolute;right:13px;top:50%;transform:translateY(-50%);font-size:32px;opacity:.1;color:var(--gold)}
.ag-two{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
@media(max-width:860px){.ag-two{grid-template-columns:1fr}}
.ag-card{background:var(--dark-card);border:1px solid var(--dark-border);border-radius:var(--r-md);overflow:hidden;margin-bottom:16px}
.ag-card-head{display:flex;align-items:center;justify-content:space-between;padding:13px 16px;border-bottom:1px solid var(--dark-border)}
.ag-card-title{font-size:13.5px;font-weight:700;color:var(--dark-text)}
.ag-tbl{width:100%;border-collapse:collapse}
.ag-tbl th{background:rgba(255,255,255,.03);padding:9px 14px;text-align:left;font-size:10px;font-weight:700;color:var(--dark-muted);text-transform:uppercase;letter-spacing:.8px;border-bottom:1px solid var(--dark-border);white-space:nowrap}
.ag-tbl td{padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);font-size:12.5px;color:var(--dark-muted);vertical-align:middle}
.ag-tbl tr:last-child td{border-bottom:none}
.ag-tbl tr:hover td{background:rgba(255,255,255,.02)}
.badge{display:inline-block;padding:3px 8px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap}
.badge-new,.badge-pending{background:rgba(96,180,255,.15);color:var(--blue);border:1px solid rgba(96,180,255,.25)}
.badge-processing{background:rgba(255,149,0,.15);color:var(--orange);border:1px solid rgba(255,149,0,.25)}
.badge-shipped{background:rgba(155,89,182,.15);color:var(--purple);border:1px solid rgba(155,89,182,.25)}
.badge-delivered,.badge-published,.badge-active{background:rgba(46,204,113,.15);color:var(--green);border:1px solid rgba(46,204,113,.25)}
.badge-rejected,.badge-cancelled,.badge-suspended{background:rgba(231,76,60,.15);color:var(--red);border:1px solid rgba(231,76,60,.25)}
.btn-xs{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;border:none;transition:opacity .12s;text-decoration:none;white-space:nowrap}
.btn-gold-xs{background:linear-gradient(135deg,var(--gold-br),var(--gold));color:#1A1A2E}
.btn-ghost-xs{background:rgba(212,175,55,.1);color:var(--gold);border:1px solid rgba(212,175,55,.2)}
.btn-danger-xs{background:rgba(231,76,60,.12);color:var(--red);border:1px solid rgba(231,76,60,.2)}
.btn-xs:hover{opacity:.8}
.empty-s{text-align:center;padding:32px 16px;color:var(--dark-muted);font-size:12.5px}
</style>

<div class="ag-stats">
  <div class="ag-stat">
    <i class="bi bi-receipt ag-stat-ico"></i>
    <div class="ag-stat-lbl"><?= e(t('admin.dashboard.total_orders')) ?></div>
    <div class="ag-stat-val"><?= number_format($stats['orders_total']) ?></div>
    <div class="ag-stat-hint"><?= e(t('admin.dashboard.new_pending_count', ['n' => $stats['orders_new']])) ?></div>
  </div>
  <div class="ag-stat">
    <i class="bi bi-currency-dollar ag-stat-ico"></i>
    <div class="ag-stat-lbl"><?= e(t('admin.dashboard.revenue_paid')) ?></div>
    <div class="ag-stat-val" style="font-size:20px;margin-top:9px">ETB <?= number_format($stats['revenue'], 0) ?></div>
    <div class="ag-stat-hint"><?= e(t('admin.dashboard.from_paid_orders')) ?></div>
  </div>
  <div class="ag-stat">
    <i class="bi bi-box-seam ag-stat-ico"></i>
    <div class="ag-stat-lbl"><?= e(t('admin.nav.products')) ?></div>
    <div class="ag-stat-val"><?= number_format($stats['products_total']) ?></div>
    <div class="ag-stat-hint"><?= e(t('admin.dashboard.awaiting_review', ['n' => $stats['products_pending']])) ?></div>
  </div>
  <div class="ag-stat">
    <i class="bi bi-shop ag-stat-ico"></i>
    <div class="ag-stat-lbl"><?= e(t('admin.nav.sellers')) ?></div>
    <div class="ag-stat-val"><?= number_format($stats['sellers_total']) ?></div>
    <div class="ag-stat-hint"><?= e(t('admin.dashboard.pending_count', ['n' => $stats['sellers_pending']])) ?></div>
  </div>
  <div class="ag-stat">
    <i class="bi bi-people ag-stat-ico"></i>
    <div class="ag-stat-lbl"><?= e(t('admin.nav.users')) ?></div>
    <div class="ag-stat-val"><?= number_format($stats['users_total']) ?></div>
    <div class="ag-stat-hint"><?= e(t('admin.dashboard.registered_accounts')) ?></div>
  </div>
</div>

<div class="ag-two">
  <div class="ag-card">
    <div class="ag-card-head">
      <span class="ag-card-title"><i class="bi bi-receipt" style="color:var(--gold)"></i> <?= e(t('admin.dashboard.recent_orders')) ?></span>
      <a href="<?= View::url('/admin/orders') ?>" class="btn-xs btn-ghost-xs"><?= e(t('common.view_all')) ?></a>
    </div>
    <table class="ag-tbl">
      <thead><tr><th><?= e(t('admin.dashboard.col_ref')) ?></th><th><?= e(t('admin.dashboard.col_customer')) ?></th><th><?= e(t('admin.dashboard.col_total')) ?></th><th><?= e(t('common.status')) ?></th></tr></thead>
      <tbody>
        <?php if (empty($recent_orders)): ?>
          <tr><td colspan="4" class="empty-s"><?= e(t('admin.dashboard.no_orders_yet')) ?></td></tr>
        <?php else: foreach ($recent_orders as $o): ?>
          <tr>
            <td style="font-family:monospace;color:var(--gold);font-size:11px"><?= e($o['code']) ?></td>
            <td><?= e($o['full_name']) ?></td>
            <td style="color:var(--gold-lt);font-weight:700;white-space:nowrap">ETB <?= number_format((float)$o['total'], 2) ?></td>
            <td><span class="badge badge-<?= e($o['status']) ?>"><?= ucfirst($o['status']) ?></span></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="ag-card">
    <div class="ag-card-head">
      <span class="ag-card-title"><i class="bi bi-box-seam" style="color:var(--orange)"></i> <?= e(t('admin.dashboard.pending_products')) ?></span>
      <a href="<?= View::url('/admin/products?filter=pending') ?>" class="btn-xs btn-ghost-xs"><?= e(t('common.view_all')) ?></a>
    </div>
    <table class="ag-tbl">
      <thead><tr><th><?= e(t('admin.dashboard.col_product')) ?></th><th><?= e(t('admin.dashboard.col_seller')) ?></th><th><?= e(t('admin.dashboard.col_price')) ?></th><th><?= e(t('admin.dashboard.col_act')) ?></th></tr></thead>
      <tbody>
        <?php if (empty($pending_products)): ?>
          <tr><td colspan="4" class="empty-s"><?= e(t('admin.dashboard.no_pending_products')) ?></td></tr>
        <?php else: foreach ($pending_products as $p): ?>
          <tr>
            <td style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--dark-text)"><?= e($p['name_en']) ?></td>
            <td style="font-size:11px"><?= e($p['store_name'] ?? '-') ?></td>
            <td style="white-space:nowrap;color:var(--gold)">ETB <?= number_format((float)$p['price'], 2) ?></td>
            <td style="white-space:nowrap">
              <form method="POST" action="<?= View::url('/admin/products/' . (int)$p['id'] . '/approve') ?>" style="display:inline">
                <?= csrf() ?>
                <button type="submit" class="btn-xs btn-gold-xs" onclick="return confirm('<?= e(addslashes(t('admin.dashboard.approve_confirm'))) ?>')"><?= e(t('admin.dashboard.ok')) ?></button>
              </form>
              <form method="POST" action="<?= View::url('/admin/products/' . (int)$p['id'] . '/reject') ?>" style="display:inline;margin-left:4px">
                <?= csrf() ?>
                <button type="submit" class="btn-xs btn-danger-xs" onclick="return confirm('<?= e(addslashes(t('admin.dashboard.reject_confirm'))) ?>')">X</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (!empty($pending_sellers)): ?>
<div class="ag-card">
  <div class="ag-card-head">
    <span class="ag-card-title"><i class="bi bi-shop" style="color:var(--gold)"></i> <?= e(t('admin.dashboard.pending_seller_applications')) ?></span>
    <a href="<?= View::url('/admin/vendors?filter=pending') ?>" class="btn-xs btn-ghost-xs"><?= e(t('common.view_all')) ?></a>
  </div>
  <table class="ag-tbl">
    <thead><tr><th><?= e(t('admin.dashboard.col_store')) ?></th><th><?= e(t('admin.dashboard.col_owner')) ?></th><th><?= e(t('admin.dashboard.col_email')) ?></th><th><?= e(t('admin.dashboard.col_phone')) ?></th><th><?= e(t('common.actions')) ?></th></tr></thead>
    <tbody>
      <?php foreach ($pending_sellers as $s): ?>
        <tr>
          <td style="font-weight:700;color:var(--dark-text)"><?= e($s['store_name']) ?></td>
          <td><?= e($s['full_name'] ?? '-') ?></td>
          <td style="font-size:11.5px"><?= e($s['email'] ?? '-') ?></td>
          <td style="font-size:11.5px"><?= e($s['phone'] ?? '-') ?></td>
          <td style="white-space:nowrap">
            <form method="POST" action="<?= View::url('/admin/vendors/' . (int)$s['id'] . '/approve') ?>" style="display:inline">
              <?= csrf() ?>
              <button type="submit" class="btn-xs btn-gold-xs" onclick="return confirm('<?= e(addslashes(t('admin.dashboard.approve_seller_confirm'))) ?>')"><?= e(t('admin.dashboard.approve')) ?></button>
            </form>
            <form method="POST" action="<?= View::url('/admin/vendors/' . (int)$s['id'] . '/suspend') ?>" style="display:inline;margin-left:4px">
              <?= csrf() ?>
              <button type="submit" class="btn-xs btn-danger-xs" onclick="return confirm('<?= e(addslashes(t('admin.dashboard.reject_seller_confirm'))) ?>')"><?= e(t('admin.dashboard.reject')) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php
$__content = ob_get_clean();
echo View::capture('_layouts/dark', array_merge(get_defined_vars(), [
    '__content' => $__content,
    'navItems'  => $navItems,
    'role'      => 'admin',
    'pageTitle' => $pageTitle,
]));
