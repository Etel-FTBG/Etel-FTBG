<?php
/**
 * Etel Gebeya v2 Homepage -- Alibaba-inspired layout
 * Fixed: Category objects use camelCase properties (nameEn, nameAm, slug)
 */
use App\Core\View;

require_once __DIR__ . '/_product_card.php';

View::render('_layouts/public', [
    'pageTitle' => "Ethiopia's Marketplace",
], function() use ($featured, $recent, $categories) {
    $locale     = $GLOBALS['__i18n']->locale();
    $featured   = $featured   ?? [];
    $recent     = $recent     ?? [];
    $categories = $categories ?? [];

    // Map Bootstrap Icon by category name keyword
    function eg_cat_icon(string $name): string {
        $lower = strtolower($name);
        $map = [
            'electronic' => 'bi-phone',
            'phone'      => 'bi-phone',
            'laptop'     => 'bi-laptop',
            'computer'   => 'bi-pc-display',
            'fashion'    => 'bi-bag-heart',
            'cloth'      => 'bi-person-standing',
            'wear'       => 'bi-person-standing',
            'shoe'       => 'bi-boot',
            'home'       => 'bi-house-door',
            'furniture'  => 'bi-lamp',
            'food'       => 'bi-egg-fried',
            'grocer'     => 'bi-cart',
            'coffee'     => 'bi-cup-hot',
            'health'     => 'bi-heart-pulse',
            'beauty'     => 'bi-stars',
            'baby'       => 'bi-emoji-smile',
            'toy'        => 'bi-controller',
            'sport'      => 'bi-bicycle',
            'auto'       => 'bi-car-front',
            'vehicle'    => 'bi-car-front',
            'book'       => 'bi-book',
            'office'     => 'bi-briefcase',
            'stationer'  => 'bi-pen',
            'tool'       => 'bi-tools',
            'garden'     => 'bi-flower1',
            'pet'        => 'bi-heart',
            'travel'     => 'bi-airplane',
            'luggage'    => 'bi-luggage',
            'clean'      => 'bi-droplet',
            'detergent'  => 'bi-bucket',
            'laundry'    => 'bi-water',
            'floor'      => 'bi-house',
            'care'       => 'bi-shield-heart',
            'oil'        => 'bi-droplet',
            'teleco'     => 'bi-wifi',
        ];
        foreach ($map as $kw => $ico) {
            if (str_contains($lower, $kw)) return $ico;
        }
        return 'bi-grid';
    }
?>

<div class="eg-page">

  <!-- ===== LEFT SIDEBAR ===== -->
  <aside class="eg-sidebar">
    <div class="eg-sidebar-box">
      <div class="eg-sidebar-head">All Categories</div>
      <?php if (!empty($categories)): ?>
        <?php foreach ($categories as $cat): ?>
          <a href="<?= View::url('/category/' . e($cat->slug)) ?>" class="eg-sidebar-item">
            <i class="bi <?= eg_cat_icon($cat->nameEn) ?>"></i>
            <?= e(html_entity_decode($cat->name($locale), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?>
          </a>
        <?php endforeach; ?>
        <a href="<?= View::url('/search') ?>" class="eg-sidebar-item" style="color:var(--g2);font-weight:700">
          <i class="bi bi-grid-3x3-gap"></i> View All
        </a>
      <?php else: ?>
        <?php
        $fallback = [
            ['q'=>'electronics',  'name'=>'Electronics',      'ico'=>'bi-phone'],
            ['q'=>'fashion',      'name'=>'Fashion',          'ico'=>'bi-bag-heart'],
            ['q'=>'home',         'name'=>'Home & Living',    'ico'=>'bi-house-door'],
            ['q'=>'food',         'name'=>'Food & Grocery',   'ico'=>'bi-egg-fried'],
            ['q'=>'health',       'name'=>'Health & Beauty',  'ico'=>'bi-heart-pulse'],
            ['q'=>'office',       'name'=>'Office & School',  'ico'=>'bi-briefcase'],
            ['q'=>'sports',       'name'=>'Sports',           'ico'=>'bi-bicycle'],
            ['q'=>'automotive',   'name'=>'Automotive',       'ico'=>'bi-car-front'],
            ['q'=>'baby',         'name'=>'Baby & Toys',      'ico'=>'bi-emoji-smile'],
            ['q'=>'tools',        'name'=>'Tools & Garden',   'ico'=>'bi-tools'],
        ];
        foreach ($fallback as $fb): ?>
          <a href="<?= View::url('/search?q=' . $fb['q']) ?>" class="eg-sidebar-item">
            <i class="bi <?= $fb['ico'] ?>"></i> <?= $fb['name'] ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </aside>

  <!-- ===== MAIN CONTENT ===== -->
  <div class="eg-main">

    <!-- HERO -->
    <div class="eg-hero">
      <div class="eg-hero-left">
        <div class="eg-hero-tag"><i class="bi bi-stars"></i> Ethiopia's #1 Marketplace</div>
        <h1>Shop Ethiopia,<br>Delivered to You</h1>
        <p>Buy in Birr, pay with Telebirr, CBE Birr or cards. Fast delivery across Ethiopia.</p>
        <div class="eg-hero-actions">
          <a href="<?= View::url('/search') ?>" class="eg-btn eg-btn-gold">
            <i class="bi bi-bag-check"></i> Shop Now
          </a>
          <a href="<?= View::url('/sell') ?>" class="eg-btn eg-btn-outline">
            <i class="bi bi-shop"></i> Become a Vendor
          </a>
        </div>
        <div class="eg-hero-stats">
          <div><div class="eg-hero-stat-val">10K+</div><div class="eg-hero-stat-label">Products</div></div>
          <div><div class="eg-hero-stat-val">500+</div><div class="eg-hero-stat-label">Vendors</div></div>
          <div><div class="eg-hero-stat-val">50K+</div><div class="eg-hero-stat-label">Customers</div></div>
        </div>
      </div>
      <div class="eg-hero-right">
        <a href="<?= View::url('/search?q=electronics') ?>" class="eg-hero-card">
          <div class="eg-hero-card-ico"><i class="bi bi-phone" style="font-size:26px;color:#D4AF37"></i></div>
          <div class="eg-hero-card-name">Electronics</div>
          <div class="eg-hero-card-sub">Phones &amp; laptops</div>
        </a>
        <a href="<?= View::url('/search?q=fashion') ?>" class="eg-hero-card">
          <div class="eg-hero-card-ico"><i class="bi bi-bag-heart" style="font-size:26px;color:#D4AF37"></i></div>
          <div class="eg-hero-card-name">Fashion</div>
          <div class="eg-hero-card-sub">Clothes &amp; shoes</div>
        </a>
        <a href="<?= View::url('/search?q=home') ?>" class="eg-hero-card">
          <div class="eg-hero-card-ico"><i class="bi bi-house-door" style="font-size:26px;color:#D4AF37"></i></div>
          <div class="eg-hero-card-name">Home Living</div>
          <div class="eg-hero-card-sub">Furniture &amp; decor</div>
        </a>
        <a href="<?= View::url('/search?q=coffee') ?>" class="eg-hero-card">
          <div class="eg-hero-card-ico"><i class="bi bi-cup-hot" style="font-size:26px;color:#D4AF37"></i></div>
          <div class="eg-hero-card-name">Ethiopian</div>
          <div class="eg-hero-card-sub">Coffee &amp; spices</div>
        </a>
      </div>
    </div>

    <!-- PROMO STRIP -->
    <div class="eg-promo-strip">
      <div class="eg-promo-card">
        <i class="bi bi-truck eg-promo-icon"></i>
        <div class="eg-promo-card-tag">Fast Delivery</div>
        <div class="eg-promo-card-title">Nationwide Shipping</div>
        <div class="eg-promo-card-sub">Delivered across all regions of Ethiopia</div>
        <a href="<?= View::url('/search') ?>" class="eg-promo-card-link">Shop Now <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="eg-promo-card">
        <i class="bi bi-phone eg-promo-icon"></i>
        <div class="eg-promo-card-tag">Pay Your Way</div>
        <div class="eg-promo-card-title">Telebirr &amp; CBE Birr</div>
        <div class="eg-promo-card-sub">Secure payments in Ethiopian Birr</div>
        <a href="<?= View::url('/checkout') ?>" class="eg-promo-card-link">Learn More <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="eg-promo-card">
        <i class="bi bi-shop eg-promo-icon"></i>
        <div class="eg-promo-card-tag">500+ Sellers</div>
        <div class="eg-promo-card-title">Ethiopian Vendors</div>
        <div class="eg-promo-card-sub">Real businesses, verified sellers</div>
        <a href="<?= View::url('/sell') ?>" class="eg-promo-card-link">Sell With Us <i class="bi bi-arrow-right"></i></a>
      </div>
    </div>

    <!-- SHOP BY CATEGORY -->
    <?php if (!empty($categories)): ?>
    <div class="mb-20">
      <div class="eg-sec-head">
        <div>
          <div class="eg-sec-title">Shop by Category</div>
          <div class="eg-sec-sub">Find exactly what you need</div>
        </div>
        <a href="<?= View::url('/search') ?>" class="eg-sec-more">See All <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="eg-cat-grid">
        <?php foreach ($categories as $cat): ?>
          <a href="<?= View::url('/category/' . e($cat->slug)) ?>" class="eg-cat-card">
            <div class="eg-cat-card-ico">
              <?php if (!empty($cat->icon)): ?>
                <i class="bi <?= e($cat->icon) ?>" style="font-size:22px;color:#FFE066"></i>
              <?php else: ?>
                <i class="bi <?= eg_cat_icon($cat->nameEn) ?>" style="font-size:22px;color:#FFE066"></i>
              <?php endif; ?>
            </div>
            <div class="eg-cat-card-name"><?= e(html_entity_decode($cat->name($locale), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?></div>
          </a>
        <?php endforeach; ?>
        <a href="<?= View::url('/search') ?>" class="eg-cat-card" style="border-style:dashed">
          <div class="eg-cat-card-ico" style="background:none;border:none">
            <i class="bi bi-plus-circle" style="font-size:24px;color:var(--g2)"></i>
          </div>
          <div class="eg-cat-card-name" style="color:var(--g2)">All Categories</div>
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- FEATURED PRODUCTS -->
    <?php if (!empty($featured)): ?>
    <div class="mb-20">
      <div class="eg-sec-head">
        <div>
          <div class="eg-sec-title">Featured Products</div>
          <div class="eg-sec-sub">Hand-picked by Etel Gebeya</div>
        </div>
        <a href="<?= View::url('/search') ?>" class="eg-sec-more">View All <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="eg-product-grid">
        <?php foreach ($featured as $p): echo eg_product_card($p, $locale); endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- VENDOR BANNER -->
    <div class="eg-vendor-banner">
      <h2>Ready to Sell on Etel Gebeya?</h2>
      <p>Join 500+ Ethiopian vendors reaching thousands of customers nationwide. Free to start.</p>
      <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
        <a href="<?= View::url('/sell') ?>" class="eg-btn eg-btn-gold">
          <i class="bi bi-shop"></i> Start Selling Free
        </a>
        <a href="<?= View::url('/vendor-panel') ?>" class="eg-btn eg-btn-outline">
          <i class="bi bi-speedometer2"></i> Vendor Dashboard
        </a>
      </div>
    </div>

    <!-- NEW ARRIVALS -->
    <?php if (!empty($recent)): ?>
    <div class="mb-20">
      <div class="eg-sec-head">
        <div>
          <div class="eg-sec-title">New Arrivals</div>
          <div class="eg-sec-sub">Fresh products added this week</div>
        </div>
        <a href="<?= View::url('/search?sort=newest') ?>" class="eg-sec-more">View All <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="eg-product-grid">
        <?php foreach ($recent as $p): echo eg_product_card($p, $locale); endforeach; ?>
      </div>
    </div>
    <?php else: ?>
    <div class="eg-empty mt-20">
      <i class="bi bi-bag"></i>
      <p>No products yet. <a href="<?= View::url('/sell') ?>">Be the first vendor!</a></p>
    </div>
    <?php endif; ?>

  </div><!-- /.eg-main -->
</div><!-- /.eg-page -->

<?php
});
