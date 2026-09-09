<?php
use App\Core\View;
require __DIR__.'/_layout.php';
$pageTitle = 'Supplier Catalog';
ob_start();
?>
<style>
.ag-bar-title{font-size:18px;font-weight:800;color:var(--dark-text);margin-bottom:16px}
.ag-card{background:var(--dark-card);border:1px solid var(--dark-border);border-radius:var(--r-md);overflow:hidden}
.ag-tbl{width:100%;border-collapse:collapse}
.ag-tbl th{background:rgba(255,255,255,.03);padding:10px 14px;text-align:left;font-size:10px;font-weight:700;color:var(--dark-muted);text-transform:uppercase;letter-spacing:.8px;border-bottom:1px solid var(--dark-border);white-space:nowrap}
.ag-tbl td{padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);font-size:12.5px;color:var(--dark-muted);vertical-align:middle}
.ag-tbl tr:last-child td{border-bottom:none}
.ag-tbl tr:hover td{background:rgba(255,255,255,.02)}
.flash-success{background:rgba(46,204,113,.12);border:1px solid rgba(46,204,113,.25);color:var(--green);padding:11px 15px;border-radius:var(--r-sm);margin-bottom:16px;font-size:13px}
.flash-error{background:rgba(231,76,60,.12);border:1px solid rgba(231,76,60,.25);color:var(--red);padding:11px 15px;border-radius:var(--r-sm);margin-bottom:16px;font-size:13px}
.badge{display:inline-block;padding:3px 8px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;white-space:nowrap}
.badge-active{background:rgba(46,204,113,.15);color:var(--green);border:1px solid rgba(46,204,113,.25)}
.badge-archived{background:rgba(136,136,168,.1);color:var(--dark-muted)}
.ag-inp,.ag-sel,.ag-txt{background:rgba(255,255,255,.05);border:1px solid var(--dark-border);color:var(--dark-text);font-family:inherit;font-size:12.5px;padding:8px 12px;border-radius:var(--r-sm);outline:none;width:100%;box-sizing:border-box}
.ag-txt{resize:vertical;min-height:60px}
.ag-sel option{background:#0A0A1A}
.btn-xs{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:6px;font-size:11.5px;font-weight:700;cursor:pointer;border:none;transition:opacity .12s;text-decoration:none}
.btn-gold-xs{background:linear-gradient(135deg,var(--gold-br),var(--gold));color:#1A1A2E}
.btn-ghost-xs{background:rgba(212,175,55,.1);color:var(--gold);border:1px solid rgba(212,175,55,.2)}
.btn-danger-xs{background:rgba(231,76,60,.12);color:var(--red);border:1px solid rgba(231,76,60,.2)}
.btn-xs:hover{opacity:.8}
.ag-pagination{display:flex;gap:5px;align-items:center;justify-content:center;padding:14px;border-top:1px solid var(--dark-border)}
.pg-link{padding:5px 11px;border-radius:6px;border:1px solid var(--dark-border);color:var(--dark-muted);font-size:12px;text-decoration:none}
.pg-link:hover,.pg-link.cur{background:rgba(212,175,55,.12);color:var(--gold);border-color:rgba(212,175,55,.3)}
.empty-s{text-align:center;padding:48px 20px;color:var(--dark-muted);font-size:13px}
.cat-thumb{width:36px;height:36px;border-radius:8px;object-fit:cover;border:1px solid var(--dark-border);background:rgba(255,255,255,.05)}
.cat-thumb-ph{width:36px;height:36px;border-radius:8px;border:1px solid var(--dark-border);background:rgba(255,255,255,.04);display:flex;align-items:center;justify-content:center;color:var(--dark-muted);font-size:14px;flex-shrink:0}
.panel-toggle-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.form-panel{display:none;background:var(--dark-card);border:1px solid var(--dark-border);border-radius:var(--r-md);padding:20px;margin-bottom:16px}
.form-panel.open{display:block}
.form-panel h3{font-size:14px;font-weight:700;color:var(--dark-text);margin-bottom:14px}
.fg-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:10px}
.fg-row.col2{grid-template-columns:repeat(2,1fr)}
.fg{display:flex;flex-direction:column;gap:4px}
.fg label{font-size:11px;color:var(--dark-muted);font-weight:600}
</style>

<?php if (!empty($flash)): ?>
  <div class="flash-<?= e($flash['type'] ?? 'success') ?>"><?= e($flash['msg'] ?? '') ?></div>
<?php endif; ?>

<div class="ag-bar-title">Supplier Catalog</div>

<div class="panel-toggle-row">
  <button type="button" class="btn-xs btn-gold-xs" onclick="togglePanel('item-panel')">+ Add Catalog Item</button>
  <button type="button" class="btn-xs btn-ghost-xs" onclick="togglePanel('supplier-panel')">+ Add Platform Supplier</button>
  <button type="button" class="btn-xs btn-ghost-xs" onclick="togglePanel('csv-panel')">Import CSV</button>
  <span style="font-size:12px;color:var(--dark-muted);margin-left:auto;align-self:center"><?= number_format($total) ?> items</span>
</div>

<!-- Add platform supplier -->
<div id="supplier-panel" class="form-panel">
  <h3>New Platform Supplier</h3>
  <form method="POST" action="<?= View::url('/admin/catalog/supplier') ?>">
    <?= csrf() ?>
    <div class="fg-row">
      <div class="fg"><label>Name *</label><input class="ag-inp" type="text" name="name" required placeholder="e.g. Addis Import Co."></div>
      <div class="fg"><label>Contact Email</label><input class="ag-inp" type="email" name="contact_email"></div>
      <div class="fg"><label>Contact Phone</label><input class="ag-inp" type="tel" name="contact_phone"></div>
    </div>
    <div class="fg-row col2">
      <div class="fg"><label>Website</label><input class="ag-inp" type="text" name="website" placeholder="https://..."></div>
      <div class="fg"><label>Notes</label><input class="ag-inp" type="text" name="notes"></div>
    </div>
    <button type="submit" class="btn-xs btn-gold-xs" style="margin-top:8px">Save Supplier</button>
  </form>
</div>

<!-- CSV import -->
<div id="csv-panel" class="form-panel">
  <h3>Import Catalog Items from CSV</h3>
  <p style="font-size:12px;color:var(--dark-muted);margin-bottom:12px">
    Header row required. Columns: <code>name_en,sku,cost_price,suggested_markup_pct,category_id,image_url,gallery_urls,description_en,lead_days_min,lead_days_max</code> -- any subset works.
  </p>
  <form method="POST" action="<?= View::url('/admin/catalog/import-csv') ?>" enctype="multipart/form-data">
    <?= csrf() ?>
    <div class="fg-row col2">
      <div class="fg">
        <label>Supplier *</label>
        <select class="ag-sel" name="supplier_id" required>
          <option value="">-- Select supplier --</option>
          <?php foreach ($suppliers as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg"><label>CSV File *</label><input class="ag-inp" type="file" name="csv" accept=".csv" required></div>
    </div>
    <button type="submit" class="btn-xs btn-gold-xs" style="margin-top:8px">Upload &amp; Import</button>
  </form>
</div>

<!-- Add/edit catalog item -->
<div id="item-panel" class="form-panel">
  <h3 id="item-panel-title">New Catalog Item</h3>
  <form method="POST" action="<?= View::url('/admin/catalog/save') ?>" id="item-form">
    <?= csrf() ?>
    <input type="hidden" name="id" id="ci-id" value="0">
    <div class="fg-row">
      <div class="fg"><label>Name (EN) *</label><input class="ag-inp" type="text" name="name_en" id="ci-name_en" required></div>
      <div class="fg"><label>Name (AM)</label><input class="ag-inp" type="text" name="name_am" id="ci-name_am"></div>
      <div class="fg"><label>SKU</label><input class="ag-inp" type="text" name="sku" id="ci-sku"></div>
    </div>
    <div class="fg-row">
      <div class="fg">
        <label>Supplier *</label>
        <select class="ag-sel" name="supplier_id" id="ci-supplier_id" required>
          <option value="">-- Select supplier --</option>
          <?php foreach ($suppliers as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg">
        <label>Category</label>
        <select class="ag-sel" name="category_id" id="ci-category_id">
          <option value="">-- None --</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= e($c['name_en']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg">
        <label>Status</label>
        <select class="ag-sel" name="status" id="ci-status">
          <option value="active">Active</option>
          <option value="archived">Archived</option>
        </select>
      </div>
    </div>
    <div class="fg-row">
      <div class="fg"><label>Cost Price (ETB) *</label><input class="ag-inp" type="number" step="0.01" min="0.01" name="cost_price" id="ci-cost_price" required></div>
      <div class="fg"><label>Suggested Markup %</label><input class="ag-inp" type="number" step="0.01" min="0" name="suggested_markup_pct" id="ci-suggested_markup_pct" value="30"></div>
      <div class="fg"><label>Ships in (days min-max)</label>
        <div style="display:flex;gap:6px">
          <input class="ag-inp" type="number" min="1" name="dropship_lead_days_min" id="ci-lead_min" placeholder="7">
          <input class="ag-inp" type="number" min="1" name="dropship_lead_days_max" id="ci-lead_max" placeholder="14">
        </div>
      </div>
    </div>
    <div class="fg-row col2">
      <div class="fg"><label>Primary Image URL</label><input class="ag-inp" type="text" name="image_url" id="ci-image_url" placeholder="https://..."></div>
      <div class="fg"><label>Gallery Image URLs (comma-separated)</label><input class="ag-inp" type="text" name="gallery_urls" id="ci-gallery_urls"></div>
    </div>
    <div class="fg" style="margin-bottom:10px">
      <label>Description (EN)</label>
      <textarea class="ag-txt" name="description_en" id="ci-description_en"></textarea>
    </div>
    <button type="submit" class="btn-xs btn-gold-xs">Save Catalog Item</button>
    <button type="button" class="btn-xs btn-ghost-xs" onclick="resetItemForm()">Clear</button>
  </form>
</div>

<div class="ag-card">
  <?php if (empty($items)): ?>
    <div class="empty-s">No catalog items yet. Add one above, or import a CSV for a supplier.</div>
  <?php else: ?>
  <table class="ag-tbl">
    <thead>
      <tr>
        <th style="width:46px"></th>
        <th>Item</th>
        <th>Supplier</th>
        <th>Cost</th>
        <th>Suggested Sale</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $it):
        $suggested = (float)$it['cost_price'] * (1 + (float)$it['suggested_markup_pct'] / 100);
      ?>
        <tr>
          <td>
            <?php if (!empty($it['image_url'])): ?>
              <img src="<?= e($it['image_url']) ?>" class="cat-thumb" alt="">
            <?php else: ?>
              <div class="cat-thumb-ph"><i class="bi bi-image"></i></div>
            <?php endif; ?>
          </td>
          <td>
            <div style="font-weight:600;color:var(--dark-text)"><?= e($it['name_en']) ?></div>
            <?php if (!empty($it['sku'])): ?><div style="font-size:10.5px;color:var(--dark-muted);font-family:monospace"><?= e($it['sku']) ?></div><?php endif; ?>
          </td>
          <td><?= e($it['supplier_name']) ?></td>
          <td style="white-space:nowrap">ETB <?= number_format((float)$it['cost_price'], 2) ?></td>
          <td style="white-space:nowrap;color:var(--gold-lt);font-weight:700">ETB <?= number_format($suggested, 2) ?></td>
          <td><span class="badge badge-<?= e($it['status']) ?>"><?= ucfirst($it['status']) ?></span></td>
          <td style="white-space:nowrap">
            <button type="button" class="btn-xs btn-ghost-xs" onclick='editItem(<?= json_encode($it, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>Edit</button>
            <form method="POST" action="<?= View::url('/admin/catalog/'.(int)$it['id'].'/delete') ?>" style="display:inline" onsubmit="return confirm('Remove this catalog item? Products vendors already imported from it are not affected.')">
              <?= csrf() ?>
              <button type="submit" class="btn-xs btn-danger-xs">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($pages > 1): ?>
    <div class="ag-pagination">
      <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?>" class="pg-link">&laquo;</a><?php endif; ?>
      <?php for ($i = max(1,$page-2); $i <= min($pages,$page+2); $i++): ?>
        <a href="?page=<?= $i ?>" class="pg-link<?= $i===$page?' cur':'' ?>"><?= $i ?></a>
      <?php endfor; ?>
      <?php if ($page < $pages): ?><a href="?page=<?= $page+1 ?>" class="pg-link">&raquo;</a><?php endif; ?>
    </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<script>
function togglePanel(id) {
  document.querySelectorAll('.form-panel').forEach(p => { if (p.id !== id) p.classList.remove('open'); });
  document.getElementById(id).classList.toggle('open');
}
function resetItemForm() {
  document.getElementById('item-form').reset();
  document.getElementById('ci-id').value = '0';
  document.getElementById('item-panel-title').textContent = 'New Catalog Item';
}
function editItem(it) {
  document.getElementById('ci-id').value = it.id;
  document.getElementById('ci-name_en').value = it.name_en || '';
  document.getElementById('ci-name_am').value = it.name_am || '';
  document.getElementById('ci-sku').value = it.sku || '';
  document.getElementById('ci-supplier_id').value = it.supplier_id || '';
  document.getElementById('ci-category_id').value = it.category_id || '';
  document.getElementById('ci-status').value = it.status || 'active';
  document.getElementById('ci-cost_price').value = it.cost_price || '';
  document.getElementById('ci-suggested_markup_pct').value = it.suggested_markup_pct || '30';
  document.getElementById('ci-lead_min').value = it.dropship_lead_days_min || '';
  document.getElementById('ci-lead_max').value = it.dropship_lead_days_max || '';
  document.getElementById('ci-image_url').value = it.image_url || '';
  document.getElementById('ci-gallery_urls').value = it.gallery_urls || '';
  document.getElementById('ci-description_en').value = it.description_en || '';
  document.getElementById('item-panel-title').textContent = 'Edit Catalog Item';
  document.getElementById('item-panel').classList.add('open');
  document.getElementById('item-panel').scrollIntoView({behavior:'smooth', block:'start'});
}
</script>

<?php
$__content = ob_get_clean();
echo View::capture('_layouts/dark', array_merge(get_defined_vars(), [
    '__content' => $__content,
    'navItems'  => $navItems,
    'role'      => 'admin',
    'pageTitle' => $pageTitle,
]));
