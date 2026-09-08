<?php
/**
 * Etel-ID Premium — Card BACK v4.1.0
 * Fixed layout: info → FIN below info → notice → QR right side
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$logo      = defined('ETELID_LOGO_B64') ? ETELID_LOGO_B64 : ETELID_URL.'public/images/etel-logo.png';
$org_site  = get_option('etelid_org_website', 'ethelmarket.com');
$qr_img    = EtelID_QR::img($member->etel_id, 120);
$companies = isset($companies) ? $companies : EtelID_DB::get_companies($member->id);
?>
<div class="eid-card-back">
  <div class="ecb-bg"></div>
  <div class="ecb-watermark">ኢE</div>

  <!-- ── ETEL COIN WATERMARK ── -->
  <?php $coin_src = defined('ETELID_LOGO_COIN_B64') ? ETELID_LOGO_COIN_B64 : ''; ?>
  <?php if ($coin_src): ?>
  <div class="ecb-coin-watermark" id="ecb-coin-back">
    <img src="<?php echo esc_attr($coin_src); ?>" alt="" draggable="false">
  </div>
  <?php endif; ?>

  <!-- ── GOLD HEADER ── -->
  <div class="ecb-header">
    <span class="ecb-header-org">ኢትኤል ቡድን · ETEL GROUP OFFICIAL ID</span>
    <span class="ecb-header-id"><?php echo esc_html($member->etel_id); ?></span>
  </div>

  <!-- ── DATES BAR ── -->
  <div class="ecb-dates">
    <div class="ecb-date-item ecb-date-item-1">
      <span class="ecb-date-lbl">የወጣበት / ISSUE</span>
      <span class="ecb-date-val"><?php echo esc_html($member->issue_date); ?></span>
    </div>
    <div class="ecb-date-item ecb-date-item-2">
      <span class="ecb-date-lbl">የሚያበቃ / EXPIRY</span>
      <span class="ecb-date-val"><?php echo esc_html($member->expiry_date); ?></span>
    </div>
    <div class="ecb-date-item ecb-date-item-3">
      <span class="ecb-date-lbl">TIER</span>
      <span class="ecb-date-val tier"><?php echo esc_html(strtoupper($member->tier ?: 'Standard')); ?></span>
    </div>
  </div>

  <!-- ── LEFT INFO (top section — phone, region, zone, company, role) ── -->
  <div class="ecb-info">

    <?php if ($member->phone): ?>
    <div class="ecb-field">
      <span class="ecb-lbl"><span class="ecb-lbl-am">ስልክ /</span> PHONE</span>
      <span class="ecb-val"><?php echo esc_html($member->phone); ?></span>
    </div>
    <?php endif; ?>

    <?php if ($member->region): ?>
    <div class="ecb-field">
      <span class="ecb-lbl"><span class="ecb-lbl-am">ክልል /</span> REGION</span>
      <span class="ecb-val"><?php echo esc_html($member->region); ?><?php if($member->zone) echo ' · '.esc_html($member->zone); ?></span>
    </div>
    <?php endif; ?>

    <?php if ($member->primary_company): ?>
    <div class="ecb-field">
      <span class="ecb-lbl"><span class="ecb-lbl-am">ድርጅት /</span> COMPANY</span>
      <span class="ecb-val"><?php echo esc_html($member->primary_company); ?></span>
    </div>
    <?php endif; ?>

    <?php if ($member->role): ?>
    <div class="ecb-field">
      <span class="ecb-lbl"><span class="ecb-lbl-am">ሚና /</span> ROLE</span>
      <span class="ecb-val"><?php echo esc_html($member->role); ?></span>
    </div>
    <?php endif; ?>

  </div>

  <!-- ── FIN BAR — below info, above footer ── -->
  <div class="ecb-fin">
    <span class="ecb-fin-lbl">ፊን | FIN</span>
    <span class="ecb-fin-val"><?php echo esc_html($member->fin); ?></span>
  </div>

  <!-- ── LOST NOTICE — below FIN ── -->
  <div class="ecb-notice">
    ይህን ካርድ ካገኙት ለ <?php echo esc_html($org_site); ?> ያስረክቡ።
    If found, return to <?php echo esc_html($org_site); ?>
  </div>

  <!-- ── QR CODE — right column ── -->
  <div class="ecb-qr">
    <div class="ecb-qr-frame"><?php echo $qr_img; ?></div>
    <span class="ecb-qr-lbl">SCAN TO VERIFY · ቃኝ</span>
    <span class="ecb-qr-id"><?php echo esc_html($member->etel_id); ?></span>
  </div>

  <!-- ── GOLD FOOTER ── -->
  <div class="ecb-footer">
    <span class="ecb-footer-sn">SN: <?php echo esc_html($member->serial_number); ?></span>
    <img src="<?php echo esc_attr($logo); ?>" alt="" class="ecb-footer-logo">
  </div>

</div>
