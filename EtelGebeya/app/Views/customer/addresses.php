<?php
use App\Core\View;
$locale = $locale ?? 'en';
ob_start();
?>
<style>
.addr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:.9rem;margin-bottom:1.5rem}
.addr-card{background:var(--card,#130d05);border:1.5px solid var(--border-dim,rgba(212,175,55,.08));border-radius:18px;padding:1.2rem 1.25rem;transition:border-color .2s,transform .2s;position:relative;overflow:hidden}
.addr-card:hover{border-color:var(--gold-line,rgba(212,175,55,.22));transform:translateY(-2px)}
.addr-card.default{border-color:rgba(212,175,55,.35);background:linear-gradient(145deg,rgba(212,175,55,.06),var(--card,#130d05))}
.addr-default-badge{display:inline-flex;align-items:center;gap:.3rem;background:rgba(212,175,55,.1);border:1px solid rgba(212,175,55,.2);color:var(--g2,#D4AF37);font-size:.65rem;font-weight:800;padding:.15rem .5rem;border-radius:6px;margin-bottom:.7rem;text-transform:uppercase;letter-spacing:.04em}
.addr-label{font-weight:900;font-size:.92rem;color:var(--text,#F7EFD9);margin-bottom:.5rem;display:flex;align-items:center;gap:.4rem}
.addr-detail{font-size:.8rem;color:var(--text-dim,#C9B88A);line-height:1.75}
.addr-icon{width:36px;height:36px;border-radius:10px;background:rgba(212,175,55,.08);display:flex;align-items:center;justify-content:center;font-size:1rem;color:var(--g2,#D4AF37);flex-shrink:0}

.add-card{background:var(--card,#130d05);border:1.5px solid var(--border-dim,rgba(212,175,55,.08));border-radius:20px;overflow:hidden}
.add-card-head{display:flex;align-items:center;gap:.6rem;padding:.9rem 1.3rem;border-bottom:1px solid rgba(212,175,55,.07);font-weight:800;font-size:.9rem;color:var(--text,#F7EFD9);background:rgba(212,175,55,.03)}
.add-card-body{padding:1.3rem}
.fg{margin-bottom:.9rem}
.fg label{display:block;font-size:.72rem;font-weight:700;color:var(--text-muted,#7A6840);margin-bottom:.38rem;text-transform:uppercase;letter-spacing:.06em}
.fg input,.fg select,.fg textarea{width:100%;padding:.65rem 1rem;background:rgba(212,175,55,.04);border:1.5px solid var(--border-dim,rgba(212,175,55,.1));border-radius:12px;color:var(--text,#F7EFD9);font-size:.88rem;font-family:inherit;outline:none;box-sizing:border-box;transition:border-color .2s;appearance:none}
.fg input:focus,.fg select:focus,.fg textarea:focus{border-color:var(--g2,#D4AF37)}
.fg select{background-image:url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%23C9B88A'%3e%3cpath fill-rule='evenodd' d='M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z'/%3e%3c/svg%3e");background-repeat:no-repeat;background-position:right .75rem center;background-size:1.1rem;padding-right:2.5rem}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:.9rem}
.btn-gold-sm{display:inline-flex;align-items:center;gap:.4rem;background:var(--grad-gold,linear-gradient(135deg,#FFE066,#D4AF37,#B8902E));color:#1a0e00;font-weight:800;padding:.65rem 1.4rem;border-radius:11px;border:none;cursor:pointer;font-size:.88rem;font-family:inherit;transition:all .2s;box-shadow:0 6px 20px rgba(212,175,55,.3)}
.btn-gold-sm:hover{transform:translateY(-1px);box-shadow:0 10px 28px rgba(212,175,55,.4)}
.flash-box{padding:.8rem 1.1rem;border-radius:12px;margin-bottom:1.1rem;border:1.5px solid;font-size:.85rem;display:flex;align-items:center;gap:.5rem}
.flash-error{background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.25);color:#ef4444}
.flash-success{background:rgba(16,185,129,.08);border-color:rgba(16,185,129,.25);color:#10b981}
</style>

<div style="margin-bottom:1.25rem">
  <h1 class="cp-page-title"><i class="bi bi-geo-alt"></i> My Addresses</h1>
  <p style="font-size:.82rem;color:var(--text-muted,#7A6840);margin:-.5rem 0 0">Saved delivery addresses</p>
</div>

<?php if (!empty($flash)): ?>
  <div class="flash-box flash-<?= $flash['type']==='error'?'error':'success' ?>">
    <i class="bi bi-<?= $flash['type']==='error'?'exclamation-circle':'check-circle' ?>"></i>
    <?= e($flash['msg']) ?>
  </div>
<?php endif; ?>

<!-- Existing addresses -->
<?php if (!empty($addresses)): ?>
  <div class="addr-grid">
    <?php foreach ($addresses as $addr):
      $icons = ['Home'=>'bi-house','Office'=>'bi-building','Other'=>'bi-geo-alt'];
      $icon  = $icons[$addr['label']??'Other'] ?? 'bi-geo-alt';
    ?>
      <div class="addr-card<?= !empty($addr['is_default'])?' default':'' ?>">
        <?php if (!empty($addr['is_default'])): ?>
          <div class="addr-default-badge"><i class="bi bi-star-fill" style="font-size:.6rem"></i> Default</div>
        <?php endif; ?>
        <div class="addr-label">
          <div class="addr-icon"><i class="bi <?= $icon ?>"></i></div>
          <?= e($addr['label']??'Home') ?>
        </div>
        <div class="addr-detail">
          <strong style="color:var(--text,#F7EFD9)"><?= e($addr['full_name']??'') ?></strong><br>
          <?= e($addr['phone']??'') ?><br>
          <?= e($addr['city']??'') ?><?= $addr['sub_city']?', '.e($addr['sub_city']):'' ?><?= $addr['wereda']?', Wereda '.e($addr['wereda']):'' ?><br>
          <?php if ($addr['address_detail']): ?><?= e($addr['address_detail']) ?><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- Add new address -->
<div class="add-card">
  <div class="add-card-head">
    <i class="bi bi-plus-circle" style="color:var(--g2,#D4AF37)"></i> Add New Address
  </div>
  <div class="add-card-body">
    <form method="POST" action="<?= View::url('/account/addresses') ?>">
      <div class="form-grid">
        <div class="fg">
          <label>Label</label>
          <select name="label">
            <option>Home</option><option>Office</option><option>Other</option>
          </select>
        </div>
        <div class="fg">
          <label>Full Name *</label>
          <input type="text" name="full_name" required placeholder="Your name">
        </div>
        <div class="fg">
          <label>Phone *</label>
          <input type="tel" name="phone" required placeholder="+251 9xx xxx xxx">
        </div>
        <div class="fg">
          <label>City *</label>
          <input type="text" name="city" required placeholder="e.g. Addis Ababa">
        </div>
        <div class="fg">
          <label>Sub-city</label>
          <input type="text" name="sub_city" placeholder="e.g. Bole">
        </div>
        <div class="fg">
          <label>Wereda</label>
          <input type="text" name="wereda" placeholder="e.g. 03">
        </div>
      </div>
      <div class="fg">
        <label>Address Detail</label>
        <textarea name="address_detail" rows="2" placeholder="Building, floor, landmark..."></textarea>
      </div>
      <button type="submit" class="btn-gold-sm">
        <i class="bi bi-check-lg"></i> Save Address
      </button>
    </form>
  </div>
</div>

<?php
$__content = ob_get_clean();
ob_start();
require __DIR__ . '/_layout.php';
$__panel = ob_get_clean();
echo View::capture('_layouts/public', [
    '__content' => $__panel,
    'pageTitle'  => 'My Addresses -- Etel Gebeya',
    'locale'     => $locale,
]);
