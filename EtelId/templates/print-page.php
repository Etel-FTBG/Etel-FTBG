<?php
/**
 * Etel-ID Premium — Print Page v4.0.0
 * CR80 / ISO 7810 ID-1 — self-contained premium print layout
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* Get member — set by the router via $GLOBALS or fallback to query_var */
if ( ! isset( $member ) ) {
    if ( ! empty( $GLOBALS['etelid_print_member'] ) ) {
        $member = $GLOBALS['etelid_print_member'];
    } else {
        $etel_id_raw = get_query_var( 'etelid_print' );
        $member = $etel_id_raw ? EtelID_DB::get_by_etel_id(
            sanitize_text_field( urldecode( $etel_id_raw ) )
        ) : null;
    }
}
if ( ! $member ) {
    wp_redirect( home_url( '/portal/' ) );
    exit;
}

$companies = EtelID_DB::get_companies( $member->id );
$org_name  = get_option('etelid_org_name',    'Etel Group');
$org_site  = get_option('etelid_org_website', 'etelgroup.com');
$logo_src  = ETELID_URL.'public/images/etel-master-coin-128.webp';
$card_css  = file_get_contents(ETELID_PATH.'public/css/etelid-card.css');
$print_css = file_get_contents(ETELID_PATH.'public/css/etelid-print.css');

$parts = explode(' ', trim($member->full_name));
$init  = '';
foreach ($parts as $p) { if ($p) $init .= strtoupper(substr($p,0,1)); }
$init  = substr($init,0,2);
$status_class = 'eid-print-status-' . ($member->status === 'active' ? 'active' : 'suspended');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Print · <?php echo esc_html($member->etel_id); ?> · <?php echo esc_html($org_name); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=JetBrains+Mono:wght@400;500;600;700&family=Cormorant+Garamond:wght@400;600;700&family=Noto+Sans+Ethiopic:wght@400;600;700&family=Noto+Sans:wght@400;600;700&family=Oswald:wght@500;600;700&display=swap" rel="stylesheet">
<style><?php echo file_get_contents(ETELID_PATH.'public/css/etelid-icons.css'); ?>
.eid-print-nav-logo,.v-nav-logo,.af-nav-logo{width:36px!important;height:36px!important;filter:drop-shadow(0 2px 8px rgba(0,0,0,.5))!important}</style>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --g1:#FDF6D8;--g2:#F5E6A3;--g3:#E8C84A;--g4:#D4AF37;
  --g5:#C8922A;--g6:#A07020;--g7:#7A5518;--g8:#4A3210;--g9:#2A1A06;
  --ink:#1A0E00;--ink2:#3A2200;--ink3:#7A5520;
}
body{font-family:'Cormorant Garamond',serif;}
<?php echo $card_css; ?>
<?php echo $print_css; ?>
</style>
</head>
<body>
<div class="eid-print-page" id="eid-print-root">
<div class="eid-print-orb eid-print-orb-1"></div>
<div class="eid-print-orb eid-print-orb-2"></div>

<!-- ══ NAV ══ -->
<nav class="eid-print-nav">
  <img src="<?php echo esc_attr($logo_src); ?>" alt="" class="eid-print-nav-logo">
  <div class="eid-print-nav-text">
    <div class="eid-print-nav-title">Print Etel-ID Card</div>
    <span class="eid-print-nav-id"><?php echo esc_html($member->etel_id); ?> · <?php echo esc_html($org_name); ?></span>
  </div>
  <div class="eid-print-nav-actions">
    <button onclick="window.print()" class="eid-print-btn eid-print-btn-gold">
      <i class="ti ti-printer"></i> Print
    </button>
    <button onclick="pFlip()" class="eid-print-btn eid-print-btn-ghost" id="p-flip-btn">
      <i class="ti ti-refresh"></i> Flip
    </button>
    <a href="<?php echo esc_url(home_url('/portal/')); ?>"
       class="eid-print-btn eid-print-btn-ghost">
      <i class="ti ti-arrow-left"></i> Back
    </a>
  </div>
</nav>

<div class="eid-print-content">

  <!-- Tips -->
  <div class="eid-print-tips">
    <i class="ti ti-info-circle"></i>
    <span>For best results: <strong>Margins = None</strong> · <strong>Background graphics = ON</strong> · <strong>Scale = 100%</strong> · Paper = A4</span>
    <span style="margin-left:auto;color:rgba(212,175,55,.4);">Press <strong style="color:#D4AF37;">Ctrl+P</strong> or click Print button</span>
  </div>

  <!-- Member strip -->
  <div class="eid-print-member-strip">
    <div class="eid-print-member-av">
      <?php if (!empty($member->photo_url)): ?>
      <img src="<?php echo esc_url($member->photo_url); ?>" alt="">
      <?php else: echo esc_html($init); endif; ?>
    </div>
    <div class="eid-print-member-info">
      <div class="eid-print-member-name"><?php echo esc_html($member->full_name); ?></div>
      <span class="eid-print-member-id"><?php echo esc_html($member->etel_id); ?> · FIN: <?php echo esc_html($member->fin); ?></span>
    </div>
    <div class="<?php echo $status_class; ?> eid-print-status">
      <?php echo esc_html(ucfirst($member->status)); ?>
    </div>
    <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:rgba(212,175,55,.4);letter-spacing:.5px;text-align:right;">
      Issue: <?php echo esc_html($member->issue_date); ?><br>
      Exp: <?php echo esc_html($member->expiry_date); ?>
    </div>
  </div>

  <!-- Sheet wrapper -->
  <div class="eid-print-sheet-wrap">
    <div class="eid-print-sheet-head">
      <div class="eid-print-sheet-icon">🖨</div>
      <div>
        <div class="eid-print-sheet-title">A4 Print Preview — CR80 Card Layout</div>
        <span class="eid-print-sheet-sub">ISO/IEC 7810 ID-1 · 85.60 × 53.98 mm · Corner radius 3.18 mm</span>
      </div>
      <div style="margin-left:auto;display:flex;gap:8px;">
        <button class="eid-orient-btn active" id="btn-front" onclick="showFace('front')">
          <i class="ti ti-id"></i> Front
        </button>
        <button class="eid-orient-btn" id="btn-back" onclick="showFace('back')">
          <i class="ti ti-rotate-180"></i> Back
        </button>
        <button class="eid-orient-btn" id="btn-both" onclick="showFace('both')">
          <i class="ti ti-layout-rows"></i> Both
        </button>
      </div>
    </div>

    <!-- A4 Sheet -->
    <div class="eid-print-sheet" id="p-sheet">
      <div class="eid-sheet-label"><?php echo esc_html($org_name); ?> · <?php echo esc_html($member->etel_id); ?> · A4</div>

      <!-- FRONT -->
      <div class="eid-card-slot" id="p-slot-front">
        <div class="eid-crop eid-crop-tl-h"></div><div class="eid-crop eid-crop-tl-v"></div>
        <div class="eid-crop eid-crop-tr-h"></div><div class="eid-crop eid-crop-tr-v"></div>
        <div class="eid-crop eid-crop-bl-h"></div><div class="eid-crop eid-crop-bl-v"></div>
        <div class="eid-crop eid-crop-br-h"></div><div class="eid-crop eid-crop-br-v"></div>
        <span class="eid-slot-lbl">FRONT SIDE</span>
        <span class="eid-slot-spec">
          85.60 × 53.98 mm<br>
          Bleed: 2mm · Corner: 3.18mm<br>
          <?php echo esc_html($org_name); ?>
        </span>
        <div class="eid-trim-box">
          <?php include ETELID_PATH.'templates/card-front.php'; ?>
        </div>
      </div>

      <!-- BACK -->
      <div class="eid-card-slot" id="p-slot-back">
        <div class="eid-crop eid-crop-tl-h"></div><div class="eid-crop eid-crop-tl-v"></div>
        <div class="eid-crop eid-crop-tr-h"></div><div class="eid-crop eid-crop-tr-v"></div>
        <div class="eid-crop eid-crop-bl-h"></div><div class="eid-crop eid-crop-bl-v"></div>
        <div class="eid-crop eid-crop-br-h"></div><div class="eid-crop eid-crop-br-v"></div>
        <span class="eid-slot-lbl">BACK SIDE</span>
        <div class="eid-trim-box">
          <?php include ETELID_PATH.'templates/card-back.php'; ?>
        </div>
      </div>

      <!-- Sheet footer -->
      <div style="font-family:'JetBrains Mono',monospace;font-size:6.5px;color:#bbb;letter-spacing:1px;text-align:center;margin-top:2mm;line-height:2;">
        <?php echo esc_html($org_name); ?> &nbsp;·&nbsp;
        <?php echo esc_html($org_site); ?> &nbsp;·&nbsp;
        <?php echo esc_html($member->etel_id); ?> &nbsp;·&nbsp;
        SN: <?php echo esc_html($member->serial_number); ?>
      </div>
    </div><!-- /sheet -->

  </div><!-- /sheet-wrap -->

  <!-- Print guide -->
  <div style="margin-top:16px;padding:14px 18px;background:rgba(0,0,0,.2);border:1px solid rgba(212,175,55,.15);border-radius:12px;animation:slide-up .4s .3s ease both;">
    <div style="font-family:'Syne',sans-serif;font-size:11px;font-weight:800;color:var(--g2);margin-bottom:10px;">📋 Professional Printing Guide</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;">
      <?php
      $guide = array(
        array('🖨','Print Settings','Margins: None · Scale: 100% · Background graphics: ON'),
        array('📄','Paper','A4 (210×297mm) · 300gsm PVC or standard'),
        array('✂','After printing','Cut along crop marks · Round corners to 3.18mm'),
        array('💳','Laminate','Recommended: 75-micron laminate for durability'),
      );
      foreach ($guide as $g):
      ?>
      <div style="display:flex;gap:10px;padding:10px 12px;background:rgba(212,175,55,.05);border:1px solid rgba(212,175,55,.12);border-radius:8px;">
        <span style="font-size:18px;flex-shrink:0;"><?php echo $g[0]; ?></span>
        <div>
          <div style="font-family:'Syne',sans-serif;font-size:11px;font-weight:800;color:var(--g2);margin-bottom:2px;"><?php echo esc_html($g[1]); ?></div>
          <div style="font-size:11px;color:var(--g5);"><?php echo esc_html($g[2]); ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

</div><!-- /content -->
</div><!-- /print-page -->

<script>
var _pCurrent = 'both';

function showFace(face) {
  _pCurrent = face;
  var front = document.getElementById('p-slot-front');
  var back  = document.getElementById('p-slot-back');
  var bFront = document.getElementById('btn-front');
  var bBack  = document.getElementById('btn-back');
  var bBoth  = document.getElementById('btn-both');
  [bFront,bBack,bBoth].forEach(function(b){b.classList.remove('active');});
  if (face === 'front') {
    front.style.display = '';
    back.style.display  = 'none';
    bFront.classList.add('active');
  } else if (face === 'back') {
    front.style.display = 'none';
    back.style.display  = '';
    bBack.classList.add('active');
  } else {
    front.style.display = '';
    back.style.display  = '';
    bBoth.classList.add('active');
  }
}

function pFlip() {
  var current = _pCurrent;
  if (current === 'front') { showFace('back'); }
  else if (current === 'back') { showFace('front'); }
  else { showFace('front'); } /* toggle both → front */
}

/* Show both by default */
showFace('both');

/* Keyboard shortcut */
document.addEventListener('keydown', function(e) {
  if (e.key === 'f' || e.key === 'F') pFlip();
});
</script>
</body>
</html>
