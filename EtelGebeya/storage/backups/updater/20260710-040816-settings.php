<?php
use App\Core\View;
require __DIR__.'/_layout.php';
ob_start();
?>
<div>
  <div class="dash-header">
    <div><h1 class="dash-title">Store Settings</h1><p class="dash-sub">Update your store information</p></div>
  </div>

  <?php if ($flash): ?>
    <div style="padding:.8rem 1.2rem;border-radius:10px;margin-bottom:1rem;background:<?= $flash['type']==='error'?'rgba(239,68,68,.15)':'rgba(16,185,129,.15)' ?>;color:<?= $flash['type']==='error'?'#ef4444':'#10b981' ?>;border:1px solid currentColor">
      <?= e($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <form method="POST" action="<?= View::url('/vendor-panel/settings') ?>">
    <div class="form-card" style="max-width:600px">
      <h3 class="form-card-title">Store Information</h3>

      <div class="form-group">
        <label>Store Name (English) <span style="color:#ef4444">*</span></label>
        <input type="text" name="store_name" required value="<?= e($seller['store_name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Store Name (Amharic)</label>
        <input type="text" name="store_name_am" value="<?= e($seller['store_name_am'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Store URL</label>
        <input type="text" readonly value="<?= View::url('/vendor/'.e($seller['store_slug']??'')) ?>" style="opacity:.6">
      </div>
      <div class="form-group">
        <label>Description</label>
        <textarea name="description" rows="4"><?= e($seller['description'] ?? '') ?></textarea>
      </div>
      <div class="form-group">
        <label>Phone</label>
        <input type="tel" name="phone" value="<?= e($seller['phone'] ?? '') ?>">
      </div>

      <button type="submit" class="btn-gold">
        <i class="bi bi-check-lg"></i> Save Settings
      </button>
    </div>
  </form>
</div>

<style>
.btn-gold{display:inline-flex;align-items:center;gap:.4rem;background:linear-gradient(135deg,#FFE066,#D4AF37);color:#080604;font-weight:800;padding:.6rem 1.3rem;border-radius:10px;text-decoration:none;font-size:.9rem;border:none;cursor:pointer}
.form-card{background:var(--eg-glass,rgba(255,255,255,.04));border:1.5px solid var(--eg-border,rgba(255,255,255,.08));border-radius:16px;padding:1.5rem}
.form-card-title{font-size:.95rem;font-weight:800;margin:0 0 1.2rem;color:var(--eg-gold,#D4AF37)}
.form-group{margin-bottom:1rem}
.form-group label{display:block;font-size:.82rem;color:var(--eg-text-dim,#aaa);margin-bottom:.4rem;font-weight:600}
.form-group input,.form-group textarea{width:100%;padding:.65rem .9rem;background:rgba(255,255,255,.05);border:1.5px solid rgba(255,255,255,.1);border-radius:10px;color:#f0f0f0;font-size:.9rem;outline:none;box-sizing:border-box;transition:border-color .2s}
.form-group input:focus,.form-group textarea:focus{border-color:var(--eg-gold,#D4AF37)}
.form-group textarea{resize:vertical;min-height:100px}
</style>
<?php
$__content = ob_get_clean();
echo View::capture('_layouts/dark', array_merge(get_defined_vars(), ['__content'=>$__content,'navItems'=>$navItems,'role'=>'vendor','pageTitle'=>$pageTitle]));
