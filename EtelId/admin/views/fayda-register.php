<?php
/**
 * Etel-ID Premium — Admin View: fayda-register.php (NEW v3.10.0)
 *
 * Admin photographs the Fayda ID front + back. OCR (Tesseract.js) and
 * QR decode (jsQR) run client-side in the browser — nothing is sent to
 * any third party. The admin reviews/corrects the extracted fields,
 * then submits a normal form POST (handled by
 * EtelID_Admin::page_fayda_register() → EtelID_Fayda::quick_register()).
 *
 * @package   Etel-ID Premium
 * @since     3.10.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="eid-admin-page">
  <div class="eid-topnav">
    <div class="eid-nav-brand">
      <span class="eid-nav-sig">ኢE</span>
      <div class="eid-nav-title">
        <span class="eid-nav-title-main">Etel-ID Premium</span>
        <span class="eid-nav-title-sub">ኢትኤል ቡድን · Corporate Identity</span>
      </div>
    </div>
    <nav class="eid-nav-links">
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id')); ?>"          class="eid-nav-link">Members</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-issue')); ?>"    class="eid-nav-link">Issue ID</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-fayda')); ?>"    class="eid-nav-link active">🪪 Fayda Quick Register</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-print')); ?>"    class="eid-nav-link">Print</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-log')); ?>"      class="eid-nav-link">Audit Log</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-settings')); ?>" class="eid-nav-link">Settings</a>
    </nav>
    <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id')); ?>" class="eid-btn eid-btn-ghost">
      <i class="ti ti-arrow-left" aria-hidden="true"></i> All Members
    </a>
  </div>

  <div class="eid-content">
    <div class="eid-page-header">
      <div>
        <div class="eid-page-title">🪪 Fayda Quick Register</div>
        <div class="eid-page-subtitle">Photograph the front &amp; back of the Fayda ID — fields below auto-fill. Review, then submit.</div>
      </div>
    </div>

    <div class="eid-notice" style="background:rgba(212,175,55,.08);border-color:rgba(212,175,55,.3);">
      <i class="ti ti-info-circle" aria-hidden="true"></i>
      This reads text from the photos in your browser (nothing is uploaded anywhere during scanning). It is fast admin-assisted intake, <strong>not</strong> a government verification — please check the fields below before submitting.
    </div>

    <?php if ( $result && $message === 'success' ) : ?>
    <div class="eid-success-banner">
      <div class="eid-success-icon"><i class="ti ti-check" aria-hidden="true"></i></div>
      <div class="eid-success-ids">
        <div class="eid-success-id-item">
          <span class="eid-success-id-lbl">Etel-ID</span>
          <span class="eid-success-id-val"><?php echo esc_html($result['member']->etel_id); ?></span>
        </div>
        <div class="eid-success-id-item">
          <span class="eid-success-id-lbl">Phone</span>
          <span class="eid-success-id-val"><?php echo esc_html($result['member']->phone); ?></span>
        </div>
        <div class="eid-success-id-item">
          <span class="eid-success-id-lbl">Temp. Password</span>
          <span class="eid-success-id-val" id="fayda-temp-pass"><?php echo esc_html($result['temp_password']); ?></span>
        </div>
      </div>

      <?php if ( $result['notify']['auto_sent'] ) : ?>
        <div class="eid-notice" style="background:rgba(34,197,94,.1);border-color:rgba(34,197,94,.35);margin-top:10px;">
          <i class="ti ti-circle-check" aria-hidden="true"></i>
          Credentials were sent automatically via <strong><?php echo esc_html(ucfirst($result['notify']['auto_channel'])); ?></strong>.
        </div>
      <?php else: ?>
        <div class="eid-notice eid-notice-err" style="margin-top:10px;">
          <i class="ti ti-alert-circle" aria-hidden="true"></i>
          Could not auto-send (<?php echo esc_html(trim($result['notify']['auto_error']) ?: 'no channel configured'); ?>). Use one of the options below to deliver the credentials manually:
        </div>
      <?php endif; ?>

      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
        <?php if ( $result['notify']['telegram_link'] ) : ?>
        <a href="<?php echo esc_url($result['notify']['telegram_link']); ?>" target="_blank" rel="noopener" class="eid-btn eid-btn-gold">
          <i class="ti ti-brand-telegram" aria-hidden="true"></i> Open Telegram Chat
        </a>
        <?php endif; ?>
        <?php if ( $result['notify']['whatsapp_link'] ) : ?>
        <a href="<?php echo esc_url($result['notify']['whatsapp_link']); ?>" target="_blank" rel="noopener" class="eid-btn eid-btn-ghost">
          <i class="ti ti-brand-whatsapp" aria-hidden="true"></i> Send via WhatsApp
        </a>
        <?php endif; ?>
        <button type="button" class="eid-btn eid-btn-ghost" id="fayda-copy-btn" data-text="<?php echo esc_attr($result['notify']['message_text']); ?>">
          <i class="ti ti-copy" aria-hidden="true"></i> Copy Credentials
        </button>
        <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-fayda')); ?>" class="eid-btn eid-btn-ghost">
          <i class="ti ti-plus" aria-hidden="true"></i> Register Another
        </a>
      </div>
      <p style="font-size:11px;color:#998;margin-top:10px;">Tip: tell the member to change this temporary password the first time they sign in — they'll be prompted automatically.</p>
    </div>
    <?php elseif ( strpos((string)$message,'error:')===0 ) : ?>
    <div class="eid-notice eid-notice-err">
      <i class="ti ti-alert-circle" aria-hidden="true"></i>
      <?php echo esc_html(substr($message,6)); ?>
    </div>
    <?php endif; ?>

    <div class="eid-panel">
      <div class="eid-panel-header"><h3 class="eid-panel-title">1. Capture the Fayda ID</h3></div>
      <div class="eid-panel-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div>
            <label class="eid-field-label">Front of card</label>
            <div class="fayda-shot-box empty" id="fayda-front-box" data-side="front">
              <span class="fayda-shot-hint">📷 Tap to capture / upload front</span>
            </div>
            <input type="file" id="fayda-front-file" accept="image/*" capture="environment" style="display:none;">
          </div>
          <div>
            <label class="eid-field-label">Back of card</label>
            <div class="fayda-shot-box empty" id="fayda-back-box" data-side="back">
              <span class="fayda-shot-hint">📷 Tap to capture / upload back (QR code side)</span>
            </div>
            <input type="file" id="fayda-back-file" accept="image/*" capture="environment" style="display:none;">
          </div>
        </div>
        <button type="button" class="eid-btn eid-btn-gold" id="fayda-extract-btn" style="margin-top:16px;" disabled>
          <i class="ti ti-scan" aria-hidden="true"></i> Extract Info from Photos
        </button>
        <span id="fayda-extract-status" style="margin-left:12px;font-size:12px;color:#D4AF37;"></span>
      </div>
    </div>

    <div class="eid-panel">
      <div class="eid-panel-header"><h3 class="eid-panel-title">2. Member's Photo <span style="font-weight:400;color:#998;font-size:12px;text-transform:none;letter-spacing:normal;">(optional - a real face photo, not another ID shot)</span></h3></div>
      <div class="eid-panel-body">
        <div style="max-width:180px;">
          <div class="fayda-shot-box empty" id="member-photo-box" style="aspect-ratio:1/1;border-radius:50%;">
            <span class="fayda-shot-hint">📷 No photo yet</span>
          </div>
        </div>
        <!-- Three explicit ways in, not one ambiguous file picker:
             capture="user" and capture="environment" tell the browser to
             open the device's actual camera app directly (front or back
             lens), which is what triggers the OS's own camera-permission
             prompt the first time - the plain "Choose from Gallery"
             button has no capture attribute at all, so it opens the
             normal file/photo browser with no camera permission
             involved. All three feed the same hidden photo_url field. -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
          <label class="eid-btn eid-btn-ghost" style="cursor:pointer;font-size:12px;">
            <input type="file" id="member-photo-front" accept="image/*" capture="user" style="display:none;" onchange="wireMemberPhoto(this)">
            🤳 Take Selfie (Front Camera)
          </label>
          <label class="eid-btn eid-btn-ghost" style="cursor:pointer;font-size:12px;">
            <input type="file" id="member-photo-back" accept="image/*" capture="environment" style="display:none;" onchange="wireMemberPhoto(this)">
            📷 Take Photo (Back Camera)
          </label>
          <label class="eid-btn eid-btn-ghost" style="cursor:pointer;font-size:12px;">
            <input type="file" id="member-photo-gallery" accept="image/*" style="display:none;" onchange="wireMemberPhoto(this)">
            🖼️ Choose from Gallery
          </label>
        </div>
        <p style="font-size:11px;color:#998;margin-top:10px;">
          The camera buttons will ask for camera permission the first time they're used - allow it to take the photo directly. Leave this blank entirely and the Fayda ID front photo above is used instead, same as before - but a real face photo works much better for account recognition and the member's own profile than a photo of their ID card.
        </p>
      </div>
    </div>

    <div class="eid-panel">
      <div class="eid-panel-header"><h3 class="eid-panel-title">3. Review &amp; Confirm</h3></div>
      <div class="eid-panel-body">
        <form method="post" id="fayda-form">
          <?php wp_nonce_field('etelid_fayda','_etelid_fayda_nonce'); ?>
          <input type="hidden" name="fayda_front_image" id="fayda_front_image">
          <input type="hidden" name="fayda_back_image"  id="fayda_back_image">
          <input type="hidden" name="fayda_ocr_raw"     id="fayda_ocr_raw">
          <input type="hidden" name="photo_url"         id="photo_url">

          <div class="eid-form-grid">
            <div class="eid-section-head">Detected Identity</div>

            <div class="eid-field-wrap">
              <label class="eid-field-label">Full Name (Latin) <span class="req">*</span></label>
              <input type="text" name="full_name" id="f_full_name" class="eid-input" required placeholder="Auto-filled from photo">
            </div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Full Name (Amharic)</label>
              <input type="text" name="full_name_am" id="f_full_name_am" class="eid-input" placeholder="ሙሉ ስም">
            </div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Date of Birth <span class="req">*</span></label>
              <input type="date" name="dob" id="f_dob" class="eid-input" required>
            </div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Sex</label>
              <select name="sex" id="f_sex" class="eid-select">
                <option value="Male">Male | ወንድ</option>
                <option value="Female">Female | ሴት</option>
                <option value="Other">Other</option>
              </select>
            </div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Nationality</label>
              <input type="text" name="nationality" id="f_nationality" class="eid-input" value="Ethiopian">
            </div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Fayda FIN (government ID number)</label>
              <input type="text" name="fayda_fin" id="f_fayda_fin" class="eid-input" placeholder="12-digit number from card / QR">
            </div>

            <div class="eid-section-head">Address</div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Region</label>
              <select name="region" id="f_region" class="eid-select">
                <option value="">— Select Region —</option>
                <?php foreach ($regions as $r): ?>
                <option value="<?php echo esc_attr($r); ?>"><?php echo esc_html($r); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Zone / City</label>
              <input type="text" name="zone" id="f_zone" class="eid-input">
            </div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Woreda / Sub-city</label>
              <input type="text" name="woreda" id="f_woreda" class="eid-input">
            </div>

            <div class="eid-section-head">Contact &amp; Company (needed to deliver the password)</div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Phone <span class="req">*</span></label>
              <input type="tel" name="phone" id="f_phone" class="eid-input" required placeholder="09xx xxx xxx — used for WhatsApp">
            </div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Email</label>
              <input type="email" name="email" id="f_email" class="eid-input">
            </div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Primary Company</label>
              <select name="primary_company_slug" class="eid-select">
                <?php foreach ($companies as $slug => $co): ?>
                <option value="<?php echo esc_attr($slug); ?>"<?php selected($slug,'etel-pay'); ?>><?php echo esc_html($co['name']); ?> (<?php echo esc_html($co['code']); ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Role</label>
              <input type="text" name="role" class="eid-input" value="Member">
            </div>

            <div class="eid-section-head">Deliver Credentials Via</div>
            <div class="eid-field-wrap" style="grid-column:1/-1;">
              <label class="eid-field-label" style="margin-right:14px;"><input type="radio" name="notify_channel" value="auto" checked> Auto (try Telegram, then WhatsApp)</label>
              <label class="eid-field-label" style="margin-right:14px;"><input type="radio" name="notify_channel" value="telegram"> Telegram only</label>
              <label class="eid-field-label"><input type="radio" name="notify_channel" value="whatsapp"> WhatsApp only</label>
            </div>
          </div>

          <button type="submit" class="eid-btn eid-btn-gold" style="margin-top:18px;">
            <i class="ti ti-id" aria-hidden="true"></i> Create Etel-ID &amp; Send Password
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<style>
.fayda-shot-box{position:relative;border:1.5px dashed rgba(212,175,55,.4);border-radius:12px;aspect-ratio:8/5;background:rgba(212,175,55,.05);display:flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;}
.fayda-shot-box img{width:100%;height:100%;object-fit:cover;}
.fayda-shot-hint{font-size:12px;color:#A07020;text-align:center;padding:10px;}
.fayda-shot-box.has-image{border-style:solid;border-color:#D4AF37;}
</style>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script>
(function(){
  var shots = { front: null, back: null }; /* dataURLs */
  var extractBtn  = document.getElementById('fayda-extract-btn');
  var statusEl    = document.getElementById('fayda-extract-status');

  function wireShot(side){
    var box  = document.getElementById('fayda-' + side + '-box');
    var file = document.getElementById('fayda-' + side + '-file');
    box.addEventListener('click', function(){ file.click(); });
    file.addEventListener('change', function(){
      var f = file.files[0];
      if (!f) return;
      var reader = new FileReader();
      reader.onload = function(e){
        shots[side] = e.target.result;
        box.classList.remove('empty');
        box.classList.add('has-image');
        box.innerHTML = '<img src="' + e.target.result + '">';
        document.getElementById('fayda_' + side + '_image').value = e.target.result;
        extractBtn.disabled = !(shots.front && shots.back);
      };
      reader.readAsDataURL(f);
    });
  }
  wireShot('front');
  wireShot('back');

  /* Member's own photo - separate from the two ID-document shots above.
     Three separate <input> triggers (front camera / back camera /
     gallery) all funnel through this one shared handler into the same
     preview circle and the same photo_url hidden field - not
     wireShot(), which is hardcoded to the fayda_front/back_image field
     names and the extract-button gating logic that only cares about
     the ID photos. */
  window.wireMemberPhoto = function(input){
    var f = input.files[0];
    if (!f) return;
    var box = document.getElementById('member-photo-box');
    var reader = new FileReader();
    reader.onload = function(e){
      box.classList.remove('empty');
      box.classList.add('has-image');
      box.innerHTML = '<img src="' + e.target.result + '">';
      document.getElementById('photo_url').value = e.target.result;
    };
    reader.readAsDataURL(f);
  };

  /* ── QR decode (back side) ── */
  function decodeQR(dataUrl, cb){
    var img = new Image();
    img.onload = function(){
      var canvas = document.createElement('canvas');
      canvas.width = img.width; canvas.height = img.height;
      var ctx = canvas.getContext('2d');
      ctx.drawImage(img, 0, 0);
      try {
        var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
        var code = (typeof jsQR === 'function') ? jsQR(imageData.data, canvas.width, canvas.height) : null;
        cb(code ? code.data : '');
      } catch(e) { cb(''); }
    };
    img.onerror = function(){ cb(''); };
    img.src = dataUrl;
  }

  /* ── Heuristic field parser ── */
  function digitsOnly(s){ return (s || '').replace(/[^0-9]/g, ''); }

  function findFin(text, qrText){
    var qrDigits = digitsOnly(qrText);
    if (qrDigits.length >= 10 && qrDigits.length <= 13) return qrDigits.slice(0,12);
    var matches = (text || '').match(/(\d[\d\s\-]{8,18}\d)/g) || [];
    for (var i=0;i<matches.length;i++){
      var d = digitsOnly(matches[i]);
      if (d.length >= 10 && d.length <= 13) return d.slice(0,12);
    }
    return '';
  }

  function findDob(text){
    var m = (text || '').match(/(\d{1,2})[\/\.\-](\d{1,2})[\/\.\-](\d{4})/);
    if (m) {
      var dd = ('0'+m[1]).slice(-2), mm = ('0'+m[2]).slice(-2), yyyy = m[3];
      return yyyy + '-' + mm + '-' + dd;
    }
    m = (text || '').match(/(\d{4})[\/\.\-](\d{1,2})[\/\.\-](\d{1,2})/);
    if (m) {
      var mm2 = ('0'+m[2]).slice(-2), dd2 = ('0'+m[3]).slice(-2);
      return m[1] + '-' + mm2 + '-' + dd2;
    }
    return '';
  }

  function findSex(text){
    if (/\bfemale\b/i.test(text) || /ሴት/.test(text)) return 'Female';
    if (/\bmale\b/i.test(text) || /ወንድ/.test(text)) return 'Male';
    return '';
  }

  var IGNORE_LINES = /federal|democratic|republic|ethiopia|national|identification|identity|card|fayda|sex|date of birth|nationality|expiry|issued/i;

  function findName(text){
    var lines = (text || '').split('\n').map(function(l){ return l.trim(); }).filter(Boolean);
    var best = '';
    for (var i=0;i<lines.length;i++){
      var l = lines[i];
      if (IGNORE_LINES.test(l)) continue;
      if (/^[A-Za-z\u1200-\u137F\s\.\-]{4,60}$/.test(l) && l.split(' ').length >= 2) {
        if (l.length > best.length) best = l;
      }
    }
    return best;
  }

  /* ── Run OCR + QR, fill the review form ── */
  extractBtn.addEventListener('click', function(){
    extractBtn.disabled = true;
    statusEl.textContent = 'Reading QR code…';

    decodeQR(shots.back, function(qrText){
      statusEl.textContent = 'Scanning text (this can take 10–20s)…';

      var ocrLangs = 'eng+amh';
      function runOcr(langs){
        return Promise.all([
          Tesseract.recognize(shots.front, langs),
          Tesseract.recognize(shots.back, langs)
        ]);
      }

      runOcr(ocrLangs).catch(function(){
        statusEl.textContent = 'Amharic OCR unavailable, retrying with English only…';
        return runOcr('eng');
      }).then(function(results){
        var frontText = results[0] && results[0].data ? results[0].data.text : '';
        var backText  = results[1] && results[1].data ? results[1].data.text : '';
        var combined  = frontText + '\n' + backText + '\n' + qrText;

        document.getElementById('f_full_name').value     = document.getElementById('f_full_name').value || findName(frontText) || findName(backText);
        var dob = findDob(combined);
        if (dob) document.getElementById('f_dob').value = dob;
        var sex = findSex(combined);
        if (sex) document.getElementById('f_sex').value = sex;
        var fin = findFin(combined, qrText);
        if (fin) document.getElementById('f_fayda_fin').value = fin;

        document.getElementById('fayda_ocr_raw').value = combined.slice(0, 4000);
        statusEl.textContent = '✓ Extraction complete — please review the fields below before submitting.';
        statusEl.style.color = '#22c55e';
        extractBtn.disabled = false;
      }).catch(function(err){
        statusEl.textContent = 'Could not read the photos automatically — please fill in the fields below by hand.';
        statusEl.style.color = '#ef4444';
        extractBtn.disabled = false;
      });
    });
  });

  /* ── Copy credentials button (on the result screen) ── */
  var copyBtn = document.getElementById('fayda-copy-btn');
  if (copyBtn) {
    copyBtn.addEventListener('click', function(){
      var text = copyBtn.getAttribute('data-text') || '';
      if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function(){
          copyBtn.innerHTML = '<i class="ti ti-check" aria-hidden="true"></i> Copied!';
          setTimeout(function(){ copyBtn.innerHTML = '<i class="ti ti-copy" aria-hidden="true"></i> Copy Credentials'; }, 2000);
        });
      }
    });
  }
})();
</script>
