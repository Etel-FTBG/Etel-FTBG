<?php
use App\Core\View;
require __DIR__.'/_layout.php';
$pageTitle = ucfirst('categories');
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
.badge-paid,.badge-active,.badge-admin{background:rgba(46,204,113,.15);color:var(--green);border:1px solid rgba(46,204,113,.25)}
.badge-unpaid,.badge-customer{background:rgba(212,175,55,.12);color:var(--gold);border:1px solid rgba(212,175,55,.2)}
.badge-pending{background:rgba(96,180,255,.15);color:var(--blue);border:1px solid rgba(96,180,255,.25)}
.badge-vendor{background:rgba(155,89,182,.15);color:var(--purple);border:1px solid rgba(155,89,182,.25)}
.badge-courier{background:rgba(78,205,196,.15);color:var(--teal);border:1px solid rgba(78,205,196,.25)}
.ag-inp{background:rgba(255,255,255,.05);border:1px solid var(--dark-border);color:var(--dark-text);font-family:inherit;font-size:12.5px;padding:8px 12px;border-radius:var(--r-sm);outline:none;width:100%;max-width:340px}
.ag-inp:focus{border-color:var(--gold)}
.ag-sel{background:rgba(255,255,255,.05);border:1px solid var(--dark-border);color:var(--dark-text);font-family:inherit;font-size:12.5px;padding:8px 12px;border-radius:var(--r-sm);cursor:pointer;outline:none}
.ag-sel option{background:#0A0A1A}
.btn-xs{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:6px;font-size:11.5px;font-weight:700;cursor:pointer;border:none;transition:opacity .12s;text-decoration:none}
.btn-gold-xs{background:linear-gradient(135deg,var(--gold-br),var(--gold));color:#1A1A2E}
.btn-ghost-xs{background:rgba(212,175,55,.1);color:var(--gold);border:1px solid rgba(212,175,55,.2)}
.btn-xs:hover{opacity:.8}
.ag-pagination{display:flex;gap:5px;align-items:center;justify-content:center;padding:14px;border-top:1px solid var(--dark-border)}
.pg-link{padding:5px 11px;border-radius:6px;border:1px solid var(--dark-border);color:var(--dark-muted);font-size:12px;text-decoration:none}
.pg-link:hover,.pg-link.cur{background:rgba(212,175,55,.12);color:var(--gold);border-color:rgba(212,175,55,.3)}
.empty-s{text-align:center;padding:48px 20px;color:var(--dark-muted);font-size:13px}
</style>

<?php if (!empty($flash)): ?>
  <div class="flash-<?= e($flash['type'] ?? 'success') ?>"><?= e($flash['msg'] ?? '') ?></div>
<?php endif; ?>

<div class="ag-bar-title"><?= ucfirst('categories') ?></div>


<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;gap:12px;flex-wrap:wrap">
  <span style="font-size:12px;color:var(--dark-muted)"><?= count($categories??[]) ?> categories</span>
  <button onclick="document.getElementById('cat-form').style.display='block';this.style.display='none'"
          class="btn-xs btn-gold-xs">+ Add Category</button>
</div>

<div id="cat-form" style="display:none;background:var(--dark-card);border:1px solid var(--dark-border);border-radius:var(--r-md);padding:20px;margin-bottom:16px">
  <div style="font-size:14px;font-weight:700;color:var(--dark-text);margin-bottom:16px">New Category</div>
  <form method="POST" action="<?= View::url('/admin/categories') ?>">
    <?= csrf() ?>
    <input type="hidden" name="id" value="0">
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:end;flex-wrap:wrap">
      <div>
        <label for="cat-name_en" style="font-size:11px;color:var(--dark-muted);display:block;margin-bottom:4px">Name (EN)*</label>
        <input type="text" name="name_en" id="cat-name_en" class="ag-inp" placeholder="e.g. Electronics" required style="max-width:none;width:100%">
      </div>
      <div>
        <label for="cat-name_am" style="font-size:11px;color:var(--dark-muted);display:block;margin-bottom:4px">Name (AM)</label>
        <input type="text" name="name_am" id="cat-name_am" class="ag-inp" placeholder="Amharic name (optional)" style="max-width:none;width:100%">
      </div>
      <div>
        <label for="cat-slug" style="font-size:11px;color:var(--dark-muted);display:block;margin-bottom:4px">Slug (auto-generated)</label>
        <input type="text" name="slug" id="cat-slug" class="ag-inp" placeholder="electronics" style="max-width:none;width:100%">
      </div>
      <button type="submit" class="btn-xs btn-gold-xs" style="height:38px;padding:0 16px">Save</button>
    </div>
  </form>
</div>

<div class="ag-card">
  <?php if (empty($categories)): ?>
    <div class="empty-s">No categories yet. Add one above.</div>
  <?php else: ?>
  <table class="ag-tbl">
    <thead><tr><th>ID</th><th>Name (EN)</th><th>Name (AM)</th><th>Slug</th><th>Products</th></tr></thead>
    <tbody>
      <?php foreach ($categories as $c): ?>
        <tr>
          <td style="font-size:11px;color:var(--dark-muted)"><?= (int)$c['id'] ?></td>
          <td style="font-weight:600;color:var(--dark-text)"><?= e($c['name_en']) ?></td>
          <td style="font-size:12px"><?= e($c['name_am'] ?? '-') ?></td>
          <td style="font-family:monospace;font-size:11.5px;color:var(--gold)"><?= e($c['slug']) ?></td>
          <td style="color:var(--dark-muted)"><?= (int)($c['product_count'] ?? 0) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php
$__content = ob_get_clean();
echo View::capture('_layouts/dark', array_merge(get_defined_vars(), [
    '__content' => $__content,
    'navItems'  => $navItems,
    'role'      => 'admin',
    'pageTitle' => $pageTitle,
]));
