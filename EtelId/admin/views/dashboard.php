<?php
/**
 * Etel-ID Premium — Admin View: dashboard.php
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
        <span class="eid-nav-title-sub">ኢትኤል ቡድን · Corporate Identity v2.0</span>
      </div>
    </div>
    <nav class="eid-nav-links">
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id')); ?>"           class="eid-nav-link active">Members</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-issue')); ?>"     class="eid-nav-link">Issue ID</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-print')); ?>"     class="eid-nav-link">Print</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-log')); ?>"       class="eid-nav-link">Audit Log</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-settings')); ?>"  class="eid-nav-link">Settings</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-updater')); ?>"  class="eid-nav-link">🔄 Updater</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-password-reset')); ?>" class="eid-nav-link">🔐 Reset PW</a>
    </nav>
    <div>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-issue')); ?>" class="eid-btn eid-btn-gold">
        <i class="ti ti-plus" aria-hidden="true"></i> Issue New ID
      </a>
    </div>
  </div>

  <div class="eid-content">
    <div class="eid-page-header">
      <div>
        <div class="eid-page-title">Member Registry</div>
        <div class="eid-page-subtitle"><?php echo number_format($total); ?> Etel-IDs issued across all companies</div>
      </div>
    </div>

    <div class="eid-stats-grid">
      <div class="eid-stat-card" style="--accent:#c8922a">
        <span class="eid-stat-label">Total Issued</span>
        <span class="eid-stat-num"><?php echo number_format($total); ?></span>
        <span class="eid-stat-sub">All time</span>
      </div>
      <div class="eid-stat-card" style="--accent:#22c55e">
        <span class="eid-stat-label">Active</span>
        <span class="eid-stat-num" style="color:#22c55e"><?php echo number_format($counts['active']); ?></span>
        <span class="eid-stat-sub">Valid IDs</span>
      </div>
      <div class="eid-stat-card" style="--accent:#f59e0b">
        <span class="eid-stat-label">Suspended</span>
        <span class="eid-stat-num" style="color:#f59e0b"><?php echo number_format($counts['suspended']); ?></span>
        <span class="eid-stat-sub">Under review</span>
      </div>
      <div class="eid-stat-card" style="--accent:#ef4444">
        <span class="eid-stat-label">Revoked</span>
        <span class="eid-stat-num" style="color:#ef4444"><?php echo number_format($counts['revoked']); ?></span>
        <span class="eid-stat-sub">Cancelled</span>
      </div>
      <div class="eid-stat-card" style="--accent:#3b82f6">
        <span class="eid-stat-label">Companies</span>
        <span class="eid-stat-num" style="color:#3b82f6">8</span>
        <span class="eid-stat-sub">All linked</span>
      </div>
    </div>

    <div class="eid-panel">
      <div class="eid-panel-header">
        <h3 class="eid-panel-title">All Members</h3>
      </div>
      <form method="get" class="eid-filter-bar">
        <input type="hidden" name="page" value="etel-id">
        <div class="eid-search-wrap">
          <i class="ti ti-search eid-search-ico" aria-hidden="true"></i>
          <input type="text" name="s" value="<?php echo esc_attr($search); ?>"
            class="eid-search-input" placeholder="Search name, Etel-ID, phone, FIN…">
        </div>
        <select name="status" class="eid-filter-select" onchange="this.form.submit()">
          <option value="">All Status</option>
          <?php foreach ( array('active','suspended','expired','revoked') as $st ) : ?>
          <option value="<?php echo esc_attr($st); ?>" <?php selected($status,$st); ?>><?php echo ucfirst($st); ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="eid-btn eid-btn-ghost" style="padding:8px 16px;font-size:12px">
          <i class="ti ti-search" aria-hidden="true"></i> Search
        </button>
        <?php if ($search||$status): ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id')); ?>" class="eid-btn eid-btn-ghost" style="padding:8px 12px;font-size:12px">
          <i class="ti ti-x" aria-hidden="true"></i>
        </a>
        <?php endif; ?>
      </form>

      <div class="eid-table-wrap">
        <table class="eid-table">
          <thead><tr>
            <th>Etel-ID</th><th>Full Name</th><th>Company / Role</th><th>Tier</th>
            <th>Phone</th><th>FIN</th><th>Status</th><th>Issued</th><th>Actions</th>
          </tr></thead>
          <tbody>
          <?php if ($members): foreach ($members as $m): ?>
          <tr data-id="<?php echo (int)$m->id; ?>">
            <td class="eid-cell-id"><code><?php echo esc_html($m->etel_id); ?></code></td>
            <td>
              <span class="eid-cell-name"><?php echo esc_html($m->full_name); ?></span>
              <?php if ($m->full_name_am): ?><span class="eid-cell-name-am"><?php echo esc_html($m->full_name_am); ?></span><?php endif; ?>
            </td>
            <td>
              <?php echo esc_html($m->primary_company); ?>
              <?php if ($m->role): ?><br><small style="color:var(--a-text-3);font-size:11px"><?php echo esc_html($m->role); ?></small><?php endif; ?>
            </td>
            <td><span class="eid-tier eid-tier-<?php echo esc_attr($m->tier); ?>"><?php echo esc_html(ucfirst($m->tier)); ?></span></td>
            <td style="font-family:'JetBrains Mono',monospace;font-size:12px"><?php echo esc_html($m->phone); ?></td>
            <td class="eid-cell-fin"><?php echo esc_html($m->fin); ?></td>
            <td><span class="eid-badge eid-badge-<?php echo esc_attr($m->status); ?>"><?php echo esc_html(ucfirst($m->status)); ?></span></td>
            <td style="font-size:12px;color:var(--a-text-2)"><?php echo esc_html($m->issue_date); ?></td>
            <td class="eid-actions">
              <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-edit-member&member_id='.(int)$m->id)); ?>" class="eid-act" title="Edit Member" style="color:var(--a-gold);"><i class="ti ti-edit" aria-hidden="true"></i></a>
              <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-print&etel_id='.urlencode($m->etel_id))); ?>" class="eid-act" title="View &amp; Print"><i class="ti ti-id" aria-hidden="true"></i></a>
              <button class="eid-act eid-toggle" data-id="<?php echo (int)$m->id; ?>" title="Toggle Status"><i class="ti ti-player-pause" aria-hidden="true"></i></button>
              <button class="eid-act danger eid-delete" data-id="<?php echo (int)$m->id; ?>" title="Delete"><i class="ti ti-trash" aria-hidden="true"></i></button>
            </td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="9" class="eid-no-data">No Etel-IDs yet. <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-issue')); ?>">Issue the first one →</a></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($total > 20):
        $pages = ceil($total/20);
        $cur   = max(1, isset($_GET['paged'])?(int)$_GET['paged']:1); ?>
      <div class="eid-pagination">
        <span>Showing <?php echo (($cur-1)*20+1); ?>–<?php echo min($cur*20,$total); ?> of <?php echo $total; ?></span>
        <div class="eid-page-links">
          <?php for ($p=max(1,$cur-2);$p<=min($pages,$cur+2);$p++): ?>
          <a href="<?php echo esc_url(add_query_arg('paged',$p)); ?>" class="eid-page-link <?php echo $p===$cur?'current':''; ?>"><?php echo $p; ?></a>
          <?php endfor; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
