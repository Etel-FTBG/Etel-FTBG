<?php
/**
 * Etel-ID Premium — Verify Page v4.0.0
 * Standalone. No WP theme.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* Clean the ID from the URL — strip non-ASCII for safety */
$raw_id  = get_query_var('etelid_verify');
/* Decode URL-encoded Ethiopic characters (e.g. %E1%A4%A4 → ኤ) */
$etel_id = $raw_id ? strtoupper( sanitize_text_field( rawurldecode( $raw_id ) ) ) : '';
$member  = $etel_id ? EtelID_DB::get_by_etel_id( $etel_id ) : null;
/* Fallback: try without strtoupper (Ethiopic chars shouldn't be uppercased) */
if ( ! $member && $etel_id ) {
    $etel_id_orig = sanitize_text_field( rawurldecode( $raw_id ) );
    $member = EtelID_DB::get_by_etel_id( $etel_id_orig );
    if ( $member ) $etel_id = $etel_id_orig;
}

if ( $member && $member->status === 'active' ) {
    EtelID_DB::increment_scan( $member->id );
    EtelID_DB::log( $member->id, $member->etel_id, 'verified', 'QR scan' );
}

$org_name    = get_option('etelid_org_name',    'Etel Group');
$org_website = get_option('etelid_org_website', 'ethelmarket.com');
$org_email   = get_option('etelid_org_email',   'info@ethelmarket.com');
$logo_src    = defined('ETELID_LOGO_B64') ? ETELID_LOGO_B64 : ETELID_URL.'public/images/etel-logo.png';
$companies   = $member ? EtelID_DB::get_companies($member->id) : array();
$card_css    = file_get_contents(ETELID_PATH.'public/css/etelid-card.css');

$is_valid     = $member && $member->status === 'active'
             && strtotime($member->expiry_date) >= time();
$is_expired   = $member && $member->status === 'active'
             && strtotime($member->expiry_date) < time();
$is_suspended = $member && $member->status === 'suspended';
$is_revoked   = $member && $member->status === 'revoked';

/* Verification result */
if ( $is_valid ) {
    $result_class = 'vr-valid';
    $result_icon  = '✓';
    $result_title = 'Verified — Valid Etel-ID';
    $result_am    = 'ተረጋግጧል';
    $result_color = '#22c55e';
    $result_bg    = 'rgba(34,197,94,.12)';
    $result_border= 'rgba(34,197,94,.35)';
} elseif ( $is_suspended ) {
    $result_class = 'vr-suspended';
    $result_icon  = '⚠';
    $result_title = 'Suspended — ID Invalid';
    $result_am    = 'ታግዷል';
    $result_color = '#f59e0b';
    $result_bg    = 'rgba(245,158,11,.12)';
    $result_border= 'rgba(245,158,11,.35)';
} elseif ( $is_expired ) {
    $result_class = 'vr-expired';
    $result_icon  = '⏱';
    $result_title = 'Expired — ID No Longer Valid';
    $result_am    = 'ጊዜው አልፏል';
    $result_color = '#f97316';
    $result_bg    = 'rgba(249,115,22,.12)';
    $result_border= 'rgba(249,115,22,.35)';
} elseif ( $is_revoked ) {
    $result_class = 'vr-revoked';
    $result_icon  = '✗';
    $result_title = 'Revoked — ID Cancelled';
    $result_am    = 'ተሰርዟል';
    $result_color = '#ef4444';
    $result_bg    = 'rgba(239,68,68,.12)';
    $result_border= 'rgba(239,68,68,.35)';
} else {
    $result_class = 'vr-notfound';
    $result_icon  = '?';
    $result_title = 'ID Not Found';
    $result_am    = 'አልተገኘም';
    $result_color = '#94a3b8';
    $result_bg    = 'rgba(148,163,184,.12)';
    $result_border= 'rgba(148,163,184,.35)';
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Etel-ID Verify · <?php echo esc_html($etel_id ?: 'Check ID'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=JetBrains+Mono:wght@400;600;700&family=Cormorant+Garamond:wght@400;600;700&family=Noto+Sans+Ethiopic:wght@400;600;700&family=Noto+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
<?php echo $card_css; ?>

/* ════════════════════════════════════════
   ROOT
════════════════════════════════════════ */
:root{
  --g1:#FDF6D8;--g2:#F5E6A3;--g3:#E8C84A;--g4:#D4AF37;
  --g5:#C8922A;--g6:#A07020;--g7:#7A5518;--g8:#4A3210;--g9:#2A1A06;
  --ink:#1A0E00;--ink2:#3A2200;--ink3:#7A5520;
  --glass:rgba(20,10,0,.5);
  --glass-border:rgba(212,175,55,.28);
}

/* ════════════════════════════════════════
   ANIMATIONS
════════════════════════════════════════ */
@keyframes holo     {0%{background-position:0 0}100%{background-position:300% 0}}
@keyframes slide-up {from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
@keyframes badge-in {0%{transform:scale(.4);opacity:0}70%{transform:scale(1.1)}100%{transform:scale(1);opacity:1}}
@keyframes orb1     {0%,100%{transform:translate(0,0)}33%{transform:translate(35px,-25px)}66%{transform:translate(-22px,18px)}}
@keyframes orb2     {0%,100%{transform:translate(0,0)}33%{transform:translate(-28px,20px)}66%{transform:translate(24px,-30px)}}
@keyframes scan     {0%{top:0;opacity:.7}85%{opacity:.7}100%{top:100%;opacity:0}}
@keyframes pulse-ring{0%,100%{box-shadow:0 0 0 0 currentColor}50%{box-shadow:0 0 0 8px transparent}}
@keyframes shimmer  {0%{background-position:-400px 0}100%{background-position:400px 0}}
@keyframes check-draw{0%{stroke-dashoffset:100}100%{stroke-dashoffset:0}}

/* ════════════════════════════════════════
   PAGE
════════════════════════════════════════ */
body{
  min-height:100vh;
  background:
    radial-gradient(ellipse at 18% 8%,  rgba(255,230,80,.22) 0%,transparent 40%),
    radial-gradient(ellipse at 82% 92%, rgba(60,30,0,.32)   0%,transparent 40%),
    linear-gradient(155deg,#2A1600 0%,#5A3A08 15%,#9A6818 30%,
                    #C8922A 44%,#D4AF37 50%,#C8922A 56%,
                    #9A6818 70%,#5A3A08 85%,#2A1600 100%);
  font-family:'Cormorant Garamond',serif;
  overflow-x:hidden;position:relative;
}
body::before{
  content:'';position:fixed;inset:0;pointer-events:none;z-index:0;
  background-image:
    repeating-linear-gradient(30deg, transparent 0,transparent 24px,rgba(255,255,255,.022) 24px,rgba(255,255,255,.022) 25px),
    repeating-linear-gradient(-30deg,transparent 0,transparent 24px,rgba(255,255,255,.022) 24px,rgba(255,255,255,.022) 25px);
}
/* Orbs */
.v-orb{position:fixed;border-radius:50%;pointer-events:none;z-index:0;filter:blur(70px);}
.v-orb-1{width:550px;height:550px;top:-160px;left:-160px;
  background:radial-gradient(circle,rgba(255,215,60,.18) 0%,transparent 70%);
  animation:orb1 14s ease-in-out infinite;}
.v-orb-2{width:450px;height:450px;bottom:-100px;right:-100px;
  background:radial-gradient(circle,rgba(80,40,0,.22) 0%,transparent 70%);
  animation:orb2 18s ease-in-out infinite;}

/* ════════════════════════════════════════
   NAV
════════════════════════════════════════ */
.v-nav{
  position:sticky;top:0;z-index:100;
  background:linear-gradient(135deg,var(--g9),var(--g8) 40%,var(--g9));
  border-bottom:2px solid var(--g4);
  height:60px;padding:0 20px;
  display:flex;align-items:center;gap:14px;
  box-shadow:0 4px 28px rgba(10,5,0,.55);
}
.v-nav::after{
  content:'';position:absolute;bottom:-2px;left:0;right:0;height:2px;
  background:linear-gradient(90deg,transparent,var(--g5),var(--g2),var(--g1),var(--g2),var(--g5),transparent);
  background-size:300% 100%;animation:holo 4s linear infinite;
}
.v-nav-logo{height:36px;width:auto;object-fit:contain;
  filter:brightness(1.2) drop-shadow(0 2px 8px rgba(0,0,0,.5));flex-shrink:0;}
.v-nav-text{flex:1;}
.v-nav-title{font-family:'Syne',sans-serif;font-size:16px;font-weight:800;color:var(--g1);}
.v-nav-sub{font-family:'Noto Sans Ethiopic',sans-serif;font-size:9px;color:var(--g4);display:block;}
.v-nav-apply{
  display:inline-flex;align-items:center;gap:6px;
  padding:8px 16px;
  background:linear-gradient(135deg,var(--g8),var(--g5),var(--g4));
  color:var(--g1);border:none;border-radius:8px;
  font-family:'Syne',sans-serif;font-size:12px;font-weight:800;
  text-decoration:none;flex-shrink:0;
  box-shadow:0 3px 0 var(--g9),0 5px 14px rgba(80,40,0,.3);
  transition:all .15s;
}
.v-nav-apply:hover{transform:translateY(-1px);color:var(--g1);text-decoration:none;}

/* ════════════════════════════════════════
   WRAP
════════════════════════════════════════ */
.v-wrap{max-width:700px;margin:0 auto;padding:28px 16px 60px;position:relative;z-index:1;}

/* ════════════════════════════════════════
   RESULT BADGE
════════════════════════════════════════ */
.v-result-badge{
  display:flex;align-items:center;gap:14px;
  padding:18px 22px;border-radius:16px;
  border:1.5px solid <?php echo $result_border; ?>;
  background:<?php echo $result_bg; ?>;
  margin-bottom:24px;
  animation:badge-in .5s cubic-bezier(.22,.68,0,1.2) both;
  backdrop-filter:blur(8px);
}
.v-result-icon{
  width:52px;height:52px;border-radius:50%;
  background:<?php echo $result_color; ?>;
  display:flex;align-items:center;justify-content:center;
  font-size:24px;font-weight:900;color:#fff;
  flex-shrink:0;
  box-shadow:0 0 0 6px <?php echo $result_bg; ?>,0 0 0 8px <?php echo $result_color; ?>44,
             0 8px 20px <?php echo $result_color; ?>44;
  animation:pulse-ring 2.5s ease infinite;
  color:<?php echo $result_color; ?>;
}
.v-result-title{
  font-family:'Syne',sans-serif;font-size:18px;font-weight:800;
  color:var(--g1);line-height:1.2;
}
.v-result-am{
  font-family:'Noto Sans Ethiopic',sans-serif;
  font-size:13px;color:<?php echo $result_color; ?>;
  display:block;margin-top:3px;
}
.v-result-id{
  font-family:'JetBrains Mono',monospace;
  font-size:11px;color:rgba(253,246,216,.5);
  letter-spacing:1.5px;margin-top:4px;display:block;
}

/* ════════════════════════════════════════
   CARD VIEWER
════════════════════════════════════════ */
.v-card-section{
  background:var(--glass);
  border:1.5px solid var(--glass-border);
  border-radius:18px;overflow:hidden;
  backdrop-filter:blur(14px);
  box-shadow:0 8px 36px rgba(10,5,0,.35),inset 0 1px 0 rgba(255,255,255,.07);
  margin-bottom:20px;
  animation:slide-up .4s .1s ease both;
}
.v-card-head{
  padding:14px 20px;
  background:linear-gradient(135deg,rgba(74,50,16,.95),rgba(26,14,0,.88));
  border-bottom:1.5px solid rgba(212,175,55,.28);
  display:flex;align-items:center;gap:10px;
  position:relative;overflow:hidden;
}
.v-card-head::after{
  content:'';position:absolute;bottom:0;left:0;right:0;height:1px;
  background:linear-gradient(90deg,transparent,var(--g4),transparent);
}
.v-card-head-icon{
  width:32px;height:32px;border-radius:8px;
  background:linear-gradient(135deg,var(--g6),var(--g8));
  display:flex;align-items:center;justify-content:center;
  font-size:15px;flex-shrink:0;
}
.v-card-head h3{font-family:'Syne',sans-serif;font-size:13px;font-weight:800;color:var(--g1);flex:1;margin:0;}
.v-card-tip{font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--g5);letter-spacing:1px;}
.v-card-body{padding:20px;}

/* Card scene */
.v-card-scene{
  width:100%;max-width:480px;margin:0 auto;
  perspective:1200px;cursor:pointer;position:relative;
}
.v-card-wrap{
  width:100%;aspect-ratio:460/290;
  position:relative;transform-style:preserve-3d;
  transition:transform .85s cubic-bezier(.4,.2,.2,1);
  filter:drop-shadow(0 18px 40px rgba(0,0,0,.55)) drop-shadow(0 5px 10px rgba(80,40,0,.4));
  animation:slide-up .6s ease both;
}
.v-card-wrap.flipped{transform:rotateY(180deg)!important;}
.v-card-face{
  position:absolute;
  top:0;left:0;right:0;bottom:0;
  width:100%;height:100%;
  border-radius:10px;overflow:hidden;
  transition:opacity .5s ease,transform .5s ease;
}
.v-card-face:first-child{opacity:1;transform:scale(1);z-index:2;pointer-events:auto;}
.v-card-back-face{opacity:0;transform:scale(0.97);z-index:1;pointer-events:none;}
.v-card-wrap.flipped .v-card-face:first-child{opacity:0;transform:scale(0.97);z-index:1;pointer-events:none;}
.v-card-wrap.flipped .v-card-back-face{opacity:1;transform:scale(1);z-index:2;pointer-events:auto;}
.v-card-face .eid-card-front,
.v-card-face .eid-card-back{position:absolute!important;inset:0;border-radius:10px;}
/* Scan line */
.v-scan{
  position:absolute;left:0;right:0;height:2px;
  background:linear-gradient(90deg,transparent,rgba(212,175,55,.7) 50%,transparent);
  pointer-events:none;opacity:0;animation:scan 2.5s linear infinite;
}
.v-card-scene:hover .v-scan{opacity:1;}
/* Controls */
.v-card-controls{
  display:flex;align-items:center;justify-content:center;gap:12px;
  margin-top:14px;flex-wrap:wrap;
}
.v-flip-btn{
  display:inline-flex;align-items:center;gap:7px;
  padding:9px 20px;background:rgba(212,175,55,.1);
  border:1.5px solid rgba(212,175,55,.3);border-radius:24px;
  font-family:'JetBrains Mono',monospace;font-size:10px;
  color:var(--g2);letter-spacing:1.5px;text-transform:uppercase;
  cursor:pointer;transition:all .2s;
}
.v-flip-btn:hover{background:rgba(212,175,55,.2);border-color:rgba(212,175,55,.6);color:var(--g1);}
.v-print-btn{
  display:inline-flex;align-items:center;gap:7px;
  padding:9px 20px;
  background:linear-gradient(135deg,var(--g9),var(--g7),var(--g5),var(--g4));
  color:var(--g1);border:none;border-radius:24px;
  font-family:'Syne',sans-serif;font-size:12px;font-weight:800;
  cursor:pointer;text-decoration:none;
  box-shadow:0 3px 0 var(--g9),0 5px 14px rgba(80,40,0,.3);
  transition:all .15s;
}
.v-print-btn:hover{transform:translateY(-1px);color:var(--g1);text-decoration:none;}
.v-side-lbl{font-family:'JetBrains Mono',monospace;font-size:9px;color:rgba(212,175,55,.4);letter-spacing:2px;text-transform:uppercase;}

/* ════════════════════════════════════════
   IDENTITY DETAILS
════════════════════════════════════════ */
.v-details-section{
  background:var(--glass);
  border:1.5px solid var(--glass-border);
  border-radius:18px;overflow:hidden;
  backdrop-filter:blur(14px);
  box-shadow:0 8px 36px rgba(10,5,0,.35),inset 0 1px 0 rgba(255,255,255,.07);
  margin-bottom:20px;
  animation:slide-up .4s .2s ease both;
}
/* Card number highlight bar */
.v-card-num-bar{
  display:flex;align-items:center;justify-content:space-between;
  gap:16px;flex-wrap:wrap;
  padding:13px 18px;
  background:linear-gradient(90deg,rgba(212,175,55,.12),rgba(212,175,55,.05));
  border-left:3px solid var(--g4);border-radius:0 8px 8px 0;
  margin:14px 0;
}
.v-cn-item{display:flex;flex-direction:column;gap:2px;}
.v-cn-lbl{font-family:'JetBrains Mono',monospace;font-size:8px;color:var(--g5);letter-spacing:2px;text-transform:uppercase;}
.v-cn-val{font-family:'JetBrains Mono',monospace;font-size:14px;font-weight:700;color:var(--g1);letter-spacing:1.5px;}
/* Details grid */
.v-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
@media(max-width:480px){.v-grid{grid-template-columns:1fr;}}
.v-field{
  padding:10px 14px;
  background:rgba(212,175,55,.05);
  border:1px solid rgba(212,175,55,.14);
  border-radius:10px;transition:background .15s;
}
.v-field:hover{background:rgba(212,175,55,.1);}
.v-field-lbl{font-family:'JetBrains Mono',monospace;font-size:8px;color:var(--g5);letter-spacing:2px;text-transform:uppercase;margin-bottom:4px;}
.v-field-val{font-size:14px;font-weight:700;color:var(--g1);}
.v-field-val.mono{font-family:'JetBrains Mono',monospace;font-size:13px;}
.v-field-val.lg{font-size:18px;color:var(--g2);}
.v-field-val-am{font-family:'Noto Sans Ethiopic',sans-serif;font-size:12px;color:var(--g3);display:block;margin-top:2px;}
/* Status badge */
.v-badge{
  display:inline-flex;align-items:center;gap:5px;
  padding:5px 13px;border-radius:20px;
  font-family:'JetBrains Mono',monospace;font-size:11px;
  font-weight:700;text-transform:uppercase;letter-spacing:1px;
}
.v-badge-active   {background:rgba(34,197,94,.15);color:#86efac;border:1px solid rgba(34,197,94,.28);}
.v-badge-suspended{background:rgba(245,158,11,.15);color:#fcd34d;border:1px solid rgba(245,158,11,.28);}
.v-badge-expired  {background:rgba(249,115,22,.15);color:#fdba74;border:1px solid rgba(249,115,22,.28);}
.v-badge-revoked  {background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.28);}
/* Company chips */
.v-chips{display:flex;flex-wrap:wrap;gap:7px;margin-top:14px;}
.v-chip{
  display:inline-flex;align-items:center;gap:6px;
  padding:7px 12px;border-radius:8px;
  background:rgba(212,175,55,.07);border:1px solid rgba(212,175,55,.18);
  font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--g2);
}
.v-chip-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
/* Scan count */
.v-scan-count{
  display:flex;align-items:center;gap:8px;
  padding:11px 16px;margin-top:14px;
  background:rgba(212,175,55,.05);border:1px solid rgba(212,175,55,.14);
  border-radius:8px;
  font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--g4);
}

/* ════════════════════════════════════════
   NOT FOUND STATE
════════════════════════════════════════ */
.v-not-found{
  text-align:center;padding:60px 20px;
  animation:slide-up .5s ease both;
}
.v-not-found-icon{
  width:80px;height:80px;border-radius:50%;
  background:rgba(148,163,184,.1);border:2px solid rgba(148,163,184,.25);
  display:flex;align-items:center;justify-content:center;
  font-size:36px;margin:0 auto 20px;
}
.v-not-found-title{font-family:'Syne',sans-serif;font-size:24px;font-weight:800;color:var(--g1);margin-bottom:8px;}
.v-not-found-sub{font-size:15px;color:var(--g4);margin-bottom:28px;}
/* Manual lookup box */
.v-lookup-box{
  max-width:400px;margin:0 auto;
  background:var(--glass);border:1.5px solid var(--glass-border);
  border-radius:14px;padding:20px;backdrop-filter:blur(8px);
}
.v-lookup-box h4{font-family:'Syne',sans-serif;font-size:14px;font-weight:800;color:var(--g2);margin-bottom:12px;}
.v-lookup-row{display:flex;gap:8px;}
.v-lookup-input{
  flex:1;padding:11px 14px;
  background:rgba(212,175,55,.08);
  border:1.5px solid rgba(212,175,55,.25);
  border-radius:9px;
  font-size:14px;font-family:'JetBrains Mono',monospace;
  color:var(--g1);letter-spacing:.5px;
  transition:border-color .2s;
}
.v-lookup-input:focus{border-color:var(--g4);outline:none;box-shadow:0 0 0 3px rgba(212,175,55,.15);}
.v-lookup-input::placeholder{color:rgba(212,175,55,.3);}
.v-lookup-btn{
  padding:11px 18px;
  background:linear-gradient(135deg,var(--g8),var(--g5),var(--g4));
  color:var(--g1);border:none;border-radius:9px;
  font-family:'Syne',sans-serif;font-size:13px;font-weight:800;
  cursor:pointer;white-space:nowrap;
  box-shadow:0 3px 0 var(--g9);transition:all .15s;
}
.v-lookup-btn:hover{transform:translateY(-1px);}

/* ════════════════════════════════════════
   FOOTER
════════════════════════════════════════ */
.v-footer{
  text-align:center;padding:20px 0 0;
  font-family:'JetBrains Mono',monospace;font-size:8px;
  color:rgba(212,175,55,.28);letter-spacing:2px;text-transform:uppercase;
}
</style>
</head>
<body>
<div class="v-orb v-orb-1"></div>
<div class="v-orb v-orb-2"></div>

<!-- ══ NAV ══ -->
<nav class="v-nav">
  <img src="<?php echo esc_attr($logo_src); ?>" alt="" class="v-nav-logo">
  <div class="v-nav-text">
    <div class="v-nav-title">Etel-ID Verification</div>
    <span class="v-nav-sub">Official <?php echo esc_html($org_name); ?> · ኢትኤል ቡድን Identity Check</span>
  </div>
  <a href="<?php echo esc_url(home_url('/apply/')); ?>" class="v-nav-apply">
    📋 Get Your ID
  </a>
</nav>

<div class="v-wrap">

<?php if ( ! $member ) : ?>
  <!-- ══ NOT FOUND ══ -->
  <div class="v-not-found">
    <div class="v-not-found-icon">🔍</div>
    <div class="v-not-found-title">
      <?php echo $etel_id ? 'ID Not Found' : 'Verify an Etel-ID'; ?>
    </div>
    <div class="v-not-found-sub">
      <?php if ($etel_id): ?>
        The ID <strong style="color:var(--g2);"><?php echo esc_html($etel_id); ?></strong>
        does not exist in our records.
      <?php else: ?>
        Enter an Etel-ID number below to verify its authenticity.
      <?php endif; ?>
    </div>
    <div class="v-lookup-box">
      <h4>🔍 Check an Etel-ID</h4>
      <div class="v-lookup-row">
        <input type="text" class="v-lookup-input" id="v-lookup-input"
          placeholder="e.g. EG-2026-00001"
          value="<?php echo esc_attr($etel_id); ?>">
        <button class="v-lookup-btn" onclick="vLookup()">Verify →</button>
      </div>
    </div>
  </div>

<?php else : ?>

  <!-- ══ RESULT BADGE ══ -->
  <div class="v-result-badge">
    <div class="v-result-icon" style="font-size:28px;">
      <?php echo $result_icon; ?>
    </div>
    <div>
      <div class="v-result-title"><?php echo esc_html($result_title); ?></div>
      <span class="v-result-am"><?php echo esc_html($result_am); ?></span>
      <span class="v-result-id"><?php echo esc_html($member->etel_id); ?> · FIN: <?php echo esc_html($member->fin); ?></span>
    </div>
  </div>

  <!-- ══ 3D CARD VIEWER ══ -->
  <div class="v-card-section">
    <div class="v-card-head">
      <div class="v-card-head-icon">🪪</div>
      <h3><?php echo esc_html($member->full_name); ?></h3>
      <span class="v-card-tip">CLICK TO FLIP</span>
    </div>
    <div class="v-card-body">
      <div class="v-card-scene" onclick="vFlip()">
        <div class="v-card-wrap" id="v-card-3d">
          <div class="v-card-face">
            <?php include ETELID_PATH.'templates/card-front.php'; ?>
          </div>
          <div class="v-card-face v-card-back-face">
            <?php include ETELID_PATH.'templates/card-back.php'; ?>
          </div>
        </div>
        <div class="v-scan"></div>
      </div>
      <div class="v-card-controls">
        <span class="v-side-lbl" id="v-side-lbl">FRONT SIDE</span>
        <button class="v-flip-btn" onclick="event.stopPropagation();vFlip()">
          <i class="ti ti-refresh"></i> Flip Card
        </button>
        <a href="<?php echo esc_url(home_url('/print/'.$member->etel_id)); ?>"
           target="_blank" class="v-print-btn">
          <i class="ti ti-printer"></i> Print Card
        </a>
      </div>
    </div>
  </div>

  <!-- ══ IDENTITY DETAILS ══ -->
  <div class="v-details-section">
    <div class="v-card-head">
      <div class="v-card-head-icon">📋</div>
      <h3>Identity Details</h3>
      <span class="v-card-tip">OFFICIAL RECORD</span>
    </div>
    <div class="v-card-body">

      <!-- Card number highlight -->
      <div class="v-card-num-bar">
        <div class="v-cn-item">
          <span class="v-cn-lbl">Etel-ID</span>
          <span class="v-cn-val"><?php echo esc_html($member->etel_id); ?></span>
        </div>
        <div class="v-cn-item">
          <span class="v-cn-lbl">FIN Number</span>
          <span class="v-cn-val"><?php echo esc_html($member->fin); ?></span>
        </div>
        <div class="v-cn-item">
          <span class="v-cn-lbl">Card Number</span>
          <span class="v-cn-val" style="font-size:11px;letter-spacing:2px;"><?php echo esc_html($member->card_number); ?></span>
        </div>
      </div>

      <div class="v-grid">
        <div class="v-field">
          <div class="v-field-lbl">Full Name</div>
          <div class="v-field-val"><?php echo esc_html($member->full_name); ?>
            <?php if ($member->full_name_am): ?>
            <span class="v-field-val-am"><?php echo esc_html($member->full_name_am); ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div class="v-field">
          <div class="v-field-lbl">Status</div>
          <div class="v-badge v-badge-<?php echo esc_attr($member->status); ?>">
            <?php echo esc_html(ucfirst($member->status)); ?>
          </div>
        </div>
        <div class="v-field">
          <div class="v-field-lbl">Company</div>
          <div class="v-field-val"><?php echo esc_html($member->primary_company ?: '—'); ?></div>
        </div>
        <div class="v-field">
          <div class="v-field-lbl">Role</div>
          <div class="v-field-val"><?php echo esc_html($member->role ?: '—'); ?></div>
        </div>
        <div class="v-field">
          <div class="v-field-lbl">Issue Date</div>
          <div class="v-field-val mono" style="font-size:12px;">
            <?php echo esc_html($member->issue_date); ?>
            <?php if (!empty($member->issue_date_eth)): ?>
            <span class="v-field-val-am"><?php echo esc_html($member->issue_date_eth); ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div class="v-field">
          <div class="v-field-lbl">Expiry Date</div>
          <div class="v-field-val mono" style="font-size:12px;<?php echo $is_expired?'color:#fdba74;':'' ?>">
            <?php echo esc_html($member->expiry_date); ?>
            <?php if (!empty($member->expiry_date_eth)): ?>
            <span class="v-field-val-am"><?php echo esc_html($member->expiry_date_eth); ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div class="v-field">
          <div class="v-field-lbl">Verification Count</div>
          <div class="v-field-val mono"><?php echo number_format((int)$member->qr_scan_count); ?> times</div>
        </div>
        <div class="v-field">
          <div class="v-field-lbl">Tier</div>
          <div class="v-field-val"><?php echo esc_html(ucfirst($member->tier ?: 'Standard')); ?></div>
        </div>
      </div>

      <?php if ($companies): ?>
      <div class="v-chips">
        <?php
        $chip_colors = array('#C8922A','#3b82f6','#22c55e','#a855f7','#ef4444','#f59e0b','#06b6d4','#ec4899');
        $ci = 0;
        foreach ($companies as $co):
          $cc = $chip_colors[$ci % count($chip_colors)]; $ci++;
        ?>
        <div class="v-chip">
          <div class="v-chip-dot" style="background:<?php echo $cc; ?>;box-shadow:0 0 5px <?php echo $cc; ?>;"></div>
          <?php echo esc_html($co->company_name); ?>
          <?php if (!empty($co->role_at) && $co->role_at !== 'Member'): ?>
          <span style="color:var(--g5);font-size:9px;">· <?php echo esc_html($co->role_at); ?></span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="v-scan-count">
        <i class="ti ti-qrcode" style="font-size:16px;"></i>
        This ID has been verified
        <strong style="color:var(--g2);margin:0 3px;"><?php echo number_format((int)$member->qr_scan_count); ?></strong>
        times.
        <?php if (!empty($member->last_verified)): ?>
        &nbsp;·&nbsp; Last: <?php echo esc_html($member->last_verified); ?>
        <?php endif; ?>
      </div>

    </div>
  </div>

  <!-- Manual lookup -->
  <div style="text-align:center;margin-bottom:20px;">
    <div class="v-lookup-box" style="max-width:480px;margin:0 auto;">
      <h4>🔍 Verify Another ID</h4>
      <div class="v-lookup-row">
        <input type="text" class="v-lookup-input" id="v-lookup-input"
          placeholder="e.g. EG-2026-00001">
        <button class="v-lookup-btn" onclick="vLookup()">Verify →</button>
      </div>
    </div>
  </div>

<?php endif; ?>

  <div class="v-footer">
    <?php echo esc_html($org_name); ?> · ኢትኤል ቡድን ·
    <?php echo esc_html($org_website); ?> ·
    <?php echo esc_html($org_email); ?> · v<?php echo ETELID_VERSION; ?>
  </div>
</div><!-- /v-wrap -->

<script>
var _vFlipped = false;

function vFlip() {
  _vFlipped = !_vFlipped;
  var c = document.getElementById('v-card-3d');
  var l = document.getElementById('v-side-lbl');
  if (c) c.classList.toggle('flipped', _vFlipped);
  if (l) l.textContent = _vFlipped ? 'BACK SIDE' : 'FRONT SIDE';
}

/* Front face shown by CSS opacity — no JS needed */

function vLookup() {
  var id = document.getElementById('v-lookup-input').value.trim();
  if (id) window.location.href = '<?php echo esc_js(home_url('/verify/')); ?>' + encodeURIComponent(id);
}

document.getElementById('v-lookup-input').addEventListener('keydown', function(e) {
  if (e.key === 'Enter') vLookup();
});

/* 3D tilt on desktop */
var _scene = document.querySelector('.v-card-scene');
var _card  = document.getElementById('v-card-3d');
if (_scene && _card && window.matchMedia('(hover:hover)').matches) {
  _scene.addEventListener('mousemove', function(e) {
    if (_vFlipped) return;
    var r = _scene.getBoundingClientRect();
    var x = (e.clientX - r.left) / r.width  - 0.5;
    var y = (e.clientY - r.top)  / r.height - 0.5;
    _card.style.transform = 'perspective(1000px) rotateY('+(x*12)+'deg) rotateX('+(-y*7)+'deg) scale(1.02)';
  });
  _scene.addEventListener('mouseleave', function() {
    if (_vFlipped) return;
    _card.style.transform = '';
  });
}
</script>
<!-- ETEL AI -- cross-company assistant widget -->
<script src="https://ethelmarket.com/EtelAi/etel-ai-widget.js" data-project="etel-id"></script>
</body>
</html>
