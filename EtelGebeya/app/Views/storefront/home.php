<?php
/**
 * Etel Gebeya v2 Homepage -- Alibaba-inspired layout
 * Fixed: Category objects use camelCase properties (nameEn, nameAm, slug)
 */
use App\Core\View;

require_once __DIR__ . '/_product_card.php';

View::render('_layouts/public', [
    'pageTitle' => t('storefront.home.page_title'),
], function() use ($featured, $recent, $categories) {
    $locale     = $GLOBALS['__i18n']->locale();
    $featured   = $featured   ?? [];
    $recent     = $recent     ?? [];
    $categories = $categories ?? [];

    // Hot Deals -- steepest discounts first, pulled from what's already loaded.
    // A product can appear in both $featured and $recent (e.g. a small
    // catalog where everything is "recent"); dedupe by id or it shows twice.
    $dealsById = [];
    foreach (array_merge($featured, $recent) as $p) {
        if ($p->hasDiscount()) $dealsById[$p->id] = $p;
    }
    $deals = array_values($dealsById);
    usort($deals, fn($a, $b) => $b->discountPct() <=> $a->discountPct());
    $deals = array_slice($deals, 0, 6);

    // eg_cat_icon() is a shared global helper (bootstrap.php) so the
    // homepage grid, search sidebar, and category pages all agree on
    // which icon a category gets.
?>

<div class="eg-page">

  <!-- ===== LEFT SIDEBAR ===== -->
  <aside class="eg-sidebar">
    <div class="eg-sidebar-box">
      <div class="eg-sidebar-head"><?= e(t('storefront.home.all_categories')) ?></div>
      <?php if (!empty($categories)): ?>
        <?php foreach ($categories as $cat): ?>
          <a href="<?= View::url('/category/' . e($cat->slug)) ?>" class="eg-sidebar-item">
            <i class="bi <?= eg_cat_icon($cat->nameEn) ?>"></i>
            <?= e(html_entity_decode($cat->name($locale), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?>
          </a>
        <?php endforeach; ?>
        <a href="<?= View::url('/search') ?>" class="eg-sidebar-item" style="color:var(--g2);font-weight:700">
          <i class="bi bi-grid-3x3-gap"></i> <?= e(t('storefront.home.view_all')) ?>
        </a>
      <?php else: ?>
        <?php
        $fallback = [
            ['q'=>'electronics',  'name'=>t('storefront.home.fb_electronics'),      'ico'=>'bi-phone'],
            ['q'=>'fashion',      'name'=>t('storefront.home.fb_fashion'),          'ico'=>'bi-bag-heart'],
            ['q'=>'home',         'name'=>t('storefront.home.fb_home_living'),      'ico'=>'bi-house-door'],
            ['q'=>'food',         'name'=>t('storefront.home.fb_food_grocery'),     'ico'=>'bi-egg-fried'],
            ['q'=>'health',       'name'=>t('storefront.home.fb_health_beauty'),    'ico'=>'bi-heart-pulse'],
            ['q'=>'office',       'name'=>t('storefront.home.fb_office_school'),    'ico'=>'bi-briefcase'],
            ['q'=>'sports',       'name'=>t('storefront.home.fb_sports'),           'ico'=>'bi-bicycle'],
            ['q'=>'automotive',   'name'=>t('storefront.home.fb_automotive'),       'ico'=>'bi-car-front'],
            ['q'=>'baby',         'name'=>t('storefront.home.fb_baby_toys'),        'ico'=>'bi-emoji-smile'],
            ['q'=>'tools',        'name'=>t('storefront.home.fb_tools_garden'),     'ico'=>'bi-tools'],
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
        <h1><?= e(t('storefront.home.hero_title_1')) ?><br><?= e(t('storefront.home.hero_title_2')) ?></h1>
        <p><?= e(t('storefront.home.hero_sub')) ?></p>
        <div class="eg-hero-actions">
          <a href="<?= View::url('/search') ?>" class="eg-btn eg-btn-gold">
            <i class="bi bi-bag-check"></i> <?= e(t('storefront.home.shop_now')) ?>
          </a>
          <a href="<?= View::url('/sell') ?>" class="eg-btn eg-btn-outline">
            <i class="bi bi-shop"></i> <?= e(t('storefront.footer.become_vendor')) ?>
          </a>
        </div>
        <div class="eg-hero-stats">
          <div><div class="eg-hero-stat-val">10K+</div><div class="eg-hero-stat-label"><?= e(t('admin.nav.products')) ?></div></div>
          <div><div class="eg-hero-stat-val">500+</div><div class="eg-hero-stat-label"><?= e(t('admin.nav.sellers')) ?></div></div>
          <div><div class="eg-hero-stat-val">50K+</div><div class="eg-hero-stat-label"><?= e(t('storefront.home.customers')) ?></div></div>
        </div>
      </div>
      <div class="eg-hero-right">
        <a href="<?= View::url('/search?q=electronics') ?>" class="eg-hero-card">
          <div class="eg-hero-card-ico"><i class="bi bi-phone" style="font-size:26px;color:#D4AF37"></i></div>
          <div class="eg-hero-card-name"><?= e(t('storefront.catnav.electronics')) ?></div>
          <div class="eg-hero-card-sub"><?= e(t('storefront.home.hero_card_electronics_sub')) ?></div>
        </a>
        <a href="<?= View::url('/search?q=fashion') ?>" class="eg-hero-card">
          <div class="eg-hero-card-ico"><i class="bi bi-bag-heart" style="font-size:26px;color:#D4AF37"></i></div>
          <div class="eg-hero-card-name"><?= e(t('storefront.catnav.fashion')) ?></div>
          <div class="eg-hero-card-sub"><?= e(t('storefront.home.hero_card_fashion_sub')) ?></div>
        </a>
        <a href="<?= View::url('/search?q=home') ?>" class="eg-hero-card">
          <div class="eg-hero-card-ico"><i class="bi bi-house-door" style="font-size:26px;color:#D4AF37"></i></div>
          <div class="eg-hero-card-name"><?= e(t('storefront.home.hero_card_home_living')) ?></div>
          <div class="eg-hero-card-sub"><?= e(t('storefront.home.hero_card_home_living_sub')) ?></div>
        </a>
        <a href="<?= View::url('/search?q=coffee') ?>" class="eg-hero-card">
          <div class="eg-hero-card-ico"><i class="bi bi-cup-hot" style="font-size:26px;color:#D4AF37"></i></div>
          <div class="eg-hero-card-name"><?= e(t('storefront.home.hero_card_ethiopian')) ?></div>
          <div class="eg-hero-card-sub"><?= e(t('storefront.home.hero_card_ethiopian_sub')) ?></div>
        </a>
      </div>
    </div>

    <!-- TRUST BAR -->
    <div class="eg-trust-bar">
      <div class="eg-trust-item"><i class="bi bi-shield-check"></i> <?= e(t('storefront.home.trust_buyer_protection')) ?></div>
      <div class="eg-trust-item"><i class="bi bi-patch-check"></i> <?= e(t('storefront.home.trust_verified_vendors')) ?></div>
      <div class="eg-trust-item"><i class="bi bi-lock"></i> <?= e(t('storefront.home.trust_secure_payments')) ?></div>
      <div class="eg-trust-item"><i class="bi bi-truck"></i> <?= e(t('storefront.home.trust_nationwide_delivery')) ?></div>
      <div class="eg-trust-item"><i class="bi bi-geo-alt"></i> <?= e(t('storefront.home.trust_based_in_hawassa')) ?></div>
    </div>

    <!-- PROMO STRIP -->
    <div class="eg-promo-strip">
      <div class="eg-promo-card">
        <i class="bi bi-truck eg-promo-icon"></i>
        <div class="eg-promo-card-tag"><?= e(t('storefront.home.promo_fast_delivery_tag')) ?></div>
        <div class="eg-promo-card-title"><?= e(t('storefront.home.promo_fast_delivery_title')) ?></div>
        <div class="eg-promo-card-sub"><?= e(t('storefront.home.promo_fast_delivery_sub')) ?></div>
        <a href="<?= View::url('/search') ?>" class="eg-promo-card-link"><?= e(t('storefront.home.shop_now')) ?> <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="eg-promo-card">
        <i class="bi bi-phone eg-promo-icon"></i>
        <div class="eg-promo-card-tag"><?= e(t('storefront.home.promo_pay_your_way_tag')) ?></div>
        <div class="eg-promo-card-title"><?= e(t('storefront.home.promo_pay_your_way_title')) ?></div>
        <div class="eg-promo-card-sub"><?= e(t('storefront.home.promo_pay_your_way_sub')) ?></div>
        <a href="<?= View::url('/checkout') ?>" class="eg-promo-card-link"><?= e(t('storefront.home.learn_more')) ?> <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="eg-promo-card">
        <i class="bi bi-shop eg-promo-icon"></i>
        <div class="eg-promo-card-tag"><?= e(t('storefront.home.promo_sellers_tag')) ?></div>
        <div class="eg-promo-card-title"><?= e(t('storefront.home.promo_sellers_title')) ?></div>
        <div class="eg-promo-card-sub"><?= e(t('storefront.home.promo_sellers_sub')) ?></div>
        <a href="<?= View::url('/sell') ?>" class="eg-promo-card-link"><?= e(t('storefront.home.sell_with_us')) ?> <i class="bi bi-arrow-right"></i></a>
      </div>
    </div>

    <!-- HOT DEALS -->
    <?php if (!empty($deals)): ?>
    <div class="mb-20">
      <div class="eg-sec-head">
        <div>
          <div class="eg-sec-title eg-sec-title-hot"><i class="bi bi-fire"></i> <?= e(t('storefront.home.hot_deals')) ?></div>
          <div class="eg-sec-sub"><?= e(t('storefront.home.hot_deals_sub')) ?></div>
        </div>
        <a href="<?= View::url('/search') ?>" class="eg-sec-more"><?= e(t('storefront.home.see_all_deals')) ?> <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="eg-product-grid">
        <?php foreach ($deals as $p): echo eg_product_card($p, $locale); endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- SHOP BY CATEGORY -->
    <?php if (!empty($categories)): ?>
    <div class="mb-20">
      <div class="eg-sec-head">
        <div>
          <div class="eg-sec-title"><?= e(t('storefront.home.shop_by_category')) ?></div>
          <div class="eg-sec-sub"><?= e(t('storefront.home.shop_by_category_sub')) ?></div>
        </div>
        <a href="<?= View::url('/search') ?>" class="eg-sec-more"><?= e(t('storefront.home.see_all')) ?> <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="eg-cat-grid">
        <?php foreach ($categories as $cat): [$ca, $cb] = eg_cat_color($cat->id); ?>
          <a href="<?= View::url('/category/' . e($cat->slug)) ?>" class="eg-cat-card"
             style="--cat-a:<?= $ca ?>;--cat-b:<?= $cb ?>">
            <div class="eg-cat-card-ico">
              <i class="bi <?= eg_cat_icon($cat->nameEn) ?>"></i>
            </div>
            <div class="eg-cat-card-name"><?= e(html_entity_decode($cat->name($locale), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?></div>
            <div class="eg-cat-card-cta"><?= e(t('storefront.home.shop_now_lower')) ?> <i class="bi bi-arrow-right"></i></div>
          </a>
        <?php endforeach; ?>
        <a href="<?= View::url('/search') ?>" class="eg-cat-card eg-cat-card-more">
          <div class="eg-cat-card-ico">
            <i class="bi bi-grid-3x3-gap-fill"></i>
          </div>
          <div class="eg-cat-card-name"><?= e(t('storefront.home.all_categories')) ?></div>
          <div class="eg-cat-card-cta"><?= e(t('storefront.home.browse_all')) ?> <i class="bi bi-arrow-right"></i></div>
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- FEATURED PRODUCTS -->
    <?php if (!empty($featured)): ?>
    <div class="mb-20">
      <div class="eg-sec-head">
        <div>
          <div class="eg-sec-title"><?= e(t('storefront.home.featured_products')) ?></div>
          <div class="eg-sec-sub"><?= e(t('storefront.home.featured_products_sub')) ?></div>
        </div>
        <a href="<?= View::url('/search') ?>" class="eg-sec-more"><?= e(t('common.view_all')) ?> <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="eg-product-grid">
        <?php foreach ($featured as $p): echo eg_product_card($p, $locale); endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- VENDOR BANNER -->
    <div class="eg-vendor-banner">
      <h2><?= e(t('storefront.home.vendor_banner_title')) ?></h2>
      <p><?= e(t('storefront.home.vendor_banner_sub')) ?></p>
      <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
        <a href="<?= View::url('/sell') ?>" class="eg-btn eg-btn-gold">
          <i class="bi bi-shop"></i> <?= e(t('storefront.home.start_selling_free')) ?>
        </a>
        <a href="<?= View::url('/vendor-panel') ?>" class="eg-btn eg-btn-outline">
          <i class="bi bi-speedometer2"></i> <?= e(t('storefront.footer.vendor_dashboard')) ?>
        </a>
      </div>
    </div>

    <!-- NEW ARRIVALS -->
    <?php if (!empty($recent)): ?>
    <div class="mb-20">
      <div class="eg-sec-head">
        <div>
          <div class="eg-sec-title"><?= e(t('storefront.home.new_arrivals')) ?></div>
          <div class="eg-sec-sub"><?= e(t('storefront.home.new_arrivals_sub')) ?></div>
        </div>
        <a href="<?= View::url('/search?sort=newest') ?>" class="eg-sec-more"><?= e(t('common.view_all')) ?> <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="eg-product-grid">
        <?php foreach ($recent as $p): echo eg_product_card($p, $locale); endforeach; ?>
      </div>
    </div>
    <?php else: ?>
    <div class="eg-empty mt-20">
      <i class="bi bi-bag"></i>
      <p><?= e(t('storefront.home.no_products_yet')) ?> <a href="<?= View::url('/sell') ?>"><?= e(t('storefront.home.be_first_vendor')) ?></a></p>
    </div>
    <?php endif; ?>

  </div><!-- /.eg-main -->
</div><!-- /.eg-page -->

<?php
});
