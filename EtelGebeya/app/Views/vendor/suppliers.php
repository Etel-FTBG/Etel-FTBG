<?php
use App\Core\View;
require __DIR__.'/_layout.php';
ob_start();
?>
<style>
.sp-toolbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem}
.sp-toolbar h1{font-size:1.4rem;font-weight:900;color:#fff;margin:0}
.sp-toolbar p{font-size:.82rem;color:var(--dark-muted);margin:.2rem 0 0}
.sp-form{background:var(--dark-card);border:1.5px solid var(--dark-border);border-radius:18px;padding:1.25rem 1.4rem;margin-bottom:1.25rem;display:none}
.sp-form .fg{margin-bottom:.9rem}
.sp-form label{display:block;font-size:.78rem;font-weight:700;color:var(--dark-muted);margin-bottom:.4rem;text-transform:uppercase;letter-spacing:.04em}
.sp-form input,.sp-form textarea{width:100%;padding:.65rem .9rem;background:rgba(255,255,255,.05);border:1.5px solid rgba(255,255,255,.08);border-radius:10px;color:#f0f0f0;font-size:.88rem;font-family:'Outfit',sans-serif;outline:none;box-sizing:border-box}
.sp-form textarea{resize:vertical;min-height:70px}
.sp-form .fg-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.sp-table-wrap{background:var(--dark-card);border:1.5px solid var(--dark-border);border-radius:20px;overflow:hidden}
.sp-table{width:100%;border-collapse:collapse}
.sp-table th{font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--gold);padding:.75rem 1rem;background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.07);white-space:nowrap}
.sp-table td{padding:.75rem 1rem;border-bottom:1px solid rgba(255,255,255,.04);font-size:.85rem;color:var(--dark-text);vertical-align:middle}
.sp-table tr:last-child td{border-bottom:none}
.sp-table tr:hover td{background:rgba(212,175,55,.04)}
.sp-name{font-weight:700;color:#fff}
.sp-muted{color:var(--dark-muted);font-size:.78rem}
.empty-zone{text-align:center;padding:3.5rem 2rem}
.empty-zone i{font-size:2.8rem;color:rgba(255,255,255,.1);display:block;margin-bottom:.75rem}
.empty-zone p{color:var(--dark-muted);font-size:.85rem;margin:0 0 1rem}
.act-btns{display:flex;gap:.4rem}
.act-btn{display:inline-flex;align-items:center;gap:.3rem;padding:.35rem .7rem;border-radius:8px;font-size:.78rem;font-weight:700;text-decoration:none;border:1.5px solid transparent;cursor:pointer;background:none}
.act-del{color:#e74c3c;border-color:rgba(231,76,60,.2)}
.act-del:hover{background:rgba(231,76,60,.12)}
</style>

<div class="sp-toolbar">
  <div>
    <h1>Suppliers</h1>
    <p>Contacts you forward dropshipped orders to for fulfilment</p>
  </div>
  <button type="button" class="btn btn-gold" onclick="document.getElementById('sp-form').style.display='block';this.style.display='none'">
    <i class="bi bi-plus-lg"></i> Add Supplier
  </button>
</div>

<div class="sp-form" id="sp-form">
  <form method="POST" action="<?= View::url('/vendor-panel/suppliers/save') ?>">
    <input type="hidden" name="id" value="0">
    <div class="fg-row">
      <div class="fg">
        <label>Supplier Name <span style="color:#ef4444">*</span></label>
        <input type="text" name="name" required placeholder="e.g. Addis Wholesale Textiles">
      </div>
      <div class="fg">
        <label>Website</label>
        <input type="text" name="website" placeholder="https://...">
      </div>
    </div>
    <div class="fg-row">
      <div class="fg">
        <label>Contact Email</label>
        <input type="email" name="contact_email" placeholder="orders@supplier.com">
      </div>
      <div class="fg">
        <label>Contact Phone</label>
        <input type="tel" name="contact_phone" placeholder="+251 9xx xxx xxx">
      </div>
    </div>
    <div class="fg">
      <label>Notes</label>
      <textarea name="notes" placeholder="Payment terms, minimum order, anything worth remembering"></textarea>
    </div>
    <button type="submit" class="btn btn-gold"><i class="bi bi-check-lg"></i> Save Supplier</button>
  </form>
</div>

<div class="sp-table-wrap">
  <?php if (empty($suppliers)): ?>
    <div class="empty-zone">
      <i class="bi bi-truck"></i>
      <p>No suppliers yet. Add one, then assign it to a dropshipped product.</p>
    </div>
  <?php else: ?>
  <table class="sp-table">
    <thead><tr><th>Supplier</th><th>Contact</th><th>Notes</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($suppliers as $s): ?>
        <tr>
          <td>
            <div class="sp-name"><?= e($s['name']) ?></div>
            <?php if (!empty($s['website'])): ?><a href="<?= e($s['website']) ?>" target="_blank" class="sp-muted" style="color:var(--gold)"><?= e($s['website']) ?></a><?php endif; ?>
          </td>
          <td>
            <?php if (!empty($s['contact_email'])): ?><div><?= e($s['contact_email']) ?></div><?php endif; ?>
            <?php if (!empty($s['contact_phone'])): ?><div class="sp-muted"><?= e($s['contact_phone']) ?></div><?php endif; ?>
            <?php if (empty($s['contact_email']) && empty($s['contact_phone'])): ?><span class="sp-muted">--</span><?php endif; ?>
          </td>
          <td class="sp-muted" style="max-width:220px"><?= e(mb_substr($s['notes'] ?? '', 0, 80, 'UTF-8')) ?></td>
          <td class="act-btns">
            <form method="POST" action="<?= View::url('/vendor-panel/suppliers/'.(int)$s['id'].'/delete') ?>" onsubmit="return confirm('Remove this supplier? Products using it will keep working but lose the supplier assignment.')">
              <button type="submit" class="act-btn act-del"><i class="bi bi-trash3"></i> Remove</button>
            </form>
          </td>
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
    'role'      => 'vendor',
    'pageTitle' => $pageTitle,
]));
