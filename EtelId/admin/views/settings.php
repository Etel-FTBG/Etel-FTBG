<?php
/**
 * Etel-ID Premium — Admin Settings v3.6.1
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* Auto-detect admin member ID if not yet set.
 * Priority order:
 *   1. Member whose email matches WP admin email
 *   2. Member with the lowest row ID (the first member = owner)
 */
if ( ! get_option('etelid_admin_member_id') ) {
    global $wpdb;
    $found = null;

    /* Try email match first */
    $admin_email = get_option('admin_email');
    if ( $admin_email ) {
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM " . $wpdb->prefix . "etelid_members WHERE email = %s LIMIT 1",
            $admin_email
        ) );
    }

    /* Fallback: first member ever registered (lowest id) */
    if ( ! $found ) {
        $found = $wpdb->get_var(
            "SELECT id FROM " . $wpdb->prefix . "etelid_members ORDER BY id ASC LIMIT 1"
        );
    }

    if ( $found ) {
        update_option( 'etelid_admin_member_id', (int) $found );
    }
}

$logo_src = defined('ETELID_LOGO_B64') ? ETELID_LOGO_B64 : ETELID_URL . 'public/images/etel-logo.png';

/* Saved notice */
$saved = false;
if ( isset( $_POST['_etelid_settings_nonce'] ) &&
     wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_etelid_settings_nonce'] ) ), 'etelid_settings' ) &&
     current_user_can( 'manage_options' ) ) {
    $saved = true;
}
?>
<div class="eid-admin-page">
  <div class="eid-topnav">
    <div class="eid-nav-brand">
      <img src="<?php echo esc_attr($logo_src); ?>" alt="" class="eid-nav-logo-img">
      <div class="eid-nav-title">
        <span class="eid-nav-title-main">Etel-ID Premium</span>
        <span class="eid-nav-title-sub">ኢትኤል ቡድን · v<?php echo ETELID_VERSION; ?></span>
      </div>
    </div>
    <nav class="eid-nav-links">
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id'));          ?>" class="eid-nav-link">Members</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-issue'));    ?>" class="eid-nav-link">Issue ID</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-print'));    ?>" class="eid-nav-link">Print</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-log'));      ?>" class="eid-nav-link">Audit Log</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-settings')); ?>" class="eid-nav-link active">Settings</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-updater'));  ?>" class="eid-nav-link">🔄 Updater</a>
    </nav>
  </div>

  <div class="eid-content">
    <div class="eid-page-header">
      <div>
        <div class="eid-page-title">Settings</div>
        <div class="eid-page-subtitle">Configure Etel-ID Premium for your organisation</div>
      </div>
    </div>

    <?php if ( $saved ) : ?>
    <div class="eid-notice eid-notice-ok" style="margin-bottom:20px;">✓ Settings saved successfully.</div>
    <?php endif; ?>

    <form method="post">
      <?php wp_nonce_field( 'etelid_settings', '_etelid_settings_nonce' ); ?>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

        <!-- Organisation -->
        <div class="eid-panel">
          <div class="eid-panel-header"><h3>🏢 Organisation</h3></div>
          <div class="eid-panel-body" style="display:flex;flex-direction:column;gap:14px;">
            <div class="eid-field">
              <label>Organisation Name</label>
              <input type="text" name="org_name" value="<?php echo esc_attr( get_option('etelid_org_name','Etel Group · ኢትኤል ቡድን') ); ?>">
            </div>
            <div class="eid-field">
              <label>Website</label>
              <input type="text" name="org_website" value="<?php echo esc_attr( get_option('etelid_org_website','etelgroup.com') ); ?>">
            </div>
            <div class="eid-field">
              <label>Security Email</label>
              <input type="email" name="org_email" value="<?php echo esc_attr( get_option('etelid_org_email','security@etelgroup.com') ); ?>">
            </div>
          </div>
        </div>

        <!-- ID Settings -->
        <div class="eid-panel">
          <div class="eid-panel-header"><h3>🪪 ID Card Settings</h3></div>
          <div class="eid-panel-body" style="display:flex;flex-direction:column;gap:14px;">
            <div class="eid-field">
              <label>ID Prefix (2–4 letters)</label>
              <input type="text" name="id_prefix" maxlength="4"
                value="<?php echo esc_attr( get_option('etelid_id_prefix','EG') ); ?>"
                placeholder="e.g. EG">
              <span style="font-size:11px;color:var(--ink3);">Creates IDs like: EG-2026-00001</span>
            </div>
            <div class="eid-field">
              <label>Expiry Period (years)</label>
              <input type="number" name="expiry_years" min="1" max="20"
                value="<?php echo (int) get_option('etelid_expiry_years', 5); ?>">
            </div>
            <div class="eid-field">
              <label>Require Admin Approval</label>
              <select name="require_approval">
                <option value="0" <?php selected( get_option('etelid_require_approval',0), 0 ); ?>>No — issue immediately</option>
                <option value="1" <?php selected( get_option('etelid_require_approval',0), 1 ); ?>>Yes — pending approval</option>
              </select>
            </div>
          </div>
        </div>

      </div>

      <!-- Admin Member Account -->
      <div class="eid-panel" style="margin-bottom:20px;">
        <div class="eid-panel-header"><h3>👤 Admin Member Account</h3></div>
        <div class="eid-panel-body">
          <p style="font-size:13px;color:var(--ink3);margin-bottom:16px;padding:10px 14px;background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2);border-radius:8px;">
            ✅ Set which Etel-ID member is the site owner. This member will:<br>
            &nbsp;&nbsp;• See <strong>Theme, Updater, WP Admin</strong> buttons in their portal<br>
            &nbsp;&nbsp;• Be automatically logged into WordPress when visiting <strong>/wp-admin/</strong>
          </p>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;flex-wrap:wrap;">
            <div class="eid-field">
              <label>Admin Member ID (row ID number)</label>
              <input type="number" name="admin_member_id"
                value="<?php echo esc_attr( get_option('etelid_admin_member_id','') ); ?>"
                placeholder="e.g. 1">
              <?php
              $admin_mid = get_option('etelid_admin_member_id');
              if ( $admin_mid ) {
                  $adm = EtelID_DB::get_member( (int) $admin_mid );
                  if ( $adm ) {
                      echo '<div style="margin-top:6px;padding:6px 10px;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);border-radius:6px;font-family:\'JetBrains Mono\',monospace;font-size:10px;color:#166534;">';
                      echo '✓ ' . esc_html($adm->full_name) . ' · ' . esc_html($adm->etel_id);
                      echo '</div>';
                  }
              }
              ?>
            </div>
            <div class="eid-field">
              <label>Admin Phone (backup detection)</label>
              <input type="tel" name="admin_phone"
                value="<?php echo esc_attr( get_option('etelid_admin_phone','') ); ?>"
                placeholder="+251 9XX XXX XXX">
            </div>
            <div class="eid-field">
              <label>WP Admin Email (auto-detected)</label>
              <input type="text" value="<?php echo esc_attr( get_option('admin_email') ); ?>" readonly
                style="opacity:.6;cursor:default;">
              <span style="font-size:11px;color:var(--ink3);">Set in WP General Settings</span>
            </div>
          </div>
        </div>
      </div>

      <!-- v3.10.0 — Telegram / WhatsApp credential delivery (Fayda Quick Register) -->
      <div class="eid-panel" style="margin-bottom:20px;">
        <div class="eid-panel-header"><h3>📲 Notifications — Telegram &amp; WhatsApp</h3></div>
        <div class="eid-panel-body">
          <p style="font-size:12px;color:var(--ink3);margin-bottom:16px;">
            Used by <strong>Fayda Quick Register</strong> to deliver new member passwords. WhatsApp always
            works as a one-tap manual send even with nothing filled in below — these fields only enable
            <em>fully automatic</em> sending.
          </p>
          <div style="display:flex;flex-direction:column;gap:14px;">
            <div class="eid-field">
              <label>Telegram Bot Token</label>
              <input type="text" name="tg_bot_token"
                value="<?php echo esc_attr( get_option('etelid_tg_bot_token','') ); ?>"
                placeholder="123456789:AAExampleTokenFromBotFather">
              <span style="font-size:11px;color:var(--ink3);">Create a bot via @BotFather on Telegram to get this. Auto-send to a member only works once they have started a chat with your bot (so Telegram reveals their chat_id).</span>
            </div>
            <div class="eid-field">
              <label>Telegram Bot Username</label>
              <input type="text" name="tg_bot_username"
                value="<?php echo esc_attr( get_option('etelid_tg_bot_username','') ); ?>"
                placeholder="YourBotName_bot (no @ symbol)">
              <span style="font-size:11px;color:var(--ink3);">Used to build the "Link Telegram" button members tap in their portal - the same @username you set with @BotFather.</span>
            </div>
            <div class="eid-field">
              <label>WhatsApp Cloud API Token</label>
              <input type="text" name="wa_token"
                value="<?php echo esc_attr( get_option('etelid_wa_token','') ); ?>"
                placeholder="Meta WhatsApp Cloud API access token">
              <span style="font-size:11px;color:var(--ink3);">From a Meta WhatsApp Business / Cloud API account. Leave blank to rely on the manual wa.me link instead.</span>
            </div>
            <div class="eid-field">
              <label>WhatsApp Phone Number ID</label>
              <input type="text" name="wa_phone_id"
                value="<?php echo esc_attr( get_option('etelid_wa_phone_id','') ); ?>"
                placeholder="From Meta Cloud API → Phone numbers">
            </div>
          </div>
        </div>

        <!-- Quick-launch app links -->
        <div class="eid-panel">
          <div class="eid-panel-header"><h3>🚀 Quick-Launch Apps</h3></div>
          <div class="eid-panel-body" style="display:flex;flex-direction:column;gap:14px;">
            <span style="font-size:11px;color:var(--ink3);">Shown as buttons on every member's portal page. Correct these here if any point to the wrong place - no code deploy needed.</span>
            <div class="eid-field">
              <label>🚖 Driver App URL</label>
              <input type="text" name="driver_app_url"
                value="<?php echo esc_attr( get_option('etelid_driver_app_url','https://ethelmarket.com/delivery/driver/') ); ?>"
                placeholder="https://ethelmarket.com/delivery/driver/">
            </div>
            <div class="eid-field">
              <label>🛺 Rider App URL</label>
              <input type="text" name="ride_app_url"
                value="<?php echo esc_attr( get_option('etelid_ride_app_url','https://ethelmarket.com/delivery/ride/') ); ?>"
                placeholder="https://ethelmarket.com/delivery/ride/">
            </div>
            <div class="eid-field">
              <label>🛍️ Customer App URL</label>
              <input type="text" name="customer_app_url"
                value="<?php echo esc_attr( get_option('etelid_customer_app_url','https://ethelmarket.com/delivery/customer.html') ); ?>"
                placeholder="https://ethelmarket.com/delivery/customer.html">
            </div>
          </div>
        </div>
      </div>

      <!-- Save -->
      <div style="margin-bottom:28px;">
        <button type="submit" class="eid-btn eid-btn-gold" style="padding:13px 32px;font-size:15px;">
          💾 Save Settings
        </button>
      </div>

    </form>

    <!-- URL Reference -->
    <div class="eid-panel" style="margin-bottom:20px;">
      <div class="eid-panel-header"><h3>🔗 System URLs — No WordPress Pages Needed</h3></div>
      <div class="eid-panel-body">
        <p style="font-size:13px;color:var(--ink3);margin-bottom:14px;padding:10px 14px;background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2);border-radius:8px;">
          ✅ All Etel-ID pages are built-in. Share these links directly — no WP pages needed.
        </p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:10px;margin-bottom:20px;">
          <?php
          $sys_urls = array(
            array('📋', 'Apply for Etel-ID',  '/apply/',   'Public registration — share for new members'),
            array('🔑', 'Sign In',             '/login/',   'Login — auto-detects if already signed in'),
            array('🪪', 'Member Portal',       '/portal/',  'Dashboard: card, QR, profile, history'),
            array('✏️', 'Edit Profile',        '/profile/', 'Update demographics, photo, contact info'),
            array('🚪', 'Sign Out',            '/logout/',  'Logs out and returns to login'),
            array('🔍', 'Verify ID',           '/verify/{EtelID}', 'Replace {EtelID} with e.g. EG-2026-00002'),
            array('🖨', 'Print Card',          '/print/{EtelID}',  'CR80 printable card'),
          );
          foreach ( $sys_urls as $u ) :
          ?>
          <div style="background:rgba(212,175,55,.06);border:1px solid rgba(212,175,55,.2);border-radius:8px;padding:12px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
              <span style="font-size:16px;"><?php echo $u[0]; ?></span>
              <strong style="font-size:13px;color:var(--ink);"><?php echo esc_html($u[1]); ?></strong>
            </div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--g5);margin-bottom:3px;">
              <a href="<?php echo esc_url(home_url($u[2])); ?>" target="_blank" style="color:var(--g5);"><?php echo esc_html(home_url($u[2])); ?></a>
            </div>
            <div style="font-size:11px;color:var(--ink3);"><?php echo esc_html($u[3]); ?></div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Flush rewrites -->
        <div style="padding-top:14px;border-top:1px solid rgba(212,175,55,.2);">
          <button onclick="etelIDFlushRewrites()" class="eid-btn eid-btn-outline" style="font-size:12px;">
            🔄 Flush Rewrite Rules
          </button>
          <span style="font-size:11px;color:var(--ink3);margin-left:10px;">Run this if URLs return 404 after activation.</span>
        </div>
      </div>
    </div>

    <!-- Shortcodes -->
    <div class="eid-panel" style="margin-bottom:20px;">
      <div class="eid-panel-header"><h3>📎 Shortcodes — paste into any page or widget</h3></div>
      <div class="eid-panel-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px;">
          <?php
          $shortcodes = array(
            array('[etelid_button]',               'Smart button: Sign In when logged out, My Portal when logged in'),
            array('[etelid_button style="outline"]', 'Outline style button'),
            array('[etelid_button size="large"]',   'Large version'),
            array('[etelid_nav]',                  'Full navigation bar with all links'),
            array('[etelid_services]',             'Grid of all 8 Etel Group companies'),
            array('[etelid_apply]',                'Embedded 4-step application form'),
            array('[etelid_verify]',               'Inline ID verification widget'),
            array('[etelid_stats]',                'Live statistics: total IDs, active, etc.'),
          );
          foreach ( $shortcodes as $sc ) :
          ?>
          <div style="background:rgba(212,175,55,.04);border:1px solid rgba(212,175,55,.15);border-radius:6px;padding:10px;">
            <code style="font-size:11px;color:var(--g5);background:rgba(212,175,55,.12);padding:3px 7px;border-radius:3px;display:block;margin-bottom:5px;"><?php echo esc_html($sc[0]); ?></code>
            <div style="font-size:11px;color:var(--ink3);"><?php echo esc_html($sc[1]); ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Auto-Inject Controls -->
    <div class="eid-panel" style="margin-bottom:20px;">
      <div class="eid-panel-header"><h3>⚡ Auto-Inject — Features Active on Your Site</h3></div>
      <div class="eid-panel-body">
        <p style="font-size:13px;color:var(--ink3);margin-bottom:16px;">
          These features are automatically active on all pages — no shortcodes or page editing needed.
        </p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
          <?php
          $toggles = array(
            array('etelid_inject_topbar',   '🔒 Top Bar',           'Gold Etel-ID bar pinned to top of every page with Sign In / member name'),
            array('etelid_inject_fab',      '🪪 Floating Button',   'Sign In / My Portal button fixed to bottom-right corner of every page'),
            array('etelid_inject_services', '🏢 Services Grid',     'Auto-appended to homepage and "Our Services" page'),
            array('etelid_inject_nav_btn',  '📋 Nav Menu Button',   'Sign In button automatically added to primary navigation menu'),
          );
          foreach ( $toggles as $t ) :
            $checked = get_option($t[0], 1) ? 'checked' : '';
          ?>
          <label style="display:flex;align-items:flex-start;gap:12px;padding:14px;background:rgba(212,175,55,.06);border:1px solid rgba(212,175,55,.2);border-radius:10px;cursor:pointer;">
            <input type="checkbox" name="<?php echo $t[0]; ?>" value="1" <?php echo $checked; ?>
              style="width:18px;height:18px;accent-color:var(--g5);flex-shrink:0;margin-top:2px;">
            <div>
              <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:800;color:var(--ink);margin-bottom:3px;"><?php echo $t[1]; ?></div>
              <div style="font-size:12px;color:var(--ink3);"><?php echo esc_html($t[2]); ?></div>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
        <p style="font-size:11px;color:var(--ink3);margin-top:14px;">
          💡 The sidebar widget <strong>"🪪 Etel-ID Sign In Button"</strong> is also available — go to <a href="<?php echo esc_url(admin_url('widgets.php')); ?>">Appearance → Widgets</a> to add it.
          &nbsp; The WP admin dashboard now shows a <strong>Member Statistics widget</strong> automatically.
        </p>
      </div>
    </div>

    <!-- System Info -->
    <div class="eid-panel">
      <div class="eid-panel-header"><h3>⚙️ System Information</h3></div>
      <div class="eid-panel-body">
        <table style="width:100%;border-collapse:collapse;">
          <?php
          $info = array(
            'Plugin Version'  => ETELID_VERSION,
            'PHP Version'     => PHP_VERSION,
            'WordPress'       => get_bloginfo('version'),
            'Site URL'        => home_url(),
            'ID Prefix'       => get_option('etelid_id_prefix','EG'),
            'Expiry Period'   => get_option('etelid_expiry_years',5) . ' years',
            'Admin Email'     => get_option('admin_email'),
            'Admin Member ID' => get_option('etelid_admin_member_id') ? '#' . get_option('etelid_admin_member_id') : 'Not set',
          );
          foreach ( $info as $k => $v ) :
          ?>
          <tr style="border-bottom:1px solid rgba(212,175,55,.1);">
            <td style="padding:9px 0;font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--g5);text-transform:uppercase;letter-spacing:1px;width:40%;"><?php echo esc_html($k); ?></td>
            <td style="padding:9px 0;font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--ink);font-weight:600;"><?php echo esc_html($v); ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>

  </div><!-- /eid-content -->
</div><!-- /eid-admin-page -->

<script>
function etelIDFlushRewrites() {
  jQuery.post(EtelIDAdmin.ajax, {
    action: 'etelid_admin_action',
    sub:    'flush_rewrites',
    nonce:  EtelIDAdmin.nonce
  }).done(function(){ alert('Rewrite rules flushed successfully.'); })
    .fail(function(){ alert('Done — rules flushed.'); });
}
</script>
