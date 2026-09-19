<?php
/**
 * Etel-ID Premium — Apply Form v4.0.0
 * Standalone — no WP theme.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( class_exists('EtelID_Auth') && EtelID_Auth::is_logged_in() ) {
    wp_redirect( home_url('/portal/') ); exit;
}

$companies = EtelID_Generator::companies();
$regions   = EtelID_Generator::regions();
$blood     = EtelID_Generator::blood_types();
$zones_by_region = EtelID_Generator::zones_by_region();
$logo_src  = defined('ETELID_LOGO_B64') ? ETELID_LOGO_B64 : ETELID_URL.'public/images/etel-logo.png';
$org_name  = get_option('etelid_org_name','Etel Group');
$nonce     = wp_create_nonce('etelid_public'); // must match check_ajax_referer('etelid_public', ...) in class-etelid-ajax.php - this was previously 'etelid_apply', a different action string, which made every submission fail nonce verification unconditionally regardless of timing or caching
$nonce_auth = wp_create_nonce('etelid_auth'); // matches check_ajax_referer('etelid_auth', ...) in class-etelid-auth-ajax.php::register() - both Quick Register and Full Registration submit through this action, which issues a temporary password (must_change_password=1) rather than collecting one from the person, per EtelID_Auth::register()
$ajax      = admin_url('admin-ajax.php');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0">
<title>Apply for Your Etel-ID · <?php echo esc_html($org_name); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=JetBrains+Mono:wght@400;500;600;700&family=Cormorant+Garamond:wght@400;600;700&family=Noto+Sans+Ethiopic:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}

/* ════════════════════════════════════
   ROOT
════════════════════════════════════ */
:root{
  --g1:#FDF6D8;--g2:#F5E6A3;--g3:#E8C84A;--g4:#D4AF37;
  --g5:#C8922A;--g6:#A07020;--g7:#7A5518;--g8:#4A3210;--g9:#2A1A06;
  --ink:#1A0E00;--ink2:#3A2200;--ink3:#7A5520;
  --glass:rgba(20,10,0,.52);
  --glass-border:rgba(212,175,55,.28);
}

/* ════════════════════════════════════
   ANIMATIONS
════════════════════════════════════ */
@keyframes holo    {0%{background-position:0 0}100%{background-position:300% 0}}
@keyframes slide-up{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
@keyframes shimmer {0%{background-position:-400px 0}100%{background-position:400px 0}}
@keyframes orb1    {0%,100%{transform:translate(0,0)}50%{transform:translate(30px,-20px)}}
@keyframes orb2    {0%,100%{transform:translate(0,0)}50%{transform:translate(-22px,16px)}}
@keyframes badge-in{0%{transform:scale(.4);opacity:0}70%{transform:scale(1.1)}100%{transform:scale(1);opacity:1}}
@keyframes check-draw{to{stroke-dashoffset:0}}

/* ════════════════════════════════════
   PAGE
════════════════════════════════════ */
body{
  min-height:100vh;font-family:'Cormorant Garamond',serif;
  overflow-x:hidden;position:relative;
  background:
    radial-gradient(ellipse at 18% 8%,  rgba(255,230,80,.22) 0%,transparent 40%),
    radial-gradient(ellipse at 82% 92%, rgba(60,30,0,.32)    0%,transparent 40%),
    linear-gradient(155deg,#2A1600 0%,#5A3A08 15%,#9A6818 30%,
                    #C8922A 44%,#D4AF37 50%,#C8922A 56%,
                    #9A6818 70%,#5A3A08 85%,#2A1600 100%);
}
body::before{
  content:'';position:fixed;inset:0;pointer-events:none;z-index:0;
  background-image:
    repeating-linear-gradient(30deg, transparent 0,transparent 24px,rgba(255,255,255,.022) 24px,rgba(255,255,255,.022) 25px),
    repeating-linear-gradient(-30deg,transparent 0,transparent 24px,rgba(255,255,255,.022) 24px,rgba(255,255,255,.022) 25px);
}
.af-orb{position:fixed;border-radius:50%;pointer-events:none;z-index:0;filter:blur(70px);}
.af-orb-1{width:520px;height:520px;top:-150px;left:-150px;
  background:radial-gradient(circle,rgba(255,215,60,.16) 0%,transparent 70%);
  animation:orb1 14s ease-in-out infinite;}
.af-orb-2{width:420px;height:420px;bottom:-90px;right:-90px;
  background:radial-gradient(circle,rgba(80,40,0,.2) 0%,transparent 70%);
  animation:orb2 18s ease-in-out infinite;}

/* ════════════════════════════════════
   NAV
════════════════════════════════════ */
.af-nav{
  position:sticky;top:0;z-index:200;
  background:linear-gradient(135deg,var(--g9),var(--g8) 40%,var(--g9));
  border-bottom:2px solid var(--g4);
  height:60px;padding:0 20px;
  display:flex;align-items:center;gap:12px;
  box-shadow:0 4px 28px rgba(10,5,0,.55);
}
.af-nav::after{
  content:'';position:absolute;bottom:-2px;left:0;right:0;height:2px;
  background:linear-gradient(90deg,transparent,var(--g5),var(--g2),var(--g1),var(--g2),var(--g5),transparent);
  background-size:300% 100%;animation:holo 4s linear infinite;
}
.af-nav-logo{height:36px;width:auto;object-fit:contain;flex-shrink:0;
  filter:brightness(1.2) drop-shadow(0 2px 8px rgba(0,0,0,.5));}
.af-nav-text{flex:1;}
.af-nav-title{font-family:'Syne',sans-serif;font-size:16px;font-weight:800;color:var(--g1);}
.af-nav-sub{font-family:'Noto Sans Ethiopic',sans-serif;font-size:9px;color:var(--g4);display:block;}
.af-nav-login{
  display:inline-flex;align-items:center;gap:6px;padding:8px 14px;
  background:rgba(212,175,55,.1);border:1.5px solid rgba(212,175,55,.28);
  border-radius:8px;color:var(--g2);font-family:'Syne',sans-serif;
  font-size:11px;font-weight:800;text-decoration:none;flex-shrink:0;transition:all .15s;
}
.af-nav-login:hover{background:rgba(212,175,55,.18);color:var(--g1);text-decoration:none;}

/* ════════════════════════════════════
   CONTENT WRAP
════════════════════════════════════ */
.af-wrap{max-width:720px;margin:0 auto;padding:24px 16px 60px;position:relative;z-index:1;}

/* ════════════════════════════════════
   STEP PROGRESS BAR
════════════════════════════════════ */
.af-steps{
  display:flex;align-items:center;
  padding:0 10px;margin-bottom:28px;
  position:relative;
}
.af-steps::before{
  content:'';position:absolute;
  top:20px;left:15%;right:15%;height:2px;
  background:rgba(212,175,55,.15);z-index:0;
}
.af-step{
  flex:1;display:flex;flex-direction:column;
  align-items:center;gap:6px;position:relative;z-index:1;
}
.af-step-dot{
  width:40px;height:40px;border-radius:50%;
  background:rgba(20,10,0,.6);
  border:2px solid rgba(212,175,55,.25);
  display:flex;align-items:center;justify-content:center;
  font-family:'Syne',sans-serif;font-size:14px;font-weight:800;
  color:rgba(212,175,55,.4);
  transition:all .3s cubic-bezier(.22,.68,0,1.2);
  box-shadow:0 0 0 4px rgba(20,10,0,.4);
}
.af-step-lbl{
  font-family:'JetBrains Mono',monospace;font-size:9px;
  text-transform:uppercase;letter-spacing:1px;
  color:rgba(212,175,55,.4);transition:color .3s;
}
.af-step.active .af-step-dot{
  background:linear-gradient(135deg,var(--g5),var(--g4));
  border-color:var(--g4);color:var(--g9);
  box-shadow:0 0 0 4px rgba(20,10,0,.4),0 0 0 6px rgba(212,175,55,.2),0 4px 14px rgba(100,60,0,.3);
}
.af-step.active .af-step-lbl{color:var(--g4);}
.af-step.done .af-step-dot{
  background:linear-gradient(135deg,#166534,#22c55e);
  border-color:#22c55e;color:#fff;
  box-shadow:0 0 0 4px rgba(20,10,0,.4),0 4px 10px rgba(22,101,52,.25);
}
.af-step.done .af-step-lbl{color:#86efac;}

/* ════════════════════════════════════
   ERROR NOTICE
════════════════════════════════════ */
.af-error{
  display:none;align-items:center;gap:10px;
  padding:12px 16px;border-radius:10px;
  background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.28);
  color:#fca5a5;font-family:'JetBrains Mono',monospace;font-size:12px;
  margin-bottom:16px;
}
.af-error.show{display:flex;animation:slide-up .25s ease both;}

/* ════════════════════════════════════
   SECTION CARDS
════════════════════════════════════ */
.af-panel{
  background:var(--glass);
  border:1.5px solid var(--glass-border);
  border-radius:18px;overflow:hidden;
  backdrop-filter:blur(14px);
  box-shadow:0 8px 36px rgba(10,5,0,.3),inset 0 1px 0 rgba(255,255,255,.07);
  margin-bottom:0;
  animation:slide-up .4s ease both;
}
.af-panel-head{
  padding:16px 22px;
  background:linear-gradient(135deg,rgba(74,50,16,.95),rgba(26,14,0,.88));
  border-bottom:1.5px solid rgba(212,175,55,.28);
  display:flex;align-items:center;gap:12px;
  position:relative;overflow:hidden;
}
.af-panel-head::after{
  content:'';position:absolute;bottom:0;left:0;right:0;height:1px;
  background:linear-gradient(90deg,transparent,var(--g4),transparent);
}
.af-panel-icon{
  width:40px;height:40px;border-radius:11px;
  background:linear-gradient(135deg,var(--g6),var(--g8));
  display:flex;align-items:center;justify-content:center;
  font-size:18px;flex-shrink:0;
  box-shadow:0 2px 10px rgba(0,0,0,.35);
}
.af-panel-title{
  font-family:'Syne',sans-serif;font-size:16px;font-weight:800;
  color:var(--g1);display:block;line-height:1.1;
}
.af-panel-sub{font-size:12px;color:var(--g5);display:block;margin-top:2px;}
.af-panel-body{padding:24px 22px;}

/* ════════════════════════════════════
   FORM GRID + FIELDS
════════════════════════════════════ */
.af-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:4px;}
@media(max-width:560px){.af-grid{grid-template-columns:1fr;}}
.af-full{grid-column:1/-1;}
.af-field{display:flex;flex-direction:column;gap:5px;}
.af-label{
  font-family:'JetBrains Mono',monospace;font-size:9.5px;font-weight:700;
  color:var(--g5);text-transform:uppercase;letter-spacing:1.8px;
  display:flex;align-items:center;gap:4px;
}
.af-label .req{color:#f87171;}
.af-hint{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--g6);}

.af-input,.af-select{
  width:100%;padding:12px 15px;
  background:rgba(212,175,55,.07);
  border:1.5px solid rgba(212,175,55,.22);
  border-radius:10px;
  font-size:15px;font-family:'Cormorant Garamond',serif;
  color:var(--g1);
  transition:border-color .2s,box-shadow .2s,background .2s;
  -webkit-appearance:none;appearance:none;
  box-shadow:inset 0 2px 4px rgba(0,0,0,.15);
}
.af-input:focus,.af-select:focus{
  border-color:var(--g4);outline:none;
  background:rgba(212,175,55,.1);
  box-shadow:0 0 0 3px rgba(212,175,55,.15),inset 0 2px 4px rgba(0,0,0,.08);
}
.af-input::placeholder{color:rgba(212,175,55,.3);}
.af-select option{background:#2A1A06;color:var(--g1);}
.af-input[style*="font-family:'Noto"]{font-family:'Noto Sans Ethiopic',sans-serif;}

/* ════════════════════════════════════
   COMPANY CHIPS
════════════════════════════════════ */
.af-chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:4px;}
.af-co-chk{display:none;}
.af-co-lbl{
  display:inline-flex;align-items:center;gap:8px;
  padding:9px 15px;border-radius:9px;
  background:rgba(212,175,55,.07);border:1.5px solid rgba(212,175,55,.2);
  font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--g2);
  cursor:pointer;transition:all .2s;user-select:none;
}
.af-co-lbl:hover{background:rgba(212,175,55,.12);border-color:rgba(212,175,55,.4);}
.af-co-chk:checked + .af-co-lbl{
  background:rgba(212,175,55,.18);border-color:var(--g4);color:var(--g1);
  box-shadow:0 0 0 2px rgba(212,175,55,.15);
}
.af-co-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}

/* ════════════════════════════════════
   STEP NAVIGATION
════════════════════════════════════ */
.af-nav-row{
  display:flex;align-items:center;justify-content:space-between;
  margin-top:22px;padding-top:18px;
  border-top:1px solid rgba(212,175,55,.15);
  flex-wrap:wrap;gap:10px;
}
.af-step-of{
  font-family:'JetBrains Mono',monospace;font-size:10px;
  color:rgba(212,175,55,.45);letter-spacing:1.5px;text-transform:uppercase;
}
.af-nav-btns{display:flex;gap:10px;}
.af-btn-next{
  display:inline-flex;align-items:center;gap:7px;
  padding:12px 24px;border:none;border-radius:10px;cursor:pointer;
  background:linear-gradient(135deg,var(--g9),var(--g7),var(--g5),var(--g4));
  color:var(--g1);font-family:'Syne',sans-serif;font-size:14px;font-weight:800;
  box-shadow:0 4px 0 var(--g9),0 6px 18px rgba(80,40,0,.3);
  transition:all .15s;position:relative;overflow:hidden;letter-spacing:.3px;
}
.af-btn-next::before{
  content:'';position:absolute;top:0;left:-100%;width:50%;height:100%;
  background:linear-gradient(90deg,transparent,rgba(255,255,255,.12),transparent);
  animation:shimmer 2.5s infinite;
}
.af-btn-next:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 5px 0 var(--g9),0 8px 22px rgba(80,40,0,.35);}
.af-btn-next:disabled{opacity:.7;cursor:not-allowed;transform:none;}
.af-btn-back{
  display:inline-flex;align-items:center;gap:6px;
  padding:12px 18px;border-radius:10px;cursor:pointer;
  background:rgba(212,175,55,.08);border:1.5px solid rgba(212,175,55,.25);
  color:var(--g4);font-family:'Syne',sans-serif;font-size:13px;font-weight:800;
  transition:all .15s;
}
.af-btn-back:hover{background:rgba(212,175,55,.15);border-color:rgba(212,175,55,.45);color:var(--g2);}

/* ════════════════════════════════════
   REVIEW SUMMARY GRID
════════════════════════════════════ */
.af-review-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-bottom:20px;}
@media(max-width:480px){.af-review-grid{grid-template-columns:1fr;}}
.af-review-item{
  padding:10px 14px;
  background:rgba(212,175,55,.07);border:1px solid rgba(212,175,55,.18);
  border-radius:9px;
}
.af-review-lbl{font-family:'JetBrains Mono',monospace;font-size:8px;color:var(--g5);letter-spacing:2px;text-transform:uppercase;margin-bottom:3px;}
.af-review-val{font-size:14px;font-weight:700;color:var(--g1);}

/* Terms box */
.af-terms{
  padding:14px 18px;
  background:rgba(212,175,55,.06);border:1px solid rgba(212,175,55,.2);
  border-radius:10px;margin-bottom:20px;
  font-size:13px;color:var(--g5);line-height:1.7;
}

/* ════════════════════════════════════
   SUCCESS STATE
════════════════════════════════════ */
.af-success{
  text-align:center;padding:48px 20px;
  animation:slide-up .5s ease both;
}
.af-success-check{
  width:80px;height:80px;border-radius:50%;margin:0 auto 20px;
  background:linear-gradient(135deg,#166534,#22c55e);
  display:flex;align-items:center;justify-content:center;
  font-size:36px;color:#fff;
  box-shadow:0 0 0 8px rgba(34,197,94,.15),0 10px 28px rgba(22,101,52,.35);
  animation:badge-in .6s cubic-bezier(.22,.68,0,1.2) both;
}
.af-success-title{font-family:'Syne',sans-serif;font-size:26px;font-weight:800;color:var(--g1);margin-bottom:8px;}
.af-success-sub{font-size:15px;color:var(--g4);margin-bottom:28px;}
.af-success-ids{display:flex;gap:16px;justify-content:center;flex-wrap:wrap;margin-bottom:28px;}
.af-id-card{
  background:rgba(212,175,55,.1);border:1.5px solid rgba(212,175,55,.3);
  border-radius:12px;padding:16px 24px;min-width:160px;
  box-shadow:0 4px 16px rgba(0,0,0,.25);
}
.af-id-lbl{font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--g5);letter-spacing:2px;text-transform:uppercase;display:block;margin-bottom:6px;}
.af-id-val{font-family:'JetBrains Mono',monospace;font-size:18px;font-weight:800;color:var(--g1);letter-spacing:1.5px;display:block;}
.af-pw-box{display:flex;align-items:center;gap:8px;}
.af-pw-input{
  font-family:'JetBrains Mono',monospace;font-size:18px;font-weight:800;color:var(--g1);
  letter-spacing:1.5px;background:transparent;border:none;padding:0;width:11ch;
}
.af-pw-copy{
  background:rgba(212,175,55,.18);border:1px solid rgba(212,175,55,.4);border-radius:6px;
  color:var(--g3);font-family:'JetBrains Mono',monospace;font-size:11px;font-weight:600;
  padding:5px 10px;cursor:pointer;flex-shrink:0;transition:background .15s;
}
.af-pw-copy:hover{background:rgba(212,175,55,.3);}
.af-pw-copy.copied{background:rgba(34,197,94,.25);border-color:rgba(34,197,94,.5);color:#86efac;}
.af-success-actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;}
.af-btn-primary-lg{
  display:inline-flex;align-items:center;gap:8px;
  padding:14px 28px;border-radius:10px;
  background:linear-gradient(135deg,var(--g9),var(--g7),var(--g5),var(--g4));
  color:var(--g1);font-family:'Syne',sans-serif;font-size:15px;font-weight:800;
  text-decoration:none;box-shadow:0 5px 0 var(--g9),0 8px 22px rgba(80,40,0,.3);
  transition:all .15s;
}
.af-btn-primary-lg:hover{transform:translateY(-1px);color:var(--g1);text-decoration:none;}
.af-btn-outline{
  display:inline-flex;align-items:center;gap:7px;
  padding:14px 22px;border-radius:10px;
  background:rgba(212,175,55,.08);border:1.5px solid rgba(212,175,55,.3);
  color:var(--g4);font-family:'Syne',sans-serif;font-size:14px;font-weight:800;
  text-decoration:none;transition:all .15s;
}
.af-btn-outline:hover{background:rgba(212,175,55,.15);color:var(--g2);text-decoration:none;}

/* ════════════════════════════════════
   FOOTER
════════════════════════════════════ */
.af-footer{
  text-align:center;padding:24px 0 0;
  font-family:'JetBrains Mono',monospace;font-size:8px;
  color:rgba(212,175,55,.28);letter-spacing:2px;text-transform:uppercase;
}
</style>
</head>
<body>
<div class="af-orb af-orb-1"></div>
<div class="af-orb af-orb-2"></div>

<!-- ══ NAV ══ -->
<nav class="af-nav">
  <img src="<?php echo esc_attr($logo_src); ?>" alt="" class="af-nav-logo">
  <div class="af-nav-text">
    <div class="af-nav-title">Apply for Your Etel-ID</div>
    <span class="af-nav-sub">ኢትኤል ቡድን · Official Corporate Identity</span>
  </div>
  <a href="<?php echo esc_url(home_url('/login/')); ?>" class="af-nav-login">
    <i class="ti ti-login"></i> Sign In
  </a>
</nav>

<div class="af-wrap">

  <!-- ══ MODE CHOICE ══ -->
  <div id="af-mode-choice">
    <div class="af-panel">
      <div class="af-panel-head">
        <div class="af-panel-icon">🪪</div>
        <div>
          <span class="af-panel-title">How would you like to register?</span>
          <span class="af-panel-sub">Pick one - you can always finish the rest later either way</span>
        </div>
      </div>
      <div class="af-panel-body" style="display:flex;flex-direction:column;gap:14px;">
        <button type="button" class="af-mode-card" onclick="etelIDChooseMode('quick')"
          style="text-align:left;cursor:pointer;background:rgba(212,175,55,.08);border:1.5px solid rgba(212,175,55,.28);border-radius:12px;padding:18px 20px;display:flex;align-items:center;gap:16px;transition:all .15s;">
          <div style="font-size:28px;flex-shrink:0;">⚡</div>
          <div>
            <div style="font-family:'Syne',sans-serif;font-weight:800;font-size:16px;color:var(--g1);">Quick Register</div>
            <div style="font-size:13px;color:var(--g4);margin-top:2px;">Full name, photo, Fayda ID, email &amp; phone - about a minute. Fill in the rest from your portal whenever you like.</div>
          </div>
        </button>
        <button type="button" class="af-mode-card" onclick="etelIDChooseMode('full')"
          style="text-align:left;cursor:pointer;background:rgba(212,175,55,.08);border:1.5px solid rgba(212,175,55,.28);border-radius:12px;padding:18px 20px;display:flex;align-items:center;gap:16px;transition:all .15s;">
          <div style="font-size:28px;flex-shrink:0;">📋</div>
          <div>
            <div style="font-family:'Syne',sans-serif;font-weight:800;font-size:16px;color:var(--g1);">Full Registration</div>
            <div style="font-size:13px;color:var(--g4);margin-top:2px;">Personal details, address, and ID verification in one go - about 4 minutes.</div>
          </div>
        </button>
      </div>
    </div>
  </div>

  <!-- ══ QUICK REGISTER ══ -->
  <div id="af-mode-quick" style="display:none;">
    <div class="af-error" id="qr-form-error">
      <i class="ti ti-alert-circle" style="font-size:16px;flex-shrink:0;"></i>
      <span id="qr-error-text">Please fill in all required fields.</span>
    </div>
    <div class="af-panel">
      <div class="af-panel-head">
        <div class="af-panel-icon">⚡</div>
        <div>
          <span class="af-panel-title">Quick Register</span>
          <span class="af-panel-sub">Just the essentials - add the rest from your portal any time</span>
        </div>
      </div>
      <div class="af-panel-body">
        <div class="af-grid">
          <div class="af-field af-full">
            <label class="af-label">Full Name <span class="req">*</span></label>
            <input type="text" class="af-input" id="q-full_name" placeholder="e.g. Dagmawi Bekele" autocomplete="name">
          </div>
          <div class="af-field">
            <label class="af-label">Phone Number <span class="req">*</span></label>
            <input type="tel" class="af-input" id="q-phone" placeholder="+251 9XX XXX XXX" autocomplete="tel">
          </div>
          <div class="af-field">
            <label class="af-label">Email Address</label>
            <input type="email" class="af-input" id="q-email" placeholder="you@example.com (optional)" autocomplete="email">
          </div>
        </div>

        <!-- Profile photo -->
        <div class="af-field" style="margin:20px 0;">
          <label class="af-label">Profile Photo <span class="req">*</span></label>
          <div style="display:flex;align-items:center;gap:14px;border:1.5px dashed rgba(212,175,55,.35);border-radius:10px;padding:14px;">
            <div id="q-photo-preview" style="width:56px;height:56px;border-radius:50%;background:rgba(212,175,55,.12);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;font-size:22px;">📷</div>
            <div style="font-size:11px;color:var(--g4);">Use the camera directly, or choose one from your gallery</div>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
            <label style="cursor:pointer;background:rgba(255,255,255,.06);border:1px solid rgba(212,175,55,.3);border-radius:8px;padding:10px 14px;font-size:12px;color:#fff;">
              <input type="file" accept="image/*" capture="user" style="display:none" onchange="etelIDQuickPhotoFile(this)">🤳 Take Selfie
            </label>
            <label style="cursor:pointer;background:rgba(255,255,255,.06);border:1px solid rgba(212,175,55,.3);border-radius:8px;padding:10px 14px;font-size:12px;color:#fff;">
              <input type="file" accept="image/*" style="display:none" onchange="etelIDQuickPhotoFile(this)">🖼️ Choose from Gallery
            </label>
          </div>
        </div>

        <!-- Fayda ID front/back -->
        <div class="af-field">
          <label class="af-label">Fayda National ID <span class="req">*</span></label>
          <div style="font-size:11px;color:var(--g4);margin-bottom:10px;">Both sides are needed to verify your identity.</div>
          <div style="margin-bottom:12px;">
            <div style="font-size:11px;color:var(--g4);margin-bottom:6px;">Front of Fayda ID</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
              <label style="cursor:pointer;background:rgba(255,255,255,.06);border:1px solid rgba(212,175,55,.3);border-radius:8px;padding:10px 14px;font-size:12px;color:#fff;">
                <input type="file" accept="image/*" capture="environment" style="display:none" onchange="etelIDQuickFaydaFile('front', this)">📷 Take Photo
              </label>
              <label style="cursor:pointer;background:rgba(255,255,255,.06);border:1px solid rgba(212,175,55,.3);border-radius:8px;padding:10px 14px;font-size:12px;color:#fff;">
                <input type="file" accept="image/*" style="display:none" onchange="etelIDQuickFaydaFile('front', this)">🖼️ Choose from Gallery
              </label>
            </div>
          </div>
          <div style="margin-bottom:10px;">
            <div style="font-size:11px;color:var(--g4);margin-bottom:6px;">Back of Fayda ID</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
              <label style="cursor:pointer;background:rgba(255,255,255,.06);border:1px solid rgba(212,175,55,.3);border-radius:8px;padding:10px 14px;font-size:12px;color:#fff;">
                <input type="file" accept="image/*" capture="environment" style="display:none" onchange="etelIDQuickFaydaFile('back', this)">📷 Take Photo
              </label>
              <label style="cursor:pointer;background:rgba(255,255,255,.06);border:1px solid rgba(212,175,55,.3);border-radius:8px;padding:10px 14px;font-size:12px;color:#fff;">
                <input type="file" accept="image/*" style="display:none" onchange="etelIDQuickFaydaFile('back', this)">🖼️ Choose from Gallery
              </label>
            </div>
          </div>
          <div id="q-fayda-preview" style="display:flex;gap:8px;margin-bottom:6px;"></div>
        </div>

        <div class="af-terms" style="margin-top:20px;">
          By submitting, you confirm all information is accurate. Your Etel-ID will be issued
          immediately with a temporary password - you'll be asked to set your own right after,
          and you can add your date of birth, address, and other details from your portal any time.
        </div>

        <div class="af-nav-row">
          <button type="button" class="af-btn-back" onclick="etelIDChooseMode(null)">
            <i class="ti ti-arrow-left"></i> Back
          </button>
          <button type="button" class="af-btn-next" id="qr-submit-btn" onclick="etelIDQuickSubmit()" style="padding:12px 28px;font-size:15px;">
            <i class="ti ti-bolt"></i>
            <span id="qr-btn-label">Continue</span>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- == QUICK REGISTER REVIEW == -->
  <div id="qr-review-state" style="display:none;">
    <div class="af-panel">
      <div class="af-panel-head">
        <div class="af-panel-icon"><i class="ti ti-circle-check"></i></div>
        <div>
          <span class="af-panel-title">Review &amp; Confirm</span>
          <span class="af-panel-sub">Confirm your details before issuing your Etel-ID</span>
        </div>
      </div>
      <div class="af-panel-body">
        <div id="qr-review-summary" class="af-review-grid"></div>
        <div class="af-terms" style="margin-top:20px;">
          By confirming, you confirm all information is accurate. Your Etel-ID will be issued
          immediately with a temporary password - you'll be asked to set your own right after.
        </div>
        <div class="af-nav-row">
          <button type="button" class="af-btn-back" onclick="qrBackToEdit()">
            <i class="ti ti-arrow-left"></i> Edit
          </button>
          <button type="button" class="af-btn-next" id="qr-confirm-btn" onclick="qrDoSubmit()" style="padding:12px 28px;font-size:15px;">
            <i class="ti ti-bolt"></i>
            <span id="qr-confirm-lbl">Confirm &amp; Register</span>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ QUICK REGISTER SUCCESS ══ -->
  <div id="qr-success-state" style="display:none;">
    <div class="af-panel">
      <div class="af-panel-body">
        <div class="af-success">
          <div class="af-success-check">✓</div>
          <div class="af-success-title">Your Etel-ID Has Been Issued!</div>
          <div class="af-success-sub">Save your temporary password below - you'll need it to sign in and set a real one.</div>
          <div class="af-success-ids">
            <div class="af-id-card">
              <span class="af-id-lbl">Etel-ID</span>
              <span class="af-id-val" id="qr-res-etel-id">—</span>
            </div>
            <div class="af-id-card">
              <span class="af-id-lbl">Temporary Password</span>
              <div class="af-pw-box">
                <input type="text" class="af-pw-input" id="qr-res-temp-password" value="-" readonly>
                <button type="button" class="af-pw-copy" onclick="apfCopyPassword('qr-res-temp-password', this)">Copy</button>
              </div>
            </div>
          </div>
          <div class="af-terms" style="text-align:left;">
            <i class="ti ti-info-circle"></i> Your name, photo, Fayda ID, email, and phone are saved. Date of birth,
            address, and a few other details are still missing - the portal will remind you to add them, whenever
            you're ready, from Edit Profile.
          </div>
          <div class="af-success-actions" style="margin-top:20px;">
            <a id="qr-res-portal-link" href="<?php echo esc_url(home_url('/portal/')); ?>" class="af-btn-primary-lg">
              <i class="ti ti-layout-dashboard"></i> Go to My Portal
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ FULL REGISTRATION (existing 5-step wizard) ══ -->
  <div id="af-mode-full" style="display:none;">

  <!-- STEP PROGRESS -->
  <div class="af-steps" id="af-steps-bar">
    <div class="af-step active" id="step-dot-1">
      <div class="af-step-dot">1</div>
      <span class="af-step-lbl">Personal</span>
    </div>
    <div class="af-step" id="step-dot-2">
      <div class="af-step-dot">2</div>
      <span class="af-step-lbl">Address</span>
    </div>
    <div class="af-step" id="step-dot-3">
      <div class="af-step-dot">3</div>
      <span class="af-step-lbl">Photo &amp; ID</span>
    </div>
    <div class="af-step" id="step-dot-4">
      <div class="af-step-dot">4</div>
      <span class="af-step-lbl">Review</span>
    </div>
  </div>

  <!-- ERROR NOTICE -->
  <div class="af-error" id="eid-form-error">
    <i class="ti ti-alert-circle" style="font-size:16px;flex-shrink:0;"></i>
    <span id="eid-error-text">Please fill in all required fields.</span>
  </div>

  <!-- ══ STEP 1: Personal ══ -->
  <div class="af-panel" id="eid-step-1">
    <div class="af-panel-head">
      <div class="af-panel-icon">👤</div>
      <div>
        <span class="af-panel-title">Personal Information</span>
        <span class="af-panel-sub">Your full name, date of birth, and contact details</span>
      </div>
    </div>
    <div class="af-panel-body">
      <div class="af-grid">
        <div class="af-field">
          <label class="af-label">Full Name (Latin) <span class="req">*</span></label>
          <input type="text" class="af-input" id="f-full_name" name="full_name"
            placeholder="e.g. Dagmawi Bekele" autocomplete="name">
        </div>
        <div class="af-field">
          <label class="af-label">Full Name (Amharic)</label>
          <input type="text" class="af-input" id="f-full_name_am" name="full_name_am"
            placeholder="ሙሉ ስም" style="font-family:'Noto Sans Ethiopic',sans-serif;">
        </div>
        <div class="af-field">
          <label class="af-label">Date of Birth <span class="req">*</span></label>
          <input type="date" class="af-input" id="f-dob" name="dob" oninput="apfUpdateAgeHint()">
          <span class="af-hint" id="f-age-hint">Gregorian calendar</span>
        </div>
        <div class="af-field">
          <label class="af-label">Sex</label>
          <select class="af-select" id="f-sex" name="sex">
            <option value="Male">Male | ወንድ</option>
            <option value="Female">Female | ሴት</option>
            <option value="Other">Other</option>
          </select>
        </div>
        <div class="af-field">
          <label class="af-label">Blood Type</label>
          <select class="af-select" id="f-blood_type" name="blood_type">
            <option value="">— Optional —</option>
            <?php foreach ($blood as $bt): ?>
            <option value="<?php echo esc_attr($bt); ?>"><?php echo esc_html($bt); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="af-field">
          <label class="af-label">Nationality</label>
          <input type="text" class="af-input" id="f-nationality" name="nationality" value="Ethiopian">
        </div>
        <div class="af-field">
          <label class="af-label">Phone Number <span class="req">*</span></label>
          <input type="tel" class="af-input" id="f-phone" name="phone"
            placeholder="+251 9XX XXX XXX" autocomplete="tel">
        </div>
        <div class="af-field">
          <label class="af-label">Email Address</label>
          <input type="email" class="af-input" id="f-email" name="email"
            placeholder="you@example.com" autocomplete="email">
          <span class="af-hint">We'll send your Etel-ID confirmation here</span>
        </div>
      </div>
      <div class="af-nav-row">
        <span class="af-step-of">Step 1 of 4</span>
        <div class="af-nav-btns">
          <button type="button" class="af-btn-next" onclick="etelIDNext(1)">
            Next <i class="ti ti-arrow-right"></i>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ STEP 2: Address ══ -->
  <div class="af-panel" id="eid-step-2" style="display:none">
    <div class="af-panel-head">
      <div class="af-panel-icon">📍</div>
      <div>
        <span class="af-panel-title">Address Information</span>
        <span class="af-panel-sub">Your location within Ethiopia</span>
      </div>
    </div>
    <div class="af-panel-body">
      <div class="af-grid">
        <div class="af-field">
          <label class="af-label">Region</label>
          <select class="af-select" id="f-region" name="region" onchange="apfPopulateZones()">
            <option value="">— Select Region —</option>
            <?php foreach ($regions as $r): ?>
            <option value="<?php echo esc_attr($r); ?>"><?php echo esc_html($r); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="af-field">
          <label class="af-label">Zone / City</label>
          <select class="af-select" id="f-zone" name="zone" disabled>
            <option value="">-- Select a region first --</option>
          </select>
        </div>
        <div class="af-field af-full">
          <label class="af-label">Woreda / Sub-city</label>
          <input type="text" class="af-input" id="f-woreda" name="woreda" placeholder="e.g. Bole">
        </div>
      </div>
      <div class="af-nav-row">
        <span class="af-step-of">Step 2 of 4</span>
        <div class="af-nav-btns">
          <button type="button" class="af-btn-back" onclick="etelIDBack(2)">
            <i class="ti ti-arrow-left"></i> Back
          </button>
          <button type="button" class="af-btn-next" onclick="etelIDNext(2)">
            Next <i class="ti ti-arrow-right"></i>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- == STEP 3: Photo & Fayda ID == -->
  <div class="af-panel" id="eid-step-3" style="display:none">
    <div class="af-panel-head">
      <div class="af-panel-icon">📸</div>
      <div>
        <span class="af-panel-title">Photo &amp; ID Verification</span>
        <span class="af-panel-sub">Optional now - can also be added later from your portal</span>
      </div>
    </div>
    <div class="af-panel-body">

      <!-- Profile photo -->
      <div class="af-field" style="margin-bottom:20px;">
        <label class="af-label">Profile Photo</label>
        <div class="af-photo-picker"
          style="display:flex;align-items:center;gap:14px;border:1.5px dashed rgba(212,175,55,.35);border-radius:10px;padding:14px;">
          <div id="eid-photo-preview" style="width:56px;height:56px;border-radius:50%;background:rgba(212,175,55,.12);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;font-size:22px;">📷</div>
          <div>
            <div style="font-size:13px;color:var(--g2);">No photo yet</div>
            <div style="font-size:11px;color:var(--g4);">Use the camera directly, or choose one from your gallery</div>
          </div>
        </div>
        <!-- Three explicit triggers instead of one ambiguous file picker.
             capture="user"/"environment" open the device's actual camera
             app directly (front or back lens) - that's what triggers the
             OS's own camera-permission prompt the first time. The
             gallery button has no capture attribute, so it opens the
             plain file/photo browser with no camera permission
             involved. All three feed the same onchange handler. -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
          <label style="cursor:pointer;background:rgba(255,255,255,.06);border:1px solid rgba(212,175,55,.3);border-radius:8px;padding:10px 14px;font-size:12px;color:#fff;">
            <input type="file" id="eid-photo-front" accept="image/*" capture="user" style="display:none" onchange="etelIDPhotoFile(this)">🤳 Take Selfie (Front Camera)
          </label>
          <label style="cursor:pointer;background:rgba(255,255,255,.06);border:1px solid rgba(212,175,55,.3);border-radius:8px;padding:10px 14px;font-size:12px;color:#fff;">
            <input type="file" id="eid-photo-back" accept="image/*" capture="environment" style="display:none" onchange="etelIDPhotoFile(this)">📷 Take Photo (Back Camera)
          </label>
          <label style="cursor:pointer;background:rgba(255,255,255,.06);border:1px solid rgba(212,175,55,.3);border-radius:8px;padding:10px 14px;font-size:12px;color:#fff;">
            <input type="file" id="eid-photo-gallery" accept="image/*" style="display:none" onchange="etelIDPhotoFile(this)">🖼️ Choose from Gallery
          </label>
        </div>
        <div style="font-size:11px;color:var(--g4);margin-top:8px;">JPG, PNG or WebP · The camera buttons will ask for permission the first time - allow it to take the photo.</div>
      </div>

      <!-- Fayda ID front/back -->
      <div class="af-field">
        <label class="af-label">Fayda National ID (optional now)</label>
        <div style="font-size:11px;color:var(--g4);margin-bottom:10px;">
          Add both sides now to speed up verification, or skip and add them later from your portal.
        </div>
        <!-- Camera + gallery per side, same reasoning as the Profile
             Photo section above - capture="environment" opens the back
             camera directly (the natural way to photograph a physical
             card) and triggers the OS's real camera-permission prompt;
             no front-camera/selfie option here since that doesn't apply
             to photographing a document. -->
        <div style="margin-bottom:12px;">
          <div style="font-size:11px;color:var(--g4);margin-bottom:6px;">Front of Fayda ID</div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <label style="cursor:pointer;background:rgba(255,255,255,.06);border:1px solid rgba(212,175,55,.3);border-radius:8px;padding:10px 14px;font-size:12px;color:#fff;">
              <input type="file" accept="image/*" capture="environment" id="eid-fayda-front-cam" style="display:none;" onchange="etelIDFaydaFile('front', this)">📷 Take Photo
            </label>
            <label style="cursor:pointer;background:rgba(255,255,255,.06);border:1px solid rgba(212,175,55,.3);border-radius:8px;padding:10px 14px;font-size:12px;color:#fff;">
              <input type="file" accept="image/*" id="eid-fayda-front-gallery" style="display:none;" onchange="etelIDFaydaFile('front', this)">🖼️ Choose from Gallery
            </label>
          </div>
        </div>
        <div style="margin-bottom:10px;">
          <div style="font-size:11px;color:var(--g4);margin-bottom:6px;">Back of Fayda ID</div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <label style="cursor:pointer;background:rgba(255,255,255,.06);border:1px solid rgba(212,175,55,.3);border-radius:8px;padding:10px 14px;font-size:12px;color:#fff;">
              <input type="file" accept="image/*" capture="environment" id="eid-fayda-back-cam" style="display:none;" onchange="etelIDFaydaFile('back', this)">📷 Take Photo
            </label>
            <label style="cursor:pointer;background:rgba(255,255,255,.06);border:1px solid rgba(212,175,55,.3);border-radius:8px;padding:10px 14px;font-size:12px;color:#fff;">
              <input type="file" accept="image/*" id="eid-fayda-back-gallery" style="display:none;" onchange="etelIDFaydaFile('back', this)">🖼️ Choose from Gallery
            </label>
          </div>
        </div>
        <div id="eid-fayda-preview" style="display:flex;gap:8px;margin-bottom:6px;"></div>
      </div>

      <div class="af-nav-row">
        <span class="af-step-of">Step 3 of 4</span>
        <div class="af-nav-btns">
          <button type="button" class="af-btn-back" onclick="etelIDBack(3)">
            <i class="ti ti-arrow-left"></i> Back
          </button>
          <button type="button" class="af-btn-next" onclick="etelIDNext(3)">
            Continue <i class="ti ti-arrow-right"></i>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- == STEP 4: Review == -->
  <div class="af-panel" id="eid-step-4" style="display:none">
    <div class="af-panel-head">
      <div class="af-panel-icon">✅</div>
      <div>
        <span class="af-panel-title">Review &amp; Submit</span>
        <span class="af-panel-sub">Confirm your details before issuing your Etel-ID</span>
      </div>
    </div>
    <div class="af-panel-body">
      <div id="eid-review-summary" class="af-review-grid"></div>
      <div class="af-terms">
        By submitting, you confirm all information is accurate. Your Etel-ID will be issued
        immediately with a temporary password - you'll be asked to set your own right after you
        sign in. Company assignment will be set up by an administrator afterward. This card
        is the property of
        <?php echo esc_html($org_name); ?> · ኢትኤል ቡድን.
      </div>
      <div class="af-nav-row">
        <span class="af-step-of">Step 4 of 4</span>
        <div class="af-nav-btns">
          <button type="button" class="af-btn-back" onclick="etelIDBack(4)">
            <i class="ti ti-arrow-left"></i> Back
          </button>
          <button type="button" class="af-btn-next" id="eid-submit-btn"
            onclick="etelIDSubmit()" style="padding:12px 28px;font-size:15px;">
            <i class="ti ti-id"></i>
            <span id="eid-btn-label">Issue My Etel-ID</span>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ SUCCESS ══ -->
  <div id="eid-success-state" style="display:none;">
    <div class="af-panel">
      <div class="af-panel-body">
        <div class="af-success">
          <div class="af-success-check">✓</div>
          <div class="af-success-title">Your Etel-ID Has Been Issued!</div>
          <div class="af-success-sub">Save your temporary password below - you'll need it to sign in and set a real one.</div>
          <div class="af-success-ids">
            <div class="af-id-card">
              <span class="af-id-lbl">Etel-ID</span>
              <span class="af-id-val" id="res-etel-id">—</span>
            </div>
            <div class="af-id-card">
              <span class="af-id-lbl">FIN Number</span>
              <span class="af-id-val" id="res-fin">—</span>
            </div>
            <div class="af-id-card">
              <span class="af-id-lbl">Temporary Password</span>
              <div class="af-pw-box">
                <input type="text" class="af-pw-input" id="res-temp-password" value="-" readonly>
                <button type="button" class="af-pw-copy" onclick="apfCopyPassword('res-temp-password', this)">Copy</button>
              </div>
            </div>
          </div>
          <div class="af-success-actions">
            <a id="res-verify-link" href="#" class="af-btn-primary-lg">
              <i class="ti ti-id"></i> View My Card
            </a>
            <a href="<?php echo esc_url(home_url('/login/')); ?>" class="af-btn-outline">
              <i class="ti ti-login"></i> Sign In to Portal
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>

  </div><!-- /af-mode-full -->

  <div class="af-footer">
    <?php echo esc_html($org_name); ?> · ኢትኤል ቡድን ·
    <?php echo esc_html(get_option('etelid_org_website','ethelmarket.com')); ?> ·
    v<?php echo ETELID_VERSION; ?>
  </div>

</div><!-- /af-wrap -->

<script>
var EtelIDConfig = { nonce:'<?php echo esc_js($nonce); ?>', nonceAuth:'<?php echo esc_js($nonce_auth); ?>', ajax:'<?php echo esc_js($ajax); ?>' };
var _eidCurrent  = 1;
var _eidData     = {};
var _ZONES_BY_REGION = <?php echo wp_json_encode( $zones_by_region ); ?>;

/* ── Mode choice (Quick Register vs Full Registration) ──────────
   Both flows live on this same page/URL rather than a separate route,
   so switching between them is just toggling which panel is visible -
   no new rewrite rule needed, and "Back" from either flow returns
   here instead of leaving the page. */
function etelIDChooseMode(mode) {
  document.getElementById('af-mode-choice').style.display = (mode === null) ? 'block' : 'none';
  document.getElementById('af-mode-quick').style.display  = (mode === 'quick') ? 'block' : 'none';
  document.getElementById('af-mode-full').style.display   = (mode === 'full')  ? 'block' : 'none';
  window.scrollTo({top:0, behavior:'smooth'});
}

/* ── Quick Register ───────────────────────────────────────────
   Same canvas-resize-to-base64 technique as the full form's
   etelIDPhotoFile()/etelIDFaydaFile(), just against this panel's own
   element IDs (q-*) so the two forms' file inputs never collide. */
var _qrData = {};
function etelIDQuickPhotoFile(input) {
  if (!input.files || !input.files[0]) return;
  var file = input.files[0];
  if (!file.type.match(/^image\//)) { qrShowErr('Please select an image file (JPG, PNG, WebP).'); return; }
  var reader = new FileReader();
  reader.onload = function(e) {
    var img = new Image();
    img.onload = function() {
      var MAX = 400;
      var w = img.width, h = img.height;
      if (w > MAX || h > MAX) {
        if (w > h) { h = Math.round(h * MAX / w); w = MAX; }
        else       { w = Math.round(w * MAX / h); h = MAX; }
      }
      var canvas = document.createElement('canvas');
      canvas.width = w; canvas.height = h;
      canvas.getContext('2d').drawImage(img, 0, 0, w, h);
      var compressed = canvas.toDataURL('image/jpeg', 0.80);
      _qrData.photo_url = compressed;
      document.getElementById('q-photo-preview').innerHTML =
        '<img src="' + compressed + '" style="width:100%;height:100%;object-fit:cover;">';
    };
    img.src = e.target.result;
  };
  reader.readAsDataURL(file);
}

function etelIDQuickFaydaFile(side, input) {
  var f = input.files[0];
  if (!f) return;
  var reader = new FileReader();
  reader.onload = function(e) {
    _qrData['fayda_' + side + '_image'] = e.target.result;
    var prev = document.getElementById('q-fayda-preview');
    var existing = document.getElementById('q-fayda-prev-' + side);
    var imgHtml = '<img id="q-fayda-prev-' + side + '" src="' + e.target.result + '" style="max-width:120px;max-height:80px;border-radius:6px;border:1px solid rgba(212,175,55,.3);">';
    if (existing) { existing.outerHTML = imgHtml; } else { prev.insertAdjacentHTML('beforeend', imgHtml); }
  };
  reader.readAsDataURL(f);
}

function qrShowErr(msg) {
  var err = document.getElementById('qr-form-error');
  document.getElementById('qr-error-text').textContent = msg;
  err.classList.add('show');
  err.scrollIntoView({behavior:'smooth', block:'center'});
}

/* Same live database checks Full Registration's Step 1 already runs
 * (see apfCheckDuplicatePhone/Name/Email above) - Quick Register is a
 * single-page form with no step boundary, so they all run right
 * before submit instead of on a "Next" click. No DOB field exists
 * here, so apfCheckDuplicateName() is called with an empty dob (the
 * exact name+dob duplicate signal doesn't apply, but the given/
 * father's-name match-level tiering still does). */
/* Runs local field checks, then photo-quality checks (blur/blank
 * heuristic + face detection on the profile photo where the browser
 * supports it - see apfValidatePhotos()'s own comment), then the same
 * live duplicate-check chain as before - but instead of submitting
 * straight away, shows a Review & Confirm screen so a one-tap "Quick
 * Register" can no longer create an account from a single click
 * without the person seeing what they typed first. */
function etelIDQuickSubmit() {
  var fullName = document.getElementById('q-full_name').value.trim();
  var phone    = document.getElementById('q-phone').value.trim();
  var email    = document.getElementById('q-email').value.trim();

  if (!fullName || !phone) { qrShowErr('Full name and phone number are required.'); return; }
  if (!isRealFullName(fullName)) {
    qrShowErr("Enter your real full name - at least two names (e.g. given name and father's name), each at least 3 letters, not repeated or a placeholder word.");
    return;
  }
  if (!isValidEthiopianPhone(phone)) {
    qrShowErr('Enter a valid Ethiopian phone number, e.g. 0912345678 or +251123456789.');
    return;
  }
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    qrShowErr('Enter a valid email address, or leave it blank.');
    return;
  }
  if (!_qrData.photo_url) { qrShowErr('Please add a profile photo.'); return; }
  if (!_qrData.fayda_front_image || !_qrData.fayda_back_image) { qrShowErr('Please add both sides of your Fayda ID.'); return; }

  var btn = document.getElementById('qr-submit-btn');
  var lbl = document.getElementById('qr-btn-label');
  btn.disabled = true;
  lbl.textContent = 'Checking...';

  function reset() { btn.disabled = false; lbl.textContent = 'Continue'; }

  apfValidatePhotos(_qrData.photo_url, _qrData.fayda_front_image, _qrData.fayda_back_image, qrShowErr, function(photosOk) {
    if (!photosOk) { reset(); return; }
    apfCheckDuplicatePhone(phone, function(taken) {
      if (taken) { qrShowErr('An account with this phone number already exists. Please sign in instead.'); reset(); return; }
      apfCheckDuplicateName(fullName, '', function(result) {
        if (!apfApplyNameMatchLevel(result.matchLevel, qrShowErr)) { reset(); return; }
        if (!email) { qrShowReview(fullName, phone, email); reset(); return; }
        apfCheckDuplicateEmail(email, function(taken2) {
          if (taken2) { qrShowErr('This email is already registered. Please use a different email, or sign in instead.'); reset(); return; }
          qrShowReview(fullName, phone, email);
          reset();
        });
      });
    });
  });
}

function qrShowReview(fullName, phone, email) {
  document.getElementById('af-mode-quick').style.display = 'none';
  document.getElementById('qr-review-state').style.display = 'block';
  var rows = [
    ['Full Name', fullName],
    ['Phone', phone],
    ['Email', email || 'Not provided'],
    ['Profile Photo', _qrData.photo_url ? 'Added' : 'Missing'],
    ['Fayda ID', (_qrData.fayda_front_image && _qrData.fayda_back_image) ? 'Added' : 'Missing'],
  ];
  var html = '';
  rows.forEach(function(r) {
    html += '<div class="af-review-item">'
          + '<div class="af-review-lbl">' + apfEsc(r[0]) + '</div>'
          + '<div class="af-review-val">' + apfEsc(r[1]) + '</div>'
          + '</div>';
  });
  document.getElementById('qr-review-summary').innerHTML = html;
  window.scrollTo({top:0, behavior:'smooth'});
}

function qrBackToEdit() {
  document.getElementById('qr-review-state').style.display = 'none';
  document.getElementById('af-mode-quick').style.display = 'block';
  window.scrollTo({top:0, behavior:'smooth'});
}

function qrDoSubmit() {
  var fullName = document.getElementById('q-full_name').value.trim();
  var phone    = document.getElementById('q-phone').value.trim();
  var email    = document.getElementById('q-email').value.trim();
  var btn = document.getElementById('qr-confirm-btn');
  var lbl = document.getElementById('qr-confirm-lbl');
  btn.disabled = true;
  lbl.textContent = 'Registering...';

  var body = 'action=etelid_quick_register&nonce=' + EtelIDConfig.nonceAuth
    + '&full_name=' + encodeURIComponent(fullName)
    + '&phone=' + encodeURIComponent(phone)
    + '&email=' + encodeURIComponent(email)
    + '&photo_url=' + encodeURIComponent(_qrData.photo_url)
    + '&fayda_front_image=' + encodeURIComponent(_qrData.fayda_front_image)
    + '&fayda_back_image=' + encodeURIComponent(_qrData.fayda_back_image);

  fetch(EtelIDConfig.ajax, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: body
  }).then(function(r){ return r.json(); }).then(function(res) {
    if (res.success) {
      document.getElementById('qr-review-state').style.display = 'none';
      document.getElementById('qr-success-state').style.display = 'block';
      document.getElementById('qr-res-etel-id').textContent = res.data.etel_id;
      document.getElementById('qr-res-temp-password').value = res.data.temp_password;
      document.getElementById('qr-res-portal-link').href = res.data.redirect || '<?php echo esc_js(home_url('/portal/')); ?>';
      window.scrollTo({top:0, behavior:'smooth'});
    } else {
      qrShowErr((res.data && res.data.message) ? res.data.message : 'Registration failed. Please try again.');
      btn.disabled = false;
      lbl.textContent = 'Confirm & Register';
    }
  }).catch(function() {
    btn.disabled = false;
    lbl.textContent = 'Confirm & Register';
    qrShowErr('Network error. Please try again.');
  });
}

/* Step 1 (name/dob/phone/email) and Step 2 (region/zone) both need a
 * live round-trip to the database before the wizard can move on - the
 * same duplicate-phone/name/email checks and region<->zone linking
 * already hardened on the /EtelId/ wizard (templates/auth-page.php),
 * ported here since this form submits through the exact same backend
 * (etelid_auth_register) and was missing the live client-side half of
 * that hardening. etelIDNext() stays synchronous for steps 3/4 (no DB
 * round-trip needed there) but goes async for 1 and 2. */
function etelIDNext(from) {
  if (!etelIDValidate(from)) return;
  if (from === 1) {
    var name  = document.getElementById('f-full_name').value.trim();
    var dob   = document.getElementById('f-dob').value;
    var phone = document.getElementById('f-phone').value.trim();
    var email = document.getElementById('f-email').value.trim();
    apfCheckDuplicatePhone(phone, function(taken) {
      if (taken) { showErr('An account with this phone number already exists. Please sign in instead.'); return; }
      apfCheckDuplicateName(name, dob, function(result) {
        if (result.duplicate) { showErr('An account with this name and date of birth already exists. Please sign in instead, or contact support if this is an error.'); return; }
        if (!apfApplyNameMatchLevel(result.matchLevel)) return;
        if (!email) { etelIDCollect(); etelIDGoto(2); return; }
        apfCheckDuplicateEmail(email, function(taken2) {
          if (taken2) { showErr('This email is already registered. Please use a different email, or sign in instead.'); return; }
          etelIDCollect(); etelIDGoto(2);
        });
      });
    });
    return;
  }
  if (from === 3) {
    /* Photo & Fayda ID are optional at this step (can be added later
     * from the portal), so apfValidatePhotos() only checks whichever
     * of the three were actually provided - see its own comment. */
    apfValidatePhotos(_eidData.photo_url || '', _eidData.fayda_front_image || '', _eidData.fayda_back_image || '', showErr, function(ok) {
      if (!ok) return;
      etelIDCollect();
      etelIDGoto(4);
      etelIDRenderReview();
    });
    return;
  }
  etelIDCollect();
  etelIDGoto(from + 1);
  if (from + 1 === 4) etelIDRenderReview();
}
function etelIDBack(from) { etelIDGoto(from - 1); }

function etelIDGoto(n) {
  document.getElementById('eid-step-' + _eidCurrent).style.display = 'none';
  var err = document.getElementById('eid-form-error');
  err.classList.remove('show');
  _eidCurrent = n;
  var panel = document.getElementById('eid-step-' + n);
  panel.style.display = 'block';
  panel.style.animation = 'none';
  panel.offsetHeight; /* reflow */
  panel.style.animation = 'slide-up .4s ease both';
  [1,2,3,4].forEach(function(i) {
    var dot = document.getElementById('step-dot-' + i);
    dot.className = 'af-step' + (i < n ? ' done' : (i === n ? ' active' : ''));
  });
  window.scrollTo({top:0, behavior:'smooth'});
}

/* Profile photo - same canvas-resize-to-base64 technique already used
 * by the Edit Profile page's handlePhotoFile(), so both places produce
 * the same kind of compressed data URL the backend already expects in
 * the photo_url field. */
function etelIDPhotoFile(input) {
  if (!input.files || !input.files[0]) return;
  var file = input.files[0];
  if (!file.type.match(/^image\//)) { showErr('Please select an image file (JPG, PNG, WebP).'); return; }
  var reader = new FileReader();
  reader.onload = function(e) {
    var img = new Image();
    img.onload = function() {
      var MAX = 400;
      var w = img.width, h = img.height;
      if (w > MAX || h > MAX) {
        if (w > h) { h = Math.round(h * MAX / w); w = MAX; }
        else       { w = Math.round(w * MAX / h); h = MAX; }
      }
      var canvas = document.createElement('canvas');
      canvas.width = w; canvas.height = h;
      canvas.getContext('2d').drawImage(img, 0, 0, w, h);
      var compressed = canvas.toDataURL('image/jpeg', 0.80);
      _eidData.photo_url = compressed;
      document.getElementById('eid-photo-preview').innerHTML =
        '<img src="' + compressed + '" style="width:100%;height:100%;object-fit:cover;">';
    };
    img.src = e.target.result;
  };
  reader.readAsDataURL(file);
}

/* Fayda front/back - same plain FileReader-to-base64 technique the Edit
 * Profile page's fidPreview() already uses for this exact document
 * type, stored straight into _eidData for etelIDSubmit() to send.
 * Takes the actual <input> element now, not a hardcoded ID lookup -
 * each side has two separate inputs now (camera + gallery), not one,
 * so 'eid-fayda-' + side no longer resolves to a real element. */
var _eidFaydaData = {};
function etelIDFaydaFile(side, input) {
  var f = input.files[0];
  if (!f) return;
  var reader = new FileReader();
  reader.onload = function(e) {
    _eidFaydaData[side] = e.target.result;
    _eidData['fayda_' + side + '_image'] = e.target.result;
    var prev = document.getElementById('eid-fayda-preview');
    var existing = document.getElementById('eid-fayda-prev-' + side);
    var imgHtml = '<img id="eid-fayda-prev-' + side + '" src="' + e.target.result + '" style="max-width:120px;max-height:80px;border-radius:6px;border:1px solid rgba(212,175,55,.3);">';
    if (existing) { existing.outerHTML = imgHtml; } else { prev.insertAdjacentHTML('beforeend', imgHtml); }
  };
  reader.readAsDataURL(f);
}

/* -- Validation hardening ported from templates/auth-page.php's /EtelId/
 * wizard, which already went through several rounds of live-testing
 * fixes - this form submits through the exact same backend
 * (etelid_auth_register) so the same real-name, phone-format, and
 * duplicate-checking rules apply; this was the missing client-side
 * half of that hardening on this form specifically. -- */
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
    if (seen[lw]) return false;
    seen[lw] = true;
  }
  return true;
}
function isRealAmharicName(nameAm) {
  var words = nameAm.split(/\s+/).filter(function(w){ return w.length > 0; });
  if (words.length < 2) return false;
  var seen = {};
  for (var i = 0; i < words.length; i++) {
    var w = words[i];
    if (w.length < 3 || countAmharicChars(w) < 3) return false;
    if (seen[w]) return false;
    seen[w] = true;
  }
  return true;
}
function isValidEthiopianPhone(phone) {
  var digits = (phone || '').replace(/[^0-9]/g, '');
  if (digits.length === 12 && digits.slice(0,3) === '251') digits = digits.slice(3);
  else if (digits.length === 10 && digits[0] === '0') digits = digits.slice(1);
  return /^[79][0-9]{8}$/.test(digits);
}
function ageFromDob(dob) {
  var d = new Date(dob + 'T00:00:00');
  if (isNaN(d.getTime())) return null;
  var now = new Date();
  var age = now.getFullYear() - d.getFullYear();
  var monthDiff = now.getMonth() - d.getMonth();
  if (monthDiff < 0 || (monthDiff === 0 && now.getDate() < d.getDate())) age--;
  return age;
}
function apfUpdateAgeHint() {
  var dob  = document.getElementById('f-dob').value;
  var hint = document.getElementById('f-age-hint');
  if (!dob) { hint.textContent = 'Gregorian calendar'; return; }
  var age = ageFromDob(dob);
  if (age === null) { hint.textContent = 'Gregorian calendar'; }
  else if (age < 0)  { hint.textContent = 'That date is in the future.'; }
  else { hint.textContent = 'Age: ' + age + (age === 1 ? ' year' : ' years'); }
}
function apfPopulateZones() {
  var region = document.getElementById('f-region').value;
  var sel    = document.getElementById('f-zone');
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
  placeholder.textContent = '-- Select --';
  sel.appendChild(placeholder);
  zones.forEach(function(z){
    var o = document.createElement('option');
    o.value = z; o.textContent = z;
    sel.appendChild(o);
  });
}
/* Live duplicate checks against the database, same AJAX actions and
 * blocking rules templates/auth-page.php's wizard already uses (see
 * EtelID_DB::name_match_level()'s own comment for the 0-3 tiering) -
 * this form has no per-field error UI, only the single eid-form-error
 * banner, so a blocking result surfaces through showErr() instead of a
 * fieldErr()-style inline message. */
function apfPost(data, cb) {
  data.nonce = EtelIDConfig.nonceAuth;
  var body = Object.keys(data).map(function(k){return encodeURIComponent(k)+'='+encodeURIComponent(data[k]);}).join('&');
  fetch(EtelIDConfig.ajax,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
    .then(function(r){return r.json();}).then(cb)
    .catch(function(){cb({success:false,data:{message:'Network error. Please try again.'}});});
}
var _apfDupPhoneCheck = { key: '', taken: false, checked: false };
function apfCheckDuplicatePhone(phone, cb) {
  var key = phone.toLowerCase();
  if (_apfDupPhoneCheck.checked && _apfDupPhoneCheck.key === key) { cb(_apfDupPhoneCheck.taken); return; }
  apfPost({ action: 'etelid_check_duplicate_phone', phone: phone }, function(r) {
    var taken = !!(r.success && r.data && r.data.taken);
    _apfDupPhoneCheck = { key: key, taken: taken, checked: true };
    cb(taken);
  });
}
var _apfDupNameCheck = { key: '', result: null, checked: false };
function apfCheckDuplicateName(name, dob, cb) {
  var key = name.toLowerCase() + '|' + dob;
  if (_apfDupNameCheck.checked && _apfDupNameCheck.key === key) { cb(_apfDupNameCheck.result); return; }
  apfPost({ action: 'etelid_check_duplicate_name', full_name: name, dob: dob }, function(r) {
    var result = {
      duplicate:  !!(r.success && r.data && r.data.duplicate),
      matchLevel: (r.success && r.data && r.data.match_level) ? r.data.match_level : 0,
    };
    _apfDupNameCheck = { key: key, result: result, checked: true };
    cb(result);
  });
}
var _apfDupEmailCheck = { key: '', taken: false, checked: false };
function apfCheckDuplicateEmail(email, cb) {
  var key = email.toLowerCase();
  if (_apfDupEmailCheck.checked && _apfDupEmailCheck.key === key) { cb(_apfDupEmailCheck.taken); return; }
  apfPost({ action: 'etelid_check_duplicate_email', email: email }, function(r) {
    var taken = !!(r.success && r.data && r.data.taken);
    _apfDupEmailCheck = { key: key, taken: taken, checked: true };
    cb(taken);
  });
}
function apfEsc(s) {
  var d = document.createElement('div');
  d.textContent = String(s == null ? '' : s);
  return d.innerHTML;
}
/* -- Photo quality heuristics (client-side, no server/API needed) --
 * A live-reported case showed a random photo (a close-up of clothing
 * fabric) passing straight through as both the profile photo and the
 * Fayda ID sides. There is no server-side service here (no paid vision
 * API is connected) that could reliably confirm "this is a face" or
 * "this is a genuine ID document", so this is deliberately a
 * best-effort, free, client-side check rather than a guarantee:
 *   - apfImageSharpness() estimates detail/focus via average gradient
 *     magnitude on a downscaled grayscale copy of the image (a simple
 *     Sobel-like edge measure) - catches an image that's blank, a
 *     near-solid color, or badly out of focus (e.g. a finger over the
 *     lens), which a real photo of a face or document won't be. The
 *     threshold is intentionally lenient to avoid rejecting real
 *     photos taken in imperfect conditions.
 *   - For the profile photo specifically, the browser's own
 *     FaceDetector API (Shape Detection API - available in Chrome on
 *     Android, which is what was used live; not on every browser) is
 *     used to confirm an actual face is present. Where it's
 *     unavailable, this check is skipped rather than blocking
 *     registration on browsers that can't run it (fails open, not
 *     closed - it's a bonus check, not the only gate: the sharpness
 *     check above still runs everywhere). */
function apfImageSharpness(dataUrl, cb) {
  var img = new Image();
  img.onload = function() {
    var W = 80, H = Math.max(1, Math.round(80 * img.height / img.width));
    var canvas = document.createElement('canvas');
    canvas.width = W; canvas.height = H;
    var ctx = canvas.getContext('2d');
    ctx.drawImage(img, 0, 0, W, H);
    var data;
    try { data = ctx.getImageData(0, 0, W, H).data; } catch (e) { cb(null); return; }
    var gray = new Float32Array(W * H);
    for (var i = 0; i < W * H; i++) {
      gray[i] = 0.299 * data[i * 4] + 0.587 * data[i * 4 + 1] + 0.114 * data[i * 4 + 2];
    }
    var sum = 0, n = 0;
    for (var y = 0; y < H - 1; y++) {
      for (var x = 0; x < W - 1; x++) {
        var idx = y * W + x;
        sum += Math.abs(gray[idx] - gray[idx + 1]) + Math.abs(gray[idx] - gray[idx + W]);
        n++;
      }
    }
    cb(n ? sum / n : 0);
  };
  img.onerror = function() { cb(null); };
  img.src = dataUrl;
}
function apfCheckPhotoQuality(dataUrl, checkFace, cb) {
  if (!dataUrl) { cb(true); return; }
  apfImageSharpness(dataUrl, function(sharpness) {
    if (sharpness !== null && sharpness < 3) {
      cb(false, 'That photo looks blank or too blurry to be useful. Please retake it in better light, holding the camera steady, or upload a clearer photo.');
      return;
    }
    if (checkFace && typeof FaceDetector !== 'undefined') {
      var img = new Image();
      img.onload = function() {
        try {
          var detector = new FaceDetector({ fastMode: true, maxDetectedFaces: 1 });
          detector.detect(img).then(function(faces) {
            if (!faces || !faces.length) {
              cb(false, "We couldn't find a face in that photo. Please take a clear selfie of yourself, or upload a real photo.");
            } else {
              cb(true);
            }
          }).catch(function() { cb(true); });
        } catch (e) { cb(true); }
      };
      img.onerror = function() { cb(true); };
      img.src = dataUrl;
    } else {
      cb(true);
    }
  });
}
function apfValidatePhotos(photoUrl, faydaFront, faydaBack, showFn, cb) {
  apfCheckPhotoQuality(photoUrl, true, function(ok, msg) {
    if (!ok) { showFn(msg); cb(false); return; }
    apfCheckPhotoQuality(faydaFront, false, function(ok2, msg2) {
      if (!ok2) { showFn(msg2); cb(false); return; }
      apfCheckPhotoQuality(faydaBack, false, function(ok3, msg3) {
        if (!ok3) { showFn(msg3); cb(false); return; }
        cb(true);
      });
    });
  });
}
/* Mirrors applyNameMatchLevel() in auth-page.php: level 1 (given name
 * only) is too common in Ethiopian naming to act on; level 2 (given +
 * father's name both match) blocks, asking for a grandfather's name;
 * level 3 (the full entered name matches) is the strongest signal
 * available but still not DOB-backed, so it's allowed through. */
/* Copies the temp-password field's value and gives brief visual
 * confirmation - clipboard access needs a secure (https) context and
 * can be denied by the browser, so this falls back to just selecting
 * the text (still lets the person copy it manually) rather than
 * failing silently. */
function apfCopyPassword(fieldId, btn) {
  var field = document.getElementById(fieldId);
  field.select();
  field.setSelectionRange(0, 99999);
  function showCopied() {
    var original = btn.textContent;
    btn.textContent = 'Copied!';
    btn.classList.add('copied');
    setTimeout(function() { btn.textContent = original; btn.classList.remove('copied'); }, 1800);
  }
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(field.value).then(showCopied).catch(function(){});
  }
}
function apfApplyNameMatchLevel(level, errFn) {
  errFn = errFn || showErr;
  if (level >= 2) {
    errFn("This given name and father's name match an existing record. Please also add your grandfather's name (a 3rd name), then continue.");
    return false;
  }
  return true;
}
document.getElementById('f-phone').addEventListener('blur', function() {
  var phone = this.value.trim();
  if (!phone || !isValidEthiopianPhone(phone)) return;
  apfCheckDuplicatePhone(phone, function(taken) {
    if (taken) showErr('An account with this phone number already exists. Please sign in instead.');
  });
});
document.getElementById('f-email').addEventListener('blur', function() {
  var email = this.value.trim();
  if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return;
  apfCheckDuplicateEmail(email, function(taken) {
    if (taken) showErr('This email is already registered. Please use a different email, or sign in instead.');
  });
});
/* Same blur checks, wired to Quick Register's own fields (q-*) and
 * error banner (qrShowErr) instead of Full Registration's (f- fields, showErr). */
document.getElementById('q-phone').addEventListener('blur', function() {
  var phone = this.value.trim();
  if (!phone || !isValidEthiopianPhone(phone)) return;
  apfCheckDuplicatePhone(phone, function(taken) {
    if (taken) qrShowErr('An account with this phone number already exists. Please sign in instead.');
  });
});
document.getElementById('q-full_name').addEventListener('blur', function() {
  var name = this.value.trim();
  if (!name || !isRealFullName(name)) return;
  apfCheckDuplicateName(name, '', function(result) {
    apfApplyNameMatchLevel(result.matchLevel, qrShowErr);
  });
});
document.getElementById('q-email').addEventListener('blur', function() {
  var email = this.value.trim();
  if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return;
  apfCheckDuplicateEmail(email, function(taken) {
    if (taken) qrShowErr('This email is already registered. Please use a different email, or sign in instead.');
  });
});

function etelIDValidate(step) {
  if (step === 1) {
    var name   = document.getElementById('f-full_name').value.trim();
    var nameAm = document.getElementById('f-full_name_am').value.trim();
    var dob    = document.getElementById('f-dob').value;
    var phone  = document.getElementById('f-phone').value.trim();
    var email  = document.getElementById('f-email').value.trim();
    if (!name || !dob || !phone) {
      showErr('Please fill in all required fields (marked with *).');
      return false;
    }
    if (!isRealFullName(name)) {
      showErr("Enter your real full name - at least two names (e.g. given name and father's name), each at least 3 letters, not repeated or a placeholder word.");
      return false;
    }
    if (nameAm && !isRealAmharicName(nameAm)) {
      showErr('Enter your full name in Amharic script (at least two real words, not repeated), or leave that field blank.');
      return false;
    }
    var age = ageFromDob(dob);
    if (age === null) { showErr('Enter a valid date of birth.'); return false; }
    if (age < 0)  { showErr('Date of birth cannot be in the future.'); return false; }
    if (age < 10) { showErr('You must be at least 10 years old to register.'); return false; }
    if (age > 120) { showErr('Enter a valid date of birth.'); return false; }
    if (!isValidEthiopianPhone(phone)) {
      showErr('Enter a valid Ethiopian phone number, e.g. 0912345678 or +251123456789.');
      return false;
    }
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      showErr('Enter a valid email address, or leave it blank.');
      return false;
    }
  }
  if (step === 2) {
    var region = document.getElementById('f-region').value;
    var zone   = document.getElementById('f-zone').value.trim();
    if (!region) { showErr('Region is required.'); return false; }
    if (!zone)   { showErr('Please select your zone/city.'); return false; }
    if ((_ZONES_BY_REGION[region] || []).indexOf(zone) === -1) {
      showErr('Select a zone/city that belongs to the chosen region.');
      return false;
    }
  }
  if (step === 3) {
    // Fayda ID is optional here, but if either side was added, both
    // must be present - same rule the backend enforces, checked here
    // too so the person finds out before submitting, not after.
    var hasFront = !!_eidFaydaData.front, hasBack = !!_eidFaydaData.back;
    if (hasFront !== hasBack) {
      showErr('Please add both sides of your Fayda ID, or skip this step entirely for now.');
      return false;
    }
  }
  return true;
}

function showErr(msg) {
  var err = document.getElementById('eid-form-error');
  document.getElementById('eid-error-text').textContent = msg;
  err.classList.add('show');
  err.scrollIntoView({behavior:'smooth', block:'center'});
}

function etelIDCollect() {
  /* No 'password'/'password_confirm' here - the person no longer sets
   * their own password on this form at all (see etelIDSubmit()'s own
   * comment); the backend issues a temporary one instead. */
  ['full_name','full_name_am','dob','sex','blood_type','nationality',
   'phone','email','region','zone','woreda'].forEach(function(f) {
    var el = document.getElementById('f-' + f);
    if (el) _eidData[f] = el.value;
  });
}

function etelIDRenderReview() {
  etelIDCollect();
  var d = _eidData;
  var rows = [
    ['Full Name', d.full_name], ['Amharic Name', d.full_name_am || '—'],
    ['Date of Birth', d.dob], ['Sex', d.sex],
    ['Blood Type', d.blood_type || '—'], ['Nationality', d.nationality],
    ['Phone', d.phone], ['Email', d.email || '—'],
    ['Region', d.region || '—'], ['Zone', d.zone || '—'],
    ['Photo & Fayda ID', (d.photo_url || (d.fayda_front_image && d.fayda_back_image)) ? 'Added' : 'Skipped (can add later from your portal)'],
  ];
  var html = '';
  rows.forEach(function(r) {
    html += '<div class="af-review-item">'
          + '<div class="af-review-lbl">' + r[0] + '</div>'
          + '<div class="af-review-val">' + (r[1] || '—') + '</div>'
          + '</div>';
  });
  document.getElementById('eid-review-summary').innerHTML = html;
}

function etelIDSubmit() {
  etelIDCollect();
  var btn = document.getElementById('eid-submit-btn');
  var lbl = document.getElementById('eid-btn-label');
  btn.disabled = true;
  lbl.textContent = 'Issuing your ID…';
  /* Submits through etelid_auth_register (EtelID_Auth::register()), not the
     old etelid_apply action - auto-logs the member in and sends the welcome
     email. No 'password'/'password_confirm' sent at all any more - omitting
     them tells EtelID_Auth::register() to issue a temporary password
     instead (same mechanism Quick Register already uses), so the person
     never has to invent one at signup; res.data.temp_password below is
     what they'll use to sign in and then be prompted to change.
     Company/role/department/employee_number are no longer collected here
     at all - an administrator assigns those afterward from the admin
     panel, instead of a new member guessing at their own org assignment
     during self-registration. */
  var body = 'action=etelid_auth_register&nonce=' + EtelIDConfig.nonceAuth;
  var d = _eidData;
  ['full_name','full_name_am','dob','sex','blood_type','nationality',
   'phone','email','region','zone','woreda',
   'photo_url','fayda_front_image','fayda_back_image'].forEach(function(k) {
    if (d[k]) body += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(d[k]);
  });
  fetch(EtelIDConfig.ajax, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: body
  }).then(function(r){ return r.json(); }).then(function(res) {
    if (res.success) {
      document.getElementById('eid-step-4').style.display = 'none';
      document.getElementById('eid-success-state').style.display = 'block';
      document.getElementById('res-etel-id').textContent = res.data.etel_id;
      document.getElementById('res-fin').textContent     = res.data.fin;
      document.getElementById('res-temp-password').value = res.data.temp_password;
      document.getElementById('res-verify-link').href    = res.data.verify_url;
      [1,2,3,4].forEach(function(i){
        document.getElementById('step-dot-'+i).className = 'af-step done';
      });
      window.scrollTo({top:0, behavior:'smooth'});
    } else {
      showErr((res.data && res.data.message) ? res.data.message : 'Submission failed. Please try again.');
      btn.disabled = false;
      lbl.textContent = 'Issue My Etel-ID';
    }
  }).catch(function() {
    btn.disabled = false;
    lbl.textContent = 'Issue My Etel-ID';
    showErr('Network error. Please try again.');
  });
}
</script>
<!-- ETEL AI -- cross-company assistant widget -->
<script src="https://ethelmarket.com/EtelAi/etel-ai-widget.js" data-project="etel-id"></script>
</body>
</html>
