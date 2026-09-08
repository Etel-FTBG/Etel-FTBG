<?php
/**
 * Search results page.
 * Variables: $q (string), $products (Product[]), $locale, $page, $per, $total
 */
declare(strict_types=1);
use App\Core\View;
require_once __DIR__ . '/_product_card.php';

$locale = $locale ?? 'en';
$q      = trim($q ?? '');
$db     = $GLOBALS['__db'] ?? null;

// All categories for filter sidebar
$allCats = \App\Models\Category::topLevel();

// Extended search: count total if not set
if (!isset($total) && $db && $db->isConnected() && $q) {
    $like  = '%' . $q . '%';
    $total = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM products WHERE status='published' AND (name_en LIKE ? OR name_am LIKE ? OR description_en LIKE ?)",
        [$like, $like, $like]
    );
}
$total = (int)($total ?? count($products ?? []));
$totalPages = $total > 0 ? max(1, (int)ceil($total / ($per ?? 20))) : 1;

ob_start();
?>
<style>
.search-page { padding: 2rem 0; }
.search-hero {
  background: linear-gradient(135deg, var(--eg-gold-dim), rgba(37,99,235,.05));
  border: 1.5px solid var(--eg-gold-glow); border-radius: var(--eg-radius);
  padding: 2rem; margin-bottom: 2rem;
}
.search-hero-form { display: flex; gap: .75rem; max-width: 640px; margin: .75rem auto 0; }
.search-hero-form input {
  flex: 1; padding: .85rem 1.2rem; border: 1.5px solid var(--eg-border); border-radius: 14px;
  font-size: 1rem; background: var(--eg-surface); color: var(--eg-text); outline: none;
}
.search-hero-form input:focus { border-color: var(--eg-gold); }
.search-hero-form button {
  padding: .85rem 1.5rem; background: linear-gradient(135deg,var(--eg-blue-deep),var(--eg-blue));
  color: #fff; border: none; border-radius: 14px; font-weight: 800; cursor: pointer; font-size:.95rem;
}
.search-layout { display: grid; grid-template-columns: 220px 1fr; gap: 2rem; }
@media(max-width:768px){ .search-layout { grid-template-columns: 1fr; } }
.search-sidebar { position: sticky; top: 1rem; }
.filter-card { background: var(--eg-glass); border: 1.5px solid var(--eg-border); border-radius: 18px; padding: 1.2rem; margin-bottom: 1rem; }
.filter-title { font-weight: 800; color: var(--eg-blue-deep); margin-bottom: .75rem; font-size: .9rem; }
.cat-filter-link { display: flex; align-items: center; gap: .5rem; padding: .3rem .5rem; border-radius: 8px; font-size: .82rem; text-decoration: none; color: var(--eg-text-dim); transition: all .2s; }
.cat-filter-link:hover { background: var(--eg-blue-dim); color: var(--eg-blue); }
.sort-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem; flex-wrap: wrap; gap: .5rem; }
.sort-bar select { background: var(--eg-glass); border: 1.5px solid var(--eg-border); border-radius: 10px; padding: .45rem .9rem; font-size: .85rem; color: var(--eg-text); outline: none; }
.results-count { font-size: .85rem; color: var(--eg-text-dim); }
.highlight { background: var(--eg-gold-dim); color: var(--eg-gold-deep); padding: 0 3px; border-radius: 3px; }
.no-results { text-align: center; padding: 4rem 2rem; }
.pag-row { display: flex; justify-content: center; gap: .5rem; margin-top: 2rem; flex-wrap: wrap; }
.pag-a { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 10px; border: 1.5px solid var(--eg-border); background: var(--eg-glass); color: var(--eg-text-dim); text-decoration: none; font-size: .88rem; transition: all .2s; }
.pag-a:hover, .pag-a.active { background: var(--eg-blue); color: #fff; border-color: var(--eg-blue); }
</style>

<div class="container search-page">
  <!-- Search Hero -->
  <div class="search-hero">
    <h2 style="text-align:center;font-size:1.4rem;font-weight:800;color:var(--eg-blue-deep)">
      <?= $q ? 'Results for <em style="color:var(--eg-gold-deep)">'.e($q).'</em>' : 'Search Etel Gebeya' ?>
    </h2>
    <div class="search-hero-form">
      <form action="<?= View::url('/search') ?>" method="GET" style="display:flex;gap:.75rem;width:100%">
        <input type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>" placeholder="<?= t('site.search.placeholder') ?>" autofocus>
        <button type="submit">/* U+1F50D */ <?= $locale === 'am' ? '???' : 'Search' ?></button>
      </form>
    </div>
  </div>

  <div class="search-layout">
    <!-- Filters -->
    <aside class="search-sidebar">
      <div class="filter-card">
        <div class="filter-title">/* U+1F4C2 */ Categories</div>
        <a href="<?= View::url('/search?q='.urlencode($q)) ?>" class="cat-filter-link" style="font-weight:700;color:var(--eg-blue)">All categories</a>
        <?php foreach ($allCats as $cat): ?>
          <a href="<?= View::url('/category/'.$cat->slug) ?>" class="cat-filter-link">
            <?= e(html_entity_decode($cat->name($locale), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="filter-card">
        <div class="filter-title">/* U+1F4B0 */ Price Range</div>
        <?php foreach ([['All',''],['Under Br 500','0-500'],['Br 500-2,000','500-2000'],['Br 2,000-10,000','2000-10000'],['Br 10,000+','10000+']] as [$label,$val]): ?>
          <label style="display:flex;align-items:center;gap:.5rem;font-size:.82rem;color:var(--eg-text-dim);padding:.2rem 0;cursor:pointer">
            <input type="radio" name="price" value="<?= $val ?>" <?= (($_GET['price']??'')===$val||(empty($_GET['price'])&&$val==='')?'checked':'') ?>>
            <?= $label ?>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="filter-card">
        <div class="filter-title">? Rating</div>
        <?php foreach ([['Any rating',''],['4? & up','4'],['3? & up','3']] as [$label,$val]): ?>
          <label style="display:flex;align-items:center;gap:.5rem;font-size:.82rem;color:var(--eg-text-dim);padding:.2rem 0;cursor:pointer">
            <input type="radio" name="rating" value="<?= $val ?>" <?= (($_GET['rating']??'')===$val?'checked':'') ?>>
            <?= $label ?>
          </label>
        <?php endforeach; ?>
      </div>
    </aside>

    <!-- Results -->
    <div>
      <div class="sort-bar">
        <div class="results-count">
          <?php if ($q): ?>
            <strong><?= number_format($total) ?></strong> results for <span class="highlight"><?= htmlspecialchars($q, ENT_QUOTES) ?></span>
          <?php else: ?>
            Browse all <strong><?= number_format($total) ?></strong> products
          <?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:.5rem;">
          <span style="font-size:.8rem;color:var(--eg-text-dim)">Sort:</span>
          <select onchange="applySort(this.value)">
            <option value="relevant" <?= ($_GET['sort']??'')==='relevant'?'selected':'' ?>>Most Relevant</option>
            <option value="newest" <?= ($_GET['sort']??'')==='newest'?'selected':'' ?>>Newest</option>
            <option value="price_asc" <?= ($_GET['sort']??'')==='price_asc'?'selected':'' ?>>Price: Low to High</option>
            <option value="price_desc" <?= ($_GET['sort']??'')==='price_desc'?'selected':'' ?>>Price: High to Low</option>
            <option value="rating" <?= ($_GET['sort']??'')==='rating'?'selected':'' ?>>Top Rated</option>
            <option value="popular" <?= ($_GET['sort']??'')==='popular'?'selected':'' ?>>Most Sold</option>
          </select>
        </div>
      </div>

      <?php if (empty($products)): ?>
        <div class="no-results">
          <div style="font-size:4rem;margin-bottom:1rem">/* U+1F50D */</div>
          <h3 style="color:var(--eg-text-dim)"><?= htmlspecialchars(t('search.no_results'), ENT_QUOTES) ?></h3>
          <p style="color:var(--eg-text-muted);margin-top:.5rem;margin-bottom:1.5rem">Try different keywords or browse by category</p>
          <div style="display:flex;gap:1rem;justify-content:center;flex-wrap:wrap">
            <?php foreach (array_slice($allCats,0,4) as $cat): ?>
              <a href="<?= View::url('/category/'.$cat->slug) ?>" style="padding:.5rem 1.1rem;border-radius:20px;border:1.5px solid var(--eg-border);color:var(--eg-blue);text-decoration:none;font-size:.85rem;background:var(--eg-glass)">
                <?= e(html_entity_decode($cat->name($locale), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?>
              </a>
            <?php endforeach; ?>
          </div>
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
            <a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>" class="pag-a">?</a>
          <?php endif; ?>
          <?php
            $from = max(1,$page-2); $to = min($totalPages,$page+2);
            for ($p2=$from;$p2<=$to;$p2++):
          ?>
            <a href="?<?= http_build_query(array_merge($_GET,['page'=>$p2])) ?>" class="pag-a <?= $p2===$page?'active':'' ?>"><?= $p2 ?></a>
          <?php endfor; ?>
          <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>" class="pag-a">?</a>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(function(){
  const input   = document.querySelector('.search-hero-form input[name=q]');
  const grid    = document.querySelector('.search-layout > div');   // results column
  const sidebar = document.querySelector('.search-sidebar');
  let timer = null;
  let lastQ = input ? input.value.trim() : '';

  function buildUrl(q) {
    const u = new URL(location.href);
    if (q) u.searchParams.set('q', q); else u.searchParams.delete('q');
    u.searchParams.delete('page');
    return u.toString();
  }

  function setLoading(on) {
    if (grid) grid.style.opacity = on ? '.4' : '1';
  }

  async function liveSearch(q) {
    setLoading(true);
    try {
      const res  = await fetch(buildUrl(q), {headers:{'X-Requested-With':'XMLHttpRequest'}});
      const html = await res.text();
      const doc  = new DOMParser().parseFromString(html, 'text/html');

      // Swap results column
      const newGrid = doc.querySelector('.search-layout > div');
      if (newGrid && grid) grid.innerHTML = newGrid.innerHTML;

      // Swap sidebar category list
      const newSide = doc.querySelector('.search-sidebar');
      if (newSide && sidebar) sidebar.innerHTML = newSide.innerHTML;

      // Update page title & URL without reload
      history.replaceState(null, '', buildUrl(q));
    } catch(e) { /* network error -- leave existing results */ }
    setLoading(false);
  }

  if (input) {
    // Suppress form submit -- let live search handle it
    input.closest('form')?.addEventListener('submit', e => {
      e.preventDefault();
      clearTimeout(timer);
      liveSearch(input.value.trim());
    });

    input.addEventListener('input', () => {
      const q = input.value.trim();
      if (q === lastQ) return;
      lastQ = q;
      clearTimeout(timer);
      // Immediate trigger for clear, debounce 380ms for typing
      timer = setTimeout(() => liveSearch(q), q.length === 0 ? 0 : 380);
    });
  }

  function applySort(val) {
    const url = new URL(location.href);
    url.searchParams.set('sort', val);
    url.searchParams.delete('page');
    location.href = url.toString();
  }
  window.applySort = applySort;

  document.querySelectorAll('input[name=price],input[name=rating]').forEach(inp => {
    inp.addEventListener('change', () => {
      const url = new URL(location.href);
      ['price','rating'].forEach(n => {
        const v = document.querySelector(`input[name=${n}]:checked`)?.value;
        if (v) url.searchParams.set(n, v); else url.searchParams.delete(n);
      });
      url.searchParams.delete('page');
      location.href = url.toString();
    });
  });
})();
</script>
<?php
$__content = ob_get_clean();
echo \App\Core\View::capture('_layouts/public', [
    '__content' => $__content,
    'pageTitle'  => $q ? "Search: $q" : 'Browse Products',
    'locale'     => $locale,
]);
