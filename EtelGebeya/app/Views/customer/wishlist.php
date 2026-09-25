<?php
use App\Core\View;
$locale = $locale ?? 'en';
function wl_img(string $p): string {
    // product_images.path is already saved as a site-absolute path
    // (see VendorController's $uploadDir) -- don't re-prefix those.
    if (str_starts_with($p,'http') || str_starts_with($p,'/')) return $p;
    return '/EtelGebeya/public/uploads/products/'.$p;
}
ob_start();
?>
<style>
.wl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem}
.wl-card{background:var(--card,#130d05);border:1.5px solid var(--border-dim,rgba(212,175,55,.08));border-radius:18px;overflow:hidden;transition:border-color .25s,transform .2s;position:relative}
.wl-card:hover{border-color:var(--gold-line,rgba(212,175,55,.22));transform:translateY(-3px)}
.wl-img{width:100%;aspect-ratio:1;object-fit:cover;background:rgba(212,175,55,.05)}
.wl-img-ph{width:100%;aspect-ratio:1;display:flex;align-items:center;justify-content:center;background:rgba(212,175,55,.05);font-size:2.5rem;color:rgba(212,175,55,.2)}
.wl-body{padding:.85rem 1rem}
.wl-name{font-weight:800;font-size:.88rem;color:var(--text,#F7EFD9);margin-bottom:.35rem;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.wl-price{font-size:1rem;font-weight:900;color:var(--g2,#D4AF37)}
.wl-compare{font-size:.78rem;color:var(--text-muted,#7A6840);text-decoration:line-through;margin-left:.4rem}
.wl-actions{display:flex;gap:.5rem;margin-top:.75rem}
.btn-cart{flex:1;display:flex;align-items:center;justify-content:center;gap:.35rem;padding:.5rem .75rem;background:var(--grad-gold,linear-gradient(135deg,#FFE066,#D4AF37,#B8902E));color:#1a0e00;font-weight:800;border-radius:10px;border:none;cursor:pointer;font-size:.78rem;font-family:inherit;text-decoration:none;transition:all .2s;box-shadow:0 4px 12px rgba(212,175,55,.25)}
.btn-cart:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(212,175,55,.4)}
.btn-remove{width:34px;height:34px;display:flex;align-items:center;justify-content:center;border-radius:10px;background:rgba(239,68,68,.08);border:1.5px solid rgba(239,68,68,.15);color:#ef4444;cursor:pointer;font-size:.9rem;transition:all .2s;flex-shrink:0}
.btn-remove:hover{background:rgba(239,68,68,.15);border-color:rgba(239,68,68,.3)}

.empty-state{text-align:center;padding:3.5rem 1rem;background:var(--card,#130d05);border:1.5px solid var(--border-dim,rgba(212,175,55,.08));border-radius:18px}
.flash-box{padding:.8rem 1.1rem;border-radius:12px;margin-bottom:1.1rem;border:1.5px solid;font-size:.85rem;display:flex;align-items:center;gap:.5rem}
.flash-success{background:rgba(16,185,129,.08);border-color:rgba(16,185,129,.25);color:#10b981}
</style>

<div style="margin-bottom:1.25rem">
  <h1 class="cp-page-title"><i class="bi bi-heart"></i> <?= e(t('customer.wishlist.my_wishlist')) ?></h1>
  <p style="font-size:.82rem;color:var(--text-muted,#7A6840);margin:-.5rem 0 0"><?= e(t('customer.wishlist.n_saved', ['n' => count($items)])) ?></p>
</div>

<?php if (!empty($flash)): ?>
  <div class="flash-box flash-success"><i class="bi bi-check-circle"></i> <?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if (empty($items)): ?>
  <div class="empty-state">
    <i class="bi bi-heart" style="font-size:2.8rem;color:rgba(212,175,55,.12);display:block;margin-bottom:1rem"></i>
    <h3 style="font-size:1.1rem;font-weight:800;color:var(--text,#F7EFD9);margin:0 0 .5rem"><?= e(t('customer.wishlist.empty_title')) ?></h3>
    <p style="font-size:.85rem;color:var(--text-muted,#7A6840);margin:0 0 1.25rem"><?= e(t('customer.wishlist.empty_sub')) ?></p>
    <a href="<?= View::url('/') ?>" style="display:inline-flex;align-items:center;gap:.4rem;background:var(--grad-gold,linear-gradient(135deg,#FFE066,#D4AF37,#B8902E));color:#1a0e00;font-weight:800;padding:.65rem 1.4rem;border-radius:12px;text-decoration:none;box-shadow:0 6px 20px rgba(212,175,55,.3)">
      <i class="bi bi-shop"></i> <?= e(t('customer.wishlist.explore_store')) ?>
    </a>
  </div>
<?php else: ?>
  <div class="wl-grid">
    <?php foreach ($items as $it):
      $name  = $locale==='am' ? ($it['name_am']??$it['name_en']) : $it['name_en'];
      $price = (float)($it['price']??0);
      $comp  = (float)($it['compare_price']??0);
    ?>
      <div class="wl-card">
        <a href="<?= View::url('/product/'.e($it['slug']??'')) ?>">
          <?php if (!empty($it['thumb'])): ?>
            <img src="<?= e(wl_img($it['thumb'])) ?>" class="wl-img" alt="<?= e($name) ?>">
          <?php else: ?>
            <div class="wl-img-ph"><i class="bi bi-image"></i></div>
          <?php endif; ?>
        </a>
        <div class="wl-body">
          <a href="<?= View::url('/product/'.e($it['slug']??'')) ?>" style="text-decoration:none">
            <div class="wl-name"><?= e($name) ?></div>
          </a>
          <div style="display:flex;align-items:baseline;flex-wrap:wrap">
            <span class="wl-price">Br <?= number_format($price,0) ?></span>
            <?php if ($comp > $price): ?>
              <span class="wl-compare">Br <?= number_format($comp,0) ?></span>
            <?php endif; ?>
          </div>
          <div class="wl-actions">
            <a href="<?= View::url('/cart/add?product_id='.$it['product_id']) ?>" class="btn-cart">
              <i class="bi bi-cart-plus"></i> <?= e(t('product.add_to_cart')) ?>
            </a>
            <form method="POST" action="<?= View::url('/account/wishlist/remove') ?>">
              <input type="hidden" name="product_id" value="<?= (int)$it['product_id'] ?>">
              <button type="submit" class="btn-remove" title="<?= e(t('vendor.suppliers.remove')) ?>"><i class="bi bi-trash3"></i></button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php
$__content = ob_get_clean();
ob_start();
require __DIR__ . '/_layout.php';
$__panel = ob_get_clean();
echo View::capture('_layouts/public', [
    '__content' => $__panel,
    'pageTitle'  => t('customer.wishlist.my_wishlist') . ' -- Etel Gebeya',
    'locale'     => $locale,
]);
