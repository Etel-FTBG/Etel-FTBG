<?php
use App\Core\View;
require __DIR__.'/_layout.php';
ob_start();
?>
<style>
.cg-toolbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;gap:1rem;flex-wrap:wrap}
.cg-toolbar h1{font-size:1.4rem;font-weight:900;color:#fff;margin:0}
.cg-toolbar p{font-size:.82rem;color:var(--dark-muted);margin:.2rem 0 0}
.cg-search{display:flex;align-items:center;gap:.5rem;background:var(--dark-card);border:1.5px solid var(--dark-border);border-radius:12px;padding:.5rem .9rem;min-width:240px}
.cg-search i{color:var(--dark-muted);font-size:.95rem;flex-shrink:0}
.cg-search input{border:none;background:transparent;color:#f0f0f0;font-size:.88rem;font-family:inherit;outline:none;width:100%}

.cg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:1rem}
.cg-card{background:var(--dark-card);border:1.5px solid var(--dark-border);border-radius:18px;overflow:hidden;display:flex;flex-direction:column}
.cg-thumb{width:100%;aspect-ratio:1;background:rgba(255,255,255,.04);display:flex;align-items:center;justify-content:center;overflow:hidden}
.cg-thumb img{width:100%;height:100%;object-fit:cover}
.cg-thumb i{font-size:2rem;color:var(--dark-muted);opacity:.4}
.cg-body{padding:1rem;display:flex;flex-direction:column;gap:.5rem;flex:1}
.cg-name{font-weight:700;font-size:.9rem;color:#fff;line-height:1.35;min-height:2.5em}
.cg-supplier{font-size:.72rem;color:var(--dark-muted);display:flex;align-items:center;gap:.3rem}
.cg-price-row{display:flex;align-items:baseline;justify-content:space-between;margin-top:.2rem}
.cg-cost{font-size:.72rem;color:var(--dark-muted)}
.cg-suggested{font-size:.95rem;font-weight:800;color:var(--gold)}
.cg-ship{font-size:.7rem;color:var(--dark-muted);display:flex;align-items:center;gap:.3rem}

.cg-import-form{margin-top:.4rem;display:flex;flex-direction:column;gap:.5rem}
.cg-import-form input{width:100%;padding:.5rem .7rem;background:rgba(255,255,255,.06);border:1.5px solid var(--dark-border);border-radius:8px;color:#f0f0f0;font-size:.82rem;font-family:inherit;outline:none;box-sizing:border-box}
.cg-import-form input:focus{border-color:var(--gold)}
.cg-import-form label{font-size:.68rem;color:var(--dark-muted);font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.btn-import{width:100%;padding:.6rem;background:linear-gradient(135deg,var(--gold-br),var(--gold));color:#1A1A2E;font-weight:800;font-size:.82rem;border:none;border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.4rem}
.btn-import:hover{opacity:.9}
.cg-imported{width:100%;padding:.6rem;background:rgba(46,204,113,.1);color:#2ecc71;border:1.5px solid rgba(46,204,113,.25);font-weight:700;font-size:.8rem;border-radius:8px;text-align:center;text-decoration:none;display:block}

.empty-zone{grid-column:1/-1;text-align:center;padding:3.5rem 2rem;background:var(--dark-card);border:1.5px solid var(--dark-border);border-radius:18px}
.empty-zone i{font-size:2.8rem;color:var(--dark-muted);display:block;margin-bottom:.75rem;opacity:.5}
.empty-zone p{color:var(--dark-muted);font-size:.85rem;margin:0}
.cg-pagination{display:flex;gap:.4rem;align-items:center;justify-content:center;padding:1.5rem 0 0}
.pg-link{padding:.4rem .75rem;border-radius:8px;border:1.5px solid var(--dark-border);color:var(--dark-muted);font-size:.78rem;text-decoration:none}
.pg-link:hover,.pg-link.cur{background:rgba(212,175,55,.12);color:var(--gold);border-color:rgba(212,175,55,.3)}
</style>

<div class="cg-toolbar">
  <div>
    <h1>Supplier Catalog</h1>
    <p>Import a product from Etel Gebeya's platform suppliers straight into your store -- set your own price, we handle the rest of the dropship setup.</p>
  </div>
  <form method="GET" action="<?= View::url('/vendor-panel/catalog') ?>" class="cg-search">
    <i class="bi bi-search"></i>
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search catalog...">
  </form>
</div>

<?php if (!empty($flash)): ?>
  <div style="padding:.8rem 1.2rem;border-radius:10px;margin-bottom:1rem;background:<?= $flash['type']==='error'?'rgba(239,68,68,.15)':'rgba(16,185,129,.15)' ?>;color:<?= $flash['type']==='error'?'#ef4444':'#10b981' ?>;border:1px solid currentColor">
    <?= e($flash['msg']) ?>
  </div>
<?php endif; ?>

<div class="cg-grid">
  <?php if (empty($items)): ?>
    <div class="empty-zone">
      <i class="bi bi-shop-window"></i>
      <p><?= $q ? 'No catalog items match your search.' : 'No platform catalog items available yet -- check back soon.' ?></p>
    </div>
  <?php else: foreach ($items as $it):
    $suggested   = (float)$it['cost_price'] * (1 + (float)$it['suggested_markup_pct'] / 100);
    $productId   = $imported[(int)$it['id']] ?? null;
    $shipRange   = $it['dropship_lead_days_min']
      ? ((int)$it['dropship_lead_days_min'] . ($it['dropship_lead_days_max'] && $it['dropship_lead_days_max'] > $it['dropship_lead_days_min'] ? '-'.(int)$it['dropship_lead_days_max'] : '+') . ' days')
      : null;
  ?>
    <div class="cg-card">
      <div class="cg-thumb">
        <?php if (!empty($it['image_url'])): ?>
          <img src="<?= e($it['image_url']) ?>" alt="">
        <?php else: ?>
          <i class="bi bi-image"></i>
        <?php endif; ?>
      </div>
      <div class="cg-body">
        <div class="cg-name"><?= e($it['name_en']) ?></div>
        <div class="cg-supplier"><i class="bi bi-truck"></i> <?= e($it['supplier_name']) ?></div>
        <?php if ($shipRange): ?><div class="cg-ship"><i class="bi bi-clock-history"></i> Ships in <?= e($shipRange) ?></div><?php endif; ?>
        <div class="cg-price-row">
          <span class="cg-cost">Cost: Br <?= number_format((float)$it['cost_price'], 2) ?></span>
          <span class="cg-suggested">Br <?= number_format($suggested, 2) ?></span>
        </div>

        <?php if ($productId): ?>
          <a href="<?= View::url('/vendor-panel/products/'.$productId.'/edit') ?>" class="cg-imported"><i class="bi bi-check-circle"></i> Imported -- Edit Product</a>
        <?php else: ?>
          <form method="POST" action="<?= View::url('/vendor-panel/catalog/import') ?>" class="cg-import-form">
            <input type="hidden" name="catalog_item_id" value="<?= (int)$it['id'] ?>">
            <div>
              <label>Your Sale Price (ETB)</label>
              <input type="number" step="0.01" min="0.01" name="sale_price" value="<?= number_format($suggested, 2, '.', '') ?>" required>
            </div>
            <button type="submit" class="btn-import"><i class="bi bi-plus-circle"></i> Import to My Store</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>

<?php if ($pages > 1): ?>
  <div class="cg-pagination">
    <?php if ($page > 1): ?><a href="?q=<?= urlencode($q) ?>&page=<?= $page-1 ?>" class="pg-link">&laquo;</a><?php endif; ?>
    <?php for ($i = max(1,$page-2); $i <= min($pages,$page+2); $i++): ?>
      <a href="?q=<?= urlencode($q) ?>&page=<?= $i ?>" class="pg-link<?= $i===$page?' cur':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($page < $pages): ?><a href="?q=<?= urlencode($q) ?>&page=<?= $page+1 ?>" class="pg-link">&raquo;</a><?php endif; ?>
  </div>
<?php endif; ?>

<?php
$__content = ob_get_clean();
echo View::capture('_layouts/dark', array_merge(get_defined_vars(), [
    '__content' => $__content,
    'navItems'  => $navItems,
    'role'      => 'vendor',
    'pageTitle' => $pageTitle,
]));
