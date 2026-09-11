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
    <?= csrf() ?>
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

      <div class="form-group">
        <label>Pickup Location <span style="font-weight:400;opacity:.7">(where couriers collect your orders)</span></label>
        <div class="ap-wrap">
          <div class="ap-search-row">
            <input type="text" id="addr-search" placeholder="Search an address in Ethiopia..." style="flex:1">
            <button type="button" id="addr-locate" class="ap-locate-btn"><i class="bi bi-crosshair"></i> Use My Location</button>
          </div>
          <div id="addr-results" class="ap-results"></div>
        </div>
        <div id="addr-map" class="ap-map"></div>
        <input type="text" name="pickup_address" id="pickup_address" placeholder="Pickup address"
               value="<?= e($seller['pickup_address'] ?? '') ?>" style="margin-top:.6rem">
        <input type="hidden" name="pickup_lat" id="pickup_lat" value="<?= e($seller['pickup_lat'] ?? '') ?>">
        <input type="hidden" name="pickup_lng" id="pickup_lng" value="<?= e($seller['pickup_lng'] ?? '') ?>">
      </div>

      <button type="submit" class="btn-gold">
        <i class="bi bi-check-lg"></i> Save Settings
      </button>
    </div>
  </form>
</div>

<link rel="stylesheet" href="<?= View::asset('vendor/leaflet/leaflet.css') ?>">
<script src="<?= View::asset('vendor/leaflet/leaflet.js') ?>"></script>
<script>const EG_BASE_URL = <?= json_encode(rtrim($GLOBALS['__config']['app']['url'] ?? '', '/')) ?>;</script>
<script src="<?= View::asset('js/address-picker.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  EGAddressPicker({
    mapEl: 'addr-map', searchInput: 'addr-search', resultsEl: 'addr-results',
    latField: 'pickup_lat', lngField: 'pickup_lng', addressField: 'pickup_address',
    locateBtn: 'addr-locate',
    initialLat: <?= json_encode(!empty($seller['pickup_lat']) ? (float)$seller['pickup_lat'] : null) ?>,
    initialLng: <?= json_encode(!empty($seller['pickup_lng']) ? (float)$seller['pickup_lng'] : null) ?>,
  });
});
</script>

<style>
.btn-gold{display:inline-flex;align-items:center;gap:.4rem;background:linear-gradient(135deg,#FFE066,#D4AF37);color:#080604;font-weight:800;padding:.6rem 1.3rem;border-radius:10px;text-decoration:none;font-size:.9rem;border:none;cursor:pointer}
.form-card{background:var(--dark-card);border:1.5px solid var(--dark-border);border-radius:16px;padding:1.5rem}
.form-card-title{font-size:.95rem;font-weight:800;margin:0 0 1.2rem;color:var(--eg-gold,#D4AF37)}
.form-group{margin-bottom:1rem}
.form-group label{display:block;font-size:.82rem;color:var(--eg-text-dim,#aaa);margin-bottom:.4rem;font-weight:600}
.form-group input,.form-group textarea{width:100%;padding:.65rem .9rem;background:rgba(255,255,255,.05);border:1.5px solid rgba(255,255,255,.1);border-radius:10px;color:#f0f0f0;font-size:.9rem;outline:none;box-sizing:border-box;transition:border-color .2s}
.form-group input:focus,.form-group textarea:focus{border-color:var(--eg-gold,#D4AF37)}
.form-group textarea{resize:vertical;min-height:100px}
.ap-wrap { position: relative; margin-bottom: .6rem; }
.ap-search-row { display: flex; gap: .5rem; }
.ap-results {
  display: none; position: absolute; z-index: 500; top: 100%; left: 0; right: 0;
  background: #1c1208; border: 1px solid rgba(212,175,55,.25); border-radius: 10px;
  margin-top: .3rem; max-height: 220px; overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,.5);
}
.ap-result { padding: .6rem .85rem; font-size: .83rem; color: #F7EFD9; cursor: pointer; border-bottom: 1px solid rgba(212,175,55,.08); }
.ap-result:last-child { border-bottom: none; }
.ap-result:hover { background: rgba(212,175,55,.1); }
.ap-result-empty { padding: .6rem .85rem; font-size: .8rem; color: #7A6840; }
.ap-locate-btn {
  flex-shrink: 0; display: flex; align-items: center; gap: .4rem; white-space: nowrap;
  background: rgba(212,175,55,.1); border: 1.5px solid rgba(212,175,55,.25); color: #D4AF37;
  border-radius: 12px; padding: 0 1rem; font-size: .83rem; font-weight: 700; cursor: pointer;
}
.ap-locate-btn:hover { background: rgba(212,175,55,.18); }
.ap-map { height: 240px; border-radius: 12px; border: 1px solid rgba(212,175,55,.18); }
</style>
<?php
$__content = ob_get_clean();
echo View::capture('_layouts/dark', array_merge(get_defined_vars(), ['__content'=>$__content,'navItems'=>$navItems,'role'=>'vendor','pageTitle'=>$pageTitle]));
