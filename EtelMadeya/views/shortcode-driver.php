<?php
/**
 * Shortcode: [madeya_driver] — the driver queue position lookup page.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'madeya_driver', 'madeya_sc_driver' );

function madeya_sc_driver() {
    $em_logo = defined( 'MADEYA_LOGO_URL' ) ? MADEYA_LOGO_URL : '';
    $em_bg = defined( 'MADEYA_PLUGIN_URL' ) ? MADEYA_PLUGIN_URL . 'assets/images/etel-bg.webp' : '';
    ob_start(); ?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap');
:root{--f:#F5A623;--f2:#FFD166;--dk:rgba(10,7,2,0.82);--sf:rgba(18,13,4,0.80);--pn:rgba(22,16,5,0.88);--cd:rgba(30,21,6,0.82);--bd:rgba(180,130,30,0.35);--tx:#f5e6c0;--mt:#c9a96e;--gn:#27AE60;--rd:#C0392B;--bl:#2980B9;--or:#E67E22;}
body{margin:0!important;padding:0!important;overflow-x:hidden!important;}
html,body,.site,.site-content,.entry-content,.page-content,#content,#primary,article{margin:0!important;padding:0!important;max-width:100%!important;width:100%!important;}
#md,#md *{box-sizing:border-box;margin:0;padding:0;}
#md{font-family:'Barlow Condensed',sans-serif;background:url('<?php echo esc_url($em_bg); ?>') center center/cover no-repeat fixed;background-color:#b8860b;color:var(--tx);min-height:100vh;width:100vw;margin:0;padding:0;}
#md-hdr{background:linear-gradient(to right,rgba(10,6,1,0.90),rgba(20,12,2,0.85),rgba(10,6,1,0.90));border-bottom:3px solid var(--f);padding:0 16px;height:58px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;box-shadow:0 4px 18px rgba(0,0,0,.5);width:100%;margin:0;}
.dh-l{display:flex;align-items:center;gap:10px;}
.dh-ic{width:36px;height:36px;background:var(--f);border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:17px;color:#000;font-weight:900;}
.dh-nm{font-size:20px;font-weight:900;color:var(--f);letter-spacing:2px;line-height:1;}
.dh-sb{font-size:10px;color:var(--mt);letter-spacing:2px;text-transform:uppercase;}
.dh-tm{font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--mt);text-align:right;}
.dh-tm b{display:block;font-size:14px;color:var(--tx);}
#md-body{padding:16px;max-width:100%;margin:0;width:100%;}
.mcard{background:var(--sf);border:1px solid var(--bd);border-radius:13px;overflow:hidden;margin-bottom:14px;}
.mcard-h{padding:12px 16px;background:var(--pn);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:8px;}
.mcard-h h3{font-size:17px;font-weight:800;letter-spacing:2px;text-transform:uppercase;color:var(--tx);}
.mcard-h i{color:var(--f);font-size:18px;}
.mcard-b{padding:16px;}
/* Station info bar */
#md-station-bar{background:rgba(245,166,35,.06);border-bottom:1px solid rgba(245,166,35,.15);padding:10px 16px;font-size:15px;color:var(--mt);display:flex;align-items:center;gap:14px;flex-wrap:wrap;width:100%;margin:0;}
#md-station-bar span{display:flex;align-items:center;gap:5px;}
#md-station-bar b{color:var(--tx);font-size:16px;}
.sbar-par.even{color:var(--bl);}
.sbar-par.odd{color:var(--or);}
.sbar-par.both{color:var(--gn);}
/* Plate input */
#md-plate{width:100%;padding:16px 14px;background:var(--cd);border:2px solid var(--bd);border-radius:9px;color:var(--tx);font-family:'JetBrains Mono',monospace;font-size:26px;font-weight:700;letter-spacing:4px;text-transform:uppercase;text-align:center;transition:all .2s;outline:none;}
#md-plate:focus{border-color:var(--f);box-shadow:0 0 0 4px rgba(245,166,35,.1);}
#md-plate::placeholder{color:var(--mt);font-size:16px;letter-spacing:1px;}
#md-check{width:100%;margin-top:12px;padding:16px;background:linear-gradient(135deg,var(--f),#c47f10);border:none;border-radius:9px;color:#000;font-family:'Barlow Condensed',sans-serif;font-size:18px;font-weight:800;letter-spacing:2.5px;text-transform:uppercase;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px;}
#md-check:hover{box-shadow:0 5px 20px rgba(245,166,35,.4);transform:translateY(-1px);}
#md-check:disabled{opacity:.5;cursor:not-allowed;transform:none;}
#md-nf{display:none;text-align:center;padding:15px;background:rgba(192,57,43,.07);border:1px solid rgba(192,57,43,.2);border-radius:8px;margin-top:11px;color:var(--rd);font-size:17px;}
#md-nf.show{display:block;animation:fup .3s ease;}
@keyframes fup{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
#md-result{display:none;}
#md-result.show{display:block;animation:fup .4s cubic-bezier(.34,1.2,.64,1);}
/* Notification */
#md-notify{display:none;background:linear-gradient(135deg,rgba(245,166,35,.13),rgba(243,156,18,.06));border:2px solid var(--f);border-radius:12px;padding:20px;margin-bottom:14px;text-align:center;}
#md-notify.show{display:block;animation:pulse-g 2s infinite;}
@keyframes pulse-g{0%,100%{box-shadow:0 0 0 0 rgba(245,166,35,.4)}50%{box-shadow:0 0 0 12px rgba(245,166,35,0)}}
.notif-bell{font-size:42px;color:var(--f);display:block;margin-bottom:10px;}
.notif-h{font-size:26px;font-weight:900;color:var(--f);letter-spacing:2px;text-transform:uppercase;margin-bottom:6px;}
.notif-b{font-size:17px;color:var(--f2);line-height:1.6;}
/* Position number */
.posdisp{background:var(--sf);border:1px solid var(--bd);border-radius:12px;overflow:hidden;margin-bottom:13px;}
.pd-h{padding:12px 16px;background:var(--pn);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);border-bottom:1px solid var(--bd);font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);display:flex;align-items:center;gap:7px;}
.pd-b{padding:24px;text-align:center;}
.pd-num{font-family:'JetBrains Mono',monospace;font-size:96px;font-weight:800;color:var(--f);line-height:1;animation:nPop .45s cubic-bezier(.34,1.56,.64,1);}
@keyframes nPop{from{transform:scale(.5);opacity:0}to{transform:scale(1);opacity:1}}
.pd-num.close{color:var(--gn);}
.pd-lbl{font-size:14px;color:var(--mt);text-transform:uppercase;letter-spacing:2px;margin-top:6px;}
.pd-tok{font-family:'JetBrains Mono',monospace;font-size:17px;color:var(--f2);margin-top:8px;}
/* Info grid */
.igrid{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-bottom:13px;}
.ibox{background:var(--sf);border:1px solid var(--bd);border-radius:9px;padding:14px;text-align:center;}
.ibox b{display:block;font-family:'JetBrains Mono',monospace;font-size:28px;font-weight:600;color:var(--f2);}
.ibox span{font-size:13px;color:var(--mt);text-transform:uppercase;letter-spacing:1px;}
/* Parity/type badge row */
.veh-row{display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:13px;background:var(--sf);border:1px solid var(--bd);border-radius:9px;padding:13px;}
.veh-type{font-size:18px;font-weight:700;color:var(--tx);}
.par-chip{font-size:15px;padding:5px 13px;border-radius:6px;font-weight:700;}
.par-chip.even{background:rgba(41,128,185,.13);color:var(--bl);}
.par-chip.odd{background:rgba(230,126,34,.1);color:var(--or);}
/* Progress */
.qprog{background:var(--sf);border:1px solid var(--bd);border-radius:9px;padding:14px;margin-bottom:13px;}
.qp-top{display:flex;justify-content:space-between;font-size:14px;color:var(--mt);margin-bottom:8px;text-transform:uppercase;letter-spacing:1px;}
.qp-bar{height:11px;background:var(--cd);border-radius:6px;overflow:hidden;}
.qp-fill{height:100%;background:linear-gradient(90deg,var(--rd),var(--or),var(--f),var(--gn));border-radius:6px;transition:width .5s ease;}
/* Refresh */
#md-refresh{width:100%;padding:14px;background:var(--pn);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);border:1px solid var(--bd);border-radius:9px;color:var(--tx);font-family:'Barlow Condensed',sans-serif;font-size:16px;font-weight:700;letter-spacing:2px;text-transform:uppercase;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:13px;}
#md-refresh:hover{border-color:var(--f);color:var(--f);}
/* Peek */
.peek-wrap{background:var(--sf);border:1px solid var(--bd);border-radius:12px;overflow:hidden;margin-bottom:14px;}
.peek-wrap .mcard-h{border-radius:0;}
.peek-table-container{overflow-x:auto;-webkit-overflow-scrolling:touch;max-width:100%;display:block;}
.ptbl{width:100%;border-collapse:collapse;table-layout:auto;min-width:500px;display:table;}
.ptbl thead{display:table-header-group;}
.ptbl tbody{display:table-row-group;}
.ptbl thead th{padding:8px 6px;font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--mt);background:var(--pn);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);border-bottom:1px solid var(--bd);text-align:left;white-space:nowrap;}
.pr{border-bottom:1px solid rgba(255,255,255,.04);display:table-row;}
.pr.mine{background:rgba(245,166,35,.07);border-left:3px solid var(--f);}
.pr td{padding:8px 6px;font-size:13px;vertical-align:middle;white-space:nowrap;display:table-cell;}
.pk-pos{font-family:'JetBrains Mono',monospace;font-size:16px;font-weight:700;color:var(--f2);min-width:35px;}
.pk-pl{font-family:'JetBrains Mono',monospace;font-size:13px;color:var(--tx);min-width:80px;}
.pk-type{font-size:12px;color:var(--tx);}
.pk-tier{font-size:12px;color:var(--f);font-weight:700;}
.pk-par{font-size:12px;color:var(--tx);}
.pk-station{font-size:11px;color:var(--mt);max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.you-badge{background:rgba(245,166,35,.15);border:1px solid rgba(245,166,35,.3);color:var(--f);border-radius:4px;padding:2px 8px;font-size:12px;font-weight:700;letter-spacing:1px;margin-left:5px;display:inline-block;}
.pk-par.even{font-size:12px;padding:2px 8px;border-radius:4px;font-weight:700;background:rgba(41,128,185,.11);color:var(--bl);}
.pk-par.odd{font-size:12px;padding:2px 8px;border-radius:4px;font-weight:700;background:rgba(230,126,34,.09);color:var(--or);}

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


/* ── Daily News & Station Map ── */
#md-news-bar{background:linear-gradient(135deg,rgba(245,166,35,.12),rgba(245,166,35,.05));border-bottom:1px solid rgba(245,166,35,.25);padding:10px 16px;display:none;}
#md-news-bar.show{display:block;}
.news-header{display:flex;align-items:center;gap:8px;margin-bottom:6px;}
.news-badge{background:var(--f);color:#000;font-size:9px;font-weight:900;letter-spacing:2px;padding:2px 8px;border-radius:10px;text-transform:uppercase;}
.news-time{font-size:10px;color:var(--mt);}
.news-text{font-size:13px;color:var(--tx);line-height:1.5;margin-bottom:8px;}
.news-vtype-chips{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:6px;}
.news-chip{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:12px;font-size:10px;font-weight:700;background:rgba(245,166,35,.1);border:1px solid rgba(245,166,35,.25);color:var(--f);}
.news-chip.inactive{background:rgba(78,78,78,.1);border-color:var(--bd);color:var(--mt);}

/* ── Station Card ── */
.station-card{background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:12px 14px;margin-bottom:10px;cursor:pointer;transition:all .2s;}
.station-card:hover,.station-card.selected{border-color:var(--f);background:rgba(245,166,35,.05);}
.station-card.no-fuel{border-color:var(--rd);opacity:.7;}
.sc-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;}
.sc-name{font-size:14px;font-weight:800;color:var(--tx);letter-spacing:.5px;}
.sc-fuel{font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:700;}
.sc-fuel.ok{color:var(--gn);}
.sc-fuel.low{color:var(--or);}
.sc-fuel.empty{color:var(--rd);}
.sc-addr{font-size:11px;color:var(--mt);margin-bottom:6px;}
.sc-types{display:flex;flex-wrap:wrap;gap:4px;}
.sc-type-chip{font-size:9px;padding:1px 7px;border-radius:8px;background:rgba(41,128,185,.1);border:1px solid rgba(41,128,185,.2);color:var(--bl);font-weight:700;}

/* ── Map ── */
#md-map-wrap{border-radius:12px;overflow:hidden;border:1px solid var(--bd);margin:10px 0;position:relative;}
#md-map{height:300px;width:100%;background:var(--cd);}
.map-loading{display:flex;align-items:center;justify-content:center;height:300px;color:var(--mt);font-size:13px;gap:8px;}
#md-directions-btn{width:100%;padding:12px;background:linear-gradient(135deg,#27AE60,#1e8449);border:none;border-radius:9px;color:#fff;font-family:'Barlow Condensed',sans-serif;font-size:14px;font-weight:800;letter-spacing:2px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all .2s;margin-top:8px;}
#md-directions-btn:hover{box-shadow:0 5px 20px rgba(39,174,96,.4);transform:translateY(-1px);}
#md-directions-btn:disabled{opacity:.4;cursor:not-allowed;transform:none;}

/* ── Priority Tiers ── */
.priority-section{background:var(--sf);border:1px solid var(--bd);border-radius:12px;overflow:hidden;margin-bottom:12px;}
.priority-header{padding:10px 14px;background:var(--pn);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:8px;}
.priority-header h4{font-size:11px;font-weight:800;letter-spacing:2px;text-transform:uppercase;color:var(--tx);margin:0;}
.tier-row{display:flex;align-items:center;gap:10px;padding:8px 14px;border-bottom:1px solid rgba(255,255,255,.04);}
.tier-row:last-child{border-bottom:none;}
.tier-num{width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;color:#fff;flex-shrink:0;}
.tier-info{flex:1;}
.tier-label{font-size:12px;font-weight:700;color:var(--tx);}
.tier-amh{font-size:10px;color:var(--mt);}
.tier-desc{font-size:10px;color:var(--mt);margin-top:1px;}
.tier-badge{font-size:9px;padding:2px 7px;border-radius:8px;font-weight:700;letter-spacing:1px;}
.tier-badge.active{background:rgba(39,174,96,.1);border:1px solid rgba(39,174,96,.3);color:var(--gn);}
.tier-badge.inactive{background:rgba(192,57,43,.08);border:1px solid rgba(192,57,43,.2);color:var(--rd);}
.tier-row.my-tier{background:rgba(245,166,35,.05);border-left:3px solid var(--f);}


/* ── Priority Card (shown after lookup) ── */
.my-priority-card{background:var(--sf);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);border-radius:12px;overflow:hidden;margin-bottom:12px;}
.mpc-header{padding:12px 16px;display:flex;align-items:center;gap:10px;}
.mpc-tier-badge{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:900;color:#fff;flex-shrink:0;box-shadow:0 0 14px rgba(0,0,0,.4);}
.mpc-title{font-size:14px;font-weight:800;color:var(--tx);line-height:1.2;}
.mpc-amh{font-size:10px;color:var(--mt);}
.mpc-body{padding:10px 14px 14px;}
.mpc-stat-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px;}
.mpc-stat{background:var(--cd);border-radius:8px;padding:10px 12px;text-align:center;}
.mpc-stat b{display:block;font-family:'JetBrains Mono',monospace;font-size:22px;font-weight:700;line-height:1.1;}
.mpc-stat span{font-size:9px;color:var(--mt);text-transform:uppercase;letter-spacing:1.5px;}
.mpc-breakdown{background:var(--cd);border-radius:8px;padding:10px 12px;}
.mpc-breakdown-title{font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);margin-bottom:8px;}
.tier-ahead-row{display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.04);}
.tier-ahead-row:last-child{border-bottom:none;}
.tier-ahead-row.is-mine{background:rgba(245,166,35,.05);border-radius:5px;padding:5px 6px;margin:2px -6px;}
.tab-dot{width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:900;color:#fff;flex-shrink:0;}
.tab-info{flex:1;}
.tab-label{font-size:11px;font-weight:700;color:var(--tx);}
.tab-amh{font-size:9px;color:var(--mt);}
.tab-count{font-family:'JetBrains Mono',monospace;font-size:14px;font-weight:700;}
.tab-you-badge{font-size:8px;background:rgba(245,166,35,.15);border:1px solid rgba(245,166,35,.3);color:var(--f);border-radius:8px;padding:1px 6px;font-weight:700;letter-spacing:1px;}

/* ── Station auto-map (shown from lookup result) ── */
.station-result-card{background:var(--sf);border:1px solid var(--bd);border-radius:12px;overflow:hidden;margin-bottom:12px;}
.src-header{padding:11px 14px;background:var(--pn);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:8px;}
.src-header h4{font-size:11px;font-weight:800;letter-spacing:2px;text-transform:uppercase;color:var(--f);margin:0;}
.src-body{padding:12px 14px;}
.src-name{font-size:15px;font-weight:800;color:var(--tx);margin-bottom:3px;}
.src-addr{font-size:12px;color:var(--mt);margin-bottom:10px;}
#src-map{height:200px;width:100%;background:var(--cd);border-radius:8px;overflow:hidden;margin-bottom:10px;}
.src-dir-btn{width:100%;padding:12px;border:none;border-radius:9px;color:#fff;font-family:'Barlow Condensed',sans-serif;font-size:13px;font-weight:800;letter-spacing:2px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all .2s;}
.src-dir-btn.locate{background:linear-gradient(135deg,#1a73e8,#1557b0);border:none;color:#fff;}
.src-dir-btn.locate:hover{box-shadow:0 4px 16px rgba(26,115,232,.5);transform:translateY(-1px);}
.src-dir-btn.locate:disabled{opacity:.5;cursor:not-allowed;transform:none;}
.src-dir-btn.gmaps{background:linear-gradient(135deg,#34a853,#1e7e34);margin-top:8px;}
.src-dir-btn.gmaps:hover{box-shadow:0 4px 16px rgba(52,168,83,.4);transform:translateY(-1px);}
/* Map container — tall like Google Maps */
#src-map{height:420px;width:100%;background:#e8e0d8;border-radius:12px;overflow:hidden;margin-bottom:10px;position:relative;box-shadow:0 4px 20px rgba(0,0,0,.4);}
/* Route info bar — Google Maps style */
.route-info{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px;}
.route-stat{background:rgba(26,115,232,.12);border:1px solid rgba(26,115,232,.3);border-radius:10px;padding:12px 14px;text-align:center;display:none;}
.route-stat.show{display:block;}
.route-stat b{display:block;font-family:'JetBrains Mono',monospace;font-size:20px;font-weight:700;color:#1a73e8;}
.route-stat span{font-size:9px;color:var(--mt);text-transform:uppercase;letter-spacing:1.5px;}
/* Map loading state */
.map-loading{display:flex;flex-direction:column;align-items:center;justify-content:center;height:420px;color:var(--mt);font-size:13px;gap:10px;background:rgba(20,15,5,0.7);}
.map-status{position:absolute;bottom:12px;left:12px;right:12px;background:rgba(255,255,255,.95);border-radius:8px;padding:10px 14px;font-size:12px;color:#333;z-index:500;display:flex;align-items:center;gap:8px;box-shadow:0 2px 8px rgba(0,0,0,.3);}
.map-status i{color:#1a73e8;}
/* Route steps panel — like Google Maps sidebar */
.route-steps{background:rgba(10,7,2,0.85);border:1px solid rgba(180,130,30,.25);border-radius:10px;padding:12px;margin-bottom:10px;display:none;max-height:200px;overflow-y:auto;}
.route-steps.show{display:block;}
.route-step{display:flex;align-items:flex-start;gap:10px;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:12px;color:var(--tx);}
.route-step:last-child{border-bottom:none;}
.route-step-icon{width:28px;height:28px;border-radius:50%;background:#1a73e8;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:11px;color:#fff;}
.route-step-dist{font-size:10px;color:var(--mt);white-space:nowrap;margin-top:2px;}


/* ═══════════════════════════════════════════════════════
   RESPONSIVE — fits every screen size automatically
   Mobile first, then tablet, then desktop
   ═══════════════════════════════════════════════════════ */

/* Base: scale font with viewport */
html { font-size: 16px; }

/* Fluid containers */
#md, #mc, #ma {
  width: 100% !important;
  max-width: 100% !important;
  overflow-x: hidden !important;
  box-sizing: border-box !important;
}
#md-body, #mc-body, #ma-body, #ma-app, .ma-wrap {
  max-width: 100% !important;
  padding-left: 10px !important;
  padding-right: 10px !important;
  box-sizing: border-box !important;
}

/* All images fluid */
img { max-width: 100% !important; height: auto !important; }

/* Stat grids — stack on tiny screens */
#mc-stats { grid-template-columns: repeat(3, 1fr) !important; }
.igrid    { grid-template-columns: repeat(2, 1fr) !important; }

/* Queue table — scrollable on small screens */
.peek-wrap {
  max-width: 100% !important;
  overflow: visible !important;
}
.peek-table-container {
  width: 100% !important;
  overflow-x: auto !important;
  -webkit-overflow-scrolling: touch !important;
  display: block !important;
}
.peek-wrap .ptbl {
  display: table !important;
  width: 100% !important;
  min-width: 500px !important;
}
.ma-tbl {
  width: 100% !important;
  display: block !important;
  overflow-x: auto !important;
  -webkit-overflow-scrolling: touch !important;
}

/* Form inputs — full width */
.ma-inp, .al-inp, .fp-inp, input[type="text"],
input[type="password"], input[type="email"],
input[type="number"], select, textarea {
  width: 100% !important;
  max-width: 100% !important;
  box-sizing: border-box !important;
  font-size: 16px !important; /* prevents iOS zoom */
}

/* Buttons — full width on mobile */
.al-btn, .ma-btn, .fp-btn, .src-dir-btn,
#md-check, #md-refresh, #mc-reg-btn {
  width: 100% !important;
  box-sizing: border-box !important;
  -webkit-tap-highlight-color: transparent;
  touch-action: manipulation;
}

/* Cards — no horizontal overflow */
.ma-card, .mcard, .station-result-card,
.my-priority-card, .priority-section,
.albox {
  max-width: 100% !important;
  box-sizing: border-box !important;
}

/* Vehicle type grid — 2 columns on mobile, 3 on tablet */
.vtypes, #vtypes-grid, .priority-tiers-grid {
  display: grid !important;
  grid-template-columns: repeat(2, 1fr) !important;
  gap: 6px !important;
}

/* Admin tabs — scrollable */
.ma-tabs {
  overflow-x: auto !important;
  -webkit-overflow-scrolling: touch !important;
  white-space: nowrap !important;
  display: flex !important;
}
.ma-tab { flex-shrink: 0 !important; }

/* Map — full width, taller on mobile */
#src-map { height: 300px !important; }
#md-finder-map { height: 240px !important; }

/* Position number — smaller on tiny screens */
.pd-num { font-size: clamp(40px, 12vw, 80px) !important; }

/* Dashboard stats grid */
.stats-grid { grid-template-columns: repeat(2, 1fr) !important; }

/* Login box — full width on mobile */
.albox { width: calc(100% - 20px) !important; max-width: 420px !important; padding: 28px 20px !important; }

/* Controller detail modal */
.cdm-box {
  width: calc(100% - 20px) !important;
  max-height: 95vh !important;
  margin: 10px !important;
  padding: 20px 14px !important;
}

/* ── Tablet (≥ 600px) ── */
@media (min-width: 600px) {
  #md-body, #mc-body, .ma-wrap { padding-left: 16px !important; padding-right: 16px !important; }
  .vtypes, #vtypes-grid { grid-template-columns: repeat(3, 1fr) !important; }
  #mc-stats { grid-template-columns: repeat(5, 1fr) !important; }
  #src-map  { height: 360px !important; }
  .stats-grid { grid-template-columns: repeat(4, 1fr) !important; }
}

/* ── Desktop (≥ 900px) ── */
@media (min-width: 900px) {
  #md-body, #mc-body, .ma-wrap { padding-left: 24px !important; padding-right: 24px !important; }
  .vtypes, #vtypes-grid { grid-template-columns: repeat(4, 1fr) !important; }
  #src-map  { height: 420px !important; }
  .priority-tiers-grid { grid-template-columns: repeat(2, 1fr) !important; }
}

/* ── Large screens (≥ 1200px) ── */
@media (min-width: 1200px) {
  #md, #mc, #ma { max-width: 100% !important; margin: 0 auto !important; }
}

/* Touch-friendly tap targets */
button, .vt-btn, .tier-btn, .ma-tab,
.station-card, .al-forgot-link button {
  min-height: 44px !important;
  cursor: pointer !important;
}

/* Prevent horizontal scroll on whole page */
body { overflow-x: hidden !important; }


/* -- Hide WordPress theme header/logo on driver page -- */
.site-header, .site-branding, header.site-header,
#masthead, #site-header, .header-main,
.wp-site-blocks > header, body > header,
.entry-header img, .site-logo img,
.custom-logo-link img, .custom-logo {
  display: none !important;
}
#md img { max-width: 100% !important; height: auto !important; }
#md-hdr img { height: 36px !important; width: auto !important; max-width: 140px !important; object-fit: contain !important; }

/* ── Toast notification system ── */
#md-toast{position:fixed;bottom:20px;right:14px;z-index:9999;display:flex;flex-direction:column;gap:7px;pointer-events:none;max-width:300px;}
.md-toast-item{background:rgba(20,14,4,0.96);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid var(--bd);border-radius:10px;padding:11px 15px;display:flex;align-items:center;gap:9px;font-size:13px;color:var(--tx);box-shadow:0 8px 26px rgba(0,0,0,.6);animation:mdTIn .35s cubic-bezier(.34,1.56,.64,1);min-width:200px;}
.md-toast-item.ok{border-color:rgba(39,174,96,.35);}.md-toast-item.ok i{color:var(--gn);}
.md-toast-item.err{border-color:rgba(192,57,43,.4);}.md-toast-item.err i{color:var(--rd);}
.md-toast-item.info{border-color:rgba(41,128,185,.35);}.md-toast-item.info i{color:var(--bl);}
.md-toast-item.warn{border-color:rgba(230,126,34,.35);}.md-toast-item.warn i{color:var(--or);}
@keyframes mdTIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}

/* ── Already-served banner (large, visible in daylight) ── */
#md-served-banner{
  background:linear-gradient(135deg,rgba(192,57,43,0.28),rgba(231,76,60,0.18));
  border:3px solid #e74c3c;border-radius:18px;padding:28px 22px;
  margin-bottom:14px;text-align:center;
  box-shadow:0 6px 24px rgba(231,76,60,0.4);
  display:none;animation:mdPulseRed 2.5s infinite;
}
#md-served-banner.show{display:block;}
@keyframes mdPulseRed{0%,100%{box-shadow:0 6px 24px rgba(231,76,60,0.4)}50%{box-shadow:0 6px 32px rgba(231,76,60,0.7)}}

/* ── Wait time estimate card ── */
#md-wait-card{background:linear-gradient(135deg,rgba(41,128,185,.12),rgba(41,128,185,.06));border:1px solid rgba(41,128,185,.3);border-radius:11px;padding:14px 16px;margin-bottom:12px;display:none;text-align:center;}
#md-wait-card.show{display:block;animation:fup .4s ease;}
.wait-icon{font-size:26px;color:var(--bl);margin-bottom:6px;}
.wait-label{font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);margin-bottom:4px;}
.wait-val{font-family:'JetBrains Mono',monospace;font-size:28px;font-weight:800;color:#1a73e8;line-height:1.1;}
.wait-sub{font-size:11px;color:var(--mt);margin-top:4px;}

/* ── QR code display ── */
#md-qr-card{background:rgba(255,255,255,.04);border:1px solid var(--bd);border-radius:11px;padding:14px;margin-bottom:12px;text-align:center;display:none;}
#md-qr-card.show{display:block;animation:fup .4s ease;}
.qr-label{font-size:10px;font-weight:700;letter-spacing:2px;color:var(--mt);text-transform:uppercase;margin-bottom:8px;}
#md-qr-img{border-radius:8px;background:#fff;padding:6px;display:inline-block;}

/* ── Tab Navigation -- */
#md-tab-nav{
  display:flex;position:sticky;top:72px;z-index:40;
  background:linear-gradient(135deg,rgba(10,6,1,0.96),rgba(18,13,4,0.94));
  border-bottom:2px solid var(--bd);box-shadow:0 4px 16px rgba(0,0,0,.5);
}
.md-tab{
  flex:1;padding:12px 6px 10px;background:none;border:none;border-bottom:3px solid transparent;
  color:var(--mt);font-family:'Barlow Condensed',sans-serif;font-size:11px;font-weight:700;
  letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;transition:all .2s;
  display:flex;flex-direction:column;align-items:center;gap:4px;
}
.md-tab i{font-size:16px;}
.md-tab:hover{color:var(--tx);}
.md-tab.active{color:var(--f);border-bottom-color:var(--f);}
.md-tab-panel{display:none;padding-bottom:80px;}
.md-tab-panel.active{display:block;}
</style>

<div id="md">
 <div id="md-hdr" style="background:linear-gradient(135deg,rgba(10,6,1,0.92),rgba(20,14,3,0.88),rgba(10,6,1,0.92));border-bottom:3px solid var(--f);padding:0 14px;height:72px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;box-shadow:0 4px 32px rgba(0,0,0,.8);">
  <div class="dh-l" style="gap:0;align-items:center;">
   <div style="height:62px;display:flex;align-items:center;padding-right:12px;border-right:1px solid rgba(245,166,35,.25);">
    <img src="<?php echo esc_url($em_logo); ?>" alt="ETEL MADEYA" style="height:36px;width:auto;max-width:140px;object-fit:contain;filter:drop-shadow(0 0 10px rgba(245,166,35,.4));transition:filter .3s,transform .3s;" onmouseover="this.style.filter='drop-shadow(0 0 20px rgba(245,166,35,.8))';this.style.transform='scale(1.04)'" onmouseout="this.style.filter='drop-shadow(0 0 10px rgba(245,166,35,.4))';this.style.transform='scale(1)'">
   </div>
   <div style="padding-left:12px;">
    <div class="dh-nm" style="font-size:17px;letter-spacing:2.5px;">ETEL MADEYA</div>
    <div style="font-size:9px;color:#2980B9;font-weight:700;letter-spacing:1px;">ኢትኤል ማደያ</div>
    <div style="display:inline-flex;align-items:center;gap:3px;background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.2);border-radius:12px;padding:1px 8px;font-size:7px;font-weight:700;color:var(--f);letter-spacing:1.5px;margin-top:2px;">
     <i class="fas fa-registered" style="font-size:6px;"></i> ኢE PATENT &amp; TRADEMARK
    </div>
   </div>
  </div>
  <div class="dh-tm"><span id="dd-date"></span><b id="dd-time"></b></div>
 </div>

 <!-- Station rules bar -->
 <div id="md-station-bar">
  <span><i class="fas fa-gas-pump" style="color:var(--f)"></i><b id="sb-station">Madeya Gas Station</b></span>
  <span>Parity: <b class="sbar-par" id="sb-par">—</b></span>
  <span>Types: <b id="sb-types">—</b></span>
 </div>

 <!-- ── Daily News Bar ── -->
 <div id="md-news-bar">
  <div class="news-header">
   <span class="news-badge"><i class="fas fa-bullhorn"></i> Daily Update</span>
   <span class="news-time" id="news-time"></span>
  </div>
  <div class="news-text" id="news-text"></div>
  <div class="news-vtype-chips" id="news-chips"></div>
 </div>

 <div id="md-body">

 <!-- -- Bottom Tab Navigation -- -->
 <nav id="md-tab-nav">
  <button class="md-tab active" onclick="mdSwitchTab('queue',this)">
   <i class="fas fa-car"></i><span>Queue</span>
  </button>
  <button class="md-tab" onclick="mdSwitchTab('map',this)">
   <i class="fas fa-map-marker-alt"></i><span>Map</span>
  </button>
  <button class="md-tab" onclick="mdSwitchTab('priority',this)">
   <i class="fas fa-shield-alt"></i><span>Priority</span>
  </button>
 </nav>

 <!-- -- Tab: Queue -- -->
 <div id="md-tab-queue" class="md-tab-panel active">
  <div class="mcard">
   <div class="mcard-h"><i class="fas fa-car"></i><h3>Check Your Position</h3></div>
   <div class="mcard-b">
    <select id="md-vtype" style="width:100%; margin-bottom:10px; padding:12px; background:var(--cd); border:2px solid var(--bd); border-radius:9px; color:var(--tx); font-family:'Barlow Condensed'; font-weight:700; text-transform:uppercase;">
    <option value="bajaj">1 - ??? (Bajaj)</option>
    <option value="qute" selected>2 - ??? (Qute)</option>
    <option value="l_moto">3 - L ??? ???? (L Motorcycle)</option>
    <option value="private_moto">4 - ??? ??? ???? (Private Motorcycle)</option>
    <option value="govt_moto">5 - ?????? ??? ???? (Govt Motorcycle)</option>
    <option value="private_car">6 - ??? ??? (Private Car)</option>
    <option value="minibus">7 - ???? ??? (Minibus Taxi)</option>
    <option value="damas">8 - ??? (Damas)</option>
</select>

<input type="text" id="md-plate" placeholder="ENTER PLATE NUMBER" maxlength="15">
    <button type="button" id="md-check" onclick="mdCheck()">
     <i class="fas fa-search"></i>CHECK MY POSITION
    </button>
    <div id="md-nf"><i class="fas fa-times-circle" style="margin-right:6px"></i><span id="md-nf-msg">Not in queue.</span></div>
   </div>
  </div>
  <div id="md-result">

   <!-- Already served banner — injected by JS, pre-styled here -->
   <div id="md-served-banner"></div>

   <!-- Wait time estimate -->
   <div id="md-wait-card">
    <div class="wait-icon"><i class="fas fa-clock"></i></div>
    <div class="wait-label">Estimated Wait</div>
    <div class="wait-val" id="md-wait-val">—</div>
    <div class="wait-sub" id="md-wait-sub">Based on today's serve rate</div>
   </div>

   <!-- QR code for your token -->
   <div id="md-qr-card">
    <div class="qr-label"><i class="fas fa-qrcode" style="margin-right:5px;"></i>Your Queue Token</div>
    <div id="md-qr-img"></div>
    <div style="font-size:11px;color:var(--mt);margin-top:6px;">Show this at the station</div>
   </div>

   <!-- GET READY alert -->
   <div id="md-notify">
    <span class="notif-bell">&#9888;</span>
    <div class="notif-h">GET READY NOW!</div>
    <div class="notif-b" id="md-notify-text">Only <b id="nd-ahead">—</b> cars of your priority ahead.<br>Head to your station now!</div>
   </div>

   <!-- Position number -->
   <div class="posdisp">
    <div class="pd-h"><i class="fas fa-hashtag"></i>Your Queue Position</div>
    <div class="pd-b">
     <div class="pd-num" id="md-posnum">—</div>
     <div class="pd-lbl">Overall Position in Queue</div>
     <div class="pd-tok">Token: <b id="md-token">—</b></div>
    </div>
   </div>

   <!-- Priority card -->
   <div class="my-priority-card" id="md-priority-card">
    <div class="mpc-header" id="mpc-header">
     <div class="mpc-tier-badge" id="mpc-badge">—</div>
     <div>
      <div class="mpc-title" id="mpc-title">—</div>
      <div class="mpc-amh" id="mpc-amh">—</div>
     </div>
     <span class="par-chip" id="md-par" style="margin-left:auto;">—</span>
    </div>
    <div class="mpc-body">
     <div class="mpc-stat-row">
      <div class="mpc-stat">
       <b id="md-same-ahead" style="color:var(--f);">—</b>
       <span>Your Priority<br>Cars Ahead</span>
      </div>
      <div class="mpc-stat">
       <b id="md-ahead" style="color:var(--mt);">—</b>
       <span>Total Cars<br>Ahead</span>
      </div>
     </div>
     <!-- Tier breakdown -->
     <div class="mpc-breakdown">
      <div class="mpc-breakdown-title"><i class="fas fa-layer-group" style="margin-right:5px;"></i>Priority Breakdown (cars ahead of you)</div>
      <div id="md-tier-breakdown"><!-- filled by JS --></div>
     </div>
    </div>
   </div>

   <!-- Vehicle + overall stats -->
   <div class="veh-row">
    <i class="fas fa-car" style="color:var(--f);font-size:17px"></i>
    <span class="veh-type" id="md-vtype">—</span>
   </div>

   <div class="igrid">
    <div class="ibox"><b id="md-total">—</b><span>Total in Queue</span></div>
    <div class="ibox">
     <b id="md-pct-num" style="color:var(--gn);">—%</b>
     <span>Your Progress</span>
    </div>
   </div>

   <div class="qprog">
    <div class="qp-top"><span>Queue Progress</span><span id="md-pct">—%</span></div>
    <div class="qp-bar"><div class="qp-fill" id="md-fill" style="width:0%"></div></div>
   </div>

   <!-- Station map — shown BEFORE and AFTER plate lookup -->
   <div class="station-result-card" id="md-station-result" style="display:none;">
    <div class="src-header">
     <i class="fas fa-map-marker-alt" style="color:#1a73e8;"></i>
     <h4>Gas Station Route</h4>
     <span id="src-gps-badge" style="margin-left:auto;font-size:9px;padding:2px 8px;border-radius:8px;font-weight:700;background:rgba(26,115,232,.12);border:1px solid rgba(26,115,232,.3);color:#1a73e8;display:none;">
      <i class="fas fa-circle" style="font-size:6px;animation:pulse-g 1.5s infinite;margin-right:4px;"></i>GPS LIVE
     </span>
    </div>
    <div class="src-body">
     <!-- Station name + address -->
     <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
      <div style="width:36px;height:36px;border-radius:50%;background:#1a73e8;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
       <i class="fas fa-gas-pump" style="color:#fff;font-size:14px;"></i>
      </div>
      <div>
       <div id="src-name" style="font-size:14px;font-weight:800;color:var(--tx);">—</div>
       <div id="src-addr" style="font-size:11px;color:var(--mt);margin-top:2px;"></div>
      </div>
     </div>

     <!-- Distance + ETA boxes (Google Maps style) -->
     <div class="route-info">
      <div class="route-stat" id="route-dist">
       <b id="r-dist">—</b>
       <span>Distance</span>
      </div>
      <div class="route-stat" id="route-eta">
       <b id="r-eta">—</b>
       <span>Drive Time</span>
      </div>
     </div>

     <!-- THE MAP — full size like Google Maps -->
     <div id="src-map">
      <div class="map-loading">
       <i class="fas fa-map-marked-alt" style="font-size:36px;color:rgba(245,166,35,.4);"></i>
       <span style="color:var(--mt);">Tap below to show your route</span>
      </div>
     </div>

     <!-- Status overlay (shown while loading) -->
     <div class="map-status" id="map-status" style="display:none;">
      <i class="fas fa-circle-notch fa-spin" id="map-status-icon" style="color:#1a73e8;"></i>
      <span id="map-status-text">Getting your location...</span>
     </div>

     <!-- Turn-by-turn steps (like Google Maps sidebar) -->
     <div class="route-steps" id="route-steps"></div>

     <!-- Action buttons -->
     <button type="button" class="src-dir-btn locate" id="btn-show-route" onclick="mdShowRoute()">
      <i class="fas fa-route"></i>SHOW ROUTE FROM MY LOCATION
     </button>
     <!-- Start Navigation button — appears after route is loaded -->
     <button type="button" id="btn-start-nav" onclick="mdStartNavigation()"
      style="display:none;width:100%;padding:13px;margin-top:8px;border:none;border-radius:10px;
             background:linear-gradient(135deg,#27AE60,#1e8449);color:#fff;font-weight:900;
             font-size:13px;letter-spacing:2px;cursor:pointer;text-transform:uppercase;">
      <i class="fas fa-play-circle"></i> START NAVIGATION
     </button>
     <!-- Navigation status text -->
     <div id="nav-status" style="display:none;margin-top:8px;padding:10px 14px;
          background:rgba(52,168,83,.12);border:1px solid rgba(52,168,83,.3);
          border-radius:8px;font-size:12px;color:#34A853;font-weight:600;text-align:center;">
     </div>
     <button type="button" class="src-dir-btn gmaps" onclick="mdOpenGoogleMaps()" style="margin-top:8px;">
      <i class="fab fa-google"></i>OPEN IN GOOGLE MAPS
     </button>
    </div>
   </div>

   <button type="button" id="md-refresh" onclick="mdRefresh()">
    <i class="fas fa-sync-alt"></i>REFRESH STATUS
   </button>

   <!-- Active Queue Section -->
   <div class="active-queue-wrap" id="md-active-queue">
    <div class="mcard-h"><i class="fas fa-list-ol"></i><h3>Active Queue — <span id="md-aq-station-name">Your Station</span></h3></div>
    <div style="padding:12px 18px;background:rgba(30,21,6,0.82);border-bottom:1px solid var(--bd);">
     <input type="text" id="md-queue-search" placeholder="🔍 Search plate number..." style="width:100%;padding:10px 14px;background:var(--cd);border:1px solid var(--bd);border-radius:6px;color:var(--tx);font-family:'JetBrains Mono',monospace;font-size:14px;outline:none;" oninput="mdSearchActiveQueue()">
    </div>
    <div class="peek-table-container" style="max-height:600px;overflow-y:auto;">
     <table class="ptbl">
      <thead><tr>
       <th style="padding:12px 15px;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">#</th>
       <th style="padding:12px 15px;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">PLATE</th>
       <th style="padding:12px 15px;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">TYPE</th>
       <th style="padding:12px 15px;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">TIER</th>
       <th style="padding:12px 15px;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">PARITY</th>
      </tr></thead>
      <tbody id="md-active-queue-body"></tbody>
     </table>
    </div>
    <div style="padding:10px 18px;background:rgba(30,21,6,0.82);border-top:1px solid var(--bd);text-align:center;font-size:13px;color:var(--mt);">
     <span id="md-queue-count">Search your plate to see queue</span>
    </div>
    <div id="md-aq-pagination" style="padding:10px 18px;background:rgba(30,21,6,0.82);display:flex;justify-content:center;gap:4px;flex-wrap:wrap;"></div>
    <style>
     .aq-pg-btn{padding:6px 13px;background:var(--cd,#1a1208);border:1px solid var(--bd,#3a2a10);border-radius:6px;color:var(--f,#d4af37);font-family:'Barlow Condensed',sans-serif;font-size:13px;font-weight:700;cursor:pointer;transition:all .2s;}
     .aq-pg-btn:hover{background:rgba(212,175,55,0.15);border-color:var(--f,#d4af37);}
     .aq-pg-active{background:linear-gradient(135deg,var(--f,#d4af37),#c47f10)!important;color:#000!important;border:none!important;cursor:default;}
    </style>
   </div>

   <div class="peek-wrap">
    <div class="mcard-h"><i class="fas fa-list"></i><h3>Queue Around You</h3></div>
    <div class="peek-table-container">
     <table class="ptbl">
      <thead><tr><th>#</th><th>Plate</th><th>Type</th><th>Tier</th><th>Parity</th><th>Station</th></tr></thead>
      <tbody id="md-peek"></tbody>
     </table>
    </div>
   </div>
  </div>
 
<!-- Toast container -->
<div id="md-toast"></div>

<!-- ── ETEL MADEYA Brand Footer ── -->
<div class="em-brand-footer">
 <img src="<?php echo esc_url($em_logo); ?>" alt="ETEL MADEYA" class="em-footer-logo">
 <div class="em-footer-text">
  <span><b>ETEL MADEYA</b> ·  ኢትኤል ማደያ</span>
  <span>ethelmarket.com/etel.Madeya</span>
 </div>
 <div class="em-footer-patent"><i class="fas fa-registered"></i> ኢE PATENT &amp; TRADEMARK OF ETEL DEV</div>
 </div>

 <!-- -- Tab: Map -- -->
 <div id="md-tab-map" class="md-tab-panel">
  <div class="mcard" id="md-station-finder">
   <div class="mcard-h">
    <i class="fas fa-map-marker-alt" style="color:var(--f)"></i>
    <h3>Find Your Gas Station</h3>
   </div>
   <div class="mcard-b" style="padding-top:10px;">

    <!-- Search bar (Nominatim geocoding) -->
    <div style="display:flex;gap:8px;margin-bottom:10px;">
     <div style="flex:1;position:relative;">
      <i class="fas fa-search" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--mt);font-size:13px;pointer-events:none;"></i>
      <input type="text" id="md-map-search" placeholder="Search address or place…" autocomplete="off"
       style="width:100%;padding:11px 11px 11px 34px;background:var(--cd);border:1px solid var(--bd);
              border-radius:9px;color:var(--tx);font-family:'Barlow Condensed',sans-serif;
              font-size:15px;outline:none;transition:border-color .2s;"
       onfocus="this.style.borderColor='var(--f)'" onblur="this.style.borderColor=''"
       onkeypress="if(event.key==='Enter')mdSearchPlace()">
     </div>
     <button type="button" id="md-map-search-btn" onclick="mdSearchPlace()"
      style="padding:11px 16px;background:linear-gradient(135deg,var(--f),#c47f10);border:none;
             border-radius:9px;color:#000;font-weight:900;font-size:15px;cursor:pointer;flex-shrink:0;transition:all .2s;"
      onmouseover="this.style.boxShadow='0 4px 14px rgba(245,166,35,.5)'" onmouseout="this.style.boxShadow=''">
      <i class="fas fa-search"></i>
     </button>
    </div>

    <!-- Inline interactive map (Leaflet / OSM) -->
    <div id="md-finder-map" style="height:280px;border-radius:12px;overflow:hidden;border:1px solid var(--bd);margin-bottom:12px;background:var(--cd);">
     <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:280px;color:var(--mt);gap:8px;">
      <i class="fas fa-map-marked-alt" style="font-size:32px;color:rgba(245,166,35,.4);"></i>
      <span style="font-size:12px;">Map loading…</span>
     </div>
    </div>

    <!-- Station list -->
    <p style="font-size:11px;color:var(--mt);margin-bottom:8px;letter-spacing:1px;text-transform:uppercase;font-weight:700;">
     <i class="fas fa-gas-pump" style="color:var(--f);margin-right:4px;"></i>Select a station for directions:
    </p>
    <div id="md-station-list"></div>

    <!-- Action buttons -->
    <div style="display:flex;flex-direction:column;gap:8px;margin-top:10px;">
     <button type="button" id="md-directions-btn" disabled onclick="mdShowRouteFromFinder()" class="src-dir-btn locate" style="border-radius:8px;">
      <i class="fas fa-route"></i>SHOW ROUTE FROM MY LOCATION
     </button>
     <button type="button" id="md-gmaps-btn" disabled onclick="mdOpenGoogleMaps()" class="src-dir-btn gmaps" style="border-radius:8px;">
      <i class="fab fa-google"></i>OPEN IN GOOGLE MAPS
     </button>
    </div>

   </div>
  </div>
 </div>

 <!-- -- Tab: Priority -- -->
 <div id="md-tab-priority" class="md-tab-panel">
  <div class="priority-section" id="md-priority-section" >
   <div class="priority-header">
    <i class="fas fa-shield-alt" style="color:var(--f)"></i>
    <h4>የነዳጅ ቅድሚያ ደረጃ — Oil Supply Priority</h4>
   </div>
   <div id="md-priority-tiers"><!-- filled by JS --></div>
  </div>

  <!-- ── Gas Station Finder ── -->
 </div>
<!-- Google Maps JavaScript API (Maps + Places + Geometry) -->
<script>window._gmReadyCb=null;window.mdGMapsReady=function(){if(typeof window._gmReadyCb==="function")window._gmReadyCb();};</script>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo esc_attr(defined("MADEYA_GOOGLE_MAPS_KEY")?MADEYA_GOOGLE_MAPS_KEY:""); ?>&libraries=places,geometry&callback=mdGMapsReady" async defer></script>
</div>
</div>
</div>

<script charset="utf-8">
(function($){
 'use strict';
 var AJ=MADEYA.ajaxurl, NO=MADEYA.nonce;
 var myPlate=null, notifPlayed=false;
 var _driverCtrlId=0, _driverPlate='';

 function tick(){
  var n=new Date(),dy=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'],mo=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  $('#dd-date').text(dy[n.getDay()]+' '+mo[n.getMonth()]+' '+n.getDate());
  $('#dd-time').text(n.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit',second:'2-digit'}));
 }
 tick(); setInterval(tick,1000);

 /* Station info */
 var pmap={even:'ሙሉ (Even)',odd:'ጎዶሎ (Odd)',both:'Both'};
 var pcls=MADEYA.allowed_parity;
 $('#sb-station').text(MADEYA.station_name||'Madeya Gas Station');
 $('#sb-par').attr('class','sbar-par '+pcls).text(pmap[pcls]||pcls);
 var tnames=(MADEYA.allowed_types||[]).map(function(k){return(MADEYA.type_labels||{})[k]||k;});
 $('#sb-types').text(tnames.join(', ')||'--');

 /* ── Toast notification system ── */
 function mdToast(msg, type, duration) {
  type = type || 'info'; duration = duration || 3500;
  var icons = {ok:'fa-check-circle',err:'fa-times-circle',warn:'fa-exclamation-triangle',info:'fa-info-circle'};
  var $t = $('<div class="md-toast-item '+type+'"><i class="fas '+icons[type]+'"></i><span>'+msg+'</span></div>');
  $('#md-toast').append($t);
  setTimeout(function(){ $t.css({opacity:0,transform:'translateX(30px)',transition:'all .4s ease'}); setTimeout(function(){ $t.remove(); }, 450); }, duration);
 }

 /* ── Animated count-up for position number ── */
 function mdAnimateCount(el, target, duration) {
  var $el = $(el);
  var start = parseInt($el.text()) || 0;
  if (start === target) { $el.text(target); return; }
  var range = target - start;
  var startTime = null;
  duration = duration || 700;
  function step(timestamp) {
   if (!startTime) startTime = timestamp;
   var progress = Math.min((timestamp - startTime) / duration, 1);
   var ease = 1 - Math.pow(1 - progress, 3); // ease-out cubic
   var current = Math.round(start + range * ease);
   $el.text(current);
   if (progress < 1) { requestAnimationFrame(step); }
   else { $el.text(target); }
  }
  requestAnimationFrame(step);
 }

 /* ── Ethiopian plate auto-format: AA-NNNNN ── */
 function mdFormatPlate(val) {
  var v = val.toUpperCase().replace(/[^A-Z0-9]/g, '');
  // Only format if we have at least 2 characters
  if (v.length === 0) return '';
  // If starts with letters, format as AA-NNNNN
  if (/^[A-Z]/.test(v)) {
   if (v.length > 2) { v = v.slice(0, 2) + '-' + v.slice(2, 7); }
   return v;
  }
  // If starts with numbers, format as NN-NNNNN (for numeric plates)
  if (v.length > 2) { v = v.slice(0, 2) + '-' + v.slice(2, 7); }
  return v;
 }

 $('#md-plate').on('input', function() {
  var raw = this.value.toUpperCase();
  // Don't auto-format - let user type freely
  this.value = raw;
 }).on('keypress', function(e) { if (e.key === 'Enter') mdCheck(); });

 /* ── QR code generator (Google Charts API, no key needed) ── */
 function mdShowQR(token, plate) {
  if (!token) return;
  var data = 'MADEYA:' + token + ':' + plate;
  var url  = 'https://chart.googleapis.com/chart?cht=qr&chs=180x180&chl=' + encodeURIComponent(data) + '&choe=UTF-8&chld=M|2';
  $('#md-qr-img').html('<img src="'+url+'" width="168" height="168" alt="QR Token" style="display:block;border-radius:6px;">');
  $('#md-qr-card').addClass('show');
 }

 /* ── Wait time display ── */
 function mdShowWaitTime(estMin) {
  if (estMin === null || estMin === undefined) {
   $('#md-wait-card').removeClass('show');
   return;
  }
  var txt;
  if (estMin === 0) { txt = 'YOU\'RE NEXT'; }
  else if (estMin < 60) { txt = estMin + ' min'; }
  else { txt = Math.floor(estMin/60) + 'h ' + (estMin % 60) + 'm'; }
  $('#md-wait-val').text(txt);
  $('#md-wait-card').addClass('show');
 }

 function beep(){
  try{
   var ctx=new(window.AudioContext||window.webkitAudioContext)();
   [660,880,660,880,1100].forEach(function(f,i){
    var o=ctx.createOscillator(),g=ctx.createGain();
    o.connect(g);g.connect(ctx.destination);
    o.frequency.value=f;o.type='sine';
    g.gain.setValueAtTime(.18,ctx.currentTime+i*.2);
    g.gain.exponentialRampToValueAtTime(.001,ctx.currentTime+i*.2+.18);
    o.start(ctx.currentTime+i*.2);o.stop(ctx.currentTime+i*.2+.2);
   });
  }catch(e){}
 }

 window.mdCheck=function(){
  var plate=$('#md-plate').val().trim().toUpperCase();
  var vtype=$('#md-vtype').val();

  if(!plate){ mdToast('Enter your plate number first.','warn'); return; }

  var btn=$('#md-check').prop('disabled',true).html('<i class="fas fa-circle-notch fa-spin"></i> CHECKING...');
  $('#md-nf').removeClass('show');
  $('#md-qr-card').removeClass('show');
  $('#md-wait-card').removeClass('show');

  $.post(AJ,{action:'madeya_driver_lookup',nonce:NO,plate:plate,vehicle_type:vtype},function(r){
   btn.prop('disabled',false).html('<i class="fas fa-search"></i> CHECK MY POSITION');
   if(r.success){
    myPlate=plate;
    updateResult(r.data);
    $('#md-result').addClass('show');
    loadPeek(r.data.position);
    mdLoadActiveQueue(r.data.controller_id, r.data.plate); // Load active queue for this controller
    if(!r.data.already_served){
     clearInterval(window._mdInt);
     window._mdInt=setInterval(function(){mdRefresh(true);},15000);
     mdToast('Position found! You are #'+r.data.position+' in queue.','ok');
    }
   } else {
    var msg=r.data&&r.data.message?r.data.message:'Your plate is not in the queue.';
    $('#md-nf-msg').text(msg);
    $('#md-nf').addClass('show'); $('#md-result').removeClass('show'); myPlate=null;
    mdToast(msg,'err');
   }
  }).fail(function(){
   btn.prop('disabled',false).html('<i class="fas fa-search"></i> CHECK MY POSITION');
   mdToast('Connection error. Try again.','err');
  });
 };

 /* Update result display with queue data */
 function updateResult(d){
  // Handle already served case
  if(d.already_served){
   $('#md-notify').removeClass('show');
   $('#md-result').html('<div style="text-align:center;padding:30px;background:linear-gradient(135deg,rgba(192,57,43,.15),rgba(192,57,43,.05));border:3px solid var(--rd);border-radius:15px;box-shadow:0 0 30px rgba(192,57,43,.3);">'+
    '<div style="font-size:48px;margin-bottom:15px;">⚠️</div>'+
    '<div style="font-size:24px;font-weight:900;color:var(--rd);margin-bottom:10px;letter-spacing:2px;">ALREADY SERVED</div>'+
    '<div style="font-size:18px;color:var(--text);margin-bottom:8px;">'+d.warning+'</div>'+
    '<div style="font-size:16px;color:var(--text-muted);margin-top:15px;">Token: <span style="color:var(--gold);font-family:\'JetBrains Mono\',monospace;font-weight:700;">#'+d.token+'</span></div>'+
    '</div>');
   return;
  }

  // Position display - FIXED: use correct element IDs
  var posClass=d.position<=30?'close':'';
  $('#md-posnum').attr('class','pd-num '+posClass).text(d.position);
  $('#md-token').text(d.token);

  // Info grid - FIXED: use correct element IDs
  $('#md-ahead').text(d.ahead||0);
  $('#md-total').text(d.total||0);

  // Vehicle type & parity - FIXED: use correct element IDs
  $('#md-vtype').text(d.type_label||d.vehicle_type);
  var parClass=d.parity==='even'?'even':'odd';
  $('#md-par').attr('class','par-chip '+parClass).text(d.parity_label||d.parity);

  // Progress bar - FIXED: use correct element IDs
  var pct=d.total>0?Math.round(((d.total-d.position)/d.total)*100):0;
  $('#md-pct-num').text(pct);
  $('#md-pct').text(pct);
  $('#md-fill').css('width',pct+'%');

  // Priority card - populate priority tier info
  if(d.priority_tier){
   var tierLabels={1:'Tier 1 - Emergency',2:'Tier 2 - Essential',3:'Tier 3 - Public',4:'Tier 4 - Commercial',5:'Tier 5 - Private',6:'Tier 6 - Luxury',7:'Tier 7 - Standard'};
   var tierAmharic={1:'ደረጃ 1 - አስቸኳይ',2:'ደረጃ 2 - አስፈላጊ',3:'ደረጃ 3 - ህዝባዊ',4:'ደረጃ 4 - ንግድ',5:'ደረጃ 5 - የግል',6:'ደረጃ 6 - ቅንጦት',7:'ደረጃ 7 - መደበኛ'};
   $('#mpc-badge').text('TIER '+d.priority_tier);
   $('#mpc-title').text(tierLabels[d.priority_tier]||'Tier '+d.priority_tier);
   $('#mpc-amh').text(tierAmharic[d.priority_tier]||'');
   $('#md-same-ahead').text(d.same_priority_ahead||0);
   $('#md-priority-card').show();
  }

  // Notification for close position
  if(d.notify30||d.position<=30){
   $('#md-notify').addClass('show');
   $('.notif-h').text('YOU\'RE GETTING CLOSE!');
   $('#nd-ahead').text(d.position);
   beep();
  } else {
   $('#md-notify').removeClass('show');
  }

  // QR code
  mdShowQR(d.token,d.plate);

  // Wait time
  mdShowWaitTime(d.est_wait_min);

  // Station info & map
  if(d.station&&d.station.lat&&d.station.lng){
   _stationForDirections=d.station;
   $('#src-name').text(d.station.name||'Gas Station');
   $('#src-addr').text(d.station.address||'');
   $('#md-station-result').show();
   if(mdGMapsLoaded())mdInitMapStationOnly(d.station);
  }
 }

 /* Load "Queue Around You" table - shows vehicles before and after your position */
 function loadPeek(myPosition){
  var vtype=$('#md-vtype').val();
  $.post(AJ,{action:'madeya_get_queue',vehicle_type:vtype},function(r){
   if(!r.success||!r.data||!r.data.queue){
    $('#md-peek').html('<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--mt);">No queue data available</td></tr>');
    return;
   }
   
   var queue=r.data.queue;
   var cfg=MADEYA||{};
   var labels=cfg.type_labels||{};
   var tbody=$('#md-peek').empty();
   
   // Sort by position
   queue.sort(function(a,b){return (a.position||0)-(b.position||0);});
   
   // Find my vehicle by plate number
   var myIdx=-1;
   var myPlateClean=myPlate?myPlate.replace(/[^A-Z0-9]/g,''):'';
   for(var i=0;i<queue.length;i++){
    var qPlateClean=queue[i].plate?queue[i].plate.replace(/[^A-Z0-9]/g,''):'';
    if(qPlateClean&&myPlateClean&&qPlateClean===myPlateClean){
     myIdx=i;
     break;
    }
   }
   
   // Show 6 before and 4 after (or all if not found)
   var start=0,end=queue.length;
   if(myIdx>=0){
    start=Math.max(0,myIdx-6);
    end=Math.min(queue.length,myIdx+5); // myIdx + 4 after + 1 for current = myIdx+5
   }
   
   // Build table rows
   var rowCount=0;
   for(var i=start;i<end;i++){
    var v=queue[i];
    var vPlateClean=v.plate?v.plate.replace(/[^A-Z0-9]/g,''):'';
    var isMine=myPlateClean&&vPlateClean===myPlateClean;
    var rowClass=isMine?'pr mine':'pr';
    var pos=v.position||0;
    var plate=v.plate||'';
    var vt=labels[v.vehicle_type]||v.vehicle_type||'';
    var tier=v.priority_tier||7;
    var par=v.plate_parity||'';
    var parLabel=par==='even'?'ሙሉ':par==='odd'?'ጎዶሎ':'';
    var station=v.station_name||'Station '+(v.controller_id||'');
    
    var youBadge=isMine?'<span class="you-badge">YOU</span>':'';
    
    tbody.append('<tr class="'+rowClass+'">'+
     '<td class="pk-pos">'+pos+'</td>'+
     '<td class="pk-pl">'+plate+youBadge+'</td>'+
     '<td class="pk-type">'+vt+'</td>'+
     '<td class="pk-tier">'+tier+'</td>'+
     '<td class="pk-par">'+parLabel+'</td>'+
     '<td class="pk-station">'+station+'</td>'+
     '</tr>');
    rowCount++;
   }
   
   if(rowCount===0){
    tbody.html('<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--mt);">No vehicles in queue</td></tr>');
   }
  }).fail(function(){
   $('#md-peek').html('<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--rd);">Failed to load queue</td></tr>');
  });
 }

 /* Load Active Queue - shows all vehicles in queue */
 var activeQueueData=[];
 var activeQueueSearchTimeout=null;
 var aqPage=1;
 var aqPerPage=50;
 
 function mdLoadActiveQueue(ctrlId, driverPlate){
  if(ctrlId) _driverCtrlId = ctrlId;
  if(driverPlate) _driverPlate = driverPlate;
  if(!_driverCtrlId){
   $('#md-active-queue-body').html('<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--mt);">Search your plate to see queue</td></tr>');
   $('#md-queue-count').text('');
   return;
  }
  
  $.post(AJ,{action:'madeya_driver_active_queue',nonce:NO,controller_id:_driverCtrlId},function(r){
   if(!r.success||!r.data||!r.data.queue){
    $('#md-active-queue-body').html('<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--mt);">No queue data available</td></tr>');
    $('#md-queue-count').text('No vehicles in queue');
    return;
   }
   
   if(r.data.station && r.data.station.station_name){
    $('#md-aq-station-name').text(r.data.station.station_name);
   }
   
   activeQueueData=r.data.queue;
   aqPage=1;
   
   if(_driverPlate){
    var plateUp=_driverPlate.toUpperCase().replace(/[^A-Z0-9]/g,'');
    for(var i=0;i<activeQueueData.length;i++){
     var qp=(activeQueueData[i].plate||'').toUpperCase().replace(/[^A-Z0-9]/g,'');
     if(qp===plateUp){
      aqPage=Math.floor(i/aqPerPage)+1;
      break;
     }
    }
   }
   
   mdDisplayActiveQueue(activeQueueData);
  }).fail(function(){
   $('#md-active-queue-body').html('<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--rd);">Failed to load queue</td></tr>');
  });
 }
 
 function mdDisplayActiveQueue(queue){
  var cfg=MADEYA||{};
  var labels=cfg.type_labels||{};
  var tbody=$('#md-active-queue-body').empty();
  
  if(!queue||queue.length===0){
   tbody.html('<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--mt);">No vehicles in queue</td></tr>');
   $('#md-queue-count').text('No vehicles in queue');
   $('#md-aq-pagination').html('');
   return;
  }
  
  queue.sort(function(a,b){
   return (parseInt(a.position)||0)-(parseInt(b.position)||0);
  });
  
  var searchQuery=$('#md-queue-search').val().trim().toLowerCase();
  var displayQueue=queue;
  if(searchQuery){
   var matches=[];
   var nonMatches=[];
   queue.forEach(function(v){
    if((v.plate||'').toLowerCase().indexOf(searchQuery)!==-1) matches.push(v);
    else nonMatches.push(v);
   });
   displayQueue=matches.concat(nonMatches);
  }
  
  var totalPages=Math.max(1,Math.ceil(displayQueue.length/aqPerPage));
  if(aqPage>totalPages) aqPage=totalPages;
  if(aqPage<1) aqPage=1;
  var startIdx=(aqPage-1)*aqPerPage;
  var pageData=displayQueue.slice(startIdx,startIdx+aqPerPage);
  
  var myPlateUp=(_driverPlate||'').toUpperCase().replace(/[^A-Z0-9]/g,'');
  
  pageData.forEach(function(v,i){
   var pos=v.position||0;
   var plate=v.plate||'';
   var vt=v.type_label||labels[v.vehicle_type]||v.vehicle_type||'';
   var par=v.plate_parity||'';
   var parLabel=par==='even'?'\u121D\u120F':par==='odd'?'\u130E\u12F6\u120E':'';
   
   var plateClean=plate.toUpperCase().replace(/[^A-Z0-9]/g,'');
   var isMe=myPlateUp && plateClean===myPlateUp;
   var isSearchMatch=searchQuery && plate.toLowerCase().indexOf(searchQuery)!==-1;
   
   var rowStyle='';
   var plateExtra='';
   var posExtra='';
   if(isMe){
    rowStyle=' style="background:linear-gradient(90deg,rgba(212,175,55,0.25),rgba(212,175,55,0.08));border-left:4px solid #d4af37;"';
    plateExtra=' <span style="font-size:9px;background:#d4af37;color:#000;padding:1px 6px;border-radius:10px;font-weight:900;margin-left:4px;">YOU</span>';
    posExtra=' <span style="color:#d4af37;">\u2605</span>';
   }else if(isSearchMatch){
    rowStyle=' style="background:rgba(39,174,96,0.15);border-left:3px solid #27AE60;"';
    posExtra=' <span style="font-size:10px;color:#27AE60;">\u2713</span>';
   }
   
   tbody.append('<tr class="pr"'+rowStyle+'>'+
    '<td class="pk-pos" style="font-weight:700;font-size:14px;">'+pos+posExtra+'</td>'+
    '<td class="pk-pl" style="font-weight:'+(isMe?'900':'600')+';font-size:'+(isMe?'15px':'13px')+';letter-spacing:1px;">'+plate+plateExtra+'</td>'+
    '<td class="pk-type">'+vt+'</td>'+
    '<td class="pk-tier">'+(v.priority_tier||7)+'</td>'+
    '<td class="pk-par"><span class="par-badge '+(par||'')+'">'+parLabel+'</span></td>'+
    '</tr>');
  });
  
  var start=startIdx+1;
  var end=Math.min(startIdx+aqPerPage,displayQueue.length);
  $('#md-queue-count').html('Showing <b>'+start+'\u2013'+end+'</b> of <b>'+displayQueue.length+'</b> vehicles');
  
  if(totalPages>1){
   var btns='';
   if(aqPage>1){
    btns+='<button onclick="mdAqPage(1)" class="aq-pg-btn">\u23EE</button>';
    btns+='<button onclick="mdAqPage('+(aqPage-1)+')" class="aq-pg-btn">\u25C0</button>';
   }
   var ps=Math.max(1,aqPage-2), pe=Math.min(totalPages,aqPage+2);
   for(var p=ps;p<=pe;p++){
    if(p===aqPage) btns+='<button class="aq-pg-btn aq-pg-active">'+p+'</button>';
    else btns+='<button onclick="mdAqPage('+p+')" class="aq-pg-btn">'+p+'</button>';
   }
   if(aqPage<totalPages){
    btns+='<button onclick="mdAqPage('+(aqPage+1)+')" class="aq-pg-btn">\u25B6</button>';
    btns+='<button onclick="mdAqPage('+totalPages+')" class="aq-pg-btn">\u23ED</button>';
   }
   $('#md-aq-pagination').html(btns);
  }else{
   $('#md-aq-pagination').html('');
  }
 }
 
 window.mdAqPage=function(p){
  aqPage=p;
  mdDisplayActiveQueue(activeQueueData);
  document.getElementById('md-active-queue').scrollIntoView({behavior:'smooth',block:'start'});
 };
 
 window.mdSearchActiveQueue=function(){
  if(activeQueueSearchTimeout) clearTimeout(activeQueueSearchTimeout);
  activeQueueSearchTimeout=setTimeout(function(){
   aqPage=1;
   mdDisplayActiveQueue(activeQueueData);
  },300);
 };

 /* ------------------------------------------------------------
    DAILY NEWS + STATION MAP + PRIORITY TIERS
    ------------------------------------------------------------ */
 var _selStation = null;

 /* Load news + station data on page load */
 function mdLoadNews(vtype) {
  vtype = vtype || (MADEYA.allowed_types||[])[0] || '';
  $.post(AJ,{action:'madeya_get_daily_news',vehicle_type:vtype},function(r){
   if(!r.success||!r.data.has_news) return;
   var d = r.data;
   // News bar
   var t = new Date(d.posted_at.replace(' ','T'));
   var ts = t.toLocaleDateString()+' '+t.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'});
   $('#news-time').text('Posted: '+ts);
   $('#news-text').text(d.text);
   // Active vehicle type chips
   var chips = $('#news-chips').empty();
   (d.active_types||[]).forEach(function(k){
    var lbl = (MADEYA.type_labels||{})[k]||k;
    var isMe = (MADEYA.allowed_types||[]).indexOf(k) >= 0;
    chips.append('<span class="news-chip'+(isMe?'':' inactive')+'"><i class="fas fa-car"></i>'+lbl+'</span>');
   });
   $('#md-news-bar').addClass('show');
   // Load stations for this news
   mdLoadStations(d.stations, vtype);
  });
 }

 function mdLoadStations(stations, myVtype) {
  if(!stations||!stations.length) return;
  var list = $('#md-station-list').empty();
  _selStation = null;
  $('#md-directions-btn').prop('disabled',true);
  $('#md-map-wrap').hide();
  $('#md-station-finder').show();

  stations.forEach(function(s,i){
   var types = (s.vehicle_types||[]).map(function(k){
    return '<span class="sc-type-chip">'+((MADEYA.type_labels||{})[k]||k)+'</span>';
   }).join('');
   list.append(
    '<div class="station-card" data-idx="'+i+'" onclick="mdSelectStation('+i+')" data-lat="'+s.lat+'" data-lng="'+s.lng+'" data-name="'+s.name+'">'
    +'<div class="sc-header"><div class="sc-name"><i class="fas fa-gas-pump" style="color:var(--f);margin-right:5px;font-size:11px;"></i>'+s.name+'</div></div>'
    +'<div class="sc-addr"><i class="fas fa-map-marker-alt" style="font-size:10px;margin-right:4px;"></i>'+s.address+'</div>'
    +'<div class="sc-types">'+types+'</div>'
    +'</div>'
   );
  });
  window._mapStations = stations;
 }

 window.mdSelectStation = function(idx) {
  _selStation = (window._mapStations||[])[idx];
  if(!_selStation) return;
  $('.station-card').removeClass('selected');
  $('.station-card[data-idx="'+idx+'"]').addClass('selected');
  $('#md-directions-btn,#md-gmaps-btn').prop('disabled',false);
  // Pan finder map to station and show marker
  if(_selStation.lat && _selStation.lng) {
   mdInitMap(_selStation);
  }
 };

 /* == Google Maps JavaScript API Implementation == */
 var _gRouteMap      = null;
 var _gFinderMap     = null;
 var _gDirService    = null;
 var _gDirRenderer   = null;
 var _gStationMarker = null;
 var _gDriverMarker  = null;
 var _gFinderStation = null;
 var _gSearchMarker  = null;
 var _navWatchId     = null;
 var _navActive      = false;
 var _mapInitialized = false;
 function mdGMapsLoaded(){
  return typeof google !== 'undefined' && google.maps && google.maps.Map;
 }

 function _onGMapsReady(){
  _gDirService = new google.maps.DirectionsService();
  if($('#md-tab-map').hasClass('active')) mdInitFinderMap(7.0621,38.4769,13);
  if($('#md-station-result').is(':visible') && _stationForDirections) mdInitMapStationOnly(_stationForDirections);
 }
 window._gmReadyCb = _onGMapsReady;
 if(mdGMapsLoaded()) _onGMapsReady();

 function mapStatus(icon, text, show){
  if(show===false){$('#map-status').hide();return;}
  $('#map-status-icon').attr('class','fas '+icon+(icon.indexOf('fa-spin')<0?' fa-spin':''));
  $('#map-status-text').text(text);
  $('#map-status').show();
 }

 /* Finder map (Map tab) */
 function mdInitFinderMap(lat, lng, zoom){
  if(!mdGMapsLoaded()) return;
  var el = document.getElementById('md-finder-map');
  if(!el) return;
  if(_gFinderMap){
   _gFinderMap.setCenter({lat:lat,lng:lng});
   _gFinderMap.setZoom(zoom||13);
   google.maps.event.trigger(_gFinderMap,'resize');
   return;
  }
  el.innerHTML = '';
  _gFinderMap = new google.maps.Map(el,{
   center:{lat:lat,lng:lng}, zoom:zoom||13,
   mapTypeControl:true,
   mapTypeControlOptions:{style:google.maps.MapTypeControlStyle.DROPDOWN_MENU},
   streetViewControl:true, fullscreenControl:false, gestureHandling:'greedy'
  });
  // Two-pass resize — fixes the hidden-tab 0-size rendering bug
  setTimeout(function(){ google.maps.event.trigger(_gFinderMap,'resize'); _gFinderMap.setCenter({lat:lat,lng:lng}); }, 250);
  setTimeout(function(){ google.maps.event.trigger(_gFinderMap,'resize'); _gFinderMap.setCenter({lat:lat,lng:lng}); }, 600);
 }

 /* Pan finder map to selected station */
 function mdInitMap(station){
  if(!mdGMapsLoaded()) return;
  if(!_gFinderMap) mdInitFinderMap(station.lat,station.lng,15);
  _gFinderMap.setCenter({lat:station.lat,lng:station.lng});
  _gFinderMap.setZoom(16);
  if(_gFinderStation) _gFinderStation.setMap(null);
  _gFinderStation = new google.maps.Marker({
   position:{lat:station.lat,lng:station.lng}, map:_gFinderMap,
   title:station.name||'Gas Station',
   animation:google.maps.Animation.DROP,
   icon:{url:'https://maps.google.com/mapfiles/ms/icons/red-dot.png',scaledSize:new google.maps.Size(36,36)}
  });
  new google.maps.InfoWindow({
   content:'<div style="font-family:sans-serif;min-width:140px;">'
    +'<b style="color:#EA4335;">Gas Station: '+(station.name||'Gas Station')+'</b>'
    +(station.address?'<br><small style="color:#555;">'+station.address+'</small>':'')+'</div>'
  }).open(_gFinderMap,_gFinderStation);
 }

 /* Geocoder search (fallback when Autocomplete does not fire) */
 window.mdSearchPlace = function(){
  var q = $('#md-map-search').val().trim();
  if(!q){mdToast('Enter a place or address to search','warn');return;}
  if(!mdGMapsLoaded()){mdToast('Map not loaded yet, retry in a moment.','err');return;}
  var btn = $('#md-map-search-btn').prop('disabled',true).html('<i class="fas fa-circle-notch fa-spin"></i>');
  var geocoder = new google.maps.Geocoder();
  geocoder.geocode({address:q},function(results,status){
   btn.prop('disabled',false).html('<i class="fas fa-search"></i>');
   if(status!=='OK'||!results.length){mdToast('No results for "'+q+'"','err');return;}
   var place = results[0];
   var loc = place.geometry.location;
   if(!_gFinderMap) mdInitFinderMap(loc.lat(),loc.lng(),15);
   if(place.geometry.viewport) _gFinderMap.fitBounds(place.geometry.viewport);
   else{_gFinderMap.setCenter(loc);_gFinderMap.setZoom(16);}
   if(_gSearchMarker) _gSearchMarker.setMap(null);
   _gSearchMarker = new google.maps.Marker({
    position:loc, map:_gFinderMap, title:place.formatted_address,
    icon:{path:google.maps.SymbolPath.CIRCLE,fillColor:'#1a73e8',fillOpacity:1,strokeColor:'#fff',strokeWeight:3,scale:10}
   });
   new google.maps.InfoWindow({content:'<b style="color:#1a73e8;">'+place.formatted_address+'</b>'}).open(_gFinderMap,_gSearchMarker);
   mdToast('Found: '+place.formatted_address.split(',').slice(0,2).join(', '),'ok',4000);
  });
 };

 /* mdOpenDirections replaced by mdShowRoute and mdOpenGoogleMaps below */

 /* Show station only on route card (no GPS yet) */
 function mdInitMapStationOnly(station){
  if(!mdGMapsLoaded()) return;
  var srcEl = document.getElementById('src-map');
  if(!srcEl) return;
  srcEl.innerHTML = '';
  srcEl.style.height = '420px';
  _mapInitialized = true;
  _gRouteMap = new google.maps.Map(srcEl,{
   center:{lat:station.lat,lng:station.lng}, zoom:15,
   mapTypeControl:false, streetViewControl:true, fullscreenControl:false, gestureHandling:'greedy'
  });
  setTimeout(function(){ google.maps.event.trigger(_gRouteMap,'resize'); _gRouteMap.setCenter({lat:station.lat,lng:station.lng}); },300);
  if(_gStationMarker) _gStationMarker.setMap(null);
  _gStationMarker = new google.maps.Marker({
   position:{lat:station.lat,lng:station.lng}, map:_gRouteMap,
   title:station.name||'Gas Station',
   animation:google.maps.Animation.DROP,
   icon:{url:'https://maps.google.com/mapfiles/ms/icons/red-dot.png',scaledSize:new google.maps.Size(42,42)}
  });
  new google.maps.InfoWindow({
   content:'<div style="font-family:sans-serif;padding:4px;min-width:160px;">'
    +'<b style="color:#EA4335;font-size:13px;">Gas Station: '+(station.name||'Gas Station')+'</b>'
    +(station.address?'<br><span style="font-size:11px;color:#555;">'+station.address+'</span>':'')+'</div>'
  }).open(_gRouteMap,_gStationMarker);
  mapStatus('','',false);
 }

 /* Route with Google Maps DirectionsService */
 function mdInitMapWithRoute(userLat, userLng, station, btn){
  if(!mdGMapsLoaded()) return;
  var srcEl = document.getElementById('src-map');
  if(!srcEl) return;
  srcEl.innerHTML = '';
  srcEl.style.height = '420px';
  _mapInitialized = true;
  _gRouteMap = new google.maps.Map(srcEl,{
   center:{lat:userLat,lng:userLng}, zoom:13,
   mapTypeControl:false, streetViewControl:true, fullscreenControl:false, gestureHandling:'greedy'
  });
  setTimeout(function(){ google.maps.event.trigger(_gRouteMap,'resize'); },300);
  if(_gDriverMarker) _gDriverMarker.setMap(null);
  _gDriverMarker = new google.maps.Marker({
   position:{lat:userLat,lng:userLng}, map:_gRouteMap, title:'You are here',
   icon:{path:google.maps.SymbolPath.CIRCLE,fillColor:'#4285F4',fillOpacity:1,strokeColor:'#fff',strokeWeight:3,scale:10},
   zIndex:999
  });
  if(_gDirRenderer) _gDirRenderer.setMap(null);
  _gDirRenderer = new google.maps.DirectionsRenderer({
   map:_gRouteMap, suppressMarkers:false,
   polylineOptions:{strokeColor:'#1a73e8',strokeWeight:6,strokeOpacity:0.92}
  });
  if(!_gDirService) _gDirService = new google.maps.DirectionsService();
  _gDirService.route({
   origin:new google.maps.LatLng(userLat,userLng),
   destination:new google.maps.LatLng(station.lat,station.lng),
   travelMode:google.maps.TravelMode.DRIVING
  },function(result,status){
   if(status==='OK'){
    _gDirRenderer.setDirections(result);
    var leg = result.routes[0].legs[0];
    $('#r-dist').text(leg.distance.text);
    $('#r-eta').text(leg.duration.text);
    $('#route-dist,#route-eta').addClass('show');
    var stepIcons={
     'turn-right':'fa-arrow-right','turn-left':'fa-arrow-left',
     'straight':'fa-arrow-up','ramp-right':'fa-arrow-right','ramp-left':'fa-arrow-left',
     'merge':'fa-code-branch','roundabout-right':'fa-circle','roundabout-left':'fa-circle',
     'uturn-left':'fa-undo','uturn-right':'fa-redo',
     'ferry':'fa-ship','destination':'fa-flag-checkered'
    };
    var html = '<div style="font-size:10px;font-weight:700;letter-spacing:2px;color:var(--mt);margin-bottom:8px;text-transform:uppercase;">Turn-by-Turn Directions</div>';
    (leg.steps||[]).slice(0,12).forEach(function(s){
     var ic = stepIcons[s.maneuver||'']||'fa-arrow-up';
     var inst = s.instructions ? s.instructions.replace(/<[^>]+>/g,'') : 'Continue';
     html += '<div class="route-step"><div class="route-step-icon"><i class="fas '+ic+'"></i></div>'
      +'<div><div>'+inst+'</div><div class="route-step-dist">'+s.distance.text+'</div></div></div>';
    });
    $('#route-steps').html(html).addClass('show');
    mapStatus('','',false);
    $('#src-gps-badge').show();
    btn.prop('disabled',false).html('<i class="fas fa-sync-alt"></i> RECALCULATE ROUTE');
    $('#btn-start-nav').show();
    if(!_navActive) window.mdStartNavigation();
   } else {
    mapStatus('fa-exclamation-triangle','Route error ('+status+'). Try again.',true);
    btn.prop('disabled',false).html('<i class="fas fa-route"></i> RETRY ROUTE');
    mdInitMapStationOnly(station);
   }
  });
 }

 /* START NAVIGATION - live GPS tracking */
 window.mdStartNavigation = function(){
  if(!_gRouteMap){alert('Load the route first, then tap Start Navigation.');return;}
  if(!navigator.geolocation){alert('GPS not supported on this device.');return;}
  if(_navActive){
   if(_navWatchId) navigator.geolocation.clearWatch(_navWatchId);
   _navWatchId=null;_navActive=false;
   $('#btn-start-nav').html('<i class="fas fa-play-circle"></i> START NAVIGATION')
    .css('background','linear-gradient(135deg,#27AE60,#1e8449)');
   $('#nav-status').hide();
   return;
  }
  _navActive=true;
  $('#btn-start-nav').html('<i class="fas fa-stop-circle"></i> STOP NAVIGATION')
   .css('background','linear-gradient(135deg,#EA4335,#b71c1c)');
  $('#nav-status').show().text('Navigation active - following your location...');
  _navWatchId=navigator.geolocation.watchPosition(
   function(pos){
    var lat=pos.coords.latitude,lng=pos.coords.longitude;
    var ll={lat:lat,lng:lng};
    if(_gDriverMarker) _gDriverMarker.setPosition(ll);
    else if(_gRouteMap){
     _gDriverMarker=new google.maps.Marker({
      position:ll, map:_gRouteMap,
      icon:{path:google.maps.SymbolPath.CIRCLE,fillColor:'#4285F4',fillOpacity:1,strokeColor:'#fff',strokeWeight:3,scale:10},
      zIndex:999
     });
    }
    if(_gRouteMap) _gRouteMap.panTo(ll);
    var st=_stationForDirections;
    if(st&&st.lat){
     var R=6371000,dLat=(st.lat-lat)*Math.PI/180,dLng=(st.lng-lng)*Math.PI/180;
     var a=Math.sin(dLat/2)*Math.sin(dLat/2)+Math.cos(lat*Math.PI/180)*Math.cos(st.lat*Math.PI/180)*Math.sin(dLng/2)*Math.sin(dLng/2);
     var dist=R*2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a));
     if(dist<50){
      $('#nav-status').text('You have arrived at '+(st.name||'the gas station')+'!');
      navigator.geolocation.clearWatch(_navWatchId);
      _navWatchId=null;_navActive=false;
      $('#btn-start-nav').html('<i class="fas fa-play-circle"></i> START NAVIGATION')
       .css('background','linear-gradient(135deg,#27AE60,#1e8449)');
     } else {
      var d=dist>1000?(dist/1000).toFixed(1)+' km':Math.round(dist)+' m';
      $('#nav-status').text(d+' to destination - keep going!');
     }
    }
   },
   function(err){$('#nav-status').text('GPS error: '+(err.message||'unavailable'));},
   {enableHighAccuracy:true,maximumAge:3000,timeout:20000}
  );
 };

 /* Show Route from Finder tab */
 window.mdShowRouteFromFinder = function(){
  var station=_selStation||_stationForDirections;
  if(!station||!station.lat){mdToast('Select a station first.','warn');return;}
  _stationForDirections=station;
  $('#src-name').text(station.name||'Gas Station');
  $('#src-addr').text(station.address||'');
  $('#md-station-result').show();
  var el=document.getElementById('md-station-result');
  if(el) el.scrollIntoView({behavior:'smooth',block:'start'});
  if(!mdGMapsLoaded()){mdToast('Map not loaded. Reload the page.','err');return;}
  var btn=$('#md-directions-btn').prop('disabled',true).html('<i class="fas fa-circle-notch fa-spin"></i> LOCATING YOU...');
  if(!navigator.geolocation){mdInitMapStationOnly(station);btn.prop('disabled',false).html('<i class="fas fa-route"></i> SHOW ROUTE');return;}
  navigator.geolocation.getCurrentPosition(
   function(pos){mdInitMapWithRoute(pos.coords.latitude,pos.coords.longitude,station,btn);},
   function(err){var m={1:'Permission denied.',2:'Unavailable.',3:'Timed out.'};mdToast((m[err.code]||'Location error.')+' Showing station only.','warn');mdInitMapStationOnly(station);btn.prop('disabled',false).html('<i class="fas fa-route"></i> SHOW ROUTE FROM MY LOCATION');},
   {enableHighAccuracy:true,timeout:15000,maximumAge:0}
  );
 };

 /* Show Route button on route card */
 window.mdShowRoute = function(){
  var station=_stationForDirections||_selStation;
  if(!station||!station.lat){alert('No station configured. Ask the admin to set station coordinates.');return;}
  if(!mdGMapsLoaded()){mdToast('Map not loaded. Check internet and reload.','err');return;}
  var btn=$('#btn-show-route').prop('disabled',true).html('<i class="fas fa-circle-notch fa-spin"></i> LOCATING YOU...');
  mapStatus('fa-circle-notch fa-spin','Getting your GPS location...',true);
  if(!navigator.geolocation){
   mapStatus('fa-exclamation-circle','GPS not available. Showing station only.',true);
   mdInitMapStationOnly(station);
   btn.prop('disabled',false).html('<i class="fas fa-route"></i> SHOW ROUTE');
   return;
  }
  navigator.geolocation.getCurrentPosition(
   function(pos){mapStatus('fa-route','Calculating route...',true);mdInitMapWithRoute(pos.coords.latitude,pos.coords.longitude,station,btn);},
   function(err){var m={1:'Location permission denied.',2:'Location unavailable.',3:'Timed out.'};mapStatus('fa-exclamation-circle',(m[err.code]||'Location error.')+' Showing station only.',true);mdInitMapStationOnly(station);btn.prop('disabled',false).html('<i class="fas fa-route"></i> SHOW ROUTE FROM MY LOCATION');},
   {enableHighAccuracy:true,timeout:15000,maximumAge:0}
  );
 };

 /* Open Google Maps app for full navigation */
 window.mdOpenGoogleMaps = function(){
  var s=_stationForDirections||_selStation;
  if(!s||!s.lat) return;
  window.open('https://www.google.com/maps/dir/?api=1&destination='+s.lat+','+s.lng+'&travelmode=driving','_blank');
 };

 /* Inject GPS pulse keyframe once */
 if(!document.getElementById('gps-pulse-style')){
  var st=document.createElement('style');
  st.id='gps-pulse-style';
  st.textContent='@keyframes gps-pulse{0%{box-shadow:0 0 0 0 rgba(66,133,244,.6)}70%{box-shadow:0 0 0 12px rgba(66,133,244,0)}100%{box-shadow:0 0 0 0 rgba(66,133,244,0)}}';
  document.head.appendChild(st);
 }

 window.mdRefresh=function(silent){
  if(!myPlate)return;
  
  var vtype=$('#md-vtype').val(); // Ensure refresh uses the current category
  
  if(!silent)$('#md-refresh').html('<i class="fas fa-circle-notch fa-spin"></i> REFRESHING...');
  
  // Include the vehicle_type in the refresh request
  $.post(AJ,{action:'madeya_driver_lookup',nonce:NO,plate:myPlate,vehicle_type:vtype},function(r){
   if(!silent)$('#md-refresh').html('<i class="fas fa-sync-alt"></i> REFRESH STATUS');
   if(r.success){updateResult(r.data);loadPeek(r.data.position);}
   else{
    $('#md-result').removeClass('show');
    $('#md-nf-msg').text('You have been served. Thank you!');
    $('#md-nf').addClass('show');
    clearInterval(window._mdInt);
   }
  });
 };
 // -- Tab switching (inside closure so _gFinderMap is in scope) --
 window.mdSwitchTab = function(tab, btn) {
  document.querySelectorAll('.md-tab-panel').forEach(function(p){ p.classList.remove('active'); });
  document.querySelectorAll('.md-tab').forEach(function(b){ b.classList.remove('active'); });
  var panel = document.getElementById('md-tab-' + tab);
  if(panel) panel.classList.add('active');
  if(btn)   btn.classList.add('active');
  if(tab === 'map'){
   setTimeout(function(){
    if(!_gFinderMap){
     if(mdGMapsLoaded()) mdInitFinderMap(7.0621,38.4769,13);
     else { window._gmReadyCb = function(){ _onGMapsReady(); mdInitFinderMap(7.0621,38.4769,13); }; }
    } else if(google && google.maps && google.maps.event){
     google.maps.event.trigger(_gFinderMap,'resize');
    }
    if(typeof mdAutoLoadStations === 'function') mdAutoLoadStations($('#md-vtype').val());
   }, 150);
  }
 };
 
 // Auto-refresh active queue every 15 seconds (only after driver has looked up their plate)
 $(document).ready(function(){
  setInterval(function(){
   if($('#md-app').is(':visible') && _driverCtrlId > 0){
    mdLoadActiveQueue(_driverCtrlId, _driverPlate);
   }
  }, 15000);
 });
 
})(jQuery);
</script>
<?php
    return ob_get_clean();
}
