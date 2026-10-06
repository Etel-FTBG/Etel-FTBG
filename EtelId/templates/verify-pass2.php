<?php
/**
 * Etel-ID Premium — Second Verification Pass v1.0 (NEW v3.10.0)
 * Biometric (face) capture + additional KYC info.
 * Completing this sets kyc_level=2 and unlocks Etel Pay.
 * Standalone page — no WP theme, same visual language as portal.php.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$member = EtelID_Auth::get_current_member();
if ( ! $member ) { wp_redirect( home_url('/login/') ); exit; }

$logo_src   = defined('ETELID_LOGO_B64') ? ETELID_LOGO_B64 : ETELID_URL.'public/images/etel-logo.png';
$org_name   = get_option('etelid_org_name','Etel Group');
$nonce      = wp_create_nonce('etelid_auth');
$ajax       = admin_url('admin-ajax.php');
$kyc_level  = isset($member->kyc_level) ? (int)$member->kyc_level : 0;
$already_done = $kyc_level >= 2;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0">
<title>Verify Your Identity · Etel Pay · <?php echo esc_html($org_name); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( ETELID_URL . 'public/css/etelid-fonts.css?v=' . filemtime( ETELID_PATH . 'public/css/etelid-fonts.css' ) ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( ETELID_URL . 'public/css/etelid-icons.css?v=' . filemtime( ETELID_PATH . 'public/css/etelid-icons.css' ) ); ?>">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --g3:#E8C84A;--g4:#D4AF37;--g5:#C8922A;--g6:#A07020;--g9:#2A1A06;
  --ink:#1A0E00;--ink2:#3A2200;--ink3:#7A5520;
  --glass:rgba(20,10,0,.55);--glass-border:rgba(212,175,55,.28);
  --ok:#22c55e;--err:#ef4444;
}
body{font-family:'JetBrains Mono',monospace;background:radial-gradient(circle at 20% 0%,#3a2400,#0c0700 70%);color:#FFFEF8;min-height:100vh;padding:24px 16px 60px;}
.wrap{max-width:560px;margin:0 auto;}
.head{display:flex;align-items:center;gap:12px;margin-bottom:24px;}
.head img{width:42px;height:42px;border-radius:10px;}
.head h1{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;color:var(--g3);}
.head p{font-size:11px;color:var(--g6);}
.card{background:var(--glass);border:1px solid var(--glass-border);border-radius:18px;padding:22px;backdrop-filter:blur(10px);margin-bottom:18px;}
.card h2{font-family:'Syne',sans-serif;font-size:15px;color:var(--g3);margin-bottom:6px;display:flex;align-items:center;gap:8px;}
.card p.hint{font-size:12px;color:#cbbf9a;margin-bottom:14px;line-height:1.5;}
label{display:block;font-size:11px;color:var(--g4);margin:12px 0 5px;letter-spacing:.04em;}
input,select{width:100%;background:rgba(255,255,255,.06);border:1px solid var(--glass-border);border-radius:8px;padding:10px 12px;color:#fff;font-family:inherit;font-size:13px;}
input:focus,select:focus{outline:none;border-color:var(--g4);}
.cam-box{position:relative;border-radius:14px;overflow:hidden;border:1px solid var(--glass-border);background:#000;aspect-ratio:4/3;display:flex;align-items:center;justify-content:center;}
.cam-box video,.cam-box img,.cam-box canvas{width:100%;height:100%;object-fit:cover;display:block;}
.cam-box.empty::after{content:'📷 Camera preview will appear here';color:#998;font-size:12px;}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:none;border-radius:10px;padding:12px 18px;font-family:'Syne',sans-serif;font-weight:700;font-size:13px;cursor:pointer;width:100%;margin-top:10px;}
.btn-gold{background:linear-gradient(135deg,var(--g3),var(--g5));color:#1A0E00;}
.btn-ghost{background:rgba(255,255,255,.08);color:#fff;border:1px solid var(--glass-border);}
.btn:disabled{opacity:.45;cursor:not-allowed;}
.row{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.msg{font-size:12px;border-radius:8px;padding:10px 12px;margin-top:10px;display:none;}
.msg.err{background:rgba(239,68,68,.12);color:#fca5a5;border:1px solid rgba(239,68,68,.3);display:block;}
.msg.ok{background:rgba(34,197,94,.12);color:#86efac;border:1px solid rgba(34,197,94,.3);display:block;}
.badge-ok{display:inline-flex;align-items:center;gap:8px;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35);color:#86efac;border-radius:10px;padding:14px 16px;font-size:13px;}
a.back{color:var(--g4);font-size:12px;text-decoration:none;}
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <img src="<?php echo esc_attr($logo_src); ?>" alt="">
    <div>
      <h1><?php echo esc_html($org_name); ?> · Identity Verification</h1>
      <p>Pass 2 of 2 — required to unlock Etel Pay</p>
    </div>
  </div>

  <?php if ( $already_done ): ?>
  <div class="card">
    <div class="badge-ok"><i class="ti ti-shield-check"></i> Verification already complete — Etel Pay is unlocked for <?php echo esc_html($member->full_name); ?>.</div>
    <a class="back" href="<?php echo esc_url(home_url('/portal/')); ?>" style="display:block;margin-top:16px;">&larr; Back to my portal</a>
  </div>
  <?php else: ?>

  <div class="card">
    <h2><i class="ti ti-face-id"></i> Step 1 — Face Photo</h2>
    <p class="hint">Look at the camera in good light. This confirms the person holding the account matches the Etel-ID on file.</p>
    <div class="cam-box empty" id="camBox"><video id="camVideo" autoplay playsinline muted style="display:none;"></video></div>
    <canvas id="camCanvas" style="display:none;"></canvas>
    <input type="file" id="camFile" accept="image/*" capture="user" style="display:none;">
    <div class="row">
      <button type="button" class="btn btn-ghost" id="btnStart"><i class="ti ti-camera"></i> Start Camera</button>
      <button type="button" class="btn btn-gold" id="btnCapture" disabled><i class="ti ti-aperture"></i> Capture</button>
    </div>
    <button type="button" class="btn btn-ghost" id="btnRetake" style="display:none;"><i class="ti ti-refresh"></i> Retake</button>
    <button type="button" class="btn btn-ghost" id="btnUseFile" style="margin-top:6px;"><i class="ti ti-upload"></i> No camera? Upload a selfie instead</button>
  </div>

  <div class="card">
    <h2><i class="ti ti-clipboard-text"></i> Step 2 — Additional Information</h2>
    <p class="hint">Basic information required by Etel Pay before money can move on this account.</p>

    <label>Occupation</label>
    <input type="text" id="occupation" placeholder="e.g. Shop owner, Driver, Student">

    <label>Monthly income range</label>
    <select id="income_range">
      <option value="">Select…</option>
      <option value="Under 3,000 ETB">Under 3,000 ETB</option>
      <option value="3,000–10,000 ETB">3,000–10,000 ETB</option>
      <option value="10,000–30,000 ETB">10,000–30,000 ETB</option>
      <option value="30,000–80,000 ETB">30,000–80,000 ETB</option>
      <option value="Over 80,000 ETB">Over 80,000 ETB</option>
    </select>

    <label>Main source of funds</label>
    <select id="source_of_funds">
      <option value="">Select…</option>
      <option value="Salary / Employment">Salary / Employment</option>
      <option value="Business income">Business income</option>
      <option value="Trade / Sales">Trade / Sales</option>
      <option value="Remittance from family">Remittance from family</option>
      <option value="Savings">Savings</option>
      <option value="Other">Other</option>
    </select>

    <div class="row">
      <div>
        <label>Next of kin — full name</label>
        <input type="text" id="next_of_kin_name" placeholder="Full name">
      </div>
      <div>
        <label>Next of kin — phone</label>
        <input type="tel" id="next_of_kin_phone" placeholder="09xx xxx xxx">
      </div>
    </div>

    <button type="button" class="btn btn-gold" id="btnSubmit" style="margin-top:18px;"><i class="ti ti-shield-check"></i> Complete Verification</button>
    <div class="msg" id="msgBox"></div>
  </div>

  <a class="back" href="<?php echo esc_url(home_url('/portal/')); ?>">&larr; I'll finish this later</a>
  <?php endif; ?>
</div>

<?php if ( ! $already_done ): ?>
<script>
(function(){
  var video   = document.getElementById('camVideo');
  var canvas  = document.getElementById('camCanvas');
  var camBox  = document.getElementById('camBox');
  var btnStart   = document.getElementById('btnStart');
  var btnCapture = document.getElementById('btnCapture');
  var btnRetake  = document.getElementById('btnRetake');
  var btnUseFile = document.getElementById('btnUseFile');
  var camFile    = document.getElementById('camFile');
  var btnSubmit  = document.getElementById('btnSubmit');
  var msgBox     = document.getElementById('msgBox');
  var stream     = null;
  var capturedDataUrl = '';

  function showMsg(text, type){
    msgBox.className = 'msg ' + type;
    msgBox.textContent = text;
  }

  btnStart.addEventListener('click', function(){
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      showMsg('Camera not supported in this browser — use "Upload a selfie instead".', 'err');
      return;
    }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false }).then(function(s){
      stream = s;
      video.srcObject = s;
      video.style.display = 'block';
      camBox.classList.remove('empty');
      btnCapture.disabled = false;
    }).catch(function(){
      showMsg('Could not access the camera. Use "Upload a selfie instead" below.', 'err');
    });
  });

  btnCapture.addEventListener('click', function(){
    canvas.width  = video.videoWidth  || 480;
    canvas.height = video.videoHeight || 360;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    capturedDataUrl = canvas.toDataURL('image/jpeg', 0.85);
    var img = document.createElement('img');
    img.src = capturedDataUrl;
    camBox.innerHTML = '';
    camBox.appendChild(img);
    if (stream) { stream.getTracks().forEach(function(t){ t.stop(); }); }
    btnCapture.style.display = 'none';
    btnStart.style.display = 'none';
    btnRetake.style.display = 'block';
  });

  btnRetake.addEventListener('click', function(){
    capturedDataUrl = '';
    camBox.className = 'cam-box empty';
    camBox.innerHTML = '<video id="camVideo" autoplay playsinline muted style="display:none;"></video>';
    video = document.getElementById('camVideo');
    btnRetake.style.display = 'none';
    btnStart.style.display = 'block';
    btnCapture.style.display = 'block';
    btnCapture.disabled = true;
  });

  btnUseFile.addEventListener('click', function(){ camFile.click(); });
  camFile.addEventListener('change', function(){
    var f = camFile.files[0];
    if (!f) return;
    var reader = new FileReader();
    reader.onload = function(e){
      capturedDataUrl = e.target.result;
      camBox.classList.remove('empty');
      camBox.innerHTML = '<img src="' + capturedDataUrl + '">';
      btnRetake.style.display = 'block';
      btnStart.style.display = 'none';
      btnCapture.style.display = 'none';
    };
    reader.readAsDataURL(f);
  });

  btnSubmit.addEventListener('click', function(){
    var occupation       = document.getElementById('occupation').value.trim();
    var income_range      = document.getElementById('income_range').value;
    var source_of_funds   = document.getElementById('source_of_funds').value;
    var next_of_kin_name  = document.getElementById('next_of_kin_name').value.trim();
    var next_of_kin_phone = document.getElementById('next_of_kin_phone').value.trim();

    if (!capturedDataUrl) { showMsg('Please capture or upload a face photo first.', 'err'); return; }
    if (!occupation || !income_range || !source_of_funds || !next_of_kin_name || !next_of_kin_phone) {
      showMsg('Please fill in every field above.', 'err'); return;
    }

    btnSubmit.disabled = true;
    btnSubmit.textContent = 'Submitting…';

    var fd = new FormData();
    fd.append('action', 'etelid_pass2_submit');
    fd.append('nonce', '<?php echo esc_js($nonce); ?>');
    fd.append('face_image', capturedDataUrl);
    fd.append('occupation', occupation);
    fd.append('income_range', income_range);
    fd.append('source_of_funds', source_of_funds);
    fd.append('next_of_kin_name', next_of_kin_name);
    fd.append('next_of_kin_phone', next_of_kin_phone);

    fetch('<?php echo esc_url($ajax); ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (j.success) {
          showMsg('Verification complete! Redirecting to your portal…', 'ok');
          setTimeout(function(){ window.location.href = '<?php echo esc_url(home_url('/portal/')); ?>'; }, 1400);
        } else {
          showMsg((j.data && j.data.message) ? j.data.message : 'Something went wrong. Please try again.', 'err');
          btnSubmit.disabled = false;
          btnSubmit.textContent = 'Complete Verification';
        }
      })
      .catch(function(){
        showMsg('Network error. Please try again.', 'err');
        btnSubmit.disabled = false;
        btnSubmit.textContent = 'Complete Verification';
      });
  });
})();
</script>
<?php endif; ?>
<!-- ETEL AI -- cross-company assistant widget -->
<script src="https://ethelmarket.com/EtelAi/etel-ai-widget.js" data-project="etel-id"></script>
</body>
</html>
