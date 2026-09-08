<?php
/**
 * Etel-ID Premium — Reset Password v1.0
 * Standalone — no WP theme. Same visual system as apply-form.php.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( class_exists('EtelID_Auth') && EtelID_Auth::is_logged_in() ) {
    wp_redirect( home_url('/portal/') ); exit;
}

$logo_src = defined('ETELID_LOGO_B64') ? ETELID_LOGO_B64 : ETELID_URL.'public/images/etel-logo.png';
$org_name = get_option('etelid_org_name','Etel Group');
$nonce    = wp_create_nonce('etelid_public');
$ajax     = admin_url('admin-ajax.php');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0">
<title>Reset Your Password · <?php echo esc_html($org_name); ?></title>
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
@keyframes shake{10%,90%{transform:translateX(-1px)}20%,80%{transform:translateX(2px)}30%,50%,70%{transform:translateX(-4px)}40%,60%{transform:translateX(4px)}}

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
.af-wrap{max-width:560px;margin:0 auto;padding:24px 16px 60px;position:relative;z-index:1;}

/* ════════════════════════════════════
   STEP PROGRESS BAR
════════════════════════════════════ */
.af-steps{display:flex;align-items:center;padding:0 10px;margin-bottom:28px;position:relative;}
.af-steps::before{
  content:'';position:absolute;top:20px;left:20%;right:20%;height:2px;
  background:rgba(212,175,55,.15);z-index:0;
}
.af-step{flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;position:relative;z-index:1;}
.af-step-dot{
  width:40px;height:40px;border-radius:50%;background:rgba(20,10,0,.6);
  border:2px solid rgba(212,175,55,.25);display:flex;align-items:center;justify-content:center;
  font-family:'Syne',sans-serif;font-size:14px;font-weight:800;color:rgba(212,175,55,.4);
  transition:all .3s cubic-bezier(.22,.68,0,1.2);box-shadow:0 0 0 4px rgba(20,10,0,.4);
}
.af-step-lbl{font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:1px;color:rgba(212,175,55,.4);transition:color .3s;}
.af-step.active .af-step-dot{
  background:linear-gradient(135deg,var(--g5),var(--g4));border-color:var(--g4);color:var(--g9);
  box-shadow:0 0 0 4px rgba(20,10,0,.4),0 0 0 6px rgba(212,175,55,.2),0 4px 14px rgba(100,60,0,.3);
}
.af-step.active .af-step-lbl{color:var(--g4);}
.af-step.done .af-step-dot{
  background:linear-gradient(135deg,#166534,#22c55e);border-color:#22c55e;color:#fff;
  box-shadow:0 0 0 4px rgba(20,10,0,.4),0 4px 10px rgba(22,101,52,.25);
}
.af-step.done .af-step-lbl{color:#86efac;}

/* ════════════════════════════════════
   ERROR / INFO NOTICES
════════════════════════════════════ */
.af-error{
  display:none;align-items:center;gap:10px;padding:12px 16px;border-radius:10px;
  background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.28);
  color:#fca5a5;font-family:'JetBrains Mono',monospace;font-size:12px;margin-bottom:16px;
}
.af-error.show{display:flex;animation:slide-up .25s ease both;}
.af-info{
  display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:10px;
  background:rgba(212,175,55,.08);border:1px solid rgba(212,175,55,.25);
  color:var(--g3);font-size:14px;line-height:1.6;margin-bottom:18px;
}

/* ════════════════════════════════════
   SECTION CARD
════════════════════════════════════ */
.af-panel{
  background:var(--glass);border:1.5px solid var(--glass-border);border-radius:18px;overflow:hidden;
  backdrop-filter:blur(14px);box-shadow:0 8px 36px rgba(10,5,0,.3),inset 0 1px 0 rgba(255,255,255,.07);
  animation:slide-up .4s ease both;
}
.af-panel-head{
  padding:16px 22px;background:linear-gradient(135deg,rgba(74,50,16,.95),rgba(26,14,0,.88));
  border-bottom:1.5px solid rgba(212,175,55,.28);display:flex;align-items:center;gap:12px;position:relative;overflow:hidden;
}
.af-panel-head::after{
  content:'';position:absolute;bottom:0;left:0;right:0;height:1px;
  background:linear-gradient(90deg,transparent,var(--g4),transparent);
}
.af-panel-icon{
  width:40px;height:40px;border-radius:11px;background:linear-gradient(135deg,var(--g6),var(--g8));
  display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;
  box-shadow:0 2px 10px rgba(0,0,0,.35);
}
.af-panel-title{font-family:'Syne',sans-serif;font-size:16px;font-weight:800;color:var(--g1);display:block;line-height:1.1;}
.af-panel-sub{font-size:12px;color:var(--g5);display:block;margin-top:2px;}
.af-panel-body{padding:24px 22px;}

/* ════════════════════════════════════
   FIELDS
════════════════════════════════════ */
.af-field{display:flex;flex-direction:column;gap:5px;margin-bottom:16px;}
.af-label{
  font-family:'JetBrains Mono',monospace;font-size:9.5px;font-weight:700;color:var(--g5);
  text-transform:uppercase;letter-spacing:1.8px;display:flex;align-items:center;gap:4px;
}
.af-label .req{color:#f87171;}
.af-hint{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--g6);margin-top:3px;}
.af-input{
  width:100%;padding:12px 15px;background:rgba(212,175,55,.07);
  border:1.5px solid rgba(212,175,55,.22);border-radius:10px;
  font-size:15px;font-family:'Cormorant Garamond',serif;color:var(--g1);
  transition:border-color .2s,box-shadow .2s,background .2s;
  -webkit-appearance:none;appearance:none;box-shadow:inset 0 2px 4px rgba(0,0,0,.15);
}
.af-input:focus{
  border-color:var(--g4);outline:none;background:rgba(212,175,55,.1);
  box-shadow:0 0 0 3px rgba(212,175,55,.15),inset 0 2px 4px rgba(0,0,0,.08);
}
.af-input::placeholder{color:rgba(212,175,55,.3);}

/* ════════════════════════════════════
   CHANNEL SELECTOR (pill group)
════════════════════════════════════ */
.af-channel-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
.af-channel-pill{
  display:flex;align-items:center;gap:7px;padding:10px 12px;cursor:pointer;
  background:rgba(212,175,55,.07);border:1.5px solid rgba(212,175,55,.22);
  border-radius:10px;transition:border-color .2s,background .2s;
  font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--g2);
}
.af-channel-pill:hover{border-color:rgba(212,175,55,.4);}
.af-channel-pill input{accent-color:var(--g4);width:14px;height:14px;flex-shrink:0;}
.af-channel-pill.checked{border-color:var(--g4);background:rgba(212,175,55,.14);color:var(--g1);}
.af-channel-pill i{font-size:15px;color:var(--g4);flex-shrink:0;}
.af-channel-pill.pill-disabled{cursor:not-allowed;opacity:.45;}
.af-soon{margin-left:auto;font-size:9px;letter-spacing:.02em;color:var(--g4);white-space:nowrap;}

/* code entry - big, centered, letter-spaced */
.af-code-input{
  width:100%;padding:16px 15px;text-align:center;
  font-family:'JetBrains Mono',monospace;font-size:28px;font-weight:700;letter-spacing:14px;
  background:rgba(212,175,55,.07);border:1.5px solid rgba(212,175,55,.22);border-radius:12px;
  color:var(--g1);transition:border-color .2s,box-shadow .2s;
}
.af-code-input:focus{border-color:var(--g4);outline:none;box-shadow:0 0 0 3px rgba(212,175,55,.15);}
.af-code-input::placeholder{letter-spacing:normal;font-size:15px;color:rgba(212,175,55,.3);}

/* password field with show/hide toggle */
.af-pw-wrap{position:relative;}
.af-pw-toggle{
  position:absolute;right:12px;top:50%;transform:translateY(-50%);
  background:none;border:none;color:var(--g5);cursor:pointer;font-size:17px;padding:4px;
}
.af-pw-toggle:hover{color:var(--g3);}

/* strength meter */
.af-strength{display:flex;gap:5px;margin-top:8px;}
.af-strength-bar{flex:1;height:5px;border-radius:3px;background:rgba(212,175,55,.15);transition:background .25s;}
.af-strength-lbl{font-family:'JetBrains Mono',monospace;font-size:10px;margin-top:6px;letter-spacing:.5px;color:var(--g6);}

/* ════════════════════════════════════
   BUTTONS
════════════════════════════════════ */
.af-btn-next{
  width:100%;display:inline-flex;align-items:center;justify-content:center;gap:7px;
  padding:14px 24px;border:none;border-radius:10px;cursor:pointer;
  background:linear-gradient(135deg,var(--g9),var(--g7),var(--g5),var(--g4));
  color:var(--g1);font-family:'Syne',sans-serif;font-size:15px;font-weight:800;
  box-shadow:0 4px 0 var(--g9),0 6px 18px rgba(80,40,0,.3);
  transition:all .15s;position:relative;overflow:hidden;letter-spacing:.3px;margin-top:6px;
}
.af-btn-next::before{
  content:'';position:absolute;top:0;left:-100%;width:50%;height:100%;
  background:linear-gradient(90deg,transparent,rgba(255,255,255,.12),transparent);
  animation:shimmer 2.5s infinite;
}
.af-btn-next:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 5px 0 var(--g9),0 8px 22px rgba(80,40,0,.35);}
.af-btn-next:disabled{opacity:.6;cursor:not-allowed;transform:none;}

.af-resend{
  display:block;text-align:center;margin-top:16px;background:none;border:none;cursor:pointer;
  font-family:'JetBrains Mono',monospace;font-size:11.5px;color:var(--g4);letter-spacing:.4px;
}
.af-resend:disabled{color:rgba(212,175,55,.35);cursor:not-allowed;}
.af-resend:hover:not(:disabled){color:var(--g2);}

/* ════════════════════════════════════
   SUCCESS STATE
════════════════════════════════════ */
.af-success{text-align:center;padding:48px 20px;animation:slide-up .5s ease both;}
.af-success-check{
  width:80px;height:80px;border-radius:50%;margin:0 auto 20px;
  background:linear-gradient(135deg,#166534,#22c55e);display:flex;align-items:center;justify-content:center;
  font-size:36px;color:#fff;box-shadow:0 0 0 8px rgba(34,197,94,.15),0 10px 28px rgba(22,101,52,.35);
  animation:badge-in .6s cubic-bezier(.22,.68,0,1.2) both;
}
.af-success-title{font-family:'Syne',sans-serif;font-size:26px;font-weight:800;color:var(--g1);margin-bottom:8px;}
.af-success-sub{font-size:15px;color:var(--g4);margin-bottom:28px;}
.af-success-actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;}
.af-btn-primary-lg{
  display:inline-flex;align-items:center;gap:8px;padding:14px 28px;border-radius:10px;
  background:linear-gradient(135deg,var(--g9),var(--g7),var(--g5),var(--g4));
  color:var(--g1);font-family:'Syne',sans-serif;font-size:15px;font-weight:800;
  text-decoration:none;box-shadow:0 5px 0 var(--g9),0 8px 22px rgba(80,40,0,.3);transition:all .15s;
}
.af-btn-primary-lg:hover{transform:translateY(-1px);color:var(--g1);text-decoration:none;}

.af-shake{animation:shake .4s;}

/* ════════════════════════════════════
   FOOTER
════════════════════════════════════ */
.af-footer{
  text-align:center;padding:24px 0 0;font-family:'JetBrains Mono',monospace;font-size:8px;
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
    <div class="af-nav-title">Reset Your Password</div>
    <span class="af-nav-sub">ኢትኤል ቡድን · Official Corporate Identity</span>
  </div>
  <a href="<?php echo esc_url(home_url('/login/')); ?>" class="af-nav-login">
    <i class="ti ti-login"></i> Sign In
  </a>
</nav>

<div class="af-wrap">

  <!-- STEP PROGRESS -->
  <div class="af-steps" id="rp-steps-bar">
    <div class="af-step active" id="step-dot-1">
      <div class="af-step-dot">1</div>
      <span class="af-step-lbl">Verify Identity</span>
    </div>
    <div class="af-step" id="step-dot-2">
      <div class="af-step-dot">2</div>
      <span class="af-step-lbl">New Password</span>
    </div>
  </div>

  <!-- ERROR NOTICE -->
  <div class="af-error" id="rp-form-error">
    <i class="ti ti-alert-circle" style="font-size:16px;flex-shrink:0;"></i>
    <span id="rp-error-text"></span>
  </div>

  <!-- ══ STEP 1: Verify Identity ══ -->
  <div class="af-panel" id="rp-step-1">
    <div class="af-panel-head">
      <div class="af-panel-icon">🔑</div>
      <div>
        <span class="af-panel-title">Verify Your Identity</span>
        <span class="af-panel-sub">Enter the phone number or Etel-ID on your account</span>
      </div>
    </div>
    <div class="af-panel-body">
      <div class="af-info">
        <i class="ti ti-mail" style="font-size:18px;margin-top:1px;flex-shrink:0;"></i>
        <span>We'll send a 6-digit code to your email or Telegram if you've linked either — otherwise we'll send it straight to your phone via WhatsApp or SMS.</span>
      </div>
      <div class="af-field">
        <label class="af-label">Phone or Etel-ID <span class="req">*</span></label>
        <input type="text" class="af-input" id="rp-identifier" placeholder="+251 9XX XXX XXX or EG-2026-00001" autocomplete="username">
      </div>
      <div class="af-field">
        <label class="af-label">Send my code via</label>
        <div class="af-channel-grid" id="rp-channel-grid">
          <label class="af-channel-pill checked">
            <input type="radio" name="rp-channel" value="auto" checked onchange="rpChannelChanged()">
            <i class="ti ti-wand"></i> Any available
          </label>
          <label class="af-channel-pill">
            <input type="radio" name="rp-channel" value="email" onchange="rpChannelChanged()">
            <i class="ti ti-mail"></i> Email
          </label>
          <label class="af-channel-pill">
            <input type="radio" name="rp-channel" value="telegram" onchange="rpChannelChanged()">
            <i class="ti ti-brand-telegram"></i> Telegram
          </label>
          <label class="af-channel-pill pill-disabled">
            <input type="radio" name="rp-channel" value="whatsapp" disabled>
            <i class="ti ti-brand-whatsapp"></i> WhatsApp <span class="af-soon">Coming soon 🔜</span>
          </label>
          <label class="af-channel-pill pill-disabled" style="grid-column:1 / -1">
            <input type="radio" name="rp-channel" value="sms" disabled>
            <i class="ti ti-message"></i> SMS <span class="af-soon">Coming soon 🔜</span>
          </label>
        </div>
        <span class="af-hint">"Any available" tries email first, then Telegram, then WhatsApp/SMS to your phone — pick one directly if you know which you'd rather use.</span>
      </div>
      <button type="button" class="af-btn-next" id="rp-send-btn" onclick="rpSendCode()">
        <i class="ti ti-send"></i> Send Reset Code
      </button>
    </div>
  </div>

  <!-- ══ STEP 2: New Password ══ -->
  <div class="af-panel" id="rp-step-2" style="display:none">
    <div class="af-panel-head">
      <div class="af-panel-icon">🛡️</div>
      <div>
        <span class="af-panel-title">Create a New Password</span>
        <span class="af-panel-sub" id="rp-step2-sub">Enter the code we sent you, and choose a new password</span>
      </div>
    </div>
    <div class="af-panel-body">
      <div class="af-field">
        <label class="af-label">6-Digit Code <span class="req">*</span></label>
        <input type="text" class="af-code-input" id="rp-code" placeholder="000000" maxlength="6" inputmode="numeric" autocomplete="one-time-code">
        <button type="button" class="af-resend" id="rp-resend-btn" onclick="rpResendCode()" disabled>Resend code in <span id="rp-resend-timer">60</span>s</button>
      </div>
      <div class="af-field">
        <label class="af-label">New Password <span class="req">*</span></label>
        <div class="af-pw-wrap">
          <input type="password" class="af-input" id="rp-new-password" placeholder="At least 6 characters" autocomplete="new-password" oninput="rpCheckStrength()">
          <button type="button" class="af-pw-toggle" onclick="rpTogglePw('rp-new-password',this)"><i class="ti ti-eye"></i></button>
        </div>
        <div class="af-strength">
          <div class="af-strength-bar" id="rp-bar-1"></div>
          <div class="af-strength-bar" id="rp-bar-2"></div>
          <div class="af-strength-bar" id="rp-bar-3"></div>
        </div>
        <span class="af-strength-lbl" id="rp-strength-lbl">&nbsp;</span>
      </div>
      <div class="af-field">
        <label class="af-label">Confirm New Password <span class="req">*</span></label>
        <div class="af-pw-wrap">
          <input type="password" class="af-input" id="rp-confirm-password" placeholder="Re-enter your new password" autocomplete="new-password">
          <button type="button" class="af-pw-toggle" onclick="rpTogglePw('rp-confirm-password',this)"><i class="ti ti-eye"></i></button>
        </div>
      </div>
      <button type="button" class="af-btn-next" id="rp-reset-btn" onclick="rpSetNewPassword()">
        <i class="ti ti-shield-check"></i> Set New Password
      </button>
    </div>
  </div>

  <!-- ══ SUCCESS ══ -->
  <div id="rp-success-state" style="display:none;">
    <div class="af-panel">
      <div class="af-panel-body">
        <div class="af-success">
          <div class="af-success-check">✓</div>
          <div class="af-success-title">Password Updated!</div>
          <div class="af-success-sub">Your new password is active. You can sign in now.</div>
          <div class="af-success-actions">
            <a href="<?php echo esc_url(home_url('/login/')); ?>" class="af-btn-primary-lg">
              <i class="ti ti-login"></i> Sign In to Portal
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="af-footer">
    <?php echo esc_html($org_name); ?> · ኢትኤል ቡድን · v1.0.0
  </div>
</div>

<script>
var _NONCE = '<?php echo esc_js($nonce); ?>';
var _AJAX  = '<?php echo esc_js($ajax); ?>';
var _identifier = '';
var _resendTimer = null;

function rpShowError(msg) {
  var el = document.getElementById('rp-form-error');
  document.getElementById('rp-error-text').textContent = msg;
  el.classList.add('show');
  el.classList.remove('af-shake'); void el.offsetWidth; el.classList.add('af-shake');
  window.scrollTo({ top: 0, behavior: 'smooth' });
}
function rpClearError() {
  document.getElementById('rp-form-error').classList.remove('show');
}

function rpSelectedChannel() {
  var checked = document.querySelector('input[name="rp-channel"]:checked');
  return checked ? checked.value : 'auto';
}

function rpChannelChanged() {
  document.querySelectorAll('#rp-channel-grid .af-channel-pill').forEach(function (pill) {
    var radio = pill.querySelector('input[type="radio"]');
    pill.classList.toggle('checked', radio.checked);
  });
}

async function rpSendCode() {
  rpClearError();
  var identifier = document.getElementById('rp-identifier').value.trim();
  if (!identifier) { rpShowError('Enter your phone number or Etel-ID.'); return; }
  var channel = rpSelectedChannel();
  var btn = document.getElementById('rp-send-btn');
  btn.disabled = true; btn.innerHTML = '<i class="ti ti-loader-2"></i> Sending…';
  try {
    var r = await fetch(_AJAX, {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: 'action=etelid_forgot_password&nonce=' + _NONCE + '&identifier=' + encodeURIComponent(identifier) + '&channel=' + encodeURIComponent(channel),
    });
    var res = await r.json();
    btn.disabled = false; btn.innerHTML = '<i class="ti ti-send"></i> Send Reset Code';
    if (!res.success) { rpShowError((res.data && res.data.message) || 'Something went wrong. Please try again.'); return; }

    /* The escalating-schedule and contact-support responses come back
     * as success:true (same anti-enumeration pattern as everything
     * else in this flow - never reveal account state through the
     * success/error split itself), so they need their own check here
     * rather than falling into the generic success path above, or the
     * UI would wrongly advance to "enter your code" when no code was
     * actually sent this time. */
    if ( res.data && ( res.data.wait_seconds || res.data.contact_support ) ) {
      rpShowError( res.data.message );
      return;
    }

    _identifier = identifier;
    document.getElementById('rp-step2-sub').textContent = res.data.message;
    document.getElementById('rp-step-1').style.display = 'none';
    document.getElementById('rp-step-2').style.display = 'block';
    document.getElementById('step-dot-1').classList.add('done'); document.getElementById('step-dot-1').classList.remove('active');
    document.getElementById('step-dot-2').classList.add('active');
    document.getElementById('rp-code').focus();
    rpStartResendCooldown();
  } catch (e) {
    btn.disabled = false; btn.innerHTML = '<i class="ti ti-send"></i> Send Reset Code';
    rpShowError('Network error. Please try again.');
  }
}

function rpStartResendCooldown() {
  var seconds = 60;
  var resendBtn = document.getElementById('rp-resend-btn');
  var timerEl = document.getElementById('rp-resend-timer');
  resendBtn.disabled = true;
  if (_resendTimer) clearInterval(_resendTimer);
  _resendTimer = setInterval(function() {
    seconds--;
    timerEl.textContent = seconds;
    if (seconds <= 0) {
      clearInterval(_resendTimer);
      resendBtn.disabled = false;
      resendBtn.innerHTML = 'Resend code';
    }
  }, 1000);
}

async function rpResendCode() {
  var btn = document.getElementById('rp-resend-btn');
  btn.disabled = true; btn.textContent = 'Sending…';
  try {
    var r = await fetch(_AJAX, {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: 'action=etelid_forgot_password&nonce=' + _NONCE + '&identifier=' + encodeURIComponent(_identifier) + '&channel=' + encodeURIComponent(rpSelectedChannel()),
    });
    var res = await r.json();
    // Same distinction as rpSendCode() above - a "success" response
    // can still mean "nothing was actually sent, you're rate-limited"
    // under the escalating schedule.
    if ( res.success && res.data && ( res.data.wait_seconds || res.data.contact_support ) ) {
      rpShowError( res.data.message );
      btn.disabled = false; btn.textContent = 'Resend code';
      return;
    }
    if (res.success) {
      btn.innerHTML = 'Resend code in <span id="rp-resend-timer">60</span>s';
      rpStartResendCooldown();
    } else {
      rpShowError((res.data && res.data.message) || 'Could not resend code.');
      btn.disabled = false; btn.textContent = 'Resend code';
    }
  } catch (e) {
    rpShowError('Network error. Please try again.');
    btn.disabled = false; btn.textContent = 'Resend code';
  }
}

function rpTogglePw(id, btn) {
  var input = document.getElementById(id);
  var icon = btn.querySelector('i');
  if (input.type === 'password') { input.type = 'text'; icon.className = 'ti ti-eye-off'; }
  else { input.type = 'password'; icon.className = 'ti ti-eye'; }
}

function rpCheckStrength() {
  var pw = document.getElementById('rp-new-password').value;
  var bars = [document.getElementById('rp-bar-1'), document.getElementById('rp-bar-2'), document.getElementById('rp-bar-3')];
  var lbl = document.getElementById('rp-strength-lbl');
  var score = 0;
  if (pw.length >= 6) score++;
  if (pw.length >= 10 && /[A-Z]/.test(pw) && /[0-9]/.test(pw)) score++;
  if (pw.length >= 10 && /[^A-Za-z0-9]/.test(pw) && /[A-Z]/.test(pw) && /[0-9]/.test(pw)) score++;
  var colors = ['#ef4444', '#f59e0b', '#22c55e'];
  var labels = ['Weak', 'Good', 'Strong'];
  bars.forEach(function(bar, i) { bar.style.background = i < score ? colors[Math.max(score - 1, 0)] : 'rgba(212,175,55,.15)'; });
  lbl.textContent = pw.length === 0 ? '\u00a0' : labels[Math.max(score - 1, 0)];
  lbl.style.color = pw.length === 0 ? 'var(--g6)' : colors[Math.max(score - 1, 0)];
}

async function rpSetNewPassword() {
  rpClearError();
  var code = document.getElementById('rp-code').value.trim();
  var newPass = document.getElementById('rp-new-password').value;
  var confirmPass = document.getElementById('rp-confirm-password').value;
  if (!code || code.length !== 6) { rpShowError('Enter the 6-digit code.'); return; }
  if (!newPass || newPass.length < 6) { rpShowError('Password must be at least 6 characters.'); return; }
  if (newPass !== confirmPass) { rpShowError('Passwords do not match.'); return; }

  var btn = document.getElementById('rp-reset-btn');
  btn.disabled = true; btn.innerHTML = '<i class="ti ti-loader-2"></i> Updating…';
  try {
    var r = await fetch(_AJAX, {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: 'action=etelid_reset_password&nonce=' + _NONCE
          + '&identifier=' + encodeURIComponent(_identifier)
          + '&code=' + encodeURIComponent(code)
          + '&new_password=' + encodeURIComponent(newPass),
    });
    var res = await r.json();
    btn.disabled = false; btn.innerHTML = '<i class="ti ti-shield-check"></i> Set New Password';
    if (!res.success) { rpShowError((res.data && res.data.message) || 'Something went wrong. Please try again.'); return; }

    document.getElementById('rp-steps-bar') && (document.getElementById('rp-steps-bar').style.display = 'none');
    document.getElementById('step-dot-2') && document.getElementById('step-dot-2').classList.add('done');
    document.getElementById('rp-step-2').style.display = 'none';
    document.getElementById('rp-success-state').style.display = 'block';
    if (_resendTimer) clearInterval(_resendTimer);
  } catch (e) {
    btn.disabled = false; btn.innerHTML = '<i class="ti ti-shield-check"></i> Set New Password';
    rpShowError('Network error. Please try again.');
  }
}

document.getElementById('rp-identifier').addEventListener('keydown', function(e) { if (e.key === 'Enter') rpSendCode(); });
document.getElementById('rp-code').addEventListener('input', function(e) { e.target.value = e.target.value.replace(/[^0-9]/g, ''); });
document.getElementById('rp-confirm-password').addEventListener('keydown', function(e) { if (e.key === 'Enter') rpSetNewPassword(); });
</script>
</body>
</html>
