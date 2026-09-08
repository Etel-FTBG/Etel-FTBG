<?php
/**
 * Shortcode: [madeya_controller] — the fuel queue controller panel.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'madeya_controller', 'madeya_sc_controller' );

function madeya_sc_controller() {
    $em_logo = defined( 'MADEYA_LOGO_URL' ) ? MADEYA_LOGO_URL : '';
    $em_bg = defined( 'MADEYA_PLUGIN_URL' ) ? MADEYA_PLUGIN_URL . 'assets/images/etel-bg.webp' : '';
    ob_start(); ?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap');
:root{--f:#F5A623;--f2:#FFD166;--dk:rgba(10,7,2,0.82);--sf:rgba(18,13,4,0.80);--pn:rgba(22,16,5,0.88);--cd:rgba(30,21,6,0.82);--bd:rgba(180,130,30,0.35);--tx:#f5e6c0;--mt:#c9a96e;--gn:#27AE60;--rd:#C0392B;--bl:#2980B9;--or:#E67E22;}
body{margin:0!important;padding:0!important;overflow-x:hidden!important;}
html,body,.site,.site-content,.entry-content,.page-content,#content,#primary,article{margin:0!important;padding:0!important;max-width:100%!important;width:100%!important;}
#mc,#mc *{box-sizing:border-box;margin:0;padding:0;}
#mc{font-family:'Barlow Condensed',sans-serif;background:url('<?php echo esc_url($em_bg); ?>') center center/cover no-repeat fixed;background-color:#b8860b;color:var(--tx);min-height:100vh;width:100vw;margin:0;padding:0;}
#mc-vip-section{padding:0 20px 16px;}
.vip-pnl-h{background:linear-gradient(135deg,rgba(192,57,43,.18),rgba(192,57,43,.08))!important;border-bottom-color:rgba(192,57,43,.35)!important;}
.vip-title{color:#e74c3c!important;}
.vip-row{background:rgba(192,57,43,.06);border-left:3px solid #e74c3c;}
.vip-badge{display:inline-block;background:linear-gradient(135deg,#c0392b,#922b21);color:#fff;font-size:9px;font-weight:800;letter-spacing:2px;padding:2px 7px;border-radius:3px;text-transform:uppercase;margin-left:6px;}
.vip-reg-btn{width:100%;padding:11px;background:linear-gradient(135deg,#c0392b,#7b241c);border:none;border-radius:8px;color:#fff;font-family:'Barlow Condensed',sans-serif;font-size:14px;font-weight:800;letter-spacing:2px;text-transform:uppercase;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;margin-top:10px;transition:all .2s;}
.vip-reg-btn:hover{box-shadow:0 5px 20px rgba(192,57,43,.45);transform:translateY(-2px);}
.vip-reg-btn:disabled{opacity:.5;cursor:not-allowed;transform:none;}
#vip-fb{display:none;margin-top:10px;padding:10px 13px;border-radius:8px;font-size:13px;font-weight:600;align-items:center;gap:9px;}
#vip-fb.show{display:flex;}
#vip-fb.ok{background:rgba(39,174,96,.1);border:1px solid rgba(39,174,96,.3);color:var(--gn);}
#vip-fb.err{background:rgba(192,57,43,.08);border:1px solid rgba(192,57,43,.25);color:var(--rd);}
#vip-fb.warn{background:rgba(230,126,34,.08);border:1px solid rgba(230,126,34,.25);color:var(--or);}
.vip-plate-wrap{position:relative;margin-bottom:8px;}
.vip-plate-inp{width:100%;padding:13px 14px;background:rgba(192,57,43,.06);border:2px solid rgba(192,57,43,.35);border-radius:9px;color:var(--tx);font-family:'JetBrains Mono',monospace;font-size:20px;font-weight:700;letter-spacing:4px;text-transform:uppercase;text-align:center;outline:none;transition:all .2s;}
.vip-plate-inp:focus{border-color:#e74c3c;box-shadow:0 0 0 3px rgba(192,57,43,.15);}
.vip-plate-inp::placeholder{color:var(--mt);font-size:13px;letter-spacing:1px;}
#mc-ls{min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--dk);position:relative;}
#mc-ls::before{content:'';position:absolute;inset:0;background:repeating-linear-gradient(45deg,rgba(245,166,35,.025) 0,rgba(245,166,35,.025) 1px,transparent 1px,transparent 38px),repeating-linear-gradient(-45deg,rgba(245,166,35,.025) 0,rgba(245,166,35,.025) 1px,transparent 1px,transparent 38px);}
.lbox{background:var(--sf);border:1px solid var(--bd);border-radius:16px;padding:38px 34px;width:100%;max-width:390px;position:relative;z-index:1;box-shadow:0 20px 60px rgba(0,0,0,.6);animation:lbUp .45s cubic-bezier(.34,1.2,.64,1);}
@keyframes lbUp{from{opacity:0;transform:translateY(28px)}to{opacity:1;transform:translateY(0)}}
.lbox-logo{text-align:center;margin-bottom:26px;}
.lbox-ic{width:64px;height:64px;background:linear-gradient(135deg,var(--f),#c47f10);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:28px;color:#000;margin:0 auto 13px;box-shadow:0 0 28px rgba(245,166,35,.28);}
.lbox-title{font-size:24px;font-weight:900;color:var(--f);letter-spacing:3px;}
.lbox-sub{font-size:10px;color:var(--mt);letter-spacing:2.5px;text-transform:uppercase;margin-top:3px;}
.lbox-field{margin-bottom:13px;}
.lbox-field label{display:block;font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);margin-bottom:5px;}
.lbox-inp{width:100%;padding:12px 13px;background:var(--cd);border:1px solid var(--bd);border-radius:8px;color:var(--tx);font-family:'Barlow Condensed',sans-serif;font-size:16px;font-weight:600;transition:all .2s;outline:none;}
.lbox-inp:focus{border-color:var(--f);box-shadow:0 0 0 3px rgba(245,166,35,.1);}
.lbox-inp::placeholder{color:var(--mt);font-weight:400;}
.lbox-btn{width:100%;padding:13px;background:linear-gradient(135deg,var(--f),#c47f10);border:none;border-radius:9px;color:#000;font-family:'Barlow Condensed',sans-serif;font-size:15px;font-weight:800;letter-spacing:3px;text-transform:uppercase;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px;margin-top:4px;}
.lbox-btn:hover{box-shadow:0 5px 20px rgba(245,166,35,.4);transform:translateY(-2px);}
.lbox-btn:disabled{opacity:.5;cursor:not-allowed;transform:none;}
.lbox-err{background:rgba(192,57,43,.08);border:1px solid rgba(192,57,43,.3);border-radius:8px;padding:10px 13px;font-size:13px;color:var(--rd);margin-top:11px;display:none;align-items:center;gap:8px;}
.lbox-err.show{display:flex;}
#mc-hdr{background:linear-gradient(to right,rgba(10,6,1,0.90),rgba(20,12,2,0.85),rgba(10,6,1,0.90));border-bottom:3px solid var(--f);padding:0 20px;height:62px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10;box-shadow:0 4px 20px rgba(0,0,0,.5);width:100%;margin:0;}
.hdr-l{display:flex;align-items:center;gap:11px;}
.hdr-ico{width:40px;height:40px;background:var(--f);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:19px;color:#000;font-weight:900;}
.hdr-name{font-size:22px;font-weight:900;color:var(--f);letter-spacing:2px;line-height:1;}
.hdr-station{font-size:10px;color:var(--mt);letter-spacing:2px;text-transform:uppercase;}
.hdr-r{display:flex;align-items:center;gap:12px;}
.hdr-ctrl{font-size:13px;color:var(--tx);display:flex;align-items:center;gap:6px;}
.hdr-ctrl i{color:var(--f);}
.hdr-config{padding:6px 13px;background:rgba(41,128,185,.1);border:1px solid rgba(41,128,185,.3);border-radius:6px;color:var(--bl);font-family:'Barlow Condensed',sans-serif;font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;transition:all .2s;}
.hdr-config:hover{background:rgba(41,128,185,.2);}
.hdr-logout{padding:6px 13px;background:rgba(192,57,43,.1);border:1px solid rgba(192,57,43,.3);border-radius:6px;color:var(--rd);font-family:'Barlow Condensed',sans-serif;font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;transition:all .2s;}
.hdr-logout:hover{background:rgba(192,57,43,.2);}
.hdr-time{font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--mt);text-align:right;}
.hdr-time b{display:block;font-size:15px;color:var(--tx);}
#mc-config-bar{background:linear-gradient(135deg,rgba(41,128,185,.1),rgba(41,128,185,.05));border-bottom:1px solid rgba(41,128,185,.2);padding:10px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;font-size:12px;color:var(--mt);width:100%;margin:0;}
#mc-config-bar span{display:flex;align-items:center;gap:6px;}
#mc-config-bar b{color:var(--tx);}
.cfg-par-badge{padding:3px 10px;border-radius:4px;font-size:11px;font-weight:700;}
.cfg-par-badge.even{background:rgba(41,128,185,.15);color:var(--bl);}
.cfg-par-badge.odd{background:rgba(230,126,34,.12);color:var(--or);}
.cfg-par-badge.both{background:rgba(39,174,96,.1);color:var(--gn);}
#mc-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:11px;padding:16px 20px 0;width:100%;margin:0;}
.sc{background:var(--sf);border:1px solid var(--bd);border-radius:9px;padding:15px;text-align:center;position:relative;overflow:hidden;}
.sc::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
.sc.f::before{background:var(--f)}.sc.g::before{background:var(--gn)}.sc.b::before{background:var(--bl)}.sc.r::before{background:var(--rd)}
.sc b{display:block;font-family:'JetBrains Mono',monospace;font-size:36px;font-weight:700;line-height:1.1;}
.sc.f b{color:var(--f)}.sc.g b{color:var(--gn)}.sc.b b{color:var(--bl)}.sc.r b{color:var(--rd)}
.sc span{font-size:13px;color:var(--mt);text-transform:uppercase;letter-spacing:1.5px;}
#mc-body{display:grid;grid-template-columns:360px 1fr;gap:16px;padding:16px 20px;width:100%;margin:0;}
.pnl{background:var(--sf);border:1px solid var(--bd);border-radius:11px;overflow:hidden;}
.pnl-h{padding:14px 18px;background:var(--pn);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:10px;}
.pnl-h h3{font-size:17px;font-weight:800;letter-spacing:2px;text-transform:uppercase;color:var(--tx);}
.pnl-h i{color:var(--f);font-size:18px;}
.pnl-h-r{margin-left:auto;}
.pnl-b{padding:18px;}
.plate-wrap{position:relative;margin-bottom:6px;}
#reg-plate{width:100%;padding:17px 14px;background:var(--cd);border:2px solid var(--bd);border-radius:9px;color:var(--tx);font-family:'JetBrains Mono',monospace;font-size:28px;font-weight:700;letter-spacing:4px;text-transform:uppercase;text-align:center;transition:all .2s;outline:none;}
#reg-plate:focus{border-color:var(--f);box-shadow:0 0 0 4px rgba(245,166,35,.1);}
#reg-plate::placeholder{color:var(--mt);font-size:16px;letter-spacing:1px;}
.pc{position:absolute;width:10px;height:10px;border-color:rgba(245,166,35,.4);border-style:solid;}
.pc.tl{top:5px;left:5px;border-width:2px 0 0 2px}.pc.tr{top:5px;right:5px;border-width:2px 2px 0 0}.pc.bl{bottom:5px;left:5px;border-width:0 0 2px 2px}.pc.br{bottom:5px;right:5px;border-width:0 2px 2px 0}
.parity-hint{text-align:center;font-size:14px;color:var(--mt);margin:8px 0 14px;min-height:18px;}
.parity-hint b{font-weight:700;}
.vtype-label{font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);margin-bottom:9px;}
.vtypes{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:15px;}
.vt-btn{padding:11px 9px;background:var(--cd);border:1px solid var(--bd);border-radius:8px;color:var(--mt);font-family:'Barlow Condensed',sans-serif;font-size:16px;font-weight:700;letter-spacing:.8px;text-align:center;cursor:pointer;transition:all .2s;}
.vt-btn:hover{border-color:var(--f);color:var(--f);}
.vt-btn.sel{background:rgba(245,166,35,.1);border-color:var(--f);color:var(--f2);}
.vt-btn.disabled-type{opacity:.3;cursor:not-allowed;pointer-events:none;}
.vt-btn i{display:block;font-size:22px;margin-bottom:4px;}
#mc-reg-btn{width:100%;padding:16px;background:linear-gradient(135deg,var(--f),#c47f10);border:none;border-radius:9px;color:#000;font-family:'Barlow Condensed',sans-serif;font-size:18px;font-weight:800;letter-spacing:2.5px;text-transform:uppercase;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:10px;}
#mc-reg-btn:hover{box-shadow:0 5px 20px rgba(245,166,35,.4);transform:translateY(-1px);}
#mc-reg-btn:disabled{opacity:.5;cursor:not-allowed;transform:none;}
#mc-fb{margin-top:14px;border-radius:8px;padding:14px 16px;display:none;align-items:flex-start;gap:10px;font-size:16px;line-height:1.5;animation:fbIn .2s ease;}
#mc-fb.show{display:flex;}
@keyframes fbIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}
#mc-fb.ok{background:rgba(39,174,96,.08);border:1px solid rgba(39,174,96,.25);color:var(--gn);}
#mc-fb.warn{background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.3);color:var(--f2);}
#mc-fb.err{background:rgba(192,57,43,.08);border:1px solid rgba(192,57,43,.25);color:var(--rd);}
#mc-fb i{flex-shrink:0;font-size:20px;margin-top:1px;}
.fb-tk{font-family:'JetBrains Mono',monospace;font-size:30px;font-weight:700;color:var(--f2);display:block;margin-top:4px;letter-spacing:2px;}
.rpt-bar{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;}
.rpt-bar label{font-size:13px;font-weight:700;color:var(--mt);letter-spacing:2px;text-transform:uppercase;}
#rpt-date{padding:6px 10px;background:var(--cd);border:1px solid var(--bd);border-radius:6px;color:var(--tx);font-family:'JetBrains Mono',monospace;font-size:15px;outline:none;}
.rpt-info{font-size:15px;color:var(--mt);margin-bottom:8px;}
.rpt-list{max-height:170px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--bd) transparent;}
.rpt-row{display:flex;align-items:center;justify-content:space-between;padding:8px 11px;border-bottom:1px solid rgba(255,255,255,.04);font-size:15px;}
.rpt-row:last-child{border-bottom:none;}
.rpt-pl{font-family:'JetBrains Mono',monospace;color:var(--tx);font-weight:600;font-size:16px;}
.rpt-right{display:flex;align-items:center;gap:8px;}
.rpt-tp{font-size:14px;color:var(--mt);}
.par-badge{font-size:13px;padding:2px 8px;border-radius:4px;font-weight:700;}
.par-badge.even{background:rgba(41,128,185,.13);color:var(--bl);}
.par-badge.odd{background:rgba(230,126,34,.1);color:var(--or);}
.rpt-tm{font-size:14px;color:var(--mt);}
#mc-qpanel{background:var(--sf);border:1px solid var(--bd);border-radius:11px;overflow:hidden;display:flex;flex-direction:column;}
#mc-qtbl-wrap{flex:1;overflow-y:auto;max-height:calc(100vh - 270px);scrollbar-width:thin;scrollbar-color:var(--bd) transparent;}
.qtbl{width:100%;border-collapse:collapse;}
.qtbl thead th{padding:12px 15px;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);background:var(--pn);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);border-bottom:1px solid var(--bd);text-align:left;position:sticky;top:0;z-index:2;}
.qr{border-bottom:1px solid rgba(255,255,255,.04);transition:background .12s;animation:rIn .2s ease;}
@keyframes rIn{from{opacity:0;transform:translateX(-6px)}to{opacity:1;transform:translateX(0)}}
.qr:hover{background:rgba(245,166,35,.04);}
.qr.first-row{background:rgba(245,166,35,.06);border-left:3px solid var(--f);}
.qr td{padding:12px 15px;font-size:16px;vertical-align:middle;}
.q-pos{font-family:'JetBrains Mono',monospace;font-size:22px;font-weight:700;color:var(--f2);width:50px;}
.q-pos.top{font-size:28px;color:var(--f);}
.q-pl{font-family:'JetBrains Mono',monospace;font-size:18px;font-weight:700;color:var(--tx);}
.q-tp{font-size:14px;color:var(--mt);}
.q-tk{font-family:'JetBrains Mono',monospace;font-size:14px;color:var(--mt);}
.q-tm{font-size:14px;color:var(--mt);}
.srv-btn{padding:8px 16px;background:linear-gradient(135deg,var(--gn),#1e8449);border:none;border-radius:7px;color:#fff;font-family:'Barlow Condensed',sans-serif;font-size:15px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;transition:all .15s;display:flex;align-items:center;gap:6px;white-space:nowrap;}
.srv-btn:hover{box-shadow:0 3px 12px rgba(39,174,96,.4);transform:translateY(-1px);}
.eq{text-align:center;padding:50px 20px;color:var(--mt);}
.eq i{font-size:48px;opacity:.18;display:block;margin-bottom:12px;}
.eq p{font-size:18px;font-weight:600;}
.clr-btn{padding:10px 18px;background:rgba(192,57,43,.1);border:1px solid rgba(192,57,43,.3);border-radius:8px;color:var(--rd);font-family:'Barlow Condensed',sans-serif;font-size:14px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;transition:all .2s;}
.clr-btn:hover{background:rgba(192,57,43,.2);}
#mc-toast{position:fixed;bottom:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:7px;pointer-events:none;}
.toast-item{background:var(--sf);border:1px solid var(--bd);border-radius:9px;padding:11px 15px;display:flex;align-items:center;gap:8px;font-size:13px;color:var(--tx);box-shadow:0 8px 26px rgba(0,0,0,.5);animation:tIn .3s cubic-bezier(.34,1.56,.64,1);min-width:230px;}
@keyframes tIn{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
.toast-item.ok i{color:var(--gn)}.toast-item.warn i{color:var(--f)}.toast-item.err i{color:var(--rd)}
.wait-btn{padding:6px 12px;background:linear-gradient(135deg,#e67e22,#ca6f1e);border:none;border-radius:6px;color:#fff;font-family:'Barlow Condensed',sans-serif;font-size:12px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;}
.wait-btn:hover{box-shadow:0 3px 12px rgba(230,126,34,.45);transform:translateY(-1px);}
.qr.waiting-row{background:rgba(230,126,34,.05);border-left:3px solid var(--or);}
#mc-waiting-section{display:none;}
#mc-waiting-section.has-waiting{display:block;}

/* ── Hide WordPress theme header/logo on controller page ── */
.site-header, .site-branding, header.site-header,
#masthead, #site-header, .header-main,
.wp-site-blocks > header, body > header,
.entry-header img, .site-logo img,
.custom-logo-link img, .custom-logo {
  display: none !important;
}
#mc img { max-width: 100% !important; height: auto !important; }
#mc-hdr img { height: 36px !important; width: auto !important; max-width: 140px !important; object-fit: contain !important; }

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


/* ── Priority Tier Selector ── */
.priority-select-wrap{margin:10px 0 13px;}
.priority-select-label{font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);margin-bottom:7px;}
.priority-tiers-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px;}
.tier-btn{padding:8px 10px;background:var(--cd);border:1px solid var(--bd);border-radius:8px;cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:8px;text-align:left;}
.tier-btn:hover{border-color:var(--f);background:rgba(245,166,35,.05);}
.tier-btn.sel{border-width:2px;}
.tier-num-badge{width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;color:#fff;flex-shrink:0;}
.tier-btn-text{flex:1;}
.tier-btn-label{font-size:11px;font-weight:700;color:var(--tx);line-height:1.2;}
.tier-btn-amh{font-size:9px;color:var(--mt);margin-top:1px;}
.tier-btn.disabled-tier{opacity:.35;cursor:not-allowed;pointer-events:none;}

/* ── Mobile breakpoints ── */
@media(max-width:880px){
  #mc-stats{grid-template-columns:1fr 1fr !important;}
  #mc-body{grid-template-columns:1fr !important;}
}
@media(max-width:520px){
  #mc-stats{grid-template-columns:repeat(2,1fr) !important;}
  .sc b{font-size:26px !important;}
  #mc-hdr{padding:0 10px !important;}
  .hdr-config,.hdr-logout{padding:5px 8px !important;font-size:9px !important;}
  .hdr-name{font-size:18px !important;}
  .qtbl{font-size:13px !important;}
  .qr td{padding:8px 8px !important;}
  .srv-btn{padding:7px 8px !important;font-size:12px !important;}
  /* Mobile button text - show icons only or short text */
  .btn-text-desktop{display:none !important;}
  .btn-text-mobile{display:inline !important;}
  .pnl-h-r{gap:4px !important;}
  .clr-btn{padding:6px 8px !important;font-size:10px !important;}
}

/* ── Shimmer skeleton loading ── */
@keyframes mcShimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
.q-skel{height:14px;border-radius:4px;background:linear-gradient(90deg,var(--cd) 25%,rgba(255,255,255,.07) 50%,var(--cd) 75%);background-size:400% 100%;animation:mcShimmer 1.4s infinite;}

/* ── QR code in registration feedback ── */
#mc-qr-wrap{text-align:center;margin-top:12px;display:none;border-top:1px solid var(--bd);padding-top:12px;}
#mc-qr-wrap.show{display:block;}
.mc-qr-lbl{font-size:9px;font-weight:700;letter-spacing:2px;color:var(--mt);text-transform:uppercase;margin-bottom:6px;}
#mc-qr-img{display:inline-block;background:#fff;border-radius:8px;padding:5px;}

/* ── Default credentials warning banner ── */
#mc-pw-warning{background:linear-gradient(135deg,rgba(230,126,34,.15),rgba(230,126,34,.07));border:2px solid rgba(230,126,34,.5);border-radius:10px;padding:12px 16px;margin:10px 20px 0;display:none;align-items:center;gap:10px;font-size:13px;color:var(--or);}
#mc-pw-warning.show{display:flex;}
#mc-pw-warning b{font-size:14px;display:block;margin-bottom:2px;}
#mc-pw-warning i{font-size:22px;flex-shrink:0;}

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
.ma-tbl, .ptbl, table {
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


/* -- Controller Tab Navigation -- */
#mc-tab-nav{display:flex;background:linear-gradient(135deg,rgba(10,6,1,0.96),rgba(18,13,4,0.94));border-bottom:2px solid var(--bd);position:sticky;top:72px;z-index:40;box-shadow:0 4px 16px rgba(0,0,0,.5);}
.mc-tab{flex:1;padding:11px 6px 9px;background:none;border:none;border-bottom:3px solid transparent;color:var(--mt);font-family:'Barlow Condensed',sans-serif;font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;transition:all .2s;display:flex;flex-direction:column;align-items:center;gap:3px;}
.mc-tab i{font-size:15px;}
.mc-tab:hover{color:var(--tx);}
.mc-tab.active{color:var(--f);border-bottom-color:var(--f);}
.mc-tab-panel{display:none;}
.mc-tab-panel.active{display:block;}

/* ── Report Tab - Repeat Vehicle Highlighting ── */
.qr.repeat-vehicle{background:rgba(192,57,43,.18)!important;border-left:4px solid var(--rd)!important;}
.qr.repeat-vehicle:hover{background:rgba(192,57,43,.28)!important;}
.qr.repeat-vehicle .q-pl{color:#fca5a5!important;font-weight:900!important;}
.repeat-badge{display:inline-block;background:linear-gradient(135deg,#c0392b,#922b21);color:#fff;font-size:10px;font-weight:800;letter-spacing:1.5px;padding:3px 8px;border-radius:4px;text-transform:uppercase;animation:pulse-red 2s infinite;}
.first-badge{display:inline-block;background:linear-gradient(135deg,var(--gn),#1e8449);color:#fff;font-size:10px;font-weight:800;letter-spacing:1.5px;padding:3px 8px;border-radius:4px;text-transform:uppercase;}
@keyframes pulse-red{0%,100%{box-shadow:0 0 5px rgba(192,57,43,.5);}50%{box-shadow:0 0 15px rgba(192,57,43,.9);}}
</style>

<div id="mc">
 <!-- LOGIN -->
 <div id="mc-ls">
  <div class="lbox">
   <div class="lbox-logo" style="text-align:center;margin-bottom:20px;">
<img src="<?php echo esc_url($em_logo); ?>" alt="ETEL MADEYA" style="height:48px;width:auto;max-width:150px;border-radius:10px;object-fit:contain;filter:drop-shadow(0 0 10px rgba(245,166,35,.4));transition:filter .3s,transform .3s;" onmouseover="this.style.filter='drop-shadow(0 0 20px rgba(245,166,35,.8))';this.style.transform='scale(1.04)'" onmouseout="this.style.filter='drop-shadow(0 0 10px rgba(245,166,35,.4))';this.style.transform='scale(1)'">    <div class="lbox-title">ETEL MADEYA</div>
    <div style="font-size:11px;color:#2980B9;font-weight:700;letter-spacing:1px;margin-top:2px;">ኢትኤል ማደያ</div>
    <div class="lbox-sub" style="margin-top:4px;">Controller Login</div>
    <div style="display:inline-flex;align-items:center;gap:4px;background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.25);border-radius:20px;padding:2px 10px;font-size:7px;font-weight:700;color:var(--f);letter-spacing:1.5px;text-transform:uppercase;margin-top:6px;">
     <i class="fas fa-registered"></i> ኢE PATENT &amp; TRADEMARK
    </div>
   </div>
   <div class="lbox-field">
    <label for="lc-user">Username</label>
    <input type="text" id="lc-user" class="lbox-inp" placeholder="Enter username" autocomplete="username">
   </div>
   <div class="lbox-field">
    <label for="lc-pass">Password</label>
    <input type="password" id="lc-pass" class="lbox-inp" placeholder="Enter password" autocomplete="current-password">
   </div>
   <button type="button" class="lbox-btn" id="lc-btn" onclick="doLogin()">
    <i class="fas fa-sign-in-alt"></i>SIGN IN
   </button>
   <div class="lbox-err" id="lc-err"><i class="fas fa-exclamation-circle"></i><span id="lc-err-msg">Error</span></div>
  </div>
 </div>

 <!-- APP -->
 <div id="mc-app" style="display:none">
  <div id="mc-hdr" style="background:linear-gradient(135deg,rgba(10,6,1,0.92),rgba(20,14,3,0.88),rgba(10,6,1,0.92));border-bottom:3px solid var(--f);padding:0 16px;height:72px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;box-shadow:0 4px 32px rgba(0,0,0,.8);">
   <div class="hdr-l" style="gap:0;align-items:center;">
    <div style="height:62px;display:flex;align-items:center;padding-right:14px;border-right:1px solid rgba(245,166,35,.25);">
    <img src="<?php echo esc_url($em_logo); ?>" alt="ETEL MADEYA" style="height:36px;width:auto;max-width:140px;object-fit:contain;filter:drop-shadow(0 0 10px rgba(245,166,35,.4));transition:filter .3s,transform .3s;" onmouseover="this.style.filter='drop-shadow(0 0 20px rgba(245,166,35,.8))';this.style.transform='scale(1.04)'" onmouseout="this.style.filter='drop-shadow(0 0 10px rgba(245,166,35,.4))';this.style.transform='scale(1)'">    </div>
    <div style="padding-left:14px;">
     <div style="font-size:18px;font-weight:900;color:var(--f);letter-spacing:3px;line-height:1.1;text-shadow:0 0 20px rgba(245,166,35,.3);">ETEL MADEYA</div>
     <div style="font-size:10px;color:#2980B9;font-weight:700;letter-spacing:1px;">ኢትኤል ማደያ <span style="color:var(--mt);font-weight:400;">·</span> <span id="hdr-station" style="color:var(--mt);">Gas Station</span></div>
     <div style="display:inline-flex;align-items:center;gap:3px;background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.2);border-radius:12px;padding:1px 8px;font-size:7px;font-weight:700;color:var(--f);letter-spacing:1.5px;margin-top:2px;">
      <i class="fas fa-registered" style="font-size:6px;"></i> ኢE PATENT &amp; TRADEMARK
     </div>
    </div>
   </div>
   <div class="hdr-r">
    <div class="hdr-ctrl"><i class="fas fa-user-shield"></i><span id="hdr-ctrl">Controller</span></div>
    <button type="button" class="hdr-config" onclick="window.open('<?php echo esc_url(admin_url('admin.php?page=madeya-queue')); ?>','_blank')"><i class="fas fa-cog"></i> SETTINGS</button>
    <button type="button" class="hdr-logout" onclick="doLogout()"><i class="fas fa-sign-out-alt"></i> LOGOUT</button>
    <div class="hdr-time"><span id="hdr-date"></span><b id="hdr-time"></b></div>
   </div>
  </div>
  <div id="mc-config-bar">
   <span><i class="fas fa-sliders-h" style="color:var(--bl)"></i>Today's Rules:</span>
   <span>Parity: <b id="cfg-parity-lbl">—</b></span>
   <span>Vehicle Types: <b id="cfg-types-lbl">—</b></span>
  </div>
  <div id="mc-pw-warning">
   <i class="fas fa-exclamation-triangle"></i>
   <div><b>Default Password Detected</b>You are using the default password. Please change it immediately in Settings for security.</div>
  </div>
  <div id="mc-stats" style="grid-template-columns:repeat(5,1fr);">
   <div class="sc f"><b id="s-q">0</b><span>In Queue</span></div>
   <div class="sc g"><b id="s-sv">0</b><span>Served Today</span></div>
   <div class="sc b"><b id="s-oe">—</b><span>Odd / Even</span></div>
   <div class="sc r"><b id="s-tot">0</b><span>Total Today</span></div>
   <div class="sc" style="border-top:3px solid #27AE60;padding:10px 13px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:5px;">
     <span style="font-size:10px;color:var(--mt);text-transform:uppercase;letter-spacing:1.5px;"><i class="fas fa-gas-pump" style="color:#27AE60;margin-right:4px;"></i>Fuel</span>
     <b id="s-fuel-rem" style="font-family:'JetBrains Mono',monospace;font-size:18px;font-weight:700;color:#27AE60;line-height:1;">—</b>
    </div>
    <div style="background:var(--bd);border-radius:4px;height:6px;overflow:hidden;margin-bottom:4px;">
     <div id="s-fuel-bar" style="height:100%;background:linear-gradient(90deg,#27AE60,#2ecc71);border-radius:4px;transition:width .5s;width:0%"></div>
    </div>
    <div style="display:flex;justify-content:space-between;">
     <span style="font-size:9px;color:var(--mt)">Remaining L</span>
     <span id="s-fuel-pct" style="font-size:9px;color:var(--mt)">—%</span>
    </div>
   </div>
  </div>

   <!-- -- Controller Tab Navigation -- -->
 <nav id="mc-tab-nav">
  <button class="mc-tab active" onclick="mcTab('register',this)">
   <i class="fas fa-plus-circle"></i>Register
  </button>
  <button class="mc-tab" onclick="mcTab('waiting',this)">
   <i class="fas fa-clock"></i>Waiting
  </button>
  <button class="mc-tab" onclick="mcTab('vip',this)">
   <i class="fas fa-star"></i>VIP
  </button>
  <button class="mc-tab" onclick="mcTab('report',this)">
   <i class="fas fa-file-alt"></i>Report
  </button>
 </nav>
 <!-- -- Tab: Register + Queue -- -->
 <div id="mc-tab-register" class="mc-tab-panel active">
  <div id="mc-body">
   <div class="pnl">
    <div class="pnl-h"><i class="fas fa-plus-circle"></i><h3>Register Vehicle</h3></div>
    <div class="pnl-b">
     <div class="plate-wrap">
      <input type="text" id="reg-plate" placeholder="PLATE NUMBER" maxlength="20" autocomplete="off">
      <div class="pc tl"></div><div class="pc tr"></div><div class="pc bl"></div><div class="pc br"></div>
     </div>
     <div class="parity-hint" id="par-hint">&nbsp;</div>
     <div class="vtype-label">Vehicle Type <span style="color:var(--mt);font-size:9px;margin-left:4px;">(select vehicle category)</span></div>
     <!-- Hidden input stores selected value -->
     <input type="hidden" id="mc-vtype" value="bajaj">
     <div id="vtype-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:13px;">
      <!-- filled by JS -->
     </div>
     <div id="vtype-hint" style="font-size:10px;color:var(--mt);margin-top:-8px;margin-bottom:10px;min-height:14px;"></div>
     <!-- Priority Tier Selector -->
     

     
     <button type="button" id="mc-reg-btn" onclick="doRegister()"><i class="fas fa-car"></i>REGISTER &amp; ASSIGN TOKEN</button>
     <div id="mc-fb"><i></i><div><span id="fb-msg"></span><span class="fb-tk" id="fb-tk"></span></div></div>
     <!-- QR code shown after successful registration -->
     <div id="mc-qr-wrap">
      <div class="mc-qr-lbl"><i class="fas fa-qrcode" style="margin-right:4px;"></i>Show QR to Driver</div>
      <div id="mc-qr-img"></div>
     </div>

     <!-- ── TXT File Import ── -->
     <div style="border-top:1px solid var(--bd);margin:15px 0"></div>
     <div style="margin-bottom:13px;">
      <div style="font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);margin-bottom:7px;"><i class="fas fa-file-import" style="color:var(--bl);margin-right:5px;"></i>Import from TXT File</div>
      <label for="mc-import-file" style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:10px;background:rgba(41,128,185,.07);border:1.5px dashed rgba(41,128,185,.4);border-radius:8px;color:var(--bl);font-family:'Barlow Condensed',sans-serif;font-size:13px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;transition:all .2s;" onmouseover="this.style.background='rgba(41,128,185,.14)'" onmouseout="this.style.background='rgba(41,128,185,.07)'">
       <i class="fas fa-upload"></i><span id="mc-import-label">CHOOSE TXT FILE</span>
      </label>
      <input type="file" id="mc-import-file" accept=".txt" style="display:none;" onchange="onImportFileChosen(this)">
      <div id="mc-import-preview" style="display:none;margin-top:8px;padding:9px 11px;background:var(--cd);border:1px solid var(--bd);border-radius:7px;font-size:12px;color:var(--mt);max-height:90px;overflow-y:auto;font-family:'JetBrains Mono',monospace;"></div>
      <button type="button" id="mc-import-btn" onclick="doImport()" style="display:none;width:100%;margin-top:8px;padding:11px;background:linear-gradient(135deg,#2980B9,#1a5276);border:none;border-radius:8px;color:#fff;font-family:'Barlow Condensed',sans-serif;font-size:13px;font-weight:800;letter-spacing:2px;text-transform:uppercase;cursor:pointer;display:none;align-items:center;justify-content:center;gap:7px;transition:all .2s;">
       <i class="fas fa-file-import"></i>IMPORT NUMBERS
      </button>
      <div id="mc-import-fb" style="display:none;margin-top:8px;padding:10px 13px;border-radius:7px;font-size:12px;font-weight:600;"></div>
     </div>

     <!-- ── Pre-Served Import ── -->
     <div style="margin-bottom:13px;">
      <div style="font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);margin-bottom:7px;"><i class="fas fa-ban" style="color:var(--rd);margin-right:5px;"></i>Import Already-Served Numbers</div>
      <label for="mc-served-file" style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:10px;background:rgba(192,57,43,.07);border:1.5px dashed rgba(192,57,43,.4);border-radius:8px;color:var(--rd);font-family:'Barlow Condensed',sans-serif;font-size:13px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;transition:all .2s;" onmouseover="this.style.background='rgba(192,57,43,.14)'" onmouseout="this.style.background='rgba(192,57,43,.07)'">
       <i class="fas fa-upload"></i><span id="mc-served-label">CHOOSE TXT FILE</span>
      </label>
      <input type="file" id="mc-served-file" accept=".txt" style="display:none;" onchange="onServedFileChosen(this)">
      <div id="mc-served-preview" style="display:none;margin-top:8px;padding:9px 11px;background:var(--cd);border:1px solid var(--bd);border-radius:7px;font-size:12px;color:var(--mt);max-height:90px;overflow-y:auto;font-family:'JetBrains Mono',monospace;"></div>
      <button type="button" id="mc-served-btn" onclick="doImportServed()" style="display:none;width:100%;margin-top:8px;padding:11px;background:linear-gradient(135deg,#c0392b,#7b241c);border:none;border-radius:8px;color:#fff;font-family:'Barlow Condensed',sans-serif;font-size:13px;font-weight:800;letter-spacing:2px;text-transform:uppercase;cursor:pointer;align-items:center;justify-content:center;gap:7px;transition:all .2s;">
       <i class="fas fa-ban"></i>MARK AS SERVED
      </button>
      <div id="mc-served-fb" style="display:none;margin-top:8px;padding:10px 13px;border-radius:7px;font-size:12px;font-weight:600;"></div>
     </div>

     <div style="border-top:1px solid var(--bd);margin:15px 0"></div>
     <div class="rpt-bar">
      <label for="rpt-date">Daily Report</label>
      <input type="date" id="rpt-date">
     </div>
     <div class="rpt-info" id="rpt-info">—</div>
     <div class="rpt-list" id="rpt-list"></div>
    </div>
   </div>
   <div id="mc-qpanel">
    <div class="pnl-h">
     <i class="fas fa-list-ol"></i><h3>Active Queue</h3>
     <div class="pnl-h-r" style="display:flex;gap:6px;flex-wrap:wrap;">
      <button type="button" class="clr-btn" style="background:rgba(192,57,43,.12);border-color:rgba(192,57,43,.4);color:var(--rd);font-size:11px;padding:6px 10px;" onclick="openDuplicatesImport()"><i class="fas fa-exclamation-triangle"></i> <span class="btn-text-desktop">DUPLICATES</span><span class="btn-text-mobile" style="display:none;">DUP</span></button>
      <button type="button" class="clr-btn" style="background:rgba(41,128,185,.12);border-color:rgba(41,128,185,.4);color:var(--bl);font-size:11px;padding:6px 10px;" onclick="openBulkImport('normal')"><i class="fas fa-file-upload"></i> <span class="btn-text-desktop">IMPORT</span><span class="btn-text-mobile" style="display:none;">IMP</span></button>
      <button type="button" class="clr-btn" style="font-size:11px;padding:6px 10px;" onclick="doClearQueue()"><i class="fas fa-trash"></i> <span class="btn-text-desktop">CLEAR</span><span class="btn-text-mobile" style="display:none;">CLR</span></button>
     </div>
    </div>
    <div style="padding:12px 18px;background:rgba(30,21,6,0.82);border-bottom:1px solid var(--bd);">
     <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <input type="text" id="mc-search-input" placeholder="🔍 Search plate number..." style="flex:1;min-width:200px;padding:8px 12px;background:var(--cd);border:1px solid var(--bd);border-radius:6px;color:var(--tx);font-family:'JetBrains Mono',monospace;font-size:14px;outline:none;" oninput="searchQueueRealtime()" onkeyup="if(event.key==='Enter') searchQueue()">
      <button type="button" onclick="clearSearch()" style="padding:8px 16px;background:rgba(192,57,43,.1);border:1px solid rgba(192,57,43,.3);border-radius:6px;color:var(--rd);font-family:'Barlow Condensed',sans-serif;font-size:13px;font-weight:700;letter-spacing:1.5px;cursor:pointer;white-space:nowrap;"><i class="fas fa-times"></i> CLEAR</button>
     </div>
     <div id="duplicates-count-indicator" style="display:none;margin-top:8px;padding:6px 10px;background:rgba(192,57,43,.08);border:1px solid rgba(192,57,43,.25);border-radius:5px;font-size:11px;color:#C0392B;font-weight:700;">
      <i class="fas fa-exclamation-triangle"></i> <span id="duplicates-count-text"></span>
     </div>
    </div>
    <div id="mc-qtbl-wrap">
     <table class="qtbl">
      <thead><tr><th>#</th><th>Plate</th><th>Type</th><th>Parity</th><th>Gal</th><th>Token</th><th>Time</th><th>Action</th></tr></thead>
      <tbody id="mc-qtbody"></tbody>
     </table>
    </div>
    <div id="mc-pagination" style="padding:14px 18px;background:rgba(30,21,6,0.82);border-top:1px solid var(--bd);display:none;">
     <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
      <div id="mc-page-info" style="font-size:13px;color:var(--mt);font-weight:600;"></div>
      <div id="mc-page-btns" style="display:flex;gap:6px;flex-wrap:wrap;"></div>
     </div>
    </div>
   </div>
  </div>
 </div>

 <!-- -- Tab: Waiting -- -->
 <div id="mc-tab-waiting" class="mc-tab-panel">
  <div id="mc-waiting-section" style="padding:0 20px 16px;">
   <div id="mc-wpanel" class="pnl">
    <div class="pnl-h" style="background:rgba(230,126,34,.08);border-bottom-color:rgba(230,126,34,.25);">
     <i class="fas fa-clock" style="color:var(--or)"></i>
     <h3 style="color:var(--or)">Waiting Queue</h3>
     <span id="wq-count" style="margin-left:6px;font-size:11px;color:var(--mt);font-weight:600;letter-spacing:1px;"></span>
    </div>
    <div id="mc-wtbl-wrap" style="overflow-y:auto;max-height:280px;scrollbar-width:thin;scrollbar-color:var(--bd) transparent;">
     <table class="qtbl">
      <thead><tr><th>#</th><th>Plate</th><th>Type</th><th>Parity</th><th>Token</th><th>Time</th><th>Action</th></tr></thead>
      <tbody id="mc-wtbody"></tbody>
     </table>
    </div>
   </div>
  </div>
 </div>

 <!-- -- Tab: VIP -- -->
 <div id="mc-tab-vip" class="mc-tab-panel">
  <div id="mc-vip-section">
   <div class="pnl" style="border-color:rgba(192,57,43,.35);">
    <div class="pnl-h vip-pnl-h">
     <i class="fas fa-star" style="color:#e74c3c"></i>
     <h3 class="vip-title">VIP Queue <span class="vip-badge">PAID PRIORITY</span></h3>
     <span id="vip-count" style="margin-left:6px;font-size:11px;color:var(--mt);font-weight:600;letter-spacing:1px;"></span>
     <div style="margin-left:auto;">
      <button type="button" class="clr-btn" style="background:rgba(192,57,43,.12);border-color:rgba(192,57,43,.4);color:#e74c3c;" onclick="openBulkImport('vip')"><i class="fas fa-file-upload"></i> IMPORT VIP</button>
     </div>
    </div>
    <div style="display:grid;grid-template-columns:300px 1fr;gap:0;">
     <div style="padding:16px;border-right:1px solid rgba(192,57,43,.2);">
      <div style="font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#e74c3c;margin-bottom:8px;"><i class="fas fa-plus-circle"></i> Register VIP Vehicle</div>
      <div class="vip-plate-wrap">
       <input type="text" id="vip-plate" class="vip-plate-inp" placeholder="VIP PLATE" maxlength="20" autocomplete="off">
      </div>
      <div class="parity-hint" id="vip-par-hint" style="margin-bottom:0;">&nbsp;</div>

      <div style="margin-bottom:10px;">
       <div class="vtype-label" style="color:#e74c3c;">Liters to Fill</div>
       <div style="position:relative;">
        <input type="number" id="vip-liters" min="1" max="9999" step="1" placeholder="e.g. 40"
               style="width:100%;padding:11px 44px 11px 14px;background:rgba(192,57,43,.06);border:2px solid rgba(192,57,43,.35);border-radius:9px;color:var(--tx);font-family:'JetBrains Mono',monospace;font-size:18px;font-weight:700;outline:none;transition:all .2s;">
        <span style="position:absolute;right:14px;top:50%;transform:translateY(-50%);font-size:11px;color:var(--mt);font-weight:700;letter-spacing:1px;">L</span>
       </div>
      </div>
      <button type="button" class="vip-reg-btn" id="vip-reg-btn" onclick="doVipRegister()">
       <i class="fas fa-star"></i>ADD TO VIP QUEUE
      </button>
      <div id="vip-fb"><i></i><span id="vip-fb-msg"></span></div>
     </div>
     <div id="mc-vtbl-wrap" style="overflow-y:auto;max-height:320px;scrollbar-width:thin;scrollbar-color:rgba(192,57,43,.2) transparent;">
      <table class="qtbl">
       <thead><tr><th>#</th><th>Plate</th><th>Type</th><th>Parity</th><th>Token</th><th>Time</th><th>Action</th></tr></thead>
       <tbody id="mc-vtbody"></tbody>
      </table>
     </div>
    </div>
   </div>
  </div>
 </div>
</div>

<!-- ── ETEL MADEYA Brand Footer ── -->
 </div>

 <!-- -- Tab: Report (Served Vehicles) -- -->
 <div id="mc-tab-report" class="mc-tab-panel" style="padding:16px 20px;">
  <div style="margin-bottom:16px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
   <div>
    <label style="display:block;font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);margin-bottom:5px;"><i class="fas fa-calendar-alt" style="color:var(--f);margin-right:4px;"></i>Report Date:</label>
    <input type="date" id="mc-report-date" style="padding:9px 12px;background:var(--cd);border:1px solid var(--bd);border-radius:6px;color:var(--tx);font-family:'JetBrains Mono',monospace;font-size:14px;outline:none;" onchange="mcLoadReport()">
   </div>
   <div style="flex:1;min-width:220px;">
    <label style="display:block;font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);margin-bottom:5px;"><i class="fas fa-search" style="color:var(--f);margin-right:4px;"></i>Search Plate:</label>
    <div style="display:flex;gap:6px;">
     <input type="text" id="mc-report-search" placeholder="Search plate number..." style="flex:1;padding:9px 12px;background:var(--cd);border:1px solid var(--bd);border-radius:6px;color:var(--tx);font-family:'JetBrains Mono',monospace;font-size:14px;outline:none;" onkeyup="if(event.key==='Enter') mcSearchReport()">
     <button onclick="mcSearchReport()" style="padding:9px 16px;background:linear-gradient(135deg,var(--f),#c47f10);border:none;border-radius:6px;color:#000;font-family:'Barlow Condensed',sans-serif;font-size:13px;font-weight:700;letter-spacing:1.5px;cursor:pointer;white-space:nowrap;"><i class="fas fa-search"></i> SEARCH</button>
     <button onclick="mcClearReportSearch()" style="padding:9px 14px;background:rgba(192,57,43,.15);border:1px solid rgba(192,57,43,.35);border-radius:6px;color:var(--rd);font-family:'Barlow Condensed',sans-serif;font-size:13px;font-weight:700;cursor:pointer;"><i class="fas fa-times"></i></button>
    </div>
   </div>
   <button onclick="mcLoadReport()" style="padding:9px 16px;background:rgba(41,128,185,.1);border:1px solid rgba(41,128,185,.3);border-radius:6px;color:var(--bl);font-family:'Barlow Condensed',sans-serif;font-size:13px;font-weight:700;letter-spacing:1.5px;cursor:pointer;white-space:nowrap;"><i class="fas fa-sync-alt"></i> REFRESH</button>
  </div>
  <div class="pnl">
   <div class="pnl-h">
    <i class="fas fa-file-alt"></i><h3>Served Vehicles Report</h3>
    <span id="mc-report-count" style="margin-left:8px;font-size:11px;color:var(--mt);font-weight:600;letter-spacing:1px;"></span>
   </div>
   <div id="mc-rtbl-wrap" style="overflow-y:auto;max-height:calc(100vh - 380px);scrollbar-width:thin;scrollbar-color:var(--bd) transparent;">
    <table class="qtbl">
     <thead><tr><th>#</th><th>Plate</th><th>Type</th><th>Token</th><th>Fuel (L)</th><th>Served At</th><th>Status</th></tr></thead>
     <tbody id="mc-rtbody"><tr><td colspan="7" class="eq"><p>Select a date to load report</p></td></tr></tbody>
    </table>
   </div>
   <div id="mc-report-pagination" style="padding:12px 18px;background:rgba(30,21,6,0.82);border-top:1px solid var(--bd);display:none;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
     <div id="mc-report-page-info" style="font-size:13px;color:var(--mt);font-weight:600;"></div>
     <div id="mc-report-page-btns" style="display:flex;gap:5px;flex-wrap:wrap;"></div>
    </div>
   </div>
  </div>
 </div>
<div class="em-brand-footer">
 <img src="<?php echo esc_url($em_logo); ?>" alt="ETEL MADEYA" class="em-footer-logo">
 <div class="em-footer-text">
  <span><b>ETEL MADEYA</b> ·  ኢትኤል ማደያ</span>
  <span>ethelmarket.com/etel.Madeya</span>
 </div>
 <div class="em-footer-patent"><i class="fas fa-registered"></i> ኢE PATENT &amp; TRADEMARK OF ETEL DEV</div>
</div>
<div id="mc-toast"></div>

<!-- BULK IMPORT MODAL -->
<div id="bulk-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.8);align-items:center;justify-content:center;">
 <div style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;padding:28px 26px;position:relative;box-shadow:0 24px 64px rgba(0,0,0,.8);">
  <button onclick="closeBulkImport()" style="position:absolute;top:14px;right:16px;background:none;border:none;color:var(--mt);font-size:22px;cursor:pointer;line-height:1;padding:4px 8px;">×</button>
  <h2 id="bulk-modal-title" style="font-size:20px;font-weight:900;color:var(--f);letter-spacing:2px;margin-bottom:4px;"><i class="fas fa-file-upload"></i> BULK IMPORT</h2>
  <p style="font-size:12px;color:var(--mt);margin-bottom:16px;">Upload a CSV or paste plate numbers (one per line). Duplicates and already-served plates are skipped.</p>
  <label for="bulk-csv-file" style="display:block;font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);margin-bottom:6px;">Upload CSV File</label>
  <input type="file" id="bulk-csv-file" accept=".csv,.txt" style="display:block;width:100%;padding:8px 10px;background:var(--cd);border:1px solid var(--bd);border-radius:7px;color:var(--tx);font-size:13px;margin-bottom:14px;">
  <label for="bulk-plates" style="display:block;font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);margin-bottom:6px;">Plate Numbers <span style="font-weight:400">(one per line or paste CSV)</span></label>
  <textarea id="bulk-plates" rows="9" placeholder="7217&#10;3415&#10;9743&#10;..." style="width:100%;padding:10px 12px;background:var(--cd);border:1px solid var(--bd);border-radius:7px;color:var(--tx);font-family:'JetBrains Mono',monospace;font-size:13px;resize:vertical;outline:none;margin-bottom:14px;"></textarea>
  <label style="display:block;font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);margin-bottom:6px;">Vehicle Type (1-5)</label>
  <select id="bulk-vtype-sel" style="width:100%; margin-bottom:16px; padding:10px 12px; background:var(--cd); border:1px solid var(--bd); border-radius:7px; color:var(--tx); font-family:'Barlow Condensed'; font-weight:700; text-transform:uppercase;">
   <option value="minibus">1 - TAXI SERVICE (RED)</option>
   <option value="private" selected>2 - PRIVATE CARS (BLUE)</option>
   <option value="pickup">3 - ORGANIZATIONS (GREEN)</option>
   <option value="government">4 - GOVERNMENT (BLACK)</option>
   <option value="truck">5 - NGOs/MERIJOY (ORANGE)</option>
  </select>
  <button id="bulk-run-btn" onclick="runBulkImport()" style="width:100%;padding:12px;background:linear-gradient(135deg,var(--f),#c47f10);border:none;border-radius:8px;color:#000;font-family:'Barlow Condensed',sans-serif;font-size:15px;font-weight:800;letter-spacing:2px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">
   <i class="fas fa-rocket"></i> IMPORT TO QUEUE
  </button>
  <div id="bulk-log" style="display:none;margin-top:18px;">
   <div id="bulk-summary" style="padding:10px 14px;border-radius:7px;font-size:13px;font-weight:700;margin-bottom:10px;"></div>
   <div id="bulk-results" style="max-height:220px;overflow-y:auto;font-family:'JetBrains Mono',monospace;font-size:11px;line-height:2;scrollbar-width:thin;scrollbar-color:var(--bd) transparent;"></div>
  </div>
 </div>
</div>

<!-- DUPLICATES IMPORT MODAL -->
<div id="duplicates-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.8);align-items:center;justify-content:center;">
 <div style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;padding:28px 26px;position:relative;box-shadow:0 24px 64px rgba(0,0,0,.8);">
  <button onclick="closeDuplicatesImport()" style="position:absolute;top:14px;right:16px;background:none;border:none;color:var(--mt);font-size:22px;cursor:pointer;line-height:1;padding:4px 8px;">×</button>
  <h2 style="font-size:20px;font-weight:900;color:#C0392B;letter-spacing:2px;margin-bottom:4px;"><i class="fas fa-exclamation-triangle"></i> IMPORT DUPLICATES</h2>
  <p style="font-size:12px;color:var(--mt);margin-bottom:16px;">Upload a TXT file containing duplicate plate numbers (one per line). These plates will be marked with red color and warning icon in the active queue.</p>
  <label for="duplicates-file" style="display:block;font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);margin-bottom:6px;">Upload TXT File</label>
  <input type="file" id="duplicates-file" accept=".txt" onchange="handleDuplicatesFile(this)" style="display:block;width:100%;padding:8px 10px;background:var(--cd);border:1px solid var(--bd);border-radius:7px;color:var(--tx);font-size:13px;margin-bottom:14px;">
  <label for="duplicates-textarea" style="display:block;font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--mt);margin-bottom:6px;">Or Paste Plate Numbers <span style="font-weight:400">(one per line)</span></label>
  <textarea id="duplicates-textarea" rows="9" placeholder="7217&#10;3415&#10;9743&#10;..." style="width:100%;padding:10px 12px;background:var(--cd);border:1px solid var(--bd);border-radius:7px;color:var(--tx);font-family:'JetBrains Mono',monospace;font-size:13px;resize:vertical;outline:none;margin-bottom:14px;"></textarea>
  <div id="duplicates-preview" style="display:none;padding:10px 14px;background:rgba(192,57,43,.08);border:1px solid rgba(192,57,43,.25);border-radius:7px;font-size:12px;color:var(--mt);margin-bottom:14px;"></div>
  <button id="duplicates-import-btn" onclick="importDuplicates()" style="width:100%;padding:12px;background:linear-gradient(135deg,#C0392B,#7B241C);border:none;border-radius:8px;color:#fff;font-family:'Barlow Condensed',sans-serif;font-size:15px;font-weight:800;letter-spacing:2px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">
   <i class="fas fa-exclamation-triangle"></i> MARK AS DUPLICATES
  </button>
  <button onclick="removeServedFromQueue()" style="width:100%;margin-top:8px;padding:10px;background:linear-gradient(135deg,#E67E22,#D35400);border:none;border-radius:6px;color:#fff;font-family:'Barlow Condensed',sans-serif;font-size:13px;font-weight:700;letter-spacing:1.5px;cursor:pointer;">
   <i class="fas fa-trash-alt"></i> REMOVE SERVED FROM QUEUE
  </button>
  <button onclick="testDuplicates()" style="width:100%;margin-top:8px;padding:8px;background:rgba(41,128,185,.1);border:1px solid rgba(41,128,185,.3);border-radius:6px;color:var(--bl);font-family:'Barlow Condensed',sans-serif;font-size:12px;font-weight:700;letter-spacing:1.5px;cursor:pointer;">
   <i class="fas fa-bug"></i> TEST (Load Sample Duplicates)
  </button>
  <div id="duplicates-feedback" style="display:none;margin-top:18px;padding:10px 14px;border-radius:7px;font-size:13px;font-weight:700;"></div>
 </div>
</div>

<script charset="utf-8">
(function($){
 'use strict';
 var AJ=MADEYA.ajaxurl, NO=MADEYA.nonce;
 var selType=null, servedCount=0, queueCount=0;
 var currentPage=1, perPage=50, searchQuery='', totalPages=1, totalRecords=0;
 var searchTimeout=null; // For debouncing real-time search
 var duplicatePlates=[]; // Store duplicate plate numbers
 var currentCtrlId=0; // Current controller ID for scoping data

 function updateStats(){
  $('#s-q').text(queueCount);
  $('#s-sv').text(servedCount);
  $('#s-tot').text(queueCount+servedCount);
 }
 var selTier=null; // currently selected priority tier
 var _tierDefs=[
  {tier:1,label:'Oil Transport',amh:'ነዳጅ ማጓጓዣ',color:'#e74c3c',icon:'fa-truck',vehicles:['oil_transport']},
  {tier:2,label:'Industry/Export',amh:'ኢንዱስትሪ',color:'#e67e22',icon:'fa-industry',vehicles:['industry_export']},
  {tier:3,label:'Gov. Construction',amh:'የመንግስት ፕሮጀክት',color:'#f39c12',icon:'fa-hard-hat',vehicles:['construction','govt_moto']},
  {tier:4,label:'Cargo & Goods',amh:'ጭነት',color:'#27ae60',icon:'fa-boxes',vehicles:['cargo']},
  {tier:5,label:'Agriculture',amh:'ግብርና',color:'#16a085',icon:'fa-tractor',vehicles:['agriculture']},
  {tier:6,label:'Public Transport',amh:'የህዝብ ትራንስፖርት',color:'#2980b9',icon:'fa-bus',vehicles:['minibus','bajaj','damas','public_bus']},
  {tier:7,label:'Other Diesel',amh:'ሌሎች',color:'#8e44ad',icon:'fa-car',vehicles:['private','motorcycle','Qute','diesel_car']},
 ];
 var CFG={allowed_parity:'both',allowed_types:[],type_labels:{}};
 // -- Vehicle Type Definitions (Hawassa City) ------------------
 var _vtypeDefs = [
  {key:'bajaj',       num:1, label:'Bajaj',              amh:'<?php echo "\u1263\u1303\u1303" ?>',       color:'#e74c3c', icon:'fa-taxi'},
  {key:'qute',        num:2, label:'Qute',               amh:'<?php echo "\u12aa\u12ed\u12ed\u1275" ?>', color:'#e67e22', icon:'fa-car'},
  {key:'l_moto',      num:3, label:'L Motorcycle',       amh:'<?php echo "L \u1230\u12c8\u1228\u12cd" ?>',color:'#f39c12',icon:'fa-motorcycle'},
  {key:'private_moto',num:4, label:'Private Motorcycle', amh:'<?php echo "\u12e8\u130d\u120d \u1230\u12c8\u1228\u12cd" ?>',color:'#27ae60',icon:'fa-motorcycle'},
  {key:'govt_moto',   num:5, label:'Govt Motorcycle',    amh:'<?php echo "\u12e8\u1218\u1295\u130d\u1235\u1275 \u1230\u12c8\u1228\u12cd" ?>',color:'#16a085',icon:'fa-motorcycle'},
  {key:'private_car', num:6, label:'Private Car',        amh:'<?php echo "\u12e8\u1264\u1275 \u1218\u12aa\u1293" ?>',color:'#2980b9',icon:'fa-car-side'},
  {key:'minibus',     num:7, label:'Minibus Taxi',       amh:'<?php echo "\u121a\u1295\u1263\u1235 \u1273\u12ad\u1235" ?>',color:'#8e44ad',icon:'fa-bus'},
  {key:'damas',       num:8, label:'Damas',              amh:'<?php echo "\u12f3\u121b\u1235" ?>',       color:'#c0392b', icon:'fa-shuttle-van'},
 ];

 function fillVtypeGrid(autoKey) {
  var grid = $('#vtype-grid').empty();
  var selKey = autoKey || $('#mc-vtype').val() || 'bajaj';
  _vtypeDefs.forEach(function(v) {
   var isSel = (v.key === selKey);
   var btn = $('<div class="tier-btn'+(isSel?' sel':'')+'" data-vkey="'+v.key+'">'+
    '<div class="tier-num-badge" style="background:'+v.color+';">'+v.num+'</div>'+
    '<div class="tier-btn-text">'+
     '<div class="tier-btn-label"><i class="fas '+v.icon+'" style="color:'+v.color+';margin-right:4px;font-size:9px;"></i>'+v.label+'</div>'+
     '<div class="tier-btn-amh">'+v.amh+'</div>'+
    '</div></div>');
   if(isSel) btn.css({'border-color':v.color,'background':'rgba('+hexToRgb(v.color)+',.08)'});
   btn.on('click',function(){
    var vk = v.key;
    $('#mc-vtype').val(vk);
    $('.tier-btn[data-vkey]').removeClass('sel').css({'border-color':'','background':''});
    $(this).addClass('sel').css({'border-color':v.color,'background':'rgba('+hexToRgb(v.color)+',.1)'});
    $('#vtype-hint').html('<i class="fas '+v.icon+'" style="color:'+v.color+'"></i> <b style="color:'+v.color+'">'+v.amh+' � '+v.label+'</b>');
    // Auto-set priority tier based on vehicle type
    var autoTier = <?php echo json_encode(array('bajaj'=>6,'qute'=>6,'l_moto'=>7,'private_moto'=>7,'govt_moto'=>3,'private_car'=>7,'minibus'=>6,'damas'=>6)); ?>;
    if(autoTier[vk]) buildPriorityGrid(autoTier[vk]);
    // Update parity hint
    updateParityHint($('#reg-plate').val());
   });
   grid.append(btn);
  });
  // Set hint for current selection
  var cur = _vtypeDefs.find(function(v){return v.key===selKey;});
  if(cur) $('#vtype-hint').html('<i class="fas '+cur.icon+'" style="color:'+cur.color+'"></i> <b style="color:'+cur.color+'">'+cur.amh+' � '+cur.label+'</b>');
 }


 function tick(){
  var n=new Date(),dy=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'],mo=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  $('#hdr-date').text(dy[n.getDay()]+' '+mo[n.getMonth()]+' '+n.getDate());
  $('#hdr-time').text(n.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit',second:'2-digit'}));
 }
 tick(); setInterval(tick,1000);
 $('#rpt-date').val(new Date().toISOString().split('T')[0]);

 function toast(m,t,dur){
  t=t||'ok'; dur=dur||3500;
  var ic={ok:'fa-check-circle',warn:'fa-exclamation-triangle',err:'fa-times-circle'};
  var el=$('<div class="toast-item '+t+'"><i class="fas '+ic[t]+'"></i><span>'+m+'</span></div>');
  $('#mc-toast').append(el);
  setTimeout(function(){el.fadeOut(300,function(){$(this).remove();});},dur);
 }

 $.post(AJ,{action:'madeya_check_session',nonce:NO},function(r){
  if(r.success&&r.data.logged_in){applyCFG(r.data);showApp(r.data.name,r.data.station,r.data);}
  else{$('#mc-ls').show();}
 }).fail(function(){ $('#mc-ls').show(); });

 function applyCFG(data){
  CFG.allowed_parity=data.allowed_parity||'both';
  CFG.allowed_types=data.allowed_types||[];
  CFG.type_labels=data.type_labels||{};
  buildVtypeGrid(); updateConfigBar();
  buildPriorityGrid(7); // default tier 7 until vehicle type chosen
 }


 function buildPriorityGrid(autoTier) {
  var grid=$('#priority-tiers-grid').empty();
  selTier=autoTier||null;
  _tierDefs.forEach(function(t){
   var isSel=(t.tier===selTier);
   var btn=$('<div class="tier-btn'+(isSel?' sel':'')+'" data-tier="'+t.tier+'">'+
    '<div class="tier-num-badge" style="background:'+t.color+';">'+t.tier+'</div>'+
    '<div class="tier-btn-text">'+
     '<div class="tier-btn-label"><i class="fas '+t.icon+'" style="color:'+t.color+';margin-right:4px;font-size:9px;"></i>'+t.label+'</div>'+
     '<div class="tier-btn-amh">'+t.amh+'</div>'+
    '</div></div>');
   if(isSel) btn.css({'border-color':t.color,'background':'rgba('+hexToRgb(t.color)+',.08)'});
   btn.on('click',function(){
    selTier=t.tier;
    $('.tier-btn').removeClass('sel').css({'border-color':'','background':''});
    $(this).addClass('sel').css({'border-color':t.color,'background':'rgba('+hexToRgb(t.color)+',.1)'});
    $('#priority-hint').html('<i class="fas fa-info-circle" style="color:'+t.color+'"></i> <b style="color:'+t.color+'">Tier '+t.tier+'</b> -- '+t.label+' ('+t.amh+')');
   });
   grid.append(btn);
  });
  if(autoTier){
   var td=_tierDefs.find(function(d){return d.tier===autoTier;});
   if(td) $('#priority-hint').html('<i class="fas fa-info-circle" style="color:'+td.color+'"></i> Auto-detected: <b style="color:'+td.color+'">Tier '+td.tier+'</b> -- '+td.label);
  }
 }

 function hexToRgb(hex){
  hex=hex.replace('#','');
  var r=parseInt(hex.substr(0,2),16),g=parseInt(hex.substr(2,2),16),b=parseInt(hex.substr(4,2),16);
  return r+','+g+','+b;
 }

 function getAutoTier(vtype){
  var t=_tierDefs.find(function(d){return d.vehicles.indexOf(vtype)>=0;});
  return t?t.tier:7;
 }

 function buildVtypeGrid(){
  var allTypes=[
   {k:'bajaj',i:'fa-motorcycle',l:'Bajaj'},{k:'minibus',i:'fa-shuttle-van',l:'Minibus Taxi'},
   {k:'motorcycle',i:'fa-motorcycle',l:'Motorcycle'},{k:'private',i:'fa-car',l:'Private Car'},
   {k:'damas',i:'fa-car-side',l:'Damas'},{k:'govt_moto',i:'fa-shield-alt',l:'Govt. Moto'},
   {k:'diesel_car',i:'fa-gas-pump',l:'Diesel Car'} // <-- Added Diesel Car
  ];
  var grid=$('#vtypes-grid').empty();
  allTypes.forEach(function(t){
   var label=CFG.type_labels[t.k]||t.l;
   var disabled=CFG.allowed_types.indexOf(t.k)===-1;
   grid.append('<div class="vt-btn'+(disabled?' disabled-type':'')+'" data-type="'+t.k+'"><i class="fas '+t.i+'"></i>'+label+'</div>');
  });
 }

 function updateConfigBar(){
  var pmap={even:'ሙሉ (Even) Only',odd:'ጎዶሎ (Odd) Only',both:'Both ሙሉ & ጎዶሎ'};
  var pcls=CFG.allowed_parity;
  $('#cfg-parity-lbl').html('<span class="cfg-par-badge '+pcls+'">'+(pmap[CFG.allowed_parity]||CFG.allowed_parity)+'</span>');
  var typeNames=CFG.allowed_types.map(function(k){return CFG.type_labels[k]||k;});
  $('#cfg-types-lbl').text(typeNames.length?typeNames.join(', '):'None configured');
 }

 $('#lc-pass').on('keypress',function(e){if(e.key==='Enter')doLogin();});
 $('#lc-user').on('keypress',function(e){if(e.key==='Enter')$('#lc-pass').focus();});

 window.doLogin=function(){
  var u=$('#lc-user').val().trim(), p=$('#lc-pass').val();
  if(!u||!p){showLErr('Enter username and password.');return;}
  var btn=$('#lc-btn').prop('disabled',true).html('<i class="fas fa-circle-notch fa-spin"></i> SIGNING IN...');
  $('#lc-err').removeClass('show');
  $.post(AJ,{action:'madeya_login',nonce:NO,username:u,password:p},function(r){
   if(r.success){applyCFG(r.data);showApp(r.data.name,r.data.station,r.data);}
   else{showLErr(r.data&&r.data.message?r.data.message:'Login failed.');btn.prop('disabled',false).html('<i class="fas fa-sign-in-alt"></i> SIGN IN');}
  }).fail(function(){showLErr('Network error.');btn.prop('disabled',false).html('<i class="fas fa-sign-in-alt"></i> SIGN IN');});
 };

 function showLErr(m){var el=$('#lc-err')[0];$('#lc-err-msg').text(m);el.classList.remove('show');void el.offsetWidth;el.classList.add('show');}

 function showApp(name,station,data){
  $('#mc-ls').hide();$('#mc-app').show();
  $('#hdr-ctrl').text(name||'Controller');$('#hdr-station').text(station||'Madeya Gas Station');
  if(data&&data.requires_pw_change){$('#mc-pw-warning').addClass('show');}else{$('#mc-pw-warning').removeClass('show');}
  // Store controller ID for scoping duplicates
  currentCtrlId=data&&data.ctrl_id?data.ctrl_id:0;
  // Load duplicates scoped to this controller + today
  loadScopedDuplicates();
  // Load private liters quota from dedicated endpoint
  $.post(AJ,{action:'madeya_get_my_quota'},function(qr){
   if(qr.success){
    ctrlQuotaAlloc=qr.data.liters_allocated||0;
    ctrlQuotaRem=qr.data.liters_remaining;
    ctrlQuotaUnlimited=qr.data.unlimited;
   } else if(data) {
    ctrlQuotaAlloc=(parseFloat(data.fuel_allocated)||0);
    ctrlQuotaRem=(data.fuel_remaining!==undefined?parseFloat(data.fuel_remaining):ctrlQuotaAlloc);
   }
  });
  loadQueue();loadWaiting();loadVipQueue();loadReport();loadFuel();fillVtypeGrid();
 }

 window.doLogout=function(){
  if(!confirm('Logout?'))return;
  $.post(AJ,{action:'madeya_logout'},function(){
   $('#mc-app').hide();$('#mc-ls').show();$('#lc-user,#lc-pass').val('');
   $('#lc-btn').prop('disabled',false).html('<i class="fas fa-sign-in-alt"></i> SIGN IN');
   selType=null;
  });
 };

 /* ── Ethiopian plate auto-format: AA-NNNNN ── */
 function mcFormatPlate(val) {
  var v = val.toUpperCase().replace(/[^A-Z0-9]/g, '');
  if (v.length > 2) { v = v.slice(0, 2) + '-' + v.slice(2, 7); }
  return v;
 }

 /* ── QR code for controller page ── */
 function mcShowQR(token, plate) {
  if (!token) { $('#mc-qr-wrap').removeClass('show'); return; }
  var data = 'MADEYA:' + token + ':' + plate;
  var url  = 'https://chart.googleapis.com/chart?cht=qr&chs=160x160&chl=' + encodeURIComponent(data) + '&choe=UTF-8&chld=M|2';
  $('#mc-qr-img').html('<img src="'+url+'" width="150" height="150" alt="QR" style="display:block;border-radius:6px;">');
  $('#mc-qr-wrap').addClass('show');
 }

 /* ── Loading skeleton for queue table ── */
 function mcQueueSkeleton() {
  var sk = '';
  for (var i = 0; i < 4; i++) {
   sk += '<tr class="qr"><td colspan="7"><div class="q-skel" style="width:'+(60+Math.random()*30)+'%"></div></td></tr>';
  }
  return sk;
 }

 $('#reg-plate').on('input', function() {
  var sel = this.selectionStart;
  var raw = this.value;
  var fmt = mcFormatPlate(raw);
  this.value = fmt;
  if (fmt.length === 3 && raw.length === 2) { this.setSelectionRange(3, 3); }
  else { this.setSelectionRange(Math.min(sel, fmt.length), Math.min(sel, fmt.length)); }
  var plate=fmt.replace(/-/g,''), digits=plate.match(/\d/g);
  if(digits&&digits.length){
   var last=parseInt(digits[digits.length-1]);
   var isEven=last%2===0;
   $('#par-hint').html('Last digit: <b style="color:'+(isEven?'var(--bl)':'var(--or)')+'">'+last+' -> '+(isEven?'ሙሉ (Even)':'ጎዶሎ (Odd)')+'</b>');
  } else { $('#par-hint').html('&nbsp;'); }
 }).on('keypress',function(e){if(e.key==='Enter')doRegister();});
$('#mc-vtype').on('change', function(){
  selType = $(this).val();
  buildPriorityGrid(getAutoTier(selType));
 });

 var fuelStock=0, fuelAvail=0, ctrlQuotaRem=null, ctrlQuotaAlloc=0, ctrlQuotaUnlimited=false;
 function loadFuel(){
  $.post(AJ,{action:'madeya_get_fuel'},function(r){
   if(!r.success)return;
   var d=r.data;

   if(d.has_quota){
    // Controller has a personal quota assigned -- use it
    ctrlQuotaAlloc = d.ctrl_quota;
    ctrlQuotaRem   = d.ctrl_remaining;
    fuelAvail      = d.ctrl_remaining;
    fuelStock      = d.ctrl_quota;
    ctrlQuotaUnlimited = false;
    var pct = ctrlQuotaAlloc>0 ? Math.min(100,Math.round((ctrlQuotaRem/ctrlQuotaAlloc)*100)) : 0;
    var col = pct>40?'#27AE60':pct>15?'#E67E22':'#e74c3c';
    $('#s-fuel-rem').text(ctrlQuotaRem.toFixed(1)).css('color',col);
    $('#s-fuel-bar').css({'width':pct+'%','background':'linear-gradient(90deg,'+col+','+col+')'});
    $('#s-fuel-pct').text(pct+'%');
    $('#fuel-avail-hint').text('(Your quota: '+ctrlQuotaRem.toFixed(1)+' / '+ctrlQuotaAlloc.toFixed(0)+' L)');
   } else {
    // No personal quota -- show global station stock or unlimited
    fuelStock  = d.stock||0;
    fuelAvail  = d.remaining!==undefined ? d.remaining : fuelStock;
    ctrlQuotaUnlimited = true;
    ctrlQuotaAlloc = 0;
    ctrlQuotaRem = null;
    var pct = d.pct||0;
    var col = pct>40?'#27AE60':pct>15?'#E67E22':'#27AE60';
    if(fuelStock===0){
     // No global stock configured -- truly unlimited
     $('#s-fuel-rem').text('Inf').css('color','#27AE60');
     $('#s-fuel-bar').css({'width':'100%','background':'linear-gradient(90deg,#27AE60,#27AE60)'});
     $('#s-fuel-pct').text('Inf');
     $('#fuel-avail-hint').text('(Unlimited -- no quota set)');
    } else {
     $('#s-fuel-rem').text(fuelAvail.toFixed(1)).css('color',col);
     $('#s-fuel-bar').css({'width':Math.min(pct,100)+'%','background':'linear-gradient(90deg,'+col+','+col+')'});
     $('#s-fuel-pct').text(pct+'%');
     $('#fuel-avail-hint').text('(Station: '+fuelAvail.toFixed(1)+' L available)');
    }
   }
  });
 }

 window.checkFuelInput=function(val){
  var v=parseFloat(val)||0;
  var warn=$('#fuel-warn');
  if(v>0&&fuelAvail>=0&&v>fuelAvail){warn.text('[!] Station only has '+fuelAvail.toFixed(1)+' L available.').show();$('#mc-reg-btn').prop('disabled',true);return;}
  if(v>0&&ctrlQuotaAlloc>0&&ctrlQuotaRem!==null&&v>ctrlQuotaRem){warn.text('[!] Your quota only has '+ctrlQuotaRem.toFixed(1)+' L remaining.').show();$('#mc-reg-btn').prop('disabled',true);return;}
  warn.hide();$('#mc-reg-btn').prop('disabled',false);
 };

 window.doRegister=function(){
  var plate=$('#reg-plate').val().trim().toUpperCase();
  var gallons=parseFloat($('#reg-liters').val())||1; // value is liters; default to 1 if field removed
  selType=$('#mc-vtype').val(); // Add this line to capture from dropdown!
  hideFb();
  if(!plate){showFb('err','fa-times-circle','Enter a plate number.');return;}
  if(!selType){showFb('warn','fa-exclamation-triangle','Select a vehicle type.');return;}
  if(!selTier){selTier=getAutoTier(selType)||7;}
  // Only block client-side if we have a known quota limit (not unlimited)
  if(!ctrlQuotaUnlimited && ctrlQuotaAlloc>0 && gallons>fuelAvail){
   showFb('err','fa-ban','Your quota only has '+fuelAvail.toFixed(1)+' L remaining.');return;
  }
  var btn=$('#mc-reg-btn').prop('disabled',true).html('<i class="fas fa-circle-notch fa-spin"></i> REGISTERING...');
  var tierToSend=selTier||getAutoTier(selType)||7;
  $.post(AJ,{action:'madeya_register',nonce:NO,plate:plate,vehicle_type:selType,gallons:gallons,liters:gallons,priority_tier:tierToSend},function(r){
   btn.prop('disabled',false).html('<i class="fas fa-car"></i> REGISTER & ASSIGN TOKEN');
   if(r.success){
    var d=r.data;
    var tierInfo=_tierDefs.find(function(t){return t.tier===(d.priority_tier||tierToSend);});
    var tierTag=tierInfo?(' | <span style="color:'+tierInfo.color+';font-size:10px;font-weight:700;">T'+tierInfo.tier+'</span>'):'';
    showFb('ok','fa-check-circle','<b>'+d.plate+'</b> -- '+(d.type_label||d.vehicle_type)+tierTag+' | '+d.parity_label+' | <i class="fas fa-gas-pump"></i> '+d.gallons+' L','#'+d.token+' — Position #'+d.position+' | Fuel left: '+d.remaining_fuel+' L');
    mcShowQR(d.token, d.plate);
    $('#reg-plate').val('').trigger('input');$('#reg-liters').val('');$('#fuel-warn').hide();
    $('.vt-btn').removeClass('sel');selType=null;
    if(d.ctrl_quota_rem!==null&&d.ctrl_quota_rem!==undefined)ctrlQuotaRem=parseFloat(d.ctrl_quota_rem);
    toast(d.plate+' → Token #'+d.token+' | '+d.gallons+' L','ok');
    loadQueue();loadReport();loadFuel();
   } else {
    var code=r.data&&r.data.code?r.data.code:'';
    var msg=r.data&&r.data.message?r.data.message:'Error';
    if(code==='IN_QUEUE')showFb('warn','fa-exclamation-triangle',msg);
    else if(code==='LOW_FUEL'||code==='QUOTA_LOW'){showFb('err','fa-ban',msg);loadFuel();}
    else if(code==='SERVED_TODAY'||code==='WRONG_PARITY'){showFb('err','fa-ban',msg);toast(msg,'err',5000);}
    else showFb('err','fa-times-circle',msg);
   }
  }).fail(function(){btn.prop('disabled',false).html('<i class="fas fa-car"></i> REGISTER & ASSIGN TOKEN');toast('Network error','err');});
 };

 window.doServe=function(id,plate){
  if(!confirm('Mark '+plate+' as SERVED and remove from queue?'))return;
  $.post(AJ,{action:'madeya_serve',nonce:NO,id:id},function(r){
   if(r.success){
    var msg=r.data.plate+' ✓ Served | Token #'+r.data.token;
    if(r.data.gallons_served>0)msg+=' | -'+r.data.gallons_served+' L | Rem: '+r.data.remaining_fuel+' L';
    toast(msg,'ok');loadQueue();loadWaiting();loadVipQueue();loadReport();loadFuel();fillVtypeGrid();
   } else toast(r.data&&r.data.message?r.data.message:'Error','err');
  });
 };

 window.waitCar=function(id,plate){
  if(!confirm('Move '+plate+' to the Waiting Queue?'))return;
  $.post(AJ,{action:'madeya_wait_car',nonce:NO,id:id},function(r){
   if(r.success){toast(r.data.plate+' -> Moved to Waiting Queue','warn');loadQueue();loadWaiting();}
   else toast(r.data&&r.data.message?r.data.message:'Error','err');
  }).fail(function(){toast('Network error','err');});
 };

 function loadQueue(){
  // Show skeleton while loading
  $('#mc-qtbody').html(mcQueueSkeleton());
  
  // If searching, load all pages to find matches
  var pageToLoad = searchQuery ? 1 : currentPage;
  var itemsPerPage = searchQuery ? 9999 : perPage; // Load all if searching
  
  $.post(AJ,{action:'madeya_get_queue',page:pageToLoad,per_page:itemsPerPage},function(r){
   if(!r.success)return;
   var q=r.data.queue, tb=$('#mc-qtbody');
   var pagination=r.data.pagination||{};
   totalPages=pagination.total_pages||1;
   totalRecords=pagination.total_records||q.length;
   queueCount=totalRecords; updateStats();
   var odd=0,even=0;
   q.forEach(function(c){if(c.plate_parity==='odd')odd++;else if(c.plate_parity==='even')even++;});
   $('#s-oe').text(odd+'/'+even);
   if(!q.length){tb.html('<tr><td colspan="8"><div class="eq"><i class="fas fa-gas-pump"></i><p>Queue is empty</p></div></td></tr>');$('#mc-pagination').hide();return;}
   q.sort(function(a,b){return parseInt(a.position)-parseInt(b.position);});
   
   // If searching, move matching vehicles to top
   var searchLower=searchQuery.toLowerCase();
   if(searchQuery){
    var matches=[];
    var nonMatches=[];
    q.forEach(function(c){
     if(c.plate.toLowerCase().indexOf(searchLower)!==-1){
      matches.push(c);
     }else{
      nonMatches.push(c);
     }
    });
    q=matches.concat(nonMatches); // Matches first, then rest
    
    // If searching, only show first 50 results (matches + some non-matches)
    q = q.slice(0, 50);
    
    // Hide pagination when searching
    $('#mc-pagination').hide();
   }
   
   tb.empty();
   var duplicatesInQueue=0;
   q.forEach(function(c,i){
    var isF=(i===0 && currentPage===1);
    var isSearchMatch=searchQuery && c.plate.toLowerCase().indexOf(searchLower)!==-1;
    var isDuplicate=duplicatePlates.indexOf(c.plate.toUpperCase())!==-1;
    if(isDuplicate)duplicatesInQueue++;
    var ts=new Date(c.registered_at.replace(' ','T')).toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'});
    var tlbl=CFG.type_labels[c.vehicle_type]||c.vehicle_type||'--';
    var par=c.plate_parity;
    var plbl=par==='even'?'ሙሉ':par==='odd'?'ጎዶሎ':'--';
    var tierDef=_tierDefs.find(function(t){return t.tier===(parseInt(c.priority_tier)||7);});
    var tierBadge=tierDef?('<span style="display:inline-block;width:18px;height:18px;border-radius:50%;background:'+tierDef.color+';color:#fff;font-size:9px;font-weight:900;text-align:center;line-height:18px;margin-right:3px;">'+tierDef.tier+'</span>'):'';
    
    // Determine row styling - duplicates override search matches
    var rowStyle='';
    var plateStyle='';
    var positionExtra='';
    
    if(isDuplicate){
     rowStyle=' style="background:rgba(192,57,43,0.15);border-left:3px solid #C0392B;"';
     plateStyle=' style="color:#C0392B;font-weight:900;"';
     positionExtra=' <i class="fas fa-exclamation-triangle" style="color:#C0392B;font-size:11px;"></i>';
    }else if(isSearchMatch){
     rowStyle=' style="background:rgba(39,174,96,0.15);border-left:3px solid #27AE60;"';
     plateStyle=' style="color:#27AE60;font-weight:900;text-shadow:0 0 10px rgba(39,174,96,0.5);"';
     positionExtra=' <span style="font-size:10px;color:#27AE60;">✓</span>';
    }
    
    var actualPosition=c.position||'--';
    tb.append('<tr class="qr'+(isF?' first-row':'')+'"'+rowStyle+'>'+
     '<td class="q-pos'+(isF?' top':'')+'">'+actualPosition+positionExtra+'</td>'+
     '<td class="q-pl"'+plateStyle+'>'+tierBadge+c.plate+(isDuplicate?' <i class="fas fa-exclamation-triangle" style="color:#C0392B;font-size:10px;margin-left:4px;"></i>':'')+'</td>'+
     '<td class="q-tp">'+tlbl+'</td>'+
     '<td><span class="par-badge '+par+'">'+plbl+'</span></td>'+
     '<td>'+(c.gallons>0?'<span style="color:#27AE60;font-weight:700;font-family:JetBrains Mono,monospace;font-size:12px">'+(parseFloat(c.liters||c.gallons)||0).toFixed(1)+' L</span>':'-')+'</td>'+
     '<td class="q-tk">#'+c.token+'</td>'+
     '<td class="q-tm">'+ts+'</td>'+
     '<td><button type="button" class="srv-btn" onclick="doServe('+c.id+',\''+c.plate+'\')"><i class="fas fa-check"></i> SERVED</button> <button type="button" class="wait-btn" onclick="waitCar('+c.id+',\''+c.plate+'\')"><i class="fas fa-clock"></i> WAIT</button></td>'+
    '</tr>');
   });
   
   // Update duplicates count indicator
   if(duplicatePlates.length>0){
    $('#duplicates-count-text').text(duplicatesInQueue+' duplicate plate'+(duplicatesInQueue!==1?'s':'')+' in current page ('+duplicatePlates.length+' total tracked)');
    $('#duplicates-count-indicator').show();
   }else{
    $('#duplicates-count-indicator').hide();
   }
   updatePagination(pagination);
  });
 }

 function updatePagination(p){
  if(!p || p.total_pages<=1){$('#mc-pagination').hide();return;}
  $('#mc-pagination').show();
  var start=((p.current_page-1)*p.per_page)+1;
  var end=Math.min(p.current_page*p.per_page,p.total_records);
  $('#mc-page-info').text('Showing '+start+'-'+end+' of '+p.total_records+' vehicles');
  var btns='';
  if(p.has_prev){
   btns+='<button onclick="goToPage(1)" style="padding:6px 12px;background:var(--cd);border:1px solid var(--bd);border-radius:6px;color:var(--f);font-family:Barlow Condensed,sans-serif;font-size:12px;font-weight:700;cursor:pointer;">⏮️ First</button>';
   btns+='<button onclick="goToPage('+(p.current_page-1)+')" style="padding:6px 12px;background:var(--cd);border:1px solid var(--bd);border-radius:6px;color:var(--f);font-family:Barlow Condensed,sans-serif;font-size:12px;font-weight:700;cursor:pointer;">◀️ Prev</button>';
  }
  var startPage=Math.max(1,p.current_page-2);
  var endPage=Math.min(p.total_pages,p.current_page+2);
  for(var i=startPage;i<=endPage;i++){
   if(i===p.current_page){
    btns+='<button style="padding:6px 12px;background:linear-gradient(135deg,var(--f),#c47f10);border:none;border-radius:6px;color:#000;font-family:Barlow Condensed,sans-serif;font-size:12px;font-weight:700;">'+i+'</button>';
   }else{
    btns+='<button onclick="goToPage('+i+')" style="padding:6px 12px;background:var(--cd);border:1px solid var(--bd);border-radius:6px;color:var(--f);font-family:Barlow Condensed,sans-serif;font-size:12px;font-weight:700;cursor:pointer;">'+i+'</button>';
   }
  }
  if(p.has_next){
   btns+='<button onclick="goToPage('+(p.current_page+1)+')" style="padding:6px 12px;background:var(--cd);border:1px solid var(--bd);border-radius:6px;color:var(--f);font-family:Barlow Condensed,sans-serif;font-size:12px;font-weight:700;cursor:pointer;">Next ▶️</button>';
   btns+='<button onclick="goToPage('+p.total_pages+')" style="padding:6px 12px;background:var(--cd);border:1px solid var(--bd);border-radius:6px;color:var(--f);font-family:Barlow Condensed,sans-serif;font-size:12px;font-weight:700;cursor:pointer;">Last ⏭️</button>';
  }
  $('#mc-page-btns').html(btns);
 }

 window.goToPage=function(page){
  currentPage=page;
  loadQueue();
 };

 window.searchQueueRealtime=function(){
  // Clear previous timeout
  if(searchTimeout) clearTimeout(searchTimeout);
  
  // Get current search value
  var inputVal=$('#mc-search-input').val().trim();
  
  // Debounce: wait 300ms after user stops typing
  searchTimeout=setTimeout(function(){
   searchQuery=inputVal;
   currentPage=1;
   loadQueue();
  },300);
 };

 window.searchQueue=function(){
  searchQuery=$('#mc-search-input').val().trim();
  currentPage=1; // Reset to first page to show matches at top
  loadQueue();
 };

 window.clearSearch=function(){
  $('#mc-search-input').val('');
  searchQuery='';
  currentPage=1;
  loadQueue();
 };

 function loadWaiting(){
  $.post(AJ,{action:'madeya_get_waiting',nonce:NO},function(r){
   if(!r.success)return;
   var q=r.data.queue, tb=$('#mc-wtbody'), section=$('#mc-waiting-section');
   if(!q.length){section.removeClass('has-waiting');tb.html('<tr><td colspan="7"><div class="eq" style="padding:24px 20px"><i class="fas fa-clock"></i><p style="font-size:13px">No vehicles waiting</p></div></td></tr>');return;}
   section.addClass('has-waiting');$('#wq-count').text('('+q.length+' waiting)');tb.empty();
   q.forEach(function(c,i){
    var ts=new Date(c.registered_at.replace(' ','T')).toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'});
    var tlbl=CFG.type_labels[c.vehicle_type]||c.vehicle_type||'--';
    var par=c.plate_parity;
    tb.append('<tr class="qr waiting-row"><td class="q-pos">'+(i+1)+'</td><td class="q-pl">'+c.plate+'</td><td class="q-tp">'+tlbl+'</td><td><span class="par-badge '+par+'">'+(par==='even'?'ሙሉ':'ጎዶሎ')+'</span></td><td class="q-tk">#'+c.token+'</td><td class="q-tm">'+ts+'</td><td><button type="button" class="srv-btn" onclick="doServe('+c.id+',\''+c.plate+'\')"><i class="fas fa-check"></i> SERVED</button></td></tr>');
   });
  });
 }

 function loadVipQueue(){
  $.post(AJ,{action:'madeya_get_vip_queue',nonce:NO},function(r){
   if(!r.success)return;
   var q=r.data.queue, tb=$('#mc-vtbody');
   $('#vip-count').text(q.length?'('+q.length+' VIP)':'');
   if(!q.length){tb.html('<tr><td colspan="7"><div class="eq" style="padding:20px"><i class="fas fa-star" style="color:#e74c3c"></i><p style="font-size:13px">No VIP vehicles</p></div></td></tr>');return;}
   tb.empty();
   q.forEach(function(c){
    var ts=new Date(c.registered_at.replace(' ','T')).toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'});
    var tlbl=CFG.type_labels[c.vehicle_type]||c.vehicle_type||'--';
    var par=c.plate_parity;
    tb.append('<tr class="qr vip-row"><td class="q-pos"><i class="fas fa-star" style="color:#e74c3c;font-size:9px;margin-right:3px"></i>'+c.position+'</td><td class="q-pl">'+c.plate+'<span class="vip-badge" style="font-size:8px;padding:1px 5px;margin-left:4px">VIP</span></td><td class="q-tp">'+tlbl+'</td><td><span class="par-badge '+par+'">'+(par==='even'?'ሙሉ':'ጎዶሎ')+'</span></td><td class="q-tk">#'+c.token+'</td><td class="q-tm">'+ts+'</td><td><button type="button" class="srv-btn" onclick="doServe('+c.id+',\''+c.plate+'\')"><i class="fas fa-check"></i> SERVED</button></td></tr>');
   });
  });
 }

 function loadReport(){
  var date=$('#rpt-date').val()||new Date().toISOString().split('T')[0];
  $.post(AJ,{action:'madeya_report',nonce:NO,date:date},function(r){
   if(!r.success)return;
   var rows=r.data.report; servedCount=rows.length; updateStats();
   $('#rpt-info').text(rows.length+' vehicle(s) served on '+date);
   var list=$('#rpt-list').empty();
   if(!rows.length){list.html('<div style="text-align:center;padding:16px;font-size:12px;color:var(--mt)">No records</div>');return;}
   rows.forEach(function(row){
    var ts=new Date(row.served_at.replace(' ','T')).toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'});
    var tlbl=CFG.type_labels[row.vehicle_type]||row.vehicle_type||'--';
    var par=row.plate_parity;
    list.append('<div class="rpt-row"><span class="rpt-pl">'+row.plate+'</span><div class="rpt-right"><span class="rpt-tp">'+tlbl+'</span><span class="par-badge '+par+'">'+(par==='even'?'ሙሉ':'ጎዶሎ')+'</span><span class="rpt-tm">'+ts+'</span></div></div>');
   });
  });
 }

 window.doClearQueue=function(){
  if(!confirm('CLEAR THE ENTIRE QUEUE?\n\nAll cars will be removed. Served records are kept.'))return;
  $.post(AJ,{action:'madeya_clear_queue',nonce:NO},function(r){if(r.success){toast('Queue cleared','warn');loadQueue();}});
 };

 $('#rpt-date').on('change',loadReport);

 function showFb(type,icon,msg,token){
  var el=$('#mc-fb'),ic={ok:'fa-check-circle',warn:'fa-exclamation-triangle',err:'fa-ban'};
  el.attr('class','show '+type).find('i').attr('class','fas '+(ic[type]||'fa-info-circle'));
  $('#fb-msg').html(msg||'');$('#fb-tk').text(token||'');
 }
 function hideFb(){$('#mc-fb').removeClass('show ok warn err');}

 $('#vip-plate').on('input',function(){
  var s=this.selectionStart;
  var formatted=mcFormatPlate(this.value);
  if(this.value!==formatted){var diff=formatted.length-this.value.length;this.value=formatted;this.setSelectionRange(s+diff,s+diff);}
  var plate=this.value.trim(), digits=plate.match(/\d/g);
  if(digits&&digits.length){
   var last=parseInt(digits[digits.length-1]);
   var isEven=last%2===0;
   $('#vip-par-hint').html('Last digit: <b style="color:'+(isEven?'var(--bl)':'var(--or)')+'">'+last+' -> '+(isEven?'ሙሉ (Even)':'ጎዶሎ (Odd)')+'</b>');
  } else { $('#vip-par-hint').html('&nbsp;'); }
 }).on('keypress',function(e){if(e.key==='Enter')doVipRegister();});

 window.doVipRegister=function(){
  var plate=$('#vip-plate').val().trim().toUpperCase();
  var liters=parseFloat($('#vip-liters').val())||0; // Capture VIP liters
  selType=$('#mc-vtype').val(); // Read the main dropdown

  $('#vip-fb').removeClass('show ok err warn');
  if(!plate){showVipFb('err','Enter a plate number.');return;}
  if(liters<=0){showVipFb('err','Enter Liters to fill for VIP.');return;} // Prevents the error

  var btn=$('#vip-reg-btn').prop('disabled',true).html('<i class="fas fa-circle-notch fa-spin"></i> ADDING...');
  
  // Notice we now pass gallons:liters and liters:liters to satisfy the backend
  $.post(AJ,{action:'madeya_register',nonce:NO,plate:plate,vehicle_type:selType,gallons:liters,liters:liters,queue_type:'vip'},function(r){
   btn.prop('disabled',false).html('<i class="fas fa-star"></i> ADD TO VIP QUEUE');
   if(r.success){
    var d=r.data;
    showVipFb('ok','⭐ VIP: '+d.plate+' -> Token #'+d.token+' | Pos #'+d.position);
    $('#vip-plate').val('').trigger('input');
    $('#vip-liters').val(''); // Clear liters after success
    toast('VIP: '+d.plate+' -> Token #'+d.token,'ok');
    loadVipQueue();loadReport();
   } else {
       showVipFb('err',r.data&&r.data.message?r.data.message:'Error');
       toast(r.data&&r.data.message?r.data.message:'Error','err',5000);
   }
  }).fail(function(){btn.prop('disabled',false).html('<i class="fas fa-star"></i> ADD TO VIP QUEUE');showVipFb('err','Network error.');});
 };

 function showVipFb(type,msg){$('#vip-fb').attr('class','show '+type).find('#vip-fb-msg').text(msg);}

 var _bulkQueueType='normal', _bulkSelType=null;
 window.openBulkImport=function(qtype){
  _bulkQueueType=qtype||'normal'; _bulkSelType=selType;
  var isVip=_bulkQueueType==='vip';
  $('#bulk-modal-title').html('<i class="fas fa-file-upload"></i> BULK IMPORT '+(isVip?'<span style="color:#e74c3c">VIP</span>':'NORMAL')+' QUEUE');
  $('#bulk-run-btn').css({'background':isVip?'linear-gradient(135deg,#c0392b,#7b241c)':'linear-gradient(135deg,var(--f),#c47f10)','color':isVip?'#fff':'#000'});
  $('#bulk-log').hide();$('#bulk-results').empty();$('#bulk-summary').text('');
  $('#bulk-plates').val('');$('#bulk-csv-file').val('');
  buildBulkVtypeGrid();$('#bulk-modal').css('display','flex');
 };
 window.closeBulkImport=function(){$('#bulk-modal').hide();};
 $('#bulk-modal').on('click',function(e){if($(e.target).is('#bulk-modal'))closeBulkImport();});

 function buildBulkVtypeGrid(){
  var allTypes=[
    {k:'bajaj',i:'fa-motorcycle',l:'Bajaj'},{k:'minibus',i:'fa-shuttle-van',l:'Minibus'},
    {k:'motorcycle',i:'fa-motorcycle',l:'Motorcycle'},{k:'private',i:'fa-car',l:'Private Car'},
    {k:'damas',i:'fa-car-side',l:'Damas'},{k:'govt_moto',i:'fa-shield-alt',l:'Govt. Moto'},
    {k:'diesel_car',i:'fa-gas-pump',l:'Diesel Car'} // <-- Added Diesel Car
  ];
  var grid=$('#bulk-vtypes').empty();
  allTypes.forEach(function(t){
   var label=CFG.type_labels[t.k]||t.l;
   var disabled=CFG.allowed_types.indexOf(t.k)===-1;
   var isSel=_bulkSelType===t.k;
   grid.append('<div class="vt-btn bulk-vt'+(disabled?' disabled-type':'')+(isSel?' sel':'')+'" data-type="'+t.k+'" style="font-size:12px;padding:7px 12px;"><i class="fas '+t.i+'"></i>'+label+'</div>');
  });
 }

 $('#bulk-csv-file').on('change',function(){
  var file=this.files[0];if(!file)return;
  var reader=new FileReader();
  reader.onload=function(e){
   var lines=e.target.result.split(/\r?\n/),plates=[];
   lines.forEach(function(line){line=line.trim();if(!line||line.toLowerCase().startsWith('page')||line.toLowerCase().startsWith('plate'))return;var parts=line.split(',');var p=parts[parts.length-1].trim().replace(/[^A-Za-z0-9]/g,'').toUpperCase();if(p&&p.length>2)plates.push(p);});
   $('#bulk-plates').val(plates.join('\n'));toast(plates.length+' plates loaded from CSV','ok');
  };
  reader.readAsText(file);
 });

 window.runBulkImport=function(){
  var plates=$('#bulk-plates').val().trim();
  _bulkSelType=$('#bulk-vtype-sel').val(); // Get type from new dropdown
  if(!plates){toast('Paste or upload plate numbers first.','warn');return;}
  if(!_bulkSelType){toast('Select a vehicle type first.','warn');return;}
  var btn=$('#bulk-run-btn').prop('disabled',true).html('<i class="fas fa-circle-notch fa-spin"></i> IMPORTING...');
  $('#bulk-log').hide();
  $.post(AJ,{action:'madeya_bulk_import',nonce:NO,plates:plates,vehicle_type:_bulkSelType,queue_type:_bulkQueueType},function(r){
   btn.prop('disabled',false).html('<i class="fas fa-rocket"></i> IMPORT TO QUEUE');
   if(!r.success){toast(r.data&&r.data.message?r.data.message:'Import failed','err');return;}
   var s=r.data.summary;
   $('#bulk-summary').css({'background':s.imported>0?'rgba(39,174,96,.1)':'rgba(192,57,43,.08)','color':s.imported>0?'var(--gn)':'var(--rd)','border':'1px solid '+(s.imported>0?'rgba(39,174,96,.3)':'rgba(192,57,43,.25)')}).html('[OK] Imported: <b>'+s.imported+'</b> &nbsp;|&nbsp; ⏭ Skipped: <b>'+s.skipped+'</b> &nbsp;|&nbsp; [ERR] Errors: <b>'+s.errors+'</b>');
   var logEl=$('#bulk-results').empty();
   r.data.results.forEach(function(row){
    var icon=row.status==='ok'?'[OK]':row.status==='skipped'?'⏭':'[ERR]';
    var color=row.status==='ok'?'var(--gn)':row.status==='skipped'?'var(--mt)':'var(--rd)';
    var detail=row.status==='ok'?' -> Token #'+row.token+' | Pos #'+row.position+' | '+(row.parity==='even'?'ሙሉ':'ጎዶሎ'):' -- '+(row.reason||'');
    logEl.append('<div style="color:'+color+'">'+icon+' <b>'+row.plate+'</b>'+detail+'</div>');
   });
   $('#bulk-log').show();loadQueue();loadVipQueue();loadReport();
   if(s.imported>0)toast(s.imported+' plates imported!','ok',5000);
  }).fail(function(){btn.prop('disabled',false).html('<i class="fas fa-rocket"></i> IMPORT TO QUEUE');toast('Network error during import','err');});
 };

 setInterval(function(){if($('#mc-app').is(':visible')){loadQueue();loadWaiting();loadVipQueue();loadReport();}},12000);

 // ── DUPLICATES IMPORT FUNCTIONS ──────────────────────────────
 window.openDuplicatesImport=function(){
  $('#duplicates-modal').css('display','flex');
  $('#duplicates-textarea').val('');
  $('#duplicates-file').val('');
  $('#duplicates-preview').hide();
  $('#duplicates-feedback').hide();
 };

 window.closeDuplicatesImport=function(){
  $('#duplicates-modal').hide();
 };

 $('#duplicates-modal').on('click',function(e){
  if($(e.target).is('#duplicates-modal'))closeDuplicatesImport();
 });

 window.handleDuplicatesFile=function(input){
  if(!input.files||!input.files[0])return;
  var file=input.files[0];
  var reader=new FileReader();
  reader.onload=function(e){
   var lines=e.target.result.split(/[\r\n]+/);
   var plates=[];
   lines.forEach(function(line){
    var clean=line.trim().replace(/[^A-Z0-9]/gi,'').toUpperCase();
    if(clean.length>=3&&!/^PAGE/i.test(line.trim())){
     plates.push(clean);
    }
   });
   $('#duplicates-textarea').val(plates.join('\n'));
   var preview='<b style="color:#C0392B">'+plates.length+' duplicate plates</b> loaded from file';
   if(plates.length>0){
    preview+='<br><span style="color:var(--mt);font-size:11px;">'+plates.slice(0,10).join(', ')+(plates.length>10?' ...':'')+'</span>';
   }
   $('#duplicates-preview').html(preview).show();
  };
  reader.readAsText(file);
 };

 window.importDuplicates=function(){
  var text=$('#duplicates-textarea').val().trim();
  if(!text){
   toast('Please upload a file or paste plate numbers','warn');
   return;
  }
  var lines=text.split(/[\r\n]+/);
  var plates=[];
  lines.forEach(function(line){
   var clean=line.trim().replace(/[^A-Z0-9]/gi,'').toUpperCase();
   if(clean.length>=3){
    plates.push(clean);
   }
  });
  if(plates.length===0){
   toast('No valid plate numbers found','warn');
   return;
  }
  // Store duplicates in memory
  duplicatePlates=plates;
  
  // Try to save to localStorage scoped to this controller + today
  try{
   var dupKey=getDupKey();
   localStorage.setItem(dupKey,JSON.stringify(duplicatePlates));
   cleanOldDupKeys(dupKey);
   console.log('Duplicates saved to localStorage ('+dupKey+'): '+duplicatePlates.length+' plates');
  }catch(e){
   console.warn('localStorage not available (private mode?), using memory only:',e);
   toast('Duplicates loaded (session only - will clear on refresh)','warn',4000);
  }
  
  $('#duplicates-feedback')
   .css({'background':'rgba(39,174,96,.1)','border':'1px solid rgba(39,174,96,.3)','color':'var(--gn)','display':'block'})
   .html('<i class="fas fa-check-circle"></i> <b>'+plates.length+' duplicate plates</b> imported successfully! They will be marked with red color and warning icon in the active queue.');
  
  toast(plates.length+' duplicates imported','ok');
  
  // Reload queue to show red markings
  loadQueue();
  
  // Close modal after 2 seconds
  setTimeout(function(){
   closeDuplicatesImport();
  },2000);
 };

 // Helper: get localStorage key scoped to this controller + today's date
 function getDupKey(){
  var today=new Date().toISOString().slice(0,10);
  return 'madeya_dup_'+currentCtrlId+'_'+today;
 }

 // Helper: clean up old duplicate keys from previous days or other controllers
 function cleanOldDupKeys(keepKey){
  try{
   var toRemove=[];
   for(var i=0;i<localStorage.length;i++){
    var k=localStorage.key(i);
    if(k&&k.indexOf('madeya_dup_')===0&&k!==keepKey){
     toRemove.push(k);
    }
   }
   toRemove.forEach(function(k){localStorage.removeItem(k);});
   // Also remove the old global key from before this update
   localStorage.removeItem('madeya_duplicates');
   if(toRemove.length>0) console.log('Cleaned '+toRemove.length+' old duplicate key(s)');
  }catch(e){}
 }

 // Load duplicates scoped to current controller + today (called from showApp)
 function loadScopedDuplicates(){
  duplicatePlates=[];
  try{
   var dupKey=getDupKey();
   var stored=localStorage.getItem(dupKey);
   if(stored){
    duplicatePlates=JSON.parse(stored)||[];
    if(duplicatePlates.length>0){
     console.log('Loaded '+duplicatePlates.length+' duplicate plates for controller '+currentCtrlId+' today');
    }
   }
   cleanOldDupKeys(dupKey);
  }catch(e){
   console.warn('Could not load duplicates from localStorage:',e);
   duplicatePlates=[];
  }
 }

 // Test function to verify duplicates feature works on mobile
 window.testDuplicates=function(){
  // Get first 5 plates from current queue as test duplicates
  $.post(AJ,{action:'madeya_get_queue',page:1,per_page:5},function(r){
   if(r.success&&r.data.queue&&r.data.queue.length>0){
    var testPlates=r.data.queue.map(function(v){return v.plate.toUpperCase();});
    duplicatePlates=testPlates;
    try{
     localStorage.setItem(getDupKey(),JSON.stringify(duplicatePlates));
     console.log('Test duplicates saved:',testPlates);
    }catch(e){
     console.warn('localStorage error:',e);
    }
    $('#duplicates-feedback')
     .css({'background':'rgba(41,128,185,.1)','border':'1px solid rgba(41,128,185,.3)','color':'var(--bl)','display':'block'})
     .html('<i class="fas fa-bug"></i> TEST: Marked first '+testPlates.length+' plates as duplicates: '+testPlates.join(', '));
    toast('Test: '+testPlates.length+' plates marked as duplicates','ok');
    loadQueue();
    setTimeout(function(){closeDuplicatesImport();},3000);
   }else{
    toast('No vehicles in queue to test with','warn');
   }
  });
 };

 // Remove served vehicles from active queue
 window.removeServedFromQueue=function(){
  var text=$('#duplicates-textarea').val().trim();
  if(!text){
   toast('Please paste the served plate numbers first','warn');
   return;
  }
  
  if(!confirm('Remove all these vehicles from the Active Queue?\n\nThis will mark them as SERVED and remove them from the queue.')){
   return;
  }
  
  var lines=text.split(/[\r\n]+/);
  var plates=[];
  lines.forEach(function(line){
   var clean=line.trim().replace(/[^A-Z0-9]/gi,'').toUpperCase();
   if(clean.length>=3){
    plates.push(clean);
   }
  });
  
  if(plates.length===0){
   toast('No valid plate numbers found','warn');
   return;
  }
  
  $('#duplicates-feedback')
   .css({'background':'rgba(230,126,34,.1)','border':'1px solid rgba(230,126,34,.3)','color':'var(--or)','display':'block'})
   .html('<i class="fas fa-spinner fa-spin"></i> Removing '+plates.length+' vehicles from queue...');
  
  // Get current queue and find matching vehicles
  $.post(AJ,{action:'madeya_get_queue',page:1,per_page:9999},function(r){
   if(!r.success||!r.data.queue){
    toast('Failed to load queue','err');
    return;
   }
   
   var queue=r.data.queue;
   var toRemove=[];
   var notFound=[];
   
   plates.forEach(function(plate){
    var found=queue.find(function(v){return v.plate.toUpperCase()===plate;});
    if(found){
     toRemove.push(found);
    }else{
     notFound.push(plate);
    }
   });
   
   if(toRemove.length===0){
    $('#duplicates-feedback')
     .css({'background':'rgba(192,57,43,.1)','border':'1px solid rgba(192,57,43,.3)','color':'var(--rd)','display':'block'})
     .html('<i class="fas fa-info-circle"></i> None of these plates are in the active queue. They may have already been served or removed.');
    toast('No matching vehicles found in queue','warn');
    return;
   }
   
   // Remove vehicles one by one
   var removed=0;
   var failed=0;
   
   function removeNext(index){
    if(index>=toRemove.length){
     // Done
     $('#duplicates-feedback')
      .css({'background':'rgba(39,174,96,.1)','border':'1px solid rgba(39,174,96,.3)','color':'var(--gn)','display':'block'})
      .html('<i class="fas fa-check-circle"></i> Removed <b>'+removed+'</b> vehicles from queue'+(failed>0?' ('+failed+' failed)':'')+(notFound.length>0?' | <b>'+notFound.length+'</b> not found in queue':''));
     toast('Removed '+removed+' vehicles from queue','ok');
     loadQueue();
     loadReport();
     setTimeout(function(){closeDuplicatesImport();},3000);
     return;
    }
    
    var vehicle=toRemove[index];
    $.post(AJ,{action:'madeya_serve',nonce:NO,id:vehicle.id},function(res){
     if(res.success){
      removed++;
     }else{
      failed++;
     }
     $('#duplicates-feedback')
      .html('<i class="fas fa-spinner fa-spin"></i> Removing... '+(index+1)+'/'+toRemove.length);
     removeNext(index+1);
    }).fail(function(){
     failed++;
     removeNext(index+1);
    });
   }
   
   removeNext(0);
  });
 };

 // ── TXT File Import ──────────────────────────────────────────
 var importedPlates = [];

 window.onImportFileChosen = function(input) {
  if (!input.files || !input.files[0]) return;
  var file = input.files[0];
  $('#mc-import-label').text(file.name);
  var reader = new FileReader();
  reader.onload = function(e) {
   var lines = e.target.result.split(/[\r\n]+/);
   importedPlates = [];
   var seen = {};
   var dupes = [];
   lines.forEach(function(line) {
    var clean = line.trim().replace(/[^A-Z0-9]/gi,'').toUpperCase();
    if (clean.length >= 3 && !/^PAGE/i.test(line.trim())) {
     if (seen[clean]) {
      dupes.push(clean);
     } else {
      seen[clean] = true;
      importedPlates.push(clean);
     }
    }
   });
   var preview = importedPlates.slice(0,10).join(', ') + (importedPlates.length > 10 ? ' ...' : '');
   var info = '<b style="color:var(--tx)">' + importedPlates.length + ' numbers</b> found';
   if (dupes.length) info += ' &nbsp;|&nbsp; <b style="color:var(--or)">' + dupes.length + ' duplicates removed</b>: ' + dupes.slice(0,5).join(', ') + (dupes.length>5?'...':'');
   $('#mc-import-preview').html(info + '<br><span style="color:var(--mt)">' + preview + '</span>').show();
   $('#mc-import-btn').css('display','flex');
   $('#mc-import-fb').hide();
  };
  reader.readAsText(file);
 };

 window.doImport = function() {
  if (!importedPlates.length) return;
  var vtype = $('#mc-vtype').val();
  if (!vtype) { alert('Select a vehicle type first.'); return; }
  var btn = $('#mc-import-btn').prop('disabled', true).html('<i class="fas fa-circle-notch fa-spin"></i> IMPORTING...');
  var fb = $('#mc-import-fb');
  fb.hide();
  $.post(AJ, {
   action: 'madeya_bulk_import',
   nonce: NO,
   plates: importedPlates.join('\n'),
   vehicle_type: vtype,
   queue_type: 'normal'
  }, function(r) {
   btn.prop('disabled', false).html('<i class="fas fa-file-import"></i>IMPORT NUMBERS');
   if (r.success) {
    var s = r.data.summary;
    var color = s.imported > 0 ? 'rgba(39,174,96,.1)' : 'rgba(230,126,34,.1)';
    var border = s.imported > 0 ? 'rgba(39,174,96,.3)' : 'rgba(230,126,34,.3)';
    var txt = s.imported > 0 ? 'var(--gn)' : 'var(--or)';
    fb.css({'background':color,'border':'1px solid '+border,'color':txt,'display':'block'})
      .html('<i class="fas fa-check-circle"></i> Imported: <b>' + s.imported + '</b> &nbsp;|&nbsp; Skipped (duplicates): <b>' + s.skipped + '</b>' + (s.errors ? ' &nbsp;|&nbsp; Errors: <b>' + s.errors + '</b>' : ''));
    if (s.imported > 0) { loadQueue(); importedPlates = []; $('#mc-import-file').val(''); $('#mc-import-label').text('CHOOSE TXT FILE'); $('#mc-import-preview').hide(); $('#mc-import-btn').hide(); }
   } else {
    fb.css({'background':'rgba(192,57,43,.08)','border':'1px solid rgba(192,57,43,.3)','color':'var(--rd)','display':'block'})
      .html('<i class="fas fa-ban"></i> ' + (r.data && r.data.message ? r.data.message : 'Import failed.'));
   }
  }).fail(function(){ btn.prop('disabled',false).html('<i class="fas fa-file-import"></i>IMPORT NUMBERS'); });
 };

 // ── Pre-Served Import ────────────────────────────────────────
 var servedPlates = [];

 window.onServedFileChosen = function(input) {
  if (!input.files || !input.files[0]) return;
  var file = input.files[0];
  $('#mc-served-label').text(file.name);
  var reader = new FileReader();
  reader.onload = function(e) {
   var lines = e.target.result.split(/[\r\n]+/);
   servedPlates = [];
   var seen = {};
   lines.forEach(function(line) {
    var clean = line.trim().replace(/[^A-Z0-9]/gi,'').toUpperCase();
    if (clean.length >= 3 && !/^PAGE/i.test(line.trim()) && !seen[clean]) {
     seen[clean] = true;
     servedPlates.push(clean);
    }
   });
   var preview = servedPlates.slice(0,10).join(', ') + (servedPlates.length > 10 ? ' ...' : '');
   $('#mc-served-preview').html('<b style="color:var(--tx)">' + servedPlates.length + ' numbers</b> will be marked as served<br><span style="color:var(--mt)">' + preview + '</span>').show();
   $('#mc-served-btn').css('display','flex');
   $('#mc-served-fb').hide();
  };
  reader.readAsText(file);
 };

 window.doImportServed = function() {
  if (!servedPlates.length) return;
  var vtype = $('#mc-vtype').val();
  if (!vtype) { alert('Select a vehicle type first.'); return; }
  if (!confirm('Mark ' + servedPlates.length + ' numbers as already served? They will NOT appear in the queue.')) return;
  var btn = $('#mc-served-btn').prop('disabled', true).html('<i class="fas fa-circle-notch fa-spin"></i> SAVING...');
  var fb = $('#mc-served-fb');
  fb.hide();
  $.post(AJ, {
   action: 'madeya_import_served',
   nonce: NO,
   plates: servedPlates.join('\n'),
   vehicle_type: vtype
  }, function(r) {
   btn.prop('disabled', false).html('<i class="fas fa-ban"></i>MARK AS SERVED');
   if (r.success) {
    var s = r.data.summary;
    fb.css({'background':'rgba(192,57,43,.08)','border':'1px solid rgba(192,57,43,.3)','color':'var(--rd)','display':'block'})
      .html('<i class="fas fa-check-circle"></i> Saved: <b>' + s.saved + '</b> &nbsp;|&nbsp; Skipped (already in report): <b>' + s.skipped + '</b>' + (s.errors ? ' &nbsp;|&nbsp; Errors: <b>' + s.errors + '</b>' : ''));
    if (s.saved > 0) { servedPlates = []; $('#mc-served-file').val(''); $('#mc-served-label').text('CHOOSE TXT FILE'); $('#mc-served-preview').hide(); $('#mc-served-btn').hide(); }
   } else {
    fb.css({'background':'rgba(192,57,43,.08)','border':'1px solid rgba(192,57,43,.3)','color':'var(--rd)','display':'block'})
      .html('<i class="fas fa-ban"></i> ' + (r.data && r.data.message ? r.data.message : 'Failed.'));
   }
  }).fail(function(){ btn.prop('disabled',false).html('<i class="fas fa-ban"></i>MARK AS SERVED'); });
 };


 // -- Controller Tab Switching --
 window.mcTab = function(tab, btn) {
  document.querySelectorAll('.mc-tab-panel').forEach(function(p){ p.classList.remove('active'); });
  document.querySelectorAll('.mc-tab').forEach(function(b){ b.classList.remove('active'); });
  var panel = document.getElementById('mc-tab-' + tab);
  if(panel) panel.classList.add('active');
  if(btn)   btn.classList.add('active');
   if(tab==='report') { mcLoadReport(); }
   if(tab==='waiting') loadWaiting();
   if(tab==='vip') loadVipQueue();
 };


 // ─── REPORT TAB ────────────────────────────────────────────────────
 var mcReportPage = 1, mcReportSearch = '';

 function mcLoadReport() {
  var reportDate = $('#mc-report-date').val() || new Date().toISOString().split('T')[0];
  var tbody = $('#mc-rtbody');
  tbody.html('<tr><td colspan="7" class="eq"><i class="fas fa-spinner fa-spin"></i><p>Loading...</p></td></tr>');
  $.post(AJ, { action: 'madeya_report', nonce: NO, date: reportDate }, function(r) {
   if (r.success) {
    var report = r.data.report || [];
    // Filter by search
    if (mcReportSearch) {
     report = report.filter(function(v){ return v.plate_number && v.plate_number.toLowerCase().indexOf(mcReportSearch.toLowerCase()) >= 0; });
    }
    $('#mc-report-count').text(report.length + ' vehicle(s)');
    if (report.length === 0) {
     tbody.html('<tr><td colspan="7" class="eq"><i class="fas fa-inbox"></i><p>No served vehicles found</p></td></tr>');
     return;
    }
    // Count plate occurrences to detect repeats
    var plateCounts = {};
    report.forEach(function(v) {
     plateCounts[v.plate_number] = (plateCounts[v.plate_number] || 0) + 1;
    });
    // Track how many times we have seen each plate as we iterate
    var plateSeenCount = {};
    var html = '';
    report.forEach(function(v, idx) {
     plateSeenCount[v.plate_number] = (plateSeenCount[v.plate_number] || 0) + 1;
     var isRepeat = plateSeenCount[v.plate_number] > 1;
     var rowClass = isRepeat ? 'qr repeat-vehicle' : 'qr';
     var badge = isRepeat
      ? '<span class="repeat-badge">&#9888; REPEAT (' + (plateSeenCount[v.plate_number]-1) + 'x)</span>'
      : '<span class="first-badge">&#10003; FIRST</span>';
     var servedAt = v.served_at ? new Date(v.served_at).toLocaleString() : '-';
     html += '<tr class="' + rowClass + '">';
     html += '<td><span class="q-pos">' + (idx+1) + '</span></td>';
     html += '<td><span class="q-pl">' + (v.plate_number||'') + '</span></td>';
     html += '<td><span class="q-tp">' + (v.vehicle_type||'') + '</span></td>';
     html += '<td><span class="q-tk">' + (v.token||'') + '</span></td>';
     html += '<td><span class="q-tm">' + (v.fuel_amount_liters||'0') + ' L</span></td>';
     html += '<td><span class="q-tm">' + servedAt + '</span></td>';
     html += '<td>' + badge + '</td>';
     html += '</tr>';
    });
    tbody.html(html);
   } else {
    tbody.html('<tr><td colspan="7" class="eq"><p>Error loading report</p></td></tr>');
   }
  }).fail(function() {
   tbody.html('<tr><td colspan="7" class="eq"><p>Connection error</p></td></tr>');
  });
 }

 function mcSearchReport() {
  mcReportSearch = $('#mc-report-search').val().trim();
  mcLoadReport();
 }

 function mcClearReportSearch() {
  $('#mc-report-search').val('');
  mcReportSearch = '';
  mcLoadReport();
 }

 // Set today's date on load
 $(document).ready(function() {
  $('#mc-report-date').val(new Date().toISOString().split('T')[0]);
 });

})(jQuery);
</script>
<?php
    return ob_get_clean();
}
