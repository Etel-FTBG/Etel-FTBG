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
    : "https://picsum.photos/seed/{$product->id}/600/600";

ob_start();
?>
<style>
.product-page { padding: 2rem 0; }
.product-breadcrumb { padding: .6rem 0; margin-bottom: 1.5rem; font-size: .8rem; color: var(--eg-text-dim); }
.product-breadcrumb a { color: var(--eg-blue); }
.product-breadcrumb span { margin: 0 .4rem; color: var(--eg-text-muted); }
.product-layout { display: grid; grid-template-columns: 1fr 1fr; gap: 2.5rem; align-items: start; }
@media(max-width:768px) { .product-layout { grid-template-columns: 1fr; } }

/* Gallery */
.gallery { position: sticky; top: 1rem; }
.gallery-main {
  border-radius: var(--eg-radius); overflow: hidden;
  background: var(--eg-surface); border: 1.5px solid var(--eg-border);
  box-shadow: var(--eg-shadow); aspect-ratio: 1; position: relative;
}
.gallery-main img { width: 100%; height: 100%; object-fit: cover; transition: transform .5s var(--eg-ease); }
.gallery-main:hover img { transform: scale(1.04); }
.gallery-badge { position: absolute; top: 1rem; left: 1rem; display: flex; flex-direction: column; gap: .4rem; }
.gallery-thumbs { display: flex; gap: .6rem; margin-top: .75rem; flex-wrap: wrap; }
.gallery-thumb {
  width: 60px; height: 60px; border-radius: 10px; overflow: hidden; cursor: pointer;
  border: 2px solid transparent; transition: border-color .2s; flex-shrink: 0;
}
.gallery-thumb.active,.gallery-thumb:hover { border-color: var(--eg-gold); }
.gallery-thumb img { width: 100%; height: 100%; object-fit: cover; }

/* Product Info */
.product-info-panel { display: flex; flex-direction: column; gap: 1.2rem; }
.product-vendor-tag {
  display: inline-flex; align-items: center; gap: .5rem;
  background: var(--eg-gold-dim); border: 1px solid var(--eg-gold-glow);
  padding: .3rem .8rem; border-radius: 20px; font-size: .78rem; color: var(--eg-gold-deep);
  font-weight: 700; text-decoration: none;
}
.product-title { font-size: 1.8rem; font-weight: 800; line-height: 1.2; color: var(--eg-text); margin: .4rem 0; }
.product-rating-row { display: flex; align-items: center; gap .75rem; }
.stars-gold { color: #D4AF37; }
.rating-val { font-weight: 700; font-size: .9rem; }
.rating-cnt { font-size: .8rem; color: var(--eg-text-dim); }
.product-price-block { background: var(--eg-glass); border: 1px solid var(--eg-gold-glow); border-radius: 16px; padding: 1.2rem 1.4rem; }
.price-current { font-size: 2rem; font-weight: 900; color: var(--eg-blue-deep); }
.price-compare { font-size: 1rem; text-decoration: line-through; color: var(--eg-text-muted); margin-left: .5rem; }
.price-discount { display: inline-block; background: #DC2626; color: #fff; font-size: .75rem; font-weight: 800; padding: 2px 8px; border-radius: 6px; margin-left: .5rem; }
.price-birr { font-size: .9rem; color: var(--eg-text-dim); margin-top: .2rem; }
.stock-row { display: flex; align-items: center; gap: .75rem; font-size: .85rem; }
.in-stock { color: #059669; font-weight: 700; }
.out-stock { color: #DC2626; font-weight: 700; }
.low-stock { color: #D97706; font-weight: 700; }

/* Qty + Cart */
.cart-row { display: flex; align-items: center; gap: .75rem; }
.qty-ctrl { display: flex; align-items: center; gap: 0; border: 1.5px solid var(--eg-border); border-radius: 12px; overflow: hidden; }
.qty-btn { width: 38px; height: 44px; background: var(--eg-glass); border: none; cursor: pointer; font-size: 1.1rem; font-weight: 700; transition: background .2s; }
.qty-btn:hover { background: var(--eg-gold-dim); }
.qty-input { width: 46px; height: 44px; text-align: center; border: none; border-left: 1.5px solid var(--eg-border); border-right: 1.5px solid var(--eg-border); font-size: .95rem; font-weight: 700; background: var(--eg-surface); }
.add-cart-btn {
  flex: 1; padding: .85rem 1.5rem; border: none; border-radius: 14px; cursor: pointer;
  background: linear-gradient(135deg, var(--eg-blue-deep), var(--eg-blue));
  color: #fff; font-weight: 800; font-size: 1rem; font-family: var(--f-sans);
  box-shadow: 0 8px 24px rgba(37,99,235,.35); transition: all .3s;
}
.add-cart-btn:hover { transform: translateY(-2px); box-shadow: 0 14px 32px rgba(37,99,235,.45); }
.add-cart-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }
.wishlist-btn {
  width: 48px; height: 48px; border-radius: 12px; border: 1.5px solid var(--eg-border);
  background: var(--eg-glass); cursor: pointer; font-size: 1.3rem; transition: all .25s;
}
.wishlist-btn:hover { border-color: #DC2626; background: rgba(220,38,38,.06); }

/* Meta table */
.product-meta { font-size: .83rem; }
.product-meta table { width: 100%; border-collapse: collapse; }
.product-meta td { padding: .5rem 0; border-bottom: 1px solid var(--eg-border); }
.product-meta td:first-child { color: var(--eg-text-dim); width: 40%; }

/* Vendor card */
.vendor-card {
  display: flex; align-items: center; gap: 1rem; padding: 1.1rem 1.3rem;
  background: var(--eg-glass); border: 1.5px solid var(--eg-border); border-radius: 16px;
}
.vendor-logo { width: 52px; height: 52px; border-radius: 12px; background: var(--eg-gold-dim); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
.vendor-name { font-weight: 700; color: var(--eg-text); }
.vendor-rating { font-size: .8rem; color: var(--eg-text-dim); }

/* Tabs */
.detail-tabs { border-bottom: 2px solid var(--eg-border); display: flex; gap: 0; margin-bottom: 1.5rem; }
.detail-tab { padding: .7rem 1.3rem; font-weight: 700; font-size: .88rem; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -2px; color: var(--eg-text-dim); transition: all .2s; }
.detail-tab.active { border-bottom-color: var(--eg-blue); color: var(--eg-blue-deep); }

/* Reviews */
.review-card { padding: 1rem; background: var(--eg-glass); border: 1px solid var(--eg-border); border-radius: 14px; margin-bottom: .75rem; }
.review-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: .5rem; }
.reviewer-name { font-weight: 700; font-size: .88rem; }
.review-date { font-size: .75rem; color: var(--eg-text-muted); }
.review-body { font-size: .85rem; color: var(--eg-text-dim); line-height: 1.6; }

/* Related */
.related-section { margin-top: 3rem; }
</style>

<div class="container product-page">
  <!-- Breadcrumb -->
  <div class="product-breadcrumb">
    <a href="<?= View::url('/') ?>"><?= t('nav.home') ?></a>
    <span>?</span>
    <a href="<?= View::url('/search') ?>"><?= t('nav.categories') ?></a>
    <?php if ($vendor): ?>
      <span>?</span>
      <a href="<?= View::url('/vendor/' . htmlspecialchars($vendor['shop_slug'] ?? '', ENT_QUOTES)) ?>"><?= htmlspecialchars($vendor['shop_name'] ?? '', ENT_QUOTES) ?></a>
    <?php endif; ?>
    <span>?</span>
    <span><?= $name ?></span>
  </div>

  <!-- Main Layout -->
  <div class="product-layout">

    <!-- Gallery -->
    <div class="gallery">
      <div class="gallery-main" id="gallery-main">
        <img src="<?= htmlspecialchars($mainImage, ENT_QUOTES) ?>" alt="<?= $name ?>" id="gallery-img"
             onerror="this.onerror=null;this.src='https://picsum.photos/seed/<?= $product->id ?>/600/600'">
        <div class="gallery-badge">
          <?php if ($product->hasDiscount()): ?><span class="badge" style="background:#DC2626;color:#fff">-<?= $product->discountPct() ?>%</span><?php endif; ?>
          <?php if ((time() - strtotime($product->createdAt)) < 7 * 86400): ?><span class="badge" style="background:var(--eg-blue);color:#fff">NEW</span><?php endif; ?>
          <?php if ($product->isFeatured): ?><span class="badge" style="background:var(--eg-gold);color:#1a0e00">? FEATURED</span><?php endif; ?>
        </div>
      </div>
      <?php if (count($images) > 1): ?>
      <div class="gallery-thumbs">
        <?php foreach ($images as $i => $img): $imgUrl = eg_img_url($img['path']); ?>
          <div class="gallery-thumb <?= $i===0?'active':'' ?>" onclick="setImage('<?= htmlspecialchars($imgUrl,ENT_QUOTES) ?>',this)">
            <img src="<?= htmlspecialchars($imgUrl,ENT_QUOTES) ?>" alt=""
                 onerror="this.onerror=null;this.src='https://picsum.photos/seed/<?= $product->id ?>-<?= $i ?>/200/200'">
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Product Info -->
    <div class="product-info-panel">
      <?php if ($vendor): ?>
        <a href="<?= View::url('/vendor/' . htmlspecialchars($vendor['shop_slug']??'',ENT_QUOTES)) ?>" class="product-vendor-tag">
          /* U+1F3EA */ <?= htmlspecialchars($vendor['shop_name']??'',ENT_QUOTES) ?>
        </a>
      <?php endif; ?>

      <h1 class="product-title"><?= $name ?></h1>

      <!-- Rating row -->
      <div class="product-rating-row" style="display:flex;align-items:center;gap:.5rem;">
        <span class="stars-gold" style="font-size:1.1rem;">
          <?= str_repeat('?', (int)round($product->ratingAvg)) . str_repeat('?', 5 - (int)round($product->ratingAvg)) ?>
        </span>
        <span class="rating-val"><?= number_format($product->ratingAvg, 1) ?></span>
        <span class="rating-cnt">(<?= number_format($product->ratingCount) ?> <?= t('product.reviews', ['count'=>'']) ?>)</span>
        <span style="color:var(--eg-text-muted)">*</span>
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
          <span class="out-stock">? <?= t('product.out_of_stock') ?></span>
        <?php elseif ($product->lowStock()): ?>
          <span class="low-stock">?? Only <?= $product->stockQty ?> left!</span>
        <?php else: ?>
          <span class="in-stock">? <?= t('product.in_stock') ?></span>
          <?php if ($product->trackStock): ?><span style="font-size:.8rem;color:var(--eg-text-dim)"><?= number_format($product->stockQty) ?> available</span><?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- Add to cart -->
      <div class="cart-row">
        <div class="qty-ctrl">
          <button class="qty-btn" onclick="changeQty(-1)">?</button>
          <input type="number" class="qty-input" id="qty" value="1" min="1" max="<?= $product->stockQty ?: 99 ?>">
          <button class="qty-btn" onclick="changeQty(1)">+</button>
        </div>
        <button class="add-cart-btn" id="add-cart-btn" onclick="addToCart(<?= $product->id ?>)"
          <?= !$product->inStock() ? 'disabled' : '' ?>>
          /* U+1F6D2 */ <?= t('product.add_to_cart') ?>
        </button>
        <button class="wishlist-btn" onclick="addWishlist(<?= $product->id ?>)" title="Add to wishlist">?</button>
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
        <div class="vendor-logo">/* U+1F3EA */</div>
        <div style="flex:1">
          <div class="vendor-name"><?= htmlspecialchars($vendor['shop_name']??'', ENT_QUOTES) ?></div>
          <div class="vendor-rating">? <?= number_format((float)($vendor['rating_avg']??0),1) ?> * <?= $vendor['rating_count']??0 ?> reviews</div>
        </div>
        <a href="<?= View::url('/vendor/'.htmlspecialchars($vendor['shop_slug']??'',ENT_QUOTES)) ?>" class="btn btn-sm btn-secondary" style="text-decoration:none;padding:.4rem .9rem;border-radius:10px;border:1px solid var(--eg-border);font-size:.8rem;font-weight:700">Visit Shop</a>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Detail Tabs -->
  <div style="margin-top:3rem;">
    <div class="detail-tabs">
      <div class="detail-tab active" onclick="showTab('desc',this)">/* U+1F4DD */ Description</div>
      <div class="detail-tab" onclick="showTab('reviews',this)">? Reviews (<?= count($reviews) ?>)</div>
    </div>

    <!-- Description -->
    <div id="tab-desc">
      <?php if ($desc): ?>
        <div style="line-height:1.8;color:var(--eg-text-dim)"><?= nl2br(htmlspecialchars(strip_tags($desc), ENT_QUOTES)) ?></div>
      <?php else: ?>
        <p style="color:var(--eg-text-muted)">No description provided for this product.</p>
      <?php endif; ?>
    </div>

    <!-- Reviews -->
    <div id="tab-reviews" style="display:none;">
      <?php if (empty($reviews)): ?>
        <p style="color:var(--eg-text-muted);padding:1rem 0">No reviews yet for this product.</p>
      <?php else: ?>
        <?php foreach ($reviews as $rv): ?>
        <div class="review-card">
          <div class="review-header">
            <div>
              <div class="reviewer-name"><?= htmlspecialchars($rv['reviewer']??'Anonymous', ENT_QUOTES) ?></div>
              <span class="stars-gold"><?= str_repeat('?',(int)($rv['rating']??0)).str_repeat('?',5-(int)($rv['rating']??0)) ?></span>
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
              <label for="rv-star-<?= $i ?>" style="cursor:pointer;color:var(--eg-border)">★</label>
            <?php endfor; ?>
          </div>
          <input type="text" name="title" maxlength="150" placeholder="Title (optional)"
                 style="width:100%;padding:.6rem .8rem;border-radius:10px;border:1px solid var(--eg-border);background:var(--eg-glass);color:inherit;margin-bottom:.5rem">
          <textarea name="body" rows="3" required placeholder="Share your experience with this product…"
                    style="width:100%;padding:.6rem .8rem;border-radius:10px;border:1px solid var(--eg-border);background:var(--eg-glass);color:inherit;margin-bottom:.75rem"></textarea>
          <button type="submit" class="add-cart-btn" style="width:auto;flex:none;padding:.6rem 1.4rem">Submit review</button>
        </form>
      </div>
      <style>
        .review-star-input input:checked ~ label,
        .review-star-input label:hover,
        .review-star-input label:hover ~ label { color: var(--eg-gold, #d4a843); }
      </style>
      <?php else: ?>
      <p style="margin-top:1.5rem;color:var(--eg-text-muted)"><a href="<?= View::url('/login') ?>">Log in</a> to write a review.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- Related Products -->
  <?php if (!empty($related)): ?>
  <div class="related-section">
    <div class="section-header">
      <div><h2 class="section-title">You Might Also Like</h2></div>
      <a href="<?= View::url('/search') ?>">See all <i class="bi bi-arrow-right"></i></a>
    </div>
    <div class="product-grid">
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
  btn.textContent = '? Adding...'; btn.disabled = true;
  fetch('<?= View::url('/cart/add') ?>', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'product_id='+id+'&qty='+qty+'&_token=<?= $_SESSION['_token'] ?? '' ?>'
  }).then(r=>r.json()).then(d=>{
    btn.textContent = d.ok ? '? Added to Cart!' : '? Error';
    setTimeout(()=>{ btn.textContent = '/* U+1F6D2 */ <?= t('product.add_to_cart') ?>'; btn.disabled = false; }, 2000);
  }).catch(()=>{ btn.textContent = '/* U+1F6D2 */ Add to Cart'; btn.disabled=false; });
}
function addWishlist(id) {
  const btn = event.target;
  btn.textContent = '?'; btn.style.color='#DC2626';
  setTimeout(()=>{ btn.style.color=''; }, 300);
}
</script>
<?php
$__content = ob_get_clean();
echo \App\Core\View::capture('_layouts/public', [
    '__content' => $__content,
    'pageTitle'  => $product->name($locale ?? 'en'),
    'locale'     => $locale ?? 'en',
]);
