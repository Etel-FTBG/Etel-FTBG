<?php
use App\Core\View;
require __DIR__.'/_layout.php';
ob_start();
?>
<style>
/* Dashboard specific */
.vd-hero{background:linear-gradient(135deg,rgba(212,175,55,.1) 0%,rgba(212,175,55,.03) 50%,rgba(96,180,255,.05) 100%);border:1.5px solid rgba(212,175,55,.18);border-radius:22px;padding:1.75rem 2rem;margin-bottom:1.5rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;position:relative;overflow:hidden}
.vd-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:200px;height:200px;background:radial-gradient(circle,rgba(212,175,55,.12),transparent 70%);pointer-events:none}
.vd-hero-left h1{font-size:1.6rem;font-weight:900;color:#fff;margin:0 0 .3rem}
.vd-hero-left p{font-size:.88rem;color:var(--dark-muted);margin:0}
.vd-hero-store{display:inline-flex;align-items:center;gap:.4rem;background:rgba(212,175,55,.12);border:1px solid rgba(212,175,55,.25);border-radius:8px;padding:.3rem .7rem;font-size:.78rem;font-weight:700;color:var(--gold);margin-top:.6rem}
.vd-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem}
.vd-stat{background:var(--dark-card);border:1.5px solid var(--dark-border);border-radius:18px;padding:1.25rem 1.4rem;position:relative;overflow:hidden;transition:transform .25s,box-shadow .25s;cursor:default}
.vd-stat:hover{transform:translateY(-4px);box-shadow:0 16px 40px -16px rgba(0,0,0,.4)}
.vd-stat::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px;border-radius:0 0 18px 18px}
.vd-stat.gold::after{background:linear-gradient(90deg,var(--gold-br),var(--gold))}
.vd-stat.blue::after{background:linear-gradient(90deg,#60b4ff,#3b82f6)}
.vd-stat.green::after{background:linear-gradient(90deg,#2ecc71,#1abc9c)}
.vd-stat.purple::after{background:linear-gradient(90deg,#9b59b6,#8e44ad)}
.vd-stat-icon{width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;margin-bottom:.9rem}
.vd-stat-val{font-size:1.8rem;font-weight:900;color:#fff;font-family:'Sora',sans-serif;letter-spacing:-.03em;line-height:1}
.vd-stat-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--dark-muted);margin-top:.4rem}
.vd-stat-sub{font-size:.75rem;color:var(--dark-muted);margin-top:.5rem}

/* Quick actions */
.qa-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;margin-bottom:1.5rem}
.qa-btn{display:flex;flex-direction:column;align-items:center;gap:.5rem;padding:1rem .75rem;border-radius:16px;border:1.5px solid var(--dark-border);background:var(--dark-card);text-decoration:none;transition:all .2s;cursor:pointer}
.qa-btn:hover{background:rgba(212,175,55,.1);transform:translateY(-2px)}
.qa-btn i{font-size:1.4rem}
.qa-btn span{font-size:.75rem;font-weight:700;color:var(--dark-muted);text-align:center}

/* Data cards */
.vd-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem}
.vd-card{background:var(--dark-card);border:1.5px solid var(--dark-border);border-radius:18px;overflow:hidden}
.vd-card-head{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--dark-border)}
.vd-card-title{font-size:.9rem;font-weight:800;color:#fff;display:flex;align-items:center;gap:.5rem}
.vd-card-link{font-size:.78rem;font-weight:700;color:var(--gold);text-decoration:none;transition:opacity .2s}
.vd-card-link:hover{opacity:.75}
.vd-table{width:100%;border-collapse:collapse}
.vd-table th{font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--gold);padding:.65rem 1rem;background:rgba(255,255,255,.025);border-bottom:1px solid rgba(255,255,255,.06)}
.vd-table td{padding:.7rem 1rem;border-bottom:1px solid rgba(255,255,255,.04);font-size:.83rem;color:var(--dark-text)}
.vd-table tr:last-child td{border-bottom:none}
.vd-table tr:hover td{background:rgba(212,175,55,.04)}
.vd-thumb{width:36px;height:36px;border-radius:8px;object-fit:cover;border:1px solid rgba(255,255,255,.1)}
.vd-thumb-placeholder{width:36px;height:36px;border-radius:8px;background:rgba(255,255,255,.06);display:flex;align-items:center;justify-content:center;color:var(--dark-muted);font-size:.9rem;flex-shrink:0}

/* Status pills */
.sp{display:inline-flex;align-items:center;padding:.18rem .55rem;border-radius:99px;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.sp-active,.sp-completed,.sp-delivered{background:rgba(46,204,113,.12);color:#2ecc71}
.sp-draft{background:rgba(136,136,168,.12);color:var(--dark-muted)}
.sp-pending,.sp-processing{background:rgba(212,175,55,.12);color:var(--gold)}
.sp-cancelled,.sp-refunded{background:rgba(231,76,60,.12);color:#e74c3c}

/* Empty state */
.empty-state{padding:2.5rem;text-align:center}
.empty-state i{font-size:2.5rem;color:rgba(255,255,255,.12);display:block;margin-bottom:.75rem}
.empty-state p{color:var(--dark-muted);font-size:.85rem;margin:.25rem 0 1rem}

/* Onboarding checklist */
.checklist{display:flex;flex-direction:column;gap:.5rem}
.check-item{display:flex;align-items:center;gap:.75rem;padding:.7rem 1rem;border-radius:12px;border:1.5px solid var(--dark-border);background:var(--dark-card);text-decoration:none;transition:background .2s}
.check-item:hover{background:rgba(212,175,55,.1)}
.check-item.done{opacity:.55}
.check-icon{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}
.check-icon.done{background:rgba(46,204,113,.15);color:#2ecc71}
.check-icon.todo{background:rgba(212,175,55,.12);color:var(--gold);border:1.5px solid rgba(212,175,55,.3)}
.check-text{flex:1;font-size:.83rem;font-weight:600;color:var(--dark-text)}
.check-text span{display:block;font-size:.72rem;color:var(--dark-muted);font-weight:400;margin-top:.1rem}
</style>

<!-- Hero -->
<div class="vd-hero">
  <div class="vd-hero-left">
    <h1>Welcome back, <?= e(explode(' ', $seller['store_name'] ?? 'Vendor')[0]) ?> &#128075;</h1>
    <p>Here's what's happening with your store today</p>
    <div class="vd-hero-store">
      <i class="bi bi-shop"></i> <?= e($seller['store_name'] ?? '') ?>
    </div>
  </div>
  <a href="<?= View::url('/vendor-panel/products/new') ?>" class="btn btn-gold">
    <i class="bi bi-plus-lg"></i> Add Product
  </a>
</div>

<!-- Stats -->
<div class="vd-stats">
  <div class="vd-stat gold">
    <div class="vd-stat-icon icon-gold"><i class="bi bi-box-seam"></i></div>
    <div class="vd-stat-val"><?= number_format($productCount) ?></div>
    <div class="vd-stat-label">Total Products</div>
    <div class="vd-stat-sub"><?= number_format($activeCount) ?> active * <?= number_format($productCount - $activeCount) ?> draft</div>
  </div>
  <div class="vd-stat blue">
    <div class="vd-stat-icon icon-blue"><i class="bi bi-receipt"></i></div>
    <div class="vd-stat-val"><?= number_format($orderCount) ?></div>
    <div class="vd-stat-label">Total Orders</div>
    <div class="vd-stat-sub">Lifetime orders</div>
  </div>
  <div class="vd-stat green">
    <div class="vd-stat-icon icon-green"><i class="bi bi-cash-stack"></i></div>
    <div class="vd-stat-val" style="font-size:<?= strlen(number_format($revenue,0))>8?'1.2rem':'1.8rem' ?>">Br <?= number_format($revenue, 0) ?></div>
    <div class="vd-stat-label">Total Revenue</div>
    <div class="vd-stat-sub">Confirmed orders only</div>
  </div>
  <div class="vd-stat purple">
    <div class="vd-stat-icon icon-purple"><i class="bi bi-graph-up-arrow"></i></div>
    <div class="vd-stat-val"><?= $productCount > 0 ? (int)round($revenue / max($productCount,1)) : 0 ?></div>
    <div class="vd-stat-label">Avg Rev/Product</div>
    <div class="vd-stat-sub">Revenue per listing</div>
  </div>
</div>

<!-- Quick actions -->
<div class="qa-grid" style="margin-bottom:1.5rem">
  <?php foreach ([
    ['/vendor-panel/products/new','bi-plus-square-dotted','#60b4ff','List Product'],
    ['/vendor-panel/orders',     'bi-receipt',           '#2ecc71','View Orders'],
    ['/vendor-panel/settings',   'bi-gear',              '#D4AF37','Store Settings'],
    ['/vendor/'.e($seller['store_slug']??''), 'bi-shop', '#9b59b6','My Storefront'],
  ] as [$url,$icon,$color,$label]): ?>
  <a href="<?= View::url($url) ?>" class="qa-btn">
    <i class="bi <?= $icon ?>" style="color:<?= $color ?>"></i>
    <span><?= $label ?></span>
  </a>
  <?php endforeach; ?>
</div>

<div class="vd-grid">

  <!-- Recent Products -->
  <div class="vd-card">
    <div class="vd-card-head">
      <div class="vd-card-title"><i class="bi bi-box-seam" style="color:var(--gold)"></i> Recent Products</div>
      <a href="<?= View::url('/vendor-panel/products') ?>" class="vd-card-link">View all <i class="bi bi-arrow-right"></i></a>
    </div>
    <?php if (empty($recentProducts)): ?>
      <div class="empty-state">
        <i class="bi bi-box2"></i>
        <p>You haven't listed any products yet.</p>
        <a href="<?= View::url('/vendor-panel/products/new') ?>" class="btn btn-gold btn-sm"><i class="bi bi-plus"></i> Add First Product</a>
      </div>
    <?php else: ?>
    <table class="vd-table">
      <thead><tr><th colspan="2">Product</th><th>Price</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($recentProducts as $pr): ?>
        <tr>
          <td style="width:46px;padding-right:0">
            <?php if (!empty($pr['thumb'])): ?>
              <img src="<?= e($pr['thumb']) ?>" class="vd-thumb" alt="">
            <?php else: ?>
              <div class="vd-thumb-placeholder"><i class="bi bi-image"></i></div>
            <?php endif; ?>
          </td>
          <td>
            <a href="<?= View::url('/vendor-panel/products/'.$pr['id'].'/edit') ?>" style="color:var(--dark-text);text-decoration:none;font-weight:600;font-size:.82rem">
              <?= e(mb_substr(html_entity_decode($pr['name_en'],ENT_QUOTES,'UTF-8'), 0, 28, 'UTF-8')) ?>...
            </a>
            <div style="font-size:.72rem;color:var(--dark-muted)">Qty: <?= (int)$pr['stock_qty'] ?></div>
          </td>
          <td style="font-weight:700;white-space:nowrap">Br <?= number_format((float)$pr['price'],0) ?></td>
          <td><span class="sp sp-<?= $pr['status'] ?>"><?= $pr['status'] ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- Right side: recent orders + onboarding -->
  <div style="display:flex;flex-direction:column;gap:1.25rem">

    <!-- Recent Orders -->
    <div class="vd-card">
      <div class="vd-card-head">
        <div class="vd-card-title"><i class="bi bi-receipt" style="color:#60b4ff"></i> Recent Orders</div>
        <a href="<?= View::url('/vendor-panel/orders') ?>" class="vd-card-link">View all <i class="bi bi-arrow-right"></i></a>
      </div>
      <?php if (empty($recentOrders)): ?>
        <div class="empty-state">
          <i class="bi bi-cart-x"></i>
          <p>No orders yet -- share your store link!</p>
        </div>
      <?php else: ?>
      <table class="vd-table">
        <thead><tr><th>Order</th><th>Total</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($recentOrders as $o): ?>
          <tr>
            <td>
              <span style="color:var(--gold);font-family:monospace;font-weight:700;font-size:.8rem">#<?= e($o['code']) ?></span>
              <div style="font-size:.72rem;color:var(--dark-muted)"><?= date('M j', strtotime($o['created_at'])) ?></div>
            </td>
            <td style="font-weight:700">Br <?= number_format((float)$o['total'],0) ?></td>
            <td><span class="sp sp-<?= $o['status'] ?>"><?= $o['status'] ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <!-- Onboarding checklist -->
    <div class="vd-card">
      <div class="vd-card-head">
        <div class="vd-card-title"><i class="bi bi-check2-circle" style="color:#2ecc71"></i> Setup Checklist</div>
        <span style="font-size:.75rem;color:var(--dark-muted)"><?= min($productCount>0?1:0 + ($orderCount>0?1:0) + (!empty($seller['description'])?1:0) + (!empty($seller['phone'])?1:0) + 1, 5) ?>/5 done</span>
      </div>
      <div style="padding:1rem 1.1rem">
        <div class="checklist">
          <?php
          $checks = [
            [true,                          'bi-check-lg','Account created',   'You\'re in! Welcome to Etel Gebeya',  ''],
            [!empty($seller['description']),'bi-pencil',  'Complete store info','Add description & phone',            '/vendor-panel/settings'],
            [$productCount>0,               'bi-box-seam','List first product', 'Products drive sales',               '/vendor-panel/products/new'],
            [!empty($seller['store_slug']), 'bi-share',   'Share store link',   'Let customers find you',             '/vendor/'.($seller['store_slug']??'')],
            [$orderCount>0,                 'bi-receipt',  'First order',        'When a customer buys from you',      '/vendor-panel/orders'],
          ];
          foreach ($checks as [$done,$icon,$title,$sub,$url]):
          ?>
          <<?= $url?'a href="'.View::url($url).'"':'div' ?> class="check-item<?= $done?' done':'' ?>">
            <div class="check-icon <?= $done?'done':'todo' ?>"><i class="bi <?= $done?'bi-check-lg':$icon ?>"></i></div>
            <div class="check-text"><?= $title ?><span><?= $sub ?></span></div>
            <?php if (!$done && $url): ?><i class="bi bi-arrow-right" style="color:var(--dark-muted)"></i><?php endif; ?>
          </<?= $url?'a':'div' ?>>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </div>
</div>
<?php
$__content = ob_get_clean();
echo View::capture('_layouts/dark', array_merge(get_defined_vars(), [
    '__content' => $__content,
    'navItems'  => $navItems,
    'role'      => 'vendor',
    'pageTitle' => $pageTitle,
]));
