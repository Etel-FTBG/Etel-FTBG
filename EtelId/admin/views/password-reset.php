<?php
/**
 * Etel-ID Premium — Admin Password Reset v1.0
 * Allows admin to reset any member's Etel-ID password
 * and set their own admin PIN.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

$logo_src  = defined('ETELID_LOGO_B64') ? ETELID_LOGO_B64 : ETELID_URL . 'public/images/etel-logo.png';
$message   = '';
$msg_type  = '';

/* ── Handle member password reset ── */
if ( isset( $_POST['action_reset_member'] ) &&
     wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce_reset'] ) ), 'etelid_admin_reset' ) ) {

    $member_id   = absint( $_POST['member_id'] );
    $new_pass    = isset( $_POST['new_password'] ) ? wp_unslash( $_POST['new_password'] ) : '';
    $confirm     = isset( $_POST['confirm_password'] ) ? wp_unslash( $_POST['confirm_password'] ) : '';

    if ( ! $member_id ) {
        $message = 'Please select a member.'; $msg_type = 'err';
    } elseif ( strlen( $new_pass ) < 8 ) {
        $message = 'Password must be at least 8 characters.'; $msg_type = 'err';
    } elseif ( $new_pass !== $confirm ) {
        $message = 'Passwords do not match.'; $msg_type = 'err';
    } else {
        $member = EtelID_DB::get_member( $member_id );
        if ( $member ) {
            EtelID_DB::update_member( $member_id, array(
                'pin_hash' => EtelID_Auth::hash_password( $new_pass )
            ) );
            EtelID_DB::log( $member_id, $member->etel_id, 'updated',
                'Password reset by admin: ' . wp_get_current_user()->display_name );
            EtelID_Auth::revoke_all_sessions( $member_id );
            $message  = 'Password reset for ' . esc_html( $member->full_name ) . ' (' . $member->etel_id . ') successfully.';
            $msg_type = 'ok';
        } else {
            $message = 'Member not found.'; $msg_type = 'err';
        }
    }
}

/* ── Handle bulk password reset (send email) ── */
if ( isset( $_POST['action_send_reset_email'] ) &&
     wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce_email'] ) ), 'etelid_email_reset' ) ) {

    $member_id = absint( $_POST['email_member_id'] );
    $member    = $member_id ? EtelID_DB::get_member( $member_id ) : null;

    if ( $member && $member->email ) {
        /* Generate a temp password */
        $temp_pass = strtoupper( bin2hex( function_exists( 'random_bytes' ) ? random_bytes( 5 ) : openssl_random_pseudo_bytes( 5 ) ) );
        EtelID_DB::update_member( $member_id, array(
            'pin_hash' => EtelID_Auth::hash_password( $temp_pass )
        ) );
        EtelID_Auth::revoke_all_sessions( $member_id );

        $subject = 'Your Etel-ID Password Has Been Reset';
        $body    = "Dear " . $member->full_name . ",\n\n"
                 . "Your Etel-ID account password has been reset by the administrator.\n\n"
                 . "Your Etel-ID : " . $member->etel_id . "\n"
                 . "Temp Password: " . $temp_pass . "\n\n"
                 . "Please sign in at: " . home_url('/login/') . "\n"
                 . "Change your password after signing in.\n\n"
                 . "Regards,\nEtel Group · ኢትኤል ቡድን";

        $sent = wp_mail( $member->email, $subject, $body );
        if ( $sent ) {
            $message  = 'Temp password sent to ' . $member->email . '. They can sign in immediately.';
            $msg_type = 'ok';
        } else {
            $message  = 'Password reset but email could not be sent. Temp password: ' . $temp_pass;
            $msg_type = 'warn';
        }
    } else {
        $message  = $member ? 'This member has no email address on file.' : 'Member not found.';
        $msg_type = 'err';
    }
}

/* Load all members for the dropdown */
$all_members = EtelID_DB::get_all( array( 'limit' => 1000, 'orderby' => 'full_name', 'order' => 'ASC' ) );
?>
<div class="eid-admin-page">
  <div class="eid-topnav">
    <div class="eid-nav-brand">
      <img src="<?php echo esc_attr($logo_src); ?>" alt="" class="eid-nav-logo-img">
      <div class="eid-nav-title">
        <span class="eid-nav-title-main">Etel-ID Premium</span>
        <span class="eid-nav-title-sub">ኢትኤል ቡድን · Password Reset</span>
      </div>
    </div>
    <nav class="eid-nav-links">
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id'));          ?>" class="eid-nav-link">Members</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-issue'));    ?>" class="eid-nav-link">Issue ID</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-settings')); ?>" class="eid-nav-link">Settings</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-updater'));  ?>" class="eid-nav-link">🔄 Updater</a>
    </nav>
    <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id')); ?>" class="eid-btn eid-btn-outline">← Members</a>
  </div>

  <div class="eid-content">
    <div class="eid-page-header">
      <div>
        <div class="eid-page-title">🔐 Password Reset</div>
        <div class="eid-page-subtitle">Reset any member's Etel-ID login password</div>
      </div>
    </div>

    <?php if ( $message ) :
      $notice_class = $msg_type === 'ok' ? 'eid-notice-ok' : ( $msg_type === 'warn' ? 'eid-notice-info' : 'eid-notice-err' );
    ?>
    <div class="eid-notice <?php echo $notice_class; ?>" style="margin-bottom:20px;">
      <?php echo esc_html($message); ?>
    </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

      <!-- Set New Password Directly -->
      <div class="eid-panel">
        <div class="eid-panel-header">
          <h3>🔑 Set New Password Directly</h3>
        </div>
        <div class="eid-panel-body">
          <p style="font-size:13px;color:var(--ink3);margin-bottom:16px;">
            Set a specific password for a member. They can change it after signing in.
          </p>
          <form method="post">
            <?php wp_nonce_field('etelid_admin_reset','_nonce_reset'); ?>
            <div class="eid-field" style="margin-bottom:14px;">
              <label>Member</label>
              <select name="member_id" style="width:100%;">
                <option value="">— Select Member —</option>
                <?php foreach ( $all_members as $m ) : ?>
                <option value="<?php echo (int)$m->id; ?>">
                  <?php echo esc_html($m->full_name); ?> · <?php echo esc_html($m->etel_id); ?>
                  <?php echo $m->email ? '(' . esc_html($m->email) . ')' : '(no email)'; ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="eid-field" style="margin-bottom:14px;">
              <label>New Password</label>
              <div style="position:relative;">
                <input type="password" name="new_password" id="new_pass"
                  placeholder="Min 6 characters" style="width:100%;padding-right:40px;"
                  autocomplete="new-password">
                <button type="button" onclick="togglePw('new_pass')"
                  style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:16px;color:var(--g5);">👁</button>
              </div>
            </div>
            <div class="eid-field" style="margin-bottom:20px;">
              <label>Confirm Password</label>
              <input type="password" name="confirm_password" id="cfm_pass"
                placeholder="Repeat new password" style="width:100%;" autocomplete="new-password">
            </div>
            <!-- Password strength meter -->
            <div id="pw-strength-bar" style="height:4px;border-radius:2px;background:var(--surface3);margin-bottom:14px;overflow:hidden;">
              <div id="pw-strength-fill" style="height:100%;width:0;border-radius:2px;transition:width .3s,background .3s;"></div>
            </div>
            <div id="pw-strength-label" style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--g5);margin-bottom:14px;"></div>
            <button type="submit" name="action_reset_member" class="eid-btn eid-btn-gold" style="padding:12px 24px;">
              🔑 Set Password
            </button>
          </form>
        </div>
      </div>

      <!-- Send Temp Password by Email -->
      <div class="eid-panel">
        <div class="eid-panel-header">
          <h3>📧 Send Temp Password by Email</h3>
        </div>
        <div class="eid-panel-body">
          <p style="font-size:13px;color:var(--ink3);margin-bottom:16px;">
            Generate a random temp password and send it to the member's email address.
            The member can sign in immediately and change it.
          </p>
          <form method="post">
            <?php wp_nonce_field('etelid_email_reset','_nonce_email'); ?>
            <div class="eid-field" style="margin-bottom:20px;">
              <label>Member</label>
              <select name="email_member_id" style="width:100%;">
                <option value="">— Select Member —</option>
                <?php foreach ( $all_members as $m ) : ?>
                <option value="<?php echo (int)$m->id; ?>"
                  <?php echo empty($m->email) ? 'disabled style="color:#999"' : ''; ?>>
                  <?php echo esc_html($m->full_name); ?> · <?php echo esc_html($m->etel_id); ?>
                  <?php echo $m->email ? '— ' . esc_html($m->email) : '(no email)'; ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div style="background:rgba(212,175,55,.08);border:1px solid rgba(212,175,55,.2);border-radius:8px;padding:12px 14px;margin-bottom:16px;">
              <div style="font-size:12px;color:var(--ink3);line-height:1.6;">
                📋 The email will contain:<br>
                &nbsp;• Their Etel-ID<br>
                &nbsp;• A random 8-character temp password<br>
                &nbsp;• A link to the sign-in page
              </div>
            </div>
            <button type="submit" name="action_send_reset_email" class="eid-btn eid-btn-gold" style="padding:12px 24px;">
              📧 Send Reset Email
            </button>
          </form>
        </div>
      </div>

    </div><!-- /grid -->

    <!-- Recent password reset activity -->
    <?php
    global $wpdb;
    $recent_resets = $wpdb->get_results(
        "SELECT l.*, m.full_name, m.etel_id
         FROM {$wpdb->prefix}etelid_log l
         JOIN {$wpdb->prefix}etelid_members m ON l.member_id = m.id
         WHERE (l.event = 'updated' OR l.action = 'updated')
           AND (l.detail LIKE '%Password reset%' OR l.detail LIKE '%password%')
         ORDER BY l.created_at DESC
         LIMIT 10"
    );
    if ( $recent_resets ) :
    ?>
    <div class="eid-panel" style="margin-top:20px;">
      <div class="eid-panel-header"><h3>📋 Recent Password Resets</h3></div>
      <div class="eid-panel-body" style="padding:0;">
        <?php foreach ( $recent_resets as $r ) : ?>
        <div style="display:flex;align-items:center;gap:12px;padding:10px 18px;border-bottom:1px solid rgba(212,175,55,.1);">
          <span style="font-size:18px;">🔑</span>
          <div style="flex:1;">
            <div style="font-size:13px;font-weight:700;color:var(--ink);">
              <?php echo esc_html($r->full_name); ?>
              <span style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--g5);"> <?php echo esc_html($r->etel_id); ?></span>
            </div>
            <div style="font-size:11px;color:var(--ink3);"><?php echo esc_html($r->detail); ?></div>
          </div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--g5);">
            <?php echo esc_html($r->created_at); ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /eid-content -->
</div><!-- /eid-admin-page -->

<script>
function togglePw(id) {
    var el = document.getElementById(id);
    el.type = el.type === 'password' ? 'text' : 'password';
}
/* Password strength meter */
document.getElementById('new_pass').addEventListener('input', function() {
    var pw   = this.value;
    var fill = document.getElementById('pw-strength-fill');
    var lbl  = document.getElementById('pw-strength-label');
    var score = 0;
    if (pw.length >= 6)  score++;
    if (pw.length >= 10) score++;
    if (/[A-Z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;
    var colors = ['', '#ef4444', '#f59e0b', '#eab308', '#22c55e', '#16a34a'];
    var labels = ['', 'Very Weak', 'Weak', 'Fair', 'Strong', 'Very Strong'];
    fill.style.width    = (score * 20) + '%';
    fill.style.background = colors[score] || '#ef4444';
    lbl.textContent     = pw.length ? labels[score] || 'Very Weak' : '';
    lbl.style.color     = colors[score] || 'var(--g5)';
});
</script>
