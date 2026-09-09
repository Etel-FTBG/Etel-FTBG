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
.cat-page { padding: 2rem 0; }
.cat-hero {
  background: linear-gradient(135deg, var(--eg-blue-deep), var(--eg-blue));
  border-radius: var(--eg-radius); padding: 2.5rem 2rem; margin-bottom: 2rem; color: #fff;
  display: flex; align-items: center; gap: 1.5rem;
}
.cat-hero-icon { font-size: 3.5rem; filter: drop-shadow(0 4px 12px rgba(0,0,0,.2)); }
.cat-hero h1 { font-size: clamp(1.4rem,3vw,2.2rem); font-weight: 900; margin-bottom: .3rem; }
.cat-hero p  { opacity: .85; font-size: .9rem; }
.cat-page-layout { display: grid; grid-template-columns: 220px 1fr; gap: 2rem; }
@media(max-width:768px){ .cat-page-layout { grid-template-columns:1fr; } }
.filter-sidebar { position: sticky; top: 1rem; }
.filter-card { background: var(--eg-glass); border: 1.5px solid var(--eg-border); border-radius: var(--eg-radius); padding: 1.2rem; margin-bottom: 1rem; }
.filter-title { font-weight: 800; color: var(--eg-blue-deep); margin-bottom: .75rem; font-size: .95rem; }
.filter-group { margin-bottom: .5rem; }
.filter-group label { display: flex; align-items: center; gap: .5rem; font-size: .83rem; color: var(--eg-text-dim); cursor: pointer; padding: .25rem 0; }
.filter-group label:hover { color: var(--eg-blue); }
.sub-cat-link { display: flex; align-items: center; gap: .5rem; padding: .4rem .6rem; border-radius: 8px; font-size: .83rem; text-decoration: none; color: var(--eg-text-dim); transition: all .2s; }
.sub-cat-link:hover, .sub-cat-link.active { background: var(--eg-blue-dim); color: var(--eg-blue); font-weight: 700; }
.sort-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem; flex-wrap: wrap; gap: .75rem; }
.sort-bar select { background: var(--eg-glass); border: 1.5px solid var(--eg-border); border-radius: 10px; padding: .45rem .9rem; font-size: .85rem; color: var(--eg-text); outline: none; }
.results-info { font-size: .82rem; color: var(--eg-text-dim); }
.no-products { text-align: center; padding: 4rem 2rem; }
.no-products .icon { font-size: 4rem; margin-bottom: 1rem; }
.pag-row { display: flex; justify-content: center; gap: .5rem; margin-top: 2rem; flex-wrap: wrap; }
.pag-a {
  display: inline-flex; align-items: center; justify-content: center;
  width: 36px; height: 36px; border-radius: 10px; border: 1.5px solid var(--eg-border);
  background: var(--eg-glass); color: var(--eg-text-dim); text-decoration: none; font-size: .88rem;
  transition: all .2s;
}
.pag-a:hover, .pag-a.active { background: var(--eg-blue); color: #fff; border-color: var(--eg-blue); }
</style>

<div class="container cat-page">
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
        <div style="display:flex;align-items:center;gap:.5rem;">
          <span style="font-size:.8rem;color:var(--eg-text-dim)">Sort:</span>
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
          <h3 style="color:var(--eg-text-dim)"><?= t('category.no_products') ?></h3>
          <p style="margin-top:.5rem;color:var(--eg-text-muted)">Be the first to sell in this category!</p>
          <a href="<?= View::url('/sell') ?>" class="btn btn-primary" style="display:inline-block;margin-top:1rem;text-decoration:none;padding:.7rem 1.5rem;border-radius:12px;background:var(--eg-blue);color:#fff;font-weight:700">Open a Shop</a>
        </div>
      <?php else: ?>
        <div class="product-grid">
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
