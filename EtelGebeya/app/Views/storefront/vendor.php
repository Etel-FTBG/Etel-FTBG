<?php
/**
 * Vendor storefront page.
 * Variables: $vendor (array), $products (Product[]), $locale
 */
declare(strict_types=1);
use App\Core\View;
require_once __DIR__ . '/_product_card.php';

$locale = $locale ?? 'en';
$db     = $GLOBALS['__db'] ?? null;
$shopName = htmlspecialchars($vendor['shop_name'] ?? 'Shop', ENT_QUOTES);
$tagline  = htmlspecialchars($locale==='am' ? ($vendor['tagline_am']??$vendor['tagline_en']??'') : ($vendor['tagline_en']??''), ENT_QUOTES);

// Vendor stats
$stats = ['products'=>0,'sold'=>0,'reviews'=>0,'rating'=>0];
if ($db && $db->isConnected()) {
    $vid = (int)($vendor['id'] ?? 0);
    $stats['products'] = (int)$db->fetchColumn("SELECT COUNT(*) FROM products WHERE seller_id=? AND status IN ('published','publish','active')",[$vid]);
    $stats['sold']     = (int)$db->fetchColumn("SELECT COALESCE(SUM(sold_count),0) FROM products WHERE seller_id=?",[$vid]);
    $reviews_row = $db->fetch("SELECT COUNT(*) cnt, COALESCE(AVG(r.rating),0) avg FROM reviews r JOIN products p ON p.id=r.product_id WHERE p.seller_id=? AND r.status='approved'",[$vid]);
    $stats['reviews'] = (int)($reviews_row['cnt'] ?? 0);
    $stats['rating']  = round((float)($reviews_row['avg'] ?? 0), 1);
}

// Recent reviews for this vendor
$recent_reviews = [];
if ($db && $db->isConnected()) {
    $recent_reviews = $db->fetchAll(
        "SELECT r.*, u.full_name reviewer, p.name_en product_name FROM reviews r
         JOIN users u ON u.id=r.user_id JOIN products p ON p.id=r.product_id
         WHERE p.seller_id=? AND r.status='approved' ORDER BY r.created_at DESC LIMIT 5",
        [(int)($vendor['id']??0)]
    );
}

ob_start();
?>
<style>
.vendor-page { padding: 24px 0 48px; }
.vendor-profile-row {
  display: flex; align-items: center; gap: 24px;
  margin: 0 24px 20px;
  background: var(--card); border: 1px solid var(--border); border-radius: var(--r2);
  padding: 20px 24px; position: relative; overflow: hidden;
}
@media(max-width:640px){ .vendor-profile-row { margin: 0 16px -20px; padding: 18px; flex-wrap: wrap; } }
.vendor-avatar {
  width: 90px; height: 90px; border-radius: var(--r2);
  border: 3px solid var(--card); box-shadow: var(--shadow);
  background: linear-gradient(135deg, rgba(212,175,55,.2), rgba(212,175,55,.08));
  color: var(--g2);
  display: flex; align-items: center; justify-content: center;
  font-size: 40px; flex-shrink: 0;
}
.vendor-head { flex: 1; min-width: 0; }
.vendor-head h1 { font-size: 26px; font-weight: 900; line-height: 1.2; color: var(--text); }
.vendor-head p { font-size: 13.5px; color: var(--text2); margin-top: 5px; }
.vendor-badges { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
.vendor-badge {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 11px; border-radius: 20px; font-size: 11.5px; font-weight: 700;
}
.badge-verified { background: rgba(46,204,113,.12); color: var(--green); border: 1px solid rgba(46,204,113,.3); }
.badge-rating   { background: rgba(212,175,55,.1); color: var(--g1); border: 1px solid var(--border); }
.vendor-seal {
  width: 64px; height: 64px; flex-shrink: 0; opacity: .9;
  align-self: center; margin-left: auto;
  filter: drop-shadow(0 2px 6px rgba(212,175,55,.25));
}
@media(max-width:640px){ .vendor-seal { display: none; } }
.vendor-stats-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; margin: 20px 24px; }
@media(max-width:640px){ .vendor-stats-row { grid-template-columns: repeat(2,1fr); margin: 20px 16px; } }
.vendor-stat { background: var(--card); border: 1px solid var(--border); border-radius: var(--r2); padding: 16px; text-align: center; }
.vendor-stat-num { font-size: 26px; font-weight: 900; color: var(--g1); }
.vendor-stat-lbl { font-size: 12px; color: var(--text3); margin-top: 3px; }
.vendor-layout { display: grid; grid-template-columns: 1fr 280px; gap: 20px; padding: 0 24px; }
@media(max-width:900px){ .vendor-layout { grid-template-columns: 1fr; padding: 0 16px; } }
.vendor-sidebar-card { background: var(--card); border: 1px solid var(--border); border-radius: var(--r2); padding: 20px; margin-bottom: 16px; }
.vendor-sidebar-card h4 { font-size: 14px; font-weight: 800; color: var(--text); margin-bottom: 12px; }
.vendor-info-row { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text2); padding: 5px 0; }
.vendor-info-row i { color: var(--g2); font-size: 15px; }
.mini-review { padding: 12px 0; border-bottom: 1px solid var(--border2); }
.mini-review:last-child { border-bottom: none; }
.mini-reviewer { font-size: 12.5px; font-weight: 700; color: var(--text); }
.mini-review-body { font-size: 12px; color: var(--text2); margin-top: 5px; line-height: 1.5; }
</style>

<div class="vendor-page">
  <div>
    <!-- Profile row -->
    <div class="vendor-profile-row">
      <div class="vendor-avatar"><i class="bi bi-shop"></i></div>
      <div class="vendor-head">
        <h1><?= $shopName ?></h1>
        <?php if ($tagline): ?><p><?= $tagline ?></p><?php endif; ?>
        <div class="vendor-badges">
          <span class="vendor-badge badge-verified"><i class="bi bi-patch-check-fill"></i> <?= e(t('storefront.vendor.verified_seller')) ?></span>
          <span class="vendor-badge badge-rating"><i class="bi bi-star-fill"></i> <?= $stats['rating'] ?> / 5.0</span>
          <?php if (!empty($vendor['business_type'])): ?>
            <span class="vendor-badge" style="background:rgba(212,175,55,.1);color:var(--g1);border:1px solid var(--border)">
              <i class="bi bi-building"></i> <?= htmlspecialchars(ucfirst($vendor['business_type']), ENT_QUOTES) ?>
            </span>
          <?php endif; ?>
        </div>
      </div>
      <img class="vendor-seal" src="<?= View::asset('img/etel-master-seal.webp') ?>" alt="Etel Family Technology and Business Group seal">
    </div>

    <!-- Stats -->
    <div class="vendor-stats-row">
      <div class="vendor-stat"><div class="vendor-stat-num"><?= number_format($stats['products']) ?></div><div class="vendor-stat-lbl"><?= e(t('admin.nav.products')) ?></div></div>
      <div class="vendor-stat"><div class="vendor-stat-num"><?= number_format($stats['sold']) ?></div><div class="vendor-stat-lbl"><?= e(t('storefront.vendor.items_sold')) ?></div></div>
      <div class="vendor-stat"><div class="vendor-stat-num"><?= number_format($stats['reviews']) ?></div><div class="vendor-stat-lbl"><?= e(t('storefront.vendor.reviews')) ?></div></div>
      <div class="vendor-stat"><div class="vendor-stat-num"><?= $stats['rating'] ?> <i class="bi bi-star-fill" style="font-size:.7em"></i></div><div class="vendor-stat-lbl"><?= e(t('storefront.vendor.rating')) ?></div></div>
    </div>

    <!-- Content layout -->
    <div class="vendor-layout">
      <!-- Products -->
      <div>
        <div class="eg-sec-head" style="margin-bottom:20px;">
          <div>
            <div class="eg-sec-title"><?= t('storefront.vendor.products_by', ['shop' => $shopName]) ?></div>
            <div class="eg-sec-sub"><?= e(t('storefront.vendor.items_available', ['n' => number_format($stats['products'])])) ?></div>
          </div>
        </div>
        <?php if (empty($products)): ?>
          <div style="text-align:center;padding:48px 20px;color:var(--text3)">
            <div style="font-size:48px;margin-bottom:12px;color:var(--g2);opacity:.5"><i class="bi bi-box-seam"></i></div>
            <p><?= e(t('storefront.vendor.no_products_yet')) ?></p>
          </div>
        <?php else: ?>
          <div class="eg-product-grid">
            <?php foreach ($products as $p): ?>
              <?= product_card($p, $locale) ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Sidebar -->
      <aside>
        <!-- About -->
        <div class="vendor-sidebar-card">
          <h4><i class="bi bi-info-circle"></i> <?= e(t('storefront.vendor.about_this_shop')) ?></h4>
          <?php
            $about = $locale==='am' ? ($vendor['description_am']??$vendor['description_en']??'') : ($vendor['description_en']??'');
            $about = strip_tags($about ?? '');
          ?>
          <?php if ($about): ?>
            <p style="font-size:.83rem;color:var(--text2);line-height:1.7"><?= htmlspecialchars(mb_substr($about,0,200,'UTF-8'),ENT_QUOTES) ?><?= mb_strlen($about,'UTF-8')>200?'...':'' ?></p>
          <?php else: ?>
            <p style="font-size:.82rem;color:var(--text3)"><?= e(t('storefront.vendor.no_description_provided')) ?></p>
          <?php endif; ?>
        </div>

        <!-- Contact -->
        <div class="vendor-sidebar-card">
          <h4><i class="bi bi-telephone"></i> <?= e(t('storefront.vendor.contact_location')) ?></h4>
          <?php if (!empty($vendor['phone'])): ?>
            <div class="vendor-info-row"><i class="bi bi-phone"></i> <?= htmlspecialchars($vendor['phone'],ENT_QUOTES) ?></div>
          <?php endif; ?>
          <?php if (!empty($vendor['email'])): ?>
            <div class="vendor-info-row"><i class="bi bi-envelope"></i> <?= htmlspecialchars($vendor['email'],ENT_QUOTES) ?></div>
          <?php endif; ?>
          <?php if (!empty($vendor['city'])): ?>
            <div class="vendor-info-row"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($vendor['city'],ENT_QUOTES) ?><?= !empty($vendor['region'])?', '.htmlspecialchars($vendor['region'],ENT_QUOTES):'' ?></div>
          <?php endif; ?>
          <?php if (!empty($vendor['approved_at'])): ?>
            <div class="vendor-info-row"><i class="bi bi-calendar3"></i> <?= e(t('storefront.vendor.member_since')) ?> <?= date('M Y', strtotime($vendor['approved_at'])) ?></div>
          <?php endif; ?>
        </div>

        <!-- Recent reviews -->
        <?php if (!empty($recent_reviews)): ?>
        <div class="vendor-sidebar-card">
          <h4><i class="bi bi-chat-square-text"></i> <?= e(t('storefront.vendor.recent_reviews')) ?></h4>
          <?php foreach ($recent_reviews as $rv): ?>
            <div class="mini-review">
              <div style="display:flex;align-items:center;justify-content:space-between;">
                <span class="mini-reviewer"><?= htmlspecialchars($rv['reviewer']??'',ENT_QUOTES) ?></span>
                <span style="color:#D4AF37;font-size:.8rem"><?= str_repeat('&#9733;',(int)($rv['rating']??0)) ?></span>
              </div>
              <div class="mini-review-body"><?= htmlspecialchars(mb_substr($rv['body']??'',0,80,'UTF-8'),ENT_QUOTES) ?><?= mb_strlen($rv['body']??'','UTF-8')>80?'...':'' ?></div>
              <div style="font-size:.7rem;color:var(--text3);margin-top:.2rem"><?= e(t('storefront.vendor.on_product')) ?> <?= htmlspecialchars($rv['product_name']??'',ENT_QUOTES) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </aside>
    </div>
  </div>
</div>
<?php
$__content = ob_get_clean();
echo \App\Core\View::capture('_layouts/public', [
    '__content' => $__content,
    'pageTitle'  => $shopName . ' -- ' . t('site.name'),
    'locale'     => $locale,
]);
