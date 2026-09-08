<?php
/**
 * Etel-ID Premium — Admin View: print.php
 *
 * @package   Etel-ID Premium
 * @version   3.1.2
 * @author    Etel Group · ኢትኤል ቡድን
 * @since     2.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* Get the logo for nav */
$logo_src = defined('ETELID_LOGO_B64') ? ETELID_LOGO_B64 : ETELID_URL . 'public/images/etel-logo.png';
?>
<div class="eid-admin-page">
  <div class="eid-topnav">
    <div class="eid-nav-brand">
      <img src="<?php echo esc_attr($logo_src); ?>" alt="Etel" class="eid-nav-logo-img">
      <div class="eid-nav-title">
        <span class="eid-nav-title-main">Etel-ID Premium</span>
        <span class="eid-nav-title-sub">View &amp; Print</span>
      </div>
    </div>
    <nav class="eid-nav-links">
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id')); ?>"          class="eid-nav-link">Members</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-issue')); ?>"    class="eid-nav-link">Issue ID</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-print')); ?>"    class="eid-nav-link active">Print</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-log')); ?>"      class="eid-nav-link">Audit Log</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-settings')); ?>" class="eid-nav-link">Settings</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-updater')); ?>"  class="eid-nav-link">🔄 Updater</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-password-reset')); ?>" class="eid-nav-link">🔐 Reset PW</a>
    </nav>
    <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id')); ?>" class="eid-btn eid-btn-outline">
      ← All Members
    </a>
  </div>

  <div class="eid-content">
    <div class="eid-page-header">
      <div>
        <div class="eid-page-title">View &amp; Print Card</div>
        <div class="eid-page-subtitle">Preview the physical ID card before printing</div>
      </div>
    </div>

    <?php if ( ! $etel_id ) : ?>
    <div class="eid-panel">
      <div class="eid-panel-header"><h3>Search Member</h3></div>
      <div class="eid-panel-body">
        <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
          <input type="hidden" name="page" value="etel-id-print">
          <div class="eid-field" style="flex:1;min-width:200px;">
            <label>Etel-ID or FIN</label>
            <input type="text" name="etel_id" placeholder="e.g. EG-2026-00001">
          </div>
          <button type="submit" class="eid-btn eid-btn-gold">🔍 Look Up</button>
        </form>
      </div>
    </div>

    <?php elseif ( ! $member ) : ?>
    <div class="eid-notice eid-notice-err">
      No member found for Etel-ID: <strong><?php echo esc_html($etel_id); ?></strong>
    </div>

    <?php else :
      $companies = EtelID_DB::get_companies( $member->id );
    ?>
    <div class="eid-panel">
      <div class="eid-panel-header">
        <h3><?php echo esc_html($member->full_name); ?> · <span style="font-family:'JetBrains Mono',monospace;font-size:12px;"><?php echo esc_html($member->etel_id); ?></span></h3>
        <span class="eid-status-pill eid-status-<?php echo esc_attr($member->status); ?>"><?php echo esc_html(ucfirst($member->status)); ?></span>
      </div>
      <div class="eid-panel-body">

        <!-- Card preview -->
        <div style="display:flex;justify-content:center;padding:20px 0;">
          <div style="position:relative;width:460px;height:290px;border-radius:10px;overflow:hidden;box-shadow:0 8px 32px rgba(100,60,0,.35);">
            <?php include ETELID_PATH . 'templates/card-front.php'; ?>
          </div>
        </div>

        <!-- Action buttons -->
        <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center;margin-top:16px;">
          <a href="<?php echo esc_url(home_url('/print/'.$member->etel_id)); ?>"
             target="_blank" class="eid-btn eid-btn-gold">
            🖨 Open Full Print Page
          </a>
          <a href="<?php echo esc_url(home_url('/verify/'.$member->etel_id)); ?>"
             target="_blank" class="eid-btn eid-btn-outline">
            🔗 Public Verify URL
          </a>
          <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-issue')); ?>"
             class="eid-btn eid-btn-outline">
            + Issue Another
          </a>
        </div>

      </div>
    </div>
    <?php endif; ?>

  </div><!-- /.eid-content -->
</div><!-- /.eid-admin-page -->
