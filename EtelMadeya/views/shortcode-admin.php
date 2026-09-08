<?php
/**
 * Shortcode: [madeya_admin] — the full admin panel.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode('madeya_admin','madeya_sc_admin');
function madeya_sc_admin(){
    // Prevent ANY cache (page cacher, CDN, browser) from storing this page
    if(!headers_sent()){
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    }
    // Tell LiteSpeed Cache / SpeedyCache / WP Super Cache not to cache this page
    do_action('litespeed_control_set_nocache','madeya_admin_page');
    do_action('litespeed_purge_all');
    if(!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
    if(!defined('DONOTCACHEDB'))   define('DONOTCACHEDB',   true);
    if(!defined('DONOTMINIFY'))    define('DONOTMINIFY',    true);
    // SpeedyCache purge
    if(function_exists('speedycache_purge_all')) speedycache_purge_all();
    if(function_exists('wp_cache_flush')) wp_cache_flush();

    $cfg=madeya_get_settings();
    $ajaxurl=admin_url('admin-ajax.php');
    $nonce=wp_create_nonce('madeya_nonce');
    $admin_user=get_option('madeya_admin_username','madeya_admin');
    $admin_email=get_option('madeya_admin_email','');
    $em_logo = defined( 'MADEYA_LOGO_URL' ) ? MADEYA_LOGO_URL : '';
    $em_bg = defined( 'MADEYA_PLUGIN_URL' ) ? MADEYA_PLUGIN_URL . 'assets/images/etel-bg.webp' : '';
    ob_start();?>

<style id="madeya-responsive">
/* Madeya Responsive CSS */
html{font-size:16px;}
html,body,.site,.site-content,.entry-content,.page-content,#content,#primary,article{margin:0!important;padding:0!important;max-width:100%!important;width:100%!important;}
#md,#mc,#ma{width:100% !important;max-width:100% !important;overflow-x:hidden !important;box-sizing:border-box !important;}
#md-body,#mc-body,#ma-body,#ma-app,.ma-wrap{max-width:100% !important;padding-left:10px !important;padding-right:10px !important;box-sizing:border-box !important;}
img{max-width:100% !important;height:auto !important;}
/* FIX HEADER IMAGE OVERLAP */
.em-footer-logo,
.albox img,
img {
    max-height: 80px !important;
    width: auto !important;
    object-fit: contain !important;
}

/* Prevent covering menu */
#ma-tabs, #ma-content {
    position: relative;
    z-index: 10;
}
#mc-stats{grid-template-columns:repeat(3,1fr) !important;}
.igrid{grid-template-columns:repeat(2,1fr) !important;}
.ma-tbl,.ptbl,table{width:100% !important;display:block !important;overflow-x:auto !important;-webkit-overflow-scrolling:touch !important;}
.ma-inp,.al-inp,.fp-inp,input[type=text],input[type=password],input[type=email],input[type=number],select,textarea{width:100% !important;max-width:100% !important;box-sizing:border-box !important;font-size:16px !important;}
.al-btn,.ma-btn,.fp-btn,.src-dir-btn,#md-check,#md-refresh,#mc-reg-btn{width:100% !important;box-sizing:border-box !important;-webkit-tap-highlight-color:transparent;touch-action:manipulation;}
.ma-card,.mcard,.station-result-card,.my-priority-card,.priority-section,.albox{max-width:100% !important;box-sizing:border-box !important;}
.vtypes,#vtypes-grid,.priority-tiers-grid{display:grid !important;grid-template-columns:repeat(2,1fr) !important;gap:6px !important;}
.ma-tabs{overflow-x:auto !important;-webkit-overflow-scrolling:touch !important;white-space:nowrap !important;display:flex !important;}
.ma-tab{flex-shrink:0 !important;}
#src-map{height:300px !important;}
.pd-num{font-size:clamp(40px,12vw,80px) !important;}
.stats-grid{grid-template-columns:repeat(2,1fr) !important;}
.albox{width:calc(100% - 20px) !important;max-width:420px !important;padding:28px 20px !important;}
.cdm-box{width:calc(100% - 20px) !important;max-height:95vh !important;margin:10px !important;padding:20px 14px !important;}
#al-btn{pointer-events:auto !important;cursor:pointer !important;position:relative !important;z-index:999 !important;}
#al-btn *{pointer-events:none;}
.albox input,.albox button{position:relative;z-index:999 !important;pointer-events:auto !important;}
@media(min-width:600px){
 #md-body,#mc-body,.ma-wrap{padding-left:16px !important;padding-right:16px !important;}
 .vtypes,#vtypes-grid{grid-template-columns:repeat(3,1fr) !important;}
 #mc-stats{grid-template-columns:repeat(5,1fr) !important;}
 #src-map{height:360px !important;}
 .stats-grid{grid-template-columns:repeat(4,1fr) !important;}
}
@media(min-width:900px){
 #md-body,#mc-body,.ma-wrap{padding-left:24px !important;padding-right:24px !important;}
 .vtypes,#vtypes-grid{grid-template-columns:repeat(4,1fr) !important;}
 #src-map{height:420px !important;}
 .priority-tiers-grid{grid-template-columns:repeat(2,1fr) !important;}
}
@media(min-width:1200px){
 #md,#mc,#ma{max-width:100% !important;margin:0 auto !important;}
}
button,.vt-btn,.tier-btn,.ma-tab,.station-card,.al-forgot-link button{min-height:44px !important;cursor:pointer !important;}
body{overflow-x:hidden !important;}
</style>

<style>
@import url('https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap');
#ma,#ma *{box-sizing:border-box;margin:0;padding:0;}
#ma{font-family:'Barlow Condensed',sans-serif;background:url('<?php echo esc_url($em_bg); ?>') center center/cover no-repeat fixed;background-color:#b8860b;color:var(--tx);min-height:100vh;}
:root{--f:#F5A623;--f2:#FFD166;--dk:rgba(10,7,2,0.82);--sf:rgba(18,13,4,0.80);--pn:rgba(22,16,5,0.88);--cd:rgba(30,21,6,0.82);--bd:rgba(180,130,30,0.35);--tx:#f5e6c0;--mt:#c9a96e;--gn:#27AE60;--rd:#C0392B;--bl:#2980B9;--or:#E67E22;}
/* LOGIN */
#ma-ls{min-height:100vh;display:flex;align-items:center;justify-content:center;background:transparent;position:relative;z-index:10;}
#ma-ls::before{content:'';position:absolute;inset:0;pointer-events:none;background:repeating-linear-gradient(45deg,rgba(245,166,35,.02) 0,rgba(245,166,35,.02) 1px,transparent 1px,transparent 40px);}
.albox{background:var(--sf);border:1px solid var(--bd);border-radius:16px;padding:38px 34px;width:100%;max-width:380px;position:relative;z-index:100;box-shadow:0 20px 60px rgba(0,0,0,.7);}
.albox-ico{width:68px;height:68px;background:linear-gradient(135deg,#8e44ad,#6c3483);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:30px;color:#fff;margin:0 auto 14px;box-shadow:0 0 28px rgba(142,68,173,.3);}
.albox-title{text-align:center;font-size:22px;font-weight:900;color:#9b59b6;letter-spacing:3px;margin-bottom:4px;}
.albox-sub{text-align:center;font-size:10px;color:var(--mt);letter-spacing:2px;text-transform:uppercase;margin-bottom:24px;}
.al-field{margin-bottom:13px;}
.al-field label{display:block;font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);margin-bottom:5px;}
.al-inp{width:100%;padding:12px 13px;background:var(--cd);border:1px solid var(--bd);border-radius:8px;color:var(--tx);font-family:'Barlow Condensed',sans-serif;font-size:16px;font-weight:600;outline:none;transition:all .2s;}
.al-inp:focus{border-color:#9b59b6;box-shadow:0 0 0 3px rgba(155,89,182,.12);}
.al-btn{width:100%;padding:13px;background:linear-gradient(135deg,#8e44ad,#6c3483);border:none;border-radius:9px;color:#fff;font-family:'Barlow Condensed',sans-serif;font-size:15px;font-weight:800;letter-spacing:3px;text-transform:uppercase;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;margin-top:6px;transition:all .2s;}
.al-btn:hover{box-shadow:0 5px 20px rgba(142,68,173,.4);transform:translateY(-2px);}
.al-btn:disabled{opacity:.5;cursor:not-allowed;transform:none;}
/* Force button to be clickable — prevent theme interference */
#al-btn{pointer-events:auto !important;cursor:pointer !important;position:relative !important;z-index:999 !important;}
#al-btn *{pointer-events:none;}
.albox input,.albox button{position:relative;z-index:999 !important;pointer-events:auto !important;}
.al-err{background:rgba(192,57,43,.08);border:1px solid rgba(192,57,43,.3);border-radius:8px;padding:10px 13px;font-size:13px;color:var(--rd);margin-top:10px;display:none;align-items:center;gap:8px;}
.al-err.show{display:flex;}

/* ── Per-controller quota modal ── */
#ctrl-detail-modal{display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.85);align-items:center;justify-content:center;}
#ctrl-detail-modal.show{display:flex;}
.cdm-box{background:var(--sf);border:1px solid var(--bd);border-radius:14px;width:100%;max-width:600px;max-height:92vh;overflow-y:auto;padding:28px;position:relative;box-shadow:0 24px 64px rgba(0,0,0,.8);}
.cdm-close{position:absolute;top:14px;right:16px;background:none;border:none;color:var(--mt);font-size:22px;cursor:pointer;}
.cdm-title{font-size:18px;font-weight:900;color:var(--f);letter-spacing:2px;margin-bottom:4px;}
.cdm-sub{font-size:12px;color:var(--mt);margin-bottom:18px;}
.cdm-quota-bar{background:var(--cd);border-radius:10px;padding:14px 16px;margin-bottom:16px;}
.cdm-quota-nums{display:flex;justify-content:space-between;margin-bottom:6px;}
.cdm-used{font-family:'JetBrains Mono',monospace;font-size:22px;font-weight:700;}
.cdm-alloc{font-size:12px;color:var(--mt);text-align:right;}
.cdm-bar-bg{height:10px;background:var(--bd);border-radius:5px;overflow:hidden;}
.cdm-bar-fill{height:100%;border-radius:5px;transition:width .5s;}
.cdm-loc{margin-bottom:16px;}
.cdm-loc label{display:block;font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);margin-bottom:5px;}
.cdm-loc-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;}

/* ── Forgot Password Flow CSS ── */
.al-forgot-link{text-align:center;margin-top:10px;}
.al-forgot-link button{background:none;border:none;color:#9b59b6;font-family:'Barlow Condensed',sans-serif;font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;cursor:pointer;padding:0;text-decoration:underline;transition:color .2s;}
.al-forgot-link button:hover{color:#b97fd4;}
.fp-panel{display:none;margin-top:16px;}
.fp-panel.show{display:block;}
.fp-step{display:none;}
.fp-step.active{display:block;}
.fp-info{font-size:11px;color:var(--mt);margin-bottom:12px;line-height:1.6;text-align:center;}
.fp-inp{width:100%;padding:11px 13px;background:var(--cd);border:1px solid #4a2a6a;border-radius:8px;color:var(--tx);font-family:'Barlow Condensed',sans-serif;font-size:15px;font-weight:600;outline:none;transition:all .2s;margin-bottom:8px;}
.fp-inp:focus{border-color:#9b59b6;box-shadow:0 0 0 3px rgba(155,89,182,.12);}
.fp-btn{width:100%;padding:11px;background:linear-gradient(135deg,#8e44ad,#6c3483);border:none;border-radius:8px;color:#fff;font-family:'Barlow Condensed',sans-serif;font-size:13px;font-weight:800;letter-spacing:2px;text-transform:uppercase;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;transition:all .2s;margin-top:4px;}
.fp-btn:hover{box-shadow:0 4px 16px rgba(142,68,173,.4);transform:translateY(-1px);}
.fp-btn:disabled{opacity:.5;cursor:not-allowed;transform:none;}
.fp-btn.green{background:linear-gradient(135deg,#27AE60,#1e8449);}
.fp-notice{border-radius:8px;padding:10px 13px;font-size:12px;line-height:1.5;display:none;align-items:flex-start;gap:8px;margin-bottom:10px;}
.fp-notice.show{display:flex;}
.fp-notice.ok{background:rgba(39,174,96,.08);border:1px solid rgba(39,174,96,.25);color:var(--gn);}
.fp-notice.err{background:rgba(192,57,43,.08);border:1px solid rgba(192,57,43,.25);color:var(--rd);}
.fp-code-grid{display:flex;gap:7px;justify-content:center;margin-bottom:12px;}
.fp-code-digit{width:40px;height:50px;background:var(--cd);border:2px solid #4a2a6a;border-radius:8px;color:var(--f);font-family:'JetBrains Mono',monospace;font-size:22px;font-weight:700;text-align:center;outline:none;transition:all .2s;}
.fp-code-digit:focus{border-color:#9b59b6;box-shadow:0 0 0 3px rgba(155,89,182,.12);}
.fp-timer{text-align:center;font-size:11px;color:var(--mt);margin-bottom:10px;}
.fp-timer b{color:var(--f);}
.fp-strength{height:4px;border-radius:2px;background:var(--bd);overflow:hidden;margin-bottom:4px;}
.fp-strength-bar{height:100%;border-radius:2px;transition:all .3s;}
.fp-strength-lbl{font-size:10px;color:var(--mt);margin-bottom:8px;}
.fp-back{background:none;border:none;color:var(--mt);font-size:11px;cursor:pointer;letter-spacing:1px;text-transform:uppercase;margin-top:8px;width:100%;text-align:center;}
.fp-back:hover{color:var(--tx);}
.fp-divider{display:flex;align-items:center;gap:10px;margin:14px 0;}
.fp-divider::before,.fp-divider::after{content:'';flex:1;height:1px;background:var(--bd);}
.fp-divider span{font-size:10px;color:var(--mt);letter-spacing:1px;}
/* HEADER */
#ma-hdr{background:linear-gradient(135deg,rgba(9,9,13,.97),rgba(26,10,46,.95));border-bottom:2px solid #9b59b6;padding:0 10px;height:42px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10;backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);}
.ma-hdr-l{display:flex;align-items:center;gap:11px;}
.ma-hdr-ico{width:40px;height:40px;background:linear-gradient(135deg,#8e44ad,#6c3483);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff;}
.ma-hdr-title{font-size:20px;font-weight:900;color:#9b59b6;letter-spacing:2px;}
.ma-hdr-sub{font-size:10px;color:var(--mt);letter-spacing:2px;}
.ma-logout-btn{padding:7px 14px;background:rgba(192,57,43,.1);border:1px solid rgba(192,57,43,.3);border-radius:6px;color:var(--rd);font-family:'Barlow Condensed',sans-serif;font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;transition:all .2s;}
.ma-logout-btn:hover{background:rgba(192,57,43,.2);}
#ma-pw-warning{background:linear-gradient(135deg,rgba(230,126,34,.15),rgba(230,126,34,.07));border:2px solid rgba(230,126,34,.5);border-left:4px solid #E67E22;border-radius:0;padding:9px 14px;display:none;align-items:center;gap:10px;font-size:12px;color:var(--or);font-family:'Barlow Condensed',sans-serif;}
#ma-pw-warning.show{display:flex;}
#ma-pw-warning b{font-size:13px;font-weight:800;letter-spacing:1px;margin-right:4px;}
#ma-pw-warning i{font-size:18px;flex-shrink:0;}
/* TABS */
#ma-tabs{display:flex;border-bottom:2px solid var(--bd);background:rgba(15,10,3,.95);backdrop-filter:blur(8px);overflow-x:auto;position:sticky;top:42px;z-index:190;-webkit-overflow-scrolling:touch;}
.ma-tab{padding:13px 18px;font-size:12px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--mt);cursor:pointer;border-bottom:3px solid transparent;transition:all .2s;display:flex;align-items:center;gap:6px;}
.ma-tab:hover{color:var(--tx);}
.ma-tab.active{color:#9b59b6;border-bottom-color:#9b59b6;}
/* CONTENT */
#ma-content{padding:20px;}
.ma-section{display:none;}
.ma-section.active{display:block;}
/* STAT CARDS */
.ma-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:24px;}
.ma-sc{background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:16px;text-align:center;position:relative;overflow:hidden;}
.ma-sc::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
.ma-sc.purple::before{background:#9b59b6;}.ma-sc.gold::before{background:var(--f);}.ma-sc.green::before{background:var(--gn);}.ma-sc.red::before{background:#e74c3c;}.ma-sc.blue::before{background:var(--bl);}
.ma-sc b{display:block;font-family:'JetBrains Mono',monospace;font-size:32px;font-weight:700;line-height:1.2;}
.ma-sc.purple b{color:#9b59b6;}.ma-sc.gold b{color:var(--f);}.ma-sc.green b{color:var(--gn);}.ma-sc.red b{color:#e74c3c;}.ma-sc.blue b{color:var(--bl);}
.ma-sc span{font-size:10px;color:var(--mt);text-transform:uppercase;letter-spacing:1.5px;}
/* CARD */
.ma-card{background:var(--sf);border:1px solid var(--bd);border-radius:11px;overflow:hidden;margin-bottom:20px;}
.ma-card-h{padding:13px 18px;background:var(--pn);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:8px;font-size:13px;font-weight:800;letter-spacing:2px;text-transform:uppercase;color:#9b59b6;}
.ma-card-b{padding:18px;}
/* FORM */
.ma-form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;}
.ma-field label{display:block;font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);margin-bottom:5px;}
.ma-inp{width:100%;padding:10px 12px;background:var(--cd);border:1px solid var(--bd);border-radius:7px;color:var(--tx);font-family:'Barlow Condensed',sans-serif;font-size:15px;font-weight:600;outline:none;transition:all .2s;}
.ma-inp:focus{border-color:#9b59b6;box-shadow:0 0 0 3px rgba(155,89,182,.1);}
.ma-select{width:100%;padding:10px 12px;background:var(--cd);border:1px solid var(--bd);border-radius:7px;color:var(--tx);font-family:'Barlow Condensed',sans-serif;font-size:15px;font-weight:600;outline:none;}
.ma-btn{padding:10px 22px;background:linear-gradient(135deg,#8e44ad,#6c3483);border:none;border-radius:7px;color:#fff;font-family:'Barlow Condensed',sans-serif;font-size:13px;font-weight:800;letter-spacing:2px;text-transform:uppercase;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:7px;}
.ma-btn:hover{box-shadow:0 4px 16px rgba(142,68,173,.35);transform:translateY(-1px);}
.ma-btn.red{background:linear-gradient(135deg,#c0392b,#7b241c);}
.ma-btn.gold{background:linear-gradient(135deg,var(--f),#c47f10);color:#000;}
.ma-btn.green{background:linear-gradient(135deg,#27AE60,#1e8449);color:#fff;}
.ma-btn:disabled{opacity:.5;cursor:not-allowed;transform:none;}
/* TABLE */
.ma-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.ma-tbl th{padding:10px 12px;text-align:left;font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);background:var(--pn);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);border-bottom:1px solid var(--bd);}
.ma-tbl td{padding:11px 12px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle;}
.ma-tbl tr:last-child td{border-bottom:none;}
.online-dot{width:8px;height:8px;border-radius:50%;background:var(--gn);display:inline-block;margin-right:5px;box-shadow:0 0 6px var(--gn);}
.offline-dot{width:8px;height:8px;border-radius:50%;background:var(--mt);display:inline-block;margin-right:5px;}
.par-chip2{display:inline-block;padding:3px 9px;border-radius:4px;font-size:11px;font-weight:700;}
.par-chip2.even{background:rgba(41,128,185,.15);color:var(--bl);}
.par-chip2.odd{background:rgba(230,126,34,.12);color:var(--or);}
.par-chip2.both{background:rgba(39,174,96,.1);color:var(--gn);}
/* NOTICE */
.ma-notice{padding:11px 14px;border-radius:7px;font-size:13px;font-weight:600;display:none;align-items:center;gap:8px;margin-bottom:14px;}
.ma-notice.show{display:flex;}
.ma-notice.ok{background:rgba(39,174,96,.1);border:1px solid rgba(39,174,96,.3);color:var(--gn);}
.ma-notice.err{background:rgba(192,57,43,.08);border:1px solid rgba(192,57,43,.25);color:var(--rd);}
/* TOAST */
#ma-toast{position:fixed;bottom:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;}
.ma-toast-item{padding:12px 18px;border-radius:9px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:9px;box-shadow:0 6px 20px rgba(0,0,0,.5);animation:fadeInUp .3s ease;}
@keyframes fadeInUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.ma-toast-item.ok{background:#1a3a2a;border:1px solid rgba(39,174,96,.4);color:var(--gn);}
.ma-toast-item.err{background:#2a1a1a;border:1px solid rgba(192,57,43,.4);color:var(--rd);}
.ma-toast-item.warn{background:#2a2010;border:1px solid rgba(230,126,34,.4);color:var(--or);}
/* PARITY RADIO */
.par-radio-group{display:flex;gap:10px;flex-wrap:wrap;}
.par-radio-label{display:flex;align-items:center;gap:7px;padding:9px 14px;background:var(--cd);border:1px solid var(--bd);border-radius:7px;cursor:pointer;font-size:13px;font-weight:600;transition:all .2s;}
.par-radio-label:has(input:checked){border-color:#9b59b6;background:rgba(155,89,182,.1);color:#9b59b6;}

/* ── ETEL MADEYA Brand Footer ── */
.em-brand-footer{
  background:linear-gradient(135deg,rgba(10,6,1,0.92),rgba(20,14,3,0.88),rgba(10,6,1,0.92));
  border-top:2px solid var(--f);
  padding:10px 18px;
  display:flex;align-items:center;justify-content:space-between;
  flex-wrap:wrap;gap:8px;
}
.em-footer-logo{height:28px;width:auto;object-fit:contain;opacity:.85;filter:drop-shadow(0 0 4px rgba(245,166,35,.3));}
.em-footer-text{font-size:9px;color:var(--mt);display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.em-footer-text b{color:var(--f);}
.em-footer-patent{
  display:inline-flex;align-items:center;gap:4px;
  background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.2);
  border-radius:10px;padding:2px 8px;
  font-size:7px;font-weight:700;color:var(--f);letter-spacing:1.5px;
}

</style>

<div id="ma">
 <!-- LOGIN -->
 <div id="ma-ls" style="position:relative;">
  <div class="albox">
   <div style="text-align:center;margin-bottom:20px;">
    <img src="<?php echo esc_url($em_logo); ?>" alt="ETEL MADEYA" style="height:50px;width:auto;object-fit:contain;filter:drop-shadow(0 0 14px rgba(142,68,173,.5));display:block;margin:0 auto 8px;transition:filter .3s;" onmouseover="this.style.filter='drop-shadow(0 0 24px rgba(142,68,173,.9))'" onmouseout="this.style.filter='drop-shadow(0 0 14px rgba(142,68,173,.5))'">
   </div>
   <div class="albox-title" style="color:#9b59b6;">ETEL MADEYA</div>
   <div style="font-size:12px;color:#2980B9;font-weight:700;letter-spacing:1px;text-align:center;margin-top:2px;">ኢትኤል ማደያ</div>
   <div style="display:flex;justify-content:center;gap:6px;margin-top:5px;margin-bottom:4px;">
    <span style="display:inline-flex;align-items:center;gap:3px;background:rgba(155,89,182,.1);border:1px solid rgba(155,89,182,.3);border-radius:12px;padding:2px 9px;font-size:7px;font-weight:700;color:#9b59b6;letter-spacing:1.5px;"><i class="fas fa-registered" style="font-size:6px;"></i> ኢE PATENT</span>
    <span style="display:inline-flex;align-items:center;gap:3px;background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.25);border-radius:12px;padding:2px 9px;font-size:7px;font-weight:700;color:var(--f);letter-spacing:1.5px;"><i class="fas fa-shield-alt" style="font-size:6px;"></i> ADMIN ACCESS</span>
   </div>
   <div class="albox-sub" style="margin-bottom:0;">Admin Access Panel</div>
   <div class="al-field"><label>Username / Email</label><input type="text" id="al-user" class="al-inp" placeholder="Admin username or email" autocomplete="username"></div>   <div class="al-field"><label>Password</label><input type="password" id="al-pass" class="al-inp" placeholder="Admin password" autocomplete="current-password"></div>
   <button type="button" class="al-btn" id="al-btn" onclick="aLogin()"><i class="fas fa-sign-in-alt"></i>LOGIN AS ADMIN</button>
   <div class="al-err" id="al-err"><i class="fas fa-exclamation-circle"></i><span id="al-err-msg"></span></div>
   <div style="text-align:center;margin-top:10px;padding:8px 12px;background:rgba(41,128,185,.08);border:1px solid rgba(41,128,185,.2);border-radius:8px;font-size:11px;color:#2980B9;">
    <i class="fas fa-info-circle"></i> Default: <strong>madeya_admin</strong> / <strong>CHANGE_ME</strong> &mdash; Change after first login.
   </div>
   <!-- Forgot password link -->
   <div class="al-forgot-link"><button type="button" onclick="fpShow()"><i class="fas fa-key" style="margin-right:5px;font-size:10px;"></i>Forgot username or password?</button></div>

   <!-- ── FORGOT PASSWORD PANEL ── -->
   <div id="fp-panel" class="fp-panel">
    <div class="fp-divider"><span>PASSWORD RESET</span></div>

    <!-- STEP 1: Enter recovery email -->
    <div id="fp-step1" class="fp-step active">
     <p class="fp-info"><i class="fas fa-envelope" style="color:#9b59b6;margin-right:5px;"></i>Enter the recovery email registered with this admin account. A 6-digit verification code will be sent to it.</p>
     <div class="fp-notice" id="fp-notice1"></div>
     <input type="email" id="fp-email" class="fp-inp" placeholder="Recovery email address" autocomplete="email">
     <button type="button" class="fp-btn" id="fp-send-btn" onclick="fpSend()">
      <i class="fas fa-paper-plane"></i>SEND VERIFICATION CODE
     </button>
     <button type="button" class="fp-back" onclick="fpHide()"><i class="fas fa-arrow-left"></i> Back to Login</button>
    </div>

    <!-- STEP 2: Enter 6-digit code -->
    <div id="fp-step2" class="fp-step">
     <p class="fp-info" id="fp-sent-to">A 6-digit code has been sent.</p>
     <div class="fp-notice" id="fp-notice2"></div>
     <div class="fp-code-grid" id="fp-code-grid">
      <input class="fp-code-digit" maxlength="1" data-idx="0" inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code">
      <input class="fp-code-digit" maxlength="1" data-idx="1" inputmode="numeric" pattern="[0-9]">
      <input class="fp-code-digit" maxlength="1" data-idx="2" inputmode="numeric" pattern="[0-9]">
      <input class="fp-code-digit" maxlength="1" data-idx="3" inputmode="numeric" pattern="[0-9]">
      <input class="fp-code-digit" maxlength="1" data-idx="4" inputmode="numeric" pattern="[0-9]">
      <input class="fp-code-digit" maxlength="1" data-idx="5" inputmode="numeric" pattern="[0-9]">
     </div>
     <div class="fp-timer" id="fp-timer">Code expires in <b id="fp-countdown">15:00</b></div>
     <button type="button" class="fp-btn" id="fp-verify-btn" onclick="fpVerify()">
      <i class="fas fa-shield-alt"></i>VERIFY CODE
     </button>
     <div style="text-align:center;margin-top:8px;">
      <button type="button" style="background:none;border:none;color:var(--mt);font-size:11px;cursor:pointer;text-decoration:underline;" onclick="fpSend(true)">Resend code</button>
     </div>
     <button type="button" class="fp-back" onclick="fpStep(1)"><i class="fas fa-arrow-left"></i> Back</button>
    </div>

    <!-- STEP 3: Set new credentials -->
    <div id="fp-step3" class="fp-step">
     <p class="fp-info"><i class="fas fa-check-circle" style="color:var(--gn);margin-right:5px;"></i>Identity verified! Set your new admin credentials below.</p>
     <div class="fp-notice" id="fp-notice3"></div>
     <input type="text" id="fp-new-user" class="fp-inp" placeholder="New admin username" autocomplete="username">
     <input type="email" id="fp-new-email" class="fp-inp" placeholder="Recovery email (leave blank to keep current)" autocomplete="email">
     <input type="password" id="fp-new-pass" class="fp-inp" placeholder="New password (min 8 characters)" autocomplete="new-password" oninput="fpStrength(this.value)">
     <div class="fp-strength"><div class="fp-strength-bar" id="fp-sbar"></div></div>
     <div class="fp-strength-lbl" id="fp-slbl"></div>
     <input type="password" id="fp-confirm-pass" class="fp-inp" placeholder="Confirm new password" autocomplete="new-password">
     <button type="button" class="fp-btn green" id="fp-reset-btn" onclick="fpReset()">
      <i class="fas fa-save"></i>SAVE NEW CREDENTIALS
     </button>
     <button type="button" class="fp-back" onclick="fpStep(2)"><i class="fas fa-arrow-left"></i> Back</button>
    </div>
   </div>
  </div>
 </div>

 <!-- APP -->
 <div id="ma-app" style="display:none;">
  <div id="ma-hdr">
   <div style="display:flex;align-items:center;gap:8px;min-width:0;overflow:hidden;">
    <img src="<?php echo esc_url($em_logo); ?>" alt="ETEL MADEYA"
     style="height:22px;width:auto;max-width:100px;object-fit:contain;filter:drop-shadow(0 0 5px rgba(155,89,182,.5));flex-shrink:0;">
    <div style="min-width:0;overflow:hidden;">
     <div style="font-size:12px;font-weight:900;color:#9b59b6;letter-spacing:2px;line-height:1.1;white-space:nowrap;">ETEL MADEYA</div>
     <div style="font-size:8px;color:var(--f);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;"><span id="ma-station-name">Madeya Gas Station</span></div>
    </div>
   </div>
   <div style="display:flex;align-items:center;gap:6px;flex-shrink:0;">
    <span id="ma-time" style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--mt);display:none;" class="hdr-clock"></span>
    <style>@media(min-width:480px){.hdr-clock{display:inline !important;}}</style>
    <button type="button" onclick="aTab('settings')" title="Settings"
     style="padding:5px 8px;background:rgba(245,166,35,.12);border:1px solid rgba(245,166,35,.3);border-radius:5px;color:var(--f);font-size:11px;cursor:pointer;line-height:1;">
     <i class="fas fa-cog"></i>
    </button>
    <button type="button" class="ma-logout-btn" onclick="aLogout()" title="Logout"
     style="padding:5px 8px;font-size:11px;line-height:1;">
     <i class="fas fa-sign-out-alt"></i>
    </button>
   </div>
  </div>

  <!-- Default credential warning -->
  <div id="ma-pw-warning">
   <i class="fas fa-exclamation-triangle"></i>
   <b>DEFAULT PASSWORD DETECTED</b> Change your admin password immediately in Settings.
  </div>

  <!-- TABS -->
  <div id="ma-tabs">
   <div class="ma-tab active" data-tab="dashboard" onclick="aTab('dashboard')"><i class="fas fa-tachometer-alt"></i>Dashboard</div>
   <div class="ma-tab" data-tab="fuel" onclick="aTab('fuel')"><i class="fas fa-gas-pump"></i>Fuel Stock</div>
   <div class="ma-tab" data-tab="controllers" onclick="aTab('controllers')"><i class="fas fa-users-cog"></i>Controllers</div>
   <div class="ma-tab" data-tab="news" onclick="aTab('news')"><i class="fas fa-bullhorn"></i>Daily News</div>
   <div class="ma-tab" data-tab="priority" onclick="aTab('priority')"><i class="fas fa-shield-alt"></i>Priority</div>
   <div class="ma-tab" data-tab="settings" onclick="aTab('settings')"><i class="fas fa-cog"></i>Settings</div>
   <div class="ma-tab" data-tab="update" onclick="aTab('update')"><i class="fas fa-rocket"></i>Update</div>
  </div>

  <div id="ma-content">

   <!-- DASHBOARD -->
   <div id="tab-dashboard" class="ma-section active">
    <!-- Quick navigation pills -->
    
    <div class="ma-stats" style="grid-template-columns:repeat(5,1fr)">
     <div class="ma-sc gold"><b id="st-queue">—</b><span>Normal Queue</span></div>
     <div class="ma-sc red"><b id="st-vip">—</b><span>VIP Queue</span></div>
     <div class="ma-sc green"><b id="st-served">—</b><span>Served Today</span></div>
     <div class="ma-sc blue"><b id="st-ctrls">—</b><span>Controllers</span></div>
     <div class="ma-sc purple"><b id="st-online">—</b><span>Online Now</span></div>
    </div>
    <!-- FUEL SUMMARY STRIP -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;">
     <div class="ma-card" style="margin:0;border-top:3px solid #27AE60;">
      <div class="ma-card-b" style="padding:14px 18px;">
       <div style="font-size:10px;color:var(--mt);letter-spacing:2px;text-transform:uppercase;margin-bottom:4px;"><i class="fas fa-gas-pump" style="color:#27AE60"></i> Current Stock</div>
       <b id="st-fuel-stock" style="font-family:'JetBrains Mono',monospace;font-size:28px;color:#27AE60;">—</b>
       <span style="font-size:11px;color:var(--mt);margin-left:4px;">L</span>
      </div>
     </div>
     <div class="ma-card" style="margin:0;border-top:3px solid var(--f);">
      <div class="ma-card-b" style="padding:14px 18px;">
       <div style="font-size:10px;color:var(--mt);letter-spacing:2px;text-transform:uppercase;margin-bottom:4px;"><i class="fas fa-list-ol" style="color:var(--f)"></i> Allocated (Queue)</div>
       <b id="st-fuel-alloc" style="font-family:'JetBrains Mono',monospace;font-size:28px;color:var(--f);">—</b>
       <span style="font-size:11px;color:var(--mt);margin-left:4px;">gal</span>
      </div>
     </div>
     <div class="ma-card" style="margin:0;border-top:3px solid var(--bl);">
      <div class="ma-card-b" style="padding:14px 18px;">
       <div style="font-size:10px;color:var(--mt);letter-spacing:2px;text-transform:uppercase;margin-bottom:4px;"><i class="fas fa-tint" style="color:var(--bl)"></i> Remaining Free</div>
       <b id="st-fuel-rem" style="font-family:'JetBrains Mono',monospace;font-size:28px;color:var(--bl);">—</b>
       <span style="font-size:11px;color:var(--mt);margin-left:4px;">gal</span>
      </div>
     </div>
     <div class="ma-card" style="margin:0;border-top:3px solid var(--rd);">
      <div class="ma-card-b" style="padding:14px 18px;">
       <div style="font-size:10px;color:var(--mt);letter-spacing:2px;text-transform:uppercase;margin-bottom:4px;"><i class="fas fa-check-circle" style="color:var(--rd)"></i> Dispensed Today</div>
       <b id="st-fuel-today" style="font-family:'JetBrains Mono',monospace;font-size:28px;color:var(--rd);">—</b>
       <span style="font-size:11px;color:var(--mt);margin-left:4px;">gal</span>
      </div>
     </div>
    </div>
    <!-- FUEL PROGRESS BAR -->
    <div class="ma-card" style="margin-bottom:20px;">
     <div class="ma-card-h" style="color:#27AE60;"><i class="fas fa-gas-pump"></i>Fuel Tank Level</div>
     <div class="ma-card-b">
      <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:13px;">
       <span style="color:var(--mt)">Stock remaining</span>
       <b id="st-fuel-pct-lbl" style="color:var(--tx)">—%</b>
      </div>
      <div style="background:var(--bd);border-radius:8px;height:18px;overflow:hidden;">
       <div id="st-fuel-bar" style="height:100%;background:linear-gradient(90deg,#27AE60,#2ecc71);border-radius:8px;transition:width .6s;width:0%;"></div>
      </div>
      <div style="display:flex;justify-content:space-between;margin-top:6px;font-size:11px;color:var(--mt);">
       <span>0</span><span id="st-fuel-cap-lbl">Capacity: — L</span>
      </div>
      <!-- Depletion estimate — injected by JS -->
      <div id="st-depletion" style="display:none;margin-top:10px;"></div>
     </div>
    </div>
    <div class="ma-card">
     <div class="ma-card-h"><i class="fas fa-bolt"></i>Quick Actions</div>
     <div class="ma-card-b" style="display:flex;gap:10px;flex-wrap:wrap;">
      <button class="ma-btn gold" onclick="aTab('controllers')"><i class="fas fa-users-cog"></i>Manage Controllers</button>
      <button class="ma-btn" onclick="aTab('settings')"><i class="fas fa-cog"></i>Station Settings</button>
      <button class="ma-btn red" onclick="aClearQueue('normal')" style="margin-left:auto;"><i class="fas fa-trash"></i>Clear Normal Queue</button>
      <button class="ma-btn red" onclick="aClearQueue('vip')"><i class="fas fa-star"></i>Clear VIP Queue</button>
      <button class="ma-btn red" onclick="aClearQueue('all')"><i class="fas fa-bomb"></i>Clear ALL Queues</button>
     </div>
    </div>
   </div>

   <!-- FUEL STOCK MANAGEMENT -->
   <div id="tab-fuel" class="ma-section">
    <div class="ma-notice" id="fuel-notice"></div>

    <!-- Controller selector bar -->
    <div class="ma-card" style="margin-bottom:18px;">
     <div class="ma-card-h" style="color:#27AE60;"><i class="fas fa-user-shield"></i>Select Controller to Assign Fuel</div>
     <div class="ma-card-b">
      <p style="font-size:12px;color:var(--mt);margin-bottom:12px;">Each controller has a private fuel quota. Select a controller to view and update their allocation. Other controllers cannot see each other&#39;s quota.</p>
      <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
       <div class="ma-field" style="flex:1;min-width:200px;margin:0;">
        <label for="f-ctrl-select">Select Controller</label>
        <select id="f-ctrl-select" class="ma-select" onchange="aFuelLoadController()">
         <option value="">— Choose a controller —</option>
        </select>
       </div>
       <button class="ma-btn" onclick="aFuelLoadController()" style="padding:10px 18px;"><i class="fas fa-sync"></i>LOAD</button>
      </div>
     </div>
    </div>

    <!-- Controller fuel form (shown after selection) -->
    <div id="f-ctrl-panel" style="display:none;">
     <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

      <!-- Assign quota -->
      <div class="ma-card">
       <div class="ma-card-h" style="color:#27AE60;"><i class="fas fa-fill-drip"></i>
        Assign Fuel Quota — <span id="f-ctrl-name" style="color:var(--f);">—</span>
       </div>
       <div class="ma-card-b">
        <!-- Current quota bar -->
        <div id="f-ctrl-quota-display" style="background:var(--cd);border-radius:10px;padding:14px 16px;margin-bottom:16px;display:none;">
         <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
          <div>
           <div style="font-size:10px;color:var(--mt);letter-spacing:1.5px;text-transform:uppercase;margin-bottom:3px;">Currently Used</div>
           <div id="f-ctrl-used" style="font-family:'JetBrains Mono',monospace;font-size:22px;font-weight:700;color:var(--f);">—</div>
          </div>
          <div style="text-align:right;">
           <div style="font-size:10px;color:var(--mt);letter-spacing:1.5px;text-transform:uppercase;margin-bottom:3px;">Remaining / Total</div>
           <div id="f-ctrl-rem" style="font-family:'JetBrains Mono',monospace;font-size:14px;color:var(--gn);">— / —</div>
          </div>
         </div>
         <div style="height:8px;background:var(--bd);border-radius:4px;overflow:hidden;">
          <div id="f-ctrl-bar" style="height:100%;border-radius:4px;background:var(--gn);transition:width .5s;width:0%;"></div>
         </div>
        </div>
        <div class="ma-form-row">
         <div class="ma-field">
          <label for="f-stock">New Quota (Liters) — 0 = Unlimited</label>
          <input type="number" id="f-stock" class="ma-inp" min="0" step="10" placeholder="e.g. 5000">
         </div>
         <div class="ma-field">
          <label for="f-cap">Tank Capacity (Liters)</label>
          <input type="number" id="f-cap" class="ma-inp" min="0" step="10" placeholder="e.g. 20000">
         </div>
        </div>
        <div class="ma-field" style="margin-bottom:12px;">
         <label for="f-note">Note (optional)</label>
         <input type="text" id="f-note" class="ma-inp" placeholder="e.g. Morning delivery, Refill #3">
        </div>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
         <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:13px;color:var(--tx);">
          <input type="checkbox" id="f-reset-used" style="width:16px;height:16px;"> Reset used counter to 0 (new cycle)
         </label>
        </div>
        <button class="ma-btn green" onclick="aSetFuel()"><i class="fas fa-save"></i>SAVE QUOTA FOR THIS CONTROLLER</button>
       </div>
      </div>

      <!-- Private usage summary for this controller -->
      <div class="ma-card">
       <div class="ma-card-h" style="color:#27AE60;"><i class="fas fa-chart-bar"></i>
        Private Usage Summary — <span id="f-ctrl-name2" style="color:var(--f);">—</span>
       </div>
       <div class="ma-card-b" id="fuel-summary-box">
        <div style="text-align:center;padding:30px;color:var(--mt);font-size:13px;"><i class="fas fa-user-shield" style="display:block;font-size:28px;opacity:.2;margin-bottom:8px;"></i>Select a controller to view their private fuel summary.</div>
       </div>
      </div>

     </div>

     <!-- This controller's fuel log -->
     <div class="ma-card" style="margin-top:18px;">
      <div class="ma-card-h" style="color:var(--f);"><i class="fas fa-history"></i>
       Fuel Log — <span id="f-ctrl-name3" style="color:var(--mt);font-size:11px;font-weight:400;">Select controller above</span>
      </div>
      <div class="ma-card-b" style="padding:0;">
       <table class="ma-tbl" id="fuel-log-tbl">
        <thead><tr><th>Time</th><th>Action</th><th>Liters (L)</th><th>Note</th></tr></thead>
        <tbody id="fuel-log-body"><tr><td colspan="4" style="text-align:center;padding:24px;color:var(--mt)">Select a controller to load their fuel log.</td></tr></tbody>
       </table>
      </div>
     </div>
    </div>

    <!-- All controllers overview -->
    <div class="ma-card" style="margin-top:18px;">
     <div class="ma-card-h" style="color:var(--bl);"><i class="fas fa-th-list"></i>All Controllers — Fuel Overview</div>
     <div style="overflow-x:auto;">
      <table class="ma-tbl" id="fuel-overview-tbl">
       <thead>
        <tr>
         <th>Controller</th><th>Station</th><th>Vehicle</th>
         <th>Quota (L)</th><th>Used (L)</th><th>Remaining (L)</th><th>%</th><th>Action</th>
        </tr>
       </thead>
       <tbody id="fuel-overview-body">
        <tr><td colspan="8" style="text-align:center;padding:24px;color:var(--mt)">Loading...</td></tr>
       </tbody>
      </table>
     </div>
    </div>
   </div>

   <!-- CONTROLLERS -->
   <div id="tab-controllers" class="ma-section">
    <div class="ma-notice" id="ctrl-notice"></div>
    <div class="ma-card" style="margin-bottom:20px;">
     <div class="ma-card-h"><i class="fas fa-plus-circle"></i>Add New Controller</div>
     <div class="ma-card-b">
      <div class="ma-form-row">
       <div class="ma-field"><label for="nc-user">Username</label><input type="text" id="nc-user" class="ma-inp" placeholder="e.g. ctrl_nok1"></div>
       <div class="ma-field"><label for="nc-pass">Password</label><input type="password" id="nc-pass" class="ma-inp" placeholder="Strong password"></div>
      </div>
      <div class="ma-form-row">
       <div class="ma-field"><label for="nc-name">Full Name</label><input type="text" id="nc-name" class="ma-inp" placeholder="Controller's name"></div>
       <div class="ma-field"><label for="nc-station">Station Name</label><input type="text" id="nc-station" class="ma-inp" value="Madeya Gas Station" placeholder="e.g. NOK #1"></div>
      </div>
      <div class="ma-form-row">
       <div class="ma-field"><label for="nc-vtype">Vehicle Type Served</label>
        <select id="nc-vtype" class="ma-select">
         <option value="bajaj">??? (Bajaj)</option><option value="qute">??? (Qute)</option><option value="l_moto">L ??? ???? (L Motorcycle)</option><option value="private_moto">??? ??? ???? (Private Motorcycle)</option><option value="govt_moto">?????? ??? ???? (Govt Motorcycle)</option><option value="private_car">??? ??? (Private Car)</option><option value="minibus">???? ??? (Minibus Taxi)</option><option value="damas">??? (Damas)</option></select>
       </div>
       <div class="ma-field"><label for="nc-parity">Plate Parity Allowed</label>
        <select id="nc-parity" class="ma-select">
         <option value="both">✅ Both Even &amp; Odd</option>
         <option value="even">🔵 Even Only (ሙሉ)</option>
         <option value="odd">🟠 Odd Only (ጎዶሎ)</option>
        </select>
       </div>
      </div>
      <button type="button" class="ma-btn" id="nc-btn" onclick="aAddCtrl()"><i class="fas fa-plus"></i>ADD CONTROLLER</button>
     </div>
    </div>
    <div class="ma-card">
     <div class="ma-card-h"><i class="fas fa-list"></i>All Controllers</div>
     <div style="overflow-x:auto;">
      <table class="ma-tbl">
       <thead><tr><th>ID</th><th>Username</th><th>Full Name</th><th>Station</th><th>Vehicle</th><th>Parity</th><th>Quota (L)</th><th>Today Served</th><th>Status</th><th>Actions</th></tr></thead>
       <tbody id="ctrl-tbody"><tr><td colspan="10" style="text-align:center;padding:24px;color:var(--mt)">Loading...</td></tr></tbody>
      </table>
     </div>
    </div>
   </div>

   <!-- DAILY NEWS TAB -->
   <div id="tab-news" class="ma-section">
    <div class="ma-notice" id="news-notice"></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
     <!-- Post news -->
     <div class="ma-card">
      <div class="ma-card-h" style="color:var(--f);"><i class="fas fa-bullhorn"></i>Post Daily Update</div>
      <div class="ma-card-b">
       <div class="ma-field" style="margin-bottom:12px;">
        <label for="news-text">Announcement Text (shown to all drivers)</label>
        <textarea id="news-text" class="ma-inp" rows="4" style="resize:vertical;font-size:13px;" placeholder="e.g. Today fuel is available at the following stations for the listed vehicle types. Check the map for directions..."></textarea>
       </div>
       <div class="ma-field" style="margin-bottom:12px;">
        <label>Active Vehicle Types Today (select all that apply)</label>
        <div id="news-vtype-grid" style="display:flex;flex-wrap:wrap;gap:7px;margin-top:6px;"></div>
       </div>
       <div class="ma-field" style="margin-bottom:12px;">
        <label for="news-expires">Expires After (hours)</label>
        <select id="news-expires" class="ma-select" style="width:160px;">
         <option value="6">6 hours</option>
         <option value="12">12 hours</option>
         <option value="24" selected>24 hours</option>
         <option value="48">48 hours</option>
        </select>
       </div>
       <button class="ma-btn gold" onclick="aPostNews()"><i class="fas fa-paper-plane"></i>POST UPDATE</button>
      </div>
     </div>
     <!-- Station list for today's news -->
     <div class="ma-card">
      <div class="ma-card-h" style="color:var(--gn);"><i class="fas fa-map-marker-alt"></i>Stations in Today's Update</div>
      <div class="ma-card-b" style="padding:0;">
       <div id="news-station-list" style="max-height:320px;overflow-y:auto;">
        <div style="text-align:center;padding:24px;color:var(--mt);font-size:12px;">Stations are pulled from controller location settings. Set location coordinates in the Controllers tab.</div>
       </div>
       <div style="padding:12px;border-top:1px solid var(--bd);">
        <button class="ma-btn" onclick="aLoadNewsStations()" style="width:100%;"><i class="fas fa-sync"></i>LOAD STATIONS FROM CONTROLLERS</button>
       </div>
      </div>
     </div>
    </div>
    <!-- Current news preview -->
    <div class="ma-card" style="margin-top:20px;">
     <div class="ma-card-h" style="color:var(--mt);"><i class="fas fa-eye"></i>Current Active Update (Driver Preview)</div>
     <div class="ma-card-b" id="news-preview">
      <div style="text-align:center;padding:20px;color:var(--mt);font-size:12px;">No active update posted yet.</div>
     </div>
    </div>
   </div>

   <!-- PRIORITY TIERS TAB -->
   <div id="tab-priority" class="ma-section">
    <div class="ma-notice" id="priority-notice"></div>
    <div style="margin-bottom:18px;">
     <h2 style="color:var(--f);margin:0 0 6px;">የነዳጅ አቅርቦት ቅድሚያ ደረጃ</h2>
     <p style="color:var(--mt);font-size:13px;max-width:600px;">Government-mandated oil supply priority order. Enable or disable tiers and add notes. Drivers will see their vehicle's tier and current status.</p>
    </div>
    <div id="priority-tiers-list" style="max-width:700px;">
     <div style="text-align:center;padding:24px;color:var(--mt);">Loading priority tiers...</div>
    </div>
    <div style="margin-top:16px;">
     <button class="ma-btn gold" onclick="aSavePriority()"><i class="fas fa-save"></i>SAVE PRIORITY RULES</button>
    </div>
   </div>

   <!-- SETTINGS -->
   <div id="tab-settings" class="ma-section">
    <div class="ma-notice" id="sett-notice"></div>
    <div class="ma-card">
     <div class="ma-card-h"><i class="fas fa-gas-pump"></i>Station Settings</div>
     <div class="ma-card-b">
      <div class="ma-form-row">
       <div class="ma-field"><label for="s-station">Station Name</label><input type="text" id="s-station" class="ma-inp" value="<?php echo esc_attr($cfg['station_name']); ?>"></div>
       <div class="ma-field"><label for="s-parity">Global Default Parity</label>
        <select id="s-parity" class="ma-select">
         <option value="both" <?php selected($cfg['allowed_parity'],'both'); ?>>Both Even &amp; Odd</option>
         <option value="even" <?php selected($cfg['allowed_parity'],'even'); ?>>Even Only (ሙሉ)</option>
         <option value="odd"  <?php selected($cfg['allowed_parity'],'odd'); ?>>Odd Only (ጎዶሎ)</option>
        </select>
       </div>
      </div>
      <div style="font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);margin-bottom:10px;">Vehicle Types Served</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px;" id="s-vtypes">
       <?php $all_keys=array('bajaj','minibus','motorcycle','private','damas','govt_moto','Qute','diesel_car');
       foreach($all_keys as $k):$checked=in_array($k,$cfg['allowed_types'])?'checked':'';?>
       <label style="display:flex;align-items:center;gap:6px;padding:8px 12px;background:var(--cd);border:1px solid var(--bd);border-radius:7px;cursor:pointer;font-size:13px;font-weight:600;">
        <input type="checkbox" name="allowed_types[]" value="<?php echo $k;?>" <?php echo $checked;?>> <?php echo esc_html($cfg['type_labels'][$k]??$k);?>
       </label>
       <?php endforeach;?>
      </div>
      <button type="button" class="ma-btn gold" onclick="aSaveSettings()"><i class="fas fa-save"></i>SAVE STATION SETTINGS</button>
     </div>
    </div>
    <div class="ma-card">
     <div class="ma-card-h"><i class="fab fa-google"></i>Google Maps API Key</div>
     <div class="ma-card-b">
      <p style="font-size:12px;color:var(--mt);margin-bottom:14px;">Required for the interactive map, address search, and turn-by-turn routing on the driver page. Get your key from <a href="https://console.cloud.google.com/apis/credentials" target="_blank" style="color:var(--f);">Google Cloud Console</a> with <b>Maps JS API</b>, <b>Directions API</b>, <b>Places API (New)</b>, and <b>Geocoding API</b> enabled.</p>
      <div class="ma-form-row">
       <div class="ma-field" style="flex:1;">
        <label for="s-gmaps-key">API Key</label>
        <input type="text" id="s-gmaps-key" class="ma-inp" value="<?php echo esc_attr(get_option('madeya_google_maps_key','')); ?>" placeholder="AIzaSy…" autocomplete="off" style="font-family:'JetBrains Mono',monospace;font-size:13px;">
       </div>
      </div>
      <button type="button" class="ma-btn gold" onclick="aSaveSettings()"><i class="fas fa-save"></i>SAVE API KEY</button>
     </div>
    </div>

    <div class="ma-card">
     <div class="ma-card-h"><i class="fas fa-key"></i>Admin Credentials</div>
     <div class="ma-card-b">
      <p style="font-size:13px;color:var(--mt);margin-bottom:14px;">Change the admin username, email, or password for this panel. Leave password blank to keep current.</p>
      <div class="ma-form-row">
       <div class="ma-field"><label for="s-auser">Admin Username</label><input type="text" id="s-auser" class="ma-inp" value="<?php echo esc_attr($admin_user);?>" placeholder="Admin username"></div>
       <div class="ma-field"><label for="s-aemail">Admin Email</label><input type="email" id="s-aemail" class="ma-inp" value="<?php echo esc_attr($admin_email);?>" placeholder="Admin email"></div>
      </div>
      <div class="ma-form-row">
       <div class="ma-field"><label for="s-apass">New Admin Password</label><input type="password" id="s-apass" class="ma-inp" placeholder="Leave blank to keep current"></div>
      </div>
      <button type="button" class="ma-btn" onclick="aSaveSettings()"><i class="fas fa-save"></i>SAVE CREDENTIALS</button>
     </div>
    </div>   </div>

  </div><!-- /ma-content -->
 </div><!-- /ma-app -->
</div><!-- /ma -->

<!-- ── ETEL MADEYA Brand Footer ── -->
<div class="em-brand-footer">
 <img src="<?php echo esc_url($em_logo); ?>" alt="ETEL MADEYA" class="em-footer-logo">
 <div class="em-footer-text">
  <span><b>ETEL MADEYA</b> ·  ኢትኤል ማደያ</span>
  <span>ethelmarket.com/etel.Madeya</span>
 </div>
 <div class="em-footer-patent"><i class="fas fa-registered"></i> ኢE PATENT &amp; TRADEMARK OF ETEL DEV</div>
</div>

<!-- ── Per-Controller Quota Detail Modal ── -->
<div id="ctrl-detail-modal">
 <div class="cdm-box">
  <button class="cdm-close" onclick="cdmClose()">×</button>
  <div class="cdm-title" id="cdm-title">Controller Quota</div>
  <div class="cdm-sub" id="cdm-sub"></div>
  <!-- Quota bar -->
  <div class="cdm-quota-bar">
   <div class="cdm-quota-nums">
    <div>
     <div style="font-size:10px;color:var(--mt);letter-spacing:1.5px;text-transform:uppercase;margin-bottom:3px;">Quota Used</div>
     <div class="cdm-used" id="cdm-used">—</div>
    </div>
    <div class="cdm-alloc">
     <div style="font-size:10px;color:var(--mt);letter-spacing:1.5px;text-transform:uppercase;margin-bottom:3px;">Remaining / Total</div>
     <div style="font-family:'JetBrains Mono',monospace;font-size:14px;color:var(--f);" id="cdm-rem">— / —</div>
    </div>
   </div>
   <div class="cdm-bar-bg"><div class="cdm-bar-fill" id="cdm-bar"></div></div>
  </div>
  <!-- Set new quota -->
  <div class="ma-form-row">
   <div class="ma-field">
    <label>New Quota (Liters) — 0 = Unlimited</label>
    <input type="number" id="cdm-liters" class="ma-inp" min="0" step="10" placeholder="e.g. 5000">
   </div>
   <div class="ma-field" style="display:flex;align-items:flex-end;padding-bottom:2px;">
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--tx);">
     <input type="checkbox" id="cdm-reset" style="width:16px;height:16px;"> Reset used counter to 0
    </label>
   </div>
  </div>
  <!-- Station location -->
  <div class="cdm-loc">
   <label style="display:block;font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--f);margin-bottom:5px;">
    <i class="fas fa-map-marker-alt" style="margin-right:4px;"></i>Station Location — shown on Driver Map
   </label>
   <p style="font-size:11px;color:var(--mt);margin-bottom:8px;">Enter coordinates so drivers can find this station. Get coordinates from Google Maps (right-click → copy coordinates).</p>
   <div class="cdm-loc-row">
    <div>
     <label for="cdm-lat" style="display:block;font-size:9px;color:var(--mt);margin-bottom:3px;letter-spacing:1px;text-transform:uppercase;">Latitude</label>
     <input type="number" id="cdm-lat" class="ma-inp" step="0.0000001" placeholder="e.g. 9.0054 (N of Addis)">
    </div>
    <div>
     <label for="cdm-lng" style="display:block;font-size:9px;color:var(--mt);margin-bottom:3px;letter-spacing:1px;text-transform:uppercase;">Longitude</label>
     <input type="number" id="cdm-lng" class="ma-inp" step="0.0000001" placeholder="e.g. 38.7636 (Addis)">
    </div>
   </div>
   <label for="cdm-address" style="display:block;font-size:9px;color:var(--mt);margin-bottom:3px;letter-spacing:1px;text-transform:uppercase;">Station Address (shown to drivers)</label>
   <input type="text" id="cdm-address" class="ma-inp" placeholder="e.g. Bole Road, Addis Ababa — near Edna Mall">
   <div style="margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <a id="cdm-map-link" href="#" target="_blank" style="font-size:11px;color:var(--bl);display:none;"><i class="fas fa-external-link-alt"></i> View current location on Google Maps</a>
    <a href="https://www.google.com/maps" target="_blank" style="font-size:11px;color:var(--mt);">
     <i class="fas fa-question-circle"></i> How to get coordinates from Google Maps
    </a>
   </div>
  </div>
  <!-- Today's queue for this controller (private) -->
  <div style="font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);margin-bottom:8px;">Today's Queue Activity</div>
  <div id="cdm-queue" style="max-height:160px;overflow-y:auto;font-size:12px;background:var(--cd);border-radius:8px;padding:10px;">
   <div style="color:var(--mt);text-align:center;">Loading...</div>
  </div>
  <div style="display:flex;gap:10px;margin-top:18px;">
   <button class="ma-btn gold" onclick="cdmSave()" style="flex:1;"><i class="fas fa-save"></i>SAVE QUOTA &amp; LOCATION</button>
   <button class="ma-btn red" onclick="cdmClose()" style="padding:10px 18px;"><i class="fas fa-times"></i>CLOSE</button>
  </div>
 </div>
</div>
 <!-- UPDATE TAB -->
 <div id="tab-update" class="ma-section">
  <div class="ma-notice" id="upd-notice"></div>
  <div class="ma-card" style="margin-bottom:16px;">
   <div class="ma-card-h"><i class="fas fa-upload"></i>Upload & Install New Version</div>
   <div class="ma-card-b">
    <div id="upd-drop" onclick="document.getElementById('upd-zip').click()"
     ondragover="event.preventDefault();this.style.borderColor='var(--f)'"
     ondragleave="this.style.borderColor='rgba(245,166,35,.3)'"
     ondrop="event.preventDefault();this.style.borderColor='rgba(245,166,35,.3)';aHandleZip(event.dataTransfer.files[0])"
     style="border:2px dashed rgba(245,166,35,.3);border-radius:10px;padding:28px;text-align:center;cursor:pointer;margin-bottom:14px;">
     <div style="font-size:32px;margin-bottom:8px;">??</div>
     <div style="color:var(--f);font-weight:700;font-size:14px;">Click or drag ZIP file here</div>
     <div style="color:var(--mt);font-size:12px;margin-top:4px;">madeya-gas-queue.zip</div>
     <div id="upd-fname" style="display:none;margin-top:10px;font-size:13px;color:#27AE60;font-weight:700;"></div>
    </div>
    <input type="file" id="upd-zip" accept=".zip" style="display:none" onchange="aHandleZip(this.files[0])">
    <button id="upd-btn" type="button" onclick="aInstallZip()" disabled
     style="width:100%;padding:14px;background:linear-gradient(135deg,#27AE60,#1e8449);border:none;border-radius:9px;color:#fff;font-family:'Barlow Condensed',sans-serif;font-size:15px;font-weight:900;letter-spacing:2px;cursor:pointer;opacity:.5;display:flex;align-items:center;justify-content:center;gap:8px;">
     <i class="fas fa-upload"></i>INSTALL UPDATE
    </button>
    <p style="font-size:11px;color:var(--mt);text-align:center;margin-top:10px;">Your data and settings are never touched during an update.</p>
   </div>
  </div>
  <div class="ma-card">
   <div class="ma-card-h"><i class="fas fa-info-circle"></i>Current Version</div>
   <div class="ma-card-b">
    <div style="display:flex;align-items:center;gap:16px;padding:10px 0;">
     <div style="font-family:'JetBrains Mono',monospace;font-size:32px;font-weight:700;color:var(--f);">v<?php echo esc_html(MADEYA_VERSION); ?></div>
     <div>
      <div style="font-size:13px;font-weight:700;color:var(--tx);">Madeya Gas Station Queue</div>
      <div style="font-size:11px;color:var(--mt);">Upload a newer zip above to update</div>
     </div>
    </div>
   </div>
  </div>
 </div>
<!-- Password Reset Modal -->
<div id="pass-reset-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9000;align-items:center;justify-content:center;">
 <div style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:28px 24px;width:90%;max-width:400px;box-shadow:0 20px 60px rgba(0,0,0,.6);">
  <div style="font-size:16px;font-weight:900;color:var(--f);letter-spacing:2px;margin-bottom:6px;"><i class="fas fa-key" style="margin-right:8px;"></i>RESET PASSWORD</div>
  <div style="font-size:12px;color:var(--mt);margin-bottom:18px;">Controller: <b id="prm-username" style="color:var(--tx);"></b></div>
  <div class="ma-notice" id="prm-notice"></div>
  <div style="margin-bottom:12px;">
   <label style="display:block;font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);margin-bottom:5px;">New Username</label>
   <input type="text" id="prm-user" class="ma-inp" placeholder="Leave blank to keep current">
  </div>
  <div style="margin-bottom:18px;">
   <label style="display:block;font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);margin-bottom:5px;">New Password *</label>
   <input type="text" id="prm-pass" class="ma-inp" placeholder="Enter new password">
  </div>
  <div style="display:flex;gap:10px;">
   <button type="button" id="prm-btn" onclick="aDoResetPass()" style="flex:1;padding:12px;background:linear-gradient(135deg,#2980B9,#1a5276);border:none;border-radius:8px;color:#fff;font-family:'Barlow Condensed',sans-serif;font-size:14px;font-weight:800;letter-spacing:2px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;">
    <i class="fas fa-save"></i>SAVE
   </button>
   <button type="button" onclick="document.getElementById('pass-reset-modal').style.display='none'" style="padding:12px 18px;background:rgba(192,57,43,.1);border:1px solid rgba(192,57,43,.3);border-radius:8px;color:var(--rd);font-family:'Barlow Condensed',sans-serif;font-size:14px;font-weight:800;cursor:pointer;">
    CANCEL
   </button>
  </div>
 </div>
</div>
<div id="ma-toast"></div>

<script charset="utf-8">
(function($){
 'use strict';
 var AJ='<?php echo esc_js($ajaxurl);?>', NO='<?php echo esc_js($nonce);?>';

 /* Clock */
 function maTick(){var n=new Date();$('#ma-time').text(n.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit',second:'2-digit'}));}
 maTick();setInterval(maTick,1000);

 /* Toast */
 function aToast(m,t,dur){t=t||'ok';dur=dur||3500;var ic={ok:'fa-check-circle',warn:'fa-exclamation-triangle',err:'fa-times-circle'};var el=$('<div class="ma-toast-item '+t+'"><i class="fas '+ic[t]+'"></i><span>'+m+'</span></div>');$('#ma-toast').append(el);setTimeout(function(){el.fadeOut(300,function(){$(this).remove();});},dur);}

 /* Nonce refresh -- fixes stale nonce from page cache (SpeedyCache/LiteSpeed) */
 function refreshNonce(cb){
  $.post(AJ,{action:'madeya_refresh_nonce'},function(r){
   if(r.success&&r.data.nonce){NO=r.data.nonce;}
   cb();
  }).fail(cb);
 }

 /* Session check */
 $.post(AJ,{action:'madeya_admin_check',nonce:NO},function(r){
  if(r.success&&r.data.logged_in){showAdminApp(r.data);}
  else{$('#ma-ls').show();}
 }).fail(function(){$('#ma-ls').show();});

 /* Login */

 

 $('#al-pass').on('keypress',function(e){if(e.key==='Enter')aLogin();});
 // Backup: wire login button via jQuery in case onclick attr is blocked
 $(document).on('click','#al-btn',function(e){
  e.preventDefault(); e.stopPropagation();
  aLogin();
 });
 // Also allow Enter on username field
 $('#al-user').on('keypress',function(e){if(e.key==='Enter')$('#al-pass').focus();});
 window.aLogin=function(){
  var u=$('#al-user').val().trim(),p=$('#al-pass').val();
  if(!u||!p){showAErr('Enter username and password.');return;}
  var btn=$('#al-btn').prop('disabled',true).html('<i class="fas fa-circle-notch fa-spin"></i> SIGNING IN...');
  $('#al-err').removeClass('show');
  /* Always get a fresh nonce before logging in -- fixes 403/-1 from cached pages */
  refreshNonce(function(){
   $.post(AJ,{action:'madeya_admin_login',nonce:NO,username:u,password:p},function(r){
    if(r.success){showAdminApp(r.data);}
    else{showAErr(r.data&&r.data.message?r.data.message:'Login failed.');btn.prop('disabled',false).html('<i class="fas fa-sign-in-alt"></i> LOGIN AS ADMIN');}
   }).fail(function(){showAErr('Network error.');btn.prop('disabled',false).html('<i class="fas fa-sign-in-alt"></i> LOGIN AS ADMIN');});
  });
 };
 function showAErr(m){$('#al-err-msg').text(m);$('#al-err').removeClass('show');void $('#al-err')[0].offsetWidth;$('#al-err').addClass('show');}

 /* Logout */
 window.aLogout=function(){
  if(!confirm('Logout from admin panel?'))return;
  $.post(AJ,{action:'madeya_admin_logout',nonce:NO},function(){$('#ma-app').hide();$('#ma-ls').show();$('#al-user,#al-pass').val('');$('#al-btn').prop('disabled',false).html('<i class="fas fa-sign-in-alt"></i> LOGIN AS ADMIN');});
 };

 function showAdminApp(data){
  $('#ma-ls').hide();$('#ma-app').show();
  if(data&&data.requires_pw_change){$('#ma-pw-warning').addClass('show');}else{$('#ma-pw-warning').removeClass('show');}
  loadStats();loadControllers();aFuelPopulateControllers();loadDashPriority();aLoadPriorityTiers();
 }

 /* Tabs */
 window.aTab=function(tab){
  $('.ma-tab').removeClass('active');$('.ma-tab[data-tab="'+tab+'"]').addClass('active');
  $('.ma-section').removeClass('active');$('#tab-'+tab).addClass('active');
  if(tab==='dashboard')loadStats();
  if(tab==='controllers'){loadControllers();aLoadNewsStations();}
  if(tab==='fuel'){loadStats();aFuelPopulateControllers();}
  if(tab==='news'){aLoadNewsStations();aLoadNewsPreview();}
  if(tab==='priority')aLoadPriorityTiers();
  if(tab==='update')aLoadUpdateTab();
 };

 /* Stats */
 function loadStats(){
  $.post(AJ,{action:'madeya_admin_stats',nonce:NO},function(r){
   if(!r.success)return;var d=r.data;
   $('#st-queue').text(d.in_queue);$('#st-vip').text(d.vip_queue);$('#st-served').text(d.served_today);
   $('#st-ctrls').text(d.controllers);$('#st-online').text(d.online);
   // Fuel summary on dashboard
   if(d.fuel_stock!==undefined){
    $('#st-fuel-stock').text(d.fuel_stock.toFixed(1));
    $('#st-fuel-alloc').text(d.fuel_allocated.toFixed(1));
    $('#st-fuel-rem').text(d.fuel_remaining.toFixed(1));
    $('#st-fuel-today').text(d.gal_served_today.toFixed(1));
    var pct=d.fuel_pct||0;
    var col=pct>40?'#27AE60':pct>15?'#E67E22':'#e74c3c';
    $('#st-fuel-bar').css({'width':Math.min(pct,100)+'%','background':'linear-gradient(90deg,'+col+','+col+')'});
    $('#st-fuel-pct-lbl').text(pct+'%');
    $('#st-fuel-cap-lbl').text('Capacity: '+d.fuel_capacity.toFixed(0)+' L');
    // Pre-fill the fuel stock inputs
    $('#f-stock').val(d.fuel_stock);
    $('#f-cap').val(d.fuel_capacity>0?d.fuel_capacity:'');
    // ── Fuel depletion estimate ──
    if(d.depletion && d.depletion.minutes_remaining !== undefined){
     var dep = d.depletion;
     var minsLeft = dep.minutes_remaining;
     var urgency = minsLeft < 60 ? '#e74c3c' : minsLeft < 120 ? '#E67E22' : '#27AE60';
     var timeStr = minsLeft < 60 ? minsLeft + ' min' : Math.floor(minsLeft/60) + 'h ' + (minsLeft%60) + 'm';
     var deplHtml = '<div style="margin-top:10px;background:rgba(231,76,60,.08);border:1px solid rgba(231,76,60,.3);border-radius:8px;padding:10px 14px;display:flex;align-items:center;gap:10px;">'
      + '<i class="fas fa-exclamation-triangle" style="color:'+urgency+';font-size:18px;flex-shrink:0;"></i>'
      + '<div>'
      + '<div style="font-size:10px;color:var(--mt);letter-spacing:1.5px;text-transform:uppercase;margin-bottom:3px;">Fuel Depletion Estimate</div>'
      + '<div style="font-family:JetBrains Mono,monospace;font-size:16px;font-weight:700;color:'+urgency+';">Empty ~'+dep.estimated_empty+' (in '+timeStr+')</div>'
      + '<div style="font-size:11px;color:var(--mt);margin-top:2px;">'+dep.liters_per_hour.toFixed(1)+' L/hr at current rate</div>'
      + '</div></div>';
     $('#st-depletion').html(deplHtml).show();
    } else { $('#st-depletion').hide(); }
    // Update live summary panel
    var remColor=pct>40?'#27AE60':pct>15?'#E67E22':'#e74c3c';
    $('#fuel-summary-box').html(
     '<div style="display:grid;gap:12px;">'+
     '<div style="background:var(--cd);padding:12px 16px;border-radius:8px;display:flex;justify-content:space-between;align-items:center;">'+
      '<span style="font-size:12px;color:var(--mt)"><i class="fas fa-tint" style="color:'+remColor+'"></i> Remaining free</span>'+
      '<b style="font-family:JetBrains Mono,monospace;font-size:22px;color:'+remColor+'">'+d.fuel_remaining.toFixed(1)+' L</b></div>'+
     '<div style="background:var(--cd);padding:12px 16px;border-radius:8px;display:flex;justify-content:space-between;align-items:center;">'+
      '<span style="font-size:12px;color:var(--mt)"><i class="fas fa-car" style="color:var(--f)"></i> Allocated to queue</span>'+
      '<b style="font-family:JetBrains Mono,monospace;font-size:22px;color:var(--f)">'+d.fuel_allocated.toFixed(1)+' L</b></div>'+
     '<div style="background:var(--bd);border-radius:6px;height:12px;overflow:hidden;">'+
      '<div style="height:100%;background:linear-gradient(90deg,'+remColor+','+remColor+');border-radius:6px;width:'+Math.min(pct,100)+'%;transition:width .5s;"></div></div>'+
     '<div style="display:flex;justify-content:space-between;font-size:11px;color:var(--mt);">'+
      '<span>0 gal</span><span>'+pct+'% full</span><span>'+d.fuel_capacity.toFixed(0)+' gal cap</span></div>'+
     '</div>'
    );
   }
  });
 }

 /* Fuel tab */

 /* ------------------------------------------------------------
    FUEL STOCK -- PER-CONTROLLER PRIVATE QUOTA MANAGEMENT
    ------------------------------------------------------------ */
 var _fuelCtrlId   = null;
 var _fuelCtrlName = '';

 /* Populate controller dropdown when fuel tab opens */
 window.aFuelPopulateControllers = function() {
  $.post(AJ,{action:'madeya_admin_get_controllers',nonce:NO},function(r){
   if(!r.success) return;
   var sel=$('#f-ctrl-select').empty().append('<option value="">-- Choose a controller --</option>');
   r.data.controllers.forEach(function(c){
    var alloc=parseFloat(c.liters_allocated||0);
    var used=parseFloat(c.liters_used||0);
    var rem=Math.max(0,alloc-used);
    var tag=alloc>0?(' | '+rem.toFixed(0)+'L rem'):'';
    sel.append('<option value="'+c.id+'">'+esc(c.username)+' -- '+esc(c.station_name||'')+ ' ('+esc(c.vehicle_type)+')'+tag+'</option>');
   });
   // Also refresh the overview table
   aFuelLoadOverview(r.data.controllers);
  });
 };

 /* Load selected controller data */
 window.aFuelLoadController = function() {
  var id=parseInt($('#f-ctrl-select').val());
  if(!id){$('#f-ctrl-panel').hide();return;}
  _fuelCtrlId=id;
  $.post(AJ,{action:'madeya_admin_get_ctrl_quota',nonce:NO,id:id},function(r){
   if(!r.success){aToast('Error loading controller','err');return;}
   var c=r.data.controller, q=r.data.quota;
   _fuelCtrlName=c.full_name||c.username;
   $('#f-ctrl-name,#f-ctrl-name2,#f-ctrl-name3').text(_fuelCtrlName+' ('+c.vehicle_type+')');
   // Pre-fill quota fields
   $('#f-stock').val(q.liters_allocated||'');
   $('#f-cap').val('');
   $('#f-note').val('');
   $('#f-reset-used').prop('checked',false);
   // Show quota bar
   var alloc=q.liters_allocated, used=q.liters_used, rem=q.liters_remaining;
   var pct=alloc>0?Math.min(100,Math.round((used/alloc)*100)):0;
   var col=pct>85?'var(--rd)':pct>60?'var(--or)':'var(--gn)';
   $('#f-ctrl-used').text(used.toFixed(1)+' L').css('color',col);
   $('#f-ctrl-rem').text(rem.toFixed(1)+' / '+(alloc||'Inf')+' L');
   $('#f-ctrl-bar').css({'width':pct+'%','background':col});
   $('#f-ctrl-quota-display').show();
   // Private summary card
   var remPct=alloc>0?Math.min(100,Math.round((rem/alloc)*100)):null;
   var remCol=remPct===null?'var(--mt)':remPct>40?'var(--gn)':remPct>15?'var(--or)':'var(--rd)';
   $('#fuel-summary-box').html(
    '<div style="display:grid;gap:10px;">'+
    '<div style="background:var(--cd);padding:12px 16px;border-radius:8px;display:flex;justify-content:space-between;align-items:center;">'+
     '<span style="font-size:12px;color:var(--mt);"><i class="fas fa-tint" style="color:'+remCol+'"></i> Remaining Quota</span>'+
     '<b style="font-family:JetBrains Mono,monospace;font-size:22px;color:'+remCol+'">'+rem.toFixed(1)+' L</b></div>'+
    '<div style="background:var(--cd);padding:12px 16px;border-radius:8px;display:flex;justify-content:space-between;align-items:center;">'+
     '<span style="font-size:12px;color:var(--mt);"><i class="fas fa-check-circle" style="color:var(--f)"></i> Dispensed (used)</span>'+
     '<b style="font-family:JetBrains Mono,monospace;font-size:22px;color:var(--f)">'+used.toFixed(1)+' L</b></div>'+
    '<div style="background:var(--cd);padding:12px 16px;border-radius:8px;display:flex;justify-content:space-between;align-items:center;">'+
     '<span style="font-size:12px;color:var(--mt);"><i class="fas fa-database" style="color:var(--bl)"></i> Total Quota</span>'+
     '<b style="font-family:JetBrains Mono,monospace;font-size:22px;color:var(--bl)">'+(alloc>0?alloc.toFixed(1)+' L':'Unlimited')+'</b></div>'+
    (alloc>0?'<div style="background:var(--bd);border-radius:6px;height:10px;overflow:hidden;"><div style="height:100%;background:linear-gradient(90deg,'+remCol+','+remCol+');border-radius:6px;width:'+(remPct||0)+'%;transition:width .5s;"></div></div>':'')
    +'</div>'
   );
   // Load fuel log for this controller
   aFuelLoadLog(id);
   $('#f-ctrl-panel').show();
  });
 };

 /* Save quota for selected controller */
 window.aSetFuel = function() {
  if(!_fuelCtrlId){showFuelNotice('err','Please select a controller first.');return;}
  var liters=parseFloat($('#f-stock').val())||0;
  var cap=parseFloat($('#f-cap').val())||0;
  var note=$('#f-note').val().trim();
  var reset=$('#f-reset-used').is(':checked')?1:0;
  if(liters<0){showFuelNotice('err','Amount cannot be negative.');return;}
  $.post(AJ,{action:'madeya_admin_set_ctrl_quota',nonce:NO,
   id:_fuelCtrlId,liters_allocated:liters,
   station_lat:0,station_lng:0,station_address:'',
   reset_used:reset},function(r){
   if(r.success){
    // Also log the fuel note
    if(note){
     $.post(AJ,{action:'madeya_admin_set_fuel',nonce:NO,liters:liters,capacity:cap,note:'['+esc(_fuelCtrlName)+'] '+note});
    }
    showFuelNotice('ok','[OK] Quota saved: '+liters.toFixed(1)+' L for '+_fuelCtrlName+(reset?' | Counter reset':''));
    $('#f-note').val('');$('#f-reset-used').prop('checked',false);
    aFuelLoadController();
    aFuelPopulateControllers();
    aToast(_fuelCtrlName+' quota -> '+liters.toFixed(0)+' L','ok');
   } else showFuelNotice('err','[ERR] '+(r.data&&r.data.message?r.data.message:'Error'));
  }).fail(function(){showFuelNotice('err','Network error.');});
 };

 /* Load fuel log for a specific controller */
 function aFuelLoadLog(ctrlId) {
  $.post(AJ,{action:'madeya_admin_fuel_log',nonce:NO,ctrl_id:ctrlId},function(r){
   var tb=$('#fuel-log-body').empty();
   if(!r.success||!r.data.log.length){
    tb.html('<tr><td colspan="4" style="text-align:center;padding:20px;color:var(--mt)">No fuel log entries yet.</td></tr>');return;
   }
   r.data.log.forEach(function(row){
    var dt=new Date(row.created_at.replace(' ','T'));
    var ts=dt.toLocaleDateString()+' '+dt.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'});
    var actCol=row.action==='set'?'var(--gn)':row.action==='deduct'?'var(--rd)':'var(--f)';
    var sign=row.action==='deduct'?'-':'';
    tb.append('<tr>'+
     '<td style="font-size:11px;color:var(--mt);font-family:JetBrains Mono,monospace;">'+ts+'</td>'+
     '<td><span style="color:'+actCol+';font-weight:700;text-transform:uppercase;font-size:11px;">'+row.action+'</span></td>'+
     '<td style="font-family:JetBrains Mono,monospace;color:'+actCol+';font-weight:700;">'+sign+parseFloat(row.gallons).toFixed(1)+' L</td>'+
     '<td style="font-size:12px;color:var(--mt);">'+esc(row.note||'--')+'</td>'+
    '</tr>');
   });
  });
 }

 /* Keep old name for compatibility */
 function loadFuelLog(){ if(_fuelCtrlId) aFuelLoadLog(_fuelCtrlId); }

 /* Overview table -- all controllers at a glance */
 function aFuelLoadOverview(controllers) {
  var tb=$('#fuel-overview-body').empty();
  if(!controllers||!controllers.length){
   tb.html('<tr><td colspan="8" style="text-align:center;padding:20px;color:var(--mt)">No controllers.</td></tr>');return;
  }
  controllers.forEach(function(c){
   var alloc=parseFloat(c.liters_allocated||0);
   var used=parseFloat(c.liters_used||0);
   var rem=Math.max(0,alloc-used);
   var pct=alloc>0?Math.min(100,Math.round((rem/alloc)*100)):null;
   var col=pct===null?'var(--mt)':pct>40?'var(--gn)':pct>15?'var(--or)':'var(--rd)';
   var pctBar=pct!==null?'<div style="background:var(--bd);border-radius:3px;height:5px;overflow:hidden;margin-top:3px;"><div style="height:100%;width:'+pct+'%;background:'+col+';border-radius:3px;"></div></div>':'';
   tb.append('<tr>'+
    '<td style="font-weight:700;">'+esc(c.username)+'<div style="font-size:10px;color:var(--mt);">'+esc(c.full_name||'')+'</div></td>'+
    '<td style="font-size:12px;color:var(--mt);">'+esc(c.station_name||'--')+'</td>'+
    '<td><span style="background:rgba(245,166,35,.1);color:var(--f);padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;">'+esc(c.vehicle_type||'--')+'</span></td>'+
    '<td style="font-family:JetBrains Mono,monospace;font-weight:700;color:var(--bl);">'+(alloc>0?alloc.toFixed(0)+' L':'Inf')+'</td>'+
    '<td style="font-family:JetBrains Mono,monospace;color:var(--f);">'+used.toFixed(1)+' L</td>'+
    '<td style="font-family:JetBrains Mono,monospace;color:'+col+';font-weight:700;">'+rem.toFixed(1)+' L'+pctBar+'</td>'+
    '<td style="font-weight:700;color:'+col+';">'+(pct!==null?pct+'%':'Inf')+'</td>'+
    '<td><button class="ma-btn gold" style="padding:5px 10px;font-size:11px;" onclick="aFuelSelectCtrl('+c.id+')"><i class="fas fa-edit"></i>SELECT</button></td>'+
   '</tr>');
  });
 }

 window.aFuelSelectCtrl = function(id) {
  $('#f-ctrl-select').val(id);
  aFuelLoadController();
  // Scroll to the form
  document.getElementById('f-ctrl-panel').scrollIntoView({behavior:'smooth'});
 };

 function showFuelNotice(t,m){$('#fuel-notice').attr('class','ma-notice show '+t).text(m);setTimeout(function(){$('#fuel-notice').removeClass('show');},5000);}

 /* Load controllers */
 function loadControllers(){
  $.post(AJ,{action:'madeya_admin_get_controllers',nonce:NO},function(r){
   if(!r.success)return;
   var tb=$('#ctrl-tbody').empty();
   if(!r.data.controllers.length){tb.html('<tr><td colspan="10" style="text-align:center;padding:24px;color:var(--mt)">No controllers yet.</td></tr>');return;}
   r.data.controllers.forEach(function(c){
    var isOn=c.active_token&&c.active_token!=='';
    var parCls=c.allowed_parity==='even'?'even':c.allowed_parity==='odd'?'odd':'both';
    var parLbl=c.allowed_parity==='even'?'ሙሉ Even':c.allowed_parity==='odd'?'ጎዶሎ Odd':'Both';
    var statusHtml=isOn?'<span class="online-dot"></span><span style="color:#27AE60;font-weight:700;">Online</span><br><small style="color:var(--mt);font-size:10px;">'+esc(c.active_ip||'')+'</small>':'<span class="offline-dot"></span><span style="color:var(--mt)">Offline</span>';
    var flBtn=isOn?'<button class="ma-btn red" style="padding:5px 10px;font-size:11px;" onclick="aForceLogout('+c.id+')"><i class="fas fa-sign-out-alt"></i> KICK</button> ':'';
    var fAlloc=parseFloat(c.liters_allocated||c.fuel_allocated||0);
    var fUsed=parseFloat(c.liters_used||c.fuel_used||0);
    var fRem=Math.max(0,fAlloc-fUsed);
    var fPct=fAlloc>0?Math.min(100,Math.round((fRem/fAlloc)*100)):null;
    var fCol=fPct===null?'var(--mt)':fPct>40?'#27AE60':fPct>15?'#E67E22':'#e74c3c';
    var fuelHtml=fAlloc>0?
     '<div style="min-width:120px;"><div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:3px;"><span style="color:'+fCol+';font-weight:700;font-family:JetBrains Mono,monospace;">'+fRem.toFixed(1)+'/'+fAlloc.toFixed(0)+' L</span><span style="color:var(--mt)">'+fPct+'%</span></div><div style="background:var(--bd);border-radius:3px;height:5px;overflow:hidden;"><div style="height:100%;width:'+fPct+'%;background:'+fCol+';border-radius:3px;"></div></div></div>':
     '<span style="color:var(--mt);font-size:11px;">Unlimited</span>';
    var editFuelBtn='<button class="ma-btn gold" style="padding:5px 10px;font-size:11px;margin-bottom:4px;" onclick="aOpenCtrlDetail('+c.id+')"><i class="fas fa-edit"></i> QUOTA</button> ';
    // ── Today's performance metrics ──
    var servedToday = parseInt(c.served_today) || 0;
    var litersToday = parseFloat(c.liters_today) || 0;
    var todayHtml = servedToday > 0
     ? '<div style="text-align:center;"><div style="font-family:JetBrains Mono,monospace;font-size:16px;font-weight:700;color:#27AE60;">'+servedToday+'</div><div style="font-size:9px;color:var(--mt);">vehicles</div><div style="font-family:JetBrains Mono,monospace;font-size:11px;color:var(--f);">'+litersToday.toFixed(1)+' L</div></div>'
     : '<span style="color:var(--mt);font-size:11px;">—</span>';
    tb.append('<tr>'+
     '<td style="font-family:\'JetBrains Mono\',monospace;color:var(--mt)">'+c.id+'</td>'+
     '<td style="font-weight:700">'+esc(c.username)+'</td>'+
     '<td>'+esc(c.full_name||'--')+'</td>'+
     '<td style="font-size:12px;color:var(--mt)">'+esc(c.station_name||'--')+'</td>'+
     '<td><span style="background:rgba(245,166,35,.1);color:var(--f);padding:3px 8px;border-radius:4px;font-size:11px;font-weight:700;">'+esc(c.vehicle_type)+'</span></td>'+
     '<td><span class="par-chip2 '+parCls+'">'+parLbl+'</span></td>'+
     '<td>'+fuelHtml+'</td>'+
     '<td>'+todayHtml+'</td>'+
     '<td>'+statusHtml+'</td>'+
     '<td style="white-space:nowrap">'+editFuelBtn+flBtn+'<button class="ma-btn red" style="padding:5px 10px;font-size:11px;" onclick="aDelCtrl('+c.id+',\''+esc(c.username)+'\')">'+
    '</tr>');
   });
  });
 }

 /* Edit fuel quota */
 window.aEditFuel=function(id,username,current){
  var val=prompt('Set fuel quota for "'+username+'" (gallons).\nEnter 0 for unlimited.\n\nCurrent: '+current+' gal',current);
  if(val===null)return;
  var g=parseFloat(val);
  if(isNaN(g)||g<0){aToast('Invalid value','err');return;}
  $.post(AJ,{action:'madeya_admin_set_ctrl_fuel',nonce:NO,id:id,fuel_allocated:g},function(r){
   if(r.success){aToast('Quota -> '+g+' L for '+username,'ok');loadControllers();}
   else aToast(r.data&&r.data.message?r.data.message:'Error','err');
  }).fail(function(){aToast('Network error','err');});
 };

  /* Add controller */
 window.aAddCtrl=function(){
  var fuel=parseFloat($('#nc-fuel').val())||0;
  var data={action:'madeya_admin_add_ctrl',nonce:NO,
   username:$('#nc-user').val().trim(),password:$('#nc-pass').val(),
   full_name:$('#nc-name').val().trim(),station_name:$('#nc-station').val().trim(),
   vehicle_type:$('#nc-vtype').val(),allowed_parity:$('#nc-parity').val(),
   fuel_allocated:fuel};
  if(!data.username||!data.password||!data.vehicle_type){showCtrlNotice('err','Username, password and vehicle type are required.');return;}
  if(data.password.length<8){showCtrlNotice('err','Password must be at least 8 characters.');return;}
  var btn=$('#nc-btn').prop('disabled',true).html('<i class="fas fa-circle-notch fa-spin"></i> ADDING...');
  $.post(AJ,data,function(r){
   btn.prop('disabled',false).html('<i class="fas fa-plus"></i> ADD CONTROLLER');
   if(r.success){
    showCtrlNotice('ok','[OK] '+r.data.message+(fuel>0?' | Quota: '+fuel+' gal':''));
    $('#nc-user,#nc-pass,#nc-name,#nc-fuel').val('');
    loadControllers();loadStats();aToast('Controller added','ok');
   } else showCtrlNotice('err','[ERR] '+(r.data&&r.data.message?r.data.message:'Error'));
  }).fail(function(){btn.prop('disabled',false).html('<i class="fas fa-plus"></i> ADD CONTROLLER');showCtrlNotice('err','Network error.');});
 };

 /* Delete controller */
 window.aDelCtrl=function(id,name){
  if(!confirm('Remove controller "'+name+'"? This cannot be undone.'))return;
  $.post(AJ,{action:'madeya_admin_del_ctrl',nonce:NO,id:id},function(r){
   if(r.success){aToast('Controller removed','warn');loadControllers();loadStats();}
   else aToast(r.data&&r.data.message?r.data.message:'Error','err');
  });
 };

 /* Force logout */
 window.aForceLogout=function(id){
  if(!confirm('Force-logout this controller? They will be kicked immediately.'))return;
  $.post(AJ,{action:'madeya_admin_force_logout',nonce:NO,id:id},function(r){
   if(r.success){aToast('Controller kicked','warn');loadControllers();}
   else aToast(r.data&&r.data.message?r.data.message:'Error','err');
  });
 };

 /* Save settings */
 window.aSaveSettings=function(){
  var types=[];$('#s-vtypes input:checked').each(function(){types.push($(this).val());});
  var data={
      action:'madeya_admin_save_settings',
      nonce:NO,
      station_name:$('#s-station').val().trim(),
      allowed_parity:$('#s-parity').val(),
      admin_username:$('#s-auser').val().trim(),
      admin_email:$('#s-aemail').val().trim(),
      admin_password:$('#s-apass').val(),
      google_maps_key:$('#s-gmaps-key').val().trim()
  };
  types.forEach(function(t,i){data['allowed_types['+i+']']=t;});
  $.post(AJ,data,function(r){
   if(r.success){showSettNotice('ok','[OK] '+r.data.message);$('#s-apass').val('');$('#ma-station-name').text($('#s-station').val());aToast('Settings saved','ok');}
   else showSettNotice('err','[ERR] '+(r.data&&r.data.message?r.data.message:'Error'));
  }).fail(function(){showSettNotice('err','Network error.');});
 };

 /* Clear queue */
 window.aClearQueue=function(type){
  var labels={normal:'Normal Queue',vip:'VIP Queue',all:'ALL Queues'};
  if(!confirm('Clear '+labels[type]+'? This cannot be undone.'))return;
  $.post(AJ,{action:'madeya_admin_clear_queue',nonce:NO,queue_type:type},function(r){
   if(r.success){aToast(labels[type]+' cleared','warn');loadStats();}
   else aToast(r.data&&r.data.message?r.data.message:'Error','err');
  });
 };

 /* Notices */
 function showCtrlNotice(t,m){$('#ctrl-notice').attr('class','ma-notice show '+t).text(m);setTimeout(function(){$('#ctrl-notice').removeClass('show');},5000);}
 function showSettNotice(t,m){$('#sett-notice').attr('class','ma-notice show '+t).text(m);setTimeout(function(){$('#sett-notice').removeClass('show');},5000);}

 /* Escape HTML */
 function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

 /* Auto-refresh dashboard every 15s */
 setInterval(function(){if($('#tab-dashboard').hasClass('active'))loadStats();},15000);


 /* ------------------------------------------------------------
    DAILY NEWS MANAGEMENT
    ------------------------------------------------------------ */
 /* Build vehicle type selector for news tab */
 (function(){
  var allTypes=[
   {k:'oil_transport',l:'Oil Transport'},{k:'industry_export',l:'Industry/Export'},
   {k:'construction',l:'Construction'},{k:'cargo',l:'Cargo & Goods'},
   {k:'agriculture',l:'Agriculture'},{k:'public_bus',l:'Public Bus'},
   {k:'minibus',l:'Minibus Taxi'},{k:'bajaj',l:'Bajaj'},
   {k:'damas',l:'Damas'},{k:'private',l:'Private Car'},
   {k:'motorcycle',l:'Motorcycle'},{k:'govt_moto',l:'Govt. Moto'},
  ];
  var grid=$('#news-vtype-grid');
  allTypes.forEach(function(t){
   grid.append('<label style="display:flex;align-items:center;gap:5px;padding:5px 10px;background:var(--cd);border:1px solid var(--bd);border-radius:7px;cursor:pointer;font-size:12px;font-weight:600;color:var(--tx);">'+
    '<input type="checkbox" name="news_vtype" value="'+t.k+'" style="margin:0;"> '+t.l+'</label>');
  });
 })();

 window.aLoadNewsStations = function() {
  $.post(AJ,{action:'madeya_get_station_locations',nonce:NO},function(r){
   if(!r.success||!r.data.stations.length){
    $('#news-station-list').html('<div style="text-align:center;padding:24px;color:var(--mt);font-size:12px;"><i class="fas fa-map-marker-alt" style="display:block;font-size:28px;opacity:.3;margin-bottom:8px;"></i>No stations with location set.<br>Add coordinates in each controller&#39;s quota settings.</div>');
    return;
   }
   var list=$('#news-station-list').empty();
   r.data.stations.forEach(function(s){
    var fCol=s.liters_rem>50?'var(--gn)':s.liters_rem>10?'var(--or)':'var(--rd)';
    list.append('<div style="padding:10px 14px;border-bottom:1px solid var(--bd);display:flex;align-items:center;justify-content:space-between;">'+
     '<div><div style="font-size:13px;font-weight:700;color:var(--tx);">'+s.name+'</div>'+
     '<div style="font-size:11px;color:var(--mt);">'+s.address+'</div>'+
     '<div style="font-size:11px;color:var(--bl);">'+s.vehicle_type+'</div></div>'+
     '<div style="text-align:right;">'+
     '<div style="font-family:JetBrains Mono,monospace;font-size:13px;font-weight:700;color:'+fCol+';">'+s.liters_rem.toFixed(0)+' L</div>'+
     '<div style="font-size:10px;color:var(--mt);">'+s.lat.toFixed(4)+', '+s.lng.toFixed(4)+'</div></div>'+
    '</div>');
   });
   window._newsStations = r.data.stations;
  });
 };

 window.aPostNews = function() {
  var text=$('#news-text').val().trim();
  if(!text){showNewsNotice('err','Announcement text is required.');return;}
  var vtypes=[];
  $('input[name="news_vtype"]:checked').each(function(){vtypes.push($(this).val());});
  var stations = window._newsStations||[];
  var expires=$('#news-expires').val();
  $.post(AJ,{action:'madeya_admin_post_news',nonce:NO,news_text:text,
   'active_vehicle_types[]':vtypes,
   stations:JSON.stringify(stations),expires_hours:expires},function(r){
   if(r.success){
    showNewsNotice('ok','[OK] Update posted! '+r.data.stations+' stations included. Expires: '+r.data.expires);
    aLoadNewsPreview();
    aToast('Daily update posted','ok');
   } else showNewsNotice('err','[ERR] '+(r.data&&r.data.message?r.data.message:'Error'));
  }).fail(function(){showNewsNotice('err','Network error.');});
 };

 window.aLoadNewsPreview = function() {
  $.post(AJ,{action:'madeya_get_daily_news'},function(r){
   var box=$('#news-preview');
   if(!r.success||!r.data.has_news){
    box.html('<div style="text-align:center;padding:20px;color:var(--mt);font-size:12px;">No active update posted yet.</div>');
    return;
   }
   var d=r.data;
   var chips=(d.active_types||[]).map(function(k){
    return '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;background:rgba(245,166,35,.1);border:1px solid rgba(245,166,35,.25);color:var(--f);margin:2px;">'+k+'</span>';
   }).join('');
   var stationRows=(d.stations||[]).map(function(s){
    return '<div style="font-size:12px;padding:6px 0;border-bottom:1px solid var(--bd);display:flex;justify-content:space-between;"><span style="color:var(--tx);">'+s.name+'</span><span style="color:var(--mt);font-size:11px;">'+s.address+'</span></div>';
   }).join('');
   box.html('<div style="background:rgba(245,166,35,.06);border:1px solid rgba(245,166,35,.2);border-radius:10px;padding:14px;">'+
    '<div style="font-size:13px;color:var(--tx);margin-bottom:10px;">'+d.text+'</div>'+
    '<div style="margin-bottom:8px;">'+chips+'</div>'+
    (stationRows?'<div>'+stationRows+'</div>':'')+
    '<div style="font-size:10px;color:var(--mt);margin-top:10px;"><i class="fas fa-clock"></i> Expires: '+d.expires_at+'</div>'+
   '</div>');
  });
 };

 function showNewsNotice(t,m){$('#news-notice').attr('class','ma-notice show '+t).html(m);setTimeout(function(){$('#news-notice').removeClass('show');},7000);}

 /* ------------------------------------------------------------
    PRIORITY TIERS MANAGEMENT
    ------------------------------------------------------------ */
 var _priorityRules = {};

 window.aLoadPriorityTiers = function() {
  $.post(AJ,{action:'madeya_get_priority_tiers',nonce:NO},function(r){
   if(!r.success) return;
   var container=$('#priority-tiers-list').empty();
   r.data.tiers.forEach(function(t){
    _priorityRules[t.tier]={enabled:t.enabled,note:t.note};
    container.append(
     '<div style="background:var(--sf);border:1px solid var(--bd);border-radius:10px;margin-bottom:10px;overflow:hidden;">'+
     '<div style="background:var(--pn);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);padding:11px 16px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--bd);">'+
      '<div style="width:32px;height:32px;border-radius:50%;background:'+t.color+';display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:900;color:#fff;flex-shrink:0;">'+t.tier+'</div>'+
      '<div style="flex:1;">'+
       '<div style="font-size:13px;font-weight:800;color:var(--tx);"><i class="fas '+t.icon+'" style="color:'+t.color+';margin-right:6px;font-size:11px;"></i>'+t.label+'</div>'+
       '<div style="font-size:11px;color:var(--mt);">'+t.amh+' . '+t.desc+'</div>'+
      '</div>'+
      '<label style="display:flex;align-items:center;gap:7px;cursor:pointer;">'+
       '<input type="checkbox" class="priority-enabled" data-tier="'+t.tier+'" '+(t.enabled?'checked':'')+' style="width:16px;height:16px;">'+
       '<span style="font-size:12px;font-weight:700;color:var(--tx);">Active</span>'+
      '</label>'+
     '</div>'+
     '<div style="padding:10px 16px;">'+
      '<input type="text" class="priority-note ma-inp" data-tier="'+t.tier+'" value="'+esc(t.note)+'" placeholder="Optional note (shown to drivers)" style="font-size:12px;">'+
     '</div></div>'
    );
   });
  });
 };

 window.aSavePriority = function() {
  var rules={};
  $('.priority-enabled').each(function(){
   var tier=$(this).data('tier');
   var note=$('.priority-note[data-tier="'+tier+'"]').val()||'';
   rules[tier]={enabled:$(this).is(':checked'),note:note};
  });
  $.post(AJ,{action:'madeya_admin_save_priority',nonce:NO,rules:JSON.stringify(rules)},function(r){
   if(r.success){aToast('Priority rules saved','ok');showPriorityNotice('ok','[OK] '+r.data.message);}
   else showPriorityNotice('err','[ERR] '+(r.data&&r.data.message?r.data.message:'Error'));
  });
 };
 function showPriorityNotice(t,m){$('#priority-notice').attr('class','ma-notice show '+t).html(m);setTimeout(function(){$('#priority-notice').removeClass('show');},5000);}

 /* ------------------------------------------------------------
    PER-CONTROLLER PRIVATE QUOTA MODAL
    ------------------------------------------------------------ */
 var _cdmCtrlId = null;

 window.aOpenCtrlDetail = function(id) {
  _cdmCtrlId = id;
  $('#cdm-queue').html('<div style="color:var(--mt);text-align:center;padding:10px;">Loading...</div>');
  $('#cdm-bar').css({'width':'0%','background':'var(--gn)'});
  $('#cdm-used,#cdm-rem').text('--');
  $.post(AJ,{action:'madeya_admin_get_ctrl_quota',nonce:NO,id:id},function(r){
   if(!r.success){aToast(r.data&&r.data.message?r.data.message:'Error','err');return;}
   var d=r.data;
   var c=d.controller, q=d.quota;
   $('#cdm-title').text(c.full_name||c.username);
   $('#cdm-sub').text(c.station_name+' . '+c.vehicle_type+' . Parity: '+c.parity);
   // Quota bar
   var pct = q.pct!==null ? q.pct : 100;
   var col = pct>40?'var(--gn)':pct>15?'var(--or)':'var(--rd)';
   $('#cdm-used').text(q.liters_used.toFixed(1)+' L used').css('color',col);
   $('#cdm-rem').text(q.liters_remaining.toFixed(1)+' / '+(q.liters_allocated||'Inf')+' L');
   $('#cdm-bar').css({'width':(q.liters_allocated>0?Math.min(100,(q.liters_used/q.liters_allocated)*100):0)+'%','background':col});
   // Pre-fill form
   $('#cdm-liters').val(q.liters_allocated||'');
   $('#cdm-lat').val(c.lat||'');
   $('#cdm-lng').val(c.lng||'');
   $('#cdm-address').val(c.address||'');
   $('#cdm-reset').prop('checked',false);
   // Map link
   if(c.lat&&c.lng){
    $('#cdm-map-link').attr('href','https://www.google.com/maps?q='+c.lat+','+c.lng).show();
   } else { $('#cdm-map-link').hide(); }
   // Private queue data
   if(!d.queue||!d.queue.length){
    $('#cdm-queue').html('<div style="color:var(--mt);text-align:center;padding:10px;">No vehicles in queue for this controller right now.</div>');
   } else {
    var rows=d.queue.map(function(v){
     return '<div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--bd);">'+
      '<span style="font-family:JetBrains Mono,monospace;color:var(--f);">'+v.plate+'</span>'+
      '<span style="color:var(--mt);">#'+v.position+'</span>'+
      '<span style="color:var(--gn);">'+parseFloat(v.liters||0).toFixed(1)+' L</span>'+
      '<span style="color:var(--mt);font-size:10px;">'+v.queue_type+'</span>'+
     '</div>';
    });
    $('#cdm-queue').html('<div style="font-size:11px;">'+rows.join('')+
     '<div style="margin-top:8px;padding-top:8px;border-top:1px solid var(--bd);color:var(--mt);">Served today: <b style="color:var(--gn);">'+d.served_liters_today.toFixed(1)+' L</b></div></div>');
   }
   $('#ctrl-detail-modal').addClass('show');
  });
 };

 window.cdmClose = function() { $('#ctrl-detail-modal').removeClass('show'); _cdmCtrlId=null; };
 $('#ctrl-detail-modal').on('click',function(e){if($(e.target).is('#ctrl-detail-modal'))cdmClose();});

 window.cdmSave = function() {
  if(!_cdmCtrlId) return;
  var liters=parseFloat($('#cdm-liters').val())||0;
  var lat=parseFloat($('#cdm-lat').val())||0;
  var lng=parseFloat($('#cdm-lng').val())||0;
  var addr=$('#cdm-address').val().trim();
  var reset=$('#cdm-reset').is(':checked')?1:0;
  $.post(AJ,{action:'madeya_admin_set_ctrl_quota',nonce:NO,id:_cdmCtrlId,
   liters_allocated:liters,station_lat:lat,station_lng:lng,station_address:addr,reset_used:reset},function(r){
   if(r.success){
    aToast('Quota updated: '+r.data.liters_allocated+' L','ok');
    cdmClose();
    loadControllers();
   } else aToast(r.data&&r.data.message?r.data.message:'Error','err');
  });
 };

 /* -- Update QUOTA button in controllers table to open detail modal -- */
 window.aEditFuel=function(id,username,current){
  aOpenCtrlDetail(id);
 };

 /* -- Update fuel display in controllers table to show L -- */
 // Already handled by loadControllers() since we patched the JS label above



 /* Load priority tier mini-preview on dashboard */
 function loadDashPriority(){
  $.post(AJ,{action:'madeya_get_priority_tiers'},function(r){
   if(!r.success)return;
   var tiers=r.data.tiers||[];
   var html=tiers.map(function(t){
    return '<div style="display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04);">'
     +'<div style="width:22px;height:22px;border-radius:50%;background:'+t.color+';display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:900;color:#fff;flex-shrink:0;">'+t.tier+'</div>'
     +'<div style="flex:1;min-width:0;">'
      +'<div style="font-size:12px;font-weight:700;color:var(--tx);">'+t.label+'</div>'
      +'<div style="font-size:10px;color:var(--mt);">'+t.amh+'</div>'
     +'</div>'
     +'<span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;background:'+(t.enabled?'rgba(39,174,96,.15)':'rgba(192,57,43,.1)')+';color:'+(t.enabled?'#27AE60':'#C0392B')+';">'+(t.enabled?'ACTIVE':'OFF')+'</span>'
     +'</div>';
   }).join('');
   $('#dash-priority-preview').html(html||'<div style="color:var(--mt);font-size:12px;padding:8px;">No tiers configured.</div>');
  });
 }


 // -- Password Reset -----------------------------------------
 var _prmCtrlId = null;
 window.aResetPassModal = function(id, username) {
  _prmCtrlId = id;
  $('#prm-username').text(username);
  $('#prm-user').val('');
  $('#prm-pass').val('');
  $('#prm-notice').removeClass('show ok err').text('');
  $('#pass-reset-modal').css('display','flex');
 };
 window.aDoResetPass = function() {
  var newPass = $('#prm-pass').val().trim();
  var newUser = $('#prm-user').val().trim();
  if(!newPass){ $('#prm-notice').attr('class','ma-notice show err').text('Password is required.'); return; }
  var btn = $('#prm-btn').prop('disabled',true).html('<i class="fas fa-circle-notch fa-spin"></i> Saving...');
  $.post(AJ,{action:'madeya_admin_reset_pass',nonce:NO,ctrl_id:_prmCtrlId,new_pass:newPass,new_user:newUser},function(r){
   btn.prop('disabled',false).html('<i class="fas fa-save"></i>SAVE');
   if(r.success){
    $('#prm-notice').attr('class','ma-notice show ok').text('? Password updated successfully.');
    setTimeout(function(){ $('#pass-reset-modal').css('display','none'); loadControllers(); },1500);
   } else {
    $('#prm-notice').attr('class','ma-notice show err').text('? '+(r.data&&r.data.message?r.data.message:'Failed.'));
   }
  });
 };

 // -- Update Tab ---------------------------------------------
 var _updZip = null;
 window.aLoadUpdateTab = function() { /* nothing to preload */ };
 window.aHandleZip = function(file) {
  if(!file||!file.name.endsWith('.zip')){ alert('Please select a .zip file.'); return; }
  _updZip = file;
  $('#upd-fname').text('?? '+file.name+' ('+(file.size/1024/1024).toFixed(2)+' MB)').show();
  $('#upd-btn').prop('disabled',false).css('opacity','1');
 };
 window.aInstallZip = function() {
  if(!_updZip){ alert('No file selected.'); return; }
  var btn=$('#upd-btn').prop('disabled',true).html('<i class="fas fa-circle-notch fa-spin"></i> Installing...');
  showUpdNotice('info','? Uploading and installing... please wait.');
  var fd=new FormData();
  fd.append('action','madeya_upload_install_zip');
  fd.append('nonce',NO);
  fd.append('zip_file',_updZip);
  fetch(AJ,{method:'POST',body:fd})
   .then(function(r){return r.json();})
   .then(function(r){
    btn.prop('disabled',false).html('<i class="fas fa-upload"></i>INSTALL UPDATE');
    if(r.success){
     showUpdNotice('ok','? '+r.data.message+'\n\nReloading in 3 seconds...');
     setTimeout(function(){location.reload();},3000);
    } else {
     showUpdNotice('err','? '+(r.data&&r.data.message?r.data.message:'Installation failed.'));
    }
   })
   .catch(function(){ btn.prop('disabled',false).html('<i class="fas fa-upload"></i>INSTALL UPDATE'); showUpdNotice('err','? Network error.'); });
 };
 function showUpdNotice(t,m){
  var el=$('#upd-notice');
  var map={ok:{bg:'rgba(39,174,96,.1)',b:'rgba(39,174,96,.3)',c:'#27AE60'},err:{bg:'rgba(192,57,43,.08)',b:'rgba(192,57,43,.3)',c:'#C0392B'},info:{bg:'rgba(41,128,185,.08)',b:'rgba(41,128,185,.3)',c:'#2980B9'}};
  var s=map[t]||map.info;
  el.attr('class','ma-notice show').css({'background':s.bg,'border-color':s.b,'color':s.c,'white-space':'pre-line'}).text(m);
 }
 })(jQuery);
</script>
<?php
    return ob_get_clean();
}
