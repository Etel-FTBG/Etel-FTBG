<?php
/**
 * Etel-ID Premium — Admin Member Updater v3.4.1
 * Edit any member's demographics, status, tier, photo, company, etc.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

$member_id = isset( $_GET['member_id'] ) ? (int) $_GET['member_id'] : 0;
$member    = $member_id ? EtelID_DB::get_member( $member_id ) : null;
$logo_src  = defined('ETELID_LOGO_B64') ? ETELID_LOGO_B64 : ETELID_URL.'public/images/etel-logo.png';

$companies = EtelID_Generator::companies();
$regions   = EtelID_Generator::regions();
$blood     = EtelID_Generator::blood_types();
$tiers     = EtelID_Generator::tiers();
$saved     = false;
$save_msg  = '';

/* ── Handle form submission ── */
if ( $member && isset( $_POST['etelid_member_update'] ) ) {
    check_admin_referer( 'etelid_member_update_' . $member_id );

    $fields = array(
        'full_name'    => 'sanitize_text_field',
        'full_name_am' => 'sanitize_text_field',
        'dob'          => 'sanitize_text_field',
        'sex'          => 'sanitize_text_field',
        'blood_type'   => 'sanitize_text_field',
        'nationality'  => 'sanitize_text_field',
        'phone'        => 'sanitize_text_field',
        'email'        => 'sanitize_email',
        'region'       => 'sanitize_text_field',
        'zone'         => 'sanitize_text_field',
        'woreda'       => 'sanitize_text_field',
        'role'         => 'sanitize_text_field',
        'department'   => 'sanitize_text_field',
        'photo_url'    => 'esc_url_raw',
        'tier'         => 'sanitize_text_field',
        'status'       => 'sanitize_text_field',
        'issue_date'   => 'sanitize_text_field',
        'expiry_date'  => 'sanitize_text_field',
    );

    $data = array();
    foreach ( $fields as $f => $fn ) {
        if ( isset( $_POST[ $f ] ) ) {
            $data[ $f ] = call_user_func( $fn, wp_unslash( $_POST[ $f ] ) );
        }
    }

    /* Company slug → name */
    if ( isset( $_POST['primary_company_slug'] ) ) {
        $slug = sanitize_key( $_POST['primary_company_slug'] );
        if ( isset( $companies[ $slug ] ) ) {
            $data['primary_company'] = $companies[ $slug ]['name'];
        }
    }

    /* Recalculate Ethiopian dates */
    if ( ! empty( $data['dob'] ) && class_exists('EtelID_EthCalendar') ) {
        $data['dob_eth'] = EtelID_EthCalendar::to_ethiopian( $data['dob'] );
    }
    if ( ! empty( $data['issue_date'] ) && class_exists('EtelID_EthCalendar') ) {
        $data['issue_date_eth'] = EtelID_EthCalendar::to_ethiopian( $data['issue_date'] );
    }
    if ( ! empty( $data['expiry_date'] ) && class_exists('EtelID_EthCalendar') ) {
        $data['expiry_date_eth'] = EtelID_EthCalendar::to_ethiopian( $data['expiry_date'] );
    }

    EtelID_DB::update_member( $member_id, $data );
    EtelID_DB::log( $member_id, $member->etel_id, 'updated', 'Admin edit by ' . wp_get_current_user()->display_name );

    /* Reload fresh data */
    $member   = EtelID_DB::get_member( $member_id );
    $saved    = true;
    $save_msg = 'Member profile updated successfully.';
}
?>
<div class="eid-admin-page">
  <div class="eid-topnav">
    <div class="eid-nav-brand">
      <img src="<?php echo esc_attr($logo_src); ?>" alt="" class="eid-nav-logo-img">
      <div class="eid-nav-title">
        <span class="eid-nav-title-main">Etel-ID Premium</span>
        <span class="eid-nav-title-sub">ኢትኤል ቡድን · Edit Member</span>
      </div>
    </div>
    <nav class="eid-nav-links">
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id')); ?>"          class="eid-nav-link active">Members</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-issue')); ?>"    class="eid-nav-link">Issue ID</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-print')); ?>"    class="eid-nav-link">Print</a>
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

    <?php if ( ! $member ) : ?>
    <div class="eid-notice eid-notice-err">
      Member not found. <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id')); ?>">← Back to Members</a>
    </div>
    <?php return; endif; ?>

    <?php if ( $saved ) : ?>
    <div class="eid-notice eid-notice-ok" style="margin-bottom:20px;">
      ✓ <?php echo esc_html($save_msg); ?>
    </div>
    <?php endif; ?>

    <!-- Member header card -->
    <div style="background:rgba(212,175,55,.08);border:1.5px solid rgba(212,175,55,.3);border-radius:14px;padding:18px 22px;margin-bottom:24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
      <?php if ($member->photo_url) : ?>
      <img src="<?php echo esc_url($member->photo_url); ?>" style="width:60px;height:60px;border-radius:50%;object-fit:cover;border:2px solid var(--g4);">
      <?php else : ?>
      <div style="width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,var(--g5),var(--g7));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-size:22px;font-weight:800;color:var(--g1);">
        <?php $p=explode(' ',trim($member->full_name));$i='';foreach($p as $x){if($x)$i.=strtoupper(substr($x,0,1));}echo esc_html(substr($i,0,2)); ?>
      </div>
      <?php endif; ?>
      <div>
        <div style="font-family:'Syne',sans-serif;font-size:18px;font-weight:800;color:var(--ink);"><?php echo esc_html($member->full_name); ?></div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--g5);margin-top:3px;"><?php echo esc_html($member->etel_id); ?> · <?php echo esc_html($member->fin); ?></div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--ink3);margin-top:2px;">Card: <?php echo esc_html($member->card_number); ?> · SN: <?php echo esc_html($member->serial_number); ?></div>
      </div>
      <div style="margin-left:auto;display:flex;gap:10px;flex-wrap:wrap;">
        <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-print&etel_id='.urlencode($member->etel_id))); ?>" class="eid-btn eid-btn-outline" target="_blank">🖨 Print Card</a>
        <a href="<?php echo esc_url(home_url('/verify/'.$member->etel_id)); ?>" class="eid-btn eid-btn-outline" target="_blank">🔗 Verify</a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-biometric&member='.$member->id)); ?>" class="eid-btn eid-btn-outline">🔏 Biometric</a>
      </div>
    </div>

    <form method="post" id="member-edit-form">
      <?php wp_nonce_field( 'etelid_member_update_' . $member_id ); ?>
      <input type="hidden" name="etelid_member_update" value="1">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

        <!-- LEFT COLUMN -->
        <div style="display:flex;flex-direction:column;gap:20px;">

          <!-- Personal Information -->
          <div class="eid-panel">
            <div class="eid-panel-header">
              <h3>👤 Personal Information</h3>
            </div>
            <div class="eid-panel-body">
              <div class="eid-form-grid" style="grid-template-columns:1fr 1fr;gap:14px;">

                <div class="eid-field">
                  <label>Full Name (Latin) *</label>
                  <input type="text" name="full_name" value="<?php echo esc_attr($member->full_name); ?>" required>
                </div>
                <div class="eid-field">
                  <label>Full Name (Amharic) <span style="font-family:'Noto Sans Ethiopic',sans-serif;">ሙሉ ስም</span></label>
                  <input type="text" name="full_name_am" value="<?php echo esc_attr($member->full_name_am); ?>">
                </div>
                <div class="eid-field">
                  <label>Date of Birth</label>
                  <input type="date" name="dob" value="<?php echo esc_attr($member->dob); ?>">
                </div>
                <div class="eid-field">
                  <label>Sex</label>
                  <select name="sex">
                    <option value="Male"   <?php selected($member->sex,'Male'); ?>>Male | ወንድ</option>
                    <option value="Female" <?php selected($member->sex,'Female'); ?>>Female | ሴት</option>
                    <option value="Other"  <?php selected($member->sex,'Other'); ?>>Other</option>
                  </select>
                </div>
                <div class="eid-field">
                  <label>Blood Type</label>
                  <select name="blood_type">
                    <option value="">— Optional —</option>
                    <?php foreach ($blood as $bt): ?>
                    <option value="<?php echo esc_attr($bt); ?>" <?php selected($member->blood_type,$bt); ?>><?php echo esc_html($bt); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="eid-field">
                  <label>Nationality</label>
                  <input type="text" name="nationality" value="<?php echo esc_attr($member->nationality ?: 'Ethiopian'); ?>">
                </div>

                <div class="eid-field" style="grid-column:1/-1;">
                  <label>Photo URL</label>
                  <div style="display:flex;gap:10px;align-items:center;">
                    <?php if ($member->photo_url): ?>
                    <img src="<?php echo esc_url($member->photo_url); ?>" id="photo-preview" style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid var(--border-g);">
                    <?php else: ?>
                    <div id="photo-preview" style="width:48px;height:48px;border-radius:50%;background:var(--surface3);display:flex;align-items:center;justify-content:center;font-size:20px;">📷</div>
                    <?php endif; ?>
                    <input type="url" name="photo_url" id="photo-url-input"
                      value="<?php echo esc_attr($member->photo_url); ?>"
                      placeholder="https://example.com/photo.jpg" style="flex:1;"
                      oninput="document.getElementById('photo-preview').src=this.value||''"
                    >
                  </div>
                </div>

              </div>
            </div>
          </div>

          <!-- Contact & Address -->
          <div class="eid-panel">
            <div class="eid-panel-header">
              <h3>📞 Contact & Address</h3>
            </div>
            <div class="eid-panel-body">
              <div class="eid-form-grid" style="grid-template-columns:1fr 1fr;gap:14px;">
                <div class="eid-field">
                  <label>Phone *</label>
                  <input type="tel" name="phone" value="<?php echo esc_attr($member->phone); ?>" required>
                </div>
                <div class="eid-field">
                  <label>Email</label>
                  <input type="email" name="email" value="<?php echo esc_attr($member->email); ?>">
                </div>
                <div class="eid-field">
                  <label>Region</label>
                  <select name="region">
                    <option value="">— Select —</option>
                    <?php foreach ($regions as $r): ?>
                    <option value="<?php echo esc_attr($r); ?>" <?php selected($member->region,$r); ?>><?php echo esc_html($r); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="eid-field">
                  <label>Zone / City</label>
                  <input type="text" name="zone" value="<?php echo esc_attr($member->zone); ?>">
                </div>
                <div class="eid-field" style="grid-column:1/-1;">
                  <label>Woreda / Sub-city</label>
                  <input type="text" name="woreda" value="<?php echo esc_attr(isset($member->woreda) ? $member->woreda : ''); ?>">
                </div>
              </div>
            </div>
          </div>

        </div><!-- /left col -->

        <!-- RIGHT COLUMN -->
        <div style="display:flex;flex-direction:column;gap:20px;">

          <!-- Company & Role -->
          <div class="eid-panel">
            <div class="eid-panel-header">
              <h3>🏢 Company & Role</h3>
            </div>
            <div class="eid-panel-body">
              <div class="eid-form-grid" style="grid-template-columns:1fr 1fr;gap:14px;">
                <div class="eid-field" style="grid-column:1/-1;">
                  <label>Primary Company</label>
                  <select name="primary_company_slug">
                    <?php foreach ($companies as $slug => $co): ?>
                    <option value="<?php echo esc_attr($slug); ?>"
                      <?php selected(strtolower(str_replace([' ','-'],'',(isset($member->primary_company) ? $member->primary_company : ''))) , strtolower(str_replace([' ','-'],'',$co['name']))); ?>>
                      <?php echo esc_html($co['name']); ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="eid-field">
                  <label>Role / Position</label>
                  <input type="text" name="role" value="<?php echo esc_attr($member->role); ?>" placeholder="e.g. Staff, Owner">
                </div>
                <div class="eid-field">
                  <label>Department</label>
                  <input type="text" name="department" value="<?php echo esc_attr(isset($member->department)?$member->department:''); ?>" placeholder="e.g. Operations">
                </div>
              </div>
            </div>
          </div>

          <!-- ID Card Settings -->
          <div class="eid-panel">
            <div class="eid-panel-header">
              <h3>🪪 ID Card Settings</h3>
            </div>
            <div class="eid-panel-body">
              <div class="eid-form-grid" style="grid-template-columns:1fr 1fr;gap:14px;">

                <div class="eid-field">
                  <label>Status</label>
                  <select name="status">
                    <option value="active"    <?php selected($member->status,'active'); ?>>✅ Active</option>
                    <option value="pending"   <?php selected($member->status,'pending'); ?>>⏳ Pending</option>
                    <option value="suspended" <?php selected($member->status,'suspended'); ?>>⚠ Suspended</option>
                    <option value="revoked"   <?php selected($member->status,'revoked'); ?>>❌ Revoked</option>
                  </select>
                </div>

                <div class="eid-field">
                  <label>Tier</label>
                  <select name="tier">
                    <?php foreach ($tiers as $slug => $tier): ?>
                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($member->tier,$slug); ?>><?php echo esc_html($tier['label']); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="eid-field">
                  <label>Issue Date</label>
                  <input type="date" name="issue_date" value="<?php echo esc_attr($member->issue_date); ?>">
                </div>

                <div class="eid-field">
                  <label>Expiry Date</label>
                  <input type="date" name="expiry_date" value="<?php echo esc_attr($member->expiry_date); ?>">
                </div>

                <!-- Read-only identity numbers -->
                <div class="eid-field" style="grid-column:1/-1;">
                  <label style="margin-bottom:8px;display:block;">Identity Numbers (Read-only)</label>
                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                    <?php
                    $id_fields = array(
                      'Etel-ID'     => $member->etel_id,
                      'FIN'         => $member->fin,
                      'Card Number' => $member->card_number,
                      'Serial'      => $member->serial_number,
                    );
                    foreach ($id_fields as $lbl => $val) : ?>
                    <div style="background:rgba(212,175,55,.07);border:1px solid rgba(212,175,55,.2);border-radius:7px;padding:8px 12px;">
                      <div style="font-family:'JetBrains Mono',monospace;font-size:8px;color:var(--g5);text-transform:uppercase;letter-spacing:1px;margin-bottom:3px;"><?php echo esc_html($lbl); ?></div>
                      <div style="font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--ink);font-weight:700;letter-spacing:.5px;"><?php echo esc_html($val); ?></div>
                    </div>
                    <?php endforeach; ?>
                  </div>
                </div>

              </div>
            </div>
          </div>

          <!-- Recent Activity -->
          <?php
          $activity = EtelID_DB::get_log($member->id, 6);
          if ($activity) :
          ?>
          <div class="eid-panel">
            <div class="eid-panel-header"><h3>📋 Recent Activity</h3></div>
            <div class="eid-panel-body" style="padding:0;">
              <?php foreach ($activity as $entry) :
                $ev = isset($entry->event) ? $entry->event : (isset($entry->action) ? $entry->action : '');
                $icons = array('login'=>'🔑','verified'=>'✅','printed'=>'🖨','updated'=>'✏️','registered'=>'🎉','issued'=>'🪪','login_failed'=>'❌','checkin'=>'📡');
                $icon = isset($icons[$ev]) ? $icons[$ev] : '📌';
              ?>
              <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid rgba(212,175,55,.1);">
                <span style="font-size:16px;"><?php echo $icon; ?></span>
                <div style="flex:1;">
                  <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--ink);font-weight:700;"><?php echo esc_html(str_replace('_',' ',ucfirst($ev))); ?></div>
                  <?php if (isset($entry->detail) && $entry->detail): ?><div style="font-size:11px;color:var(--ink3);"><?php echo esc_html($entry->detail); ?></div><?php endif; ?>
                  <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--g5);"><?php echo esc_html($entry->created_at); ?></div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

        </div><!-- /right col -->

      </div><!-- /grid -->

      <!-- Save bar (sticky bottom) -->
      <div style="position:sticky;bottom:0;background:linear-gradient(135deg,var(--g9,#2A1A06),var(--g8,#4A3210));padding:14px 20px;border-top:2px solid rgba(212,175,55,.4);display:flex;align-items:center;gap:14px;margin-top:24px;border-radius:0 0 12px 12px;box-shadow:0 -4px 20px rgba(0,0,0,.2);z-index:50;">
        <button type="submit" class="eid-btn eid-btn-gold" style="padding:12px 28px;font-size:15px;">
          💾 Save Changes
        </button>
        <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-print&etel_id='.urlencode($member->etel_id))); ?>"
           class="eid-btn eid-btn-outline" target="_blank">🖨 Preview Card</a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id')); ?>" class="eid-btn eid-btn-outline">← Back</a>
        <div style="margin-left:auto;font-family:'JetBrains Mono',monospace;font-size:10px;color:rgba(212,175,55,.5);letter-spacing:1px;">
          Last updated: <?php echo esc_html((isset($member->updated_at) ? $member->updated_at : (isset($member->created_at) ? $member->created_at : '—'))); ?>
        </div>
      </div>

    </form>
  </div><!-- /eid-content -->
</div><!-- /eid-admin-page -->
