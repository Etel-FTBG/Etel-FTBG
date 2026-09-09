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
.search-page { padding: 20px 0; }
.search-hero {
  background: linear-gradient(135deg,#0d0900 0%,#1c1208 40%,#2a1c0a 70%,#100c06 100%);
  border: 1px solid var(--border); border-radius: var(--r2);
  padding: 28px 24px; margin-bottom: 20px;
}
.search-hero-form { display: flex; gap: 10px; max-width: 640px; margin: 14px auto 0; }
.search-hero-form input {
  flex: 1; padding: 13px 18px; border: 1.5px solid var(--border); border-radius: var(--r);
  font-size: 14px; background: var(--card); color: var(--text); outline: none;
}
.search-hero-form input:focus { border-color: var(--g2); }
.search-hero-form button {
  padding: 13px 22px; background: var(--grad);
  color: #080604; border: none; border-radius: var(--r); font-weight: 800; cursor: pointer; font-size: 13.5px;
  display: inline-flex; align-items: center; gap: 6px;
}
.search-layout { display: grid; grid-template-columns: 220px 1fr; gap: 20px; }
@media(max-width:768px){ .search-layout { grid-template-columns: 1fr; } }
.search-sidebar { position: sticky; top: calc(var(--header-h) + 8px); }
.filter-card {
  position: relative; overflow: hidden;
  background: var(--card); border: 1px solid var(--border); border-radius: var(--r2);
  padding: 16px; margin-bottom: 12px;
}
.filter-card::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
  background: linear-gradient(90deg, transparent, var(--g2), transparent);
}
.filter-title {
  display: flex; align-items: center; gap: 7px;
  font-weight: 700; color: var(--text); margin-bottom: 10px; font-size: 12.5px;
  text-transform: uppercase; letter-spacing: .8px;
}
.filter-title i { color: var(--g2); font-size: 13px; }
.cat-filter-link {
  display: flex; align-items: center; gap: 9px; padding: 8px 9px; border-radius: 9px;
  font-size: 12.5px; text-decoration: none; color: var(--text2);
  transition: background .18s, color .18s, transform .18s;
}
.cat-filter-link i { font-size: 13px; width: 16px; text-align: center; color: var(--g3); flex-shrink: 0; transition: color .18s; }
.cat-filter-link:hover { background: rgba(212,175,55,.08); color: var(--g1); transform: translateX(2px); }
.cat-filter-link:hover i { color: var(--g1); }
.cat-filter-link:focus-visible { outline: 2px solid var(--g2); outline-offset: -2px; }
.cat-filter-link.active { background: rgba(212,175,55,.1); color: var(--g1); font-weight: 700; }
.cat-filter-link.active i { color: var(--g1); }
.filter-radio {
  display: flex; align-items: center; gap: 9px; font-size: 12.5px; color: var(--text2);
  padding: 6px 9px; border-radius: 9px; cursor: pointer; transition: background .18s, color .18s;
}
.filter-radio:hover { background: rgba(212,175,55,.06); color: var(--text); }
.filter-radio input[type="radio"] { accent-color: var(--g2); width: 15px; height: 15px; flex-shrink: 0; }
.sort-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
.sort-bar select { background: var(--card); border: 1.5px solid var(--border); border-radius: 8px; padding: 8px 12px; font-size: 12.5px; color: var(--text); outline: none; }
.results-count { font-size: 12.5px; color: var(--text2); }
.results-count strong { color: var(--text); }
.highlight { background: rgba(212,175,55,.15); color: var(--g1); padding: 0 3px; border-radius: 3px; }
.no-results { text-align: center; padding: 60px 20px; color: var(--text3); }
.pag-row { display: flex; justify-content: center; gap: 8px; margin-top: 24px; flex-wrap: wrap; }
.pag-a { display: inline-flex; align-items: center; justify-content: center; min-width: 40px; height: 40px; padding: 0 6px; border-radius: 10px; border: 1.5px solid var(--border); background: var(--card); color: var(--text2); text-decoration: none; font-size: 13px; font-weight: 600; transition: all .15s; }
.pag-a:hover, .pag-a.active { background: var(--grad); color: #080604; border-color: transparent; }
</style>

<div class="search-page">
  <!-- Search Hero -->
  <div class="search-hero">
    <h2 style="text-align:center;font-size:1.4rem;font-weight:800;color:var(--text)">
      <?= $q ? 'Results for <em style="color:var(--g1)">'.e($q).'</em>' : 'Search Etel Gebeya' ?>
    </h2>
    <div class="search-hero-form">
      <form action="<?= View::url('/search') ?>" method="GET" style="display:flex;gap:.75rem;width:100%">
        <input type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>" placeholder="<?= t('site.search.placeholder') ?>" autofocus>
        <button type="submit"><i class="bi bi-search"></i> <?= $locale === 'am' ? '&#4936;&#4621;&#4877;' : 'Search' ?></button>
      </form>
    </div>
  </div>

  <div class="search-layout">
    <!-- Filters -->
    <aside class="search-sidebar">
      <div class="filter-card">
        <div class="filter-title"><i class="bi bi-folder2"></i> Categories</div>
        <a href="<?= View::url('/search?q='.urlencode($q)) ?>" class="cat-filter-link active"><i class="bi bi-grid-3x3-gap"></i> All categories</a>
        <?php foreach ($allCats as $cat): ?>
          <a href="<?= View::url('/category/'.$cat->slug) ?>" class="cat-filter-link">
            <i class="bi <?= eg_cat_icon($cat->nameEn) ?>"></i>
            <?= e(html_entity_decode($cat->name($locale), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="filter-card">
        <div class="filter-title"><i class="bi bi-cash-stack"></i> Price Range</div>
        <?php foreach ([['All',''],['Under Br 500','0-500'],['Br 500-2,000','500-2000'],['Br 2,000-10,000','2000-10000'],['Br 10,000+','10000+']] as [$label,$val]): ?>
          <label class="filter-radio">
            <input type="radio" name="price" value="<?= $val ?>" <?= (($_GET['price']??'')===$val||(empty($_GET['price'])&&$val==='')?'checked':'') ?>>
            <?= $label ?>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="filter-card">
        <div class="filter-title"><i class="bi bi-star-fill"></i> Rating</div>
        <?php foreach ([['Any rating',''],['4&#9733; & up','4'],['3&#9733; & up','3']] as [$label,$val]): ?>
          <label class="filter-radio">
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
          <span style="font-size:.8rem;color:var(--text2)">Sort:</span>
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
          <div style="font-size:4rem;margin-bottom:1rem;color:var(--g2);opacity:.5"><i class="bi bi-search"></i></div>
          <h3 style="color:var(--text)"><?= htmlspecialchars(t('search.no_results'), ENT_QUOTES) ?></h3>
          <p style="margin-top:.5rem;margin-bottom:1.5rem">Try different keywords or browse by category</p>
          <div style="display:flex;gap:1rem;justify-content:center;flex-wrap:wrap">
            <?php foreach (array_slice($allCats,0,4) as $cat): ?>
              <a href="<?= View::url('/category/'.$cat->slug) ?>" style="padding:.5rem 1.1rem;border-radius:20px;border:1.5px solid var(--border);color:var(--g1);text-decoration:none;font-size:.85rem;background:var(--card)">
                <?= e(html_entity_decode($cat->name($locale), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?>
              </a>
            <?php endforeach; ?>
          </div>
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
            <a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>" class="pag-a"><i class="bi bi-chevron-left"></i></a>
          <?php endif; ?>
          <?php
            $from = max(1,$page-2); $to = min($totalPages,$page+2);
            for ($p2=$from;$p2<=$to;$p2++):
          ?>
            <a href="?<?= http_build_query(array_merge($_GET,['page'=>$p2])) ?>" class="pag-a <?= $p2===$page?'active':'' ?>"><?= $p2 ?></a>
          <?php endfor; ?>
          <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>" class="pag-a"><i class="bi bi-chevron-right"></i></a>
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
