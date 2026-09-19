<?php
/**
 * Etel-ID Premium — Auth Page (Login / Sign Up)
 *
 * @package    Etel-ID Premium
 * @version    2.1.0
 * @author     Etel Group · ኢትኤል ቡድን
 * @since      2.1.0
 *
 * Loaded at /login/  and  /signup/
 * Standalone — no WP theme header/footer.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* Check if coming from wp-admin */
$redirect_to = isset( $_GET['redirect'] ) ? sanitize_text_field( $_GET['redirect'] ) : '';
$is_wpadmin_redirect = ( $redirect_to === 'wpadmin' );

/* Redirect if already logged in */
if ( EtelID_Auth::is_logged_in() ) {
    wp_redirect( home_url( '/portal/' ) );
    exit;
}

$regions         = EtelID_Generator::regions();
$zones_by_region = EtelID_Generator::zones_by_region();
$blood           = EtelID_Generator::blood_types();
$org_name  = get_option( 'etelid_org_name', 'Etel Group' );
$org_site  = get_option( 'etelid_org_website', 'etelgroup.com' );
$init_tab  = ( get_query_var('etelid_page') === 'signup' ) ? 'signup' : 'login';

/* Minimal standalone HTML */
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Etel-ID — <?php echo $init_tab === 'signup' ? 'Create Account' : 'Sign In'; ?> · <?php echo esc_html($org_name); ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
<style><?php echo file_get_contents(ETELID_PATH.'public/css/etelid-auth.css'); ?></style>
</head>
<body>

<?php
$logo_src = defined('ETELID_LOGO_B64') ? ETELID_LOGO_B64 : ETELID_URL . 'public/images/etel-logo.png';
$features = array(
  array('🪪', 'Official Etel Group Corporate ID'),
  array('🔐', 'Biometric-grade security'),
  array('🏢', 'Linked across all 8 companies'),
  array('📱', 'Digital & printable card'),
);
?>
<div class="eid-auth-page">

  <!-- ── LEFT PANEL ── -->
  <div class="eid-auth-left">
    <div class="eid-auth-orb eid-auth-orb-1"></div>
    <div class="eid-auth-orb eid-auth-orb-2"></div>
    <div class="eid-auth-brand">
      <img src="<?php echo esc_attr($logo_src); ?>" alt="" class="eid-auth-brand-logo">
      <div class="eid-auth-brand-sig">ኢE</div>
      <div class="eid-auth-brand-name"><?php echo esc_html($org_name); ?></div>
      <div class="eid-auth-brand-sub">ኢትኤል ቡድን · Corporate ID System</div>
      <div class="eid-auth-brand-desc">
        The official digital identity card for all Etel Group employees, customers and partners across Ethiopia.
      </div>
      <!-- Animated mock card -->
      <div class="eid-auth-mock-card">
        <div class="eid-auth-mock-inner">
          <div class="eid-auth-mock-photo">👤</div>
          <div class="eid-auth-mock-lines">
            <div class="eid-auth-mock-line gold"></div>
            <div class="eid-auth-mock-line med"></div>
            <div class="eid-auth-mock-line long"></div>
            <div class="eid-auth-mock-line short"></div>
            <div class="eid-auth-mock-line med"></div>
          </div>
        </div>
        <div class="eid-auth-mock-footer"></div>
      </div>
      <!-- Feature pills -->
      <div class="eid-auth-features">
        <?php foreach ($features as $f): ?>
        <div class="eid-auth-feature">
          <span><?php echo $f[0]; ?></span>
          <span><?php echo esc_html($f[1]); ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- ── RIGHT PANEL ── -->
  <div class="eid-auth-right">
  <div class="eid-auth-card">

    <!-- Holographic top strip -->
    <div class="eid-auth-holo"></div>

    <!-- Card header -->
    <div class="eid-auth-head">
      <?php if ( $is_wpadmin_redirect ) : ?>
      <div style="background:rgba(212,175,55,.12);border:1px solid rgba(212,175,55,.35);border-radius:8px;padding:10px 14px;margin-bottom:14px;display:flex;align-items:center;gap:10px;text-align:left;">
        <span style="font-size:20px;flex-shrink:0;">🔐</span>
        <div>
          <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:800;color:var(--ink);">Admin Access Required</div>
          <div style="font-size:12px;color:var(--ink3);">Sign in with your admin Etel-ID to access WP Admin.</div>
        </div>
      </div>
      <?php endif; ?>
      <div class="eid-auth-logo">
        <img src="<?php echo esc_attr($logo_src); ?>" alt="" class="eid-auth-logo-img">
        <span class="eid-auth-org">ኢትኤል ቡድን · Etel Group</span>
      </div>
      <div class="eid-auth-title" id="auth-title">
        <?php echo $init_tab === 'signup' ? 'Create Your Etel-ID' : 'Welcome Back'; ?>
      </div>
      <div class="eid-auth-subtitle" id="auth-subtitle">
        <?php echo $init_tab === 'signup' ? 'Sign up with your phone number' : 'Sign in to your Etel Group account'; ?>
      </div>
    </div>

    <!-- Tabs -->
    <div class="eid-auth-tabs">
      <button class="eid-auth-tab <?php echo $init_tab==='login'?'active':''; ?>" id="tab-login" onclick="switchTab('login')">
        Sign In
      </button>
      <button class="eid-auth-tab <?php echo $init_tab==='signup'?'active':''; ?>" id="tab-signup" onclick="switchTab('signup')">
        Create Account
      </button>
    </div>

    <div class="eid-auth-body">

      <!-- Global notice -->
      <div class="eid-auth-notice eid-auth-notice-err" id="auth-notice-err">
        <i class="ti ti-alert-circle" aria-hidden="true"></i>
        <span id="auth-notice-err-text"></span>
      </div>
      <div class="eid-auth-notice eid-auth-notice-ok" id="auth-notice-ok">
        <i class="ti ti-check" aria-hidden="true"></i>
        <span id="auth-notice-ok-text"></span>
      </div>

      <!-- ══ LOGIN FORM ══ -->
      <div id="panel-login" <?php echo $init_tab!=='login'?'style="display:none"':''; ?>>
        <div class="eid-af-row-1">
          <div class="eid-af-field">
            <label class="eid-af-label"><i class="ti ti-phone"></i> Phone Number <span class="req">*</span></label>
            <div class="eid-af-input-wrap">
              <i class="ti ti-phone eid-af-input-icon"></i>
              <input type="tel" id="login-phone" class="eid-af-input" placeholder="+251 9XX XXX XXX" autocomplete="tel">
            </div>
          </div>
        </div>
        <div class="eid-af-row-1">
          <div class="eid-af-field">
            <label class="eid-af-label"><i class="ti ti-lock"></i> Password <span class="req">*</span></label>
            <div class="eid-af-input-wrap">
              <i class="ti ti-lock eid-af-input-icon"></i>
              <input type="password" id="login-password" class="eid-af-input" placeholder="Your password" autocomplete="current-password">
              <button type="button" class="eid-pw-toggle" onclick="togglePw('login-password',this)" aria-label="Toggle password visibility">
                <i class="ti ti-eye"></i>
              </button>
            </div>
          </div>
        </div>
        <!-- Remember Me -->
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
          <input type="checkbox" id="login-remember" style="width:16px;height:16px;accent-color:var(--g5);cursor:pointer;">
          <label for="login-remember" style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--ink2);cursor:pointer;letter-spacing:.5px;">
            Keep me signed in for 30 days
          </label>
        </div>
        <div style="text-align:right;margin-bottom:14px;">
          <a href="<?php echo esc_url(home_url('/reset-password/')); ?>" style="color:var(--ag-lt);cursor:pointer;font-family:'JetBrains Mono',monospace;font-size:11.5px;letter-spacing:.3px;text-decoration:none;">Forgot password?</a>
        </div>
        <button class="eid-auth-submit" id="login-btn" onclick="doLogin()">
          <i class="ti ti-login" style="margin-right:6px"></i> Sign In
        </button>
        <button class="eid-auth-submit" id="bio-login-btn" onclick="doBioLogin()"
                style="display:none;margin-top:10px;background:transparent;border:1.5px solid var(--g5);color:var(--g5);">
          <i class="ti ti-fingerprint" style="margin-right:6px"></i> Sign in with Face / Fingerprint
        </button>
        <div class="eid-auth-divider"><span>OR</span></div>
        <p style="text-align:center;font-size:13px;color:var(--at3)">
          Don't have an account?
          <button onclick="switchTab('signup')" style="background:none;border:none;color:var(--ag-lt);cursor:pointer;font-family:'Syne',sans-serif;font-weight:800;font-size:13px">Create one →</button>
        </p>
      </div>

      <!-- ══ SIGNUP FORM ══ -->
      <div id="panel-signup" <?php echo $init_tab!=='signup'?'style="display:none"':''; ?>>

        <!-- Step dots -->
        <div class="eid-reg-dots">
          <div class="eid-reg-dot active" id="dot-1" data-step="1"></div>
          <div class="eid-reg-dot" id="dot-2" data-step="2"></div>
          <div class="eid-reg-dot" id="dot-3" data-step="3"></div>
        </div>

        <!-- ── Step 1: Phone + Password ── -->
        <div id="reg-step-1">
          <div class="eid-af-row-1">
            <div class="eid-af-field">
              <label class="eid-af-label"><i class="ti ti-phone"></i> Phone Number <span class="req">*</span></label>
              <div class="eid-af-input-wrap">
                <i class="ti ti-phone eid-af-input-icon"></i>
                <input type="tel" id="reg-phone" class="eid-af-input" placeholder="+251 9XX XXX XXX" autocomplete="tel">
              </div>
              <div class="eid-af-err" id="err-reg-phone"></div>
            </div>
          </div>
          <div class="eid-af-row-1">
            <div class="eid-af-field">
              <label class="eid-af-label"><i class="ti ti-lock"></i> Password <span class="req">*</span></label>
              <div class="eid-af-input-wrap">
                <i class="ti ti-lock eid-af-input-icon"></i>
                <input type="password" id="reg-password" class="eid-af-input" placeholder="Min 8 characters">
                <button type="button" class="eid-pw-toggle" onclick="togglePw('reg-password',this)" aria-label="Toggle">
                  <i class="ti ti-eye"></i>
                </button>
              </div>
              <!-- Strength meter -->
              <div class="eid-pw-strength" id="pw-strength-wrap" style="display:none">
                <div class="eid-pw-strength-bar">
                  <div class="eid-pw-strength-fill" id="pw-strength-fill"></div>
                </div>
                <span class="eid-pw-strength-lbl" id="pw-strength-lbl"></span>
              </div>
              <!-- Live requirements checklist -->
              <div class="eid-pw-checklist" id="pw-checklist" style="display:none;font-size:11px;margin-top:6px;line-height:1.7;font-family:'JetBrains Mono',monospace">
                <div id="pwc-len" data-label="At least 8 characters" style="color:var(--ink3)">[ ] At least 8 characters</div>
                <div id="pwc-upper" data-label="One uppercase letter (A-Z)" style="color:var(--ink3)">[ ] One uppercase letter (A-Z)</div>
                <div id="pwc-lower" data-label="One lowercase letter (a-z)" style="color:var(--ink3)">[ ] One lowercase letter (a-z)</div>
                <div id="pwc-num" data-label="One number (0-9)" style="color:var(--ink3)">[ ] One number (0-9)</div>
                <div id="pwc-special" data-label="One special character (!@#$%...)" style="color:var(--ink3)">[ ] One special character (!@#$%...)</div>
              </div>
              <div class="eid-af-err" id="err-reg-password"></div>
            </div>
          </div>
          <div class="eid-af-row-1">
            <div class="eid-af-field">
              <label class="eid-af-label"><i class="ti ti-lock-check"></i> Confirm Password <span class="req">*</span></label>
              <div class="eid-af-input-wrap">
                <i class="ti ti-lock-check eid-af-input-icon"></i>
                <input type="password" id="reg-password-confirm" class="eid-af-input" placeholder="Repeat password">
              </div>
              <div class="eid-af-err" id="err-reg-password-confirm"></div>
            </div>
          </div>
          <div class="eid-step-nav">
            <button class="eid-step-next" onclick="regNext(1)">
              Continue <i class="ti ti-arrow-right" style="margin-left:4px"></i>
            </button>
          </div>
        </div>

        <!-- ── Step 2: Personal info ── -->
        <div id="reg-step-2" style="display:none">
          <div class="eid-af-row">
            <div class="eid-af-field">
              <label class="eid-af-label">Full Name <span class="req">*</span></label>
              <div class="eid-af-input-wrap">
                <i class="ti ti-user eid-af-input-icon"></i>
                <input type="text" id="reg-full-name" class="eid-af-input" placeholder="Latin name" oninput="document.getElementById('warn-reg-full-name').style.display='none'">
              </div>
              <div class="eid-af-err" id="err-reg-full-name"></div>
              <div id="warn-reg-full-name" style="display:none;font-size:11px;color:#b45309;margin-top:4px;font-family:'JetBrains Mono',monospace"></div>
            </div>
            <div class="eid-af-field">
              <label class="eid-af-label">Amharic Name</label>
              <input type="text" id="reg-full-name-am" class="eid-af-input no-icon" placeholder="ሙሉ ስም">
              <div class="eid-af-err" id="err-reg-full-name-am"></div>
            </div>
          </div>
          <div class="eid-af-row">
            <div class="eid-af-field">
              <label class="eid-af-label">Date of Birth <span class="req">*</span></label>
              <input type="date" id="reg-dob" class="eid-af-input no-icon" oninput="updateAgeHint(); document.getElementById('warn-reg-full-name').style.display='none';">
              <div class="eid-af-age-hint" id="reg-age-hint" style="font-size:11px;color:var(--ink3);margin-top:4px;font-family:'JetBrains Mono',monospace"></div>
              <div class="eid-af-err" id="err-reg-dob"></div>
            </div>
            <div class="eid-af-field">
              <label class="eid-af-label">Sex</label>
              <select id="reg-sex" class="eid-af-select no-icon">
                <option value="Male">Male | ወንድ</option>
                <option value="Female">Female | ሴት</option>
                <option value="Other">Other</option>
              </select>
            </div>
          </div>
          <div class="eid-af-row">
            <div class="eid-af-field">
              <label class="eid-af-label">Blood Type</label>
              <select id="reg-blood" class="eid-af-select no-icon">
                <option value="">— Optional —</option>
                <?php foreach ($blood as $bt): ?>
                <option value="<?php echo esc_attr($bt); ?>"><?php echo esc_html($bt); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="eid-af-field">
              <label class="eid-af-label">Email</label>
              <div class="eid-af-input-wrap">
                <i class="ti ti-mail eid-af-input-icon"></i>
                <input type="email" id="reg-email" class="eid-af-input" placeholder="Optional">
              </div>
              <div class="eid-af-err" id="err-reg-email"></div>
            </div>
          </div>
          <div class="eid-af-row">
            <div class="eid-af-field">
              <label class="eid-af-label">Region <span class="req">*</span></label>
              <select id="reg-region" class="eid-af-select no-icon" onchange="populateZones()">
                <option value="">— Select —</option>
                <?php foreach ($regions as $r): ?>
                <option value="<?php echo esc_attr($r); ?>"><?php echo esc_html($r); ?></option>
                <?php endforeach; ?>
              </select>
              <div class="eid-af-err" id="err-reg-region"></div>
            </div>
            <div class="eid-af-field">
              <label class="eid-af-label">Zone / City <span class="req">*</span></label>
              <select id="reg-zone" class="eid-af-select no-icon" disabled>
                <option value="">— Select a region first —</option>
              </select>
              <div class="eid-af-err" id="err-reg-zone"></div>
            </div>
          </div>
          <div class="eid-step-nav">
            <button class="eid-step-back" onclick="regBack(2)"><i class="ti ti-arrow-left"></i><span class="eid-step-back-txt">Back</span></button>
            <button class="eid-step-next" style="flex:1" onclick="regNext(2)">
              Continue <i class="ti ti-arrow-right" style="margin-left:4px"></i>
            </button>
          </div>
        </div>

        <!-- -- Step 3: Profile photo + Fayda ID (front/back) -- -->
        <div id="reg-step-3" style="display:none">
          <div class="eid-cam-field">
            <label class="eid-af-label"><i class="ti ti-camera"></i> Profile Photo <span class="req">*</span></label>
            <div class="eid-cam-box round" id="cam-box-photo"></div>
            <input type="file" id="cam-file-cam-photo" accept="image/*" capture="user" style="display:none" onchange="camFileChange('photo', this)">
            <input type="file" id="cam-file-photo" accept="image/*" style="display:none" onchange="camFileChange('photo', this)">
            <div class="eid-cam-btns" id="cam-btns-photo">
              <button type="button" class="eid-cam-btn" onclick="document.getElementById('cam-file-cam-photo').click()"><i class="ti ti-camera"></i> Open Camera</button>
            </div>
            <button type="button" class="eid-cam-btn-ghost" id="cam-retake-photo" style="display:none;width:100%" onclick="camRetake('photo')"><i class="ti ti-refresh"></i> Retake</button>
            <button type="button" class="eid-cam-btn-ghost" style="width:100%" onclick="document.getElementById('cam-file-photo').click()"><i class="ti ti-upload"></i> Or upload a photo</button>
            <div class="eid-af-err" id="err-reg-photo"></div>
          </div>

          <div class="eid-cam-field">
            <label class="eid-af-label"><i class="ti ti-id-badge-2"></i> Fayda ID - Front Side <span class="req">*</span></label>
            <div class="eid-cam-box" id="cam-box-fayda-front"><div class="eid-cam-guide"></div></div>
            <input type="file" id="cam-file-cam-fayda-front" accept="image/*" capture="environment" style="display:none" onchange="camFileChange('fayda-front', this)">
            <input type="file" id="cam-file-fayda-front" accept="image/*" style="display:none" onchange="camFileChange('fayda-front', this)">
            <div class="eid-cam-btns" id="cam-btns-fayda-front">
              <button type="button" class="eid-cam-btn" onclick="document.getElementById('cam-file-cam-fayda-front').click()"><i class="ti ti-camera"></i> Open Camera</button>
            </div>
            <button type="button" class="eid-cam-btn-ghost" id="cam-retake-fayda-front" style="display:none;width:100%" onclick="camRetake('fayda-front')"><i class="ti ti-refresh"></i> Retake</button>
            <button type="button" class="eid-cam-btn-ghost" style="width:100%" onclick="document.getElementById('cam-file-fayda-front').click()"><i class="ti ti-upload"></i> Or upload a photo</button>
            <div class="eid-af-err" id="err-reg-fayda-front"></div>
          </div>

          <div class="eid-cam-field">
            <label class="eid-af-label"><i class="ti ti-id-badge-2"></i> Fayda ID - Back Side <span class="req">*</span></label>
            <div class="eid-cam-box" id="cam-box-fayda-back"><div class="eid-cam-guide"></div></div>
            <input type="file" id="cam-file-cam-fayda-back" accept="image/*" capture="environment" style="display:none" onchange="camFileChange('fayda-back', this)">
            <input type="file" id="cam-file-fayda-back" accept="image/*" style="display:none" onchange="camFileChange('fayda-back', this)">
            <div class="eid-cam-btns" id="cam-btns-fayda-back">
              <button type="button" class="eid-cam-btn" onclick="document.getElementById('cam-file-cam-fayda-back').click()"><i class="ti ti-camera"></i> Open Camera</button>
            </div>
            <button type="button" class="eid-cam-btn-ghost" id="cam-retake-fayda-back" style="display:none;width:100%" onclick="camRetake('fayda-back')"><i class="ti ti-refresh"></i> Retake</button>
            <button type="button" class="eid-cam-btn-ghost" style="width:100%" onclick="document.getElementById('cam-file-fayda-back').click()"><i class="ti ti-upload"></i> Or upload a photo</button>
            <div class="eid-af-err" id="err-reg-fayda-back"></div>
          </div>

          <div class="eid-step-nav">
            <button class="eid-step-back" onclick="regBack(3)"><i class="ti ti-arrow-left"></i><span class="eid-step-back-txt">Back</span></button>
            <button class="eid-auth-submit" style="flex:1;margin-top:0" id="reg-submit-btn" onclick="regNext(3)">
              <i class="ti ti-id" style="margin-right:6px"></i>
              <span id="reg-submit-lbl">Create My Etel-ID</span>
            </button>
          </div>
        </div>

        <p style="text-align:center;font-size:12px;color:var(--at3);margin-top:14px">
          Already have an account?
          <button onclick="switchTab('login')" style="background:none;border:none;color:var(--ag-lt);cursor:pointer;font-family:'Syne',sans-serif;font-weight:800;font-size:12px">Sign in →</button>
        </p>
      </div>

    </div><!-- /.eid-auth-body -->

    <!-- Footer -->
    <div class="eid-auth-card-footer">
      <?php echo esc_html($org_name); ?> · <?php echo esc_html($org_site); ?> · v<?php echo ETELID_VERSION; ?>
    </div>

  </div><!-- /.eid-auth-card -->
  </div><!-- /.eid-auth-right -->
</div><!-- /.eid-auth-page -->

<script>
var _NONCE   = '<?php echo esc_js(wp_create_nonce("etelid_auth")); ?>';
var _AJAX    = '<?php echo esc_js(admin_url("admin-ajax.php")); ?>';
var _PORTAL  = '<?php echo esc_js(home_url("/portal/")); ?>';
var _ZONES_BY_REGION = <?php echo wp_json_encode( $zones_by_region ); ?>;
var _regStep = 1;

/* ── Tab switch ─────────────────────────────────── */
function switchTab(tab) {
  document.getElementById('panel-login').style.display  = tab==='login'  ? '' : 'none';
  document.getElementById('panel-signup').style.display = tab==='signup' ? '' : 'none';
  document.getElementById('tab-login').classList.toggle('active',  tab==='login');
  document.getElementById('tab-signup').classList.toggle('active', tab==='signup');
  document.getElementById('auth-title').textContent    = tab==='login' ? 'Welcome Back' : 'Create Your Etel-ID';
  document.getElementById('auth-subtitle').textContent = tab==='login'
    ? 'Sign in to your Etel Group account'
    : 'Sign up with your phone number and password';
  hideNotices();
}

/* ── Password toggle ────────────────────────────── */
function togglePw(id, btn) {
  var inp = document.getElementById(id);
  var ic  = btn.querySelector('i');
  if (inp.type==='password') {
    inp.type='text';
    ic.className='ti ti-eye-off';
  } else {
    inp.type='password';
    ic.className='ti ti-eye';
  }
}

/* ── Notices ────────────────────────────────────── */
function showErr(msg) {
  hideNotices();
  var el = document.getElementById('auth-notice-err');
  document.getElementById('auth-notice-err-text').textContent = msg;
  el.classList.add('show');
  el.scrollIntoView({behavior:'smooth',block:'center'});
}
function showOk(msg) {
  hideNotices();
  var el = document.getElementById('auth-notice-ok');
  document.getElementById('auth-notice-ok-text').textContent = msg;
  el.classList.add('show');
}
function hideNotices() {
  document.getElementById('auth-notice-err').classList.remove('show');
  document.getElementById('auth-notice-ok').classList.remove('show');
}

/* ── Field error ─────────────────────────────────── */
function fieldErr(id, msg) {
  var el = document.getElementById('err-'+id);
  var inp = document.getElementById(id.replace('err-reg-','reg-').replace(/-/g,'_'));
  if (el) { el.textContent=msg; el.classList.add('show'); }
  /* try direct id match */
  var inp2 = document.getElementById(id.replace('reg-','').replace(/-/g,'_')) || document.getElementById(id);
  if (inp2) inp2.classList.add('has-error');
}
function clearFieldErrs() {
  document.querySelectorAll('.eid-af-err').forEach(function(e){e.classList.remove('show');e.textContent='';});
  document.querySelectorAll('.has-error').forEach(function(e){e.classList.remove('has-error');});
}

/* ── Login ──────────────────────────────────────── */
function doLogin() {
  hideNotices(); clearFieldErrs();
  var phone = document.getElementById('login-phone').value.trim();
  var pw    = document.getElementById('login-password').value;
  var remember = document.getElementById('login-remember') && document.getElementById('login-remember').checked;
  if (!phone||!pw) { showErr('Please enter your phone number and password.'); return; }
  var btn = document.getElementById('login-btn');
  btn.disabled=true; btn.innerHTML='<i class="ti ti-loader" style="margin-right:6px"></i> Signing in…';
  var _redirect = (typeof _WPADMIN_REDIRECT !== 'undefined' && _WPADMIN_REDIRECT) ? 'wpadmin' : '';
  post({action:'etelid_auth_login',phone:phone,password:pw,remember:remember?'1':'0',redirect:_redirect},function(r){
    if(r.success){
      /* Save phone for Remember Me auto-fill */
      if (r.data.remember) {
        localStorage.setItem('etelid_saved_phone', r.data.phone);
      } else {
        localStorage.removeItem('etelid_saved_phone');
      }
      /* Cross-app SSO for etel-ai-widget.js (and anything else reading
         this key) - previously nothing on this page ever wrote it, so
         the AI widget always thought a genuinely signed-in member was
         a stranger whenever they'd logged in directly on EtelID's own
         portal rather than via Delivery/Ride's login. */
      if (r.data.sso_token) {
        localStorage.setItem('etel_sso', JSON.stringify({ token: r.data.sso_token, etel_id: r.data.etel_id || null }));
      }
      showOk(r.data.message);
      setTimeout(function(){ location.href = r.data.redirect; }, 400);
    } else {
      showErr(r.data.message);
      btn.disabled=false;
      btn.innerHTML='<i class="ti ti-login" style="margin-right:6px"></i> Sign In';
    }
  });
}

/* ── Sign in with device Face ID / Touch ID / fingerprint (WebAuthn) ── */
function _waB64urlToBuf(s){
  s = s.replace(/-/g,'+').replace(/_/g,'/');
  while (s.length % 4) s += '=';
  var bin = atob(s), buf = new Uint8Array(bin.length);
  for (var i=0;i<bin.length;i++) buf[i] = bin.charCodeAt(i);
  return buf.buffer;
}
function _waBufToB64url(buf){
  var bytes = new Uint8Array(buf), bin = '';
  for (var i=0;i<bytes.length;i++) bin += String.fromCharCode(bytes[i]);
  return btoa(bin).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
}
(async function initBioLogin(){
  var btn = document.getElementById('bio-login-btn');
  if (!btn || !window.PublicKeyCredential) return;
  try {
    var available = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
    if (available) btn.style.display = '';
  } catch(e) {}
})();

async function doBioLogin(){
  hideNotices(); clearFieldErrs();
  var phone = document.getElementById('login-phone').value.trim();
  if (!phone) { showErr('Enter your phone number first, then tap this button.'); return; }

  var btn = document.getElementById('bio-login-btn');
  btn.disabled = true;
  btn.innerHTML = '<i class="ti ti-loader" style="margin-right:6px"></i> Checking…';

  try {
    var optRes = await new Promise(function(resolve){ post({action:'etelid_webauthn_login_options', identifier: phone}, resolve); });
    if (!optRes.success) throw new Error(optRes.data.message);
    var opt = optRes.data;

    var publicKey = {
      challenge: _waB64urlToBuf(opt.challenge),
      rpId: opt.rpId,
      timeout: opt.timeout,
      userVerification: opt.userVerification,
      allowCredentials: (opt.allowCredentials||[]).map(function(c){ return { type: c.type, id: _waB64urlToBuf(c.id), transports: c.transports }; }),
    };

    btn.innerHTML = '<i class="ti ti-fingerprint" style="margin-right:6px"></i> Follow the prompt…';
    var cred = await navigator.credentials.get({ publicKey: publicKey });

    var payload = {
      id: cred.id,
      response: {
        clientDataJSON: _waBufToB64url(cred.response.clientDataJSON),
        authenticatorData: _waBufToB64url(cred.response.authenticatorData),
        signature: _waBufToB64url(cred.response.signature),
      },
    };

    btn.innerHTML = '<i class="ti ti-loader" style="margin-right:6px"></i> Signing in…';
    var loginRes = await new Promise(function(resolve){
      post({ action:'etelid_webauthn_login_verify', member_id: opt._member_id, credential: JSON.stringify(payload) }, resolve);
    });
    if (!loginRes.success) throw new Error(loginRes.data.message);

    if (loginRes.data.sso_token) {
      localStorage.setItem('etel_sso', JSON.stringify({ token: loginRes.data.sso_token, etel_id: loginRes.data.etel_id || null }));
    }
    showOk(loginRes.data.message);
    setTimeout(function(){ location.href = loginRes.data.redirect; }, 400);
  } catch (err) {
    showErr(err.name === 'NotAllowedError' ? 'Cancelled or timed out.' : (err.message || 'Biometric sign-in failed.'));
    btn.disabled = false;
    btn.innerHTML = '<i class="ti ti-fingerprint" style="margin-right:6px"></i> Sign in with Face / Fingerprint';
  }
}

/* ── Register steps ─────────────────────────────── */
/* Mirrors EtelID_Security::valid_ethiopian_phone() (includes/class-etelid-security.php)
 * so an invalid phone (e.g. "09") is caught here, at the step the user
 * actually typed it into, instead of only at final submit after they've
 * filled in the rest of the wizard - the server-side check still runs
 * too (defense-in-depth), this just gives fast, in-place feedback. */
/* Ethiopic Unicode block is U+1200-U+137F (decimal 4608-4991). Written as
 * charCode comparison rather than a \u-escaped regex literal, since this
 * source file is otherwise kept ASCII-clean for the same WAF-safety
 * reason PHP files here are. */
function hasAmharicChar(s) {
  for (var i = 0; i < s.length; i++) {
    var c = s.charCodeAt(i);
    if (c >= 4608 && c <= 4991) return true;
  }
  return false;
}
function countAmharicChars(s) {
  var n = 0;
  for (var i = 0; i < s.length; i++) {
    var c = s.charCodeAt(i);
    if (c >= 4608 && c <= 4991) n++;
  }
  return n;
}
/* Requires at least two names (given name + father's name, the standard
 * Ethiopian full-name convention), each at least 3 letters, no two
 * identical words, and not a common placeholder/keyboard-mash word -
 * "Da fa" (a genuine case seen live: two 2-letter nonsense words) passed
 * the original 2-letter-minimum, non-repeated-word-blind version.
 * Mirrors EtelID_Auth_Ajax::is_real_full_name() server-side. */
var NAME_BLOCKLIST = ['test','tests','testing','asdf','asdfg','qwerty','xxx','xxxx',
  'aaa','aaaa','sample','dummy','none','unknown','foo','bar','abc','xyz','name',
  'fname','lname','firstname','lastname','fullname','na','nan','null','undefined',
  'admin','user','demo'];
function isPlaceholderWord(w) {
  if (NAME_BLOCKLIST.indexOf(w.toLowerCase()) !== -1) return true;
  if (/^([A-Za-z])\1+$/.test(w)) return true; /* "aaaa", "mmmm" */
  return false;
}
function isRealFullName(name) {
  var words = name.split(/\s+/).filter(function(w){ return w.length > 0; });
  if (words.length < 2) return false;
  var seen = {};
  for (var i = 0; i < words.length; i++) {
    var w = words[i];
    if (!/^[A-Za-z'-]{3,}$/.test(w)) return false;
    if (isPlaceholderWord(w)) return false;
    var lw = w.toLowerCase();
    if (seen[lw]) return false; /* same word repeated */
    seen[lw] = true;
  }
  return true;
}
/* Same shape as isRealFullName(), for the optional Amharic name field -
 * each word must actually contain Amharic script and be at least 3
 * characters (not a single stray glyph), with no word repeated - "chuch
 * chuch"-style repeated test input (a genuine case seen live) no longer
 * passes. Mirrors EtelID_Auth_Ajax::is_real_amharic_name() server-side. */
function isRealAmharicName(nameAm) {
  var words = nameAm.split(/\s+/).filter(function(w){ return w.length > 0; });
  if (words.length < 2) return false;
  var seen = {};
  for (var i = 0; i < words.length; i++) {
    var w = words[i];
    if (w.length < 3 || countAmharicChars(w) < 3) return false;
    if (seen[w]) return false; /* same word repeated */
    seen[w] = true;
  }
  return true;
}
/* Rebuilds the Zone/City <select> to only offer zones that belong to
 * whichever Region is currently selected - this is what stops a
 * region/zone mismatch (e.g. "Tigray" + "Shashamane and Hawassa",
 * a real case seen live) from being possible to submit at all. */
function populateZones() {
  var region = document.getElementById('reg-region').value;
  var sel    = document.getElementById('reg-zone');
  sel.innerHTML = '';
  var zones = _ZONES_BY_REGION[region] || [];
  if (!region || !zones.length) {
    sel.disabled = true;
    var opt = document.createElement('option');
    opt.value = '';
    opt.textContent = region ? 'No zones available' : 'Select a region first';
    sel.appendChild(opt);
    return;
  }
  sel.disabled = false;
  var placeholder = document.createElement('option');
  placeholder.value = '';
  placeholder.textContent = '— Select —';
  sel.appendChild(placeholder);
  zones.forEach(function(z){
    var o = document.createElement('option');
    o.value = z; o.textContent = z;
    sel.appendChild(o);
  });
}
function isValidEthiopianPhone(phone) {
  var digits = (phone || '').replace(/[^0-9]/g, '');
  if (digits.length === 12 && digits.slice(0,3) === '251') digits = digits.slice(3);
  else if (digits.length === 10 && digits[0] === '0') digits = digits.slice(1);
  return /^[79][0-9]{8}$/.test(digits);
}
function regNext(from) {
  hideNotices(); clearFieldErrs();
  if (from===1) {
    var phone = document.getElementById('reg-phone').value.trim();
    var pw    = document.getElementById('reg-password').value;
    var pwc   = document.getElementById('reg-password-confirm').value;
    var ok = true;
    if (!phone) { fieldErr('reg-phone', 'Phone number is required.'); ok = false; }
    else if (!isValidEthiopianPhone(phone)) { fieldErr('reg-phone', 'Enter a valid Ethiopian phone number, e.g. 0912345678 or +251123456789.'); ok = false; }
    if (pw.length<8) { fieldErr('reg-password', 'Password must be at least 8 characters.'); ok = false; }
    if (pw!==pwc) { fieldErr('reg-password-confirm', 'Passwords do not match.'); ok = false; }
    if (!ok) return;
    /* Live duplicate-phone check against the database before advancing
     * to Step 2 - EtelID_Auth::register() already blocks a duplicate
     * phone at final submit, this surfaces the same signal immediately. */
    checkDuplicatePhone(phone, function(taken){
      if (taken) {
        fieldErr('reg-phone', 'An account with this phone number already exists. Please sign in instead.');
        return;
      }
      setRegStep(2);
    });
    return;
  }
  if (from===2) {
    var name    = document.getElementById('reg-full-name').value.trim();
    var nameAm  = document.getElementById('reg-full-name-am').value.trim();
    var dob     = document.getElementById('reg-dob').value;
    var email   = document.getElementById('reg-email').value.trim();
    var region  = document.getElementById('reg-region').value;
    var zone    = document.getElementById('reg-zone').value.trim();
    var ok2 = true;
    if (!name) { fieldErr('reg-full-name', 'Full name is required.'); ok2 = false; }
    else if (!isRealFullName(name)) { fieldErr('reg-full-name', 'Enter your real full name - at least two names (e.g. given name and father\'s name), each at least 3 letters, not repeated or a placeholder word.'); ok2 = false; }
    if (nameAm && !isRealAmharicName(nameAm)) { fieldErr('reg-full-name-am', 'Enter your full name in Amharic script (at least two real words, not repeated), or leave this blank.'); ok2 = false; }
    var dobOk = false;
    if (!dob)  { fieldErr('reg-dob', 'Date of birth is required.'); ok2 = false; }
    else {
      var age = ageFromDob(dob);
      if (age === null) { fieldErr('reg-dob', 'Enter a valid date.'); ok2 = false; }
      else if (age < 0) { fieldErr('reg-dob', 'Date of birth cannot be in the future.'); ok2 = false; }
      else if (age < 10) { fieldErr('reg-dob', 'You must be at least 10 years old to register.'); ok2 = false; }
      else if (age > 120) { fieldErr('reg-dob', 'Enter a valid date of birth.'); ok2 = false; }
      else { dobOk = true; }
    }
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { fieldErr('reg-email', 'Enter a valid email address, or leave it blank.'); ok2 = false; }
    if (!region) { fieldErr('reg-region', 'Region is required.'); ok2 = false; }
    if (!zone)   { fieldErr('reg-zone', 'Please select your zone/city.'); ok2 = false; }
    else if (region && (_ZONES_BY_REGION[region]||[]).indexOf(zone) === -1) { fieldErr('reg-zone', 'Select a zone/city that belongs to the chosen region.'); ok2 = false; }
    if (!ok2) return;
    /* All local checks passed - run the live duplicate-name check, then
     * (if an email was given) the live duplicate-email check, against
     * the database, and if both come back clear, create the account
     * directly (no separate Company/Role step - see doRegister()). */
    if (!dobOk) return;
    checkDuplicateName(name, dob, function(result){
      if (result.duplicate) {
        fieldErr('reg-full-name', 'An account with this name and date of birth already exists. Please sign in instead, or contact support if this is an error.');
        return;
      }
      showNameTakenWarning(result.nameTaken);
      if (!email) { setRegStep(3); return; }
      checkDuplicateEmail(email, function(taken){
        if (taken) {
          fieldErr('reg-email', 'This email is already registered. Please use a different email, or sign in instead.');
          return;
        }
        setRegStep(3);
      });
    });
    return;
  }
  if (from===3) {
    var ok3 = true;
    if (!_capturedImg.photo)        { fieldErr('reg-photo', 'Please add a profile photo.'); ok3 = false; }
    if (!_capturedImg['fayda-front']) { fieldErr('reg-fayda-front', 'Please add a photo of the front of your Fayda ID.'); ok3 = false; }
    if (!_capturedImg['fayda-back'])  { fieldErr('reg-fayda-back', 'Please add a photo of the back of your Fayda ID.'); ok3 = false; }
    if (!ok3) return;
    doRegister();
    return;
  }
}
function showNameTakenWarning(nameTaken) {
  var el = document.getElementById('warn-reg-full-name');
  if (nameTaken) {
    el.textContent = 'Note: an account with this name already exists (different date of birth on file). If this is you, please sign in instead.';
    el.style.display = 'block';
  } else {
    el.style.display = 'none';
  }
}
/* Live checks against the database (etelid_check_duplicate_name /
 * etelid_check_duplicate_email AJAX actions) - fire on blur of the
 * relevant field once it locally looks valid, and again as the final
 * gate before Step 2 -> Step 3, so a name+DOB combination that's
 * already registered, or an email already in use, is caught while
 * filling the form, not only after clicking "Create My Etel-ID" at
 * the very end. Name+DOB together blocks (a real duplicate signal);
 * name alone only warns (two different real people can share a name -
 * see EtelID_DB::find_by_name()'s own comment); email always blocks
 * since it should map to one account. */
var _dupNameCheck  = { key: '', result: null, checked: false };
var _dupEmailCheck = { key: '', taken: false, checked: false };
function checkDuplicateName(name, dob, cb) {
  var key = name.toLowerCase() + '|' + dob;
  if (_dupNameCheck.checked && _dupNameCheck.key === key) {
    cb(_dupNameCheck.result);
    return;
  }
  post({ action: 'etelid_check_duplicate_name', full_name: name, dob: dob }, function(r) {
    var result = {
      duplicate: !!(r.success && r.data && r.data.duplicate),
      nameTaken: !!(r.success && r.data && r.data.name_taken),
    };
    _dupNameCheck = { key: key, result: result, checked: true };
    cb(result);
  });
}
function checkDuplicateEmail(email, cb) {
  var key = email.toLowerCase();
  if (_dupEmailCheck.checked && _dupEmailCheck.key === key) {
    cb(_dupEmailCheck.taken);
    return;
  }
  post({ action: 'etelid_check_duplicate_email', email: email }, function(r) {
    var taken = !!(r.success && r.data && r.data.taken);
    _dupEmailCheck = { key: key, taken: taken, checked: true };
    cb(taken);
  });
}
var _dupPhoneCheck = { key: '', taken: false, checked: false };
function checkDuplicatePhone(phone, cb) {
  var key = phone.toLowerCase();
  if (_dupPhoneCheck.checked && _dupPhoneCheck.key === key) {
    cb(_dupPhoneCheck.taken);
    return;
  }
  post({ action: 'etelid_check_duplicate_phone', phone: phone }, function(r) {
    var taken = !!(r.success && r.data && r.data.taken);
    _dupPhoneCheck = { key: key, taken: taken, checked: true };
    cb(taken);
  });
}
document.getElementById('reg-phone').addEventListener('blur', function() {
  var phone = this.value.trim();
  if (!phone || !isValidEthiopianPhone(phone)) return;
  checkDuplicatePhone(phone, function(taken) {
    if (taken) {
      fieldErr('reg-phone', 'An account with this phone number already exists. Please sign in instead.');
    }
  });
});
document.getElementById('reg-full-name').addEventListener('blur', function() {
  var name = this.value.trim();
  var dob  = document.getElementById('reg-dob').value;
  if (!name || !dob || !isRealFullName(name) || ageFromDob(dob) === null) return;
  checkDuplicateName(name, dob, function(result) {
    if (result.duplicate) {
      fieldErr('reg-full-name', 'An account with this name and date of birth already exists. Please sign in instead, or contact support if this is an error.');
    } else {
      showNameTakenWarning(result.nameTaken);
    }
  });
});
document.getElementById('reg-email').addEventListener('blur', function() {
  var email = this.value.trim();
  if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return;
  checkDuplicateEmail(email, function(taken) {
    if (taken) {
      fieldErr('reg-email', 'This email is already registered. Please use a different email, or sign in instead.');
    }
  });
});
/* -- Step 3: Profile photo + Fayda ID capture --
 * "Open Camera" is a plain <input type=file capture="user|environment">
 * (see cam-file-cam-<kind> in the HTML above), the exact same mechanism
 * templates/apply-form.php's already-working "Take Selfie"/"Take Photo"
 * buttons use - it launches the device's native camera app via an
 * Android/iOS intent, not a JS API. An earlier version of this used
 * getUserMedia() for a live in-page preview so the crop guide frame
 * could be shown while framing the shot; that turned out unreliable on
 * live devices (secure-context and per-origin permission requirements
 * that this proven, already-working native-camera pattern doesn't have
 * at all), so this now matches the reliable approach instead. The
 * guide frame stays as a purely visual alignment aid on the empty box
 * (see .eid-cam-guide in etelid-auth.css); the actual crop happens
 * after the fact in camFileChange(), the same way an uploaded gallery
 * photo is already cropped - a centre-crop to the ID-1 card ratio
 * (85.6mm x 54mm ~= 1.586:1) so the stored image is just the card, not
 * the desk/hand/background around it. */
var CAM = {
  'photo':        { crop: false },
  'fayda-front':  { crop: true  },
  'fayda-back':   { crop: true  }
};
var _capturedImg = { 'photo': '', 'fayda-front': '', 'fayda-back': '' };
var FAYDA_RATIO = 1.586;

function drawCropped(source, sx, sy, sw, sh, maxDim) {
  var outW = sw, outH = sh;
  if (outW > maxDim || outH > maxDim) {
    if (outW > outH) { outH = Math.round(outH * maxDim / outW); outW = maxDim; }
    else              { outW = Math.round(outW * maxDim / outH); outH = maxDim; }
  }
  var canvas = document.createElement('canvas');
  canvas.width = Math.round(outW); canvas.height = Math.round(outH);
  canvas.getContext('2d').drawImage(source, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);
  return canvas.toDataURL('image/jpeg', 0.85);
}

function finishCapture(kind, dataUrl) {
  _capturedImg[kind] = dataUrl;
  var box = document.getElementById('cam-box-' + kind);
  box.innerHTML = '<img src="' + dataUrl + '">';
  box.classList.add('filled');
  document.getElementById('cam-btns-' + kind).style.display = 'none';
  document.getElementById('cam-retake-' + kind).style.display = 'block';
  var err = document.getElementById('err-reg-' + kind);
  if (err) { err.classList.remove('show'); err.textContent = ''; }
}

function camRetake(kind) {
  _capturedImg[kind] = '';
  var cfg = CAM[kind];
  var box = document.getElementById('cam-box-' + kind);
  box.className = 'eid-cam-box' + (kind === 'photo' ? ' round' : '');
  box.innerHTML = cfg.crop ? '<div class="eid-cam-guide"></div>' : '';
  document.getElementById('cam-retake-' + kind).style.display = 'none';
  document.getElementById('cam-btns-' + kind).style.display = 'flex';
}

/* Upload fallback - centre-crops to the same card aspect ratio so a
 * gallery photo of the ID gets the same "just the card" treatment as
 * a live capture, instead of keeping the whole surrounding photo. */
function camFileChange(kind, input) {
  var f = input.files && input.files[0];
  if (!f) return;
  if (!f.type.match(/^image\//)) { fieldErr('reg-' + kind, 'Please select an image file.'); return; }
  var cfg = CAM[kind];
  var reader = new FileReader();
  reader.onload = function(e) {
    var img = new Image();
    img.onload = function() {
      var sx = 0, sy = 0, sw = img.width, sh = img.height;
      if (cfg.crop) {
        var srcRatio = img.width / img.height;
        if (srcRatio > FAYDA_RATIO) { sw = img.height * FAYDA_RATIO; sx = (img.width - sw) / 2; }
        else                        { sh = img.width / FAYDA_RATIO;  sy = (img.height - sh) / 2; }
      }
      finishCapture(kind, drawCropped(img, sx, sy, sw, sh, cfg.crop ? 1000 : 500));
    };
    img.src = e.target.result;
  };
  reader.readAsDataURL(f);
}

/* Returns whole years of age from a yyyy-mm-dd date-input value, or null
 * if unparseable. Negative means the date is in the future. */
function ageFromDob(dob) {
  var d = new Date(dob + 'T00:00:00');
  if (isNaN(d.getTime())) return null;
  var now = new Date();
  var age = now.getFullYear() - d.getFullYear();
  var monthDiff = now.getMonth() - d.getMonth();
  if (monthDiff < 0 || (monthDiff === 0 && now.getDate() < d.getDate())) age--;
  return age;
}
function updateAgeHint() {
  var dob  = document.getElementById('reg-dob').value;
  var hint = document.getElementById('reg-age-hint');
  if (!dob) { hint.textContent = ''; return; }
  var age = ageFromDob(dob);
  if (age === null) { hint.textContent = ''; }
  else if (age < 0)  { hint.textContent = 'That date is in the future.'; }
  else { hint.textContent = 'Age: ' + age + (age === 1 ? ' year' : ' years'); }
}
function regBack(from) {
  setRegStep(from-1);
}
function setRegStep(n) {
  _regStep = n;
  [1,2,3].forEach(function(i){
    document.getElementById('reg-step-'+i).style.display = i===n ? '' : 'none';
    var dot = document.getElementById('dot-'+i);
    dot.className = 'eid-reg-dot' + (i<n?' done':(i===n?' active':''));
  });
  window.scrollTo({top:0, behavior:'smooth'});
}
/* No separate Company/Role step - every self-registered member defaults
 * to Etel Group / Member, same as omitting these fields already works
 * for quick_register() (EtelID_Generator::create() falls back cleanly
 * when they're not sent). Staff/company assignment is handled later via
 * EtelGroup admin's Employees panel, not at self-registration time. */
function doRegister() {
  var btn = document.getElementById('reg-submit-btn');
  var lbl = document.getElementById('reg-submit-lbl');
  btn.disabled=true; lbl.textContent='Creating your Etel-ID…';
  var data = {
    action:'etelid_auth_register',
    phone:                document.getElementById('reg-phone').value,
    password:             document.getElementById('reg-password').value,
    password_confirm:     document.getElementById('reg-password-confirm').value,
    full_name:            document.getElementById('reg-full-name').value,
    full_name_am:         document.getElementById('reg-full-name-am').value,
    dob:                  document.getElementById('reg-dob').value,
    sex:                  document.getElementById('reg-sex').value,
    blood_type:           document.getElementById('reg-blood').value,
    email:                document.getElementById('reg-email').value,
    region:               document.getElementById('reg-region').value,
    zone:                 document.getElementById('reg-zone').value,
    nationality:          'Ethiopian',
    photo_url:            _capturedImg.photo,
    fayda_front_image:    _capturedImg['fayda-front'],
    fayda_back_image:     _capturedImg['fayda-back'],
  };
  post(data,function(r){
    if(r.success){ showOk(r.data.message+' Redirecting…'); setTimeout(function(){location.href=r.data.redirect;},800); }
    else {
      showErr(r.data.message||'Registration failed. Please try again.');
      btn.disabled=false; lbl.textContent='Create My Etel-ID';
    }
  });
}

/* ── AJAX helper ─────────────────────────────────── */
function post(data, cb) {
  data.nonce = _NONCE;
  var body = Object.keys(data).map(function(k){return encodeURIComponent(k)+'='+encodeURIComponent(data[k]);}).join('&');
  fetch(_AJAX,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
    .then(function(r){return r.json();}).then(cb)
    .catch(function(){cb({success:false,data:{message:'Network error. Please try again.'}});});
}

/* ── Auto-detect: check if already logged in ── */
(function() {
  /* If there's a saved phone in localStorage (from remember me), pre-fill */
  var saved = localStorage.getItem('etelid_saved_phone');
  if (saved) {
    var phoneEl = document.getElementById('login-phone');
    if (phoneEl) { phoneEl.value = saved; }
    var remEl = document.getElementById('login-remember');
    if (remEl) remEl.checked = true;
  }
  /* If portal is reachable (session still active), auto-redirect */
  fetch(_AJAX, {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'action=etelid_auth_portal_data&nonce=' + _NONCE
  }).then(function(r){return r.json();}).then(function(res){
    if (res.success) {
      /* Already logged in — go straight to portal */
      window.location.href = _PORTAL;
    }
  }).catch(function(){/* ignore network errors */});
})();

/* Password strength meter + live requirements checklist */
function pwCheckItem(id, met) {
  var el = document.getElementById(id);
  el.style.color = met ? '#16a34a' : 'var(--ink3)';
  el.textContent = (met ? '[OK] ' : '[ ] ') + el.getAttribute('data-label');
}
document.getElementById('reg-password').addEventListener('input', function() {
  var pw        = this.value;
  var wrap      = document.getElementById('pw-strength-wrap');
  var fill      = document.getElementById('pw-strength-fill');
  var lbl       = document.getElementById('pw-strength-lbl');
  var checklist = document.getElementById('pw-checklist');
  if (!pw) { wrap.style.display='none'; checklist.style.display='none'; return; }
  wrap.style.display = 'block';
  checklist.style.display = 'block';

  var hasLen     = pw.length >= 8;
  var hasUpper   = /[A-Z]/.test(pw);
  var hasLower   = /[a-z]/.test(pw);
  var hasNum     = /[0-9]/.test(pw);
  var hasSpecial = /[^A-Za-z0-9]/.test(pw);

  pwCheckItem('pwc-len',     hasLen);
  pwCheckItem('pwc-upper',   hasUpper);
  pwCheckItem('pwc-lower',   hasLower);
  pwCheckItem('pwc-num',     hasNum);
  pwCheckItem('pwc-special', hasSpecial);

  var classesMet = [hasUpper, hasLower, hasNum, hasSpecial].filter(Boolean).length;
  var score = (hasLen ? 1 : 0) + classesMet + (pw.length >= 12 ? 1 : 0);
  var colors = ['#ef4444','#ef4444','#f59e0b','#eab308','#eab308','#22c55e','#16a34a'];
  var labels = ['Too Short','Very Weak','Weak','Fair','Good','Strong','Very Strong'];
  var i = hasLen ? Math.min(score, 6) : 0;
  fill.style.width       = (Math.round((i/6)*100))+'%';
  fill.style.background  = colors[i];
  lbl.textContent        = labels[i];
  lbl.style.color        = colors[i];
});

/* Enter key on login fields */
document.addEventListener('keydown',function(e){
  if(e.key==='Enter'){
    if(document.getElementById('panel-login').style.display!=='none') doLogin();
  }
});
</script>

<!-- ETEL AI -- cross-company assistant widget -->
<script src="https://ethelmarket.com/EtelAi/etel-ai-widget.js" data-project="etel-id"></script>
</body>
</html>
