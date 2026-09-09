<?php
/**
 * Product detail page.
 * Variables: $product (Product), $locale (string)
 */
declare(strict_types=1);
use App\Core\View;
require_once __DIR__ . '/_product_card.php';

$db       = $GLOBALS['__db']   ?? null;
$auth     = $GLOBALS['__auth'] ?? null;
$locale   = $locale ?? 'en';
$name     = htmlspecialchars($product->name($locale), ENT_QUOTES);
$desc     = $locale === 'am' ? ($product->descriptionAm ?: $product->descriptionEn) : ($product->descriptionEn ?: $product->descriptionAm);

// Related products (same category, exclude current)
$related = [];
if ($db && $db->isConnected() && $product->categoryId) {
    try {
        $rows = $db->fetchAll(
            "SELECT * FROM products WHERE status IN ('active','published','publish') AND category_id=? AND id!=? ORDER BY RAND() LIMIT 4",
            [$product->categoryId, $product->id]
        );
        $related = array_values(array_filter(array_map(function($r){try{return \App\Models\Product::bySlug($r['slug']??'') ?? new \App\Models\Product($r);}catch(\Throwable $_){return null;}}, $rows)));
    } catch (\Throwable $_) {}
}

// Vendor/seller info
$vendor = null;
if ($db && $db->isConnected() && $product->vendorId) {
    try {
        $vendor = $db->fetch(
            "SELECT s.*, u.full_name FROM sellers s JOIN users u ON u.id=s.user_id WHERE s.id=?",
            [$product->vendorId]
        );
        if ($vendor) {
            // Normalise column names
            $vendor['shop_name'] = $vendor['store_name'] ?? $vendor['shop_name'] ?? '';
            $vendor['shop_slug'] = $vendor['store_slug'] ?? $vendor['shop_slug'] ?? '';
        }
    } catch (\Throwable $_) {}
}

// Reviews
$reviews = [];
if ($db && $db->isConnected()) {
    try {
        $reviews = $db->fetchAll(
            "SELECT r.*, u.full_name reviewer FROM reviews r JOIN users u ON u.id=r.user_id
             WHERE r.product_id=? AND r.status='approved' ORDER BY r.created_at DESC LIMIT 10",
            [$product->id]
        );
    } catch (\Throwable $_) {}
}

// Images -- path can be a full URL or a relative path
$images = [];
if ($db && $db->isConnected()) {
    try {
        $images = $db->fetchAll(
            "SELECT path FROM product_images WHERE product_id=? ORDER BY sort_order",
            [$product->id]
        );
    } catch (\Throwable $_) {}
}
function eg_img_url(string $path): string {
    if (str_starts_with($path, 'http')) return $path;
    // product_images.path is already saved as a site-absolute path
    // (see VendorController's $uploadDir) -- don't re-prefix those,
    // only bare filenames that never got a path stored.
    if (str_starts_with($path, '/')) return $path;
    return '/EtelGebeya/public/uploads/products/' . $path;
}
$mainImage = !empty($images) ? eg_img_url($images[0]['path'])
    : View::asset('img/no-image.svg');

ob_start();
?>
<style>
.product-page { padding: 20px 0; }
.product-breadcrumb { padding: 10px 0; margin-bottom: 20px; font-size: 12.5px; color: var(--text2); display: flex; align-items: center; flex-wrap: wrap; }
.product-breadcrumb a { color: var(--text2); transition: color .15s; }
.product-breadcrumb a:hover { color: var(--g1); }
.product-breadcrumb span { margin: 0 8px; color: var(--text3); display: inline-flex; }
.product-layout { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; align-items: start; }
@media(max-width:768px) { .product-layout { grid-template-columns: 1fr; } }

/* Gallery */
.gallery { position: sticky; top: calc(var(--header-h) + 8px); }
.gallery-main {
  border-radius: var(--r2); overflow: hidden;
  background: var(--card); border: 1px solid var(--border);
  box-shadow: var(--shadow); aspect-ratio: 1; position: relative;
}
.gallery-main img { width: 100%; height: 100%; object-fit: cover; transition: transform .5s ease; }
.gallery-main:hover img { transform: scale(1.04); }
.gallery-badge { position: absolute; top: 14px; left: 14px; display: flex; flex-direction: column; gap: 6px; }
.gallery-badge .badge { font-size: 11px; font-weight: 800; padding: 3px 9px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px; }
.gallery-thumbs { display: flex; gap: 10px; margin-top: 12px; flex-wrap: wrap; }
.gallery-thumb {
  width: 60px; height: 60px; border-radius: 10px; overflow: hidden; cursor: pointer;
  border: 2px solid transparent; transition: border-color .15s; flex-shrink: 0;
}
.gallery-thumb.active,.gallery-thumb:hover { border-color: var(--g2); }
.gallery-thumb img { width: 100%; height: 100%; object-fit: cover; }

/* Product Info */
.product-info-panel { display: flex; flex-direction: column; gap: 18px; }
.product-vendor-tag {
  display: inline-flex; align-items: center; gap: 8px; align-self: flex-start;
  background: rgba(212,175,55,.1); border: 1px solid var(--border);
  padding: 5px 13px; border-radius: 20px; font-size: 12.5px; color: var(--g1);
  font-weight: 700; text-decoration: none;
}
.product-title { font-size: 26px; font-weight: 800; line-height: 1.25; color: var(--text); margin: 4px 0; }
.product-rating-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.stars-gold { color: var(--g2); }
.rating-val { font-weight: 700; font-size: 13.5px; color: var(--text); }
.rating-cnt { font-size: 12px; color: var(--text2); }
.product-price-block { background: rgba(212,175,55,.06); border: 1px solid var(--border); border-radius: var(--r2); padding: 18px 20px; }
.price-current { font-size: 30px; font-weight: 900; color: var(--g1); }
.price-compare { font-size: 15px; text-decoration: line-through; color: var(--text3); margin-left: 8px; }
.price-discount { display: inline-block; background: var(--red); color: #fff; font-size: 11px; font-weight: 800; padding: 2px 8px; border-radius: 6px; margin-left: 8px; }
.price-birr { font-size: 13px; color: var(--text2); margin-top: 3px; }
.stock-row { display: flex; align-items: center; gap: 10px; font-size: 12.5px; }
.in-stock { color: var(--green); font-weight: 700; }
.out-stock { color: var(--red); font-weight: 700; }
.low-stock { color: #D97706; font-weight: 700; }
.ship-estimate { display: flex; align-items: center; gap: 7px; font-size: 12.5px; color: var(--text2); margin-top: 8px; }
.ship-estimate i { color: var(--g2); }

/* Qty + Cart */
.cart-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.qty-ctrl { display: flex; align-items: center; gap: 0; border: 1.5px solid var(--border); border-radius: var(--r); overflow: hidden; }
.qty-btn { width: 40px; height: 44px; background: var(--card); border: none; cursor: pointer; font-size: 15px; font-weight: 700; color: var(--text); transition: background .15s; }
.qty-btn:hover { background: rgba(212,175,55,.1); }
.qty-input { width: 48px; height: 44px; text-align: center; border: none; border-left: 1.5px solid var(--border); border-right: 1.5px solid var(--border); font-size: 14px; font-weight: 700; background: var(--card); color: var(--text); }
.add-cart-btn {
  flex: 1; min-width: 160px; padding: 13px 22px; border: none; border-radius: var(--r); cursor: pointer;
  background: var(--grad);
  color: #080604; font-weight: 800; font-size: 14.5px;
  box-shadow: var(--shadow-gold); transition: all .2s;
}
.add-cart-btn:hover { transform: translateY(-2px); opacity: .92; }
.add-cart-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }
.wishlist-btn {
  width: 48px; height: 48px; border-radius: var(--r); border: 1.5px solid var(--border);
  background: var(--card); color: var(--text2); cursor: pointer; font-size: 19px; transition: all .2s;
}
.wishlist-btn:hover { border-color: var(--red); background: rgba(231,76,60,.08); }

/* Meta table */
.product-meta { font-size: 12.5px; }
.product-meta table { width: 100%; border-collapse: collapse; }
.product-meta td { padding: 8px 0; border-bottom: 1px solid var(--border2); color: var(--text); }
.product-meta td:first-child { color: var(--text2); width: 40%; }

/* Vendor card */
.vendor-card {
  display: flex; align-items: center; gap: 14px; padding: 16px 18px;
  background: var(--card); border: 1px solid var(--border); border-radius: var(--r2);
}
.vendor-logo { width: 52px; height: 52px; border-radius: var(--r); background: rgba(212,175,55,.1); color: var(--g2); display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
.vendor-name { font-weight: 700; color: var(--text); }
.vendor-rating { font-size: 12px; color: var(--text2); }

/* Tabs */
.detail-tabs { border-bottom: 2px solid var(--border); display: flex; gap: 0; margin-bottom: 20px; }
.detail-tab { padding: 11px 20px; font-weight: 700; font-size: 13px; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -2px; color: var(--text2); transition: all .15s; display: inline-flex; align-items: center; gap: 6px; }
.detail-tab.active { border-bottom-color: var(--g2); color: var(--g1); }

/* Reviews */
.review-card { padding: 16px; background: var(--card); border: 1px solid var(--border); border-radius: var(--r2); margin-bottom: 12px; }
.review-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.reviewer-name { font-weight: 700; font-size: 13.5px; color: var(--text); }
.review-date { font-size: 11.5px; color: var(--text3); }
.review-body { font-size: 13px; color: var(--text2); line-height: 1.6; }

/* Related */
.related-section { margin-top: 40px; }
</style>

<div class="product-page">
  <!-- Breadcrumb -->
  <div class="product-breadcrumb">
    <a href="<?= View::url('/') ?>"><?= t('nav.home') ?></a>
    <span><i class="bi bi-chevron-right" style="font-size:.7rem"></i></span>
    <a href="<?= View::url('/search') ?>"><?= t('nav.categories') ?></a>
    <?php if ($vendor): ?>
      <span><i class="bi bi-chevron-right" style="font-size:.7rem"></i></span>
      <a href="<?= View::url('/vendor/' . htmlspecialchars($vendor['shop_slug'] ?? '', ENT_QUOTES)) ?>"><?= htmlspecialchars($vendor['shop_name'] ?? '', ENT_QUOTES) ?></a>
    <?php endif; ?>
    <span><i class="bi bi-chevron-right" style="font-size:.7rem"></i></span>
    <span><?= $name ?></span>
  </div>

  <!-- Main Layout -->
  <div class="product-layout">

    <!-- Gallery -->
    <div class="gallery">
      <div class="gallery-main" id="gallery-main">
        <img src="<?= htmlspecialchars($mainImage, ENT_QUOTES) ?>" alt="<?= $name ?>" id="gallery-img"
             onerror="this.onerror=null;this.src='<?= View::asset('img/no-image.svg') ?>'">
        <div class="gallery-badge">
          <?php if ($product->hasDiscount()): ?><span class="badge" style="background:var(--red);color:#fff">-<?= $product->discountPct() ?>%</span><?php endif; ?>
          <?php if ((time() - strtotime($product->createdAt)) < 7 * 86400): ?><span class="badge" style="background:var(--green);color:#fff">NEW</span><?php endif; ?>
          <?php if ($product->isFeatured): ?><span class="badge" style="background:var(--grad);color:#080604"><i class="bi bi-star-fill"></i> FEATURED</span><?php endif; ?>
        </div>
      </div>
      <?php if (count($images) > 1): ?>
      <div class="gallery-thumbs">
        <?php foreach ($images as $i => $img): $imgUrl = eg_img_url($img['path']); ?>
          <div class="gallery-thumb <?= $i===0?'active':'' ?>" onclick="setImage('<?= htmlspecialchars($imgUrl,ENT_QUOTES) ?>',this)">
            <img src="<?= htmlspecialchars($imgUrl,ENT_QUOTES) ?>" alt=""
                 onerror="this.onerror=null;this.src='<?= View::asset('img/no-image.svg') ?>'">
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Product Info -->
    <div class="product-info-panel">
      <?php if ($vendor): ?>
        <a href="<?= View::url('/vendor/' . htmlspecialchars($vendor['shop_slug']??'',ENT_QUOTES)) ?>" class="product-vendor-tag">
          <i class="bi bi-shop"></i> <?= htmlspecialchars($vendor['shop_name']??'',ENT_QUOTES) ?>
        </a>
      <?php endif; ?>

      <h1 class="product-title"><?= $name ?></h1>

      <!-- Rating row -->
      <div class="product-rating-row" style="display:flex;align-items:center;gap:.5rem;">
        <span class="stars-gold" style="font-size:1.1rem;">
          <?= str_repeat('&#9733;', (int)round($product->ratingAvg)) . str_repeat('&#9734;', 5 - (int)round($product->ratingAvg)) ?>
        </span>
        <span class="rating-val"><?= number_format($product->ratingAvg, 1) ?></span>
        <span class="rating-cnt">(<?= number_format($product->ratingCount) ?> <?= t('product.reviews', ['count'=>'']) ?>)</span>
        <span style="color:var(--text3)">*</span>
        <span class="rating-cnt"><?= number_format($product->soldCount) ?> <?= t('product.sold',['count'=>'']) ?></span>
      </div>

      <!-- Price block -->
      <div class="product-price-block">
        <div>
          <span class="price-current">Br <?= number_format($product->price, 2) ?></span>
          <?php if ($product->hasDiscount()): ?>
            <span class="price-compare">Br <?= number_format($product->comparePrice, 2) ?></span>
            <span class="price-discount">-<?= $product->discountPct() ?>%</span>
          <?php endif; ?>
        </div>
        <div class="price-birr"><?= t('site.currency') ?> * Ethiopian Birr</div>
      </div>

      <!-- Stock status -->
      <div class="stock-row">
        <?php if (!$product->inStock()): ?>
          <span class="out-stock"><i class="bi bi-x-circle-fill"></i> <?= t('product.out_of_stock') ?></span>
        <?php elseif ($product->lowStock()): ?>
          <span class="low-stock"><i class="bi bi-exclamation-triangle-fill"></i> Only <?= $product->stockQty ?> left!</span>
        <?php else: ?>
          <span class="in-stock"><i class="bi bi-check-circle-fill"></i> <?= t('product.in_stock') ?></span>
          <?php if ($product->trackStock): ?><span style="font-size:.8rem;color:var(--text2)"><?= number_format($product->stockQty) ?> available</span><?php endif; ?>
        <?php endif; ?>
      </div>

      <?php if ($product->shippingEstimate()): ?>
      <div class="ship-estimate">
        <i class="bi bi-truck"></i> Ships in <?= e($product->shippingEstimate()) ?> -- shipped directly by the supplier
      </div>
      <?php endif; ?>

      <!-- Add to cart -->
      <div class="cart-row">
        <div class="qty-ctrl">
          <button class="qty-btn" onclick="changeQty(-1)"><i class="bi bi-dash-lg"></i></button>
          <input type="number" class="qty-input" id="qty" value="1" min="1" max="<?= $product->stockQty ?: 99 ?>">
          <button class="qty-btn" onclick="changeQty(1)"><i class="bi bi-plus-lg"></i></button>
        </div>
        <button class="add-cart-btn" id="add-cart-btn" onclick="addToCart(<?= $product->id ?>)"
          <?= !$product->inStock() ? 'disabled' : '' ?>>
          <i class="bi bi-cart-plus"></i> <?= t('product.add_to_cart') ?>
        </button>
        <button class="wishlist-btn" onclick="addWishlist(<?= $product->id ?>)" title="Add to wishlist"><i class="bi bi-heart"></i></button>
      </div>

      <!-- Meta -->
      <div class="product-meta">
        <table>
          <?php if ($product->sku): ?><tr><td><?= t('product.sku') ?></td><td style="font-family:monospace"><?= htmlspecialchars($product->sku, ENT_QUOTES) ?></td></tr><?php endif; ?>
          <?php if ($product->weightKg): ?><tr><td><?= t('product.weight') ?></td><td><?= $product->weightKg ?> <?= t('product.kg') ?></td></tr><?php endif; ?>
          <tr><td>Views</td><td><?= number_format($product->viewCount) ?></td></tr>
          <tr><td>Sold</td><td><?= number_format($product->soldCount) ?></td></tr>
        </table>
      </div>

      <!-- Vendor card -->
      <?php if ($vendor): ?>
      <div class="vendor-card">
        <div class="vendor-logo"><i class="bi bi-shop"></i></div>
        <div style="flex:1">
          <div class="vendor-name"><?= htmlspecialchars($vendor['shop_name']??'', ENT_QUOTES) ?></div>
          <div class="vendor-rating"><i class="bi bi-star-fill"></i> <?= number_format((float)($vendor['rating_avg']??0),1) ?> &middot; <?= $vendor['rating_count']??0 ?> reviews</div>
        </div>
        <a href="<?= View::url('/vendor/'.htmlspecialchars($vendor['shop_slug']??'',ENT_QUOTES)) ?>" style="text-decoration:none;color:var(--text);display:inline-block;padding:8px 16px;border-radius:var(--r);border:1.5px solid var(--border);font-size:12.5px;font-weight:700;transition:border-color .15s" onmouseover="this.style.borderColor='var(--g2)'" onmouseout="this.style.borderColor='var(--border)'">Visit Shop</a>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Detail Tabs -->
  <div style="margin-top:3rem;">
    <div class="detail-tabs">
      <div class="detail-tab active" onclick="showTab('desc',this)"><i class="bi bi-file-text"></i> Description</div>
      <div class="detail-tab" onclick="showTab('reviews',this)"><i class="bi bi-chat-square-text"></i> Reviews (<?= count($reviews) ?>)</div>
    </div>

    <!-- Description -->
    <div id="tab-desc">
      <?php if ($desc): ?>
        <div style="line-height:1.8;color:var(--text2)"><?= nl2br(htmlspecialchars(strip_tags($desc), ENT_QUOTES)) ?></div>
      <?php else: ?>
        <p style="color:var(--text3)">No description provided for this product.</p>
      <?php endif; ?>
    </div>

    <!-- Reviews -->
    <div id="tab-reviews" style="display:none;">
      <?php if (empty($reviews)): ?>
        <p style="color:var(--text3);padding:1rem 0">No reviews yet for this product.</p>
      <?php else: ?>
        <?php foreach ($reviews as $rv): ?>
        <div class="review-card">
          <div class="review-header">
            <div>
              <div class="reviewer-name"><?= htmlspecialchars($rv['reviewer']??'Anonymous', ENT_QUOTES) ?></div>
              <span class="stars-gold"><?= str_repeat('&#9733;',(int)($rv['rating']??0)).str_repeat('&#9734;',5-(int)($rv['rating']??0)) ?></span>
            </div>
            <div class="review-date"><?= date('M d, Y', strtotime($rv['created_at'])) ?></div>
          </div>
          <?php if ($rv['title']): ?><div style="font-weight:700;margin-bottom:.3rem"><?= htmlspecialchars($rv['title'], ENT_QUOTES) ?></div><?php endif; ?>
          <div class="review-body"><?= htmlspecialchars($rv['body']??'', ENT_QUOTES) ?></div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($auth && $auth->check()): ?>
      <div class="review-card" style="margin-top:1.5rem">
        <div style="font-weight:700;margin-bottom:.75rem">Write a review</div>
        <form method="post" action="<?= View::url('/product/' . htmlspecialchars($product->slug, ENT_QUOTES) . '/review') ?>">
          <div class="review-star-input" style="display:flex;flex-direction:row-reverse;justify-content:flex-end;gap:4px;font-size:1.6rem;margin-bottom:.75rem">
            <?php for ($i = 5; $i >= 1; $i--): ?>
              <input type="radio" name="rating" value="<?= $i ?>" id="rv-star-<?= $i ?>" style="display:none" required>
              <label for="rv-star-<?= $i ?>" style="cursor:pointer;color:var(--border)">★</label>
            <?php endfor; ?>
          </div>
          <input type="text" name="title" maxlength="150" placeholder="Title (optional)"
                 style="width:100%;padding:.6rem .8rem;border-radius:10px;border:1px solid var(--border);background:var(--card);color:inherit;margin-bottom:.5rem">
          <textarea name="body" rows="3" required placeholder="Share your experience with this product…"
                    style="width:100%;padding:.6rem .8rem;border-radius:10px;border:1px solid var(--border);background:var(--card);color:inherit;margin-bottom:.75rem"></textarea>
          <button type="submit" class="add-cart-btn" style="width:auto;flex:none;padding:.6rem 1.4rem">Submit review</button>
        </form>
      </div>
      <style>
        .review-star-input input:checked ~ label,
        .review-star-input label:hover,
        .review-star-input label:hover ~ label { color: var(--g2); }
      </style>
      <?php else: ?>
      <p style="margin-top:1.5rem;color:var(--text3)"><a href="<?= View::url('/login') ?>">Log in</a> to write a review.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- Related Products -->
  <?php if (!empty($related)): ?>
  <div class="related-section">
    <div class="eg-sec-head">
      <div>
        <div class="eg-sec-title">You Might Also Like</div>
        <div class="eg-sec-sub">More from this category</div>
      </div>
      <a href="<?= View::url('/search') ?>" class="eg-sec-more">See All <i class="bi bi-arrow-right"></i></a>
    </div>
    <div class="eg-product-grid">
      <?php foreach ($related as $rp): ?>
        <?= product_card($rp, $locale) ?>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
function setImage(src, thumb) {
  document.getElementById('gallery-img').src = src;
  document.querySelectorAll('.gallery-thumb').forEach(t=>t.classList.remove('active'));
  thumb.classList.add('active');
}
function changeQty(d) {
  const inp = document.getElementById('qty');
  inp.value = Math.max(1, Math.min(parseInt(inp.max)||99, parseInt(inp.value||1)+d));
}
function showTab(name, el) {
  document.querySelectorAll('.detail-tab').forEach(t=>t.classList.remove('active'));
  el.classList.add('active');
  document.getElementById('tab-desc').style.display = name==='desc'?'block':'none';
  document.getElementById('tab-reviews').style.display = name==='reviews'?'block':'none';
}
function addToCart(id) {
  const qty = parseInt(document.getElementById('qty').value)||1;
  const btn = document.getElementById('add-cart-btn');
  btn.textContent = 'Adding...'; btn.disabled = true;
  fetch('<?= View::url('/cart/add') ?>', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'product_id='+id+'&qty='+qty+'&_token=<?= $_SESSION['_token'] ?? '' ?>'
  }).then(r=>r.json()).then(d=>{
    btn.textContent = d.ok ? 'Added to Cart!' : 'Error';
    setTimeout(()=>{ btn.innerHTML = '<i class="bi bi-cart-plus"></i> <?= t('product.add_to_cart') ?>'; btn.disabled = false; }, 2000);
  }).catch(()=>{ btn.innerHTML = '<i class="bi bi-cart-plus"></i> Add to Cart'; btn.disabled=false; });
}
function addWishlist(id) {
  const btn = event.target.closest('.wishlist-btn');
  const icon = btn.querySelector('i');
  icon.className = 'bi bi-heart-fill';
  btn.style.color='#DC2626';
  setTimeout(()=>{ btn.style.color=''; icon.className='bi bi-heart'; }, 300);
}
</script>
<?php
$__content = ob_get_clean();
echo \App\Core\View::capture('_layouts/public', [
    '__content' => $__content,
    'pageTitle'  => $product->name($locale ?? 'en'),
    'locale'     => $locale ?? 'en',
]);
