<?php
use App\Core\View;
require __DIR__.'/_layout.php';
ob_start();

$checks = [
  ['field' => 'store_name',     'label' => 'Store name',      'done' => trim($seller['store_name'] ?? '') !== ''],
  ['field' => 'store_name_am',  'label' => 'Amharic name',     'done' => trim($seller['store_name_am'] ?? '') !== ''],
  ['field' => 'description',    'label' => 'Description',      'done' => trim($seller['description'] ?? '') !== ''],
  ['field' => 'phone',          'label' => 'Phone number',     'done' => trim($seller['phone'] ?? '') !== ''],
  ['field' => 'pickup_address', 'label' => 'Pickup location',  'done' => trim($seller['pickup_address'] ?? '') !== ''],
];
$doneCount = 0;
foreach ($checks as $c) { if ($c['done']) { $doneCount++; } }
$totalCount = count($checks);
$pct = (int) round($doneCount / $totalCount * 100);
$storeUrl = View::url('/vendor/'.($seller['store_slug'] ?? ''));
$initial = mb_substr($seller['store_name'] ?? 'S', 0, 1, 'UTF-8');
?>
<div class="eg-set">

  <div class="eg-set-head">
    <div>
      <h1 class="dash-title">Store Settings</h1>
      <p class="dash-sub">This is what shoppers see and how couriers find you</p>
    </div>
    <a href="<?= View::url('/vendor/'.e($seller['store_slug']??'')) ?>" target="_blank" class="eg-view-store">
      <i class="bi bi-shop"></i> View storefront
    </a>
  </div>

  <?php if ($flash): ?>
    <div class="eg-flash eg-flash-<?= $flash['type']==='error'?'error':'ok' ?>">
      <i class="bi <?= $flash['type']==='error'?'bi-exclamation-triangle':'bi-check-circle' ?>"></i>
      <span><?= e($flash['msg']) ?></span>
    </div>
  <?php endif; ?>

  <form method="POST" action="<?= View::url('/vendor-panel/settings') ?>" id="eg-settings-form">
    <?= csrf() ?>
    <div class="eg-set-grid">

      <!-- MAIN COLUMN -->
      <div class="eg-set-main">

        <section class="eg-card">
          <div class="eg-card-head">
            <div class="eg-card-icon"><i class="bi bi-shop-window"></i></div>
            <div>
              <h3>Store identity</h3>
              <p>The name and description customers see across the marketplace</p>
            </div>
          </div>

          <div class="eg-avatar-row">
            <div class="eg-avatar" id="eg-avatar"><?= e($initial) ?></div>
            <div class="eg-avatar-meta">
              <div class="eg-avatar-name" id="eg-avatar-name"><?= e($seller['store_name'] ?? 'Your store') ?></div>
              <div class="eg-status-pill eg-status-<?= ($seller['status']??'active')==='active'?'active':'draft' ?>">
                <span class="dot"></span> Store <?= e(ucfirst($seller['status'] ?? 'active')) ?>
              </div>
            </div>
          </div>

          <div class="eg-field-grid">
            <div class="eg-field">
              <label for="f-store-name">Store name (English) <span class="req">*</span></label>
              <input type="text" id="f-store-name" name="store_name" required value="<?= e($seller['store_name'] ?? '') ?>" aria-describedby="h-store-name">
              <p class="eg-help" id="h-store-name">Shown on your storefront, receipts and search results</p>
            </div>
            <div class="eg-field">
              <label for="f-store-name-am">Store name (Amharic)</label>
              <input type="text" id="f-store-name-am" name="store_name_am" value="<?= e($seller['store_name_am'] ?? '') ?>" aria-describedby="h-store-name-am">
              <p class="eg-help" id="h-store-name-am">Used when a customer browses in Amharic</p>
            </div>
          </div>

          <div class="eg-field">
            <label for="f-store-url">Store URL</label>
            <div class="eg-copy-row">
              <input type="text" readonly value="<?= e($storeUrl) ?>" id="f-store-url" aria-describedby="h-store-url">
              <button type="button" class="eg-copy-btn" id="eg-copy-url" aria-label="Copy store link"><i class="bi bi-clipboard" aria-hidden="true"></i></button>
            </div>
            <p class="eg-help" id="h-store-url">Share this link directly with customers</p>
          </div>

          <div class="eg-field">
            <label for="f-description">Description</label>
            <textarea id="f-description" name="description" rows="4" placeholder="Tell shoppers what you sell and what makes your store worth buying from" aria-describedby="h-description"><?= e($seller['description'] ?? '') ?></textarea>
            <p class="eg-help" id="h-description">Appears at the top of your storefront page</p>
          </div>
        </section>

        <section class="eg-card">
          <div class="eg-card-head">
            <div class="eg-card-icon"><i class="bi bi-telephone" aria-hidden="true"></i></div>
            <div>
              <h3>Contact</h3>
              <p>How customers and couriers reach you</p>
            </div>
          </div>
          <div class="eg-field" style="max-width:340px">
            <label for="f-phone">Phone</label>
            <input type="tel" id="f-phone" name="phone" value="<?= e($seller['phone'] ?? '') ?>" placeholder="+251 9xx xxx xxx" aria-describedby="h-phone">
            <p class="eg-help" id="h-phone">Used for order updates and courier pickup coordination</p>
          </div>
        </section>

        <section class="eg-card">
          <div class="eg-card-head">
            <div class="eg-card-icon"><i class="bi bi-geo-alt"></i></div>
            <div>
              <h3>Pickup location</h3>
              <p>Where couriers collect your orders</p>
            </div>
          </div>

          <div class="ap-wrap">
            <div class="ap-search-row">
              <input type="text" id="addr-search" placeholder="Search an address in Ethiopia...">
              <button type="button" id="addr-locate" class="ap-locate-btn"><i class="bi bi-crosshair"></i> Use my location</button>
            </div>
            <div id="addr-results" class="ap-results"></div>
          </div>
          <div id="addr-map" class="ap-map"></div>
          <div class="eg-field" style="margin-top:.85rem">
            <label for="pickup_address" class="sr-only">Pickup address</label>
            <input type="text" name="pickup_address" id="pickup_address" placeholder="Pickup address"
                   value="<?= e($seller['pickup_address'] ?? '') ?>" aria-describedby="h-pickup-address">
            <p class="eg-help" id="h-pickup-address">Confirm the pin matches where couriers should actually collect from</p>
          </div>
          <input type="hidden" name="pickup_lat" id="pickup_lat" value="<?= e($seller['pickup_lat'] ?? '') ?>">
          <input type="hidden" name="pickup_lng" id="pickup_lng" value="<?= e($seller['pickup_lng'] ?? '') ?>">
        </section>

      </div>

      <!-- READINESS RAIL -->
      <aside class="eg-set-aside">
        <div class="eg-readiness">
          <div class="eg-ring" id="eg-ring" style="--pct:<?= $pct ?>">
            <div class="eg-ring-value"><span id="eg-ring-pct"><?= $pct ?></span>%</div>
          </div>
          <div class="eg-readiness-title">Profile completeness</div>
          <p class="eg-readiness-sub">Complete stores earn more customer trust and rank higher in search</p>

          <ul class="eg-checklist" id="eg-checklist">
            <?php foreach ($checks as $c): ?>
              <li data-field="<?= e($c['field']) ?>" class="<?= $c['done'] ? 'done' : '' ?>">
                <i class="bi <?= $c['done'] ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i>
                <?= e($c['label']) ?>
              </li>
            <?php endforeach; ?>
          </ul>

          <button type="submit" class="btn-gold eg-save-btn">
            <i class="bi bi-check-lg"></i> Save changes
          </button>
        </div>
      </aside>

    </div>
  </form>

  <div class="eg-mobile-savebar">
    <button type="button" class="btn-gold" id="eg-mobile-save"><i class="bi bi-check-lg"></i> Save changes</button>
  </div>
</div>

<script>
window.EG_BASE_URL = <?= json_encode(rtrim($GLOBALS['__config']['app']['url'] ?? '', '/')) ?>;
window.EG_GOOGLE_MAPS_KEY = <?= json_encode($GLOBALS['__config']['maps']['google_api_key'] ?? '') ?>;
</script>
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

  // -- live store-name avatar --
  var nameInput = document.getElementById('f-store-name');
  var avatar = document.getElementById('eg-avatar');
  var avatarName = document.getElementById('eg-avatar-name');
  nameInput.addEventListener('input', function () {
    var v = nameInput.value.trim();
    avatar.textContent = v ? v.charAt(0).toUpperCase() : 'S';
    avatarName.textContent = v || 'Your store';
  });

  // -- copy store URL --
  var copyBtn = document.getElementById('eg-copy-url');
  copyBtn.addEventListener('click', function () {
    var input = document.getElementById('f-store-url');
    var done = function () {
      var icon = copyBtn.querySelector('i');
      icon.className = 'bi bi-check-lg';
      setTimeout(function () { icon.className = 'bi bi-clipboard'; }, 1500);
    };
    if (navigator.clipboard) {
      navigator.clipboard.writeText(input.value).then(done);
    } else {
      input.select();
      document.execCommand('copy');
      done();
    }
  });

  // -- live completeness ring --
  var fieldEls = {
    store_name: nameInput,
    store_name_am: document.getElementById('f-store-name-am'),
    description: document.getElementById('f-description'),
    phone: document.getElementById('f-phone'),
    pickup_address: document.getElementById('pickup_address'),
  };
  var ring = document.getElementById('eg-ring');
  var ringPct = document.getElementById('eg-ring-pct');
  var checklist = document.getElementById('eg-checklist');
  var total = Object.keys(fieldEls).length;

  function recompute() {
    var done = 0;
    Object.keys(fieldEls).forEach(function (key) {
      var el = fieldEls[key];
      var filled = el && el.value.trim() !== '';
      var item = checklist.querySelector('[data-field="' + key + '"]');
      if (item) {
        item.classList.toggle('done', filled);
        item.querySelector('i').className = filled ? 'bi bi-check-circle-fill' : 'bi bi-circle';
      }
      if (filled) done++;
    });
    var pct = Math.round((done / total) * 100);
    ring.style.setProperty('--pct', pct);
    ringPct.textContent = pct;
  }

  Object.values(fieldEls).forEach(function (el) {
    if (el) el.addEventListener('input', recompute);
  });

  // -- mobile sticky save --
  document.getElementById('eg-mobile-save').addEventListener('click', function () {
    document.getElementById('eg-settings-form').requestSubmit();
  });
});
</script>

<style>
.eg-set{max-width:1180px}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.eg-set a:focus-visible,
.eg-set button:focus-visible,
.eg-set input:focus-visible,
.eg-set textarea:focus-visible{outline:2px solid var(--eg-gold,#D4AF37);outline-offset:2px}
.eg-set-head{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem;margin-bottom:1.2rem;flex-wrap:wrap}
.eg-view-store{display:inline-flex;align-items:center;gap:.4rem;font-size:.82rem;font-weight:700;color:var(--eg-gold,#D4AF37);text-decoration:none;border:1.5px solid rgba(212,175,55,.28);padding:.5rem .9rem;border-radius:10px;transition:background .2s}
.eg-view-store:hover{background:rgba(212,175,55,.1)}

.eg-flash{display:flex;align-items:center;gap:.6rem;padding:.8rem 1.1rem;border-radius:10px;margin-bottom:1.1rem;font-size:.87rem;border:1px solid currentColor}
.eg-flash-error{background:rgba(239,68,68,.12);color:#ef4444}
.eg-flash-ok{background:rgba(16,185,129,.12);color:#10b981}

.eg-set-grid{display:grid;grid-template-columns:1fr 320px;gap:1.3rem;align-items:start}

.eg-card{background:var(--dark-card);border:1.5px solid var(--dark-border);border-radius:16px;padding:1.5rem;margin-bottom:1.3rem}
.eg-card-head{display:flex;align-items:flex-start;gap:.85rem;margin-bottom:1.3rem;padding-bottom:1.1rem;border-bottom:1px solid rgba(212,175,55,.12)}
.eg-card-icon{flex-shrink:0;width:42px;height:42px;border-radius:11px;background:rgba(212,175,55,.1);border:1.5px solid rgba(212,175,55,.22);color:var(--eg-gold,#D4AF37);display:flex;align-items:center;justify-content:center;font-size:1.1rem}
.eg-card-head h3{margin:0 0 .2rem;font-family:'Sora',sans-serif;font-size:1rem;font-weight:700;color:#f5f5f5}
.eg-card-head p{margin:0;font-size:.8rem;color:var(--dark-muted,#8888A8)}

.eg-avatar-row{display:flex;align-items:center;gap:.9rem;margin-bottom:1.4rem}
.eg-avatar{width:56px;height:56px;border-radius:14px;background:linear-gradient(135deg,#D4AF37,#E8C76B);display:flex;align-items:center;justify-content:center;font-weight:900;font-size:1.4rem;color:#1A1A2E;flex-shrink:0}
.eg-avatar-name{font-weight:800;font-size:1rem;color:#fff;margin-bottom:.3rem}
.eg-status-pill{display:inline-flex;align-items:center;gap:.35rem;font-size:.72rem;font-weight:700;color:#2ecc71}
.eg-status-pill .dot{width:6px;height:6px;border-radius:50%;background:#2ecc71}
.eg-status-pill.eg-status-draft{color:#f59e0b}
.eg-status-pill.eg-status-draft .dot{background:#f59e0b}

.eg-field-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.eg-field{margin-bottom:1.1rem}
.eg-field label{display:block;font-size:.82rem;color:var(--dark-muted,#8888A8);margin-bottom:.4rem;font-weight:600}
.eg-field .req{color:#ef4444}
.eg-field input,.eg-field textarea{width:100%;padding:.65rem .9rem;background:rgba(255,255,255,.05);border:1.5px solid rgba(255,255,255,.1);border-radius:10px;color:#f0f0f0;font-size:.9rem;outline:none;box-sizing:border-box;transition:border-color .2s;font-family:inherit}
.eg-field input:focus,.eg-field textarea:focus{border-color:var(--eg-gold,#D4AF37)}
.eg-field textarea{resize:vertical;min-height:100px}
.eg-help{margin:.35rem 0 0;font-size:.75rem;color:var(--dark-muted,#8888A8)}

.eg-copy-row{display:flex;gap:.5rem}
.eg-copy-row input{opacity:.7}
.eg-copy-btn{flex-shrink:0;width:44px;min-height:44px;border-radius:10px;border:1.5px solid rgba(212,175,55,.25);background:rgba(212,175,55,.08);color:var(--eg-gold,#D4AF37);cursor:pointer;font-size:1rem}
.eg-copy-btn:hover{background:rgba(212,175,55,.16)}

/* readiness rail */
.eg-set-aside{position:sticky;top:1.5rem}
.eg-readiness{position:relative;overflow:hidden;background:var(--dark-card,rgba(9,7,3,.92));border:1.5px solid rgba(212,175,55,.32);border-radius:18px;padding:1.6rem 1.4rem;text-align:center}
.eg-readiness::before{content:'';position:absolute;inset:-30% -30% auto -30%;height:65%;background:radial-gradient(closest-side,rgba(212,175,55,.18),transparent 72%);pointer-events:none}
.eg-readiness > *{position:relative;z-index:1}
.eg-ring{--pct:0;width:118px;height:118px;border-radius:50%;margin:0 auto 1.1rem;background:conic-gradient(var(--eg-gold,#D4AF37) calc(var(--pct)*1%), rgba(255,255,255,.08) 0);display:flex;align-items:center;justify-content:center;position:relative;transition:background .4s ease}
.eg-ring::before{content:'';position:absolute;inset:11px;border-radius:50%;background:#0e0b06}
.eg-ring-value{position:relative;z-index:1;font-family:'Sora',sans-serif;font-weight:800;font-size:1.5rem;color:#fff}
.eg-ring-value span{font-size:1.5rem}
.eg-readiness-title{font-family:'Sora',sans-serif;font-weight:700;font-size:.95rem;color:#fff;margin-bottom:.35rem}
.eg-readiness-sub{font-size:.78rem;color:var(--dark-muted,#8888A8);margin:0 0 1.2rem;line-height:1.5}
.eg-checklist{list-style:none;margin:0 0 1.3rem;padding:0;text-align:left;display:flex;flex-direction:column;gap:.55rem}
.eg-checklist li{display:flex;align-items:center;gap:.55rem;font-size:.82rem;color:var(--dark-muted,#8888A8)}
.eg-checklist li i{font-size:1rem;color:rgba(255,255,255,.2)}
.eg-checklist li.done{color:#e8e8f0}
.eg-checklist li.done i{color:#2ecc71}
.eg-save-btn{width:100%;justify-content:center;min-height:44px}

.eg-mobile-savebar{display:none}

.ap-wrap{position:relative;margin-bottom:.6rem}
.ap-search-row{display:flex;gap:.5rem}
.ap-search-row input{flex:1;padding:.65rem .9rem;background:rgba(255,255,255,.05);border:1.5px solid rgba(255,255,255,.1);border-radius:10px;color:#f0f0f0;font-size:.9rem;outline:none}
.ap-search-row input:focus{border-color:var(--eg-gold,#D4AF37)}
.ap-results{display:none;position:absolute;z-index:500;top:100%;left:0;right:0;background:#1c1208;border:1px solid rgba(212,175,55,.25);border-radius:10px;margin-top:.3rem;max-height:220px;overflow-y:auto;box-shadow:0 10px 30px rgba(0,0,0,.5)}
.ap-result{padding:.6rem .85rem;font-size:.83rem;color:#F7EFD9;cursor:pointer;border-bottom:1px solid rgba(212,175,55,.08)}
.ap-result:last-child{border-bottom:none}
.ap-result:hover{background:rgba(212,175,55,.1)}
.ap-result-empty{padding:.6rem .85rem;font-size:.8rem;color:#7A6840}
.ap-locate-btn{flex-shrink:0;display:flex;align-items:center;gap:.4rem;white-space:nowrap;min-height:44px;background:rgba(212,175,55,.1);border:1.5px solid rgba(212,175,55,.25);color:#D4AF37;border-radius:12px;padding:0 1rem;font-size:.83rem;font-weight:700;cursor:pointer}
.ap-locate-btn:hover{background:rgba(212,175,55,.18)}
.ap-map{height:260px;border-radius:12px;border:1px solid rgba(212,175,55,.18)}

@media (max-width:960px){
  .eg-set-grid{grid-template-columns:1fr}
  .eg-set-aside{position:static;order:-1}
  .eg-readiness{padding:1.3rem}
  .eg-save-btn{display:none}
  .eg-mobile-savebar{display:block;position:fixed;left:0;right:0;bottom:0;padding:.8rem 1rem calc(.8rem + env(safe-area-inset-bottom));background:rgba(10,10,26,.92);backdrop-filter:blur(14px);border-top:1px solid rgba(212,175,55,.2);z-index:200}
  .eg-mobile-savebar button{width:100%;justify-content:center}
  .eg-set{padding-bottom:5rem}
}
@media (max-width:640px){
  .eg-field-grid{grid-template-columns:1fr}
  .eg-set-head{align-items:flex-start}
}
@media (prefers-reduced-motion:reduce){
  .eg-ring,.eg-view-store,.eg-copy-btn,.ap-locate-btn,.eg-field input,.eg-field textarea{transition:none}
}
</style>
<?php
$__content = ob_get_clean();
echo View::capture('_layouts/dark', array_merge(get_defined_vars(), ['__content'=>$__content,'navItems'=>$navItems,'role'=>'vendor','pageTitle'=>$pageTitle]));
