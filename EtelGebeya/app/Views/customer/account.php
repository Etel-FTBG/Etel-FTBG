<?php
use App\Core\View;
$locale = $locale ?? 'en';
$u      = $user ?? [];

ob_start();
?>
<style>
/* ?? ACCOUNT DASHBOARD ???????????????????????????????????? */
.acc-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:.9rem;margin-bottom:1.5rem}
.acc-stat{background:var(--card,#130d05);border:1.5px solid var(--border-dim,rgba(212,175,55,.08));border-radius:18px;padding:1.2rem 1.3rem;position:relative;overflow:hidden;transition:transform .25s,border-color .25s}
.acc-stat:hover{transform:translateY(-3px);border-color:var(--gold-line,rgba(212,175,55,.22))}
.acc-stat::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px;border-radius:0 0 18px 18px}
.acc-stat.gold::after{background:var(--grad-gold,linear-gradient(90deg,#FFE066,#D4AF37))}
.acc-stat.green::after{background:linear-gradient(90deg,#10b981,#059669)}
.acc-stat.blue::after{background:linear-gradient(90deg,#60a5fa,#3b82f6)}
.acc-stat-icon{width:38px;height:38px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;margin-bottom:.75rem}
.acc-stat-val{font-size:1.7rem;font-weight:900;color:var(--text,#F7EFD9);letter-spacing:-.03em;line-height:1}
.acc-stat-lbl{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted,#7A6840);margin-top:.35rem}

.acc-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.1rem}
@media(max-width:580px){.acc-grid{grid-template-columns:1fr}}
.acc-card{background:var(--card,#130d05);border:1.5px solid var(--border-dim,rgba(212,175,55,.08));border-radius:18px;overflow:hidden}
.acc-card-head{display:flex;align-items:center;justify-content:space-between;padding:.85rem 1.2rem;border-bottom:1px solid rgba(212,175,55,.07)}
.acc-card-title{font-size:.88rem;font-weight:800;color:var(--text,#F7EFD9);display:flex;align-items:center;gap:.5rem}
.acc-card-link{font-size:.75rem;color:var(--g2,#D4AF37);text-decoration:none;font-weight:700}
.acc-card-link:hover{color:var(--g1,#FFE066)}
.acc-card-body{padding:1rem 1.2rem}

.ord-row{display:flex;align-items:center;justify-content:space-between;padding:.55rem 0;border-bottom:1px solid rgba(212,175,55,.05)}
.ord-row:last-child{border-bottom:none}
.ord-code{font-family:monospace;color:var(--g2,#D4AF37);font-weight:700;font-size:.82rem}
.ord-date{font-size:.72rem;color:var(--text-muted,#7A6840);margin-top:.12rem}
.ord-amt{font-weight:800;font-size:.85rem;color:var(--text,#F7EFD9);white-space:nowrap}

.sp{display:inline-flex;padding:.18rem .55rem;border-radius:99px;font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em}
.sp-pending,.sp-processing{background:rgba(212,175,55,.1);color:var(--g2,#D4AF37);border:1px solid rgba(212,175,55,.2)}
.sp-completed,.sp-delivered{background:rgba(16,185,129,.1);color:#10b981;border:1px solid rgba(16,185,129,.2)}
.sp-cancelled,.sp-refunded{background:rgba(239,68,68,.1);color:#ef4444;border:1px solid rgba(239,68,68,.2)}
.sp-shipped{background:rgba(96,165,250,.1);color:#60a5fa;border:1px solid rgba(96,165,250,.2)}

.fg{margin-bottom:.85rem}
.fg label{display:block;font-size:.72rem;font-weight:700;color:var(--text-muted,#7A6840);margin-bottom:.38rem;text-transform:uppercase;letter-spacing:.06em}
.fg input{width:100%;padding:.65rem 1rem;background:rgba(212,175,55,.04);border:1.5px solid var(--border-dim,rgba(212,175,55,.1));border-radius:12px;color:var(--text,#F7EFD9);font-size:.88rem;font-family:inherit;outline:none;box-sizing:border-box;transition:border-color .2s}
.fg input:focus{border-color:var(--g2,#D4AF37)}
.pass-divider{border-top:1px solid var(--border-dim,rgba(212,175,55,.08));margin:1rem 0;padding-top:1rem}
.pass-divider-label{font-size:.72rem;font-weight:700;color:var(--text-muted,#7A6840);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.75rem}
.btn-gold-sm{display:inline-flex;align-items:center;gap:.4rem;background:var(--grad-gold,linear-gradient(135deg,#FFE066,#D4AF37,#B8902E));color:#1a0e00;font-weight:800;padding:.6rem 1.3rem;border-radius:11px;border:none;cursor:pointer;font-size:.85rem;font-family:inherit;transition:all .2s;box-shadow:0 6px 20px rgba(212,175,55,.3)}
.btn-gold-sm:hover{transform:translateY(-1px);box-shadow:0 10px 28px rgba(212,175,55,.4)}

.flash-box{padding:.8rem 1.1rem;border-radius:12px;margin-bottom:1.1rem;border:1.5px solid;font-size:.85rem;display:flex;align-items:center;gap:.5rem}
.flash-error{background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.25);color:#ef4444}
.flash-success{background:rgba(16,185,129,.08);border-color:rgba(16,185,129,.25);color:#10b981}

.empty-panel{text-align:center;padding:2rem 1rem}
.empty-panel i{font-size:2.2rem;color:rgba(212,175,55,.15);display:block;margin-bottom:.6rem}
.empty-panel p{font-size:.82rem;color:var(--text-muted,#7A6840);margin:0 0 .9rem}

.qa-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:.6rem;margin-top:1.1rem}
.qa-btn{display:flex;flex-direction:column;align-items:center;gap:.4rem;padding:.85rem .6rem;background:var(--card,#130d05);border:1.5px solid var(--border-dim,rgba(212,175,55,.08));border-radius:14px;text-decoration:none;transition:all .2s}
.qa-btn:hover{border-color:rgba(212,175,55,.3);background:rgba(212,175,55,.04);transform:translateY(-2px)}
.qa-btn i{font-size:1.3rem}
.qa-btn span{font-size:.72rem;font-weight:700;color:var(--text-muted,#7A6840);text-align:center}
</style>

<?php if (!empty($flash)): ?>
  <div class="flash-box flash-<?= $flash['type']==='error'?'error':'success' ?>">
    <i class="bi bi-<?= $flash['type']==='error'?'exclamation-circle':'check-circle' ?>"></i>
    <?= e($flash['msg']) ?>
  </div>
<?php endif; ?>

<!-- Page header -->
<div style="margin-bottom:1.25rem">
  <h1 class="cp-page-title"><i class="bi bi-speedometer2"></i> <?= e(t('customer.nav.dashboard')) ?></h1>
  <p style="font-size:.82rem;color:var(--text-muted,#7A6840);margin:-.5rem 0 0"><?= e(t('customer.dashboard.member_since')) ?> <?= date('F Y', strtotime($u['created_at']??'now')) ?></p>
</div>

<!-- Stats -->
<div class="acc-stats">
  <div class="acc-stat gold">
    <div class="acc-stat-icon" style="background:rgba(212,175,55,.1)"><i class="bi bi-receipt" style="color:var(--g2,#D4AF37)"></i></div>
    <div class="acc-stat-val"><?= number_format($orderCount) ?></div>
    <div class="acc-stat-lbl"><?= e(t('customer.dashboard.total_orders')) ?></div>
  </div>
  <div class="acc-stat green">
    <div class="acc-stat-icon" style="background:rgba(16,185,129,.1)"><i class="bi bi-cash-stack" style="color:#10b981"></i></div>
    <div class="acc-stat-val" style="font-size:<?= strlen(number_format($totalSpent,0))>7?'1.1rem':'1.7rem' ?>">Br <?= number_format($totalSpent,0) ?></div>
    <div class="acc-stat-lbl"><?= e(t('customer.dashboard.total_spent')) ?></div>
  </div>
  <div class="acc-stat blue">
    <div class="acc-stat-icon" style="background:rgba(96,165,250,.1)"><i class="bi bi-heart" style="color:#60a5fa"></i></div>
    <div class="acc-stat-val">0</div>
    <div class="acc-stat-lbl"><?= e(t('customer.nav.wishlist')) ?></div>
  </div>
</div>

<div class="acc-grid">

  <!-- Recent Orders -->
  <div class="acc-card">
    <div class="acc-card-head">
      <div class="acc-card-title"><i class="bi bi-receipt" style="color:var(--g2,#D4AF37)"></i> <?= e(t('customer.dashboard.recent_orders')) ?></div>
      <a href="<?= View::url('/account/orders') ?>" class="acc-card-link"><?= e(t('customer.dashboard.view_all')) ?></a>
    </div>
    <div class="acc-card-body">
      <?php if (empty($orders)): ?>
        <div class="empty-panel">
          <i class="bi bi-cart-x"></i>
          <p><?= e(t('customer.dashboard.no_orders_yet')) ?></p>
          <a href="<?= View::url('/') ?>" class="btn-gold-sm"><i class="bi bi-shop"></i> <?= e(t('storefront.cart.start_shopping')) ?></a>
        </div>
      <?php else: ?>
        <?php foreach ($orders as $o): ?>
          <div class="ord-row">
            <div>
              <div class="ord-code">#<?= e($o['code']) ?></div>
              <div class="ord-date"><?= date('M j, Y', strtotime($o['created_at'])) ?></div>
            </div>
            <div style="display:flex;align-items:center;gap:.65rem">
              <span class="ord-amt">Br <?= number_format((float)($o['total']??0),0) ?></span>
              <span class="sp sp-<?= $o['status'] ?>"><?= $o['status'] ?></span>
              <a href="<?= View::url('/account/orders/'.$o['code']) ?>" style="color:var(--g2,#D4AF37);font-size:.75rem;text-decoration:none"><?= e(t('customer.dashboard.view')) ?></a>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Profile -->
  <div class="acc-card">
    <div class="acc-card-head">
      <div class="acc-card-title"><i class="bi bi-person-circle" style="color:#60a5fa"></i> <?= e(t('customer.dashboard.edit_profile')) ?></div>
    </div>
    <div class="acc-card-body">
      <form method="POST" action="<?= View::url('/account/profile') ?>">
        <?= csrf() ?>
        <div class="fg">
          <label><?= e(t('auth.full_name')) ?></label>
          <input type="text" name="full_name" required value="<?= e($u['full_name']??'') ?>">
        </div>
        <div class="fg">
          <label><?= e(t('storefront.checkout.phone_label')) ?></label>
          <input type="tel" name="phone" value="<?= e($u['phone']??'') ?>" placeholder="+251 9xx xxx xxx">
        </div>
        <div class="pass-divider">
          <div class="pass-divider-label"><?= e(t('customer.dashboard.change_password')) ?></div>
          <div class="fg">
            <label><?= e(t('customer.dashboard.new_password')) ?></label>
            <input type="password" name="new_password" minlength="8" placeholder="<?= e(t('customer.dashboard.leave_blank_hint')) ?>">
          </div>
          <div class="fg" style="margin-bottom:1.1rem">
            <label><?= e(t('auth.confirm_password')) ?></label>
            <input type="password" name="confirm_password" placeholder="<?= e(t('auth.repeat_password')) ?>">
          </div>
        </div>
        <button type="submit" class="btn-gold-sm"><i class="bi bi-check-lg"></i> <?= e(t('customer.dashboard.save_changes')) ?></button>
      </form>
    </div>
  </div>

</div>

<!-- Quick actions -->
<div class="qa-grid">
  <?php foreach ([
    ['/account/orders',   'bi-receipt',    '#D4AF37',t('customer.nav.my_orders')],
    ['/account/addresses','bi-geo-alt',    '#60a5fa',t('customer.nav.addresses')],
    ['/',                 'bi-bag',        '#10b981',t('customer.dashboard.shop_now')],
  ] as [$url,$icon,$col,$lbl]): ?>
  <a href="<?= View::url($url) ?>" class="qa-btn">
    <i class="bi <?= $icon ?>" style="color:<?= $col ?>"></i>
    <span><?= $lbl ?></span>
  </a>
  <?php endforeach; ?>
</div>

<?php
$__content = ob_get_clean();

// Build sidebar+content wrapper
ob_start();
$__sidebar_content = $__content;
require __DIR__ . '/_layout.php';
$__panel = ob_get_clean();

echo View::capture('_layouts/public', [
    '__content' => $__panel,
    'pageTitle'  => t('storefront.footer.my_account') . ' -- Etel Gebeya',
    'locale'     => $locale,
]);
