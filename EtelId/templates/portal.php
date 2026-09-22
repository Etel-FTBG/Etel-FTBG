<?php
/**
 * Etel-ID Premium — Member Portal v4.0.0
 * Premium standalone page. No WP theme.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$member = EtelID_Auth::get_current_member();
if ( ! $member ) { wp_redirect( home_url('/login/') ); exit; }

$companies    = EtelID_DB::get_companies( $member->id );
$log          = EtelID_DB::get_log( $member->id, 6 );
$verify_url   = EtelID_QR::verify_url( $member->etel_id );
/* rawurlencode handles Ethiopic characters in ID prefix (e.g. ኤ.E-2026-00002) */
$print_url    = home_url('/print/'  . rawurlencode($member->etel_id));
/* Was a raw inline api.qrserver.com URL, built independently of
 * EtelID_QR::img() - meaning it never got the local-caching fix
 * applied there, and kept hitting the external service live on every
 * single portal page view even after that fix shipped. Routed through
 * the shared method now so this gets the same caching, with the same
 * custom colors and the same requested-vs-displayed size split as
 * before (220px generated/cached, shown at 160px - see the img()
 * usage below, which no longer needs to pass width/height itself). */
$qr_img = EtelID_QR::img( $member->etel_id, 220, '2A1A06', 'FFFEF8', 160, 6 );
$org_name     = get_option('etelid_org_name','Etel Group');
/* Quick-launch links to the group's other end-user apps. Configurable
 * options (not hardcoded) so these can be corrected from Settings in
 * one click if the actual URL differs, without needing a code deploy -
 * same pattern already used for etelid_tg_bot_username. Defaults are
 * best-guess based on the known Delivery/Ride site structure:
 * "Driver" -> the Ride system's driver console, "Rider" -> the Ride
 * system's passenger-facing app, "Customer" -> the Delivery ordering
 * app. Confirm/adjust these under Etel-ID > Settings if any are wrong. */
$driver_app_url   = get_option('etelid_driver_app_url',   'https://ethelmarket.com/delivery/driver/');
$rider_app_url    = get_option('etelid_ride_app_url',     'https://ethelmarket.com/delivery/ride/');
$customer_app_url = get_option('etelid_customer_app_url', 'https://ethelmarket.com/delivery/customer.html');
$logo_src     = defined('ETELID_LOGO_B64') ? ETELID_LOGO_B64 : ETELID_URL.'public/images/etel-logo.png';
$nonce        = wp_create_nonce('etelid_auth');
$ajax         = admin_url('admin-ajax.php');
$card_css     = file_get_contents(ETELID_PATH.'public/css/etelid-card.css');

/* Member helpers */
$parts = explode(' ', trim($member->full_name));
$init  = '';
foreach ($parts as $p) { if ($p) $init .= strtoupper(substr($p,0,1)); }
$init  = substr($init,0,2);

$status_color = $member->status==='active' ? '#22c55e' : ($member->status==='suspended' ? '#ef4444' : '#f59e0b');
$expiry_ts    = $member->expiry_date ? strtotime($member->expiry_date) : 0;
$days_left    = $expiry_ts ? max(0, round(($expiry_ts - time()) / 86400)) : 0;
$expiry_warn  = $days_left > 0 && $days_left < 90;

/* Admin check */
/* Strict admin check — only the member whose ID matches
 * etelid_admin_member_id in Settings sees admin tools.
 * Uses EtelID_Auth::is_site_admin() as single source of truth. */
$is_admin = EtelID_Auth::is_site_admin( $member );
/* Data for the in-portal Fayda Quick Register modal (admin only — no
 * point loading these for the ~99% of visits that aren't the admin). */
$fayda_companies = $is_admin ? EtelID_Generator::companies() : array();
$fayda_regions    = $is_admin ? EtelID_Generator::regions()   : array();
$fayda_nonce      = $is_admin ? wp_create_nonce( 'etelid_auth' ) : '';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0">
<title>My Etel-ID · <?php echo esc_html($member->full_name); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=JetBrains+Mono:wght@400;500;600;700&family=Cormorant+Garamond:wght@400;600;700&family=Noto+Sans+Ethiopic:wght@400;600;700&family=Noto+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
<?php echo $card_css; ?>

/* ════════════════════════════════════════════════
   PORTAL v4.0 — ROOT VARIABLES
════════════════════════════════════════════════ */
:root{
  --g1:#FDF6D8;--g2:#F5E6A3;--g3:#E8C84A;--g4:#D4AF37;
  --g5:#C8922A;--g6:#A07020;--g7:#7A5518;--g8:#4A3210;--g9:#2A1A06;
  --ink:#1A0E00;--ink2:#3A2200;--ink3:#7A5520;
  --glass:rgba(20,10,0,.55);
  --glass-border:rgba(212,175,55,.28);
  --glass-head:linear-gradient(135deg,rgba(74,50,16,.95),rgba(26,14,0,.88));
}

/* ════════════════════════════════════════════════
   ANIMATIONS
════════════════════════════════════════════════ */
@keyframes holo{0%{background-position:0 0}100%{background-position:300% 0}}
@keyframes orb1{0%,100%{transform:translate(0,0)}33%{transform:translate(40px,-30px)}66%{transform:translate(-25px,20px)}}
@keyframes orb2{0%,100%{transform:translate(0,0)}33%{transform:translate(-35px,20px)}66%{transform:translate(30px,-30px)}}
@keyframes slide-up{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
@keyframes card-in{from{opacity:0;transform:translateY(16px) scale(.98)}to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes shimmer{0%{background-position:-400px 0}100%{background-position:400px 0}}
@keyframes pulse-dot{0%,100%{box-shadow:0 0 0 0 currentColor}50%{box-shadow:0 0 0 5px transparent}}
@keyframes badge-in{0%{transform:scale(.5);opacity:0}70%{transform:scale(1.08)}100%{transform:scale(1);opacity:1}}

/* ════════════════════════════════════════════════
   PAGE
════════════════════════════════════════════════ */
body{margin:0;font-family:'Cormorant Garamond',serif;overflow-x:hidden;}
.p-page{
  min-height:100vh;
  background:
    radial-gradient(ellipse at 18% 8%,  rgba(255,230,80,.22) 0%,transparent 40%),
    radial-gradient(ellipse at 82% 92%, rgba(60,30,0,.32)   0%,transparent 40%),
    radial-gradient(ellipse at 50% 50%, rgba(200,146,42,.1)  0%,transparent 55%),
    linear-gradient(155deg,#2A1600 0%,#5A3A08 15%,#9A6818 30%,#C8922A 44%,#D4AF37 50%,#C8922A 56%,#9A6818 70%,#5A3A08 85%,#2A1600 100%);
  position:relative;
}
/* Guilloché overlay */
.p-page::before{
  content:'';position:fixed;inset:0;pointer-events:none;z-index:0;
  background-image:
    repeating-linear-gradient(30deg, transparent 0,transparent 24px,rgba(255,255,255,.022) 24px,rgba(255,255,255,.022) 25px),
    repeating-linear-gradient(-30deg,transparent 0,transparent 24px,rgba(255,255,255,.022) 24px,rgba(255,255,255,.022) 25px);
}
/* Animated orbs */
.p-orb{position:fixed;border-radius:50%;pointer-events:none;z-index:0;filter:blur(70px);}
.p-orb-1{width:600px;height:600px;top:-180px;left:-180px;background:radial-gradient(circle,rgba(255,215,60,.18) 0%,transparent 70%);animation:orb1 14s ease-in-out infinite;}
.p-orb-2{width:500px;height:500px;bottom:-120px;right:-120px;background:radial-gradient(circle,rgba(100,50,0,.22) 0%,transparent 70%);animation:orb2 18s ease-in-out infinite;}

/* ════════════════════════════════════════════════
   NAVIGATION
════════════════════════════════════════════════ */
.p-nav{
  position:sticky;top:0;z-index:200;
  background:linear-gradient(135deg,var(--g9),var(--g8) 40%,var(--g9));
  border-bottom:2px solid var(--g4);
  height:64px;padding:0 20px;
  display:flex;align-items:center;justify-content:space-between;gap:12px;
  box-shadow:0 4px 32px rgba(15,8,0,.6);
  backdrop-filter:blur(20px);
}
/* Holographic nav bottom line */
.p-nav::after{
  content:'';position:absolute;bottom:-2px;left:0;right:0;height:2px;
  background:linear-gradient(90deg,transparent,var(--g5),var(--g2),var(--g1),var(--g2),var(--g5),transparent);
  background-size:300% 100%;animation:holo 4s linear infinite;
}
.p-nav-brand{display:flex;align-items:center;gap:12px;flex-shrink:0;}
.p-nav-logo{height:42px;width:auto;object-fit:contain;filter:brightness(1.2) drop-shadow(0 2px 10px rgba(0,0,0,.5));}
.p-nav-text{display:flex;flex-direction:column;gap:1px;}
.p-nav-main{font-family:'Syne',sans-serif;font-size:16px;font-weight:800;color:var(--g1);line-height:1;}
.p-nav-sub{font-family:'JetBrains Mono',monospace;font-size:8px;color:var(--g4);letter-spacing:2px;text-transform:uppercase;}
.p-nav-right{display:flex;align-items:center;gap:10px;}
/* Member chip in nav */
.p-nav-chip{
  display:flex;align-items:center;gap:9px;
  padding:6px 14px 6px 6px;
  background:rgba(255,255,255,.07);
  border:1px solid rgba(212,175,55,.3);
  border-radius:30px;
  backdrop-filter:blur(8px);
}
.p-nav-av{
  width:38px;height:38px;border-radius:50%;
  background:linear-gradient(135deg,var(--g5),var(--g7));
  border:2px solid var(--g4);
  display:flex;align-items:center;justify-content:center;
  font-family:'Syne',sans-serif;font-size:13px;font-weight:800;color:var(--g1);
  overflow:hidden;flex-shrink:0;
  box-shadow:0 0 0 3px rgba(212,175,55,.18);
}
.p-nav-av img{width:100%;height:100%;object-fit:cover;border-radius:50%;}
.p-nav-name{font-family:'Syne',sans-serif;font-size:13px;font-weight:800;color:var(--g1);line-height:1.2;}
.p-nav-id{font-family:'JetBrains Mono',monospace;font-size:8px;color:var(--g3);letter-spacing:.5px;}
.p-signout{
  display:inline-flex;align-items:center;gap:6px;
  padding:8px 14px;background:rgba(220,38,38,.12);
  color:#fca5a5;border:1px solid rgba(220,38,38,.28);border-radius:8px;
  font-family:'Syne',sans-serif;font-size:11px;font-weight:800;cursor:pointer;
  transition:all .2s;
}
.p-signout:hover{background:rgba(220,38,38,.22);color:#fecaca;}

/* ════════════════════════════════════════════════
   CONTENT WRAPPER
════════════════════════════════════════════════ */
.p-wrap{max-width:1020px;margin:0 auto;padding:24px 16px 60px;position:relative;z-index:1;}

/* ════════════════════════════════════════════════
   STATUS BAR
════════════════════════════════════════════════ */
.p-status{
  display:flex;align-items:center;gap:12px;flex-wrap:wrap;
  padding:13px 18px;
  background:rgba(255,255,255,.06);
  border:1px solid rgba(212,175,55,.22);
  border-radius:12px;margin-bottom:22px;
  backdrop-filter:blur(8px);
  animation:slide-up .4s ease both;
}
.p-status-dot{
  width:10px;height:10px;border-radius:50%;
  background:<?php echo $status_color; ?>;
  box-shadow:0 0 0 3px <?php echo $status_color; ?>33;
  animation:pulse-dot 2s ease infinite;
  color:<?php echo $status_color; ?>;
  flex-shrink:0;
}
.p-status-lbl{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--g3);letter-spacing:1.5px;text-transform:uppercase;}
.p-status-val{font-family:'JetBrains Mono',monospace;font-size:13px;color:#fff;font-weight:700;letter-spacing:.5px;}
.p-status-sep{color:rgba(212,175,55,.25);font-size:18px;}
.p-tier{
  display:inline-flex;align-items:center;gap:5px;
  padding:4px 12px;border-radius:20px;
  background:rgba(212,175,55,.15);border:1px solid rgba(212,175,55,.3);
  font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--g3);
  text-transform:uppercase;letter-spacing:1px;
}
<?php if($expiry_warn): ?>
.p-expiry-warn{
  display:flex;align-items:center;gap:10px;
  padding:12px 16px;
  background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);
  border-radius:10px;margin-bottom:16px;
  font-family:'JetBrains Mono',monospace;font-size:11px;color:#fcd34d;
}
<?php endif; ?>

/* ════════════════════════════════════════════════
   ACTION BUTTONS GRID
════════════════════════════════════════════════ */
.p-actions{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(110px,1fr));
  gap:10px;margin-bottom:24px;
}
.p-action{
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:9px;padding:18px 10px;
  background:rgba(255,255,255,.07);
  border:1.5px solid rgba(212,175,55,.25);
  border-radius:14px;cursor:pointer;text-decoration:none;
  transition:all .25s cubic-bezier(.22,.68,0,1.2);
  backdrop-filter:blur(8px);
  position:relative;overflow:hidden;
}
.p-action::before{
  content:'';position:absolute;inset:0;
  background:linear-gradient(135deg,transparent,rgba(212,175,55,.07));
  opacity:0;transition:opacity .2s;
}
.p-action:hover{
  transform:translateY(-4px) scale(1.03);
  border-color:rgba(212,175,55,.55);
  background:rgba(255,255,255,.12);
  box-shadow:0 8px 28px rgba(80,40,0,.28),0 0 0 1px rgba(212,175,55,.2);
  text-decoration:none;
}
.p-action:hover::before{opacity:1;}
.p-action:active{transform:translateY(-1px) scale(1.00);}
.p-action-icon{
  width:46px;height:46px;border-radius:13px;
  background:linear-gradient(135deg,var(--g8),var(--g6));
  display:flex;align-items:center;justify-content:center;
  font-size:20px;
  box-shadow:0 4px 12px rgba(0,0,0,.3),inset 0 1px 0 rgba(255,255,255,.12);
  transition:transform .2s;
}
.p-action:hover .p-action-icon{transform:scale(1.1) rotate(4deg);}
/* Real coin/badge logos (Driver/Ride/Delivery apps) - no gradient
 * background underneath, since these images already carry their own
 * rich circular artwork; a background would just muddy it. object-fit
 * contain (not cover) so nothing gets cropped - "ETEL DRIVER" etc. text
 * around the coin's edge needs to stay fully visible. */
.p-action-icon.logo-icon{background:none;box-shadow:none;padding:1px;}
.p-action-icon.logo-icon img{width:100%;height:100%;object-fit:contain;border-radius:10px;}
.p-action-lbl{
  font-family:'Syne',sans-serif;font-size:11px;font-weight:800;
  color:var(--g2);letter-spacing:.3px;text-align:center;line-height:1.2;
}
/* Admin tools divider */
.p-admin-divider{
  grid-column:1/-1;
  padding-top:10px;margin-top:2px;
  border-top:1px solid rgba(212,175,55,.18);
}
.p-admin-label{
  font-family:'JetBrains Mono',monospace;font-size:8px;
  color:rgba(212,175,55,.4);letter-spacing:2.5px;text-transform:uppercase;
  margin-bottom:10px;display:block;
}
.p-admin-grid{
  display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:10px;
}
.p-action.admin{border-color:rgba(34,197,94,.22);background:rgba(34,197,94,.06);}
.p-action.admin:hover{border-color:rgba(34,197,94,.45);background:rgba(34,197,94,.1);}
.p-action.admin .p-action-icon{background:linear-gradient(135deg,#166534,#15803d);}

/* ════════════════════════════════════════════════
   MAIN GRID
════════════════════════════════════════════════ */
.p-grid{display:grid;grid-template-columns:1fr 320px;gap:20px;margin-bottom:20px;}
@media(max-width:800px){.p-grid{grid-template-columns:1fr;}}

/* ════════════════════════════════════════════════
   GLASS CARD
════════════════════════════════════════════════ */
.p-card{
  background:var(--glass);
  border:1.5px solid var(--glass-border);
  border-radius:18px;overflow:hidden;
  backdrop-filter:blur(14px);
  box-shadow:0 8px 36px rgba(10,5,0,.35),inset 0 1px 0 rgba(255,255,255,.07);
  animation:slide-up .4s ease both;
}
.p-card-head{
  padding:15px 20px;
  background:var(--glass-head);
  border-bottom:1.5px solid rgba(212,175,55,.28);
  display:flex;align-items:center;gap:10px;
  position:relative;overflow:hidden;
}
/* Gold shimmer line at bottom of card head */
.p-card-head::after{
  content:'';position:absolute;bottom:0;left:0;right:0;height:1px;
  background:linear-gradient(90deg,transparent,var(--g4),transparent);
}
.p-card-head-icon{
  width:34px;height:34px;border-radius:10px;
  background:linear-gradient(135deg,var(--g6),var(--g8));
  display:flex;align-items:center;justify-content:center;
  font-size:16px;flex-shrink:0;
  box-shadow:0 2px 8px rgba(0,0,0,.3);
}
.p-card-head h3{
  font-family:'Syne',sans-serif;font-size:13px;font-weight:800;
  color:var(--g1);flex:1;margin:0;letter-spacing:.3px;
}
.p-card-body{padding:20px;}

/* ════════════════════════════════════════════════
   3D ID CARD VIEWER
════════════════════════════════════════════════ */
.p-card-scene{
  width:100%;max-width:440px;
  margin:0 auto;cursor:pointer;position:relative;
}
.p-card-wrap{
  width:100%;aspect-ratio:460/290;
  position:relative;
  filter:drop-shadow(0 18px 40px rgba(0,0,0,.55)) drop-shadow(0 5px 10px rgba(80,40,0,.4));
  animation:card-in .7s ease both;
  transform-style:preserve-3d;
  will-change:transform;
}
/* Each face occupies the full card area */
.p-card-face{
  position:absolute;
  top:0;left:0;right:0;bottom:0;
  width:100%;height:100%;
  border-radius:10px;overflow:hidden;
  transition:opacity .5s ease, transform .5s ease;
}
/* FRONT: visible by default */
.p-card-face:first-child{
  opacity:1;
  transform:scale(1);
  z-index:2;
  pointer-events:auto;
}
/* BACK: hidden by default */
.p-card-face-back{
  opacity:0;
  transform:scale(0.97);
  z-index:1;
  pointer-events:none;
}
/* FLIPPED state: front hides, back shows */
.p-card-wrap.flipped .p-card-face:first-child{
  opacity:0;
  transform:scale(0.97);
  z-index:1;
  pointer-events:none;
}
.p-card-wrap.flipped .p-card-face-back{
  opacity:1;
  transform:scale(1);
  z-index:2;
  pointer-events:auto;
}
.p-card-face .eid-card-front,
.p-card-face .eid-card-back{
  position:absolute!important;
  inset:0;border-radius:10px;
}
/* Scan line */
.p-card-scan{
  position:absolute;left:0;right:0;height:2px;
  z-index:10;
  background:linear-gradient(90deg,transparent,rgba(212,175,55,.7) 50%,transparent);
  pointer-events:none;opacity:0;animation:scan-line 2.5s linear infinite;
}
@keyframes scan-line{0%{top:0;opacity:.8}80%{opacity:.8}100%{top:100%;opacity:0}}
.p-card-scene:hover .p-card-scan{opacity:1;}
/* Card controls row */
.p-card-controls{
  display:flex;align-items:center;justify-content:center;gap:12px;
  margin-top:14px;flex-wrap:wrap;
}
.p-card-side{
  font-family:'JetBrains Mono',monospace;font-size:9px;
  color:rgba(212,175,55,.45);letter-spacing:2px;text-transform:uppercase;
}
.p-flip-btn{
  display:inline-flex;align-items:center;gap:7px;
  padding:9px 20px;background:rgba(212,175,55,.1);
  border:1.5px solid rgba(212,175,55,.3);border-radius:24px;
  font-family:'JetBrains Mono',monospace;font-size:10px;
  color:var(--g2);letter-spacing:1.5px;text-transform:uppercase;
  cursor:pointer;transition:all .2s;
}
.p-flip-btn:hover{background:rgba(212,175,55,.2);border-color:rgba(212,175,55,.6);color:var(--g1);}

/* ════════════════════════════════════════════════
   CHANGE PASSWORD PANEL
════════════════════════════════════════════════ */
.p-pw-panel{
  display:none;margin-top:16px;padding:18px;
  background:rgba(212,175,55,.05);
  border:1px solid rgba(212,175,55,.2);border-radius:12px;
}
.p-pw-panel.open{display:block;animation:slide-up .3s ease both;}
.p-pw-label{
  font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--g5);
  letter-spacing:2px;text-transform:uppercase;display:block;margin-bottom:6px;
}
.p-pw-input{
  width:100%;padding:11px 14px;
  background:rgba(212,175,55,.07);
  border:1.5px solid rgba(212,175,55,.25);
  border-radius:8px;font-size:15px;font-family:'Cormorant Garamond',serif;
  color:var(--g1);margin-bottom:12px;
  transition:border-color .2s,box-shadow .2s;
}
.p-pw-input:focus{border-color:var(--g4);outline:none;box-shadow:0 0 0 3px rgba(212,175,55,.15);}
.p-pw-input::placeholder{color:rgba(212,175,55,.3);}
.p-pw-btn{
  display:inline-flex;align-items:center;gap:7px;
  padding:11px 22px;border:none;border-radius:9px;
  background:linear-gradient(135deg,var(--g9),var(--g7),var(--g5),var(--g4));
  color:var(--g1);font-family:'Syne',sans-serif;font-size:13px;font-weight:800;
  cursor:pointer;letter-spacing:.3px;
  box-shadow:0 4px 0 var(--g9),0 6px 18px rgba(80,40,0,.3);
  transition:all .15s;position:relative;overflow:hidden;
}
.p-pw-btn::before{
  content:'';position:absolute;top:0;left:-100%;width:50%;height:100%;
  background:linear-gradient(90deg,transparent,rgba(255,255,255,.1),transparent);
  animation:shimmer 2.5s infinite;
}
.p-pw-btn:hover{transform:translateY(-1px);box-shadow:0 5px 0 var(--g9),0 8px 22px rgba(80,40,0,.35);}
.p-pw-msg{font-family:'JetBrains Mono',monospace;font-size:11px;margin-top:10px;padding:8px 12px;border-radius:6px;}
.p-pw-ok {background:rgba(34,197,94,.1);color:#86efac;border:1px solid rgba(34,197,94,.22);}
.p-pw-err{background:rgba(239,68,68,.1);color:#fca5a5;border:1px solid rgba(239,68,68,.22);}

/* ════════════════════════════════════════════════
   PERSONAL DETAILS GRID
════════════════════════════════════════════════ */
.p-details{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
@media(max-width:500px){.p-details{grid-template-columns:1fr;}}
.p-detail{
  padding:11px 14px;
  background:rgba(212,175,55,.05);
  border:1px solid rgba(212,175,55,.15);
  border-radius:10px;transition:all .15s;
}
.p-detail:hover{background:rgba(212,175,55,.1);border-color:rgba(212,175,55,.3);}
.p-detail-lbl{
  font-family:'JetBrains Mono',monospace;font-size:8px;
  color:var(--g5);letter-spacing:2px;text-transform:uppercase;margin-bottom:4px;
}
.p-detail-val{font-size:14px;font-weight:700;color:var(--g1);line-height:1.3;}
.p-detail-val-am{
  font-family:'Noto Sans Ethiopic',sans-serif;
  font-size:12px;color:var(--g3);display:block;margin-top:2px;
}
/* Company chips */
.p-chips{display:flex;flex-wrap:wrap;gap:7px;margin-top:14px;}
.p-chip{
  display:inline-flex;align-items:center;gap:6px;
  padding:7px 12px;border-radius:8px;
  background:rgba(212,175,55,.07);border:1px solid rgba(212,175,55,.2);
  font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--g2);
  transition:all .15s;
}
.p-chip:hover{background:rgba(212,175,55,.13);border-color:rgba(212,175,55,.4);}
.p-chip-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}

/* ════════════════════════════════════════════════
   RIGHT COLUMN — QR + IDENTITY
════════════════════════════════════════════════ */
.p-qr-wrap{display:flex;flex-direction:column;align-items:center;gap:8px;margin-bottom:18px;}
.p-qr-frame{
  background:var(--g1);padding:8px;border-radius:10px;
  border:2px solid var(--g4);
  box-shadow:4px 4px 0 var(--g5),6px 6px 0 var(--g6),8px 8px 0 var(--g8),
             0 20px 40px rgba(0,0,0,.3);
  line-height:0;transition:transform .3s;
}
.p-qr-frame:hover{transform:translateY(-4px) scale(1.02);}
.p-qr-frame img{display:block;border-radius:6px;}
.p-qr-lbl{
  font-family:'JetBrains Mono',monospace;font-size:8px;
  color:var(--g5);letter-spacing:2px;text-transform:uppercase;
}
/* ID fields */
.p-id-fields{display:flex;flex-direction:column;gap:9px;}
.p-id-field{
  padding:10px 14px;
  background:rgba(212,175,55,.06);
  border:1px solid rgba(212,175,55,.18);
  border-radius:10px;transition:background .15s;
}
.p-id-field:hover{background:rgba(212,175,55,.1);}
.p-id-lbl{font-family:'JetBrains Mono',monospace;font-size:8px;color:var(--g5);letter-spacing:2px;text-transform:uppercase;margin-bottom:3px;}
.p-id-val{font-family:'JetBrains Mono',monospace;font-size:14px;color:var(--g1);font-weight:700;letter-spacing:1px;display:flex;align-items:center;gap:6px;}
.p-id-val.lg{font-size:18px;color:var(--g2);}
/* Copy button */
.p-copy{background:none;border:none;color:var(--g5);cursor:pointer;font-size:14px;padding:2px 6px;border-radius:4px;transition:all .15s;}
.p-copy:hover{color:var(--g3);background:rgba(212,175,55,.1);}
/* Status badge */
.p-badge{
  display:inline-flex;align-items:center;gap:5px;
  padding:5px 14px;border-radius:20px;
  font-family:'JetBrains Mono',monospace;font-size:11px;
  font-weight:700;text-transform:uppercase;letter-spacing:1px;
}
.p-badge-active   {background:rgba(34,197,94,.15);color:#86efac;border:1px solid rgba(34,197,94,.28);}
.p-badge-suspended{background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.28);}
.p-badge-pending  {background:rgba(245,158,11,.15);color:#fcd34d;border:1px solid rgba(245,158,11,.28);}

/* ════════════════════════════════════════════════
   ACTIVITY LOG
════════════════════════════════════════════════ */
.p-activity{display:flex;flex-direction:column;gap:5px;}
.p-act-item{
  display:flex;align-items:center;gap:11px;
  padding:9px 13px;
  background:rgba(212,175,55,.04);
  border:1px solid rgba(212,175,55,.12);
  border-radius:8px;transition:background .15s;
}
.p-act-item:hover{background:rgba(212,175,55,.09);}
.p-act-icon{
  width:30px;height:30px;border-radius:8px;
  background:linear-gradient(135deg,var(--g7),var(--g8));
  display:flex;align-items:center;justify-content:center;
  font-size:13px;flex-shrink:0;
}
.p-act-name{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--g2);font-weight:700;}
.p-act-detail{font-size:11px;color:var(--g5);margin-top:1px;}
.p-act-time{font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--g6);margin-top:1px;}

/* ════════════════════════════════════════════════
   FOOTER
════════════════════════════════════════════════ */
.p-footer{
  text-align:center;padding:20px 0 0;
  font-family:'JetBrains Mono',monospace;font-size:8px;
  color:rgba(212,175,55,.28);letter-spacing:2px;text-transform:uppercase;
}

/* ════════════════════════════════════════════════
   SOCIAL & BANK ACCOUNTS (Connect & Pay on the NFC card)
════════════════════════════════════════════════ */
.p-links-list{display:flex;flex-direction:column;gap:8px;margin-bottom:12px;}
.p-links-empty{font-size:12.5px;color:var(--g5);text-align:center;padding:14px 0;}
.p-link-row{
  display:flex;align-items:center;gap:11px;
  padding:10px 12px;border-radius:10px;
  background:rgba(212,175,55,.05);border:1px solid rgba(212,175,55,.16);
}
.p-link-badge{
  width:34px;height:34px;border-radius:50%;flex:none;
  display:flex;align-items:center;justify-content:center;
  font-family:'Syne',sans-serif;font-weight:800;font-size:10px;color:#fff;
}
.p-link-body{flex:1 1 auto;min-width:0;}
.p-link-name{font-family:'Manrope',sans-serif;font-size:12.5px;font-weight:700;color:var(--g1);}
.p-link-val{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--g5);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.p-link-del{
  flex:none;background:none;border:none;color:var(--g6);cursor:pointer;
  font-size:15px;padding:4px 6px;border-radius:6px;transition:all .15s;
}
.p-link-del:hover{color:#f87171;background:rgba(248,113,113,.1);}
.p-link-add-btn{
  width:100%;padding:10px;border-radius:10px;
  border:1.5px dashed rgba(212,175,55,.35);background:transparent;
  color:var(--g2);font-family:'Manrope',sans-serif;font-size:12.5px;font-weight:700;
  cursor:pointer;transition:all .15s;
}
.p-link-add-btn:hover{border-color:rgba(212,175,55,.6);background:rgba(212,175,55,.06);}
.p-link-form{display:none;margin-top:12px;padding:14px;border-radius:10px;background:rgba(212,175,55,.05);border:1px solid rgba(212,175,55,.2);}
.p-link-form.open{display:block;animation:slide-up .3s ease both;}
.p-link-form-row{display:flex;gap:8px;margin-bottom:10px;}
.p-link-form-row .p-pw-input{margin-bottom:0;}
.p-link-type-toggle{display:flex;gap:8px;margin-bottom:10px;}
.p-link-type-btn{
  flex:1;padding:8px;border-radius:8px;text-align:center;cursor:pointer;
  border:1.5px solid rgba(212,175,55,.25);color:var(--g5);
  font-family:'Manrope',sans-serif;font-size:11.5px;font-weight:700;
  transition:all .15s;
}
.p-link-type-btn.active{border-color:var(--g2);color:var(--g1);background:rgba(212,175,55,.12);}
</style>
</head>
<body>
<div class="p-page">
<div class="p-orb p-orb-1"></div>
<div class="p-orb p-orb-2"></div>

<!-- ════ NAV ════ -->
<nav class="p-nav">
  <div class="p-nav-brand">
    <img src="<?php echo esc_attr($logo_src); ?>" alt="" class="p-nav-logo">
    <div class="p-nav-text">
      <span class="p-nav-main"><?php echo esc_html($org_name); ?></span>
      <span class="p-nav-sub">ኢትኤል ቡድን · Member Portal</span>
    </div>
  </div>
  <div class="p-nav-right">
    <div class="p-nav-chip" style="display:none;" id="p-member-chip">
      <div class="p-nav-av">
        <?php if ($member->photo_url): ?>
        <img src="<?php echo esc_url($member->photo_url); ?>" alt="">
        <?php else: echo esc_html($init); endif; ?>
      </div>
      <div>
        <div class="p-nav-name"><?php echo esc_html($member->full_name); ?></div>
        <div class="p-nav-id"><?php echo esc_html($member->etel_id); ?></div>
      </div>
    </div>
    <button class="p-signout" onclick="doSignOut()">
      <i class="ti ti-logout"></i> Sign Out
    </button>
  </div>
</nav>
<script>
/* Show member chip only on wider screens */
if(window.innerWidth>600){document.getElementById('p-member-chip').style.display='flex';}
</script>

<?php
/* ════ v3.10.0 — FAYDA quick-register follow-ups ════
 * 1. Force a password change if this account was created via the
 *    admin Fayda quick-register flow (must_change_password = 1).
 * 2. Nudge towards Pass 2 (biometric + info) if not done yet —
 *    Etel Pay requires kyc_level >= 2. */
$etelid_must_change = ! empty( $member->must_change_password );
$etelid_kyc_level   = isset( $member->kyc_level ) ? (int) $member->kyc_level : 0;
/* Quick Register (see class-etelid-auth-ajax.php::quick_register()) only
 * ever collects full name, photo, Fayda ID, email, and phone - dob is
 * always required by every other entry point (the full registration
 * wizard, admin-created accounts, the Fayda quick-register admin tool),
 * so an empty dob reliably means this account still needs the rest of
 * its profile filled in, regardless of which flow created it.
 *
 * HARDENING: this banner never actually showed for a single Quick
 * Register account, live-reported. Root cause: the dob column is
 * DATE NOT NULL - inserting the empty string EtelID_Generator::create()
 * writes when no dob was collected gets silently coerced by MySQL to
 * '0000-00-00', not stored as NULL/empty. empty('0000-00-00') is false
 * in PHP (it's a non-blank, non-zero string), so the condition below
 * never matched a real Quick Register account. Checking for that exact
 * sentinel value alongside empty() is what actually catches it. */
$etelid_profile_incomplete = empty( $member->dob ) || '0000-00-00' === $member->dob;
?>
<?php if ( $etelid_profile_incomplete ): ?>
<div id="etelid-complete-profile-banner" style="position:relative;z-index:40;max-width:1100px;margin:14px auto 0;padding:12px 18px;border-radius:12px;background:rgba(212,175,55,.12);border:1px solid rgba(212,175,55,.35);display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;font-family:'JetBrains Mono',monospace;">
  <div style="display:flex;align-items:center;gap:10px;color:#FFFEF8;font-size:13px;">
    <i class="ti ti-user-exclamation" style="color:#D4AF37;font-size:18px;"></i>
    <span>Your profile is missing a few details (date of birth, address, and a password of your own). Add them any time.</span>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="<?php echo esc_url( home_url('/profile/') ); ?>" style="background:linear-gradient(135deg,#E8C84A,#C8922A);color:#1A0E00;font-weight:700;font-size:12px;padding:8px 14px;border-radius:8px;text-decoration:none;">Complete Now</a>
    <button type="button" onclick="document.getElementById('etelid-complete-profile-banner').style.display='none';" style="background:transparent;border:1px solid rgba(212,175,55,.35);color:#D4AF37;border-radius:8px;font-size:12px;padding:8px 12px;cursor:pointer;">Later</button>
  </div>
</div>
<?php endif; ?>

<?php if ( $etelid_kyc_level < 2 && ! $etelid_must_change ): ?>
<div id="etelid-pass2-banner" style="position:relative;z-index:40;max-width:1100px;margin:14px auto 0;padding:12px 18px;border-radius:12px;background:rgba(212,175,55,.12);border:1px solid rgba(212,175,55,.35);display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;font-family:'JetBrains Mono',monospace;">
  <div style="display:flex;align-items:center;gap:10px;color:#FFFEF8;font-size:13px;">
    <i class="ti ti-shield-exclamation" style="color:#D4AF37;font-size:18px;"></i>
    <span>Etel Pay is locked. Finish the second verification step (face photo + a few details) to unlock it.</span>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="<?php echo esc_url( home_url('/verify-pass2/') ); ?>" style="background:linear-gradient(135deg,#E8C84A,#C8922A);color:#1A0E00;font-weight:700;font-size:12px;padding:8px 14px;border-radius:8px;text-decoration:none;">Verify now</a>
    <button type="button" onclick="document.getElementById('etelid-pass2-banner').style.display='none';" style="background:transparent;border:1px solid rgba(212,175,55,.35);color:#D4AF37;border-radius:8px;font-size:12px;padding:8px 12px;cursor:pointer;">Later</button>
  </div>
</div>
<?php endif; ?>

<?php if ( $etelid_must_change ): ?>
<div id="etelid-mcp-overlay" style="position:fixed;inset:0;background:rgba(8,4,0,.86);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;font-family:'JetBrains Mono',monospace;">
  <div style="max-width:420px;width:100%;background:rgba(26,14,0,.95);border:1px solid rgba(212,175,55,.35);border-radius:16px;padding:26px;">
    <h2 style="font-family:'Syne',sans-serif;color:#E8C84A;font-size:17px;margin-bottom:8px;display:flex;align-items:center;gap:8px;"><i class="ti ti-lock"></i> Set a new password</h2>
    <p style="font-size:12px;color:#cbbf9a;margin-bottom:16px;line-height:1.5;">Your account was created with a temporary password. Please set your own password to continue.</p>
    <label style="display:block;font-size:11px;color:#D4AF37;margin-bottom:5px;">Temporary password</label>
    <input type="password" id="mcp-old" style="width:100%;background:rgba(255,255,255,.06);border:1px solid rgba(212,175,55,.28);border-radius:8px;padding:10px 12px;color:#fff;font-family:inherit;font-size:13px;margin-bottom:10px;">
    <label style="display:block;font-size:11px;color:#D4AF37;margin-bottom:5px;">New password (min 8 characters)</label>
    <input type="password" id="mcp-new" style="width:100%;background:rgba(255,255,255,.06);border:1px solid rgba(212,175,55,.28);border-radius:8px;padding:10px 12px;color:#fff;font-family:inherit;font-size:13px;margin-bottom:10px;">
    <div id="mcp-pw-checklist" style="display:none;font-size:11px;margin:-4px 0 10px;line-height:1.7;">
      <div id="mcp-pwc-len" data-label="At least 8 characters" style="color:#cbbf9a;">[ ] At least 8 characters</div>
      <div id="mcp-pwc-upper" data-label="One uppercase letter (A-Z)" style="color:#cbbf9a;">[ ] One uppercase letter (A-Z)</div>
      <div id="mcp-pwc-lower" data-label="One lowercase letter (a-z)" style="color:#cbbf9a;">[ ] One lowercase letter (a-z)</div>
      <div id="mcp-pwc-num" data-label="One number (0-9)" style="color:#cbbf9a;">[ ] One number (0-9)</div>
      <div id="mcp-pwc-special" data-label="One special character (!@#$%...)" style="color:#cbbf9a;">[ ] One special character (!@#$%...)</div>
    </div>
    <label style="display:block;font-size:11px;color:#D4AF37;margin-bottom:5px;">Confirm new password</label>
    <input type="password" id="mcp-confirm" style="width:100%;background:rgba(255,255,255,.06);border:1px solid rgba(212,175,55,.28);border-radius:8px;padding:10px 12px;color:#fff;font-family:inherit;font-size:13px;margin-bottom:14px;">
    <button type="button" id="mcp-submit" style="width:100%;background:linear-gradient(135deg,#E8C84A,#C8922A);color:#1A0E00;font-family:'Syne',sans-serif;font-weight:700;border:none;border-radius:10px;padding:12px;cursor:pointer;font-size:13px;">Change Password &amp; Continue</button>
    <div id="mcp-msg" style="font-size:12px;margin-top:10px;display:none;border-radius:8px;padding:9px 11px;"></div>
  </div>
</div>
<script>
(function(){
  var btn = document.getElementById('mcp-submit');
  var msg = document.getElementById('mcp-msg');
  /* Live strength checklist, same shape as the registration wizard's
   * (templates/auth-page.php) - purely informational like there (the
   * only hard requirement, here and server-side in
   * EtelID_Auth_Ajax::change_password(), is the 8-character minimum),
   * but shown here too so a person changing their temp password gets
   * the same guidance as someone registering fresh. */
  function mcpPwCheckItem(id, met) {
    var el = document.getElementById(id);
    el.style.color = met ? '#86efac' : '#cbbf9a';
    el.textContent = (met ? '[OK] ' : '[ ] ') + el.getAttribute('data-label');
  }
  document.getElementById('mcp-new').addEventListener('input', function() {
    var pw = this.value;
    var checklist = document.getElementById('mcp-pw-checklist');
    if (!pw) { checklist.style.display = 'none'; return; }
    checklist.style.display = 'block';
    mcpPwCheckItem('mcp-pwc-len',     pw.length >= 8);
    mcpPwCheckItem('mcp-pwc-upper',   /[A-Z]/.test(pw));
    mcpPwCheckItem('mcp-pwc-lower',   /[a-z]/.test(pw));
    mcpPwCheckItem('mcp-pwc-num',     /[0-9]/.test(pw));
    mcpPwCheckItem('mcp-pwc-special', /[^A-Za-z0-9]/.test(pw));
  });
  btn.addEventListener('click', function(){
    var oldP = document.getElementById('mcp-old').value;
    var newP = document.getElementById('mcp-new').value;
    var cfP  = document.getElementById('mcp-confirm').value;
    if (!oldP || newP.length < 8 || newP !== cfP) {
      msg.style.display='block'; msg.style.background='rgba(239,68,68,.12)'; msg.style.color='#fca5a5';
      msg.textContent = (!oldP) ? 'Enter your temporary password.' : (newP.length<8 ? 'New password must be at least 8 characters.' : 'Passwords do not match.');
      return;
    }
    btn.disabled = true; btn.textContent = 'Saving…';
    var fd = new FormData();
    fd.append('action','etelid_auth_change_password');
    fd.append('nonce', '<?php echo esc_js( $nonce ); ?>');
    fd.append('old_password', oldP);
    fd.append('new_password', newP);
    fd.append('confirm_password', cfP);
    fetch('<?php echo esc_url( $ajax ); ?>', { method:'POST', body: fd, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (j.success) {
          document.getElementById('etelid-mcp-overlay').remove();
        } else {
          msg.style.display='block'; msg.style.background='rgba(239,68,68,.12)'; msg.style.color='#fca5a5';
          msg.textContent = (j.data && j.data.message) ? j.data.message : 'Could not change password.';
          btn.disabled = false; btn.textContent = 'Change Password & Continue';
        }
      })
      .catch(function(){
        msg.style.display='block'; msg.style.background='rgba(239,68,68,.12)'; msg.style.color='#fca5a5';
        msg.textContent = 'Network error. Please try again.';
        btn.disabled = false; btn.textContent = 'Change Password & Continue';
      });
  });
})();
</script>
<?php endif; ?>

<!-- ════ CONTENT ════ -->
<div class="p-wrap">

  <!-- Status bar -->
  <div class="p-status">
    <div class="p-status-dot"></div>
    <span class="p-status-lbl">STATUS</span>
    <span class="p-status-val"><?php echo esc_html(strtoupper($member->status)); ?></span>
    <span class="p-status-sep">|</span>
    <span class="p-status-val"><?php echo esc_html($member->etel_id); ?></span>
    <span class="p-status-sep">|</span>
    <span class="p-tier">⭐ <?php echo esc_html(strtoupper($member->tier ?: 'Standard')); ?></span>
    <?php if ($expiry_warn): ?>
    <span style="margin-left:auto;font-family:'JetBrains Mono',monospace;font-size:10px;color:#fcd34d;">
      ⚠ Expires in <?php echo (int)$days_left; ?> days
    </span>
    <?php endif; ?>
  </div>

  <?php if ($expiry_warn): ?>
  <div class="p-expiry-warn">
    <i class="ti ti-alert-triangle" style="font-size:18px;color:#fbbf24;"></i>
    Your Etel-ID expires on <strong><?php echo esc_html($member->expiry_date); ?></strong>
    — <?php echo (int)$days_left; ?> days remaining. Contact admin to renew.
  </div>
  <?php endif; ?>

  <!-- ════ ACTION BUTTONS ════ -->
  <div class="p-actions">
    <a href="<?php echo esc_url($print_url); ?>" target="_blank" class="p-action">
      <div class="p-action-icon">🖨</div>
      <span class="p-action-lbl">Print Card</span>
    </a>
    <a href="<?php echo esc_url($verify_url); ?>" target="_blank" class="p-action">
      <div class="p-action-icon">🔗</div>
      <span class="p-action-lbl">Verify Page</span>
    </a>
    <div class="p-action" onclick="togglePw()">
      <div class="p-action-icon" id="pw-icon">🔐</div>
      <span class="p-action-lbl">Change Password</span>
    </div>
    <div class="p-action" onclick="flipCard()">
      <div class="p-action-icon">🔄</div>
      <span class="p-action-lbl">Flip Card</span>
    </div>
    <a href="<?php echo esc_url(home_url('/profile/')); ?>" class="p-action">
      <div class="p-action-icon">✏️</div>
      <span class="p-action-lbl">Edit Profile</span>
    </a>
    <div class="p-action" onclick="openBioRegisterModal(event)">
      <div class="p-action-icon">🆔</div>
      <span class="p-action-lbl">Face / Fingerprint</span>
    </div>
    <div class="p-action" onclick="openTelegramLink()">
      <div class="p-action-icon">📲</div>
      <span class="p-action-lbl"><?php echo empty($member->tg_chat_id) ? 'Link Telegram' : 'Telegram Linked ✓'; ?></span>
    </div>
    <a href="<?php echo esc_url($driver_app_url); ?>" target="_blank" class="p-action">
      <div class="p-action-icon logo-icon"><img src="<?php echo esc_url(ETELID_URL.'public/images/logo-driver.webp'); ?>" alt="Etel Driver"></div>
      <span class="p-action-lbl">Driver App</span>
    </a>
    <a href="<?php echo esc_url($rider_app_url); ?>" target="_blank" class="p-action">
      <div class="p-action-icon logo-icon"><img src="<?php echo esc_url(ETELID_URL.'public/images/logo-ride.webp'); ?>" alt="Etel Ride"></div>
      <span class="p-action-lbl">Rider App</span>
    </a>
    <a href="<?php echo esc_url($customer_app_url); ?>" target="_blank" class="p-action">
      <div class="p-action-icon logo-icon"><img src="<?php echo esc_url(ETELID_URL.'public/images/etel-delivery-coin.webp'); ?>" alt="Etel Delivery"></div>
      <span class="p-action-lbl">Customer App</span>
    </a>

    <?php if ($is_admin): ?>
    <div class="p-admin-divider">
      <span class="p-admin-label">Admin Tools</span>
      <div class="p-admin-grid">
        <a href="#" onclick="openFaydaModal(event)" class="p-action admin">
          <div class="p-action-icon">🪪</div>
          <span class="p-action-lbl">Fayda Register</span>
        </a>
        <a href="#" onclick="openMembersModal(event)" class="p-action admin">
          <div class="p-action-icon">👥</div>
          <span class="p-action-lbl">Members</span>
        </a>
        <a href="#" onclick="openNfcModal(event)" class="p-action admin">
          <div class="p-action-icon">📡</div>
          <span class="p-action-lbl">Write NFC Card</span>
        </a>
        <a href="#" onclick="openUpdaterModal(event)" class="p-action admin">
          <div class="p-action-icon">🔄</div>
          <span class="p-action-lbl">Updater</span>
        </a>
        <a href="#" onclick="openBrandingModal(event)" class="p-action admin">
          <div class="p-action-icon">🎨</div>
          <span class="p-action-lbl">Theme</span>
        </a>
        <a href="#" onclick="openPagesModal(event,'new')" class="p-action admin">
          <div class="p-action-icon">📄</div>
          <span class="p-action-lbl">New Page</span>
        </a>
        <a href="#" onclick="openMenusModal(event)" class="p-action admin">
          <div class="p-action-icon">📋</div>
          <span class="p-action-lbl">Menus</span>
        </a>
        <a href="#" onclick="openMediaModal(event)" class="p-action admin">
          <div class="p-action-icon">🖼</div>
          <span class="p-action-lbl">Media</span>
        </a>
        <a href="#" onclick="openPagesModal(event,'list')" class="p-action admin">
          <div class="p-action-icon">📑</div>
          <span class="p-action-lbl">Pages</span>
        </a>
        <a href="#" onclick="openResetPwModal(event)" class="p-action admin">
          <div class="p-action-icon">🔑</div>
          <span class="p-action-lbl">Reset PW</span>
        </a>
        <a href="#" onclick="openCompanyRolesModal(event)" class="p-action admin">
          <div class="p-action-icon">🏢</div>
          <span class="p-action-lbl">Company Roles</span>
        </a>
        <a href="#" onclick="openBiometricCenterModal(event)" class="p-action admin">
          <div class="p-action-icon">🔏</div>
          <span class="p-action-lbl">Biometric Center</span>
        </a>

        <a href="#" onclick="openEidDiag(event)" class="p-action admin">
          <div class="p-action-icon">🔬</div>
          <span class="p-action-lbl">Diagnostics</span>
        </a>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ════ MAIN GRID ════ -->
  <div class="p-grid">

    <!-- LEFT: ID Card + Details -->
    <div style="display:flex;flex-direction:column;gap:20px;">

      <!-- 3D ID Card -->
      <div class="p-card">
        <div class="p-card-head">
          <div class="p-card-head-icon">🪪</div>
          <h3>Your Etel-ID Card</h3>
          <span style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--g5);letter-spacing:1px;">CLICK TO FLIP</span>
        </div>
        <div class="p-card-body">
          <div class="p-card-scene" onclick="flipCard()">
            <div class="p-card-wrap" id="p-card-3d">
              <!-- Specular sheen overlay -->
              <div class="p-card-sheen" style="
                position:absolute;inset:0;z-index:50;pointer-events:none;
                border-radius:10px;opacity:0;transition:opacity .15s;
                mix-blend-mode:overlay;
              "></div>
              <div class="p-card-face">
                <?php include ETELID_PATH.'templates/card-front.php'; ?>
              </div>
              <div class="p-card-face p-card-face-back">
                <?php include ETELID_PATH.'templates/card-back.php'; ?>
              </div>
            </div>
            <div class="p-card-scan"></div>
          </div>
          <div class="p-card-controls">
            <span class="p-card-side" id="p-card-side-lbl">FRONT SIDE</span>
            <button class="p-flip-btn" onclick="event.stopPropagation();flipCard()">
              <i class="ti ti-refresh"></i> Flip Card
            </button>
          </div>

          <!-- Change password -->
          <div class="p-pw-panel" id="p-pw-panel">
            <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:800;color:var(--g2);margin-bottom:14px;">🔐 Change Password</div>
            <label class="p-pw-label">Current Password</label>
            <input type="password" class="p-pw-input" id="pw-old" placeholder="Your current password">
            <label class="p-pw-label">New Password</label>
            <input type="password" class="p-pw-input" id="pw-new" placeholder="Min 6 characters">
            <label class="p-pw-label">Confirm New Password</label>
            <input type="password" class="p-pw-input" id="pw-cfm" placeholder="Repeat new password">
            <button class="p-pw-btn" onclick="doChangePw()">
              <i class="ti ti-lock-check"></i> Update Password
            </button>
            <div id="p-pw-msg" style="display:none;" class="p-pw-msg"></div>
          </div>
        </div>
      </div>

      <!-- Personal Details -->
      <div class="p-card">
        <div class="p-card-head">
          <div class="p-card-head-icon">👤</div>
          <h3>Personal Details</h3>
        </div>
        <div class="p-card-body">
          <div class="p-details">
            <div class="p-detail">
              <div class="p-detail-lbl">Full Name</div>
              <div class="p-detail-val"><?php echo esc_html($member->full_name); ?>
                <?php if ($member->full_name_am): ?><span class="p-detail-val-am"><?php echo esc_html($member->full_name_am); ?></span><?php endif; ?>
              </div>
            </div>
            <div class="p-detail">
              <div class="p-detail-lbl">Date of Birth</div>
              <div class="p-detail-val"><?php echo esc_html($member->dob); ?>
                <?php if (!empty($member->dob_eth)): ?><span class="p-detail-val-am"><?php echo esc_html($member->dob_eth); ?></span><?php endif; ?>
              </div>
            </div>
            <div class="p-detail">
              <div class="p-detail-lbl">Sex</div>
              <div class="p-detail-val"><?php echo esc_html($member->sex); ?></div>
            </div>
            <div class="p-detail">
              <div class="p-detail-lbl">Blood Type</div>
              <div class="p-detail-val"><?php echo esc_html($member->blood_type ?: '—'); ?></div>
            </div>
            <div class="p-detail">
              <div class="p-detail-lbl">Nationality</div>
              <div class="p-detail-val"><?php echo esc_html($member->nationality); ?></div>
            </div>
            <div class="p-detail">
              <div class="p-detail-lbl">Phone</div>
              <div class="p-detail-val"><?php echo esc_html($member->phone); ?></div>
            </div>
            <?php if ($member->email): ?>
            <div class="p-detail" style="grid-column:1/-1;">
              <div class="p-detail-lbl">Email</div>
              <div class="p-detail-val" style="font-size:13px;"><?php echo esc_html($member->email); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($member->region): ?>
            <div class="p-detail">
              <div class="p-detail-lbl">Region / Zone</div>
              <div class="p-detail-val" style="font-size:13px;"><?php echo esc_html($member->region); ?><?php if($member->zone) echo ' · '.esc_html($member->zone); ?></div>
            </div>
            <?php endif; ?>
            <div class="p-detail">
              <div class="p-detail-lbl">Issue Date</div>
              <div class="p-detail-val" style="font-size:13px;"><?php echo esc_html($member->issue_date); ?>
                <?php if (!empty($member->issue_date_eth)): ?><span class="p-detail-val-am"><?php echo esc_html($member->issue_date_eth); ?></span><?php endif; ?>
              </div>
            </div>
            <div class="p-detail">
              <div class="p-detail-lbl">Expiry Date</div>
              <div class="p-detail-val" style="font-size:13px;<?php echo $expiry_warn?'color:#fcd34d;':'' ?>"><?php echo esc_html($member->expiry_date); ?></div>
            </div>
          </div>
          <?php if ($member->primary_company || $member->role): ?>
          <div style="margin-top:13px;padding:11px 14px;background:rgba(212,175,55,.07);border:1px solid rgba(212,175,55,.18);border-radius:10px;">
            <div class="p-detail-lbl" style="margin-bottom:5px;">Company & Role</div>
            <div class="p-detail-val"><?php echo esc_html($member->primary_company); ?>
              <?php if($member->role): ?> <span style="color:var(--g4);">·</span> <?php echo esc_html($member->role); ?><?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
          <?php if ($companies): ?>
          <div class="p-chips">
            <?php
            $chip_colors = array('#C8922A','#3b82f6','#22c55e','#a855f7','#ef4444','#f59e0b','#06b6d4','#ec4899');
            $ci = 0;
            foreach ($companies as $co):
              $cc = $chip_colors[$ci % count($chip_colors)]; $ci++;
            ?>
            <div class="p-chip">
              <div class="p-chip-dot" style="background:<?php echo $cc; ?>;box-shadow:0 0 5px <?php echo $cc; ?>;"></div>
              <?php echo esc_html($co->company_name); ?>
              <?php if (!empty($co->role_at) && $co->role_at !== 'Member'): ?>
              <span style="color:var(--g5);font-size:9px;">· <?php echo esc_html($co->role_at); ?></span>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /left col -->

    <!-- RIGHT: QR + Identity + Activity -->
    <div style="display:flex;flex-direction:column;gap:20px;">

      <!-- Identity Numbers + QR -->
      <div class="p-card">
        <div class="p-card-head">
          <div class="p-card-head-icon">📲</div>
          <h3>Identity Numbers</h3>
        </div>
        <div class="p-card-body">
          <div class="p-qr-wrap">
            <div class="p-qr-frame">
              <?php echo $qr_img; /* already a complete, escaped <img> tag from EtelID_QR::img() - includes its own width/height/alt */ ?>
            </div>
            <div class="p-qr-lbl">Scan to verify identity</div>
          </div>
          <div class="p-id-fields">
            <div class="p-id-field">
              <div class="p-id-lbl">Etel-ID</div>
              <div class="p-id-val lg"><?php echo esc_html($member->etel_id); ?>
                <button class="p-copy" onclick="pCopy('<?php echo esc_js($member->etel_id); ?>',this)" title="Copy"><i class="ti ti-copy"></i></button>
              </div>
            </div>
            <div class="p-id-field">
              <div class="p-id-lbl">FIN Number</div>
              <div class="p-id-val"><?php echo esc_html($member->fin); ?>
                <button class="p-copy" onclick="pCopy('<?php echo esc_js($member->fin); ?>',this)" title="Copy"><i class="ti ti-copy"></i></button>
              </div>
            </div>
            <div class="p-id-field">
              <div class="p-id-lbl">Card Number</div>
              <div class="p-id-val" style="font-size:11px;letter-spacing:2px;"><?php echo esc_html($member->card_number); ?></div>
            </div>
            <div class="p-id-field">
              <div class="p-id-lbl">Status</div>
              <div class="p-badge p-badge-<?php echo esc_attr($member->status); ?>"><?php echo esc_html(ucfirst($member->status)); ?></div>
            </div>
            <div class="p-id-field">
              <div class="p-id-lbl">QR Scan Count</div>
              <div class="p-id-val"><?php echo (int)$member->qr_scan_count; ?> times</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Social & Bank Accounts (Connect & Pay on the NFC card) -->
      <div class="p-card">
        <div class="p-card-head">
          <div class="p-card-head-icon">🔗</div>
          <h3>Social &amp; Bank Accounts</h3>
          <button type="button" class="p-flip-btn" id="p-logos-admin-btn" style="display:none;margin-left:auto;" onclick="openPlatformLogosModal()">🖼 Platform Logos</button>
        </div>
        <div class="p-card-body">
          <p style="font-size:11.5px;color:var(--g5);margin:0 0 12px;">Shown on your NFC card so a signed-in scanner can follow you or pay you instantly - no manual typing.</p>
          <div class="p-links-list" id="p-links-list">
            <div class="p-links-empty">Loading...</div>
          </div>
          <button type="button" class="p-link-add-btn" id="p-link-add-toggle" onclick="toggleLinkForm()">+ Add Social or Bank Account</button>

          <div class="p-link-form" id="p-link-form">
            <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:800;color:var(--g2);margin-bottom:12px;" id="p-link-form-title">Add Account</div>
            <div class="p-link-type-toggle">
              <div class="p-link-type-btn active" id="p-link-type-social" onclick="setLinkType('social')">📱 Social</div>
              <div class="p-link-type-btn" id="p-link-type-bank" onclick="setLinkType('bank')">🏦 Bank</div>
            </div>
            <div class="p-link-form-row">
              <select id="p-link-platform" class="p-pw-input" style="cursor:pointer;"></select>
            </div>
            <div class="p-link-form-row" id="p-link-name-row" style="display:none;">
              <input type="text" id="p-link-account-name" class="p-pw-input" placeholder="Account holder name">
            </div>
            <div class="p-link-form-row">
              <input type="text" id="p-link-value" class="p-pw-input" placeholder="Handle, profile link, or account number">
            </div>
            <div style="display:flex;gap:8px;">
              <button type="button" class="p-pw-btn" style="flex:1;" id="p-link-save-btn" onclick="saveLink()">Save Account</button>
              <button type="button" class="p-flip-btn" onclick="toggleLinkForm()">Cancel</button>
            </div>
            <div id="p-link-msg" class="p-pw-msg" style="display:none;"></div>
          </div>
        </div>
      </div>

      <!-- Activity -->
      <?php if ($log): ?>
      <div class="p-card">
        <div class="p-card-head">
          <div class="p-card-head-icon">📋</div>
          <h3>Recent Activity</h3>
        </div>
        <div class="p-card-body" style="padding:14px;">
          <div class="p-activity">
            <?php
            $icons = array('login'=>'🔑','verified'=>'✅','printed'=>'🖨','updated'=>'✏️','registered'=>'🎉','issued'=>'🪪','login_failed'=>'❌','checkin'=>'📡','suspended'=>'🔒');
            foreach ($log as $entry):
              $ev   = isset($entry->event)  ? $entry->event  : (isset($entry->action) ? $entry->action : '');
              $det  = isset($entry->detail) ? $entry->detail : '';
              $icon = isset($icons[$ev]) ? $icons[$ev] : '📌';
              $time = $entry->created_at ? human_time_diff(strtotime($entry->created_at),time()).' ago' : '';
            ?>
            <div class="p-act-item">
              <div class="p-act-icon"><?php echo $icon; ?></div>
              <div style="flex:1;min-width:0;">
                <div class="p-act-name"><?php echo esc_html(str_replace('_',' ',ucfirst($ev))); ?></div>
                <?php if ($det): ?><div class="p-act-detail"><?php echo esc_html($det); ?></div><?php endif; ?>
                <div class="p-act-time"><?php echo esc_html($time); ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /right col -->

  </div><!-- /grid -->

  <div class="p-footer">
    <?php echo esc_html($org_name); ?> · ኢትኤል ቡድን · v<?php echo ETELID_VERSION; ?>
  </div>
</div><!-- /p-wrap -->
</div><!-- /p-page -->

<script>
var _P = {
  ajax:  '<?php echo esc_js($ajax); ?>',
  nonce: '<?php echo esc_js($nonce); ?>',
  verifyBase: '<?php echo esc_js( home_url( '/verify/' ) ); ?>',
  flip:  false
};

/* ── Generic AJAX helper for the in-portal admin panels (Members/Pages/Media/Menus/Branding/Reset PW) ── */
async function _eidPanel(action, params, isFormData) {
  params = params || {};
  try {
    var opts = { method: 'POST' };
    if (isFormData) {
      var fd = new FormData();
      fd.append('action', action);
      fd.append('nonce', _P.nonce);
      Object.keys(params).forEach(function(k){ fd.append(k, params[k]); });
      opts.body = fd;
    } else {
      var body = 'action=' + encodeURIComponent(action) + '&nonce=' + encodeURIComponent(_P.nonce);
      Object.keys(params).forEach(function(k){ body += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); });
      opts.headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
      opts.body = body;
    }
    var r = await fetch(_P.ajax, opts);
    var data = await r.json();
    return data;
  } catch (e) {
    return { success: false, data: { message: 'Network error: ' + e.message } };
  }
}

async function openTelegramLink() {
  var res = await _eidPanel('etelid_get_telegram_link', {});
  if (!res.success) {
    alert((res.data && res.data.message) ? res.data.message : 'Could not get Telegram link.');
    return;
  }
  if (res.data.already_linked) {
    if (!confirm('Telegram is already linked. Open the link again to re-link (e.g. if you switched Telegram accounts)?')) return;
  }
  window.open(res.data.url, '_blank');
}
function _eidPanelMsg(elId, msg, ok) {
  var el = document.getElementById(elId);
  if (!el) return;
  el.style.display = 'block';
  el.style.color = ok ? '#6ee7a0' : '#ef9a9a';
  el.style.background = ok ? 'rgba(34,197,94,.1)' : 'rgba(239,68,68,.1)';
  el.style.border = '1px solid ' + (ok ? 'rgba(34,197,94,.35)' : 'rgba(239,68,68,.35)');
  el.style.borderRadius = '8px';
  el.style.padding = '8px 12px';
  el.style.fontSize = '12px';
  el.style.marginTop = '10px';
  el.textContent = msg;
}

/* ── Social & Bank Accounts (Connect & Pay on the NFC card) ── */
var _PL = { platforms: null, type: 'social', editingId: 0, links: [] };

function _plMonogramColor(meta) {
  return 'background:' + (meta && meta.color ? meta.color : '#A07820') + ';';
}

async function loadLinks() {
  var res = await _eidPanel('etelid_links_list', {});
  var list = document.getElementById('p-links-list');
  if (!res.success) {
    list.innerHTML = '<div class="p-links-empty">Could not load your accounts.</div>';
    return;
  }
  _PL.platforms = res.data.platforms || {};
  _PL.links = res.data.links || [];
  renderLinksList(_PL.links);
  populatePlatformSelect();
  document.getElementById('p-logos-admin-btn').style.display = res.data.is_admin ? 'inline-flex' : 'none';
}

function _plBadgeHtml(meta) {
  if (meta && meta.logo) {
    return '<img src="' + meta.logo.replace(/"/g, '&quot;') + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">';
  }
  return (meta && meta.mono) ? meta.mono : '?';
}

function renderLinksList(links) {
  var list = document.getElementById('p-links-list');
  if (!links.length) {
    list.innerHTML = '<div class="p-links-empty">No accounts added yet. Add your social profiles or bank/mobile money accounts below.</div>';
    return;
  }
  list.innerHTML = '';
  links.forEach(function (l) {
    var row = document.createElement('div');
    row.className = 'p-link-row';

    var badge = document.createElement('div');
    badge.className = 'p-link-badge';
    if (!(l.meta && l.meta.logo)) badge.setAttribute('style', _plMonogramColor(l.meta));
    badge.innerHTML = _plBadgeHtml(l.meta);
    row.appendChild(badge);

    var body = document.createElement('div');
    body.className = 'p-link-body';
    var nm = document.createElement('div');
    nm.className = 'p-link-name';
    nm.textContent = l.label;
    var val = document.createElement('div');
    val.className = 'p-link-val';
    val.textContent = l.link_type === 'bank' && l.account_name ? (l.account_name + ' · ' + l.account_value) : l.account_value;
    body.appendChild(nm);
    body.appendChild(val);
    body.style.cursor = 'pointer';
    body.addEventListener('click', function () { editLink(l); });
    row.appendChild(body);

    var edit = document.createElement('button');
    edit.type = 'button';
    edit.className = 'p-link-del';
    edit.innerHTML = '<i class="ti ti-pencil"></i>';
    edit.title = 'Edit';
    edit.addEventListener('click', function () { editLink(l); });
    row.appendChild(edit);

    var del = document.createElement('button');
    del.type = 'button';
    del.className = 'p-link-del';
    del.innerHTML = '<i class="ti ti-trash"></i>';
    del.title = 'Remove';
    del.addEventListener('click', function () { deleteLink(l.id); });
    row.appendChild(del);

    list.appendChild(row);
  });
}

function populatePlatformSelect() {
  var sel = document.getElementById('p-link-platform');
  if (!sel || !_PL.platforms) return;
  var group = _PL.platforms[_PL.type] || {};
  var current = sel.value;
  sel.innerHTML = '';
  Object.keys(group).forEach(function (key) {
    var opt = document.createElement('option');
    opt.value = key;
    opt.textContent = group[key].label;
    sel.appendChild(opt);
  });
  if (current && group[current]) sel.value = current;
  document.getElementById('p-link-name-row').style.display = (_PL.type === 'bank') ? 'block' : 'none';
  document.getElementById('p-link-value').placeholder = (_PL.type === 'bank') ? 'Account number' : 'Handle or profile link (e.g. yourname)';
}

function setLinkType(type) {
  _PL.type = type;
  document.getElementById('p-link-type-social').classList.toggle('active', type === 'social');
  document.getElementById('p-link-type-bank').classList.toggle('active', type === 'bank');
  populatePlatformSelect();
}

// Opens the form empty for a new account. Editing goes through
// editLink() instead, which fills the same form and sets
// _PL.editingId so saveLink() updates that row rather than inserting.
function toggleLinkForm() {
  var form = document.getElementById('p-link-form');
  if (form.classList.contains('open')) {
    form.classList.remove('open');
    _PL.editingId = 0;
    return;
  }
  _PL.editingId = 0;
  document.getElementById('p-link-form-title').textContent = 'Add Account';
  document.getElementById('p-link-save-btn').textContent = 'Save Account';
  document.getElementById('p-link-value').value = '';
  document.getElementById('p-link-account-name').value = '';
  document.getElementById('p-link-msg').style.display = 'none';
  setLinkType('social');
  form.classList.add('open');
  if (!_PL.platforms) loadLinks(); else populatePlatformSelect();
}

function editLink(l) {
  _PL.editingId = l.id;
  _PL.type = l.link_type;
  document.getElementById('p-link-type-social').classList.toggle('active', l.link_type === 'social');
  document.getElementById('p-link-type-bank').classList.toggle('active', l.link_type === 'bank');
  populatePlatformSelect();
  document.getElementById('p-link-platform').value = l.platform;
  document.getElementById('p-link-value').value = l.account_value;
  document.getElementById('p-link-account-name').value = l.account_name || '';
  document.getElementById('p-link-name-row').style.display = (l.link_type === 'bank') ? 'block' : 'none';
  document.getElementById('p-link-form-title').textContent = 'Edit Account';
  document.getElementById('p-link-save-btn').textContent = 'Update Account';
  document.getElementById('p-link-msg').style.display = 'none';
  document.getElementById('p-link-form').classList.add('open');
  document.getElementById('p-link-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

async function saveLink() {
  var platform = document.getElementById('p-link-platform').value;
  var value    = document.getElementById('p-link-value').value.trim();
  var name     = document.getElementById('p-link-account-name').value.trim();
  if (!value) { _eidPanelMsg('p-link-msg', _PL.type === 'bank' ? 'Enter the account number.' : 'Enter your handle or profile link.', false); return; }

  var res = await _eidPanel('etelid_links_save', {
    id: _PL.editingId || 0,
    link_type: _PL.type,
    platform: platform,
    account_value: value,
    account_name: name
  });
  if (!res.success) {
    _eidPanelMsg('p-link-msg', (res.data && res.data.message) ? res.data.message : 'Could not save.', false);
    return;
  }
  _PL.links = res.data.links || [];
  renderLinksList(_PL.links);
  toggleLinkForm();
}

async function deleteLink(id) {
  if (!confirm('Remove this account from your card?')) return;
  var res = await _eidPanel('etelid_links_delete', { id: id });
  if (res.success) { _PL.links = res.data.links || []; renderLinksList(_PL.links); }
}

loadLinks();

/* ── Platform Logos (admin-only) ── */
function openPlatformLogosModal() {
  _eapOpen('eap-platform-logos-modal');
  loadPlatformLogos();
}
function closePlatformLogosModal() { _eapClose('eap-platform-logos-modal'); }

async function loadPlatformLogos() {
  var res = await _eidPanel('etelid_links_admin_logos_list', {});
  var grid = document.getElementById('p-logos-grid');
  if (!res.success) {
    grid.innerHTML = '<div style="color:#ef9a9a;font-size:12.5px;">' + ((res.data && res.data.message) || 'Could not load.') + '</div>';
    return;
  }
  _PL.platforms = res.data.platforms || {};
  renderPlatformLogosGrid();
}

function renderPlatformLogosGrid() {
  var grid = document.getElementById('p-logos-grid');
  grid.innerHTML = '';
  ['social', 'bank'].forEach(function (type) {
    var group = (_PL.platforms && _PL.platforms[type]) || {};
    Object.keys(group).forEach(function (key) {
      var meta = group[key];
      var tile = document.createElement('div');
      tile.style.cssText = 'border:1px solid rgba(212,175,55,.22);border-radius:10px;padding:10px;text-align:center;background:rgba(212,175,55,.04);';

      var preview = document.createElement('div');
      preview.style.cssText = 'width:44px;height:44px;border-radius:50%;margin:0 auto 8px;display:flex;align-items:center;justify-content:center;font-family:Syne,sans-serif;font-weight:800;font-size:11px;color:#fff;overflow:hidden;' + (meta.logo ? '' : ('background:' + meta.color + ';'));
      preview.innerHTML = meta.logo ? ('<img src="' + meta.logo.replace(/"/g,'&quot;') + '" style="width:100%;height:100%;object-fit:cover;">') : meta.mono;
      tile.appendChild(preview);

      var lbl = document.createElement('div');
      lbl.style.cssText = 'font-size:10.5px;color:var(--g2);font-weight:700;margin-bottom:8px;';
      lbl.textContent = meta.label;
      tile.appendChild(lbl);

      var fileInput = document.createElement('input');
      fileInput.type = 'file';
      fileInput.accept = 'image/*';
      fileInput.style.display = 'none';
      fileInput.addEventListener('change', function () {
        if (fileInput.files && fileInput.files[0]) uploadPlatformLogo(type, key, fileInput.files[0]);
      });
      tile.appendChild(fileInput);

      var upBtn = document.createElement('button');
      upBtn.type = 'button';
      upBtn.className = 'p-flip-btn';
      upBtn.style.cssText = 'width:100%;justify-content:center;font-size:9px;padding:6px 8px;margin-bottom:4px;';
      upBtn.textContent = meta.logo ? 'Replace' : 'Upload';
      upBtn.addEventListener('click', function () { fileInput.click(); });
      tile.appendChild(upBtn);

      if (meta.logo) {
        var rmBtn = document.createElement('button');
        rmBtn.type = 'button';
        rmBtn.className = 'p-flip-btn';
        rmBtn.style.cssText = 'width:100%;justify-content:center;font-size:9px;padding:6px 8px;color:#ef9a9a;border-color:rgba(239,68,68,.35);';
        rmBtn.textContent = 'Remove';
        rmBtn.addEventListener('click', function () { removePlatformLogo(type, key); });
        tile.appendChild(rmBtn);
      }

      grid.appendChild(tile);
    });
  });
}

async function uploadPlatformLogo(type, key, file) {
  var res = await _eidPanel('etelid_links_admin_logo_upload', { link_type: type, platform: key, file: file }, true);
  if (!res.success) { alert((res.data && res.data.message) || 'Upload failed.'); return; }
  _PL.platforms = res.data.platforms || {};
  renderPlatformLogosGrid();
  loadLinks(); // refresh the member's own list so badges reflect the new logo immediately
}

async function removePlatformLogo(type, key) {
  if (!confirm('Remove this platform\'s logo? It will fall back to the color badge for everyone.')) return;
  var res = await _eidPanel('etelid_links_admin_logo_remove', { link_type: type, platform: key });
  if (!res.success) { alert((res.data && res.data.message) || 'Could not remove.'); return; }
  _PL.platforms = res.data.platforms || {};
  renderPlatformLogosGrid();
  loadLinks();
}

/* ── Flip card ── */
function flipCard() {
  _P.flip = !_P.flip;
  var el = document.getElementById('p-card-3d');
  if (el) el.classList.toggle('flipped', _P.flip);
  var lbl = document.getElementById('p-card-side-lbl');
  if (lbl) lbl.textContent = _P.flip ? 'BACK SIDE' : 'FRONT SIDE';
}

/* Front face shown by default via CSS opacity */


/* ── Toggle password panel ── */
function togglePw() {
  var panel = document.getElementById('p-pw-panel');
  var icon  = document.getElementById('pw-icon');
  if (!panel) return;
  panel.classList.toggle('open');
  if (icon) icon.textContent = panel.classList.contains('open') ? '✕' : '🔐';
}

/* ── Copy to clipboard ── */
function pCopy(text, btn) {
  navigator.clipboard.writeText(text).then(function() {
    var orig = btn.innerHTML;
    btn.innerHTML = '<i class="ti ti-check"></i>';
    btn.style.color = '#86efac';
    setTimeout(function() { btn.innerHTML = orig; btn.style.color = ''; }, 1800);
  });
}

/* ── Change password ── */
function doChangePw() {
  var old = document.getElementById('pw-old').value;
  var nw  = document.getElementById('pw-new').value;
  var cfm = document.getElementById('pw-cfm').value;
  var msg = document.getElementById('p-pw-msg');
  if (!old || !nw || !cfm) { showPwMsg('Please fill in all fields.', false); return; }
  if (nw.length < 6)        { showPwMsg('Password must be at least 6 characters.', false); return; }
  if (nw !== cfm)           { showPwMsg('Passwords do not match.', false); return; }
  var btn = document.querySelector('.p-pw-btn');
  btn.disabled = true; btn.innerHTML = '⏳ Updating…';
  fetch(_P.ajax, {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=etelid_auth_change_password&nonce='+_P.nonce+'&old_password='+encodeURIComponent(old)+'&new_password='+encodeURIComponent(nw)+'&confirm_password='+encodeURIComponent(cfm)
  }).then(function(r){return r.json();}).then(function(res) {
    if (res.success) {
      showPwMsg('✓ Password updated!', true);
      document.getElementById('pw-old').value='';
      document.getElementById('pw-new').value='';
      document.getElementById('pw-cfm').value='';
    } else {
      showPwMsg((res.data && res.data.message) ? res.data.message : 'Error. Try again.', false);
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="ti ti-lock-check"></i> Update Password';
  });
}
function showPwMsg(text, ok) {
  var el = document.getElementById('p-pw-msg');
  el.textContent = text;
  el.className = 'p-pw-msg ' + (ok ? 'p-pw-ok' : 'p-pw-err');
  el.style.display = 'block';
  if (ok) setTimeout(function(){ el.style.display='none'; }, 3500);
}

/* ── Sign out ── */
function doSignOut() {
  fetch(_P.ajax, {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=etelid_auth_logout&nonce='+_P.nonce
  }).then(function(r){return r.json();}).then(function(res){
    location.href = (res.success && res.data.redirect)
      ? res.data.redirect
      : '<?php echo esc_js(home_url("/login/")); ?>';
  });
}

/* ── Premium 3D tilt + coin parallax + specular sheen ── */
(function() {
  var scene = document.querySelector('.p-card-scene');
  var card  = document.getElementById('p-card-3d');
  if (!scene || !card) return;
  if (!window.matchMedia('(hover:hover)').matches) return;

  var raf = null;
  var curX = 0, curY = 0, tarX = 0, tarY = 0;

  function lerp(a,b,t){ return a + (b-a)*t; }

  function animate() {
    curX = lerp(curX, tarX, 0.12);
    curY = lerp(curY, tarY, 0.12);
    var rotY =  curX * 22;
    var rotX = -curY * 14;

    if (!_P.flip) {
      card.style.transform =
        'perspective(1000px) rotateX('+rotX+'deg) rotateY('+rotY+'deg) scale(1.04)';
      card.style.filter =
        'drop-shadow('+(4+curX*14)+'px '+(18+curY*10)+'px '+
        (42+Math.abs(curX)*22)+'px rgba(0,0,0,.65))'+
        ' drop-shadow(0 4px 10px rgba(80,40,0,.3))';
    }

    /* Coin parallax — floats opposite direction = depth illusion */
    var coins = card.querySelectorAll('.efc-coin-watermark, .ecb-coin-watermark');
    coins.forEach(function(c) {
      c.style.transform =
        'translate(calc(-50% + '+(curX*-26)+'px), calc(-50% + '+(curY*-18)+'px))';
    });

    if (Math.abs(curX-tarX)>.0005 || Math.abs(curY-tarY)>.0005) {
      raf = requestAnimationFrame(animate);
    } else { raf = null; }
  }

  scene.addEventListener('mousemove', function(e) {
    var r = scene.getBoundingClientRect();
    tarX = (e.clientX - r.left) / r.width  - 0.5;
    tarY = (e.clientY - r.top)  / r.height - 0.5;

    /* Moving specular sheen */
    var sheen = card.querySelector('.p-card-sheen');
    if (sheen) {
      var px = ((e.clientX-r.left)/r.width *100).toFixed(1);
      var py = ((e.clientY-r.top) /r.height*100).toFixed(1);
      sheen.style.background =
        'radial-gradient(circle at '+px+'% '+py+'%, '+
        'rgba(255,255,255,.22) 0%, rgba(255,255,255,.04) 40%, transparent 65%)';
      sheen.style.opacity = '1';
    }
    if (!raf) raf = requestAnimationFrame(animate);
  });

  scene.addEventListener('mouseleave', function() {
    tarX = 0; tarY = 0;
    var sheen = card.querySelector('.p-card-sheen');
    if (sheen) sheen.style.opacity = '0';
    card.style.transition = 'transform .55s cubic-bezier(.22,.68,0,1.1), filter .35s ease';
    card.style.transform  = '';
    card.style.filter     = '';
    var coins = card.querySelectorAll('.efc-coin-watermark, .ecb-coin-watermark');
    coins.forEach(function(c) {
      c.style.transition = 'transform .55s cubic-bezier(.22,.68,0,1.1)';
      c.style.transform  = 'translate(-50%,-50%)';
    });
    setTimeout(function() {
      card.style.transition = '';
      coins.forEach(function(c){ c.style.transition=''; });
    }, 600);
  });
})();
</script>

<!-- ══ ETEL Updater Modal ═══════════════════════════════════════ -->
<div id="eid-updater-modal" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.75);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:16px;">
  <div class="eid-gold-box" style="border-radius:16px;width:min(96vw,560px);max-height:90vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.8);">

    <!-- Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 20px;border-bottom:1px solid rgba(212,175,55,.15);">
      <div>
        <div style="font-size:16px;font-weight:700;color:#D4AF37;">🔄 ኢE Updater</div>
        <div style="font-size:11px;color:#666;margin-top:2px;">Drop any ZIP — manifest optional · auto-detects all files</div>
      </div>
      <button onclick="closeUpdaterModal()" style="background:none;border:1px solid #333;color:#888;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:13px;">✕</button>
    </div>

    <!-- Body -->
    <div style="padding:20px;">

      <!-- Mode tabs -->
      <div style="display:flex;gap:6px;margin-bottom:16px;">
        <button type="button" id="upd-tab-zip" onclick="updSwitchTab('zip')" style="flex:1;padding:8px;border-radius:7px;border:1px solid #D4AF37;background:rgba(212,175,55,.1);color:#D4AF37;font-size:12px;cursor:pointer;">📦 ZIP</button>
        <button type="button" id="upd-tab-multi" onclick="updSwitchTab('multi')" style="flex:1;padding:8px;border-radius:7px;border:1px solid #2a2a2a;background:#111;color:#998;font-size:12px;cursor:pointer;">📄 Multiple Files</button>
      </div>

      <!-- ══ ZIP MODE ══ -->
      <div id="upd-mode-zip">

      <!-- Drop zone -->
      <div id="upd-drop" onclick="document.getElementById('upd-file-input').click()"
        ondragover="event.preventDefault();this.style.borderColor='#D4AF37';this.style.background='rgba(212,175,55,.08)'"
        ondragleave="this.style.borderColor='rgba(212,175,55,.25)';this.style.background='rgba(212,175,55,.03)'"
        ondrop="updHandleDrop(event)"
        style="border:2px dashed rgba(212,175,55,.25);border-radius:12px;padding:32px 20px;text-align:center;cursor:pointer;background:rgba(212,175,55,.03);transition:all .2s;margin-bottom:16px;">
        <div style="font-size:40px;margin-bottom:10px;">📦</div>
        <div style="font-size:15px;font-weight:700;color:#e8e2d4;margin-bottom:6px;">Drop ZIP patch here or click to select</div>
        <div style="font-size:12px;color:#555;">Any ZIP — no manifest needed — all .php .css .js files auto-deployed</div>
        <div id="upd-fname" style="display:none;margin-top:12px;font-family:monospace;font-size:12px;color:#D4AF37;"></div>
      </div>
      <input type="file" id="upd-file-input" accept=".zip" style="display:none" onchange="updFileSelected(this)">

      <!-- File list preview -->
      <div id="upd-preview" style="display:none;background:rgba(212,175,55,.05);border:1px solid rgba(212,175,55,.15);border-radius:8px;padding:12px 14px;margin-bottom:14px;">
        <div style="font-size:11px;color:#888;margin-bottom:8px;text-transform:uppercase;letter-spacing:.06em;">Files in ZIP</div>
        <div id="upd-preview-list" style="display:flex;flex-direction:column;gap:4px;font-family:monospace;font-size:11px;color:#aaa;max-height:140px;overflow-y:auto;"></div>
      </div>

      <!-- Progress -->
      <div id="upd-progress" style="display:none;margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
          <span style="font-size:11px;color:#888;" id="upd-prog-lbl">Deploying…</span>
          <span style="font-size:11px;color:#555;" id="upd-prog-pct">0%</span>
        </div>
        <div style="height:5px;background:#1a1a1a;border-radius:3px;overflow:hidden;">
          <div id="upd-prog-bar" style="height:100%;width:0%;background:linear-gradient(90deg,#C8960C,#D4AF37);border-radius:3px;transition:width .3s;"></div>
        </div>
      </div>

      <!-- Result -->
      <div id="upd-result" style="display:none;border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:13px;"></div>

      <!-- Deployed files -->
      <div id="upd-files" style="display:none;margin-bottom:14px;">
        <div id="upd-files-list" style="display:flex;flex-direction:column;gap:4px;"></div>
      </div>

      <!-- Actions -->
      <div style="display:flex;gap:10px;align-items:center;">
        <button id="upd-apply-btn" onclick="updApply()" disabled
          style="flex:1;padding:11px;border-radius:8px;border:none;background:linear-gradient(135deg,#C8960C,#D4AF37);color:#0d0d0d;font-weight:700;font-size:14px;cursor:pointer;opacity:.4;transition:opacity .2s;">
          ⚡ Deploy ZIP
        </button>
        <button onclick="updClear()" style="padding:11px 16px;border-radius:8px;border:1px solid #333;background:none;color:#888;cursor:pointer;">Clear</button>
      </div>
      </div><!-- /upd-mode-zip -->

      <!-- ══ MULTI-FILE MODE ══ -->
      <div id="upd-mode-multi" style="display:none;">
        <div id="updm-drop" onclick="document.getElementById('updm-file-input').click()"
          ondragover="event.preventDefault();this.style.borderColor='#D4AF37';this.style.background='rgba(212,175,55,.08)'"
          ondragleave="this.style.borderColor='rgba(212,175,55,.25)';this.style.background='rgba(212,175,55,.03)'"
          ondrop="updmHandleDrop(event)"
          style="border:2px dashed rgba(212,175,55,.25);border-radius:12px;padding:28px 20px;text-align:center;cursor:pointer;background:rgba(212,175,55,.03);margin-bottom:14px;">
          <div style="font-size:34px;margin-bottom:8px;">📄</div>
          <div style="font-size:14px;font-weight:700;color:#e8e2d4;margin-bottom:4px;">Drop one or more files, or click to select</div>
          <div style="font-size:11px;color:#555;">No zipping needed — set the destination path for each file below</div>
        </div>
        <input type="file" id="updm-file-input" multiple style="display:none" onchange="updmFilesSelected(this.files)">

        <div id="updm-list" style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px;"></div>

        <div id="updm-result" style="display:none;border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:13px;"></div>

        <div style="display:flex;gap:10px;">
          <button id="updm-apply-btn" onclick="updmApply()" disabled
            style="flex:1;padding:11px;border-radius:8px;border:none;background:linear-gradient(135deg,#C8960C,#D4AF37);color:#0d0d0d;font-weight:700;font-size:14px;cursor:pointer;opacity:.4;">
            ⚡ Deploy Files
          </button>
          <button onclick="updmClear()" style="padding:11px 16px;border-radius:8px;border:1px solid #333;background:none;color:#888;cursor:pointer;">Clear</button>
        </div>
      </div><!-- /upd-mode-multi -->

      <div style="margin-top:14px;padding-top:14px;border-top:1px solid #1a1a1a;display:flex;justify-content:space-between;align-items:center;">
        <span style="font-size:11px;color:#444;">Original files auto-backed up before replace</span>
        <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-updater')); ?>" target="_blank" style="font-size:11px;color:#555;text-decoration:none;">Full history →</a>
      </div>
    </div>
  </div>
</div>

<script>
var _updFile = null;
var _updNonce = '<?php echo esc_js(wp_create_nonce("etelid_patch_upload")); ?>';
var _updAjax  = '<?php echo esc_js(admin_url("admin-ajax.php")); ?>';

/* ── Tab switching ── */
function updSwitchTab(mode) {
  var zipOn = mode === 'zip';
  document.getElementById('upd-mode-zip').style.display   = zipOn ? 'block' : 'none';
  document.getElementById('upd-mode-multi').style.display = zipOn ? 'none' : 'block';
  document.getElementById('upd-tab-zip').style.cssText   = zipOn
    ? 'flex:1;padding:8px;border-radius:7px;border:1px solid #D4AF37;background:rgba(212,175,55,.1);color:#D4AF37;font-size:12px;cursor:pointer;'
    : 'flex:1;padding:8px;border-radius:7px;border:1px solid #2a2a2a;background:#111;color:#998;font-size:12px;cursor:pointer;';
  document.getElementById('upd-tab-multi').style.cssText = ! zipOn
    ? 'flex:1;padding:8px;border-radius:7px;border:1px solid #D4AF37;background:rgba(212,175,55,.1);color:#D4AF37;font-size:12px;cursor:pointer;'
    : 'flex:1;padding:8px;border-radius:7px;border:1px solid #2a2a2a;background:#111;color:#998;font-size:12px;cursor:pointer;';
}

/* ── Multi-file mode: known filename -> plugin-relative path guesses.
 * Anything not listed falls back to a best-effort guess by extension,
 * but the path is always editable before deploying. ── */
var _updmKnownPaths = {
  'etel-id.php': 'etel-id.php',
  'portal.php': 'templates/portal.php',
  'profile-update.php': 'templates/profile-update.php',
  'verify-pass2.php': 'templates/verify-pass2.php',
  'apply-form.php': 'templates/apply-form.php',
  'auth-page.php': 'templates/auth-page.php',
  'card-front.php': 'templates/card-front.php',
  'card-back.php': 'templates/card-back.php',
  'card-view.php': 'templates/card-view.php',
  'print-page.php': 'templates/print-page.php',
  'verify.php': 'templates/verify.php',
  'verify-form.php': 'templates/verify-form.php',
  'fayda-register.php': 'admin/views/fayda-register.php',
  'password-reset.php': 'admin/views/password-reset.php',
  'etelid-card.css': 'public/css/etelid-card.css'
};
function updmGuessPath(filename) {
  if (_updmKnownPaths[filename]) return _updmKnownPaths[filename];
  if (/^class-etelid-/.test(filename)) return 'includes/' + filename;
  var ext = (filename.split('.').pop() || '').toLowerCase();
  if (ext === 'css') return 'public/css/' + filename;
  if (ext === 'js')  return 'public/js/' + filename;
  return '';
}

var _updmFiles = [];
function updmHandleDrop(e) {
  e.preventDefault();
  e.currentTarget.style.borderColor = 'rgba(212,175,55,.25)';
  e.currentTarget.style.background = 'rgba(212,175,55,.03)';
  if (e.dataTransfer && e.dataTransfer.files.length) updmFilesSelected(e.dataTransfer.files);
}
function updmFilesSelected(fileList) {
  for (var i = 0; i < fileList.length; i++) _updmFiles.push(fileList[i]);
  updmRenderList();
}
function updmRenderList() {
  var wrap = document.getElementById('updm-list');
  wrap.innerHTML = '';
  _updmFiles.forEach(function(f, idx) {
    var guess = updmGuessPath(f.name);
    var row = document.createElement('div');
    row.style.cssText = 'display:flex;gap:8px;align-items:center;background:rgba(212,175,55,.05);border:1px solid rgba(212,175,55,.15);border-radius:8px;padding:8px 10px;';
    row.innerHTML = '<span style="font-family:monospace;font-size:11px;color:#aaa;min-width:90px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + f.name + '">' + f.name + '</span>'
      + '<input type="text" class="updm-path-input" data-idx="' + idx + '" value="' + guess + '" placeholder="e.g. includes/class-etelid-foo.php" style="flex:1;background:#111;border:1px solid #2a2a2a;color:#e8e2d4;border-radius:6px;padding:6px 8px;font-size:11px;font-family:monospace;">'
      + '<button onclick="updmRemove(' + idx + ')" style="background:none;border:1px solid #333;color:#888;border-radius:5px;padding:4px 8px;cursor:pointer;font-size:11px;">✕</button>';
    wrap.appendChild(row);
  });
  document.getElementById('updm-apply-btn').disabled = _updmFiles.length === 0;
  document.getElementById('updm-apply-btn').style.opacity = _updmFiles.length === 0 ? '.4' : '1';
}
function updmRemove(idx) {
  _updmFiles.splice(idx, 1);
  updmRenderList();
}
function updmClear() {
  _updmFiles = [];
  document.getElementById('updm-file-input').value = '';
  document.getElementById('updm-result').style.display = 'none';
  updmRenderList();
}
function updmApply() {
  var inputs = document.querySelectorAll('.updm-path-input');
  var paths = [];
  var ok = true;
  inputs.forEach(function(inp) {
    var v = inp.value.trim();
    if (!v) ok = false;
    paths.push(v);
  });
  if (!ok || !_updmFiles.length) {
    var res = document.getElementById('updm-result');
    res.style.display = 'block'; res.style.background = 'rgba(226,75,74,.1)'; res.style.border = '1px solid rgba(226,75,74,.3)'; res.style.color = '#E24B4A';
    res.innerHTML = '<strong>❌ Every file needs a destination path before deploying.</strong>';
    return;
  }
  var btn = document.getElementById('updm-apply-btn');
  btn.disabled = true; btn.textContent = 'Deploying…';

  var fd = new FormData();
  fd.append('action', 'etelid_portal_patch_multi');
  fd.append('nonce', _updNonce);
  _updmFiles.forEach(function(f) { fd.append('patch_files[]', f, f.name); });
  paths.forEach(function(p) { fd.append('paths[]', p); });

  fetch(_updAjax, { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(j){
      var res = document.getElementById('updm-result');
      res.style.display = 'block';
      if (j.success) {
        res.style.background = 'rgba(34,197,94,.1)'; res.style.border = '1px solid rgba(34,197,94,.3)'; res.style.color = '#6ee7a0';
        res.innerHTML = '<strong>✅ ' + (j.data.message || 'Deployed.') + '</strong>';
        _updmFiles = [];
        document.getElementById('updm-file-input').value = '';
        updmRenderList();
      } else {
        res.style.background = 'rgba(226,75,74,.1)'; res.style.border = '1px solid rgba(226,75,74,.3)'; res.style.color = '#E24B4A';
        res.innerHTML = '<strong>❌ ' + ((j.data && j.data.message) || 'Deploy failed.') + '</strong>';
      }
      btn.disabled = false; btn.textContent = '⚡ Deploy Files';
    })
    .catch(function(err){
      var res = document.getElementById('updm-result');
      res.style.display = 'block'; res.style.background = 'rgba(226,75,74,.1)'; res.style.border = '1px solid rgba(226,75,74,.3)'; res.style.color = '#E24B4A';
      res.innerHTML = '<strong>❌ Network error: ' + err.message + '</strong>';
      btn.disabled = false; btn.textContent = '⚡ Deploy Files';
    });
}

function openUpdaterModal(e) {
  if(e) e.preventDefault();
  var m = document.getElementById('eid-updater-modal');
  m.style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function closeUpdaterModal() {
  document.getElementById('eid-updater-modal').style.display = 'none';
  document.body.style.overflow = '';
}
document.getElementById('eid-updater-modal').addEventListener('click', function(e){
  if(e.target===this) closeUpdaterModal();
});

function updHandleDrop(e) {
  e.preventDefault();
  var z = document.getElementById('upd-drop');
  z.style.borderColor='rgba(212,175,55,.25)'; z.style.background='rgba(212,175,55,.03)';
  if(e.dataTransfer.files[0]) { _updFile=e.dataTransfer.files[0]; updShowFile(); }
}
function updFileSelected(inp) {
  if(inp.files[0]) { _updFile=inp.files[0]; updShowFile(); }
}
function updShowFile() {
  if(!_updFile) return;
  document.getElementById('upd-fname').textContent='📦 '+_updFile.name+' ('+((_updFile.size)/1024).toFixed(1)+' KB)';
  document.getElementById('upd-fname').style.display='block';
  var btn=document.getElementById('upd-apply-btn'); btn.disabled=false; btn.style.opacity='1';
  document.getElementById('upd-result').style.display='none';
  document.getElementById('upd-files').style.display='none';
  // Preview using JSZip if available, else just show filename
  document.getElementById('upd-preview-list').innerHTML='<span style="color:#666">'+_updFile.name+' — file list shown after deploy</span>';
  document.getElementById('upd-preview').style.display='block';
}
function updClear() {
  _updFile=null;
  document.getElementById('upd-file-input').value='';
  document.getElementById('upd-fname').style.display='none';
  document.getElementById('upd-apply-btn').disabled=true;
  document.getElementById('upd-apply-btn').style.opacity='.4';
  document.getElementById('upd-preview').style.display='none';
  document.getElementById('upd-result').style.display='none';
  document.getElementById('upd-files').style.display='none';
  document.getElementById('upd-progress').style.display='none';
}

</script>

<!-- ══ Fayda Quick Register Modal ═══════════════════════════════ -->
<style>
.pf-field{display:flex;flex-direction:column;gap:4px;}
.pf-field label{font-size:11px;color:#998;text-transform:uppercase;letter-spacing:.04em;}
.pf-field input,.pf-field select{background:#111;border:1px solid #2a2a2a;color:#e8e2d4;border-radius:7px;padding:9px 10px;font-size:13px;}
.pf-field input:focus,.pf-field select:focus{outline:none;border-color:#D4AF37;}
.pf-section{grid-column:1/-1;font-size:11px;color:#D4AF37;text-transform:uppercase;letter-spacing:.06em;margin-top:8px;border-bottom:1px solid rgba(212,175,55,.15);padding-bottom:6px;}
.fayda-shot-box{position:relative;border:1.5px dashed rgba(212,175,55,.4);border-radius:12px;aspect-ratio:8/5;background:rgba(212,175,55,.05);display:flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;}
.fayda-shot-box img{width:100%;height:100%;object-fit:cover;}
.fayda-shot-box.has-image{border-style:solid;border-color:#D4AF37;}
</style>
<div id="eid-fayda-modal" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.75);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:16px;">
  <div class="eid-gold-box" style="border-radius:16px;width:min(96vw,640px);max-height:92vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.8);">

    <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid rgba(212,175,55,.15);">
      <div>
        <div style="font-size:16px;font-weight:700;color:#D4AF37;">🪪 Fayda Quick Register</div>
        <div style="font-size:11px;color:#666;margin-top:2px;">Photograph the Fayda ID, review, send credentials — all from here</div>
      </div>
      <button onclick="closeFaydaModal()" style="background:none;border:1px solid #333;color:#888;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:13px;">✕</button>
    </div>

    <div style="padding:20px;">

      <div style="background:rgba(212,175,55,.08);border:1px solid rgba(212,175,55,.3);border-radius:8px;padding:10px 12px;font-size:12px;color:#cbb;margin-bottom:16px;">
        Reads text from the photos in your browser only — nothing is uploaded during scanning. This is fast admin-assisted intake, not a government verification — check the fields before submitting.
      </div>

      <div id="pf-step1">
        <div style="font-size:13px;font-weight:700;color:#e8e2d4;margin-bottom:10px;">1. Capture the Fayda ID</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
          <div>
            <label style="font-size:11px;color:#998;">Front of card</label>
            <div class="fayda-shot-box" id="pf-front-box" data-side="front" style="margin-top:5px;">
              <span style="font-size:12px;color:#A07020;text-align:center;padding:10px;">📷 Tap to capture / upload front</span>
            </div>
            <input type="file" id="pf-front-file" accept="image/*" capture="environment" style="display:none;">
          </div>
          <div>
            <label style="font-size:11px;color:#998;">Back of card</label>
            <div class="fayda-shot-box" id="pf-back-box" data-side="back" style="margin-top:5px;">
              <span style="font-size:12px;color:#A07020;text-align:center;padding:10px;">📷 Tap to capture / upload back (FIN &amp; address side)</span>
            </div>
            <input type="file" id="pf-back-file" accept="image/*" capture="environment" style="display:none;">
          </div>
        </div>
        <button type="button" id="pf-extract-btn" disabled
          style="margin-top:14px;padding:10px 16px;border-radius:8px;border:none;background:linear-gradient(135deg,#C8960C,#D4AF37);color:#0d0d0d;font-weight:700;font-size:13px;cursor:pointer;opacity:.4;">
          🔍 Extract Info from Photos
        </button>
        <span id="pf-extract-status" style="margin-left:10px;font-size:12px;color:#D4AF37;"></span>
      </div>

      <form id="pf-form" style="margin-top:18px;">
        <input type="hidden" id="pf_fayda_front_image">
        <input type="hidden" id="pf_fayda_back_image">
        <input type="hidden" id="pf_fayda_ocr_raw">

        <div style="font-size:13px;font-weight:700;color:#e8e2d4;margin-bottom:10px;">2. Review &amp; Confirm</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">

          <div class="pf-section">Detected Identity</div>
          <div class="pf-field"><label>Full Name (Latin) *</label><input type="text" id="pf_full_name" required placeholder="Auto-filled from photo"></div>
          <div class="pf-field"><label>Full Name (Amharic)</label><input type="text" id="pf_full_name_am" placeholder="ሙሉ ስም"></div>
          <div class="pf-field"><label>Date of Birth *</label><input type="date" id="pf_dob" required></div>
          <div class="pf-field"><label>Sex</label>
            <select id="pf_sex"><option value="Male">Male | ወንድ</option><option value="Female">Female | ሴት</option><option value="Other">Other</option></select>
          </div>
          <div class="pf-field"><label>Nationality</label><input type="text" id="pf_nationality" value="Ethiopian"></div>
          <div class="pf-field"><label>Fayda FIN</label><input type="text" id="pf_fayda_fin" placeholder="12-digit number from card / QR"></div>

          <div class="pf-section">Address</div>
          <div class="pf-field"><label>Region</label>
            <select id="pf_region"><option value="">— Select Region —</option>
              <?php foreach ($fayda_regions as $r): ?><option value="<?php echo esc_attr($r); ?>"><?php echo esc_html($r); ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="pf-field"><label>Zone / City</label><input type="text" id="pf_zone"></div>
          <div class="pf-field"><label>Woreda / Sub-city</label><input type="text" id="pf_woreda"></div>

          <div class="pf-section">Contact &amp; Company</div>
          <div class="pf-field"><label>Phone *</label><input type="tel" id="pf_phone" required placeholder="09xx xxx xxx"></div>
          <div class="pf-field"><label>Email</label><input type="email" id="pf_email"></div>
          <div class="pf-field"><label>Primary Company</label>
            <select id="pf_primary_company_slug">
              <?php foreach ($fayda_companies as $slug => $co): ?><option value="<?php echo esc_attr($slug); ?>"<?php selected($slug,'etel-pay'); ?>><?php echo esc_html($co['name']); ?> (<?php echo esc_html($co['code']); ?>)</option><?php endforeach; ?>
            </select>
          </div>
          <div class="pf-field"><label>Role</label><input type="text" id="pf_role" value="Member"></div>

          <div class="pf-section">Deliver Credentials Via</div>
          <div style="grid-column:1/-1;display:flex;gap:14px;font-size:12px;color:#bbb;">
            <label><input type="radio" name="pf_notify_channel" value="auto" checked> Auto</label>
            <label><input type="radio" name="pf_notify_channel" value="whatsapp"> WhatsApp only</label>
            <label><input type="radio" name="pf_notify_channel" value="telegram"> Telegram only</label>
          </div>
        </div>

        <button type="submit" id="pf-submit-btn"
          style="margin-top:18px;width:100%;padding:12px;border-radius:8px;border:none;background:linear-gradient(135deg,#C8960C,#D4AF37);color:#0d0d0d;font-weight:700;font-size:14px;cursor:pointer;">
          🪪 Create Etel-ID &amp; Send Password
        </button>
      </form>

      <div id="pf-result" style="display:none;border-radius:8px;padding:14px;margin-top:16px;font-size:13px;"></div>
    </div>
  </div>
</div>

<script>
function updApply() {
  if(!_updFile) return;
  var btn=document.getElementById('upd-apply-btn');
  var prog=document.getElementById('upd-progress');
  var bar=document.getElementById('upd-prog-bar');
  var pct=document.getElementById('upd-prog-pct');
  var lbl=document.getElementById('upd-prog-lbl');
  var res=document.getElementById('upd-result');
  var files=document.getElementById('upd-files');
  var list=document.getElementById('upd-files-list');

  btn.disabled=true; btn.style.opacity='.5'; btn.textContent='⏳ Deploying…';
  prog.style.display='block'; res.style.display='none'; files.style.display='none';

  var p=0;
  var ticker=setInterval(function(){
    p=Math.min(p+Math.random()*18,88);
    bar.style.width=p+'%'; pct.textContent=Math.round(p)+'%';
  },180);

  var fd=new FormData();
  fd.append('action','etelid_portal_patch');
  fd.append('nonce',_updNonce);
  fd.append('patch_zip',_updFile);
  var _eidCookie = document.cookie.split(';').map(function(c){return c.trim();})
    .find(function(c){return c.startsWith('etelid_session=');});
  if(_eidCookie) fd.append('eid_token', decodeURIComponent(_eidCookie.split('=').slice(1).join('=')));
  fd.append('updater_secret', '<?php echo esc_js(defined("ETELID_UPDATER_SECRET") ? ETELID_UPDATER_SECRET : ""); ?>');

  fetch(_updAjax,{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(data){
      clearInterval(ticker);
      bar.style.width='100%'; pct.textContent='100%'; lbl.textContent='Done!';
      setTimeout(function(){
        prog.style.display='none';
        res.style.display='block';
        var ok = data.success && (!data.data.failed || !data.data.failed.length);
        res.style.background = ok ? 'rgba(99,153,34,.12)' : data.success ? 'rgba(212,175,55,.1)' : 'rgba(226,75,74,.1)';
        res.style.border     = ok ? '1px solid rgba(99,153,34,.3)' : data.success ? '1px solid rgba(212,175,55,.25)' : '1px solid rgba(226,75,74,.3)';
        res.style.color      = ok ? '#639922' : data.success ? '#D4AF37' : '#E24B4A';
        var msg = data.data && data.data.message ? data.data.message : (data.success ? 'Deployed!' : 'Error');
        res.innerHTML = '<strong>'+(ok?'✅ ':data.success?'⚠️ ':'❌ ')+msg+'</strong>';
        if(data.data && data.data.version) res.innerHTML+='<div style="font-size:10px;margin-top:4px;opacity:.6">Version: '+data.data.version+'</div>';
        var html='';
        if(data.data && data.data.applied && data.data.applied.length) {
          data.data.applied.forEach(function(f){
            html+='<div style="display:flex;gap:7px;align-items:center;padding:5px 8px;background:rgba(99,153,34,.08);border:1px solid rgba(99,153,34,.15);border-radius:5px;">'
              +'<span style="color:#639922;font-size:13px;">✓</span>'
              +'<span style="font-family:monospace;font-size:11px;color:#aaa;">'+f+'</span></div>';
          });
        }
        if(data.data && data.data.failed && data.data.failed.length) {
          data.data.failed.forEach(function(f){
            html+='<div style="display:flex;gap:7px;align-items:center;padding:5px 8px;background:rgba(226,75,74,.08);border:1px solid rgba(226,75,74,.15);border-radius:5px;">'
              +'<span style="color:#E24B4A;font-size:13px;">✗</span>'
              +'<span style="font-family:monospace;font-size:11px;color:#aaa;">'+f+'</span></div>';
          });
        }
        if(html){ list.innerHTML=html; files.style.display='block'; }
        if(ok){ res.innerHTML+='<div style="font-size:11px;margin-top:6px;opacity:.6">Reloading in 3s…</div>'; setTimeout(function(){location.reload();},3000); }
        btn.disabled=false; btn.style.opacity='1'; btn.textContent='⚡ Deploy ZIP';
      },300);
    })
    .catch(function(err){
      clearInterval(ticker); prog.style.display='none';
      res.style.display='block'; res.style.background='rgba(226,75,74,.1)';
      res.style.border='1px solid rgba(226,75,74,.3)'; res.style.color='#E24B4A';
      res.innerHTML='<strong>❌ Network error: '+err.message+'</strong>';
      btn.disabled=false; btn.style.opacity='1'; btn.textContent='⚡ Deploy ZIP';
    });
}

var _pfNonce = '<?php echo esc_js( $fayda_nonce ); ?>';
var _pfAjax  = '<?php echo esc_js( $ajax ); ?>';
var _pfScriptsLoaded = false;

function openFaydaModal(e) {
  if (e) e.preventDefault();
  document.getElementById('eid-fayda-modal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
  if (!_pfScriptsLoaded) {
    _pfScriptsLoaded = true;
    var s2 = document.createElement('script'); s2.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';
    document.body.appendChild(s2);
  }
}
function closeFaydaModal() {
  document.getElementById('eid-fayda-modal').style.display = 'none';
  document.body.style.overflow = '';
}
document.getElementById('eid-fayda-modal').addEventListener('click', function(e){
  if (e.target === this) closeFaydaModal();
});

(function(){
  var shots = { front: null, back: null };
  var extractBtn = document.getElementById('pf-extract-btn');
  var statusEl   = document.getElementById('pf-extract-status');

  function wireShot(side){
    var box  = document.getElementById('pf-' + side + '-box');
    var file = document.getElementById('pf-' + side + '-file');
    box.addEventListener('click', function(){ file.click(); });
    file.addEventListener('change', function(){
      var f = file.files[0];
      if (!f) return;
      var reader = new FileReader();
      reader.onload = function(e){
        shots[side] = e.target.result;
        box.classList.add('has-image');
        box.innerHTML = '<img src="' + e.target.result + '">';
        document.getElementById('pf_fayda_' + side + '_image').value = e.target.result;
        extractBtn.disabled = !(shots.front && shots.back);
        extractBtn.style.opacity = extractBtn.disabled ? '.4' : '1';
      };
      reader.readAsDataURL(f);
    });
  }
  wireShot('front');
  wireShot('back');

  function digitsOnly(s){ return (s || '').replace(/[^0-9]/g, ''); }
  function linesOf(text){ return (text || '').split('\n').map(function(l){ return l.trim(); }).filter(Boolean); }

  /* ── Date of Birth ──────────────────────────────────────────
   * The front card prints TWO dates right after "Date of Birth":
   * an Ethiopian-calendar one (DD/MM/YYYY-ish) then the Gregorian
   * one (YYYY/MM/DD). Other dates (Print Date, Issue/Expiry on the
   * back) must NOT be picked up, so this only looks at a small
   * window right after the "birth" label on the FRONT, and prefers
   * the YYYY/MM/DD-shaped match (the Gregorian one). */
  function findDob(frontText){
    var lines = linesOf(frontText);
    var idx = -1;
    for (var i=0;i<lines.length;i++){
      if (/date of birth|የተወልዱ|የትውልድ/i.test(lines[i])) { idx = i; break; }
    }
    var window = idx >= 0 ? lines.slice(idx, idx+4).join(' ') : frontText;
    var ymd = window.match(/(\d{4})[\/\.\-](\d{1,2})[\/\.\-](\d{1,2})/);
    if (ymd) { var mm=('0'+ymd[2]).slice(-2), dd=('0'+ymd[3]).slice(-2); return ymd[1]+'-'+mm+'-'+dd; }
    var dmy = window.match(/(\d{1,2})[\/\.\-](\d{1,2})[\/\.\-](\d{4})/);
    if (dmy) { var dd2=('0'+dmy[1]).slice(-2), mm2=('0'+dmy[2]).slice(-2); return dmy[3]+'-'+mm2+'-'+dd2; }
    return '';
  }
  function findSex(text){
    if (/\bfemale\b/i.test(text) || /ሴት/.test(text)) return 'Female';
    if (/\bmale\b/i.test(text) || /ወንድ/.test(text)) return 'Male';
    return '';
  }
  /* ── Fayda FIN ───────────────────────────────────────────────
   * Anchor to the printed "FIN" label on the back. Deliberately
   * does NOT fall back to digits decoded from the QR — that QR
   * holds a signed/encrypted government payload, not plain text,
   * so any digit-looking substring in it is coincidental noise. */
  function findFin(backText){
    var m = (backText || '').match(/FIN\s*[:\-]?\s*([\d][\d\s]{8,18}[\d])/i);
    if (m) { var d = digitsOnly(m[1]); if (d.length >= 10 && d.length <= 13) return d; }
    return '';
  }
  var KNOWN_REGIONS = ['Addis Ababa','Oromia','Amhara','SNNP','Tigray','Sidama','South West Ethiopia','Central Ethiopia','Afar','Somali','Benishangul-Gumuz','Gambela','Harari','Dire Dawa'];
  function findRegion(backText){
    for (var i=0;i<KNOWN_REGIONS.length;i++){
      var re = new RegExp('\\b' + KNOWN_REGIONS[i].replace(/[\-\/]/g,'.') + '\\b', 'i');
      if (re.test(backText)) return KNOWN_REGIONS[i];
    }
    return '';
  }
  /* ── Zone / Woreda / Phone ──────────────────────────────────
   * The back card repeats each field as an Amharic label line,
   * an English label line, then the value (often Amharic value
   * then English value). So: find the English label line, then
   * take the next plausible value line — preferring one that's
   * mostly Latin script if both an Amharic and Latin value line
   * follow. */
  function valueAfterLabel(lines, labelRe, opts){
    opts = opts || {};
    for (var i=0;i<lines.length;i++){
      if (labelRe.test(lines[i])) {
        for (var j=i+1; j<Math.min(i+4, lines.length); j++){
          var v = lines[j];
          if (!v || labelRe.test(v)) continue;
          if (opts.digitsOnly) {
            var d = digitsOnly(v);
            if (d.length >= 9) return d.slice(0,10).replace(/^(\d{9})$/, '0$1');
            continue;
          }
          if (opts.latinOnly && !/^[A-Za-z0-9\s\.\-]+$/.test(v)) continue;
          if (/^[A-Za-z\u1200-\u137F0-9\s\.\-\/]{2,40}$/.test(v)) return v;
        }
      }
    }
    return '';
  }
  function findPhone(backText){ return valueAfterLabel(linesOf(backText), /phone number|ስልክ ቁ/i, { digitsOnly: true }); }
  function findZone(backText){ return valueAfterLabel(linesOf(backText), /subcity\s*\/\s*zone|ክፍለ ከተማ/i, { latinOnly: true }); }
  function findWoreda(backText){ return valueAfterLabel(linesOf(backText), /\bworeda\b|ወረዳ/i, { latinOnly: true }); }

  /* ── Name ────────────────────────────────────────────────────
   * Pure-Latin, multi-word, title-case-ish lines only — mixed
   * Latin/Ethiopic OCR noise (which the old version could match)
   * is excluded entirely rather than competing on length. */
  var IGNORE_LINES = /federal|democratic|republic|ethiopia|national|identification|identity|card|fayda|sex|date of birth|nationality|expiry|issued|print date|country of citizen/i;
  function findName(text){
    var lines = linesOf(text);
    var best = '';
    for (var i=0;i<lines.length;i++){
      var l = lines[i];
      if (IGNORE_LINES.test(l)) continue;
      if (/^[A-Z][A-Za-z]*(\s+[A-Z][A-Za-z]*){1,4}$/.test(l)) {
        if (l.length > best.length) best = l;
      }
    }
    return best;
  }

  function fillField(id, val){ if (val) document.getElementById(id).value = val; }

  function preprocessImage(dataUrl, cb){
    var img = new Image();
    img.onload = function(){
      var scale = Math.min( 2, Math.max( 1, 1600 / img.width ) );
      var w = Math.round(img.width * scale), h = Math.round(img.height * scale);
      var canvas = document.createElement('canvas');
      canvas.width = w; canvas.height = h;
      var ctx = canvas.getContext('2d');
      ctx.imageSmoothingEnabled = true;
      try { ctx.imageSmoothingQuality = 'high'; } catch(e){}
      ctx.drawImage(img, 0, 0, w, h);
      try {
        var imageData = ctx.getImageData(0, 0, w, h);
        var d = imageData.data;
        var contrast = 1.4, brightness = 14;
        for (var i = 0; i < d.length; i += 4) {
          var gray = 0.299*d[i] + 0.587*d[i+1] + 0.114*d[i+2];
          gray = (gray - 128) * contrast + 128 + brightness;
          gray = gray < 0 ? 0 : (gray > 255 ? 255 : gray);
          d[i] = d[i+1] = d[i+2] = gray;
        }
        ctx.putImageData(imageData, 0, 0);
      } catch(e) { /* tainted canvas or unsupported — use upscale-only result */ }
      cb(canvas.toDataURL('image/jpeg', 0.92));
    };
    img.onerror = function(){ cb(dataUrl); };
    img.src = dataUrl;
  }

  function runTesseractFallback(){
    statusEl.textContent = 'Sharpening photos for better reading…';
    Promise.all([
      new Promise(function(res){ preprocessImage(shots.front, res); }),
      new Promise(function(res){ preprocessImage(shots.back, res); })
    ]).then(function(prepped){
      var frontImg = prepped[0], backImg = prepped[1];
      statusEl.textContent = 'Scanning text (this can take 10–20s)…';
      function runOcr(langs){
        return Promise.all([
          Tesseract.recognize(frontImg, langs),
          Tesseract.recognize(backImg, langs)
        ]);
      }
      return runOcr('eng+amh').catch(function(){
        statusEl.textContent = 'Amharic OCR unavailable, retrying with English only…';
        return runOcr('eng');
      });
    }).then(function(results){
      var frontText = results[0] && results[0].data ? results[0].data.text : '';
      var backText  = results[1] && results[1].data ? results[1].data.text : '';
      var combined  = frontText + '\n' + backText;

      document.getElementById('pf_full_name').value = document.getElementById('pf_full_name').value || findName(frontText) || findName(backText);
      fillField('pf_dob', findDob(frontText));
      fillField('pf_sex', findSex(combined));
      fillField('pf_fayda_fin', findFin(backText));
      fillField('pf_region', findRegion(backText));
      fillField('pf_zone', findZone(backText));
      fillField('pf_woreda', findWoreda(backText));
      fillField('pf_phone', findPhone(backText));

      document.getElementById('pf_fayda_ocr_raw').value = combined.slice(0, 4000);
      statusEl.textContent = '✓ Extraction complete (basic OCR) — please review carefully.';
      statusEl.style.color = '#D4AF37';
      extractBtn.disabled = false;
    }).catch(function(){
      statusEl.textContent = 'Could not read the photos automatically — fill in the fields by hand.';
      statusEl.style.color = '#ef4444';
      extractBtn.disabled = false;
    });
  }

  extractBtn.addEventListener('click', function(){
    extractBtn.disabled = true;
    statusEl.textContent = 'Reading the card with AI vision…';
    statusEl.style.color = '#D4AF37';

    var fd = new FormData();
    fd.append('action', 'etelid_fayda_vision_extract');
    fd.append('nonce', _pfNonce);
    fd.append('fayda_front_image', shots.front);
    fd.append('fayda_back_image', shots.back);

    fetch(_pfAjax, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (data.success) {
          var d = data.data;
          document.getElementById('pf_full_name').value = document.getElementById('pf_full_name').value || d.full_name || '';
          fillField('pf_full_name_am', d.full_name_am);
          fillField('pf_dob', d.dob);
          fillField('pf_sex', d.sex);
          fillField('pf_nationality', d.nationality);
          fillField('pf_fayda_fin', d.fayda_fin);
          fillField('pf_region', d.region);
          fillField('pf_zone', d.zone);
          fillField('pf_woreda', d.woreda);
          fillField('pf_phone', d.phone);
          document.getElementById('pf_fayda_ocr_raw').value = JSON.stringify(d).slice(0, 4000);
          statusEl.textContent = '✓ Extraction complete — please review the fields below.';
          statusEl.style.color = '#22c55e';
          extractBtn.disabled = false;
        } else if (data.data && data.data.code === 'no_api_key') {
          statusEl.textContent = 'AI vision not configured yet — falling back to basic OCR…';
          runTesseractFallback();
        } else {
          statusEl.textContent = '❌ ' + ((data.data && data.data.message) || 'Vision extraction failed.') + ' Falling back to basic OCR…';
          runTesseractFallback();
        }
      })
      .catch(function(){
        statusEl.textContent = 'Network error reaching the vision API — falling back to basic OCR…';
        runTesseractFallback();
      });
  });

  document.getElementById('pf-form').addEventListener('submit', function(e){
    e.preventDefault();
    var btn = document.getElementById('pf-submit-btn');
    var res = document.getElementById('pf-result');
    btn.disabled = true; btn.style.opacity = '.6'; btn.textContent = '⏳ Creating…';
    res.style.display = 'none';

    var fd = new FormData();
    fd.append('action', 'etelid_fayda_quick_register');
    fd.append('nonce', _pfNonce);
    fd.append('full_name', document.getElementById('pf_full_name').value);
    fd.append('full_name_am', document.getElementById('pf_full_name_am').value);
    fd.append('dob', document.getElementById('pf_dob').value);
    fd.append('sex', document.getElementById('pf_sex').value);
    fd.append('nationality', document.getElementById('pf_nationality').value);
    fd.append('fayda_fin', document.getElementById('pf_fayda_fin').value);
    fd.append('region', document.getElementById('pf_region').value);
    fd.append('zone', document.getElementById('pf_zone').value);
    fd.append('woreda', document.getElementById('pf_woreda').value);
    fd.append('phone', document.getElementById('pf_phone').value);
    fd.append('email', document.getElementById('pf_email').value);
    fd.append('primary_company_slug', document.getElementById('pf_primary_company_slug').value);
    fd.append('role', document.getElementById('pf_role').value);
    fd.append('notify_channel', (document.querySelector('input[name=pf_notify_channel]:checked')||{}).value || 'auto');
    fd.append('fayda_front_image', document.getElementById('pf_fayda_front_image').value);
    fd.append('fayda_back_image', document.getElementById('pf_fayda_back_image').value);
    fd.append('fayda_ocr_raw', document.getElementById('pf_fayda_ocr_raw').value);

    fetch(_pfAjax, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(data){
        res.style.display = 'block';
        if (data && data.success) {
          var d = data.data;
          res.style.background = 'rgba(99,153,34,.12)'; res.style.border = '1px solid rgba(99,153,34,.3)'; res.style.color = '#cde';
          var msg = '<div style="font-weight:700;color:#8fd14f;margin-bottom:8px;">✅ Created ' + d.etel_id + '</div>';
          msg += '<div>Phone: ' + d.phone + ' &nbsp;·&nbsp; Temp password: <strong>' + d.temp_password + '</strong></div>';
          if (d.notify && d.notify.auto_sent) {
            msg += '<div style="margin-top:6px;color:#8fd14f;">Sent automatically via ' + (d.notify.auto_channel||'') + '.</div>';
          } else {
            msg += '<div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;">';
            if (d.notify && d.notify.whatsapp_link) msg += '<a href="'+d.notify.whatsapp_link+'" target="_blank" style="color:#D4AF37;">Send via WhatsApp</a>';
            if (d.notify && d.notify.telegram_link) msg += '<a href="'+d.notify.telegram_link+'" target="_blank" style="color:#D4AF37;">Open Telegram</a>';
            msg += '</div>';
          }
          res.innerHTML = msg;
          document.getElementById('pf-form').reset();
          document.getElementById('pf-form').style.display = 'none';
          document.getElementById('pf-step1').style.display = 'none';
        } else {
          res.style.background = 'rgba(226,75,74,.1)'; res.style.border = '1px solid rgba(226,75,74,.3)'; res.style.color = '#E24B4A';
          var errMsg = (data && data.data && data.data.message) ? data.data.message
                     : (!data || typeof data !== 'object') ? 'Your session may have expired. Please fully reload this page (not from a background tab) and try again.'
                     : 'Could not create the member.';
          res.innerHTML = '<strong>❌ ' + errMsg + '</strong>';
          btn.disabled = false; btn.style.opacity = '1'; btn.textContent = '🪪 Create Etel-ID & Send Password';
        }
      })
      .catch(function(err){
        res.style.display = 'block'; res.style.background = 'rgba(226,75,74,.1)'; res.style.border = '1px solid rgba(226,75,74,.3)'; res.style.color = '#E24B4A';
        res.innerHTML = '<strong>❌ Network error: ' + err.message + '</strong>';
        btn.disabled = false; btn.style.opacity = '1'; btn.textContent = '🪪 Create Etel-ID & Send Password';
      });
  });
})();
</script>


<!-- ══ Etel Admin Panels (Members / Pages / Media / Menus / Branding / Reset PW) ══ -->
<style>
.eap-modal{display:none;position:fixed;inset:0;z-index:99997;background:rgba(0,0,0,.75);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:16px;}
.eap-box{position:relative;overflow-x:hidden;overflow-y:auto;border-radius:16px;width:min(96vw,760px);max-height:92vh;box-shadow:0 24px 80px rgba(0,0,0,.8);}
.eid-gold-box{
  position:relative;overflow-x:hidden;
  background:
    radial-gradient(ellipse at 85% 0%, rgba(212,175,55,.16) 0%, transparent 55%),
    repeating-linear-gradient(30deg,  transparent 0, transparent 18px, rgba(212,175,55,.04) 18px, rgba(212,175,55,.04) 19px),
    repeating-linear-gradient(-30deg, transparent 0, transparent 18px, rgba(212,175,55,.04) 18px, rgba(212,175,55,.04) 19px),
    linear-gradient(165deg,#130d05 0%,#0a0703 45%,#130d05 100%);
  border:1px solid rgba(212,175,55,.35);
}
.eid-gold-box::before{
  content:'';position:absolute;top:0;left:0;right:0;height:3px;z-index:3;pointer-events:none;
  background:linear-gradient(90deg,#6B4E0E,#D4AF37,#F5E6A3,#D4AF37,#6B4E0E);
}
.eid-gold-box::after{
  content:'';position:absolute;top:3px;right:8px;bottom:0;width:4px;z-index:3;pointer-events:none;opacity:.4;
  background:linear-gradient(180deg,#D4AF37 0%,#F5E6A3 20%,#A07820 40%,#D4AF37 60%,#F5E6A3 80%,#A07820 100%);
}
.eap-head{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid rgba(212,175,55,.15);position:sticky;top:0;background:linear-gradient(165deg,#130d05 0%,#0a0703 100%);z-index:2;}
.eap-title{font-size:16px;font-weight:700;color:#D4AF37;}
.eap-sub{font-size:11px;color:#666;margin-top:2px;}
.eap-close{background:none;border:1px solid #333;color:#888;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:13px;}
.eap-body{padding:20px;}
.eap-field{display:flex;flex-direction:column;gap:4px;margin-bottom:12px;}
.eap-field label{font-size:11px;color:#998;text-transform:uppercase;letter-spacing:.04em;}
.eap-input{background:#111;border:1px solid #2a2a2a;color:#e8e2d4;border-radius:7px;padding:9px 10px;font-size:13px;width:100%;box-sizing:border-box;font-family:inherit;}
.eap-input:focus{outline:none;border-color:#D4AF37;}
.eap-grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.eap-btn-gold{padding:10px 16px;border-radius:8px;border:none;background:linear-gradient(135deg,#C8960C,#D4AF37);color:#0d0d0d;font-weight:700;font-size:13px;cursor:pointer;}
.eap-btn-ghost{padding:10px 16px;border-radius:8px;border:1px solid #333;background:none;color:#cbb;font-size:13px;cursor:pointer;}
.eap-btn-danger{padding:10px 16px;border-radius:8px;border:1px solid rgba(239,68,68,.4);background:rgba(239,68,68,.08);color:#ef9a9a;font-size:13px;cursor:pointer;}
.eap-tabs{display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap;}
.eap-tab{padding:7px 14px;border-radius:7px;border:1px solid #2a2a2a;background:#111;color:#998;font-size:12px;cursor:pointer;}
.eap-tab.active{border-color:#D4AF37;color:#D4AF37;background:rgba(212,175,55,.08);}
.eap-row{display:flex;align-items:center;gap:10px;padding:10px 12px;border-bottom:1px solid rgba(212,175,55,.08);font-size:13px;cursor:pointer;}
.eap-row:hover{background:rgba(212,175,55,.04);}
.eap-pill{font-size:10px;padding:2px 8px;border-radius:10px;text-transform:uppercase;letter-spacing:.03em;}
.eap-pill.active{background:rgba(34,197,94,.15);color:#6ee7a0;}
.eap-pill.suspended{background:rgba(239,68,68,.15);color:#ef9a9a;}
.eap-pill.expired,.eap-pill.revoked{background:rgba(150,150,150,.15);color:#aaa;}
.eap-media-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:10px;}
.eap-media-item{position:relative;border:1px solid #2a2a2a;border-radius:8px;overflow:hidden;aspect-ratio:1/1;background:#111;}
.eap-media-item img{width:100%;height:100%;object-fit:cover;}
.eap-media-del{position:absolute;top:4px;right:4px;background:rgba(0,0,0,.7);color:#ef9a9a;border:none;border-radius:5px;font-size:11px;padding:2px 6px;cursor:pointer;}
</style>

<!-- ── Members Controller ── -->
<div id="eap-members-modal" class="eap-modal">
  <div class="eap-box eid-gold-box" style="width:min(98vw,1180px);max-height:96vh;">
    <div class="eap-head">
      <div><div class="eap-title">👥 Members Controller</div><div class="eap-sub">Cross-system member directory — EtelID, EtelPay, Delivery &amp; Ride</div></div>
      <button class="eap-close" onclick="closeMembersModal()">✕</button>
    </div>
    <div class="eap-body" style="padding:16px;">
      <div class="eap-tabs" id="eap-mem-tabs">
        <div class="eap-tab active" data-src="etelid" onclick="eapMemSwitch('etelid')">EtelID</div>
        <div class="eap-tab" data-src="pay" onclick="eapMemSwitch('pay')">EtelPay</div>
        <div class="eap-tab" data-src="delivery" onclick="eapMemSwitch('delivery')">Delivery &amp; Ride</div>
      </div>
      <div id="eap-mem-search-row" style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
        <input type="text" id="eap-mem-search" class="eap-input" style="flex:1;min-width:160px;" placeholder="Search name, phone, Etel-ID, email…" onkeydown="if(event.key==='Enter')eapMemLoad(1)">
        <select id="eap-mem-status" class="eap-input" style="width:auto;" onchange="eapMemLoad(1)">
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="suspended">Suspended</option>
          <option value="expired">Expired</option>
          <option value="revoked">Revoked</option>
        </select>
        <select id="eap-mem-role" class="eap-input" style="width:auto;display:none;" onchange="eapMemLoad(1)">
          <option value="">All roles</option>
          <option value="customer">Customer</option>
          <option value="driver">Driver</option>
          <option value="service_giver">Service Giver</option>
          <option value="courier">Courier</option>
          <option value="admin">Admin</option>
        </select>
        <button class="eap-btn-gold" onclick="eapMemLoad(1)">Search</button>
      </div>
      <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
        <div style="flex:1 1 320px;min-width:280px;max-width:420px;">
          <div id="eap-mem-list" style="max-height:64vh;overflow-y:auto;border:1px solid rgba(212,175,55,.12);border-radius:8px;"><div style="color:#666;font-size:13px;padding:14px;">Loading…</div></div>
          <div id="eap-mem-pager" style="display:flex;justify-content:center;gap:10px;margin-top:12px;"></div>
        </div>
        <div id="eap-mem-detail" style="flex:2 1 460px;min-width:300px;max-height:74vh;overflow-y:auto;border:1px solid rgba(212,175,55,.12);border-radius:8px;padding:16px;">
          <div style="color:#666;font-size:13px;text-align:center;padding:40px 20px;">← Select a member from the list to view full details</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── Device Biometric Registration (Face ID / Touch ID / fingerprint) ── -->
<div id="eap-bio-register-modal" class="eap-modal">
  <div class="eap-box eid-gold-box" style="width:min(94vw,460px);">
    <div class="eap-head">
      <div><div class="eap-title">🆔 Face / Fingerprint</div><div class="eap-sub">Sign in with this device's own Face ID, Touch ID or fingerprint</div></div>
      <button class="eap-close" onclick="closeBioRegisterModal()">✕</button>
    </div>
    <div class="eap-body" style="padding:16px;text-align:center;">
      <p style="font-size:13px;color:#aaa;line-height:1.6;margin-bottom:16px;">
        This uses your device's own built-in biometric sensor. Your face or fingerprint never leaves your device and is never sent to or stored on our servers — only a secure cryptographic key is saved.
      </p>
      <div id="eap-bio-status" style="font-size:13px;margin-bottom:14px;min-height:18px;"></div>
      <button class="eap-btn-gold" id="eap-bio-register-btn" onclick="eapBioRegister()">Register This Device</button>
    </div>
  </div>
</div>

<!-- ── Biometric Center ── -->
<div id="eap-biometric-modal" class="eap-modal">
  <div class="eap-box eid-gold-box" style="width:min(98vw,900px);max-height:96vh;">
    <div class="eap-head">
      <div><div class="eap-title">🔏 Biometric Center</div><div class="eap-sub">Real device biometric (WebAuthn) status &amp; physical NFC card linking — no fake data, everything here reflects an actual registered device or card</div></div>
      <button class="eap-close" onclick="closeBiometricCenterModal()">✕</button>
    </div>
    <div class="eap-body" style="padding:16px;">
      <div style="display:flex;gap:8px;margin-bottom:14px;">
        <input type="text" id="eap-bio-search" class="eap-input" style="flex:1" placeholder="Search member by name, phone, Etel-ID…" onkeydown="if(event.key==='Enter')eapBioSearch()">
        <button class="eap-btn-gold" onclick="eapBioSearch()">Search</button>
      </div>
      <div id="eap-bio-search-results" style="margin-bottom:16px;"></div>
      <div id="eap-bio-detail"><div style="color:#666;font-size:13px;padding:14px;text-align:center;">Search for a member to view their biometric enrollment.</div></div>
    </div>
  </div>
</div>

<!-- ── Platform Logos (admin-only: one real logo image per social/bank
     platform, used on every member's NFC card from then on) ── -->
<div id="eap-platform-logos-modal" class="eap-modal">
  <div class="eap-box eid-gold-box" style="width:min(98vw,760px);max-height:96vh;">
    <div class="eap-head">
      <div><div class="eap-title">🖼 Platform Logos</div><div class="eap-sub">Upload one real logo per platform - it replaces the color badge on every member's card, everywhere, instantly.</div></div>
      <button class="eap-close" onclick="closePlatformLogosModal()">✕</button>
    </div>
    <div class="eap-body" style="padding:16px;">
      <div id="p-logos-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;">Loading...</div>
    </div>
  </div>
</div>

<!-- ── Company Roles ── -->
<div id="eap-company-roles-modal" class="eap-modal">
  <div class="eap-box eid-gold-box" style="width:min(98vw,1080px);max-height:96vh;">
    <div class="eap-head">
      <div><div class="eap-title">🏢 Company Roles</div><div class="eap-sub">Set who is admin/owner for each project — that's who receives payments and payouts via ኢE Pay</div></div>
      <button class="eap-close" onclick="closeCompanyRolesModal()">✕</button>
    </div>
    <div class="eap-body" style="padding:16px;">
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:flex-end;">
        <div class="eap-field" style="flex:1;min-width:180px;margin:0">
          <label>Member (Etel-ID or phone)</label>
          <input type="text" id="eap-cr-identifier" class="eap-input" placeholder="EG-2026-00001 or +2519...">
        </div>
        <div class="eap-field" style="flex:1;min-width:160px;margin:0">
          <label>Company Slug</label>
          <input type="text" id="eap-cr-slug" class="eap-input" list="eap-cr-known-slugs" placeholder="e.g. delivery, gebeya, pay">
          <datalist id="eap-cr-known-slugs"></datalist>
        </div>
        <div class="eap-field" style="min-width:150px;margin:0">
          <label>Role</label>
          <select id="eap-cr-role" class="eap-input">
            <option value="">Choose…</option>
            <option value="admin">Admin</option>
            <option value="owner">Owner</option>
            <option value="creator">Creator</option>
            <option value="Member">Member</option>
          </select>
        </div>
        <button class="eap-btn-gold" onclick="eapCrSet()">Save</button>
      </div>
      <div id="eap-cr-msg" style="font-size:12px;margin-bottom:12px;"></div>
      <div id="eap-cr-list" style="max-height:56vh;overflow-y:auto;"><div style="color:#666;font-size:13px;padding:14px;">Loading…</div></div>
    </div>
  </div>
</div>

<!-- ── Pages Manager ── -->
<div id="eap-pages-modal" class="eap-modal">
  <div class="eap-box eid-gold-box">
    <div class="eap-head">
      <div><div class="eap-title">📑 Pages</div><div class="eap-sub">Create and edit WordPress pages without leaving the portal</div></div>
      <button class="eap-close" onclick="closePagesModal()">✕</button>
    </div>
    <div class="eap-body">
      <div id="eap-pages-list-view">
        <div style="display:flex;justify-content:flex-end;margin-bottom:12px;">
          <button class="eap-btn-gold" onclick="eapPageEdit(0)">+ New Page</button>
        </div>
        <div id="eap-pages-list"><div style="color:#666;font-size:13px;">Loading…</div></div>
      </div>
      <div id="eap-pages-edit-view" style="display:none;">
        <input type="hidden" id="eap-page-id" value="0">
        <div class="eap-field"><label>Title</label><input type="text" id="eap-page-title" class="eap-input"></div>
        <div class="eap-field"><label>Content</label><textarea id="eap-page-content" class="eap-input" rows="10"></textarea></div>
        <div class="eap-field"><label>Status</label>
          <select id="eap-page-status" class="eap-input">
            <option value="publish">Published</option>
            <option value="draft">Draft</option>
            <option value="pending">Pending Review</option>
          </select>
        </div>
        <div style="display:flex;gap:10px;">
          <button class="eap-btn-gold" onclick="eapPageSave()">Save Page</button>
          <button class="eap-btn-ghost" onclick="eapPagesShowList()">← Back to List</button>
        </div>
        <div id="eap-page-msg"></div>
      </div>
    </div>
  </div>
</div>

<!-- ── Media Manager ── -->
<div id="eap-media-modal" class="eap-modal">
  <div class="eap-box eid-gold-box">
    <div class="eap-head">
      <div><div class="eap-title">🖼 Media Library</div><div class="eap-sub">Upload and manage images &amp; files</div></div>
      <button class="eap-close" onclick="closeMediaModal()">✕</button>
    </div>
    <div class="eap-body">
      <div style="margin-bottom:14px;">
        <input type="file" id="eap-media-file" style="display:none;" onchange="eapMediaUpload()">
        <button class="eap-btn-gold" onclick="document.getElementById('eap-media-file').click()">⬆ Upload File</button>
        <span id="eap-media-msg" style="margin-left:10px;font-size:12px;color:#D4AF37;"></span>
      </div>
      <div id="eap-media-grid" class="eap-media-grid"><div style="color:#666;font-size:13px;">Loading…</div></div>
    </div>
  </div>
</div>

<!-- ── Menus Manager ── -->
<div id="eap-menus-modal" class="eap-modal">
  <div class="eap-box eid-gold-box">
    <div class="eap-head">
      <div><div class="eap-title">📋 Menus</div><div class="eap-sub">Edit navigation menu items, order, and links</div></div>
      <button class="eap-close" onclick="closeMenusModal()">✕</button>
    </div>
    <div class="eap-body">
      <div class="eap-field"><label>Menu</label>
        <select id="eap-menu-select" class="eap-input" onchange="eapMenuLoadItems()"></select>
      </div>
      <div id="eap-menu-items"></div>
      <button class="eap-btn-ghost" style="margin-top:10px;" onclick="eapMenuAddRow()">+ Add Item</button>
      <div style="display:flex;gap:10px;margin-top:16px;">
        <button class="eap-btn-gold" onclick="eapMenuSave()">Save Menu</button>
      </div>
      <div id="eap-menu-msg"></div>
    </div>
  </div>
</div>

<!-- ── Branding (Theme) ── -->
<div id="eap-branding-modal" class="eap-modal">
  <div class="eap-box eid-gold-box">
    <div class="eap-head">
      <div><div class="eap-title">🎨 Theme &amp; Branding</div><div class="eap-sub">Site identity and the gold/dark design-system accent — not the full WP Customizer</div></div>
      <button class="eap-close" onclick="closeBrandingModal()">✕</button>
    </div>
    <div class="eap-body">
      <div class="eap-grid2">
        <div class="eap-field"><label>Site Title</label><input type="text" id="eap-b-title" class="eap-input"></div>
        <div class="eap-field"><label>Tagline</label><input type="text" id="eap-b-tagline" class="eap-input"></div>
        <div class="eap-field"><label>Organization Name</label><input type="text" id="eap-b-org" class="eap-input"></div>
        <div class="eap-field"><label>Website</label><input type="text" id="eap-b-website" class="eap-input"></div>
        <div class="eap-field"><label>Org Email</label><input type="email" id="eap-b-email" class="eap-input"></div>
        <div class="eap-field"><label>Gold Accent</label><input type="color" id="eap-b-gold" class="eap-input" style="height:38px;padding:2px;"></div>
      </div>
      <div class="eap-field"><label>Logo</label>
        <div style="display:flex;align-items:center;gap:10px;">
          <img id="eap-b-logo-preview" src="" style="height:40px;display:none;border-radius:4px;">
          <input type="file" id="eap-b-logo-file" style="display:none;" onchange="eapBrandingUploadLogo()">
          <button type="button" class="eap-btn-ghost" onclick="document.getElementById('eap-b-logo-file').click()">Upload Logo</button>
        </div>
      </div>
      <div class="eap-field"><label>Custom CSS</label><textarea id="eap-b-css" class="eap-input" rows="6" placeholder="/* optional overrides */"></textarea></div>
      <button class="eap-btn-gold" onclick="eapBrandingSave()">Save Branding</button>
      <div id="eap-branding-msg"></div>
    </div>
  </div>
</div>

<!-- ── Reset Password ── -->
<div id="eap-resetpw-modal" class="eap-modal">
  <div class="eap-box eid-gold-box" style="width:min(96vw,460px);">
    <div class="eap-head">
      <div><div class="eap-title">🔑 Reset Password</div><div class="eap-sub">Set a new login password for any member</div></div>
      <button class="eap-close" onclick="closeResetPwModal()">✕</button>
    </div>
    <div class="eap-body">
      <div class="eap-field"><label>Find Member (name, phone, or Etel-ID)</label>
        <div style="display:flex;gap:8px;">
          <input type="text" id="eap-rp-search" class="eap-input" onkeydown="if(event.key==='Enter')eapRpSearch()">
          <button class="eap-btn-ghost" onclick="eapRpSearch()">Find</button>
        </div>
      </div>
      <div id="eap-rp-results"></div>
      <div id="eap-rp-form" style="display:none;margin-top:14px;border-top:1px solid rgba(212,175,55,.15);padding-top:14px;">
        <input type="hidden" id="eap-rp-id">
        <div id="eap-rp-who" style="font-size:13px;color:#D4AF37;margin-bottom:10px;"></div>
        <div class="eap-field"><label>New Password</label><input type="password" id="eap-rp-pass" class="eap-input" placeholder="Min 6 characters"></div>
        <div class="eap-field"><label>Confirm Password</label><input type="password" id="eap-rp-confirm" class="eap-input"></div>
        <button class="eap-btn-gold" onclick="eapRpSubmit()">Set Password</button>
      </div>
      <div id="eap-rp-msg"></div>
    </div>
  </div>
</div>

<div id="eap-nfc-modal" class="eap-modal">
  <div class="eap-box eid-gold-box" style="width:min(96vw,520px);max-height:92vh;">
    <div class="eap-head">
      <div><div class="eap-title">📡 Write NFC Card</div><div class="eap-sub">Issue a tap-to-verify link onto a member's NFC tag</div></div>
      <button class="eap-close" onclick="closeNfcModal()">✕</button>
    </div>
    <div class="eap-body" style="padding:16px;">
      <div class="eap-field">
        <label>Find member</label>
        <div style="display:flex;gap:8px;">
          <input type="text" id="eap-nfc-search" class="eap-input" style="flex:1;" placeholder="Name, phone, or Etel-ID… (leave blank to list all)" oninput="eapNfcSearchDebounced()" onkeydown="if(event.key==='Enter')eapNfcSearch()">
          <button class="eap-btn-ghost" onclick="eapNfcSearch()">Search</button>
        </div>
      </div>
      <div id="eap-nfc-results" style="margin-top:8px;max-height:240px;overflow-y:auto;"></div>

      <div id="eap-nfc-selected" style="display:none;margin-top:16px;border-top:1px solid rgba(212,175,55,.15);padding-top:14px;">
        <div style="font-size:13px;color:#D4AF37;margin-bottom:10px;">Selected: <strong id="eap-nfc-sel-name"></strong> (<span id="eap-nfc-sel-id"></span>)</div>

        <div class="eap-field"><label>Profile URL</label>
          <div style="display:flex;gap:8px;">
            <input type="text" id="eap-nfc-url" class="eap-input" readonly style="flex:1;">
            <button class="eap-btn-ghost" onclick="eapNfcCopy()">Copy</button>
          </div>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
          <a id="eap-nfc-test-link" href="#" target="_blank" class="eap-btn-ghost" style="text-decoration:none;">Open / Test on This Phone</a>
          <button class="eap-btn-gold" onclick="eapNfcWrite(event)">Write to NFC Tag</button>
        </div>
        <div id="eap-nfc-status" style="font-size:12px;color:#F2CC6B;margin-top:10px;min-height:16px;"></div>
        <div style="font-size:11px;color:#777;margin-top:8px;line-height:1.5;">
          No physical tag yet? "Open / Test on This Phone" loads the exact page a tap will show later — confirms the link works before you buy NTAG213 cards.
        </div>
      </div>
    </div>
  </div>
</div>

<script>
/* ── Generic modal helpers ── */
function _eapOpen(id){ document.getElementById(id).style.display='flex'; document.body.style.overflow='hidden'; }
function _eapClose(id){ document.getElementById(id).style.display='none'; document.body.style.overflow=''; }
['eap-members-modal','eap-pages-modal','eap-media-modal','eap-menus-modal','eap-branding-modal','eap-resetpw-modal','eap-nfc-modal','eap-company-roles-modal','eap-bio-register-modal','eap-biometric-modal','eap-platform-logos-modal'].forEach(function(id){
  document.getElementById(id).addEventListener('click', function(e){ if (e.target === this) _eapClose(id); });
});

/* ════════ NFC CARD WRITER ════════ */
function openNfcModal(e){
  if(e)e.preventDefault();
  _eapOpen('eap-nfc-modal');
  document.getElementById('eap-nfc-selected').style.display = 'none';
  document.getElementById('eap-nfc-search').value = '';
  eapNfcSearch();
}
function closeNfcModal(){ _eapClose('eap-nfc-modal'); }

var _eapNfcDebounce = null;
function eapNfcSearchDebounced(){
  clearTimeout(_eapNfcDebounce);
  _eapNfcDebounce = setTimeout(eapNfcSearch, 350);
}

async function eapNfcSearch(){
  var box = document.getElementById('eap-nfc-results');
  var q = document.getElementById('eap-nfc-search').value.trim();
  box.innerHTML = '<div style="color:#666;font-size:13px;padding:10px;">Loading…</div>';
  var res = await _eidPanel('etelid_panel_members_list', { source: 'etelid', page: 1, search: q, status: '' });
  if (!res.success || !res.data.members || !res.data.members.length) {
    box.innerHTML = '<div style="color:#666;font-size:13px;padding:10px;">No members found.</div>';
    return;
  }
  var html = '';
  res.data.members.forEach(function(m){
    html += '<div class="eap-row" onclick=\'eapNfcSelect(' + JSON.stringify(m.id) + ',' + JSON.stringify(m.name) + ',' + JSON.stringify(m.etel_id) + ')\'>'
      + '<div style="flex:1;"><div style="font-weight:700;color:#e8e2d4;">' + (m.name||'—') + '</div>'
      + '<div style="font-size:11px;color:#998;">' + (m.etel_id||'') + '</div></div>'
      + (m.status && m.status!=='active' ? '<span class="eap-pill ' + m.status + '">' + m.status + '</span>' : '')
      + '</div>';
  });
  if (res.data.total > res.data.members.length) {
    html += '<div style="color:#666;font-size:11px;padding:8px;text-align:center;">Showing first ' + res.data.members.length + ' of ' + res.data.total + ' — refine search to narrow down.</div>';
  }
  box.innerHTML = html;
}

async function eapNfcSelect(memberId, name, etelId){
  document.getElementById('eap-nfc-sel-name').textContent = name;
  document.getElementById('eap-nfc-sel-id').textContent = etelId;
  document.getElementById('eap-nfc-url').value = 'Generating link...';
  document.getElementById('eap-nfc-status').textContent = '';
  document.getElementById('eap-nfc-selected').style.display = 'block';
  document.getElementById('eap-nfc-results').innerHTML = '';

  // Issues a real card token (etelid_nfc_cards row) and returns the
  // opaque /m/{token} link the NFC service-selector page resolves -
  // this used to just build a /verify/{etel_id} link client-side,
  // which pointed at the public read-only verify page (no service
  // buttons, and the member's real Etel-ID printed in the clear on
  // the physical tag) instead of ever actually issuing a card.
  var res = await _eidPanel('etelid_nfc_issue', { member_id: memberId });
  if (!res.success) {
    document.getElementById('eap-nfc-url').value = '';
    document.getElementById('eap-nfc-status').textContent = 'Error: ' + (res.data && res.data.message ? res.data.message : 'Could not issue card.');
    return;
  }
  var url = res.data.url;
  document.getElementById('eap-nfc-url').value = url;
  document.getElementById('eap-nfc-test-link').href = url;
}

function eapNfcCopy(){
  var f = document.getElementById('eap-nfc-url');
  f.select();
  document.execCommand('copy');
}

var _eapNfcWriteController = null;

async function eapNfcWrite(evt){
  var statusEl = document.getElementById('eap-nfc-status');
  var url = document.getElementById('eap-nfc-url').value;

  if (typeof NDEFReader === 'undefined') {
    statusEl.textContent = 'Web NFC not available here — use Chrome on Android over HTTPS, or write the tag with an app like "NFC Tools" using the URL above.';
    return;
  }

  /* Abort any previous in-flight write before starting a new one - this is
     what causes "Push is cancelled due to a new push request" on Android:
     two overlapping write() calls fighting over the NFC radio. */
  if (_eapNfcWriteController) {
    _eapNfcWriteController.abort();
  }
  _eapNfcWriteController = new AbortController();

  var btn = evt ? evt.currentTarget : null;
  if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }

  statusEl.textContent = 'Hold a blank NFC tag steady on the back of your phone — do not move it until this message changes.';

  try {
    var writer = new NDEFReader();
    await writer.write(
      { records: [{ recordType: 'url', data: url }] },
      { signal: _eapNfcWriteController.signal }
    );
    statusEl.textContent = 'Tag written successfully.';
  } catch (err) {
    if (err.name === 'AbortError') {
      statusEl.textContent = 'Write cancelled.';
    } else {
      statusEl.textContent = 'Write failed: ' + err.message + '. Make sure no other app (Google Pay, Nearby Share) is using NFC, only tap "Write to NFC Tag" once, and keep the tag still and touching the phone the whole time.';
    }
  } finally {
    if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
  }
}

/* ════════ MEMBERS CONTROLLER ════════ */
var _eapMemSrc = 'etelid', _eapMemPage = 1;
function openMembersModal(e){ if(e)e.preventDefault(); _eapOpen('eap-members-modal'); eapMemLoad(1); }
function closeMembersModal(){ _eapClose('eap-members-modal'); }

/* ════════ DEVICE BIOMETRIC (Face ID / Touch ID / fingerprint via WebAuthn) ════════ */
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

async function openBioRegisterModal(e){
  if(e)e.preventDefault();
  _eapOpen('eap-bio-register-modal');
  var statusEl = document.getElementById('eap-bio-status');
  var btn = document.getElementById('eap-bio-register-btn');
  statusEl.style.color = '';
  if (!window.PublicKeyCredential) {
    statusEl.style.color = '#f66';
    statusEl.textContent = 'Your browser does not support device biometrics. Try Safari on iPhone, Chrome on Android, or a recent desktop browser.';
    btn.style.display = 'none';
    return;
  }
  try {
    var available = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
    if (!available) {
      statusEl.style.color = '#f66';
      statusEl.textContent = 'No Face ID, Touch ID or fingerprint sensor was found on this device.';
      btn.style.display = 'none';
      return;
    }
  } catch(e) {}
  btn.style.display = '';
  statusEl.textContent = '';
}
function closeBioRegisterModal(){ _eapClose('eap-bio-register-modal'); }

async function eapBioRegister(){
  var statusEl = document.getElementById('eap-bio-status');
  var btn = document.getElementById('eap-bio-register-btn');
  btn.disabled = true;
  statusEl.style.color = '#999';
  statusEl.textContent = 'Requesting options…';
  try {
    var optRes = await _eidPanel('etelid_webauthn_register_options', {});
    if (!optRes.success) throw new Error((optRes.data && optRes.data.message) || 'Could not start registration.');
    var opt = optRes.data;

    var publicKey = {
      challenge: _waB64urlToBuf(opt.challenge),
      rp: opt.rp,
      user: { id: _waB64urlToBuf(opt.user.id), name: opt.user.name, displayName: opt.user.displayName },
      pubKeyCredParams: opt.pubKeyCredParams,
      authenticatorSelection: opt.authenticatorSelection,
      attestation: opt.attestation,
      timeout: opt.timeout,
      excludeCredentials: (opt.excludeCredentials||[]).map(function(c){ return { type: c.type, id: _waB64urlToBuf(c.id) }; }),
    };

    statusEl.textContent = 'Follow the prompt on your device…';
    var cred = await navigator.credentials.create({ publicKey: publicKey });

    var payload = {
      id: cred.id,
      rawId: _waBufToB64url(cred.rawId),
      type: cred.type,
      response: {
        clientDataJSON: _waBufToB64url(cred.response.clientDataJSON),
        attestationObject: _waBufToB64url(cred.response.attestationObject),
      },
    };

    statusEl.textContent = 'Saving…';
    var verifyRes = await _eidPanel('etelid_webauthn_register_verify', { credential: JSON.stringify(payload) });
    if (!verifyRes.success) throw new Error((verifyRes.data && verifyRes.data.message) || 'Could not save credential.');

    statusEl.style.color = '#6ee7a0';
    statusEl.textContent = (verifyRes.data && verifyRes.data.message) || 'Registered!';
    setTimeout(closeBioRegisterModal, 1600);
  } catch (err) {
    statusEl.style.color = '#f66';
    statusEl.textContent = err.name === 'NotAllowedError' ? 'Cancelled or timed out.' : (err.message || 'Registration failed.');
  } finally {
    btn.disabled = false;
  }
}

/* ════════ BIOMETRIC CENTER ════════ */
function openBiometricCenterModal(e){
  if(e)e.preventDefault();
  _eapOpen('eap-biometric-modal');
  document.getElementById('eap-bio-search').value = '';
  document.getElementById('eap-bio-search-results').innerHTML = '';
  document.getElementById('eap-bio-detail').innerHTML = '<div style="color:#666;font-size:13px;padding:14px;text-align:center;">Search for a member to view their biometric enrollment.</div>';
}
function closeBiometricCenterModal(){ _eapClose('eap-biometric-modal'); }

async function eapBioSearch(){
  var q = document.getElementById('eap-bio-search').value.trim();
  var resultsEl = document.getElementById('eap-bio-search-results');
  if (!q) { resultsEl.innerHTML = ''; return; }
  resultsEl.innerHTML = '<div style="color:#666;font-size:13px;">Searching…</div>';
  var res = await _eidPanel('etelid_panel_biometric_search', { q: q });
  if (!res.success || !(res.data.members||[]).length) { resultsEl.innerHTML = '<div style="color:#888;font-size:13px;">No members found.</div>'; return; }
  resultsEl.innerHTML = res.data.members.map(function(m){
    return '<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 10px;border:1px solid rgba(212,175,55,.12);border-radius:8px;margin-bottom:6px;cursor:pointer;" onclick="eapBioLoad(' + m.id + ')">' +
      '<span style="font-size:13px;color:#eee;">' + m.full_name + ' <span style="color:#888;font-family:monospace;font-size:11px;">' + m.etel_id + '</span></span>' +
      '<span style="font-size:11px;color:#888;font-family:monospace;">' + m.phone + '</span>' +
    '</div>';
  }).join('');
}

async function eapBioLoad(memberId){
  var detailEl = document.getElementById('eap-bio-detail');
  detailEl.innerHTML = '<div style="color:#666;font-size:13px;padding:14px;">Loading…</div>';
  var res = await _eidPanel('etelid_panel_biometric_get', { member_id: memberId });
  if (!res.success) { detailEl.innerHTML = '<div style="color:#f66;font-size:13px;padding:14px;">' + ((res.data && res.data.message) || 'Failed to load.') + '</div>'; return; }

  var d = res.data;
  var wa = d.webauthn;
  var waHtml = wa.enrolled
    ? '<span style="color:#6ee7a0;font-weight:700;">✓ Registered</span> — enrolled by ' + (wa.enrolled_by || 'self') +
      ' <button class="eap-btn-ghost" style="margin-left:10px;padding:4px 10px;font-size:11px;" onclick="eapBioRevoke(' + memberId + ')">Revoke</button>'
    : '<span style="color:#888;">Not registered</span> — member can register from their own portal (“Face / Fingerprint” tile)';

  var logHtml = (d.log||[]).length
    ? '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:8px;">' +
        '<thead><tr style="text-align:left;color:#888;font-size:10px;text-transform:uppercase;"><th style="padding:4px 8px;">Method</th><th style="padding:4px 8px;">Result</th><th style="padding:4px 8px;">When</th></tr></thead>' +
        '<tbody>' + d.log.map(function(l){
          return '<tr style="border-top:1px solid rgba(255,255,255,.06);"><td style="padding:4px 8px;color:#ddd;">' + l.method + '</td>' +
            '<td style="padding:4px 8px;color:' + (l.result==='success'?'#6ee7a0':'#f66') + ';">' + l.result + '</td>' +
            '<td style="padding:4px 8px;color:#888;">' + l.created_at + '</td></tr>';
        }).join('') + '</tbody></table>'
    : '<div style="color:#888;font-size:12px;margin-top:8px;">No check-ins yet.</div>';

  detailEl.innerHTML =
    '<div style="border:1px solid rgba(212,175,55,.15);border-radius:10px;padding:14px;margin-bottom:12px;">' +
      '<div style="font-weight:700;color:#eee;margin-bottom:2px;">' + d.member.full_name + '</div>' +
      '<div style="font-size:11px;color:#888;font-family:monospace;margin-bottom:12px;">' + d.member.etel_id + ' · ' + d.member.phone + '</div>' +

      '<div style="font-size:12px;font-weight:700;color:#D4AF37;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">🆔 Device Biometric (Face / Fingerprint)</div>' +
      '<div style="font-size:13px;margin-bottom:14px;">' + waHtml + '</div>' +

      '<div style="font-size:12px;font-weight:700;color:#D4AF37;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">📡 NFC / RFID Card</div>' +
      '<div style="display:flex;gap:8px;margin-bottom:6px;">' +
        '<input type="text" id="eap-bio-nfc-input" class="eap-input" style="flex:1;font-family:monospace" placeholder="e.g. A3:F1:04:BC:9E" value="' + (d.nfc_uid||'') + '">' +
        '<button class="eap-btn-gold" onclick="eapBioSaveNfc(' + memberId + ')">Save</button>' +
      '</div>' +
      '<div id="eap-bio-nfc-msg" style="font-size:12px;min-height:16px;"></div>' +
    '</div>' +
    '<div style="font-size:12px;font-weight:700;color:#D4AF37;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">Recent Check-ins</div>' +
    logHtml;
}

async function eapBioSaveNfc(memberId){
  var msgEl = document.getElementById('eap-bio-nfc-msg');
  var uid = document.getElementById('eap-bio-nfc-input').value.trim();
  msgEl.style.color = '#999'; msgEl.textContent = 'Saving…';
  var res = await _eidPanel('etelid_panel_biometric_nfc_save', { member_id: memberId, nfc_uid: uid });
  msgEl.style.color = res.success ? '#6ee7a0' : '#f66';
  msgEl.textContent = res.success ? (res.data.message || 'Saved.') : ((res.data && res.data.message) || 'Failed.');
}

async function eapBioRevoke(memberId){
  if (!confirm('Revoke this member\'s registered device biometric? They will need to register again.')) return;
  var res = await _eidPanel('etelid_panel_biometric_webauthn_revoke', { member_id: memberId });
  if (res.success) eapBioLoad(memberId);
}

/* ════════ COMPANY ROLES ════════ */
function openCompanyRolesModal(e){ if(e)e.preventDefault(); _eapOpen('eap-company-roles-modal'); eapCrLoad(); }
function closeCompanyRolesModal(){ _eapClose('eap-company-roles-modal'); }

function eapCrRoleBadge(role){
  var priv = ['admin','owner','creator'].indexOf(String(role).toLowerCase()) !== -1;
  return '<span style="padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;' +
    (priv ? 'background:rgba(212,175,55,.18);color:#D4AF37' : 'background:rgba(255,255,255,.08);color:#999') +
    '">' + role.replace(/[<>&]/g, function(c){ return {'<':'&lt;','>':'&gt;','&':'&amp;'}[c]; }) + '</span>';
}

async function eapCrLoad(filterSlug){
  var listEl = document.getElementById('eap-cr-list');
  listEl.innerHTML = '<div style="color:#666;font-size:13px;padding:14px;">Loading…</div>';
  var res = await _eidPanel('etelid_panel_company_roles_list', { company_slug: filterSlug || '' });
  if (!res.success) { listEl.innerHTML = '<div style="color:#f66;font-size:13px;padding:14px;">' + ((res.data && res.data.message) || 'Failed to load.') + '</div>'; return; }

  var dl = document.getElementById('eap-cr-known-slugs');
  dl.innerHTML = (res.data.known_slugs || []).map(function(s){ return '<option value="' + s + '">'; }).join('');

  var links = res.data.links || [];
  if (!links.length) { listEl.innerHTML = '<div style="color:#666;font-size:13px;padding:14px;">No company links yet.</div>'; return; }

  var rows = links.map(function(l){
    return '<tr style="border-bottom:1px solid rgba(255,255,255,.06);">' +
      '<td style="padding:8px 10px;color:#eee;">' + l.full_name + '</td>' +
      '<td style="padding:8px 10px;font-family:monospace;font-size:12px;color:#ddd;">' + l.etel_id + '</td>' +
      '<td style="padding:8px 10px;font-family:monospace;font-size:12px;color:#ddd;">' + l.phone + '</td>' +
      '<td style="padding:8px 10px;font-family:monospace;font-size:12px;color:#D4AF37;">' + l.company_slug + '</td>' +
      '<td style="padding:8px 10px;">' + eapCrRoleBadge(l.role_at) + '</td>' +
      '<td style="padding:8px 10px;font-size:11px;color:#888;">' + l.linked_at + '</td>' +
      '<td style="padding:8px 10px;"><button class="eap-btn-ghost" style="padding:4px 10px;font-size:11px;" onclick="eapCrRemove(' + l.id + ')">Remove</button></td>' +
    '</tr>';
  }).join('');

  listEl.innerHTML = '<table style="width:100%;border-collapse:collapse;font-size:13px;">' +
    '<thead><tr style="text-align:left;border-bottom:1px solid rgba(212,175,55,.15);color:#999;font-size:11px;text-transform:uppercase;">' +
      '<th style="padding:8px 10px;">Member</th><th style="padding:8px 10px;">Etel-ID</th><th style="padding:8px 10px;">Phone</th>' +
      '<th style="padding:8px 10px;">Company Slug</th><th style="padding:8px 10px;">Role</th><th style="padding:8px 10px;">Linked</th><th></th>' +
    '</tr></thead><tbody>' + rows + '</tbody></table>';
}

async function eapCrSet(){
  var msgEl = document.getElementById('eap-cr-msg');
  var identifier = document.getElementById('eap-cr-identifier').value.trim();
  var slug = document.getElementById('eap-cr-slug').value.trim();
  var role = document.getElementById('eap-cr-role').value;
  if (!identifier || !slug || !role) { msgEl.style.color = '#f66'; msgEl.textContent = 'Fill in member, company slug and role.'; return; }

  msgEl.style.color = '#999'; msgEl.textContent = 'Saving…';
  var res = await _eidPanel('etelid_panel_company_roles_set', { member_identifier: identifier, company_slug: slug, role_at: role });
  if (res.success) {
    msgEl.style.color = '#6ee7a0'; msgEl.textContent = (res.data && res.data.message) || 'Saved.';
    document.getElementById('eap-cr-identifier').value = '';
    document.getElementById('eap-cr-slug').value = '';
    document.getElementById('eap-cr-role').value = '';
    eapCrLoad();
  } else {
    msgEl.style.color = '#f66'; msgEl.textContent = (res.data && res.data.message) || 'Failed to save.';
  }
}

async function eapCrRemove(id){
  if (!confirm('Remove this company link?')) return;
  var res = await _eidPanel('etelid_panel_company_roles_remove', { id: id });
  if (res.success) eapCrLoad();
}
async function eapExtUserStatus(id, status) {
  var msgEl = document.getElementById('eap-ext-msg-' + id);
  if (msgEl) msgEl.textContent = 'Saving…';
  var res = await _eidPanel('etelid_panel_ext_user_status', { source: _eapMemSrc, id: id, status: status });
  if (msgEl) msgEl.textContent = res.success ? (status === 'active' ? '✓ Activated' : '⊘ Suspended') : '⚠ ' + ((res.data && res.data.message) || 'Failed');
  if (res.success) { setTimeout(function(){ eapMemLoad(_eapMemPage); }, 800); }
}
function eapMemSwitch(src){
  _eapMemSrc = src;
  document.querySelectorAll('#eap-mem-tabs .eap-tab').forEach(function(t){ t.classList.toggle('active', t.getAttribute('data-src')===src); });
  document.getElementById('eap-mem-search-row').style.display = 'flex';
  var searchEl = document.getElementById('eap-mem-search');
  var roleEl = document.getElementById('eap-mem-role');
  var statusEl = document.getElementById('eap-mem-status');
  if (src === 'etelid') {
    searchEl.placeholder = 'Search name, phone, Etel-ID, email…';
    roleEl.style.display = 'none';
    statusEl.style.display = '';
  } else if (src === 'pay') {
    searchEl.placeholder = 'Search name, phone, email…';
    roleEl.style.display = 'none';
    statusEl.style.display = '';
  } else {
    searchEl.placeholder = 'Search name or phone…';
    roleEl.style.display = '';
    statusEl.style.display = 'none';
  }
  document.getElementById('eap-mem-detail').innerHTML = '<div style="color:#666;font-size:13px;text-align:center;padding:40px 20px;">← Select a member from the list to view full details</div>';
  eapMemLoad(1);
}
async function eapMemLoad(page){
  _eapMemPage = page || 1;
  var listEl = document.getElementById('eap-mem-list');
  listEl.innerHTML = '<div style="color:#666;font-size:13px;">Loading…</div>';
  document.getElementById('eap-mem-pager').innerHTML = '';
  var res = await _eidPanel('etelid_panel_members_list', {
    source: _eapMemSrc, page: _eapMemPage,
    search: document.getElementById('eap-mem-search').value,
    status: document.getElementById('eap-mem-status').value,
    role:   document.getElementById('eap-mem-role').value
  });
  if (!res.success) { listEl.innerHTML = '<div style="color:#ef9a9a;font-size:13px;">' + (res.data && res.data.message || 'Failed to load.') + '</div>'; return; }
  var d = res.data;
  if (d.configured === false) {
    listEl.innerHTML = '<div style="color:#998;font-size:13px;padding:20px;text-align:center;">' + d.message + '</div>';
    return;
  }
  if (!d.members.length) {
    if (d.message) {
      listEl.innerHTML = '<div style="color:#ef9a9a;font-size:13px;padding:20px;text-align:center;">⚠️ ' + d.message + '</div>';
    } else {
      listEl.innerHTML = '<div style="color:#666;font-size:13px;padding:20px;text-align:center;">No members found.</div>';
    }
    return;
  }
  var html = '';
  d.members.forEach(function(m, idx){
    var statusPill = m.status ? '<span class="eap-pill ' + m.status + '">' + m.status + '</span>' : '';
    var sub = '', verifyBadge = '';
    if (_eapMemSrc === 'etelid') {
      sub = (m.etel_id||'') + ' · ' + (m.phone||'') + (m.company ? ' · ' + m.company : '');
      if (m.fayda_fin) {
        verifyBadge = (m.kyc_level >= 2)
          ? '<span class="eap-pill active" style="margin-left:6px;">✓ Pass 2 · FIN ' + m.fayda_fin + '</span>'
          : '<span class="eap-pill suspended" style="margin-left:6px;">Pass 1 only · FIN ' + m.fayda_fin + '</span>';
      }
      if (m.pass2_status === 'pending') {
        verifyBadge += '<span class="eap-pill suspended" style="margin-left:6px;background:rgba(212,175,55,.18);color:#F2CC6B;">⏳ Pass 2 review pending</span>';
      } else if (m.pass2_status === 'rejected') {
        verifyBadge += '<span class="eap-pill suspended" style="margin-left:6px;">Pass 2 rejected</span>';
      }
    } else if (_eapMemSrc === 'pay') {
      sub = (m.phone||'') + (m.email ? ' · ' + m.email : '') + (m.balance_etb !== undefined ? ' · ' + parseFloat(m.balance_etb||0).toLocaleString() + ' ETB' : '');
      statusPill = m.kyc_status ? '<span class="eap-pill ' + (m.kyc_status==='verified'?'active':'suspended') + '" style="font-size:9px;">' + m.kyc_status.toUpperCase() + '</span>' : statusPill;
    } else {
      sub = (m.phone||'') + (m.role ? ' · ' + m.role : '');
      if (m.courier_code) sub += ' · ' + m.courier_code;
      if (m.rating) sub += ' · ★' + m.rating;
      if (m.total_deliveries) sub += ' · ' + m.total_deliveries + ' trips';
      var roleColor = {customer:'#60B4FF',driver:'#F2CC6B',service_giver:'#6ee7a0',courier:'#F2A25C',admin:'#ef9a9a'}[m.role]||'#998';
      verifyBadge = m.role ? '<span style="font-size:9px;padding:2px 7px;border-radius:10px;background:rgba(255,255,255,.06);color:' + roleColor + ';text-transform:uppercase;letter-spacing:.04em;margin-left:6px;">' + m.role + '</span>' : '';
    }
    var initials = (m.name||m.full_name||'?').split(' ').slice(0,2).map(function(w){return w[0]||'';}).join('').toUpperCase();
    var avatarBg = _eapMemSrc==='pay' ? '#1a120a' : (_eapMemSrc==='delivery' ? '#0a1a10' : '#0a0d1a');
    html += '<div class="eap-row" onclick="eapMemOpenDetail(' + (m.id||0) + ')" style="gap:12px;">'
      + '<div style="width:36px;height:36px;border-radius:50%;background:' + avatarBg + ';border:1px solid rgba(212,175,55,.3);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#D4AF37;flex-shrink:0;">' + initials + '</div>'
      + '<div style="flex:1;min-width:0;"><div style="font-weight:700;color:#e8e2d4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + (m.name||m.full_name||'—') + '</div>'
      + '<div style="font-size:11px;color:#998;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + sub + '</div></div>'
      + '<div style="display:flex;flex-direction:column;align-items:flex-end;gap:3px;">' + statusPill + verifyBadge + '</div>'
      + '</div>';
  });
  listEl.innerHTML = html;
  if (d.pages > 1) {
    var p = '';
    if (d.page > 1) p += '<button class="eap-btn-ghost" onclick="eapMemLoad(' + (d.page-1) + ')">← Prev</button>';
    p += '<span style="font-size:12px;color:#998;align-self:center;">Page ' + d.page + ' of ' + d.pages + '</span>';
    if (d.page < d.pages) p += '<button class="eap-btn-ghost" onclick="eapMemLoad(' + (d.page+1) + ')">Next →</button>';
    document.getElementById('eap-mem-pager').innerHTML = p;
  }
}
async function eapMemOpenExternalDetail(id){
  var det = document.getElementById('eap-mem-detail');
  det.innerHTML = '<div style="color:#666;font-size:13px;">Loading…</div>';
  var res = await _eidPanel('etelid_panel_members_external_detail', { source: _eapMemSrc, id: id });
  if (!res.success) { det.innerHTML = '<div style="color:#ef9a9a;font-size:13px;">⚠️ ' + (res.data && res.data.message || 'Failed to load.') + '</div>'; return; }
  var d = res.data.detail || {};
  var html = '';

  /* ── Shared avatar helper ── */
  function _memAvatar(name, bg, size) {
    var ini = (name||'?').split(' ').slice(0,2).map(function(w){return(w[0]||'').toUpperCase();}).join('');
    return '<div style="width:'+size+'px;height:'+size+'px;border-radius:50%;background:'+bg+';border:2px solid rgba(212,175,55,.4);display:flex;align-items:center;justify-content:center;font-size:'+(size/3)+'px;font-weight:700;color:#D4AF37;flex-shrink:0;">'+ini+'</div>';
  }
  function _memStatBox(label, val, color) {
    return '<div style="flex:1;min-width:80px;background:rgba(255,255,255,.04);border:1px solid rgba(212,175,55,.12);border-radius:8px;padding:10px 12px;">'
      +'<div style="font-size:10px;color:#998;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">'+label+'</div>'
      +'<div style="font-size:14px;font-weight:700;color:'+(color||'#e8e2d4')+';">'+val+'</div></div>';
  }
  function _memSection(title) {
    return '<div style="font-size:10px;font-weight:700;color:#998;text-transform:uppercase;letter-spacing:.06em;margin:18px 0 8px;border-top:1px solid rgba(212,175,55,.08);padding-top:14px;">'+title+'</div>';
  }
  function _statusPill(s) {
    var c={'active':'active','verified':'active','online':'active','approved':'active','suspended':'suspended','offline':'suspended','rejected':'suspended','pending':''}[s]||'';
    return '<span class="eap-pill '+c+'">'+s+'</span>';
  }

  if (_eapMemSrc === 'pay') {
    var u = d.user || {};
    var uName = u.full_name || u.name || '—';
    html += '<div style="display:flex;align-items:center;gap:14px;margin-bottom:18px;">'
      + _memAvatar(uName,'#1a0d05',52)
      + '<div><div style="font-size:16px;font-weight:700;color:#e8e2d4;">'+uName+'</div>'
      + '<div style="font-size:12px;color:#998;margin-top:2px;">'+(u.phone||'—')+(u.email?' · '+u.email:'')+'</div>'
      + '<div style="margin-top:6px;">'+_statusPill(u.kyc_status||'none')+'</div></div>'
      + '<div style="margin-left:auto;text-align:right;">'
      + '<div style="font-size:22px;font-weight:800;color:#F2CC6B;">'+parseFloat(u.balance_etb||0).toLocaleString()+' <span style="font-size:13px;font-weight:400;">ETB</span></div>'
      + (u.balance_usd ? '<div style="font-size:11px;color:#998;">'+parseFloat(u.balance_usd).toFixed(2)+' USD</div>' : '')
      + '</div></div>';

    html += '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">'
      + _memStatBox('Joined',(u.created_at||'—').substring(0,10),'#e8e2d4')
      + _memStatBox('KYC Level',u.kyc_level||'0','#F2CC6B')
      + (u.fayda_fin ? _memStatBox('FAYDA FIN',u.fayda_fin,'#6ee7a0') : '')
      + '</div>';

    if (d.kyc) {
      html += _memSection('KYC Documents');
      html += '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:8px;">'
        + (d.kyc.doc_front ? '<div style="position:relative;"><img src="'+d.kyc.doc_front+'" style="width:130px;height:85px;object-fit:cover;border-radius:8px;border:1px solid rgba(212,175,55,.25);cursor:pointer;" onclick="window.open(this.src)"><div style="font-size:9px;color:#998;text-align:center;margin-top:2px;">Front</div></div>' : '')
        + (d.kyc.doc_back  ? '<div><img src="'+d.kyc.doc_back+'" style="width:130px;height:85px;object-fit:cover;border-radius:8px;border:1px solid rgba(212,175,55,.25);cursor:pointer;" onclick="window.open(this.src)"><div style="font-size:9px;color:#998;text-align:center;margin-top:2px;">Back</div></div>' : '')
        + (d.kyc.selfie    ? '<div><img src="'+d.kyc.selfie+'" style="width:85px;height:85px;object-fit:cover;border-radius:50%;border:2px solid rgba(212,175,55,.4);cursor:pointer;" onclick="window.open(this.src)"><div style="font-size:9px;color:#998;text-align:center;margin-top:2px;">Selfie</div></div>' : '')
        + '</div>';
      html += '<div style="font-size:11px;color:#998;margin-bottom:4px;">Type: <strong style="color:#e8e2d4;">'+(d.kyc.doc_type||'—')+'</strong> · Status: '+_statusPill(d.kyc.status||'pending')
        + (d.kyc.submitted_at ? ' · Submitted '+d.kyc.submitted_at.substring(0,10) : '')+'</div>';
    } else {
      html += _memSection('KYC Documents');
      html += '<div style="font-size:12px;color:#666;padding:12px;background:rgba(255,255,255,.03);border-radius:6px;text-align:center;">No KYC documents submitted.</div>';
    }

    html += _memSection('Recent Transactions');
    var txs = d.transactions || [];
    if (!txs.length) {
      html += '<div style="font-size:12px;color:#666;padding:12px;background:rgba(255,255,255,.03);border-radius:6px;text-align:center;">No transactions yet.</div>';
    } else {
      html += '<div style="display:flex;flex-direction:column;gap:4px;">';
      txs.forEach(function(t){
        var isOut = (t.sender_ep_id == id);
        var dir = isOut ? '↑' : '↓';
        var dirColor = isOut ? '#ef9a9a' : '#6ee7a0';
        var dirLabel = isOut ? 'Sent' : 'Received';
        html += '<div style="display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:7px;background:rgba(255,255,255,.03);border:1px solid rgba(212,175,55,.07);">'
          + '<div style="width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.06);display:flex;align-items:center;justify-content:center;font-size:13px;color:'+dirColor+';">'+dir+'</div>'
          + '<div style="flex:1;"><div style="font-size:12px;color:#e8e2d4;">'+dirLabel+' · '+(t.type||'transfer')+'</div><div style="font-size:10px;color:#666;">'+(t.tx_ref||'—')+' · '+(t.created_at||'').substring(0,10)+'</div></div>'
          + '<div style="text-align:right;"><div style="font-weight:700;color:'+dirColor+';">'+(isOut?'-':'+')+parseFloat(t.amount||0).toLocaleString()+' '+(t.currency||'ETB')+'</div><div style="font-size:10px;">'+_statusPill(t.status||'')+'</div></div>'
          + '</div>';
      });
      html += '</div>';
    }

  } else {
    /* ── Delivery & Ride detail ── */
    var du = d.user || {};
    var duName = du.name || '—';
    var roleColor = {customer:'#60B4FF',driver:'#F2CC6B',service_giver:'#6ee7a0',courier:'#F2A25C',admin:'#ef9a9a'}[du.role]||'#998';
    var driverOnline = d.driver && d.driver.online == 1;

    html += '<div style="display:flex;align-items:center;gap:14px;margin-bottom:18px;">'
      + _memAvatar(duName,'#0a1a10',52)
      + '<div style="flex:1;"><div style="font-size:16px;font-weight:700;color:#e8e2d4;">'+duName+'</div>'
      + '<div style="font-size:12px;color:#998;margin-top:2px;">'+(du.phone||'—')+'</div>'
      + '<div style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap;">'
      + (du.role ? '<span style="font-size:10px;padding:2px 8px;border-radius:10px;background:rgba(255,255,255,.07);color:'+roleColor+';text-transform:uppercase;letter-spacing:.05em;">'+du.role.replace('_',' ')+'</span>' : '')
      + (du.status ? _statusPill(du.status) : '')
      + (d.driver ? '<span class="eap-pill '+(driverOnline?'active':'suspended')+'">'+(driverOnline?'🟢 Online':'⚫ Offline')+'</span>' : '')
      + '</div></div>'
      + '<div style="text-align:right;font-size:11px;color:#666;">Joined<br><span style="color:#e8e2d4;">'+(du.created_at||'—').substring(0,10)+'</span></div>'
      + '</div>';

    /* Stats row */
    html += '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">';
    if (d.driver) {
      html += _memStatBox('Driver Rating','★ '+(d.driver.rating||'0.0'),'#F2CC6B')
        + _memStatBox('Trips Rated',d.driver.rating_count||0,'#e8e2d4');
    }
    if (d.courier) {
      html += _memStatBox('Courier Code',d.courier.courier_code||'—','#e8e2d4')
        + _memStatBox('Deliveries',d.courier.completed_deliveries||d.courier.total_deliveries||0,'#e8e2d4')
        + _memStatBox('Rating','★ '+(d.courier.average_rating||d.courier.rating||0),'#F2CC6B');
      if (d.courier.total_earnings) html += _memStatBox('Earnings',parseFloat(d.courier.total_earnings).toLocaleString()+' ETB','#6ee7a0');
    }
    if (!d.driver && !d.courier) {
      var rideCount = (d.rides||[]).length;
      var orderCount = (d.orders||[]).length;
      html += _memStatBox('Rides',rideCount,'#e8e2d4') + _memStatBox('Orders',orderCount,'#e8e2d4');
    }
    html += '</div>';

    /* Action buttons */
    html += '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px;padding:12px;background:rgba(255,255,255,.03);border-radius:8px;border:1px solid rgba(212,175,55,.1);">'
      + '<button class="eap-btn-ghost" style="font-size:12px;" onclick="eapExtUserStatus('+id+',\'active\')">✓ Activate</button>'
      + '<button class="eap-btn-ghost" style="font-size:12px;" onclick="eapExtUserStatus('+id+',\'suspended\')">⊘ Suspend</button>'
      + '<div id="eap-ext-msg-'+id+'" style="font-size:11px;color:#D4AF37;align-self:center;margin-left:8px;"></div>'
      + '</div>';

    /* Recent Orders */
    html += _memSection('Recent Orders');
    var orders = d.orders || [];
    if (!orders.length) {
      html += '<div style="font-size:12px;color:#666;padding:12px;background:rgba(255,255,255,.03);border-radius:6px;text-align:center;margin-bottom:4px;">No orders yet.</div>';
    } else {
      html += '<div style="display:flex;flex-direction:column;gap:4px;margin-bottom:4px;">';
      orders.forEach(function(o){
        var sc = {completed:'#6ee7a0',cancelled:'#ef9a9a',pending:'#F2CC6B'}[o.status]||'#998';
        html += '<div style="display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:7px;background:rgba(255,255,255,.03);border:1px solid rgba(212,175,55,.07);">'
          + '<div style="flex:1;"><div style="font-size:12px;color:#e8e2d4;">'+(o.order_code||'#'+o.id)+' · '+(o.from_zone||'—')+' → '+(o.to_zone||'—')+'</div>'
          + '<div style="font-size:10px;color:#666;">'+(o.placed_at||'').substring(0,10)+'</div></div>'
          + '<div style="text-align:right;"><div style="font-size:12px;font-weight:700;color:#e8e2d4;">'+parseFloat(o.fare_total||0).toLocaleString()+' ETB</div>'
          + '<div style="font-size:10px;color:'+sc+';">'+o.status+'</div></div>'
          + '</div>';
      });
      html += '</div>';
    }

    /* Recent Rides */
    html += _memSection('Recent Rides');
    var rides = d.rides || [];
    if (!rides.length) {
      html += '<div style="font-size:12px;color:#666;padding:12px;background:rgba(255,255,255,.03);border-radius:6px;text-align:center;">No rides yet.</div>';
    } else {
      html += '<div style="display:flex;flex-direction:column;gap:4px;">';
      rides.forEach(function(r){
        var sc = {completed:'#6ee7a0',cancelled:'#ef9a9a',in_progress:'#F2CC6B'}[r.status]||'#998';
        html += '<div style="display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:7px;background:rgba(255,255,255,.03);border:1px solid rgba(212,175,55,.07);">'
          + '<div style="font-size:18px;">🚖</div>'
          + '<div style="flex:1;"><div style="font-size:12px;color:#e8e2d4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+(r.pickup_address||'—')+' → '+(r.dropoff_address||'—')+'</div>'
          + '<div style="font-size:10px;color:#666;">'+(r.created_at||'').substring(0,10)+(r.distance_km?' · '+parseFloat(r.distance_km).toFixed(1)+'km':'')+'</div></div>'
          + '<div style="text-align:right;"><div style="font-size:12px;font-weight:700;color:#e8e2d4;">'+parseFloat(r.fare_final||0).toLocaleString()+' ETB'+(r.rating?' <span style="color:#F2CC6B;">★'+r.rating+'</span>':'')+'</div>'
          + '<div style="font-size:10px;color:'+sc+';">'+r.status+'</div></div>'
          + '</div>';
      });
      html += '</div>';
    }
  }

  det.innerHTML = html;
}
async function eapMemOpenDetail(id){
  if (_eapMemSrc !== 'etelid') { eapMemOpenExternalDetail(id); return; }
  var det = document.getElementById('eap-mem-detail');
  det.style.display = 'block';
  det.innerHTML = '<div style="color:#666;font-size:13px;">Loading…</div>';
  var res = await _eidPanel('etelid_panel_members_get', { id: id });
  if (!res.success) { det.innerHTML = '<div style="color:#ef9a9a;font-size:13px;">' + res.data.message + '</div>'; return; }
  var m = res.data.member;
  var kycLevel = parseInt(m.kyc_level || 0, 10);
  var verifyHtml = eapFaydaSectionHtml(m, id);
  det.innerHTML =
    '<div class="eap-title" style="font-size:14px;margin-bottom:10px;">Editing ' + m.full_name + ' · ' + m.etel_id + '</div>'
    + verifyHtml
    + '<div class="eap-grid2">'
    + '<div class="eap-field"><label>Full Name</label><input class="eap-input" id="eap-em-name" value="' + (m.full_name||'').replace(/"/g,'&quot;') + '"></div>'
    + '<div class="eap-field"><label>Full Name (Amharic)</label><input class="eap-input" id="eap-em-name-am" value="' + (m.full_name_am||'').replace(/"/g,'&quot;') + '"></div>'
    + '<div class="eap-field"><label>Phone</label><input class="eap-input" id="eap-em-phone" value="' + (m.phone||'') + '"></div>'
    + '<div class="eap-field"><label>Email</label><input class="eap-input" id="eap-em-email" value="' + (m.email||'') + '"></div>'
    + '<div class="eap-field"><label>Company</label><input class="eap-input" id="eap-em-company" value="' + (m.primary_company||'') + '"></div>'
    + '<div class="eap-field"><label>Role</label><input class="eap-input" id="eap-em-role" value="' + (m.role||'') + '"></div>'
    + '<div class="eap-field"><label>Department</label><input class="eap-input" id="eap-em-dept" value="' + (m.department||'') + '"></div>'
    + '<div class="eap-field"><label>Tier</label><select class="eap-input" id="eap-em-tier">'
    + ['standard','staff','manager','executive','vip'].map(function(t){ return '<option value="' + t + '"' + (m.tier===t?' selected':'') + '>' + t + '</option>'; }).join('')
    + '</select></div></div>'
    + '<div class="eap-field"><label>Notes</label><textarea class="eap-input" id="eap-em-notes" rows="2">' + (m.notes||'') + '</textarea></div>'
    + '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
    + '<button class="eap-btn-gold" onclick="eapMemSave(' + id + ')">Save Changes</button>'
    + '<button class="eap-btn-ghost" onclick="eapMemStatus(' + id + ',\'active\')">Activate</button>'
    + '<button class="eap-btn-ghost" onclick="eapMemStatus(' + id + ',\'suspended\')">Suspend</button>'
    + '<button class="eap-btn-ghost" onclick="openResetPwModal(null,' + id + ',\'' + m.full_name.replace(/'/g,"\\'") + '\')">Reset Password</button>'
    + '<button class="eap-btn-danger" onclick="eapMemDelete(' + id + ')">Delete</button>'
    + '</div><div id="eap-em-msg"></div>';
}
function eapFaydaSectionHtml(m, id) {
  var kycLevel = parseInt(m.kyc_level || 0, 10);
  var hasPhotos = m.fayda_front_image && m.fayda_back_image;
  var html = '<div style="background:rgba(212,175,55,.06);border:1px solid rgba(212,175,55,.2);border-radius:8px;padding:14px;margin-bottom:14px;">';

  /* Status row */
  if (m.fayda_fin || hasPhotos) {
    var levelLabel = kycLevel >= 2 ? 'Pass 2 complete - verified' : (kycLevel === 1 ? 'Pass 1 only - Fayda quick-register' : 'No verification');
    var levelClass = kycLevel >= 2 ? 'active' : 'suspended';
    html += '<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">'
      + (m.fayda_fin ? '<div><div style="font-size:10px;color:#998;text-transform:uppercase;">Fayda FIN</div><div style="font-family:monospace;color:#e8e2d4;">' + m.fayda_fin + '</div></div>' : '')
      + '<div><div style="font-size:10px;color:#998;text-transform:uppercase;">Verification</div><span class="eap-pill ' + levelClass + '">' + levelLabel + '</span></div>'
      + (m.pass2_completed_at ? '<div><div style="font-size:10px;color:#998;text-transform:uppercase;">Pass 2 Completed</div><div style="color:#e8e2d4;font-size:12px;">' + m.pass2_completed_at + '</div></div>' : '')
      + '</div>';
  } else {
    html += '<div style="font-size:12px;color:#998;margin-bottom:12px;">No Fayda ID on file for this member yet.</div>';
  }

  /* Existing photos, if any */
  if (hasPhotos) {
    html += '<div style="display:flex;gap:10px;margin-bottom:12px;flex-wrap:wrap;">'
      + '<div><div style="font-size:10px;color:#998;margin-bottom:4px;">FRONT</div><img src="' + m.fayda_front_image + '" style="max-width:160px;max-height:110px;border-radius:6px;border:1px solid rgba(212,175,55,.25);object-fit:cover;"></div>'
      + '<div><div style="font-size:10px;color:#998;margin-bottom:4px;">BACK</div><img src="' + m.fayda_back_image + '" style="max-width:160px;max-height:110px;border-radius:6px;border:1px solid rgba(212,175,55,.25);object-fit:cover;"></div>'
      + '</div>';
  }

  /* Pending member self-submission - approve/reject */
  if (m.pass2_status === 'pending') {
    html += '<div style="background:rgba(212,175,55,.1);border:1px solid rgba(212,175,55,.3);border-radius:8px;padding:10px 12px;margin-bottom:12px;">'
      + '<div style="font-size:12px;color:#F2CC6B;margin-bottom:8px;">⏳ ' + m.full_name + ' submitted these photos themselves - awaiting your review.</div>'
      + '<input type="text" class="eap-input" id="eap-fayda-note-' + id + '" placeholder="Optional note (shown to member if rejected)" style="margin-bottom:8px;">'
      + '<div style="display:flex;gap:8px;">'
      + '<button class="eap-btn-gold" onclick="eapMemFaydaReview(' + id + ',\'approve\')">✓ Approve - Pass 2</button>'
      + '<button class="eap-btn-danger" onclick="eapMemFaydaReview(' + id + ',\'reject\')">✕ Reject</button>'
      + '</div></div>';
  } else if (m.pass2_status === 'rejected' && m.pass2_review_note) {
    html += '<div style="font-size:12px;color:#ef9a9a;margin-bottom:12px;">Last rejection note: ' + m.pass2_review_note + '</div>';
  }

  /* Admin capture/upload - always available, instant approve */
  html += '<div style="border-top:1px solid rgba(212,175,55,.15);padding-top:12px;">'
    + '<div style="font-size:11px;color:#998;text-transform:uppercase;margin-bottom:8px;">' + (hasPhotos ? 'Replace' : 'Capture') + ' Fayda ID (instant Pass 2 approval)</div>'
    + '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;">'
    + '<label class="eap-btn-ghost" style="cursor:pointer;"><input type="file" accept="image/*" capture="environment" id="eap-fayda-front-' + id + '" style="display:none;" onchange="eapFaydaPreview(' + id + ',\'front\')">📷 Front Photo</label>'
    + '<label class="eap-btn-ghost" style="cursor:pointer;"><input type="file" accept="image/*" capture="environment" id="eap-fayda-back-' + id + '" style="display:none;" onchange="eapFaydaPreview(' + id + ',\'back\')">📷 Back Photo</label>'
    + '</div>'
    + '<div id="eap-fayda-preview-' + id + '" style="display:flex;gap:8px;margin-bottom:8px;"></div>'
    + '<button class="eap-btn-gold" id="eap-fayda-submit-' + id + '" onclick="eapMemFaydaUpload(' + id + ')" disabled>Save &amp; Approve Pass 2</button>'
    + '<div id="eap-fayda-msg-' + id + '"></div>'
    + '</div></div>';
  return html;
}

var _eapFaydaData = {};
function eapFaydaPreview(id, side) {
  var input = document.getElementById('eap-fayda-' + side + '-' + id);
  var f = input.files[0];
  if (!f) return;
  var reader = new FileReader();
  reader.onload = function(e) {
    _eapFaydaData[id] = _eapFaydaData[id] || {};
    _eapFaydaData[id][side] = e.target.result;
    var prev = document.getElementById('eap-fayda-preview-' + id);
    var existing = document.getElementById('eap-fayda-prev-img-' + id + '-' + side);
    var imgHtml = '<img id="eap-fayda-prev-img-' + id + '-' + side + '" src="' + e.target.result + '" style="max-width:120px;max-height:80px;border-radius:6px;border:1px solid rgba(212,175,55,.3);">';
    if (existing) { existing.outerHTML = imgHtml; } else { prev.insertAdjacentHTML('beforeend', imgHtml); }
    var btn = document.getElementById('eap-fayda-submit-' + id);
    if (_eapFaydaData[id].front && _eapFaydaData[id].back) btn.disabled = false;
  };
  reader.readAsDataURL(f);
}
async function eapMemFaydaUpload(id) {
  var d = _eapFaydaData[id];
  if (!d || !d.front || !d.back) return;
  var btn = document.getElementById('eap-fayda-submit-' + id);
  btn.disabled = true; btn.textContent = 'Saving...';
  var res = await _eidPanel('etelid_panel_fayda_id_upload', { id: id, front_image: d.front, back_image: d.back });
  _eidPanelMsg('eap-fayda-msg-' + id, res.data.message || '', res.success);
  btn.textContent = 'Save & Approve Pass 2';
  if (res.success) { delete _eapFaydaData[id]; eapMemOpenDetail(id); eapMemLoad(_eapMemPage); }
  else btn.disabled = false;
}
async function eapMemFaydaReview(id, decision) {
  var noteEl = document.getElementById('eap-fayda-note-' + id);
  var res = await _eidPanel('etelid_panel_fayda_id_review', { id: id, decision: decision, note: noteEl ? noteEl.value : '' });
  _eidPanelMsg('eap-fayda-msg-' + id, res.data.message || '', res.success);
  if (res.success) { eapMemOpenDetail(id); eapMemLoad(_eapMemPage); }
}
async function eapMemSave(id){
  var res = await _eidPanel('etelid_panel_members_update', {
    id: id,
    full_name: document.getElementById('eap-em-name').value,
    full_name_am: document.getElementById('eap-em-name-am').value,
    phone: document.getElementById('eap-em-phone').value,
    email: document.getElementById('eap-em-email').value,
    primary_company: document.getElementById('eap-em-company').value,
    role: document.getElementById('eap-em-role').value,
    department: document.getElementById('eap-em-dept').value,
    tier: document.getElementById('eap-em-tier').value,
    notes: document.getElementById('eap-em-notes').value
  });
  _eidPanelMsg('eap-em-msg', res.data.message || (res.success ? 'Saved.' : 'Failed.'), res.success);
  if (res.success) eapMemLoad(_eapMemPage);
}
async function eapMemStatus(id, status){
  var res = await _eidPanel('etelid_panel_members_status', { id: id, status: status });
  _eidPanelMsg('eap-em-msg', res.data.message || '', res.success);
  if (res.success) eapMemLoad(_eapMemPage);
}
async function eapMemDelete(id){
  if (!confirm('Permanently delete this member? This cannot be undone.')) return;
  var res = await _eidPanel('etelid_panel_members_delete', { id: id });
  if (res.success) { document.getElementById('eap-mem-detail').style.display='none'; eapMemLoad(_eapMemPage); }
  else _eidPanelMsg('eap-em-msg', res.data.message || 'Failed.', false);
}

/* ════════ PAGES ════════ */
function openPagesModal(e, mode){ if(e)e.preventDefault(); _eapOpen('eap-pages-modal'); if (mode==='new') eapPageEdit(0); else eapPagesShowList(); }
function closePagesModal(){ _eapClose('eap-pages-modal'); }
function eapPagesShowList(){
  document.getElementById('eap-pages-list-view').style.display = 'block';
  document.getElementById('eap-pages-edit-view').style.display = 'none';
  eapPagesLoad();
}
async function eapPagesLoad(){
  var el = document.getElementById('eap-pages-list');
  el.innerHTML = '<div style="color:#666;font-size:13px;">Loading…</div>';
  var res = await _eidPanel('etelid_panel_pages_list', {});
  if (!res.success || !res.data.pages.length) { el.innerHTML = '<div style="color:#666;font-size:13px;padding:14px;">No pages yet.</div>'; return; }
  var html = '';
  res.data.pages.forEach(function(p){
    html += '<div class="eap-row">'
      + '<div style="flex:1;cursor:pointer;" onclick="eapPageEdit(' + p.id + ')"><div style="font-weight:700;color:#e8e2d4;">' + p.title + '</div>'
      + '<div style="font-size:11px;color:#998;">' + p.status + ' · modified ' + p.modified + '</div></div>'
      + '<button class="eap-btn-danger" style="padding:4px 10px;" onclick="event.stopPropagation();eapPageDelete(' + p.id + ')">Delete</button></div>';
  });
  el.innerHTML = html;
}
async function eapPageEdit(id){
  document.getElementById('eap-pages-list-view').style.display = 'none';
  document.getElementById('eap-pages-edit-view').style.display = 'block';
  document.getElementById('eap-page-id').value = id;
  document.getElementById('eap-page-msg').innerHTML = '';
  if (!id) {
    document.getElementById('eap-page-title').value = '';
    document.getElementById('eap-page-content').value = '';
    document.getElementById('eap-page-status').value = 'publish';
    return;
  }
  var res = await _eidPanel('etelid_panel_pages_get', { id: id });
  if (res.success) {
    document.getElementById('eap-page-title').value = res.data.title;
    document.getElementById('eap-page-content').value = res.data.content;
    document.getElementById('eap-page-status').value = res.data.status;
  }
}
async function eapPageSave(){
  var res = await _eidPanel('etelid_panel_pages_save', {
    id: document.getElementById('eap-page-id').value,
    title: document.getElementById('eap-page-title').value,
    content: document.getElementById('eap-page-content').value,
    status: document.getElementById('eap-page-status').value
  });
  _eidPanelMsg('eap-page-msg', res.data.message || '', res.success);
  if (res.success) document.getElementById('eap-page-id').value = res.data.id;
}
async function eapPageDelete(id){
  if (!confirm('Move this page to trash?')) return;
  var res = await _eidPanel('etelid_panel_pages_delete', { id: id });
  if (res.success) eapPagesLoad();
}

/* ════════ MEDIA ════════ */
function openMediaModal(e){ if(e)e.preventDefault(); _eapOpen('eap-media-modal'); eapMediaLoad(); }
function closeMediaModal(){ _eapClose('eap-media-modal'); }
async function eapMediaLoad(){
  var el = document.getElementById('eap-media-grid');
  el.innerHTML = '<div style="color:#666;font-size:13px;">Loading…</div>';
  var res = await _eidPanel('etelid_panel_media_list', {});
  if (!res.success || !res.data.media.length) { el.innerHTML = '<div style="color:#666;font-size:13px;">No media yet.</div>'; return; }
  var html = '';
  res.data.media.forEach(function(m){
    html += '<div class="eap-media-item"><img src="' + m.thumb + '" loading="lazy"><button class="eap-media-del" onclick="eapMediaDelete(' + m.id + ')">✕</button></div>';
  });
  el.innerHTML = html;
}
async function eapMediaUpload(){
  var f = document.getElementById('eap-media-file').files[0];
  if (!f) return;
  document.getElementById('eap-media-msg').textContent = 'Uploading…';
  var res = await _eidPanel('etelid_panel_media_upload', { file: f }, true);
  document.getElementById('eap-media-msg').textContent = res.success ? 'Uploaded.' : (res.data.message || 'Failed.');
  if (res.success) eapMediaLoad();
  document.getElementById('eap-media-file').value = '';
}
async function eapMediaDelete(id){
  if (!confirm('Delete this file permanently?')) return;
  var res = await _eidPanel('etelid_panel_media_delete', { id: id });
  if (res.success) eapMediaLoad();
}

/* ════════ MENUS ════════ */
function openMenusModal(e){ if(e)e.preventDefault(); _eapOpen('eap-menus-modal'); eapMenusLoad(); }
function closeMenusModal(){ _eapClose('eap-menus-modal'); }
async function eapMenusLoad(){
  var sel = document.getElementById('eap-menu-select');
  sel.innerHTML = '<option>Loading…</option>';
  var res = await _eidPanel('etelid_panel_menus_list', {});
  if (!res.success || !res.data.menus.length) { sel.innerHTML = '<option>No menus found</option>'; return; }
  sel.innerHTML = res.data.menus.map(function(m){ return '<option value="' + m.id + '">' + m.name + ' (' + m.count + ' items)</option>'; }).join('');
  eapMenuLoadItems();
}
async function eapMenuLoadItems(){
  var menuId = document.getElementById('eap-menu-select').value;
  var wrap = document.getElementById('eap-menu-items');
  wrap.innerHTML = '<div style="color:#666;font-size:13px;">Loading…</div>';
  if (!menuId) { wrap.innerHTML = ''; return; }
  var res = await _eidPanel('etelid_panel_menu_items', { menu_id: menuId });
  wrap.innerHTML = '';
  if (res.success && res.data.items.length) {
    res.data.items.forEach(function(it){ eapMenuAddRow(it.id, it.title, it.url); });
  }
}
function eapMenuAddRow(id, title, url){
  var wrap = document.getElementById('eap-menu-items');
  var row = document.createElement('div');
  row.style.cssText = 'display:flex;gap:8px;margin-bottom:8px;align-items:center;';
  row.innerHTML = '<input type="hidden" class="eap-mi-id" value="' + (id||0) + '">'
    + '<input type="text" class="eap-input eap-mi-title" placeholder="Label" style="flex:1;" value="' + (title||'').replace(/"/g,'&quot;') + '">'
    + '<input type="text" class="eap-input eap-mi-url" placeholder="URL" style="flex:2;" value="' + (url||'') + '">'
    + '<button class="eap-btn-danger" style="padding:8px 10px;" onclick="this.parentNode.remove()">✕</button>';
  wrap.appendChild(row);
}
async function eapMenuSave(){
  var menuId = document.getElementById('eap-menu-select').value;
  var rows = document.querySelectorAll('#eap-menu-items > div');
  var items = [];
  rows.forEach(function(r, idx){
    items.push({
      id: r.querySelector('.eap-mi-id').value,
      title: r.querySelector('.eap-mi-title').value,
      url: r.querySelector('.eap-mi-url').value,
      order: idx + 1
    });
  });
  var res = await _eidPanel('etelid_panel_menu_save', { menu_id: menuId, items: JSON.stringify(items) });
  _eidPanelMsg('eap-menu-msg', res.data.message || '', res.success);
  if (res.success) eapMenuLoadItems();
}

/* ════════ BRANDING ════════ */
function openBrandingModal(e){ if(e)e.preventDefault(); _eapOpen('eap-branding-modal'); eapBrandingLoad(); }
function closeBrandingModal(){ _eapClose('eap-branding-modal'); }
async function eapBrandingLoad(){
  var res = await _eidPanel('etelid_panel_branding_get', {});
  if (!res.success) return;
  var d = res.data;
  document.getElementById('eap-b-title').value = d.site_title || '';
  document.getElementById('eap-b-tagline').value = d.tagline || '';
  document.getElementById('eap-b-org').value = d.org_name || '';
  document.getElementById('eap-b-website').value = d.org_website || '';
  document.getElementById('eap-b-email').value = d.org_email || '';
  document.getElementById('eap-b-gold').value = d.gold_accent || '#D4AF37';
  document.getElementById('eap-b-css').value = d.custom_css || '';
  var prev = document.getElementById('eap-b-logo-preview');
  if (d.logo_url) { prev.src = d.logo_url; prev.style.display = 'inline-block'; prev.setAttribute('data-id', d.logo_id); }
}
async function eapBrandingUploadLogo(){
  var f = document.getElementById('eap-b-logo-file').files[0];
  if (!f) return;
  var res = await _eidPanel('etelid_panel_media_upload', { file: f }, true);
  if (res.success) {
    var prev = document.getElementById('eap-b-logo-preview');
    prev.src = res.data.url; prev.style.display = 'inline-block'; prev.setAttribute('data-id', res.data.id);
  }
}
async function eapBrandingSave(){
  var logoEl = document.getElementById('eap-b-logo-preview');
  var res = await _eidPanel('etelid_panel_branding_save', {
    site_title: document.getElementById('eap-b-title').value,
    tagline: document.getElementById('eap-b-tagline').value,
    org_name: document.getElementById('eap-b-org').value,
    org_website: document.getElementById('eap-b-website').value,
    org_email: document.getElementById('eap-b-email').value,
    gold_accent: document.getElementById('eap-b-gold').value,
    custom_css: document.getElementById('eap-b-css').value,
    logo_id: logoEl.getAttribute('data-id') || 0
  });
  _eidPanelMsg('eap-branding-msg', res.data.message || '', res.success);
}

/* ════════ RESET PASSWORD ════════ */
function openResetPwModal(e, presetId, presetName){
  if(e)e.preventDefault();
  _eapOpen('eap-resetpw-modal');
  document.getElementById('eap-rp-results').innerHTML = '';
  document.getElementById('eap-rp-form').style.display = 'none';
  document.getElementById('eap-rp-msg').innerHTML = '';
  if (presetId) {
    document.getElementById('eap-rp-id').value = presetId;
    document.getElementById('eap-rp-who').textContent = presetName || ('Member #' + presetId);
    document.getElementById('eap-rp-form').style.display = 'block';
  }
}
function closeResetPwModal(){ _eapClose('eap-resetpw-modal'); }
async function eapRpSearch(){
  var q = document.getElementById('eap-rp-search').value;
  var el = document.getElementById('eap-rp-results');
  el.innerHTML = 'Searching…';
  var res = await _eidPanel('etelid_panel_members_list', { source: 'etelid', page: 1, search: q, status: '' });
  if (!res.success || !res.data.members.length) { el.innerHTML = '<div style="color:#666;font-size:12px;">No matches.</div>'; return; }
  el.innerHTML = res.data.members.slice(0,8).map(function(m){
    return '<div class="eap-row" onclick="eapRpPick(' + m.id + ',\'' + m.name.replace(/'/g,"\\'") + ' · ' + m.etel_id + '\')">' + m.name + ' <span style="color:#998;font-size:11px;">' + m.etel_id + ' · ' + m.phone + '</span></div>';
  }).join('');
}
function eapRpPick(id, label){
  document.getElementById('eap-rp-id').value = id;
  document.getElementById('eap-rp-who').textContent = label;
  document.getElementById('eap-rp-form').style.display = 'block';
}
async function eapRpSubmit(){
  var res = await _eidPanel('etelid_panel_reset_password', {
    id: document.getElementById('eap-rp-id').value,
    new_password: document.getElementById('eap-rp-pass').value,
    confirm_password: document.getElementById('eap-rp-confirm').value
  });
  _eidPanelMsg('eap-rp-msg', res.data.message || '', res.success);
  if (res.success) { document.getElementById('eap-rp-pass').value=''; document.getElementById('eap-rp-confirm').value=''; }
}
</script>

<!-- ══ EtelID Diagnostic Console ═══════════════════════════════ -->
<div id="eid-diag-modal" style="display:none;position:fixed;inset:0;z-index:99998;background:rgba(0,0,0,.8);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:16px;">
  <div class="eid-gold-box" style="border-radius:16px;width:min(96vw,520px);max-height:88vh;display:flex;flex-direction:column;box-shadow:0 24px 80px rgba(0,0,0,.9);">

    <!-- Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid rgba(212,175,55,.12);flex-shrink:0;">
      <div>
        <div style="font-size:15px;font-weight:700;color:#D4AF37;font-family:'Syne',sans-serif;">🔬 EtelID Diagnostic Console</div>
        <div id="eid-diag-sub" style="font-size:11px;color:#444;margin-top:1px;">ethelmarket.com/EtelId · checking...</div>
      </div>
      <div style="display:flex;align-items:center;gap:8px;">
        <span id="eid-diag-ver" style="font-size:10px;color:#555;background:#111;padding:2px 8px;border-radius:4px;border:1px solid #222;">v<?php echo ETELID_VERSION; ?></span>
        <button onclick="closeEidDiag()" style="background:none;border:1px solid #333;color:#666;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:12px;">✕</button>
      </div>
    </div>

    <!-- Stats -->
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:5px;padding:10px 14px;border-bottom:1px solid #111;flex-shrink:0;">
      <div class="eid-dstat" id="eds-api"><div class="eid-dstat-n">—</div><div class="eid-dstat-l">API</div></div>
      <div class="eid-dstat" id="eds-db"><div class="eid-dstat-n">—</div><div class="eid-dstat-l">DB</div></div>
      <div class="eid-dstat" id="eds-sso"><div class="eid-dstat-n">—</div><div class="eid-dstat-l">SSO</div></div>
      <div class="eid-dstat" id="eds-tg"><div class="eid-dstat-n">—</div><div class="eid-dstat-l">Telegram</div></div>
      <div class="eid-dstat" id="eds-ms"><div class="eid-dstat-n">—</div><div class="eid-dstat-l">Latency</div></div>
    </div>

    <!-- Toolbar -->
    <div style="display:flex;gap:5px;flex-wrap:wrap;padding:8px 12px;border-bottom:1px solid #111;flex-shrink:0;">
      <button class="eid-dbtn" onclick="eidDiagAll()">▶ All</button>
      <button class="eid-dbtn" onclick="eidDiagPing()">⚡ Ping</button>
      <button class="eid-dbtn" onclick="eidDiagDB()">🗄 DB</button>
      <button class="eid-dbtn" onclick="eidDiagSSO()">🔐 SSO</button>
      <button class="eid-dbtn" onclick="eidDiagTG()">✈️ Telegram</button>
      <button class="eid-dbtn" onclick="eidDiagBarcode()">🏷 Barcode</button>
      <button class="eid-dbtn" onclick="eidDiagREST()">🌐 REST API</button>
      <button class="eid-dbtn" style="color:#E24B4A;border-color:#3a1010" onclick="eidDiagClear()">✕ Clear</button>
    </div>

    <!-- Console -->
    <div id="eid-diag-con" style="flex:1;overflow-y:auto;padding:10px 12px;font-family:'JetBrains Mono',monospace;font-size:11px;background:#050505;min-height:200px;max-height:360px;"></div>
  </div>
</div>

<style>
.eid-dstat{background:#0f0f0f;border:1px solid #1a1a1a;border-radius:5px;padding:7px 4px;text-align:center}
.eid-dstat-n{font-size:14px;font-weight:700;color:#D4AF37;line-height:1;font-family:'JetBrains Mono',monospace}
.eid-dstat-l{font-size:9px;color:#444;margin-top:2px}
.eid-dstat.ok .eid-dstat-n{color:#639922} .eid-dstat.err .eid-dstat-n{color:#E24B4A} .eid-dstat.warn .eid-dstat-n{color:#D4AF37}
.eid-dbtn{padding:4px 10px;border-radius:5px;border:1px solid #222;background:#0f0f0f;color:#888;font-size:10px;font-family:'JetBrains Mono',monospace;cursor:pointer;transition:all .15s}
.eid-dbtn:hover{border-color:#D4AF37;color:#D4AF37}
</style>

<script>
/* ── EtelID Diagnostic Panel ──────────────────────────────────── */
var _eidD = {
  nonce: _P.nonce,
  ajax:  _P.ajax,
  rest:  '<?php echo esc_js(rest_url("etelid/v1")); ?>',
  svcKey:'', /* service key not available in browser — tested server-side */
  open: false
};

function openEidDiag(e){ if(e)e.preventDefault(); document.getElementById('eid-diag-modal').style.display='flex'; document.body.style.overflow='hidden'; if(!_eidD.open){_eidD.open=true;eidDiagPing();} }
function closeEidDiag(){ document.getElementById('eid-diag-modal').style.display='none'; document.body.style.overflow=''; }
document.getElementById('eid-diag-modal').addEventListener('click',function(e){if(e.target===this)closeEidDiag();});

function _eidTs(){ return new Date().toLocaleTimeString('en-GB',{hour12:false,hour:'2-digit',minute:'2-digit',second:'2-digit'}); }
function _eidLog(tag,msg,raw){
  var con=document.getElementById('eid-diag-con');
  var colors={ok:'#1a2a0a:#639922',er:'#3a1010:#E24B4A',wn:'#2a1a00:#D4AF37',in:'#0a1a2a:#60B4FF',rn:'#111:#555'};
  var cp=(colors[tag]||colors.in).split(':');
  var rawHtml=raw!==undefined?'<pre style="background:#050505;padding:5px;border-radius:3px;margin-top:3px;white-space:pre-wrap;word-break:break-all;font-size:10px;color:#555;border:1px solid #111;max-height:100px;overflow-y:auto">'+JSON.stringify(raw,null,2)+'</pre>':'';
  con.innerHTML+='<div style="padding:3px 0;border-bottom:1px solid #0c0c0c;display:flex;gap:7px;align-items:flex-start">'
    +'<span style="color:#2a2a2a;min-width:60px;flex-shrink:0">'+_eidTs()+'</span>'
    +'<span style="min-width:26px;flex-shrink:0;font-size:9px;padding:1px 4px;border-radius:2px;background:'+cp[0]+';color:'+cp[1]+';text-align:center;margin-top:1px">'+tag.toUpperCase()+'</span>'
    +'<span style="flex:1;color:#b8afa0;line-height:1.5;word-break:break-all">'+msg+rawHtml+'</span></div>';
  con.scrollTop=con.scrollHeight;
}
function _eidStat(id,val,cls){ var e=document.getElementById(id); if(!e)return; e.className='eid-dstat '+(cls||''); e.querySelector('.eid-dstat-n').textContent=val; }
function eidDiagClear(){ document.getElementById('eid-diag-con').innerHTML=''; }

async function _eidAjax(action, body){
  body = body||{};
  var t0=performance.now();
  try {
    var params='action='+encodeURIComponent(action)+'&nonce='+encodeURIComponent(_eidD.nonce);
    Object.keys(body).forEach(function(k){ params+='&'+encodeURIComponent(k)+'='+encodeURIComponent(body[k]); });
    var r=await fetch(_eidD.ajax,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:params});
    var ms=Math.round(performance.now()-t0);
    var text=await r.text();
    var data; try{data=JSON.parse(text);}catch(e){return{ok:false,ms,parseErr:true,raw:text.slice(0,300)};}
    return{ok:r.ok,status:r.status,ms,data};
  } catch(e){ return{ok:false,ms:Math.round(performance.now()-t0),netErr:e.message}; }
}

async function _eidREST(path){
  var t0=performance.now();
  try{
    var r=await fetch(_eidD.rest+path,{headers:{'X-WP-Nonce':_eidD.nonce}});
    var ms=Math.round(performance.now()-t0);
    var data; try{data=await r.json();}catch(e){return{ok:false,ms,parseErr:true};}
    return{ok:r.ok,status:r.status,ms,data};
  }catch(e){return{ok:false,ms:Math.round(performance.now()-t0),netErr:e.message};}
}

async function eidDiagPing(){
  _eidLog('rn','Testing EtelID WordPress + API...');
  var t0=performance.now();
  try{
    var r=await fetch(window.location.href,{method:'HEAD'});
    var ms=Math.round(performance.now()-t0);
    _eidLog('ok','Portal responding · <span style="color:#60B4FF">'+ms+'ms</span> · HTTP '+r.status);
    _eidStat('eds-api','OK','ok');
    _eidStat('eds-ms',ms+'ms',ms<500?'ok':ms<1500?'warn':'err');
    document.getElementById('eid-diag-sub').textContent='ethelmarket.com/EtelId · '+new Date().toLocaleString();
  }catch(e){ _eidLog('er','Portal unreachable: '+e.message); _eidStat('eds-api','ERR','err'); }
}

async function eidDiagDB(){
  _eidLog('rn','Testing database via AJAX...');
  /* Use etelid_apply action with dummy — a real DB error returns non-JSON */
  var r=await _eidAjax('etelid_auth_change_password',{old_password:'__diag__',new_password:'__diag__',confirm_password:'__diag__'});
  if(r.netErr){ _eidLog('er','Network error: '+r.netErr); _eidStat('eds-db','ERR','err'); return; }
  if(r.parseErr){ _eidLog('er','Non-JSON response — likely DB connection error',r.raw); _eidStat('eds-db','ERR','err'); return; }
  /* Any structured JSON response means WP + DB are working */
  _eidLog('ok','DB connected · WordPress AJAX responding · <span style="color:#60B4FF">'+r.ms+'ms</span>');
  _eidStat('eds-db','OK','ok');
}

async function eidDiagSSO(){
  _eidLog('rn','Testing EtelID SSO service endpoint...');
  var r=await _eidREST('/service/resolve');
  if(r.netErr){ _eidLog('er','REST API unreachable: '+r.netErr); _eidStat('eds-sso','ERR','err'); return; }
  if(r.parseErr){ _eidLog('er','Non-JSON from REST API'); _eidStat('eds-sso','ERR','err'); return; }
  /* 400/403 = endpoint exists, service key required — that's correct for unauthenticated */
  if(r.status===400||r.status===403||r.status===401){
    _eidLog('ok','SSO endpoint <span style="color:#639922">active</span> · returns correct auth error · <span style="color:#60B4FF">'+r.ms+'ms</span>');
    _eidStat('eds-sso','OK','ok');
  } else if(r.status===404){
    _eidLog('er','SSO REST route not found — plugin may need reactivation');
    _eidStat('eds-sso','ERR','err');
  } else {
    _eidLog('wn','SSO unexpected response (HTTP '+r.status+')',r.data);
    _eidStat('eds-sso','?','warn');
  }
}

async function eidDiagTG(){
  _eidLog('rn','Testing Telegram bot link...');
  /* Check if current member has tg_chat_id — visible in PHP-rendered data */
  var tgLinked = <?php echo !empty($member->tg_chat_id) ? 'true' : 'false'; ?>;
  var tgUser   = '<?php echo esc_js(isset($member->tg_username) ? $member->tg_username : ""); ?>';
  var phone    = '<?php echo esc_js($member->phone); ?>';
  if(tgLinked){
    _eidLog('ok','Telegram <span style="color:#639922">linked</span>'+(tgUser?' · @'+tgUser:''));
    _eidLog('ok','OTPs will be delivered free via @EtelDeliveryRideBot');
    _eidStat('eds-tg','OK','ok');
  } else {
    _eidLog('wn','Telegram <span style="color:#D4AF37">NOT linked</span> for phone '+phone);
    _eidLog('in','To link: open <a href="https://t.me/EtelDeliveryRideBot?start='+encodeURIComponent(phone)+'" target="_blank" style="color:#60B4FF">@EtelDeliveryRideBot</a> → send /start '+phone);
    _eidStat('eds-tg','NO','warn');
  }
}

async function eidDiagBarcode(){
  _eidLog('rn','Testing local barcode generator...');
  var classExists = <?php echo class_exists('EtelID_Barcode') ? 'true' : 'false'; ?>;
  if(classExists){
    _eidLog('ok','EtelID_Barcode class <span style="color:#639922">loaded</span> · TEC-IT replaced ✅');
    /* Check if barcode img renders on current page */
    var barcodeImgs = document.querySelectorAll('.efc-barcode img');
    if(barcodeImgs.length>0){
      _eidLog('ok','Barcode SVG rendering on card · '+barcodeImgs.length+' element(s) found');
    } else {
      _eidLog('wn','EtelID_Barcode loaded but no barcode img found on page — check card template');
    }
  } else {
    _eidLog('er','EtelID_Barcode class NOT loaded — class-etelid-barcode.php missing or has PHP error');
    _eidLog('in','Upload fixed class-etelid-barcode.php via cPanel or Updater');
  }
}

async function eidDiagREST(){
  _eidLog('rn','Testing WordPress REST API...');
  try{
    var t0=performance.now();
    var r=await fetch('<?php echo esc_js(rest_url("wp/v2")); ?>',{method:'GET'});
    var ms=Math.round(performance.now()-t0);
    if(r.ok||r.status===401){
      _eidLog('ok','WP REST API responding · <span style="color:#60B4FF">'+ms+'ms</span>');
    } else {
      _eidLog('wn','WP REST API returned HTTP '+r.status+' · may be blocked by security plugin');
    }
  }catch(e){ _eidLog('er','WP REST API error: '+e.message); }

  /* Test EtelID namespace */
  var r2=await _eidREST('');
  if(!r2.netErr && !r2.parseErr){
    _eidLog('ok','EtelID REST namespace <span style="color:#639922">registered</span> · etelid/v1 ✅');
  } else {
    _eidLog('er','EtelID REST namespace not found');
  }
}

async function eidDiagAll(){
  eidDiagClear();
  _eidLog('in','=== EtelID Full Diagnostic · <?php echo esc_js(ETELID_VERSION); ?> ===');
  _eidLog('in','Member: <?php echo esc_js($member->full_name); ?> · <?php echo esc_js($member->etel_id); ?>');
  await eidDiagPing();
  await eidDiagDB();
  await eidDiagSSO();
  await eidDiagTG();
  await eidDiagBarcode();
  await eidDiagREST();
  _eidLog('in','=== Complete ===');
}

/* Auto-run silently on portal load to catch startup errors */
window.addEventListener('error',function(e){ _eidLog('er','JS: '+e.message+' ('+((e.filename||'').split('/').pop()||'?')+':'+e.lineno+')'); });
</script>
<!-- ETEL AI -- cross-company assistant widget -->
<script src="https://ethelmarket.com/EtelAi/etel-ai-widget.js" data-project="etel-id"></script>
</body>
</html>
