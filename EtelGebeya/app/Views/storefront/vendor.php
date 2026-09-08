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
.vendor-page { padding: 0 0 3rem; }
.vendor-banner {
  height: 200px; background: linear-gradient(135deg, var(--eg-blue-deep) 0%, var(--eg-blue) 50%, #0ea5e9 100%);
  border-radius: 0 0 var(--eg-radius) var(--eg-radius); position: relative; overflow: hidden;
}
.vendor-banner::before {
  content:''; position: absolute; inset: 0;
  background: url("https://picsum.photos/seed/vendor<?= $vendor['id'] ?? 1 ?>/1200/200") center/cover;
  opacity: .3;
}
.vendor-profile-row {
  display: flex; align-items: flex-end; gap: 1.5rem;
  padding: 0 2rem; transform: translateY(-40px); margin-bottom: -20px;
}
.vendor-avatar {
  width: 90px; height: 90px; border-radius: 20px;
  border: 3px solid var(--eg-surface); box-shadow: var(--eg-shadow);
  background: linear-gradient(135deg, var(--eg-gold-dim), var(--eg-gold-glow));
  display: flex; align-items: center; justify-content: center;
  font-size: 2.5rem; flex-shrink: 0;
}
.vendor-head { flex: 1; padding-bottom: .5rem; }
.vendor-head h1 { font-size: 1.6rem; font-weight: 900; line-height: 1.2; }
.vendor-head p { font-size: .85rem; color: var(--eg-text-dim); margin-top: .3rem; }
.vendor-badges { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .5rem; }
.vendor-badge {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .25rem .7rem; border-radius: 20px; font-size: .72rem; font-weight: 700;
}
.badge-verified { background: rgba(5,150,105,.12); color: #059669; border: 1px solid rgba(5,150,105,.3); }
.badge-rating   { background: var(--eg-gold-dim); color: var(--eg-gold-deep); border: 1px solid var(--eg-gold-glow); }
.vendor-stats-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 1rem; margin: 1.25rem 0; }
@media(max-width:640px){ .vendor-stats-row { grid-template-columns: repeat(2,1fr); } }
.vendor-stat { background: var(--eg-glass); border: 1.5px solid var(--eg-border); border-radius: 14px; padding: 1rem; text-align: center; }
.vendor-stat-num { font-size: 1.6rem; font-weight: 900; color: var(--eg-blue-deep); }
.vendor-stat-lbl { font-size: .75rem; color: var(--eg-text-muted); margin-top: .2rem; }
.vendor-layout { display: grid; grid-template-columns: 1fr 280px; gap: 2rem; }
@media(max-width:900px){ .vendor-layout { grid-template-columns: 1fr; } }
.vendor-sidebar-card { background: var(--eg-glass); border: 1.5px solid var(--eg-border); border-radius: var(--eg-radius); padding: 1.3rem; margin-bottom: 1rem; }
.vendor-sidebar-card h4 { font-size: .9rem; font-weight: 800; color: var(--eg-blue-deep); margin-bottom: .75rem; }
.vendor-info-row { display: flex; align-items: center; gap: .5rem; font-size: .83rem; color: var(--eg-text-dim); padding: .3rem 0; }
.vendor-info-row i { color: var(--eg-gold-deep); font-size: 1rem; }
.mini-review { padding: .75rem 0; border-bottom: 1px solid var(--eg-border); }
.mini-review:last-child { border-bottom: none; }
.mini-reviewer { font-size: .8rem; font-weight: 700; }
.mini-review-body { font-size: .78rem; color: var(--eg-text-dim); margin-top: .3rem; line-height: 1.5; }
</style>

<div class="vendor-page">
  <!-- Banner -->
  <div class="vendor-banner"></div>

  <div class="container">
    <!-- Profile row -->
    <div class="vendor-profile-row">
      <div class="vendor-avatar">/* U+1F3EA */</div>
      <div class="vendor-head">
        <h1><?= $shopName ?></h1>
        <?php if ($tagline): ?><p><?= $tagline ?></p><?php endif; ?>
        <div class="vendor-badges">
          <span class="vendor-badge badge-verified">? Verified Seller</span>
          <span class="vendor-badge badge-rating">? <?= $stats['rating'] ?> / 5.0</span>
          <?php if (!empty($vendor['business_type'])): ?>
            <span class="vendor-badge" style="background:var(--eg-blue-dim);color:var(--eg-blue);border:1px solid var(--eg-blue-light)">
              /* U+1F3E2 */ <?= htmlspecialchars(ucfirst($vendor['business_type']), ENT_QUOTES) ?>
            </span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Stats -->
    <div class="vendor-stats-row">
      <div class="vendor-stat"><div class="vendor-stat-num"><?= number_format($stats['products']) ?></div><div class="vendor-stat-lbl">Products</div></div>
      <div class="vendor-stat"><div class="vendor-stat-num"><?= number_format($stats['sold']) ?></div><div class="vendor-stat-lbl">Items Sold</div></div>
      <div class="vendor-stat"><div class="vendor-stat-num"><?= number_format($stats['reviews']) ?></div><div class="vendor-stat-lbl">Reviews</div></div>
      <div class="vendor-stat"><div class="vendor-stat-num"><?= $stats['rating'] ?>?</div><div class="vendor-stat-lbl">Rating</div></div>
    </div>

    <!-- Content layout -->
    <div class="vendor-layout">
      <!-- Products -->
      <div>
        <div class="section-header" style="margin-bottom:1.25rem;">
          <div><h2 class="section-title">Products by <?= $shopName ?></h2><p class="section-sub"><?= number_format($stats['products']) ?> items available</p></div>
        </div>
        <?php if (empty($products)): ?>
          <div style="text-align:center;padding:3rem;color:var(--eg-text-muted)">
            <div style="font-size:3rem;margin-bottom:.75rem">/* U+1F4E6 */</div>
            <p>This vendor has no published products yet.</p>
          </div>
        <?php else: ?>
          <div class="product-grid">
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
          <h4>?? About This Shop</h4>
          <?php
            $about = $locale==='am' ? ($vendor['description_am']??$vendor['description_en']??'') : ($vendor['description_en']??'');
            $about = strip_tags($about ?? '');
          ?>
          <?php if ($about): ?>
            <p style="font-size:.83rem;color:var(--eg-text-dim);line-height:1.7"><?= htmlspecialchars(mb_substr($about,0,200,'UTF-8'),ENT_QUOTES) ?><?= mb_strlen($about,'UTF-8')>200?'...':'' ?></p>
          <?php else: ?>
            <p style="font-size:.82rem;color:var(--eg-text-muted)">No description provided.</p>
          <?php endif; ?>
        </div>

        <!-- Contact -->
        <div class="vendor-sidebar-card">
          <h4>/* U+1F4DE */ Contact & Location</h4>
          <?php if (!empty($vendor['phone'])): ?>
            <div class="vendor-info-row">/* U+1F4F1 */ <?= htmlspecialchars($vendor['phone'],ENT_QUOTES) ?></div>
          <?php endif; ?>
          <?php if (!empty($vendor['email'])): ?>
            <div class="vendor-info-row">/* U+1F4E7 */ <?= htmlspecialchars($vendor['email'],ENT_QUOTES) ?></div>
          <?php endif; ?>
          <?php if (!empty($vendor['city'])): ?>
            <div class="vendor-info-row">/* U+1F4CD */ <?= htmlspecialchars($vendor['city'],ENT_QUOTES) ?><?= !empty($vendor['region'])?', '.htmlspecialchars($vendor['region'],ENT_QUOTES):'' ?></div>
          <?php endif; ?>
          <?php if (!empty($vendor['approved_at'])): ?>
            <div class="vendor-info-row">/* U+1F4C5 */ Member since <?= date('M Y', strtotime($vendor['approved_at'])) ?></div>
          <?php endif; ?>
        </div>

        <!-- Recent reviews -->
        <?php if (!empty($recent_reviews)): ?>
        <div class="vendor-sidebar-card">
          <h4>? Recent Reviews</h4>
          <?php foreach ($recent_reviews as $rv): ?>
            <div class="mini-review">
              <div style="display:flex;align-items:center;justify-content:space-between;">
                <span class="mini-reviewer"><?= htmlspecialchars($rv['reviewer']??'',ENT_QUOTES) ?></span>
                <span style="color:#D4AF37;font-size:.8rem"><?= str_repeat('?',(int)($rv['rating']??0)) ?></span>
              </div>
              <div class="mini-review-body"><?= htmlspecialchars(mb_substr($rv['body']??'',0,80,'UTF-8'),ENT_QUOTES) ?><?= mb_strlen($rv['body']??'','UTF-8')>80?'...':'' ?></div>
              <div style="font-size:.7rem;color:var(--eg-text-muted);margin-top:.2rem">on <?= htmlspecialchars($rv['product_name']??'',ENT_QUOTES) ?></div>
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
