<?php
use App\Core\View;
require __DIR__.'/_layout.php';

$isEdit = !empty($product);
$p      = $product ?? [];
$imgs   = $productImages ?? [];

// Existing image URLs
$imgUrls = array_column($imgs, 'path');

ob_start();
?>
<style>
/* ?? PRODUCT FORM ???????????????????????????????????????? */
.pf-grid{display:grid;grid-template-columns:1fr 340px;gap:1.5rem;align-items:start}
.pf-section{background:rgba(255,255,255,.04);border:1.5px solid rgba(255,255,255,.07);border-radius:20px;overflow:hidden;margin-bottom:1.25rem}
.pf-section-head{display:flex;align-items:center;gap:.75rem;padding:1.1rem 1.4rem;border-bottom:1px solid rgba(255,255,255,.07);background:rgba(255,255,255,.02)}
.pf-section-icon{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.pf-section-title{font-size:.95rem;font-weight:800;color:#fff}
.pf-section-sub{font-size:.75rem;color:var(--dark-muted);margin-top:.1rem}
.pf-body{padding:1.4rem}

/* Form controls */
.fg{margin-bottom:1.1rem}
.fg label{display:block;font-size:.78rem;font-weight:700;color:var(--dark-muted);margin-bottom:.45rem;text-transform:uppercase;letter-spacing:.05em}
.fg label span{color:#ef4444;margin-left:.2rem}
.fg input,.fg select,.fg textarea{width:100%;padding:.7rem 1rem;background:rgba(255,255,255,.05);border:1.5px solid rgba(255,255,255,.08);border-radius:12px;color:#f0f0f0;font-size:.9rem;font-family:'Outfit',sans-serif;outline:none;transition:border-color .2s,box-shadow .2s;box-sizing:border-box}
.fg input:focus,.fg select:focus,.fg textarea:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(212,175,55,.12)}
.fg textarea{resize:vertical;min-height:110px;line-height:1.6}
.fg select option{background:#111128}
.fg .hint{font-size:.74rem;color:var(--dark-muted);margin-top:.35rem}
.fg-row{display:grid;gap:1rem}
.fg-row.col2{grid-template-columns:1fr 1fr}
.fg-row.col3{grid-template-columns:1fr 1fr 1fr}

/* Image uploader */
.img-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;margin-bottom:.75rem}
.img-slot{position:relative;aspect-ratio:1;border:2px dashed rgba(255,255,255,.12);border-radius:14px;overflow:hidden;cursor:pointer;transition:border-color .2s,background .2s;background:rgba(255,255,255,.03)}
.img-slot:hover{border-color:var(--gold);background:rgba(212,175,55,.05)}
.img-slot.primary{border-color:var(--gold);border-style:solid;grid-column:1/3;grid-row:1/3;aspect-ratio:unset;min-height:180px}
.img-slot.has-image{border-style:solid;border-color:rgba(212,175,55,.35)}
.img-slot img{width:100%;height:100%;object-fit:cover;display:block}
.img-slot-overlay{position:absolute;inset:0;background:rgba(0,0,0,.55);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.35rem;color:#fff;font-size:.78rem;font-weight:600;opacity:0;transition:opacity .2s}
.img-slot:hover .img-slot-overlay,.img-slot.has-image .img-slot-overlay{opacity:1}
.img-slot-add{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;min-height:70px;color:var(--dark-muted);gap:.4rem;font-size:.75rem;font-weight:600}
.img-slot-add i{font-size:1.4rem;color:rgba(212,175,55,.5)}
.img-url-input{margin-top:.5rem;font-size:.8rem;padding:.5rem .75rem;border-radius:8px}
.primary-badge{position:absolute;top:.5rem;left:.5rem;background:var(--gold);color:#1a1a2e;font-size:.65rem;font-weight:800;padding:.2rem .5rem;border-radius:6px;text-transform:uppercase;letter-spacing:.05em}

/* Price display */
.price-preview{display:flex;align-items:baseline;gap:.75rem;padding:.75rem 1rem;background:rgba(212,175,55,.06);border:1px solid rgba(212,175,55,.18);border-radius:12px;margin-top:.75rem}
.price-main{font-size:1.4rem;font-weight:900;color:var(--gold)}
.price-compare{font-size:1rem;color:var(--dark-muted);text-decoration:line-through}
.price-discount{background:rgba(46,204,113,.12);color:#2ecc71;font-size:.75rem;font-weight:800;padding:.2rem .5rem;border-radius:6px}

/* Stock indicator */
.stock-bar{height:4px;background:rgba(255,255,255,.08);border-radius:99px;margin-top:.5rem;overflow:hidden}
.stock-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#2ecc71,#1abc9c);transition:width .5s}
.stock-fill.low{background:linear-gradient(90deg,#e74c3c,#c0392b)}
.stock-fill.medium{background:linear-gradient(90deg,#f39c12,#e67e22)}

/* Tag input */
.tags-wrap{display:flex;flex-wrap:wrap;gap:.4rem;padding:.5rem;border:1.5px solid rgba(255,255,255,.08);border-radius:12px;background:rgba(255,255,255,.05);min-height:44px;cursor:text}
.tags-wrap:focus-within{border-color:var(--gold);box-shadow:0 0 0 3px rgba(212,175,55,.12)}
.tag-chip{display:inline-flex;align-items:center;gap:.25rem;background:rgba(212,175,55,.15);border:1px solid rgba(212,175,55,.3);color:var(--gold);font-size:.78rem;font-weight:700;padding:.18rem .55rem;border-radius:6px}
.tag-chip button{background:none;border:none;color:var(--gold);cursor:pointer;padding:0;line-height:1;font-size:.85rem}
.tag-input-inner{border:none;outline:none;background:transparent;color:#f0f0f0;font-size:.88rem;font-family:'Outfit',sans-serif;min-width:80px;flex:1}

/* Publish sidebar */
.pub-card{background:rgba(255,255,255,.04);border:1.5px solid rgba(255,255,255,.07);border-radius:20px;overflow:hidden;margin-bottom:1.1rem}
.pub-head{padding:.9rem 1.2rem;border-bottom:1px solid rgba(255,255,255,.07);font-size:.88rem;font-weight:800;color:#fff;display:flex;align-items:center;gap:.5rem}
.pub-body{padding:1.1rem 1.2rem}
.pub-row{display:flex;align-items:center;justify-content:space-between;padding:.55rem 0;border-bottom:1px solid rgba(255,255,255,.04)}
.pub-row:last-child{border-bottom:none}
.pub-label{font-size:.8rem;color:var(--dark-muted);font-weight:600}
.pub-value{font-size:.85rem;color:#f0f0f0;font-weight:700}

/* Toggle switch */
.toggle-wrap{display:flex;align-items:center;gap:.75rem;padding:.6rem 0}
.toggle{position:relative;width:42px;height:24px;flex-shrink:0}
.toggle input{opacity:0;width:0;height:0;position:absolute}
.toggle-slider{position:absolute;inset:0;background:rgba(255,255,255,.1);border-radius:12px;cursor:pointer;transition:background .2s}
.toggle-slider::before{content:'';position:absolute;left:3px;top:3px;width:18px;height:18px;background:#fff;border-radius:50%;transition:transform .2s}
.toggle input:checked~.toggle-slider{background:linear-gradient(135deg,var(--gold),var(--gold-lt))}
.toggle input:checked~.toggle-slider::before{transform:translateX(18px)}
.toggle-label{font-size:.85rem;color:var(--dark-text);font-weight:600}

/* Submit zone */
.submit-zone{display:flex;gap:.75rem;flex-direction:column}
.btn-publish{width:100%;padding:.85rem;background:linear-gradient(135deg,#FFE066,#D4AF37,#B8902E);color:#080604;font-weight:900;font-size:1rem;border:none;border-radius:14px;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:.5rem;box-shadow:0 8px 24px -6px rgba(212,175,55,.5)}
.btn-publish:hover{transform:translateY(-2px);box-shadow:0 14px 30px -8px rgba(212,175,55,.6)}
.btn-draft{width:100%;padding:.7rem;background:rgba(255,255,255,.05);color:var(--dark-muted);font-weight:700;font-size:.88rem;border:1.5px solid rgba(255,255,255,.08);border-radius:12px;cursor:pointer;transition:all .2s}
.btn-draft:hover{background:rgba(255,255,255,.09);color:var(--dark-text)}

/* Flash */
.flash-box{padding:.85rem 1.1rem;border-radius:12px;margin-bottom:1.25rem;border:1.5px solid;font-size:.88rem;display:flex;align-items:center;gap:.5rem}
.flash-error{background:rgba(231,76,60,.1);border-color:rgba(231,76,60,.3);color:#e74c3c}
.flash-success{background:rgba(46,204,113,.1);border-color:rgba(46,204,113,.3);color:#2ecc71}

/* Section nav */
.pf-nav{display:flex;gap:.5rem;margin-bottom:1.25rem;overflow-x:auto;padding-bottom:.25rem}
.pf-nav a{display:inline-flex;align-items:center;gap:.4rem;padding:.45rem .9rem;border-radius:20px;font-size:.8rem;font-weight:700;color:var(--dark-muted);border:1.5px solid transparent;text-decoration:none;white-space:nowrap;transition:all .2s}
.pf-nav a.active,.pf-nav a:hover{color:var(--gold);border-color:rgba(212,175,55,.3);background:rgba(212,175,55,.08)}
</style>

<!-- Page header -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem">
  <div>
    <h1 style="font-size:1.5rem;font-weight:900;color:#fff;margin:0"><?= $isEdit ? 'Edit Product' : 'Add New Product' ?></h1>
    <p style="color:var(--dark-muted);font-size:.85rem;margin:.25rem 0 0"><?= $isEdit ? e(html_entity_decode($p['name_en']??'',ENT_QUOTES,'UTF-8')) : 'Fill in the details to list your product' ?></p>
  </div>
  <a href="<?= View::url('/vendor-panel/products') ?>" class="btn btn-dark btn-sm"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($flash)): ?>
  <div class="flash-box flash-<?= $flash['type']==='error'?'error':'success' ?>">
    <i class="bi bi-<?= $flash['type']==='error'?'exclamation-circle':'check-circle' ?>"></i>
    <?= e($flash['msg']) ?>
  </div>
<?php endif; ?>

<form method="POST" action="<?= View::url('/vendor-panel/products/save') ?>" enctype="multipart/form-data" id="pf">
<input type="hidden" name="id" value="<?= (int)($p['id'] ?? 0) ?>">

<div class="pf-grid">

  <!-- ?? LEFT COLUMN ?? -->
  <div>

    <!-- MEDIA -->
    <div class="pf-section">
      <div class="pf-section-head">
        <div class="pf-section-icon icon-gold"><i class="bi bi-images"></i></div>
        <div>
          <div class="pf-section-title">Product Media</div>
          <div class="pf-section-sub">Add up to 6 photos * Max 5 MB each * JPG/PNG/WEBP</div>
        </div>
      </div>
      <div class="pf-body">
        <div class="img-grid" id="imgGrid">
          <?php for ($slot = 0; $slot < 6; $slot++): ?>
            <?php $existing = $imgUrls[$slot] ?? ''; ?>
            <div class="img-slot<?= $slot===0?' primary':'' ?><?= $existing?' has-image':'' ?>" id="slot<?= $slot ?>" onclick="document.getElementById('urlInput<?= $slot ?>').focus()">
              <?php if ($slot===0): ?><div class="primary-badge"><i class="bi bi-star-fill"></i> Primary</div><?php endif; ?>
              <?php if ($existing): ?>
                <img src="<?= e($existing) ?>" id="preview<?= $slot ?>" alt="">
              <?php else: ?>
                <div class="img-slot-add" id="slotAdd<?= $slot ?>">
                  <i class="bi bi-<?= $slot===0?'cloud-upload':'plus-lg' ?>"></i>
                  <span><?= $slot===0?'Main Photo':'Add photo' ?></span>
                </div>
                <img src="" id="preview<?= $slot ?>" alt="" style="display:none;width:100%;height:100%;object-fit:cover">
              <?php endif; ?>
              <div class="img-slot-overlay">
                <?php if ($existing): ?>
                  <i class="bi bi-pencil" style="font-size:1.2rem"></i>
                  <span>Change</span>
                <?php else: ?>
                  <span>Enter URL below</span>
                <?php endif; ?>
              </div>
            </div>
          <?php endfor; ?>
        </div>
        <!-- Per-slot: file + URL -->
        <?php for ($slot = 0; $slot < 6; $slot++): ?>
          <div style="margin-bottom:.6rem;background:rgba(255,255,255,.025);border:1px solid rgba(255,255,255,.06);border-radius:10px;padding:.55rem .75rem">
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem">
              <span style="font-size:.7rem;color:var(--gold);font-weight:800;min-width:18px"><?= $slot===0?'?':$slot+1 ?></span>
              <span style="font-size:.72rem;color:var(--dark-muted);font-weight:600"><?= $slot===0?'Primary Image':'Gallery '.($slot+1) ?></span>
            </div>
            <div style="display:flex;gap:.5rem;align-items:center">
              <!-- File browse -->
              <label style="flex-shrink:0;display:inline-flex;align-items:center;gap:.35rem;padding:.42rem .75rem;background:rgba(212,175,55,.1);border:1.5px solid rgba(212,175,55,.25);border-radius:8px;color:var(--gold);font-size:.75rem;font-weight:700;cursor:pointer;white-space:nowrap">
                <i class="bi bi-folder2-open"></i> Browse
                <input type="file" name="file_<?= $slot ?>" accept="image/jpeg,image/png,image/webp,image/gif"
                       style="display:none" onchange="previewFromFile(<?= $slot ?>, this)">
              </label>
              <span style="font-size:.7rem;color:var(--dark-muted)">or</span>
              <!-- URL paste -->
              <input type="url" name="path_<?= $slot ?>" id="urlInput<?= $slot ?>"
                     placeholder="Paste image URL..."
                     value="<?= e($imgUrls[$slot] ?? '') ?>"
                     style="flex:1;padding:.42rem .75rem;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:8px;color:#f0f0f0;font-size:.8rem;font-family:'Outfit',sans-serif;outline:none;min-width:0"
                     oninput="previewFromUrl(<?= $slot ?>, this.value)">
            </div>
          </div>
        <?php endfor; ?>
        <p style="font-size:.73rem;color:var(--dark-muted);margin-top:.25rem"><i class="bi bi-info-circle"></i> File uploads are saved to the server. Max 5 MB each. URL paste also works.</p>
      </div>
    </div>

    <!-- PRODUCT INFORMATION -->
    <div class="pf-section">
      <div class="pf-section-head">
        <div class="pf-section-icon icon-blue"><i class="bi bi-card-text"></i></div>
        <div>
          <div class="pf-section-title">Product Information</div>
          <div class="pf-section-sub">Name, SKU, and description</div>
        </div>
      </div>
      <div class="pf-body">
        <div class="fg-row col2">
          <div class="fg">
            <label>Product Name (English)<span>*</span></label>
            <input type="text" name="name_en" required
                   value="<?= e(html_entity_decode($p['name_en']??'',ENT_QUOTES,'UTF-8')) ?>"
                   placeholder="e.g. Premium Ethiopian Coffee Beans"
                   id="nameEnInput" oninput="updateSlugPreview(this.value)">
          </div>
          <div class="fg">
            <label>Product Name (????)</label>
            <input type="text" name="name_am"
                   value="<?= e(html_entity_decode($p['name_am']??'',ENT_QUOTES,'UTF-8')) ?>"
                   placeholder="e.g. ????? ??">
          </div>
        </div>
        <div class="fg-row col2">
          <div class="fg">
            <label>SKU / Product Code</label>
            <input type="text" name="sku" value="<?= e($p['sku']??'') ?>" placeholder="EG-001">
            <div class="hint">Unique identifier for your product</div>
          </div>
          <div class="fg">
            <label>URL Slug</label>
            <input type="text" id="slugPreview" readonly
                   value="<?= e($p['slug']??'') ?>"
                   style="opacity:.55;cursor:default">
            <div class="hint">Auto-generated from product name</div>
          </div>
        </div>
        <div class="fg">
          <label>Description (English)</label>
          <textarea name="description_en" rows="5" placeholder="Describe your product in detail -- quality, origin, usage, dimensions..."><?= e(html_entity_decode($p['description_en']??'',ENT_QUOTES,'UTF-8')) ?></textarea>
        </div>
        <div class="fg">
          <label>Description (????)</label>
          <textarea name="description_am" rows="4" placeholder="???? ????? ????..."><?= e(html_entity_decode($p['description_am']??'',ENT_QUOTES,'UTF-8')) ?></textarea>
        </div>
      </div>
    </div>

    <!-- PRICING -->
    <div class="pf-section">
      <div class="pf-section-head">
        <div class="pf-section-icon icon-green"><i class="bi bi-currency-exchange"></i></div>
        <div>
          <div class="pf-section-title">Pricing</div>
          <div class="pf-section-sub">Set sale and compare prices in Ethiopian Birr</div>
        </div>
      </div>
      <div class="pf-body">
        <div class="fg-row col2">
          <div class="fg">
            <label>Sale Price (ETB)<span>*</span></label>
            <input type="number" name="price" required min="0" step="0.01"
                   id="priceInput"
                   value="<?= (float)($p['price']??0) ?>" placeholder="0.00"
                   oninput="updatePricePreview()">
          </div>
          <div class="fg">
            <label>Compare-at Price (ETB)</label>
            <input type="number" name="compare_price" min="0" step="0.01"
                   id="compareInput"
                   value="<?= (float)($p['compare_price']??0) ?: '' ?>" placeholder="Original price"
                   oninput="updatePricePreview()">
            <div class="hint">Shows crossed-out on storefront = "was Br X"</div>
          </div>
        </div>
        <div id="pricePreview" style="display:none" class="price-preview">
          <span class="price-main" id="ppMain"></span>
          <span class="price-compare" id="ppCompare"></span>
          <span class="price-discount" id="ppDiscount"></span>
        </div>
      </div>
    </div>

    <!-- INVENTORY -->
    <div class="pf-section">
      <div class="pf-section-head">
        <div class="pf-section-icon icon-teal"><i class="bi bi-box-seam"></i></div>
        <div>
          <div class="pf-section-title">Inventory & Stock</div>
          <div class="pf-section-sub">Track quantities and set low-stock alerts</div>
        </div>
      </div>
      <div class="pf-body">
        <div class="fg-row col3">
          <div class="fg">
            <label>Stock Quantity<span>*</span></label>
            <input type="number" name="stock_qty" min="0" id="stockInput"
                   value="<?= (int)($p['stock_qty']??0) ?>" placeholder="0"
                   oninput="updateStockBar()">
          </div>
          <div class="fg">
            <label>Low Stock Alert At</label>
            <input type="number" name="low_stock_alert" min="0"
                   value="<?= (int)($p['low_stock_alert']??5) ?>" placeholder="5">
            <div class="hint">Alert when stock <= this</div>
          </div>
          <div class="fg">
            <label>Weight (grams)</label>
            <input type="number" name="weight_grams" min="0"
                   value="<?= (int)($p['weight_grams']??0) ?: '' ?>" placeholder="e.g. 500">
            <div class="hint">Used for shipping calculations</div>
          </div>
        </div>
        <div class="stock-bar"><div class="stock-fill" id="stockFill" style="width:0%"></div></div>
        <div id="stockLabel" style="font-size:.75rem;color:var(--dark-muted);margin-top:.35rem">Enter quantity above</div>
      </div>
    </div>

  </div><!-- /left column -->

  <!-- ?? RIGHT SIDEBAR ?? -->
  <div>

    <!-- PUBLISH -->
    <div class="pub-card">
      <div class="pub-head"><i class="bi bi-rocket-takeoff" style="color:var(--gold)"></i> Publish</div>
      <div class="pub-body">
        <div class="fg" style="margin-bottom:.9rem">
          <label>Visibility Status</label>
          <select name="status" id="statusSelect" onchange="updateStatusBadge()">
            <option value="active" <?= ($p['status']??'draft')==='active'?'selected':'' ?>>/* U+1F7E2 */ Active -- visible to buyers</option>
            <option value="draft"  <?= ($p['status']??'draft')==='draft' ?'selected':'' ?>>/* U+1F534 */ Draft -- hidden from store</option>
          </select>
        </div>
        <div class="toggle-wrap">
          <label class="toggle">
            <input type="checkbox" name="is_featured" value="1" <?= !empty($p['is_featured'])?'checked':'' ?>>
            <span class="toggle-slider"></span>
          </label>
          <span class="toggle-label">? Featured Product</span>
        </div>
        <div style="font-size:.75rem;color:var(--dark-muted);margin-top:.4rem;margin-left:54px">Appears in homepage featured section</div>
        <div class="submit-zone" style="margin-top:1.2rem">
          <button type="submit" class="btn-publish" name="_action" value="publish">
            <i class="bi bi-rocket-takeoff"></i>
            <?= $isEdit ? 'Save Changes' : 'Publish Product' ?>
          </button>
          <?php if (!$isEdit): ?>
          <button type="submit" class="btn-draft" name="_action" value="draft" onclick="document.getElementById('statusSelect').value='draft'">
            <i class="bi bi-file-earmark"></i> Save as Draft
          </button>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ORGANIZATION -->
    <div class="pub-card">
      <div class="pub-head"><i class="bi bi-folder2-open" style="color:var(--blue)"></i> Organization</div>
      <div class="pub-body">
        <div class="fg">
          <label>Category</label>
          <select name="category_id">
            <option value="">-- No category --</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= $cat['id'] ?>" <?= ($p['category_id']??null)==$cat['id']?'selected':'' ?>>
                <?= e(html_entity_decode($cat['name_en'],ENT_QUOTES,'UTF-8')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg" style="margin-bottom:0">
          <label>Tags <span style="text-transform:none;letter-spacing:0;font-weight:500">(press Enter or comma)</span></label>
          <div class="tags-wrap" id="tagsWrap">
            <input type="text" class="tag-input-inner" id="tagInput" placeholder="Add tag..." onkeydown="handleTagKey(event)">
          </div>
          <input type="hidden" name="tags" id="tagsHidden" value="">
          <div class="hint">e.g. "handmade", "organic", "imported"</div>
        </div>
      </div>
    </div>

    <!-- STORE INFO -->
    <div class="pub-card">
      <div class="pub-head"><i class="bi bi-shop" style="color:var(--teal)"></i> Store</div>
      <div class="pub-body" style="padding:.75rem 1.2rem">
        <div class="pub-row">
          <span class="pub-label">Store</span>
          <span class="pub-value"><?= e($seller['store_name']??'') ?></span>
        </div>
        <div class="pub-row">
          <span class="pub-label">Status</span>
          <span class="status-pill sp-green" style="font-size:.7rem"><?= e(ucfirst($seller['status']??'active')) ?></span>
        </div>
        <div class="pub-row">
          <span class="pub-label">URL</span>
          <a href="<?= View::url('/vendor/'.e($seller['store_slug']??'')) ?>" target="_blank" style="color:var(--gold);font-size:.8rem;text-decoration:none">View storefront ?</a>
        </div>
      </div>
    </div>

    <?php if ($isEdit): ?>
    <!-- DANGER ZONE -->
    <div class="pub-card" style="border-color:rgba(231,76,60,.2)">
      <div class="pub-head" style="color:#e74c3c"><i class="bi bi-exclamation-triangle"></i> Danger Zone</div>
      <div class="pub-body">
        <form method="POST" action="<?= View::url('/vendor-panel/products/'.(int)($p['id'])  .'/delete') ?>"
              onsubmit="return confirm('Permanently delete this product? This cannot be undone.')">
          <button type="submit" class="btn btn-danger" style="width:100%;justify-content:center">
            <i class="bi bi-trash3"></i> Delete Product
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /sidebar -->
</div><!-- /grid -->
</form>

<script>
// ?? Slug preview ??
function slugify(s){return s.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'')}
function updateSlugPreview(v){document.getElementById('slugPreview').value=slugify(v).substring(0,80)}

// ?? Price preview ??
function updatePricePreview(){
  const p=parseFloat(document.getElementById('priceInput').value)||0;
  const c=parseFloat(document.getElementById('compareInput').value)||0;
  const wrap=document.getElementById('pricePreview');
  if(!p){wrap.style.display='none';return;}
  wrap.style.display='flex';
  document.getElementById('ppMain').textContent='Br '+p.toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2});
  if(c>p){
    document.getElementById('ppCompare').textContent='Br '+c.toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2});
    document.getElementById('ppCompare').style.display='';
    const disc=Math.round((1-p/c)*100);
    document.getElementById('ppDiscount').textContent=disc+'% OFF';
    document.getElementById('ppDiscount').style.display='';
  } else {
    document.getElementById('ppCompare').style.display='none';
    document.getElementById('ppDiscount').style.display='none';
  }
}

// ?? Stock bar ??
function updateStockBar(){
  const v=parseInt(document.getElementById('stockInput').value)||0;
  const fill=document.getElementById('stockFill');
  const label=document.getElementById('stockLabel');
  const pct=Math.min(100,Math.round(v/100*100));
  fill.style.width=Math.max(2,pct)+'%';
  fill.className='stock-fill'+(v<=5?' low':v<=20?' medium':'');
  label.textContent=v===0?'?? Out of stock':v<=5?`?? Very low stock (${v} left)`:v<=20?`/* U+1F4E6 */ Low stock (${v} units)`:`? In stock -- ${v} units available`;
  label.style.color=v===0?'#e74c3c':v<=5?'#e67e22':v<=20?'#f39c12':'#2ecc71';
}

// ?? Image file preview ??
function previewFromFile(slot, input){
  if(!input.files||!input.files[0]) return;
  const reader=new FileReader();
  reader.onload=e=>{
    const pre=document.getElementById('preview'+slot);
    const add=document.getElementById('slotAdd'+slot);
    const slotEl=document.getElementById('slot'+slot);
    pre.src=e.target.result;pre.style.display='block';
    if(add)add.style.display='none';
    slotEl.classList.add('has-image');
    // Clear URL input since file takes priority
    document.getElementById('urlInput'+slot).value='';
  };
  reader.readAsDataURL(input.files[0]);
}

// ?? Image URL preview ??
function previewFromUrl(slot,url){
  const pre=document.getElementById('preview'+slot);
  const add=document.getElementById('slotAdd'+slot);
  const slotEl=document.getElementById('slot'+slot);
  if(url){
    pre.src=url;pre.style.display='block';
    if(add)add.style.display='none';
    slotEl.classList.add('has-image');
  } else {
    pre.src='';pre.style.display='none';
    if(add)add.style.display='flex';
    slotEl.classList.remove('has-image');
  }
}

// ?? Tag chips ??
let tags=[];
function handleTagKey(e){
  if(e.key==='Enter'||e.key===','){e.preventDefault();addTag(e.target.value);}
  if(e.key==='Backspace'&&e.target.value===''&&tags.length){removeTag(tags.length-1);}
}
function addTag(raw){
  const t=raw.trim().replace(/,/g,'').toLowerCase();
  if(!t||tags.includes(t))return;
  tags.push(t);renderTags();document.getElementById('tagInput').value='';
}
function removeTag(i){tags.splice(i,1);renderTags();}
function renderTags(){
  const wrap=document.getElementById('tagsWrap');
  Array.from(wrap.querySelectorAll('.tag-chip')).forEach(el=>el.remove());
  const input=document.getElementById('tagInput');
  tags.forEach((t,i)=>{
    const chip=document.createElement('span');
    chip.className='tag-chip';
    chip.innerHTML=`${t}<button type="button" onclick="removeTag(${i})">?</button>`;
    wrap.insertBefore(chip,input);
  });
  document.getElementById('tagsHidden').value=tags.join(',');
}

// Init
document.addEventListener('DOMContentLoaded',()=>{
  updatePricePreview();
  updateStockBar();
  const existing='<?= e(implode(',', array_column($imgs, 'image_url'))) ?>';
  if(existing){existing.split(',').filter(Boolean).forEach((u,i)=>previewFromUrl(i,u));}
});
</script>
<?php
$__content = ob_get_clean();
echo View::capture('_layouts/dark', array_merge(get_defined_vars(), [
    '__content' => $__content,
    'navItems'  => $navItems,
    'role'      => 'vendor',
    'pageTitle' => $pageTitle,
]));
