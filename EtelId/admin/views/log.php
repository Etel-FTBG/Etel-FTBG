<?php
/**
 * Etel-ID Premium — Admin View: log.php
 *
 * @package   Etel-ID Premium
 * @version   2.0.0
 * @author    Etel Group · ኢትኤል ቡድን
 * @since     2.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="eid-admin-page">
  <div class="eid-topnav">
    <div class="eid-nav-brand">
      <span class="eid-nav-sig">ኢE</span>
      <div class="eid-nav-title">
        <span class="eid-nav-title-main">Etel-ID Premium</span>
        <span class="eid-nav-title-sub">Audit Log</span>
      </div>
    </div>
    <nav class="eid-nav-links">
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id')); ?>"          class="eid-nav-link">Members</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-issue')); ?>"    class="eid-nav-link">Issue ID</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-print')); ?>"    class="eid-nav-link">Print</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-log')); ?>"      class="eid-nav-link active">Audit Log</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-settings')); ?>" class="eid-nav-link">Settings</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-updater')); ?>"  class="eid-nav-link">🔄 Updater</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-password-reset')); ?>" class="eid-nav-link">🔐 Reset PW</a>
    </nav>
  </div>
  <div class="eid-content">
    <div class="eid-page-header">
      <div>
        <div class="eid-page-title">Audit Log</div>
        <div class="eid-page-subtitle">All identity events — issued, verified, suspended, printed</div>
      </div>
    </div>

    <?php if ( ! $etel_id ) : ?>
    <div class="eid-panel">
      <div class="eid-panel-header"><h3 class="eid-panel-title">Filter by Member</h3></div>
      <div class="eid-panel-body">
        <form method="get" style="display:flex;gap:12px;align-items:flex-end">
          <input type="hidden" name="page" value="etel-id-log">
          <div class="eid-field-wrap" style="flex:1">
            <label class="eid-field-label">Etel-ID</label>
            <input type="text" name="etel_id" class="eid-input" placeholder="e.g. EG-2025-00001 (leave blank to see all)">
          </div>
          <button type="submit" class="eid-btn eid-btn-gold"><i class="ti ti-filter" aria-hidden="true"></i> Filter</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <?php if ( $member ) : ?>
    <div class="eid-notice eid-notice-ok" style="margin-bottom:20px">
      <i class="ti ti-user" aria-hidden="true"></i>
      Showing log for <strong><?php echo esc_html($member->full_name); ?></strong> · <?php echo esc_html($member->etel_id); ?>
    </div>
    <?php endif; ?>

    <div class="eid-panel">
      <div class="eid-panel-header"><h3 class="eid-panel-title">Event History</h3></div>
      <div class="eid-table-wrap">
        <table class="eid-table">
          <thead><tr>
            <th>Etel-ID</th><th>Event</th><th>Actor</th><th>IP Address</th><th>Detail</th><th>Time</th>
          </tr></thead>
          <tbody>
          <?php if ($log): foreach ($log as $entry):
            $event_cls = 'eid-log-' . sanitize_html_class($entry->event);
          ?>
          <tr>
            <td class="eid-cell-id"><code><?php echo esc_html($entry->etel_id); ?></code></td>
            <td><span class="eid-log-event <?php echo esc_attr($event_cls); ?>"><?php echo esc_html($entry->event); ?></span></td>
            <td style="font-size:12px;color:var(--a-text-2)"><?php echo $entry->actor_id ? esc_html(get_userdata($entry->actor_id)->display_name) : 'Public'; ?></td>
            <td style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--a-text-3)"><?php echo esc_html($entry->actor_ip); ?></td>
            <td style="font-size:12px;color:var(--a-text-2)"><?php echo esc_html($entry->detail); ?></td>
            <td style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--a-text-3)"><?php echo esc_html($entry->created_at); ?></td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="6" class="eid-no-data">No log entries found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
