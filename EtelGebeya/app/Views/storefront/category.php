<?php
/**
 * Category listing page.
 * Variables: $category (Category), $products (Product[]), $locale, $page, $per
 */
declare(strict_types=1);
use App\Core\View;
require_once __DIR__ . '/_product_card.php';

$locale   = $locale ?? 'en';
$catName  = htmlspecialchars($category->name($locale), ENT_QUOTES);
$db       = $GLOBALS['__db'] ?? null;

// Sub-categories
$subCats = \App\Models\Category::childrenOf($category->id);

// Total count for pagination
$total = 0;
if ($db && $db->isConnected()) {
    $total = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM products WHERE status='published' AND category_id=?",
        [$category->id]
    );
}
$totalPages = max(1, (int)ceil($total / ($per ?? 20)));

ob_start();
?>
<style>
.cat-page { padding: 20px 0; }
.cat-breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text3); margin-bottom: 16px; }
.cat-breadcrumb a { color: var(--text2); transition: color .15s; }
.cat-breadcrumb a:hover { color: var(--g1); }
.cat-breadcrumb i { font-size: 10px; }
.cat-breadcrumb .current { color: var(--text); font-weight: 600; }

.cat-hero {
  background: linear-gradient(135deg,#0d0900 0%,#1c1208 40%,#2a1c0a 70%,#100c06 100%);
  border: 1px solid var(--border); border-radius: var(--r2);
  padding: 28px 32px; margin-bottom: 20px;
  display: flex; align-items: center; gap: 22px; position: relative; overflow: hidden;
}
.cat-hero::before {
  content: ''; position: absolute; inset: 0;
  background: radial-gradient(ellipse 60% 80% at 80% 50%, rgba(212,175,55,.08), transparent 70%);
  pointer-events: none;
}
.cat-hero-icon {
  width: 72px; height: 72px; border-radius: var(--r2); flex-shrink: 0;
  background: linear-gradient(135deg, rgba(212,175,55,.18), rgba(212,175,55,.06));
  border: 1px solid var(--border);
  display: flex; align-items: center; justify-content: center;
  font-size: 32px; color: var(--g2); position: relative;
}
.cat-hero h1 {
  font-size: clamp(20px,3vw,28px); font-weight: 900; line-height: 1.2; margin-bottom: 4px;
  background: var(--grad); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.cat-hero p { color: var(--text2); font-size: 13px; position: relative; }

.cat-page-layout { display: grid; grid-template-columns: 220px 1fr; gap: 20px; }
@media(max-width:768px){ .cat-page-layout { grid-template-columns:1fr; } }

.filter-sidebar { position: sticky; top: calc(var(--header-h) + 8px); }
.filter-card { background: var(--card); border: 1px solid var(--border); border-radius: var(--r2); padding: 16px; margin-bottom: 12px; }
.filter-title { font-weight: 700; color: var(--text); margin-bottom: 10px; font-size: 12.5px; text-transform: uppercase; letter-spacing: .8px; }
.filter-group { margin-bottom: 4px; }
.filter-group label { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--text2); cursor: pointer; padding: 6px 0; min-height: 24px; }
.filter-group label:hover { color: var(--g1); }
.sub-cat-link { display: flex; align-items: center; gap: 8px; padding: 8px 6px; border-radius: 8px; font-size: 12.5px; text-decoration: none; color: var(--text2); transition: all .15s; }
.sub-cat-link:hover, .sub-cat-link.active { background: rgba(212,175,55,.08); color: var(--g1); font-weight: 700; }

.sort-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
.sort-bar select { background: var(--card2,var(--card)); border: 1.5px solid var(--border); border-radius: 8px; padding: 8px 12px; font-size: 12.5px; color: var(--text); outline: none; }
.results-info { font-size: 12.5px; color: var(--text2); }
.results-info strong { color: var(--text); }

.no-products { text-align: center; padding: 60px 20px; color: var(--text3); }
.no-products .icon { font-size: 48px; margin-bottom: 14px; color: var(--g2); opacity: .5; }
.no-products h3 { color: var(--text); font-weight: 700; }

.pag-row { display: flex; justify-content: center; gap: 8px; margin-top: 24px; flex-wrap: wrap; }
.pag-a {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 40px; height: 40px; padding: 0 6px; border-radius: 10px;
  border: 1.5px solid var(--border); background: var(--card); color: var(--text2);
  text-decoration: none; font-size: 13px; font-weight: 600; transition: all .15s;
}
.pag-a:hover, .pag-a.active { background: var(--grad); color: #080604; border-color: transparent; }
</style>

<div class="cat-page">
  <!-- Breadcrumb -->
  <div class="cat-breadcrumb">
    <a href="<?= View::url('/') ?>">Home</a>
    <i class="bi bi-chevron-right"></i>
    <span class="current"><?= $catName ?></span>
  </div>

  <!-- Hero -->
  <div class="cat-hero">
    <div class="cat-hero-icon">
      <i class="bi <?= e($category->icon ?: 'bi-box-seam') ?>"></i>
    </div>
    <div>
      <h1><?= $catName ?></h1>
      <p><?= number_format($total) ?> products available</p>
    </div>
  </div>

  <div class="cat-page-layout">
    <!-- Sidebar filters -->
    <aside class="filter-sidebar">
      <?php if (!empty($subCats)): ?>
      <div class="filter-card">
        <div class="filter-title">Sub-categories</div>
        <?php foreach ($subCats as $sc): ?>
          <a href="<?= View::url('/category/' . $sc->slug) ?>" class="sub-cat-link">
            <i class="bi bi-chevron-right" style="font-size:.7rem"></i> <?= htmlspecialchars($sc->name($locale), ENT_QUOTES) ?>
          </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="filter-card">
        <div class="filter-title">Price Range</div>
        <div class="filter-group">
          <label><input type="radio" name="price" value="all" checked> Any price</label>
          <label><input type="radio" name="price" value="0-500"> Under Br 500</label>
          <label><input type="radio" name="price" value="500-2000"> Br 500 - 2,000</label>
          <label><input type="radio" name="price" value="2000-10000"> Br 2,000 - 10,000</label>
          <label><input type="radio" name="price" value="10000+"> Br 10,000+</label>
        </div>
      </div>

      <div class="filter-card">
        <div class="filter-title">Rating</div>
        <div class="filter-group">
          <label><input type="radio" name="rating" value="any" checked> Any rating</label>
          <label><input type="radio" name="rating" value="4"> &#9733;&#9733;&#9733;&#9733; &amp; above</label>
          <label><input type="radio" name="rating" value="3"> &#9733;&#9733;&#9733; &amp; above</label>
        </div>
      </div>
    </aside>

    <!-- Main content -->
    <div>
      <!-- Sort bar -->
      <div class="sort-bar">
        <div class="results-info">
          Showing <strong><?= count($products) ?></strong> of <strong><?= number_format($total) ?></strong> products in <strong><?= $catName ?></strong>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
          <span style="font-size:12px;color:var(--text3)">Sort:</span>
          <select onchange="applySort(this.value)">
            <option value="newest">Newest First</option>
            <option value="price_asc">Price: Low to High</option>
            <option value="price_desc">Price: High to Low</option>
            <option value="popular">Most Popular</option>
            <option value="rating">Top Rated</option>
          </select>
        </div>
      </div>

      <?php if (empty($products)): ?>
        <div class="no-products">
          <div class="icon"><i class="bi bi-box-seam"></i></div>
          <h3><?= t('category.no_products') ?></h3>
          <p style="margin-top:.5rem">Be the first to sell in this category!</p>
          <a href="<?= View::url('/sell') ?>" class="eg-btn eg-btn-gold" style="display:inline-flex;margin-top:1rem;text-decoration:none">
            <i class="bi bi-shop"></i> Open a Shop
          </a>
        </div>
      <?php else: ?>
        <div class="eg-product-grid">
          <?php foreach ($products as $p): ?>
            <?= product_card($p, $locale) ?>
          <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pag-row">
          <?php if ($page > 1): ?>
            <a href="?page=<?= $page-1 ?>" class="pag-a"><i class="bi bi-chevron-left"></i></a>
          <?php endif; ?>
          <?php
            $from = max(1, $page-2); $to = min($totalPages, $page+2);
            for ($p2=$from; $p2<=$to; $p2++):
          ?>
            <a href="?page=<?= $p2 ?>" class="pag-a <?= $p2===$page?'active':'' ?>"><?= $p2 ?></a>
          <?php endfor; ?>
          <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page+1 ?>" class="pag-a"><i class="bi bi-chevron-right"></i></a>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
function applySort(val) {
  const url = new URL(location.href);
  url.searchParams.set('sort', val);
  url.searchParams.delete('page');
  location.href = url.toString();
}
document.querySelectorAll('input[name=price], input[name=rating]').forEach(inp => {
  inp.addEventListener('change', () => {
    const url = new URL(location.href);
    url.searchParams.set('price', document.querySelector('input[name=price]:checked').value);
    url.searchParams.set('rating', document.querySelector('input[name=rating]:checked').value);
    url.searchParams.delete('page');
    location.href = url.toString();
  });
});
</script>
<?php
$__content = ob_get_clean();
echo \App\Core\View::capture('_layouts/public', [
    '__content' => $__content,
    'pageTitle'  => $category->name($locale ?? 'en') . ' -- ' . t('site.name'),
    'locale'     => $locale ?? 'en',
]);
