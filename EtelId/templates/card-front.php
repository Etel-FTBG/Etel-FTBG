<?php
/**
 * Etel-ID Premium — Card FRONT v4.0.0
 * International premium layout — Fayda/US Passport Card level
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$logo = defined('ETELID_LOGO_B64') ? ETELID_LOGO_B64 : ETELID_URL.'public/images/etel-logo.png';
$org  = get_option('etelid_org_name', 'Etel Group');

/* ── Local Code128 barcode — pure PHP, no external request ──
   Replaces TEC-IT (barcode.tec-it.com) with EtelID_Barcode::svg()
   Works offline, prints reliably, zero dependency. */
$barcode_data = preg_replace('/[^A-Za-z0-9\-\.\_]/', '', $member->etel_id);
if ( empty($barcode_data) ) $barcode_data = preg_replace('/[^A-Za-z0-9\-\.\_]/', '', $member->serial_number);

$sex_am = $member->sex === 'Female' ? 'ሴት' : ($member->sex === 'Male' ? 'ወንድ' : '');
$parts  = explode(' ', trim($member->full_name));
$init   = '';
foreach ($parts as $p) { if ($p) $init .= strtoupper(substr($p, 0, 1)); }
$init   = substr($init, 0, 2);
?>
<div class="eid-card-front">
  <div class="efc-bg"></div>
  <div class="efc-guilloche"></div>

  <!-- ── ETEL COIN WATERMARK — centered behind all content ── -->
  <?php $coin_src = defined('ETELID_LOGO_COIN_B64') ? ETELID_LOGO_COIN_B64 : ''; ?>
  <?php if ($coin_src): ?>
  <div class="efc-coin-watermark" id="efc-coin-front">
    <img src="<?php echo esc_attr($coin_src); ?>" alt="" draggable="false">
  </div>
  <?php endif; ?>

  <!-- ── GOLD HEADER ── -->
  <div class="efc-header">
    <div class="efc-header-left">
      <span class="efc-header-am">ኢትኤል ቡድን ካርድ</span>
      <span class="efc-header-en"><?php echo esc_html($org); ?> · Corporate ID Card</span>
    </div>
    <div class="efc-header-right">
      <img src="<?php echo esc_attr($logo); ?>" alt="" class="efc-header-logo">
      <div class="efc-flag">
        <div class="efc-flag-g"></div>
        <div class="efc-flag-y"></div>
        <div class="efc-flag-r"></div>
      </div>
    </div>
  </div>

  <!-- ── HOLOGRAPHIC SIDE STRIP ── -->
  <div class="efc-holo-strip"></div>

  <!-- ── LEFT PHOTO ZONE ── -->
  <div class="efc-left">
    <div class="efc-barcode">
      <?php
      /* Rotate 90°: render vertical barcode via CSS transform on SVG */
      $bc_svg = class_exists('EtelID_Barcode')
          ? EtelID_Barcode::img_tag( $barcode_data, 180, 36, '#003366',
              'style="transform:rotate(90deg);transform-origin:center center;display:block;margin:auto"' )
          : ''; /* graceful fallback — no broken image */
      echo $bc_svg;
      ?>
      <span class="efc-barcode-num"><?php echo esc_html($member->etel_id); ?></span>
    </div>

    <div class="efc-photo">
      <div class="efc-photo-corner tl"></div>
      <div class="efc-photo-corner tr"></div>
      <div class="efc-photo-corner bl"></div>
      <div class="efc-photo-corner br"></div>
      <?php if (!empty($member->photo_url)): ?>
        <img src="<?php echo esc_url($member->photo_url); ?>" alt="Photo">
      <?php else: ?>
        <div class="efc-photo-init"><?php echo esc_html($init); ?></div>
      <?php endif; ?>
    </div>

    <div class="efc-chip">
      <div class="efc-chip-inner">
        <div class="efc-chip-pad"></div><div class="efc-chip-pad"></div>
        <div class="efc-chip-pad"></div><div class="efc-chip-pad"></div>
        <div class="efc-chip-pad"></div><div class="efc-chip-pad"></div>
      </div>
    </div>
  </div>

  <!-- ── FIELDS ── -->
  <div class="efc-fields">

    <div class="efc-title">
      <span class="efc-title-am">የኢትኤል ቡድን ዲጂታል ታነሳ ካርድ</span>
      <span class="efc-title-en">CORPORATE DIGITAL ID CARD</span>
    </div>

    <!-- Name -->
    <div class="efc-f">
      <span class="efc-lbl"><span class="efc-lbl-am">ስም /</span> FIRST, MIDDLE, SURNAME</span>
      <?php if (!empty($member->full_name_am)): ?>
      <span class="efc-name-am"><?php echo esc_html($member->full_name_am); ?></span>
      <?php endif; ?>
      <span class="efc-name"><?php echo esc_html($member->full_name); ?></span>
    </div>

    <!-- DOB -->
    <div class="efc-f">
      <span class="efc-lbl"><span class="efc-lbl-am">የትውልድ ቀን /</span> DATE OF BIRTH</span>
      <span class="efc-val"><?php echo esc_html($member->dob); ?>
        <?php if (!empty($member->dob_eth)): ?>
        <span style="font-family:'Noto Sans Ethiopic',sans-serif;font-size:8.5px;color:#7A5518;margin-left:5px;"><?php echo esc_html($member->dob_eth); ?></span>
        <?php endif; ?>
      </span>
    </div>

    <!-- Sex + Blood + Holo patch -->
    <div class="efc-f-row">
      <div class="efc-f-half">
        <span class="efc-lbl"><span class="efc-lbl-am">ጾታ /</span> SEX</span>
        <span class="efc-val"><?php echo esc_html($member->sex); ?>
          <?php if ($sex_am): ?><span style="font-family:'Noto Sans Ethiopic',sans-serif;font-size:8.5px;color:#555;"> | <?php echo esc_html($sex_am); ?></span><?php endif; ?>
        </span>
      </div>
      <div class="efc-f-half">
        <span class="efc-lbl"><span class="efc-lbl-am">ደም /</span> BLOOD</span>
        <span class="efc-val"><?php echo esc_html($member->blood_type ?: '—'); ?></span>
      </div>
    </div>

    <!-- Nationality -->
    <div class="efc-f">
      <span class="efc-lbl"><span class="efc-lbl-am">ዜግነት /</span> NATIONALITY</span>
      <span class="efc-val"><?php echo esc_html($member->nationality ?: 'Ethiopian'); ?></span>
    </div>

  </div>

  <!-- ── GOLD FOOTER ── -->
  <div class="efc-footer">
    <span class="efc-footer-id"><?php echo esc_html($member->etel_id); ?></span>
    <?php if (!empty($member->card_number)): ?>
    <span class="efc-footer-cn"><?php echo esc_html($member->card_number); ?></span>
    <?php endif; ?>
    <img src="<?php echo esc_attr($logo); ?>" alt="" class="efc-footer-logo">
  </div>

</div>
