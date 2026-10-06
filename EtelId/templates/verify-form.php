<?php
/**
 * Etel-ID Premium — Inline Verify / Lookup Widget
 *
 * @package    Etel-ID Premium
 * @version    2.0.0
 * @author     Etel Group · ኢትኤል ቡድን
 * @since      2.0.0
 *
 * Inline ID lookup widget used by [etelid_verify] shortcode.
 * Accepts Etel-ID, FIN, or 16-digit card number via GET param eid_q.
 * Shows member summary + link to full verify page on match.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$query  = isset($_GET['eid_q']) ? sanitize_text_field(wp_unslash($_GET['eid_q'])) : '';
$member = null;
if ($query) {
    $member = EtelID_DB::get_by_etel_id($query);
    if (!$member) $member = EtelID_DB::get_by_fin($query);
    if (!$member) $member = EtelID_DB::get_by_card($query);
    if ($member) {
        EtelID_DB::increment_scan($member->id);
        EtelID_DB::log($member->id, $member->etel_id, 'verified', 'Shortcode lookup');
    }
}
?>
<link rel="stylesheet" href="<?php echo esc_url( ETELID_URL . 'public/css/etelid-public.css?v=' . filemtime( ETELID_PATH . 'public/css/etelid-public.css' ) ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( ETELID_URL . 'public/css/etelid-fonts.css?v=' . filemtime( ETELID_PATH . 'public/css/etelid-fonts.css' ) ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( ETELID_URL . 'public/css/etelid-icons.css?v=' . filemtime( ETELID_PATH . 'public/css/etelid-icons.css' ) ); ?>">

<div class="eid-pub-page" style="padding-bottom:0">
  <div class="eid-pub-wrap" style="padding-top:24px">

    <div class="eid-verify-lookup">
      <h2>Verify an Etel-ID</h2>
      <p>Enter an Etel-ID number, FIN, or 16-digit card number to verify its authenticity</p>
      <form method="get" action="">
        <div class="eid-verify-input-row">
          <div class="eid-verify-input-wrap">
            <label class="eid-verify-input-label">Etel-ID / FIN / Card Number</label>
            <input type="text" name="eid_q" value="<?php echo esc_attr($query); ?>"
              class="eid-pub-input" placeholder="EG-2025-00001 · 3580-1754-1932 · 6408 3195 8602 8154">
          </div>
          <button type="submit" class="eid-pub-btn eid-btn-primary">
            <i class="ti ti-search" aria-hidden="true"></i> Verify
          </button>
        </div>
      </form>
    </div>

    <?php if ($query && !$member): ?>
    <div class="eid-pub-notice eid-pub-notice-err" style="margin-top:20px">
      <i class="ti ti-x" aria-hidden="true"></i>
      No Etel-ID found matching <strong><?php echo esc_html($query); ?></strong>
    </div>

    <?php elseif ($member): ?>
    <div style="margin-top:24px">
      <?php if ($member->status === 'active'): ?>
      <div class="eid-verify-valid-badge" style="margin-bottom:20px">
        <i class="ti ti-shield-check" aria-hidden="true"></i>
        Verified — Valid Etel-ID · ተረጋግጧል
      </div>
      <?php elseif ($member->status === 'suspended'): ?>
      <div class="eid-pub-notice eid-pub-notice-warn" style="margin-bottom:20px">
        <i class="ti ti-alert-triangle" aria-hidden="true"></i>
        This ID is currently <strong>suspended</strong>.
      </div>
      <?php else: ?>
      <div class="eid-pub-notice eid-pub-notice-err" style="margin-bottom:20px">
        <i class="ti ti-ban" aria-hidden="true"></i>
        This ID has been <strong>revoked</strong>.
      </div>
      <?php endif; ?>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px">
        <?php
        $rows = array(
          array('Etel-ID',  $member->etel_id),
          array('Name',     $member->full_name),
          array('Company',  $member->primary_company),
          array('Expires',  $member->expiry_date),
        );
        foreach ($rows as $r): ?>
        <div style="background:var(--p-carbon-3);border:.5px solid var(--p-border);border-radius:6px;padding:10px 14px">
          <div style="font-family:'JetBrains Mono',monospace;font-size:8px;color:var(--p-text-3);letter-spacing:1.5px;text-transform:uppercase;margin-bottom:4px"><?php echo esc_html($r[0]); ?></div>
          <div style="font-size:14px;font-weight:600;color:var(--p-text)"><?php echo esc_html($r[1]); ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <a href="<?php echo esc_url(EtelID_QR::verify_url($member->etel_id)); ?>" class="eid-pub-btn eid-btn-primary">
        <i class="ti ti-external-link" aria-hidden="true"></i> View Full Card
      </a>
    </div>
    <?php endif; ?>

  </div>
</div>
