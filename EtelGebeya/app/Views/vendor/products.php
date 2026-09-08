<?php
use App\Core\View;
require __DIR__.'/_layout.php';

$totalPages = $total > 0 ? (int)ceil($total / $per) : 1;
ob_start();
?>
<style>
.pl-toolbar{display:flex;align-items:center;gap:.75rem;margin-bottom:1.25rem;flex-wrap:wrap}
.pl-search{display:flex;align-items:center;gap:.5rem;background:rgba(255,255,255,.05);border:1.5px solid rgba(255,255,255,.08);border-radius:12px;padding:.5rem .9rem;flex:1;min-width:200px}
.pl-search i{color:var(--dark-muted);font-size:.95rem;flex-shrink:0}
.pl-search input{border:none;background:transparent;color:#f0f0f0;font-size:.88rem;font-family:'Outfit',sans-serif;outline:none;width:100%}
.pl-search input::placeholder{color:var(--dark-muted)}
.pl-filter{display:flex;gap:.4rem}
.pl-filter a{padding:.45rem .9rem;border-radius:20px;font-size:.78rem;font-weight:700;color:var(--dark-muted);border:1.5px solid transparent;text-decoration:none;transition:all .2s;white-space:nowrap}
.pl-filter a.on{color:var(--gold);border-color:rgba(212,175,55,.3);background:rgba(212,175,55,.08)}
.pl-filter a:hover:not(.on){color:var(--dark-text);background:rgba(255,255,255,.05)}

.prod-table-wrap{background:var(--dark-card);border:1.5px solid var(--dark-border);border-radius:20px;overflow:hidden}
.prod-table{width:100%;border-collapse:collapse}
.prod-table th{font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:var(--gold);padding:.8rem 1rem;background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.07);white-space:nowrap}
.prod-table td{padding:.8rem 1rem;border-bottom:1px solid rgba(255,255,255,.04);font-size:.85rem;color:var(--dark-text);vertical-align:middle}
.prod-table tr:last-child td{border-bottom:none}
.prod-table tbody tr{transition:background .15s}
.prod-table tbody tr:hover td{background:rgba(212,175,55,.04)}

.prod-thumb{width:48px;height:48px;border-radius:10px;object-fit:cover;border:1.5px solid rgba(255,255,255,.1);display:block}
.prod-thumb-ph{width:48px;height:48px;border-radius:10px;background:rgba(255,255,255,.06);border:1.5px dashed rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;color:var(--dark-muted);font-size:1.1rem;flex-shrink:0}
.prod-name{font-weight:700;font-size:.88rem;color:#fff;max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.prod-sku{font-size:.72rem;color:var(--dark-muted);margin-top:.18rem;font-family:monospace}
.prod-price{font-weight:800;color:#fff;white-space:nowrap}
.prod-price-compare{font-size:.75rem;color:var(--dark-muted);text-decoration:line-through;display:block}

.sp{display:inline-flex;align-items:center;padding:.2rem .6rem;border-radius:99px;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.sp-active{background:rgba(46,204,113,.12);color:#2ecc71}
.sp-draft{background:rgba(136,136,168,.1);color:var(--dark-muted)}
.sp-featured{background:rgba(212,175,55,.12);color:var(--gold);border:1px solid rgba(212,175,55,.25)}

.act-btns{display:flex;align-items:center;gap:.4rem}
.act-btn{display:inline-flex;align-items:center;gap:.3rem;padding:.35rem .7rem;border-radius:8px;font-size:.78rem;font-weight:700;text-decoration:none;border:1.5px solid transparent;transition:all .15s;cursor:pointer;background:none}
.act-edit{color:var(--gold);border-color:rgba(212,175,55,.25);background:rgba(212,175,55,.08)}
.act-edit:hover{background:rgba(212,175,55,.18)}
.act-del{color:#e74c3c;border-color:rgba(231,76,60,.2)}
.act-del:hover{background:rgba(231,76,60,.12)}

.stock-low{color:#e74c3c;font-weight:700}
.stock-mid{color:#f39c12;font-weight:700}
.stock-ok{color:#2ecc71}

.pager{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-top:1px solid rgba(255,255,255,.06)}
.pager-info{font-size:.78rem;color:var(--dark-muted)}
.pager-btns{display:flex;gap:.4rem}
.pager-btn{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:8px;border:1.5px solid rgba(255,255,255,.1);background:transparent;color:var(--dark-muted);text-decoration:none;font-size:.82rem;font-weight:700;transition:all .15s}
.pager-btn.on{background:rgba(212,175,55,.15);border-color:rgba(212,175,55,.35);color:var(--gold)}
.pager-btn:hover:not(.on){background:rgba(255,255,255,.07);color:var(--dark-text)}

.empty-zone{text-align:center;padding:4rem 2rem}
.empty-zone .big-icon{font-size:3.5rem;color:rgba(255,255,255,.1);display:block;margin-bottom:1rem}
.empty-zone h3{font-size:1.1rem;font-weight:800;color:var(--dark-text);margin:0 0 .4rem}
.empty-zone p{color:var(--dark-muted);font-size:.85rem;margin:0 0 1.25rem}
</style>

<!-- Header -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
  <div>
    <h1 style="font-size:1.4rem;font-weight:900;color:#fff;margin:0">My Products</h1>
    <p style="font-size:.82rem;color:var(--dark-muted);margin:.2rem 0 0"><?= number_format($total) ?> product<?= $total!==1?'s':'' ?> in your catalog</p>
  </div>
  <a href="<?= View::url('/vendor-panel/products/new') ?>" class="btn btn-gold">
    <i class="bi bi-plus-lg"></i> Add Product
  </a>
</div>

<!-- Toolbar -->
<div class="pl-toolbar">
  <form method="GET" action="<?= View::url('/vendor-panel/products') ?>" style="display:contents">
    <div class="pl-search">
      <i class="bi bi-search"></i>
      <input type="text" name="q" value="<?= e($q??'') ?>" placeholder="Search by name or SKU..." autofocus>
      <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    </div>
  </form>
  <div class="pl-filter">
    <?php foreach ([''=>'All','active'=>'Active','draft'=>'Draft'] as $val=>$lbl): ?>
      <a href="<?= View::url('/vendor-panel/products?status='.$val.($q?'&q='.urlencode($q):'')) ?>"
         class="<?= $status===$val?'on':'' ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
</div>

<!-- Table -->
<div class="prod-table-wrap">
  <?php if (empty($products)): ?>
    <div class="empty-zone">
      <i class="bi bi-box2 big-icon"></i>
      <h3><?= $q?'No products match your search':'No products yet' ?></h3>
      <p><?= $q?'Try a different keyword or clear the filter':'Start building your catalog to drive sales' ?></p>
      <?php if (!$q): ?>
        <a href="<?= View::url('/vendor-panel/products/new') ?>" class="btn btn-gold">Add Your First Product</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
  <table class="prod-table">
    <thead>
      <tr>
        <th style="width:56px"></th>
        <th>Product</th>
        <th>Price</th>
        <th>Stock</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($products as $pr): ?>
      <tr>
        <td>
          <?php if (!empty($pr['thumb'])): ?>
            <img src="<?= e($pr['thumb']) ?>" class="prod-thumb" alt="">
          <?php else: ?>
            <div class="prod-thumb-ph"><i class="bi bi-image"></i></div>
          <?php endif; ?>
        </td>
        <td>
          <div class="prod-name"><?= e(html_entity_decode($pr['name_en'],ENT_QUOTES,'UTF-8')) ?></div>
          <div class="prod-sku"><?= $pr['sku']?e($pr['sku']):'--' ?></div>
          <?php if (!empty($pr['is_featured'])): ?>
            <span class="sp sp-featured" style="margin-top:.25rem"><i class="bi bi-star-fill" style="font-size:.6rem"></i> Featured</span>
          <?php endif; ?>
        </td>
        <td>
          <div class="prod-price">Br <?= number_format((float)$pr['price'],2) ?></div>
          <?php if (!empty($pr['compare_price']) && $pr['compare_price']>$pr['price']): ?>
            <div class="prod-price-compare">Br <?= number_format((float)$pr['compare_price'],2) ?></div>
          <?php endif; ?>
        </td>
        <td>
          <?php $qty=(int)$pr['stock_qty']; $alert=(int)($pr['low_stock_alert']??5); ?>
          <span class="<?= $qty<=0?'stock-low':($qty<=$alert?'stock-mid':'stock-ok') ?>">
            <?= $qty<=0?'Out of stock':$qty.' units' ?>
          </span>
        </td>
        <td><span class="sp sp-<?= $pr['status'] ?>"><?= $pr['status'] ?></span></td>
        <td>
          <div class="act-btns">
            <a href="<?= View::url('/vendor-panel/products/'.$pr['id'].'/edit') ?>" class="act-btn act-edit">
              <i class="bi bi-pencil"></i> Edit
            </a>
            <form method="POST" action="<?= View::url('/vendor-panel/products/'.$pr['id'].'/delete') ?>"
                  onsubmit="return confirm('Delete ?<?= e(addslashes(html_entity_decode($pr['name_en'],ENT_QUOTES,'UTF-8'))) ?>??')">
              <button type="submit" class="act-btn act-del"><i class="bi bi-trash3"></i></button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($totalPages > 1): ?>
  <div class="pager">
    <span class="pager-info">Showing <?= number_format(($page-1)*$per+1) ?>-<?= number_format(min($page*$per,$total)) ?> of <?= number_format($total) ?></span>
    <div class="pager-btns">
      <?php for ($i=1;$i<=$totalPages;$i++): ?>
        <a href="?page=<?=$i?>&status=<?=urlencode($status)?>&q=<?=urlencode($q??'')?>"
           class="pager-btn<?= $i===$page?' on':'' ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php if (!empty($flash)): ?>
<script>
const f=document.createElement('div');
f.style.cssText='position:fixed;top:1.25rem;right:1.25rem;z-index:9999;background:<?= $flash["type"]==="error"?"rgba(231,76,60,.9)":"rgba(46,204,113,.9)" ?>;color:#fff;padding:.8rem 1.3rem;border-radius:12px;font-weight:700;font-size:.88rem;box-shadow:0 8px 30px rgba(0,0,0,.3);display:flex;align-items:center;gap:.5rem;animation:slideIn .3s ease';
f.innerHTML='<i class="bi bi-<?= $flash["type"]==="error"?"exclamation-circle":"check-circle" ?>"></i><?= e($flash["msg"]) ?>';
document.body.appendChild(f);
setTimeout(()=>{f.style.opacity='0';f.style.transition='opacity .4s';setTimeout(()=>f.remove(),400)},3500);
</script>
<?php endif; ?>
<?php
$__content = ob_get_clean();
echo View::capture('_layouts/dark', array_merge(get_defined_vars(), [
    '__content' => $__content,
    'navItems'  => $navItems,
    'role'      => 'vendor',
    'pageTitle' => $pageTitle,
]));
