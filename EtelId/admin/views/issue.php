<?php
/**
 * Etel-ID Premium — Admin View: issue.php
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
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id')); ?>"          class="eid-nav-link">Members</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-issue')); ?>"    class="eid-nav-link active">Issue ID</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-print')); ?>"    class="eid-nav-link">Print</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-log')); ?>"      class="eid-nav-link">Audit Log</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-settings')); ?>" class="eid-nav-link">Settings</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-updater')); ?>"  class="eid-nav-link">🔄 Updater</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-password-reset')); ?>" class="eid-nav-link">🔐 Reset PW</a>
    </nav>
    <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id')); ?>" class="eid-btn eid-btn-ghost">
      <i class="ti ti-arrow-left" aria-hidden="true"></i> All Members
    </a>
  </div>

  <div class="eid-content">
    <div class="eid-page-header">
      <div>
        <div class="eid-page-title">Issue New Etel-ID</div>
        <div class="eid-page-subtitle">Fill in member details to generate a premium corporate identity card</div>
      </div>
    </div>

    <?php if ( $new_member && strpos($message,'success:')===0 ) : ?>
    <div class="eid-success-banner">
      <div class="eid-success-icon"><i class="ti ti-check" aria-hidden="true"></i></div>
      <div class="eid-success-ids">
        <div class="eid-success-id-item">
          <span class="eid-success-id-lbl">Etel-ID</span>
          <span class="eid-success-id-val"><?php echo esc_html($new_member->etel_id); ?></span>
        </div>
        <div class="eid-success-id-item">
          <span class="eid-success-id-lbl">FIN</span>
          <span class="eid-success-id-val"><?php echo esc_html($new_member->fin); ?></span>
        </div>
        <div class="eid-success-id-item">
          <span class="eid-success-id-lbl">Serial</span>
          <span class="eid-success-id-val"><?php echo esc_html($new_member->serial_number); ?></span>
        </div>
        <div class="eid-success-id-item">
          <span class="eid-success-id-lbl">Expires</span>
          <span class="eid-success-id-val"><?php echo esc_html($new_member->expiry_date); ?></span>
        </div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-print&etel_id='.urlencode($new_member->etel_id))); ?>" class="eid-btn eid-btn-gold">
          <i class="ti ti-id" aria-hidden="true"></i> View & Print Card
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-issue')); ?>" class="eid-btn eid-btn-ghost">
          <i class="ti ti-plus" aria-hidden="true"></i> Issue Another
        </a>
      </div>
    </div>
    <?php elseif ( strpos($message,'error:')===0 ) : ?>
    <div class="eid-notice eid-notice-err">
      <i class="ti ti-alert-circle" aria-hidden="true"></i>
      <?php echo esc_html(substr($message,6)); ?>
    </div>
    <?php endif; ?>

    <div class="eid-panel">
      <div class="eid-panel-header">
        <h3 class="eid-panel-title">Member Details</h3>
      </div>
      <div class="eid-panel-body">
        <form method="post">
          <?php wp_nonce_field('etelid_issue','_etelid_issue_nonce'); ?>
          <div class="eid-form-grid">

            <div class="eid-section-head">Personal Information</div>

            <div class="eid-field-wrap">
              <label class="eid-field-label">Full Name (Latin) <span class="req">*</span></label>
              <input type="text" name="full_name" class="eid-input" required placeholder="e.g. Dagmawi Bekele">
            </div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Full Name (Amharic)</label>
              <input type="text" name="full_name_am" class="eid-input" placeholder="e.g. ዳግማዊ በቀለ">
            </div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Date of Birth <span class="req">*</span></label>
              <input type="date" name="dob" class="eid-input" required>
            </div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Sex</label>
              <select name="sex" class="eid-select">
                <option value="Male">Male | ወንድ</option>
                <option value="Female">Female | ሴት</option>
                <option value="Other">Other</option>
              </select>
            </div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Blood Type</label>
              <select name="blood_type" class="eid-select">
                <option value="">— Select —</option>
                <?php foreach ( $blood as $bt ) : ?>
                <option value="<?php echo esc_attr($bt); ?>"><?php echo esc_html($bt); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Nationality</label>
              <input type="text" name="nationality" class="eid-input" value="Ethiopian" placeholder="Ethiopian">
            </div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Phone <span class="req">*</span></label>
              <input type="tel" name="phone" class="eid-input" required placeholder="+251 9XX XXX XXX">
            </div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Email</label>
              <input type="email" name="email" class="eid-input" placeholder="member@etelgroup.com">
            </div>

            <div class="eid-section-head">Address</div>

            <div class="eid-field-wrap">
              <label class="eid-field-label">Region</label>
              <select name="region" class="eid-select">
                <option value="">— Select Region —</option>
                <?php foreach ($regions as $r): ?>
                <option value="<?php echo esc_attr($r); ?>"><?php echo esc_html($r); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Zone / City</label>
              <input type="text" name="zone" class="eid-input" placeholder="e.g. Wolayita Zone">
            </div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Woreda / Sub-city</label>
              <input type="text" name="woreda" class="eid-input" placeholder="e.g. Around Sodo">
            </div>

            <div class="eid-section-head">Company Affiliation</div>

            <div class="eid-field-wrap">
              <label class="eid-field-label">Primary Company</label>
              <select name="primary_company_slug" class="eid-select">
                <?php foreach ($companies as $slug => $co): ?>
                <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($co['name']); ?> (<?php echo esc_html($co['code']); ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Role / Position</label>
              <input type="text" name="role" class="eid-input" placeholder="e.g. Delivery Driver, Manager, Customer">
            </div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Department</label>
              <input type="text" name="department" class="eid-input" placeholder="e.g. Operations">
            </div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">Employee Number</label>
              <input type="text" name="employee_number" class="eid-input" placeholder="e.g. EMP-001">
            </div>
            <div class="eid-field-wrap">
              <label class="eid-field-label">ID Tier</label>
              <select name="tier" class="eid-select">
                <?php foreach ($tiers as $slug => $t): ?>
                <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($t['label']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Additional companies -->
            <div style="grid-column:1/-1">
              <label class="eid-field-label" style="display:block;margin-bottom:10px">Also link to these companies</label>
              <div class="eid-co-grid">
                <?php foreach ($companies as $slug => $co): ?>
                <input type="checkbox" name="extra_companies[]" value="<?php echo esc_attr($slug); ?>"
                  id="co_<?php echo esc_attr($slug); ?>" class="eid-co-check">
                <label for="co_<?php echo esc_attr($slug); ?>" class="eid-co-label">
                  <?php echo esc_html($co['name']); ?>
                </label>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="eid-field-wrap eid-form-full">
              <label class="eid-field-label">Notes</label>
              <textarea name="notes" class="eid-textarea" placeholder="Any internal notes about this member…"></textarea>
            </div>

            <div class="eid-form-footer">
              <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id')); ?>" class="eid-btn eid-btn-ghost">Cancel</a>
              <button type="submit" class="eid-btn eid-btn-gold eid-btn-lg">
                <i class="ti ti-id" aria-hidden="true"></i> Issue Etel-ID
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
