<?php
/**
 * WordPress Admin: registers the Gas Queue menu, Settings page, and Controllers page.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Admin menu ─────────────────────────────────────────────────
add_action( 'admin_menu', function () {
    add_menu_page(
        'Madeya Gas Queue', 'Gas Queue', 'manage_options',
        'madeya-queue', 'madeya_admin_settings_page',
        'dashicons-car', 25
    );
    add_submenu_page(
        'madeya-queue', 'Station Settings', 'Station Settings',
        'manage_options', 'madeya-queue', 'madeya_admin_settings_page'
    );
    add_submenu_page(
        'madeya-queue', 'Controllers', 'Controllers',
        'manage_options', 'madeya-controllers', 'madeya_admin_controllers_page'
    );
    add_submenu_page(
        'madeya-queue', 'Admin Credentials', '🔐 Admin Credentials',
        'manage_options', 'madeya-credentials', 'madeya_admin_credentials_page'
    );
    add_submenu_page(
        'madeya-queue', 'Release Dashboard', '🚀 Release Update',
        'manage_options', 'madeya-release', 'madeya_release_dashboard_page'
    );
} );

// ── Settings page ──────────────────────────────────────────────
function madeya_admin_settings_page() {
    if ( isset( $_POST['madeya_save_settings'] ) && check_admin_referer( 'madeya_settings' ) ) {
        $types  = array_map( 'sanitize_text_field', $_POST['allowed_types'] ?? array() );
        $all_keys = array( 'bajaj', 'qute', 'l_moto', 'private_moto', 'govt_moto', 'private_car', 'minibus', 'damas' );
        $labels = array();
        foreach ( $all_keys as $k ) {
            $labels[ $k ] = sanitize_text_field( $_POST[ 'type_label_' . $k ] ?? ucfirst( $k ) );
        }
        update_option( 'madeya_station_settings', array(
            'station_name'   => sanitize_text_field( $_POST['station_name'] ?? 'Madeya Gas Station' ),
            'allowed_parity' => sanitize_text_field( $_POST['allowed_parity'] ?? 'both' ),
            'allowed_types'  => $types,
            'type_labels'    => $labels,
        ) );
        echo '<div class="notice notice-success is-dismissible"><p><strong>Settings saved.</strong></p></div>';
    }

    $cfg = madeya_get_settings();
    global $wpdb;
    $qt      = $wpdb->prefix . 'madeya_queue';
    $rt      = $wpdb->prefix . 'madeya_report';
    $today   = date( 'Y-m-d' );
    $inQueue = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$qt}" );
    $served  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rt} WHERE report_date=%s", $today ) );

    $all_types = array(
        'bajaj'        => array( 'label' => 'ባጃጅ (Bajaj)',                        'icon' => '🛺' ),
        'qute'         => array( 'label' => 'ኪዪት (Qute)',                         'icon' => '🚗' ),
        'l_moto'       => array( 'label' => 'L ሞተር ሳይክል (L Motorcycle)',          'icon' => '🏍️' ),
        'private_moto' => array( 'label' => 'የግል ሞተር ሳይክል (Private Motorcycle)',  'icon' => '🏍️' ),
        'govt_moto'    => array( 'label' => 'የመንግስት ሞተር ሳይክል (Govt Motorcycle)', 'icon' => '🏍️' ),
        'private_car'  => array( 'label' => 'የቤት መኪና (Private Car)',              'icon' => '🚙' ),
        'minibus'      => array( 'label' => 'ሚንባስ ታክስ (Minibus Taxi)',            'icon' => '🚐' ),
        'damas'        => array( 'label' => 'ዳማስ (Damas)',                        'icon' => '🚙' ),
    );
    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px;">
            <span style="background:#F5A623;color:#000;padding:4px 12px;border-radius:4px;font-weight:700;letter-spacing:2px;">MADEYA</span>
            Station Settings
        </h1>

        <div style="display:flex;gap:12px;margin:16px 0;flex-wrap:wrap;">
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px 24px;text-align:center;border-top:4px solid #F5A623;">
                <b style="display:block;font-size:28px;color:#F5A623"><?php echo $inQueue; ?></b>
                <span style="font-size:11px;color:#888;text-transform:uppercase">In Queue Now</span>
            </div>
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px 24px;text-align:center;border-top:4px solid #27AE60;">
                <b style="display:block;font-size:28px;color:#27AE60"><?php echo $served; ?></b>
                <span style="font-size:11px;color:#888;text-transform:uppercase">Served Today</span>
            </div>
        </div>

        <form method="post">
            <?php wp_nonce_field( 'madeya_settings' ); ?>
            <input type="hidden" name="madeya_save_settings" value="1">

            <h2>Station Name</h2>
            <input type="text" name="station_name" value="<?php echo esc_attr( $cfg['station_name'] ); ?>"
                style="width:360px;padding:8px 12px;border:1px solid #ccc;border-radius:6px;font-size:15px">

            <h2 style="margin-top:24px">Plate Parity Rule <small style="font-weight:400;color:#666">(ሙሉ / ጎዶሎ)</small></h2>
            <p style="color:#666;margin-bottom:10px">Select which plate numbers are served at this station today.</p>
            <?php
            $parity_options = array(
                'both' => '✅ Both Even (ሙሉ) and Odd (ጎዶሎ)',
                'even' => '🔵 Even only — ሙሉ (last digit: 0, 2, 4, 6, 8)',
                'odd'  => '🟠 Odd only — ጎዶሎ (last digit: 1, 3, 5, 7, 9)',
            );
            foreach ( $parity_options as $val => $lbl ) :
                $checked = $cfg['allowed_parity'] === $val ? 'checked' : ''; ?>
            <label style="display:block;margin-bottom:10px;font-size:15px;cursor:pointer;">
                <input type="radio" name="allowed_parity" value="<?php echo $val; ?>" <?php echo $checked; ?> style="margin-right:8px">
                <?php echo $lbl; ?>
            </label>
            <?php endforeach; ?>

            <h2 style="margin-top:24px">Vehicle Types Served Today</h2>
            <p style="color:#666;margin-bottom:12px">Check which vehicle types this station accepts today.</p>
            <table class="widefat" style="max-width:600px;margin-bottom:16px">
                <thead>
                    <tr><th style="width:40px">Serve?</th><th>Vehicle Type</th><th>Display Name</th></tr>
                </thead>
                <tbody>
                <?php foreach ( $all_types as $key => $info ) :
                    $checked   = in_array( $key, $cfg['allowed_types'] ) ? 'checked' : '';
                    $cur_label = $cfg['type_labels'][ $key ] ?? $info['label'];
                ?>
                <tr>
                    <td style="text-align:center">
                        <input type="checkbox" name="allowed_types[]" value="<?php echo $key; ?>" <?php echo $checked; ?> id="vt_<?php echo $key; ?>">
                    </td>
                    <td>
                        <label for="vt_<?php echo $key; ?>" style="cursor:pointer">
                            <?php echo $info['icon']; ?> <strong><?php echo $key; ?></strong>
                        </label>
                    </td>
                    <td>
                        <input type="text" name="type_label_<?php echo $key; ?>" value="<?php echo esc_attr( $cur_label ); ?>"
                            style="width:200px;padding:5px 9px;border:1px solid #ccc;border-radius:4px">
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php submit_button( 'Save Settings', 'primary', 'submit', true,
                array( 'style' => 'background:#F5A623;border-color:#c47f10;color:#000;font-weight:700;font-size:14px;' ) ); ?>
        </form>

        <hr>
        <h2>Shortcodes</h2>
        <table class="widefat" style="max-width:500px">
            <tr><th>Page</th><th>Shortcode</th></tr>
            <tr><td>Controller Panel</td><td><code>[madeya_controller]</code></td></tr>
            <tr><td>Driver Queue Page</td><td><code>[madeya_driver]</code></td></tr>
            <tr><td>Admin Panel</td><td><code>[madeya_admin]</code></td></tr>
        </table>
    </div>
    <?php
}

// ── Controllers page ───────────────────────────────────────────
function madeya_admin_controllers_page() {
    global $wpdb;
    $ct = $wpdb->prefix . 'madeya_controllers';

    if ( isset( $_POST['add_ctrl'] ) && check_admin_referer( 'madeya_add_ctrl' ) ) {
        $un      = sanitize_text_field( $_POST['username']      ?? '' );
        $pw      = $_POST['password'] ?? '';
        $name    = sanitize_text_field( $_POST['full_name']     ?? '' );
        $station = sanitize_text_field( $_POST['station_name']  ?? '' );
        $vtype   = sanitize_text_field( $_POST['vehicle_type']  ?? '' );
        $parity  = sanitize_text_field( $_POST['allowed_parity'] ?? 'both' );

        if ( ! $station || ! $vtype ) {
            echo '<div class="notice notice-error"><p>Station and vehicle type required.</p></div>';
        } elseif ( $un && $pw ) {
            if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$ct} WHERE username=%s", $un ) ) ) {
                echo '<div class="notice notice-error"><p>Username already exists.</p></div>';
            } else {
                $wpdb->insert( $ct, array(
                    'username'       => $un,
                    'pass_hash'      => password_hash( $pw, PASSWORD_DEFAULT ),
                    'full_name'      => $name,
                    'station_name'   => $station,
                    'vehicle_type'   => $vtype,
                    'allowed_parity' => $parity,
                ) );
                echo '<div class="notice notice-success"><p>Controller added.</p></div>';
            }
        }
    }

    if ( isset( $_GET['del'] ) && check_admin_referer( 'del_' . intval( $_GET['del'] ) ) ) {
        $wpdb->delete( $ct, array( 'id' => intval( $_GET['del'] ) ), array( '%d' ) );
        echo '<div class="notice notice-success"><p>Controller removed.</p></div>';
    }

    $ctrls = $wpdb->get_results( "SELECT id, username, full_name, station_name, vehicle_type, allowed_parity FROM {$ct} ORDER BY id ASC" );
    ?>
    <div class="wrap">
        <h1>Manage Controllers</h1>
        <h2>Add New Controller</h2>
        <form method="post" style="max-width:480px">
            <?php wp_nonce_field( 'madeya_add_ctrl' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="new-username">Username</label></th>
                    <td><input type="text" id="new-username" name="username" required style="width:220px"></td>
                </tr>
                <tr>
                    <th><label for="new-password">Password</label></th>
                    <td><input type="password" id="new-password" name="password" required style="width:220px"></td>
                </tr>
                <tr>
                    <th><label for="new-fullname">Full Name</label></th>
                    <td><input type="text" id="new-fullname" name="full_name" style="width:220px"></td>
                </tr>
                <tr>
                    <th><label for="new-station">Station Name</label></th>
                    <td>
                        <input type="text" id="new-station" name="station_name" value="Madeya Gas Station" style="width:220px">
                        <p class="description">e.g. NOK #1, Total Mobile, Habasha</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="new-vtype">Vehicle Type Served</label></th>
                    <td>
                        <select id="new-vtype" name="vehicle_type" style="width:224px">
                            <option value="bajaj">ባጃጅ (Bajaj)</option>
                            <option value="qute">ኪዪት (Qute)</option>
                            <option value="l_moto">L ሞተር ሳይክል (L Motorcycle)</option>
                            <option value="private_moto">የግል ሞተር ሳይክል (Private Motorcycle)</option>
                            <option value="govt_moto">የመንግስት ሞተር ሳይክል (Govt Motorcycle)</option>
                            <option value="private_car">የቤት መኪና (Private Car)</option>
                            <option value="minibus">ሚንባስ ታክስ (Minibus Taxi)</option>
                            <option value="damas">ዳማስ (Damas)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="new-parity">Plate Parity</label></th>
                    <td>
                        <select id="new-parity" name="allowed_parity" style="width:224px">
                            <option value="both">Both ሙሉ &amp; ጎዶሎ</option>
                            <option value="even">ሙሉ Only (Even — 0,2,4,6,8)</option>
                            <option value="odd">ጎዶሎ Only (Odd — 1,3,5,7,9)</option>
                        </select>
                    </td>
                </tr>
            </table>
            <input type="hidden" name="add_ctrl" value="1">
            <?php submit_button( 'Add Controller' ); ?>
        </form>

        <h2>Current Controllers</h2>
        <table class="widefat" style="max-width:700px">
            <thead>
                <tr><th>ID</th><th>Username</th><th>Full Name</th><th>Station</th><th>Vehicle</th><th>Parity</th><th>Action</th></tr>
            </thead>
            <tbody>
            <?php foreach ( $ctrls as $c ) : ?>
            <tr>
                <td><?php echo $c->id; ?></td>
                <td><strong><?php echo esc_html( $c->username ); ?></strong></td>
                <td><?php echo esc_html( $c->full_name ); ?></td>
                <td><?php echo esc_html( $c->station_name ?? '—' ); ?></td>
                <td><?php echo esc_html( $c->vehicle_type ?? '—' ); ?></td>
                <td><?php echo esc_html( $c->allowed_parity ?? 'both' ); ?></td>
                <td>
                    <a href="<?php echo wp_nonce_url(
                        add_query_arg( array( 'page' => 'madeya-controllers', 'del' => $c->id ), admin_url( 'admin.php' ) ),
                        'del_' . $c->id
                    ); ?>" onclick="return confirm('Remove this controller?')" style="color:red">Remove</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// ── Admin Credentials Page ─────────────────────────────────────
function madeya_admin_credentials_page() {
    $current_user   = get_option( 'madeya_admin_username', 'madeya_admin' );
    $current_email  = get_option( 'madeya_admin_email', '' );
    $last_reset     = get_option( 'madeya_admin_last_reset', '' );
    $nonce          = wp_create_nonce( 'madeya_wp_creds_nonce' );
    $ajaxurl        = admin_url( 'admin-ajax.php' );
    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px;">
            <span style="background:#8e44ad;color:#fff;padding:4px 12px;border-radius:4px;font-weight:700;font-size:14px;">🔐</span>
            ETEL MADEYA — Admin Panel Credentials
        </h1>
        <p style="color:#666;max-width:660px;margin-bottom:0;">
            Manage the username, recovery email, and password for the
            <code>[madeya_admin]</code> shortcode login. Changes take effect immediately.
            The recovery email is used for the <strong>Forgot Password</strong> flow on the admin login page.
        </p>

        <?php if ( $last_reset ) : ?>
        <div class="notice notice-info" style="margin-top:12px;">
            <p><strong>Last credential change:</strong> <?php echo esc_html( $last_reset ); ?></p>
        </div>
        <?php endif; ?>

        <div id="cred-notice" style="display:none;margin:14px 0;padding:12px 16px;border-radius:6px;font-weight:600;max-width:900px;"></div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;max-width:900px;margin-top:20px;">

            <!-- Current state -->
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;border-top:4px solid #8e44ad;">
                <h2 style="margin-top:0;color:#8e44ad;">Current Configuration</h2>
                <table class="form-table" style="margin:0;">
                    <tr>
                        <th style="padding:8px 0;width:140px;">Username</th>
                        <td><code style="font-size:14px;background:#f5f0fa;padding:3px 8px;border-radius:4px;"><?php echo esc_html( $current_user ); ?></code></td>
                    </tr>
                    <tr>
                        <th style="padding:8px 0;">Recovery Email</th>
                        <td>
                            <?php if ( $current_email ) : ?>
                                <code style="font-size:13px;background:#f0f9f4;padding:3px 8px;border-radius:4px;"><?php echo esc_html( $current_email ); ?></code>
                                <span style="color:#27AE60;font-size:12px;margin-left:6px;display:block;margin-top:3px;">✓ Forgot Password flow is active</span>
                            <?php else : ?>
                                <span style="color:#C0392B;font-weight:600;">⚠ Not set — add one to enable Forgot Password</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th style="padding:8px 0;">Password</th>
                        <td><span style="color:#888;">●●●●●●●● (stored as bcrypt hash)</span></td>
                    </tr>
                </table>
            </div>

            <!-- Update credentials -->
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;border-top:4px solid #F5A623;">
                <h2 style="margin-top:0;color:#c47f10;">Update Credentials</h2>
                <p style="color:#888;font-size:13px;margin-bottom:16px;">Leave password blank to keep current password unchanged.</p>
                <table class="form-table" style="margin:0;">
                    <tr>
                        <th style="padding:8px 0;width:140px;"><label for="cred-user">Username</label></th>
                        <td><input type="text" id="cred-user" value="<?php echo esc_attr( $current_user ); ?>"
                            style="width:100%;padding:7px 10px;border:1px solid #ccc;border-radius:5px;font-size:14px;" autocomplete="username"></td>
                    </tr>
                    <tr>
                        <th style="padding:8px 0;"><label for="cred-email">Recovery Email</label></th>
                        <td><input type="email" id="cred-email" value="<?php echo esc_attr( $current_email ); ?>"
                            placeholder="Used for Forgot Password emails"
                            style="width:100%;padding:7px 10px;border:1px solid #ccc;border-radius:5px;font-size:14px;" autocomplete="email"></td>
                    </tr>
                    <tr>
                        <th style="padding:8px 0;"><label for="cred-pass">New Password</label></th>
                        <td>
                            <input type="password" id="cred-pass"
                                placeholder="Leave blank to keep current"
                                style="width:100%;padding:7px 10px;border:1px solid #ccc;border-radius:5px;font-size:14px;"
                                autocomplete="new-password" oninput="credStrength(this.value)">
                            <div style="height:4px;border-radius:2px;background:#eee;overflow:hidden;margin-top:5px;">
                                <div id="cred-sbar-fill" style="height:100%;width:0;border-radius:2px;transition:all .3s;"></div>
                            </div>
                            <div id="cred-slbl" style="font-size:11px;color:#888;margin-top:3px;"></div>
                        </td>
                    </tr>
                    <tr>
                        <th style="padding:8px 0;"><label for="cred-pass2">Confirm Password</label></th>
                        <td><input type="password" id="cred-pass2"
                            placeholder="Repeat new password"
                            style="width:100%;padding:7px 10px;border:1px solid #ccc;border-radius:5px;font-size:14px;" autocomplete="new-password"></td>
                    </tr>
                </table>
                <div style="margin-top:16px;">
                    <button id="cred-save-btn" type="button" onclick="credSave()"
                        style="background:linear-gradient(135deg,#8e44ad,#6c3483);color:#fff;border:none;padding:10px 24px;border-radius:6px;font-size:14px;font-weight:700;letter-spacing:1px;cursor:pointer;">
                        💾 Save Credentials
                    </button>
                </div>
            </div>
        </div>

        <!-- Security tips -->
        <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;max-width:900px;margin-top:20px;border-left:4px solid #2980B9;">
            <h3 style="margin-top:0;color:#2980B9;">🛡 Security Tips</h3>
            <ul style="color:#555;font-size:13px;line-height:1.9;margin:0;padding-left:20px;">
                <li>Use a <strong>strong password</strong> — at least 12 characters with uppercase, numbers, and symbols.</li>
                <li>Keep your <strong>recovery email up to date</strong> — it is the only way to reset a forgotten password using the Forgot Password flow.</li>
                <li>The <code>[madeya_admin]</code> page should be <strong>access-restricted</strong> (known IPs or a private page) in production.</li>
                <li>Every credential change sends a <strong>confirmation email</strong> to the registered recovery address.</li>
                <li>The Forgot Password verification code expires in <strong>15 minutes</strong> and is limited to <strong>5 incorrect attempts</strong>.</li>
            </ul>
        </div>
    </div>

    <script>
    function credSave() {
        var user  = document.getElementById('cred-user').value.trim();
        var email = document.getElementById('cred-email').value.trim();
        var pass  = document.getElementById('cred-pass').value;
        var pass2 = document.getElementById('cred-pass2').value;
        var btn   = document.getElementById('cred-save-btn');

        if (!user)                   { credNotice('error', 'Username cannot be empty.'); return; }
        if (pass && pass.length < 8) { credNotice('error', 'Password must be at least 8 characters.'); return; }
        if (pass && pass !== pass2)  { credNotice('error', 'Passwords do not match.'); return; }

        btn.disabled    = true;
        btn.textContent = '⏳ Saving...';

        var fd = new FormData();
        fd.append('action',           'madeya_wp_admin_save_creds');
        fd.append('nonce',            '<?php echo esc_js( $nonce ); ?>');
        fd.append('new_username',     user);
        fd.append('new_email',        email);
        fd.append('new_password',     pass);
        fd.append('confirm_password', pass2);

        fetch('<?php echo esc_js( $ajaxurl ); ?>', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(r) {
                btn.disabled    = false;
                btn.textContent = '💾 Save Credentials';
                if (r.success) {
                    credNotice('success', '✅ ' + r.data.message);
                    document.getElementById('cred-pass').value  = '';
                    document.getElementById('cred-pass2').value = '';
                    document.getElementById('cred-sbar-fill').style.width = '0';
                    document.getElementById('cred-slbl').textContent = '';
                } else {
                    credNotice('error', '❌ ' + (r.data && r.data.message ? r.data.message : 'Save failed.'));
                }
            })
            .catch(function() {
                btn.disabled    = false;
                btn.textContent = '💾 Save Credentials';
                credNotice('error', '❌ Network error.');
            });
    }

    function credNotice(type, msg) {
        var el       = document.getElementById('cred-notice');
        el.style.display  = 'block';
        el.style.background = type === 'success' ? 'rgba(39,174,96,.1)' : 'rgba(192,57,43,.08)';
        el.style.border     = '1px solid ' + (type === 'success' ? 'rgba(39,174,96,.3)' : 'rgba(192,57,43,.3)');
        el.style.color      = type === 'success' ? '#27AE60' : '#C0392B';
        el.textContent = msg;
        setTimeout(function() { el.style.display = 'none'; }, 6000);
    }

    function credStrength(v) {
        var score = 0;
        if (v.length >= 8)           score++;
        if (v.length >= 12)          score++;
        if (/[A-Z]/.test(v))         score++;
        if (/[0-9]/.test(v))         score++;
        if (/[^A-Za-z0-9]/.test(v)) score++;
        var colors = ['#C0392B', '#E67E22', '#F5A623', '#27AE60', '#1abc9c'];
        var labels = ['Very Weak', 'Weak', 'Fair', 'Strong', 'Very Strong'];
        var fill = document.getElementById('cred-sbar-fill');
        var lbl  = document.getElementById('cred-slbl');
        fill.style.width      = Math.min(100, score * 20) + '%';
        fill.style.background = v ? (colors[Math.max(0, score-1)] || '#eee') : '#eee';
        lbl.textContent       = v ? (labels[Math.max(0, score-1)] || '') : '';
        lbl.style.color       = v ? (colors[Math.max(0, score-1)] || '#888') : '#888';
    }
    </script>
    <?php
}

// ── Release Dashboard registered in main admin_menu above ──

// ── AJAX: publish a new release ────────────────────────────────
add_action( 'wp_ajax_madeya_publish_release', 'madeya_ajax_publish_release' );

function madeya_ajax_publish_release() {
    check_ajax_referer( 'madeya_release_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Permission denied.' ) );
    }

    $new_version  = sanitize_text_field( $_POST['version']     ?? '' );
    $changelog    = wp_kses_post( $_POST['changelog']          ?? '' );
    $download_url = esc_url_raw( $_POST['download_url']        ?? '' );
    $requires     = sanitize_text_field( $_POST['requires']    ?? '5.5' );
    $tested       = sanitize_text_field( $_POST['tested']      ?? '6.6' );
    $requires_php = sanitize_text_field( $_POST['requires_php']?? '7.4' );

    if ( ! $new_version || ! preg_match( '/^\d+\.\d+(\.\d+)?$/', $new_version ) ) {
        wp_send_json_error( array( 'message' => 'Invalid version number. Use format: 3.1 or 3.1.2' ) );
    }
    if ( ! $download_url ) {
        wp_send_json_error( array( 'message' => 'Download URL is required.' ) );
    }

    // Check version is actually newer
    $current = MADEYA_VERSION;
    if ( version_compare( $current, $new_version, '>=' ) ) {
        wp_send_json_error( array(
            'message' => "New version ({$new_version}) must be higher than current ({$current}).",
        ) );
    }

    // Build the JSON payload
    $json = array(
        'version'      => $new_version,
        'download_url' => $download_url,
        'requires'     => $requires,
        'tested'       => $tested,
        'requires_php' => $requires_php,
        'last_updated' => current_time( 'Y-m-d' ),
        'changelog'    => $changelog,
    );

    // Write the JSON file to the plugin folder
    $json_path = MADEYA_PLUGIN_DIR . 'madeya-update.json';
    $written   = file_put_contents( $json_path, wp_json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );

    if ( $written === false ) {
        wp_send_json_error( array(
            'message' => 'Could not write madeya-update.json. Check file permissions on the plugin folder.',
            'path'    => $json_path,
        ) );
    }

    // Store release history
    $history   = get_option( 'madeya_release_history', array() );
    $history[] = array(
        'version'   => $new_version,
        'date'      => current_time( 'mysql' ),
        'changelog' => $changelog,
        'url'       => $download_url,
        'by'        => wp_get_current_user()->user_login,
    );
    // Keep last 20 releases
    update_option( 'madeya_release_history', array_slice( $history, -20 ) );

    // Clear the update cache so WordPress checks immediately
    delete_transient( 'madeya_update_cache_' . md5( MADEYA_UPDATE_URL ) );
    delete_site_transient( 'update_plugins' );

    wp_send_json_success( array(
        'message'      => "Release {$new_version} published successfully!",
        'json_path'    => str_replace( ABSPATH, '/', $json_path ),
        'json_url'     => MADEYA_UPDATE_URL,
        'next_step'    => "Now upload madeya-gas-queue.zip to: {$download_url}",
    ) );
}

// ── AJAX: fetch current remote JSON for comparison ─────────────
add_action( 'wp_ajax_madeya_fetch_remote_json', 'madeya_ajax_fetch_remote_json' );

function madeya_ajax_fetch_remote_json() {
    check_ajax_referer( 'madeya_release_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

    $resp = wp_remote_get( MADEYA_UPDATE_URL, array( 'timeout' => 8 ) );
    if ( is_wp_error( $resp ) ) {
        wp_send_json_error( array( 'message' => $resp->get_error_message() ) );
    }
    $body = wp_remote_retrieve_body( $resp );
    $data = json_decode( $body, true );
    if ( ! $data ) {
        wp_send_json_error( array( 'message' => 'Could not parse remote JSON. Check the URL.' ) );
    }
    wp_send_json_success( $data );
}

// ── Release Dashboard page HTML ────────────────────────────────
function madeya_release_dashboard_page() {
    $current_ver = MADEYA_VERSION;
    $update_url  = MADEYA_UPDATE_URL;
    $history     = array_reverse( (array) get_option( 'madeya_release_history', array() ) );
    $nonce       = wp_create_nonce( 'madeya_release_nonce' );
    $ajax_url    = admin_url( 'admin-ajax.php' );
    $json_path   = MADEYA_PLUGIN_DIR . 'madeya-update.json';
    $json_data   = file_exists( $json_path ) ? json_decode( file_get_contents( $json_path ), true ) : array();
    $file_ver    = $json_data['version'] ?? '—';
    $json_writable = is_writable( $json_path ) || is_writable( MADEYA_PLUGIN_DIR );

    // Guess next version
    $parts = explode( '.', $current_ver );
    $parts[ count($parts) - 1 ] = (int) end( $parts ) + 1;
    $suggested_next = implode( '.', $parts );

    // Default download URL from JSON
    $default_dl = $json_data['download_url'] ?? 'https://ethelmarket.com/updates/madeya-gas-queue.zip';
    ?>
    <div class="wrap" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;max-width:860px;">

     <!-- Header -->
     <div style="background:linear-gradient(135deg,#07080d,#0e1220);border:1px solid #272f3d;border-radius:12px;padding:22px 28px;margin-bottom:20px;display:flex;align-items:center;gap:16px;">
      <img src="<?php echo esc_url( MADEYA_PLUGIN_URL . 'assets/images/etel-madeya-logo-sm.webp' ); ?>" style="height:52px;width:auto;" alt="ETEL MADEYA">
      <div>
       <div style="font-size:20px;font-weight:900;color:#F5A623;letter-spacing:2px;">ETEL MADEYA</div>
       <div style="font-size:11px;color:#2980B9;font-weight:700;letter-spacing:1px;">🚀 Release Dashboard — Publish Updates From Your Phone or Browser</div>
      </div>
      <div style="margin-left:auto;text-align:right;">
       <div style="font-size:10px;color:#4d5e78;text-transform:uppercase;letter-spacing:1px;">Installed Version</div>
       <div style="font-family:'Courier New',monospace;font-size:22px;font-weight:700;color:#F5A623;">v<?php echo esc_html( $current_ver ); ?></div>
      </div>
     </div>

     <!-- Status bar -->
     <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:20px;">
      <div style="background:#11141a;border:1px solid #272f3d;border-radius:10px;padding:14px 16px;">
       <div style="font-size:10px;color:#4d5e78;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:5px;">Plugin (installed)</div>
       <div style="font-family:'Courier New',monospace;font-size:18px;font-weight:700;color:#F5A623;">v<?php echo esc_html( $current_ver ); ?></div>
      </div>
      <div style="background:#11141a;border:1px solid #272f3d;border-radius:10px;padding:14px 16px;">
       <div style="font-size:10px;color:#4d5e78;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:5px;">Update JSON (local file)</div>
       <div style="font-family:'Courier New',monospace;font-size:18px;font-weight:700;color:<?php echo $file_ver === $current_ver ? '#27AE60' : '#F5A623'; ?>;">v<?php echo esc_html( $file_ver ); ?></div>
      </div>
      <div style="background:#11141a;border:1px solid #272f3d;border-radius:10px;padding:14px 16px;">
       <div style="font-size:10px;color:#4d5e78;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:5px;">File Write Access</div>
       <div style="font-size:15px;font-weight:700;color:<?php echo $json_writable ? '#27AE60' : '#C0392B'; ?>;">
        <?php echo $json_writable ? '✓ Ready to publish' : '✗ No write access — fix permissions'; ?>
       </div>
      </div>
     </div>

     <?php if ( ! $json_writable ) : ?>
     <div style="background:rgba(192,57,43,.08);border:1px solid rgba(192,57,43,.3);border-radius:8px;padding:14px 18px;margin-bottom:18px;color:#C0392B;">
      <strong>⚠ Cannot write to plugin folder.</strong> SSH into your server and run:<br>
      <code style="background:rgba(0,0,0,.3);padding:4px 10px;border-radius:4px;display:inline-block;margin-top:6px;">
       chmod 755 <?php echo esc_html( MADEYA_PLUGIN_DIR ); ?>
      </code>
     </div>
     <?php endif; ?>

     <?php
     // Check if the JSON file exists on the remote server
     $remote_check = get_transient( 'madeya_server_reachable' );
     if ( $remote_check === false ) {
         $r = wp_remote_head( $update_url, array( 'timeout' => 5, 'sslverify' => false ) );
         $remote_check = ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200 ) ? 'yes' : 'no';
         set_transient( 'madeya_server_reachable', $remote_check, 300 );
     }
     $server_live = ( $remote_check === 'yes' );
     ?>

     <?php if ( ! $server_live ) : ?>
     <!-- Server not set up yet — show one-time setup guide -->
     <div style="background:rgba(245,166,35,.06);border:2px solid rgba(245,166,35,.3);border-radius:12px;padding:18px 20px;margin-bottom:20px;">
      <div style="font-size:14px;font-weight:800;color:#F5A623;margin-bottom:10px;">⚠ One-Time Server Setup Required</div>
      <p style="font-size:13px;color:#dde4f0;margin-bottom:12px;">
       The update server is not reachable yet at <code style="background:#1e2330;padding:2px 6px;border-radius:4px;"><?php echo esc_html( $update_url ); ?></code>.
       You need to do this <strong>once</strong> to activate remote updates.
      </p>
      <ol style="font-size:13px;color:#aab4c8;margin:0 0 0 18px;line-height:2.1;">
       <li>Go to <strong>cPanel → File Manager</strong> on your phone or computer</li>
       <li>Create a folder called <code style="background:#1e2330;padding:1px 5px;border-radius:3px;">updates</code> inside <code>public_html</code></li>
       <li>Upload <strong>two files</strong> into that folder:
        <ul style="margin:4px 0 4px 18px;">
         <li><code>madeya-update.json</code> — found inside your plugin folder</li>
         <li><code>madeya-gas-queue.zip</code> — your current plugin zip</li>
        </ul>
       </li>
       <li>Come back here and publish a release — updates will work automatically from then on</li>
      </ol>
      <div style="margin-top:12px;padding:10px 14px;background:#1e2330;border-radius:8px;font-size:11px;color:#4d5e78;">
       📁 Plugin folder on your server:
       <code style="color:#F5A623;"><?php echo esc_html( MADEYA_PLUGIN_DIR ); ?></code>
      </div>
     </div>
     <?php else : ?>
     <!-- Server is live — show normal release instructions -->
     <div style="background:rgba(39,174,96,.06);border:1px solid rgba(39,174,96,.2);border-radius:10px;padding:14px 18px;margin-bottom:20px;font-size:13px;color:#dde4f0;">
      <strong style="color:#27AE60;">✅ Update server is live!</strong> To release a new version:
      <ol style="margin:8px 0 0 18px;line-height:1.9;color:#aab4c8;">
       <li>Upload your new <code>madeya-gas-queue.zip</code> to <code><?php echo esc_html( $default_dl ); ?></code></li>
       <li>Fill in the form below and tap <strong>PUBLISH RELEASE</strong></li>
       <li>All WordPress sites with the plugin see the update within 12 hours</li>
      </ol>
     </div>
     <?php endif; ?>

     <!-- Upload & Install ZIP directly -->
     <div style="background:#11141a;border:2px solid rgba(245,166,35,.4);border-radius:12px;overflow:hidden;margin-bottom:20px;">
      <div style="background:linear-gradient(135deg,rgba(245,166,35,.15),rgba(245,166,35,.05));border-bottom:1px solid rgba(245,166,35,.3);padding:14px 20px;display:flex;align-items:center;gap:8px;">
       <span style="font-size:18px;">⬆️</span>
       <strong style="color:#F5A623;font-size:14px;letter-spacing:1px;">UPLOAD & INSTALL NEW VERSION</strong>
       <span style="margin-left:auto;font-size:11px;color:#4d5e78;">Upload your zip → click Install → done</span>
      </div>
      <div style="padding:22px 24px;">
       <div id="upload-notice" style="display:none;margin-bottom:16px;padding:13px 16px;border-radius:8px;font-weight:600;font-size:13px;"></div>
       <div style="border:2px dashed rgba(245,166,35,.3);border-radius:10px;padding:28px;text-align:center;margin-bottom:16px;cursor:pointer;transition:all .2s;"
            id="zip-drop-zone" onclick="document.getElementById('zip-file-input').click()"
            ondragover="event.preventDefault();this.style.borderColor='#F5A623'"
            ondragleave="this.style.borderColor='rgba(245,166,35,.3)'"
            ondrop="event.preventDefault();this.style.borderColor='rgba(245,166,35,.3)';handleZipDrop(event.dataTransfer.files[0])">
        <div style="font-size:32px;margin-bottom:8px;">📦</div>
        <div style="color:#F5A623;font-weight:700;font-size:14px;">Click or drag your ZIP file here</div>
        <div style="color:#4d5e78;font-size:12px;margin-top:4px;">madeya-gas-queue.zip</div>
        <div id="zip-file-name" style="margin-top:10px;font-size:13px;color:#27AE60;font-weight:700;display:none;"></div>
       </div>
       <input type="file" id="zip-file-input" accept=".zip" style="display:none" onchange="handleZipSelect(this.files[0])">
       <button id="zip-install-btn" type="button" onclick="mdInstallZip()" disabled
        style="background:linear-gradient(135deg,#27AE60,#1e8449);border:none;padding:14px 30px;border-radius:9px;color:#fff;font-weight:900;font-size:15px;letter-spacing:2px;cursor:pointer;width:100%;display:flex;align-items:center;justify-content:center;gap:8px;opacity:.5;">
        ⬆️ INSTALL UPDATE
       </button>
       <div style="margin-top:10px;font-size:11px;color:#4d5e78;text-align:center;">
        WordPress will replace the plugin files automatically. Your data and settings are never touched.
       </div>
      </div>
     </div>

     <!-- Publish form -->
     <div style="background:#11141a;border:1px solid #272f3d;border-radius:12px;overflow:hidden;margin-bottom:20px;">
      <div style="background:#181d26;border-bottom:1px solid #272f3d;padding:14px 20px;display:flex;align-items:center;gap:8px;">
       <span style="font-size:16px;">🚀</span>
       <strong style="color:#F5A623;font-size:14px;letter-spacing:1px;">PUBLISH NEW RELEASE</strong>
      </div>
      <div style="padding:22px 24px;">

       <div id="release-notice" style="display:none;margin-bottom:16px;padding:13px 16px;border-radius:8px;font-weight:600;font-size:13px;"></div>

       <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div>
         <label for="rel-version" style="display:block;font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#4d5e78;margin-bottom:5px;">New Version Number *</label>
         <input type="text" id="rel-version" value="<?php echo esc_attr( $suggested_next ); ?>"
          placeholder="e.g. 3.1" style="width:100%;padding:10px 13px;background:#1e2330;border:1px solid #272f3d;border-radius:7px;color:#dde4f0;font-family:'Courier New',monospace;font-size:16px;font-weight:700;">
        </div>
        <div>
         <label for="rel-date" style="display:block;font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#4d5e78;margin-bottom:5px;">Release Date</label>
         <input type="text" id="rel-date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"
          readonly style="width:100%;padding:10px 13px;background:#181d26;border:1px solid #272f3d;border-radius:7px;color:#4d5e78;font-family:'Courier New',monospace;font-size:14px;">
        </div>
       </div>

       <div style="margin-bottom:14px;">
        <label for="rel-url" style="display:block;font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#4d5e78;margin-bottom:5px;">Download URL (where you uploaded the zip) *</label>
        <input type="url" id="rel-url" value="<?php echo esc_attr( $default_dl ); ?>"
         style="width:100%;padding:10px 13px;background:#1e2330;border:1px solid #272f3d;border-radius:7px;color:#dde4f0;font-size:13px;">
       </div>

       <div style="margin-bottom:14px;">
        <label for="rel-changelog" style="display:block;font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#4d5e78;margin-bottom:5px;">What Changed in This Version *</label>
        <textarea id="rel-changelog" rows="5" placeholder="• Fixed driver map not showing&#10;• Added priority queue ordering&#10;• Improved fuel quota display"
         style="width:100%;padding:10px 13px;background:#1e2330;border:1px solid #272f3d;border-radius:7px;color:#dde4f0;font-size:13px;resize:vertical;line-height:1.6;"></textarea>
        <div style="font-size:10px;color:#4d5e78;margin-top:4px;">One change per line. Will be shown to site owners in the WP update popup.</div>
       </div>

       <details style="margin-bottom:18px;">
        <summary style="cursor:pointer;font-size:11px;color:#4d5e78;font-weight:600;letter-spacing:1px;text-transform:uppercase;">▶ Advanced settings (WP version compatibility)</summary>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-top:10px;">
         <div>
          <label for="rel-requires" style="display:block;font-size:10px;color:#4d5e78;margin-bottom:4px;">Requires WP</label>
          <input type="text" id="rel-requires" value="5.5" style="width:100%;padding:8px 10px;background:#1e2330;border:1px solid #272f3d;border-radius:6px;color:#dde4f0;font-size:13px;">
         </div>
         <div>
          <label for="rel-tested" style="display:block;font-size:10px;color:#4d5e78;margin-bottom:4px;">Tested up to WP</label>
          <input type="text" id="rel-tested" value="6.6" style="width:100%;padding:8px 10px;background:#1e2330;border:1px solid #272f3d;border-radius:6px;color:#dde4f0;font-size:13px;">
         </div>
         <div>
          <label for="rel-php" style="display:block;font-size:10px;color:#4d5e78;margin-bottom:4px;">Requires PHP</label>
          <input type="text" id="rel-php" value="7.4" style="width:100%;padding:8px 10px;background:#1e2330;border:1px solid #272f3d;border-radius:6px;color:#dde4f0;font-size:13px;">
         </div>
        </div>
       </details>

       <button id="rel-publish-btn" type="button" onclick="mdPublishRelease()"
        style="background:linear-gradient(135deg,#F5A623,#c47f10);border:none;padding:14px 30px;border-radius:9px;color:#000;font-weight:900;font-size:15px;letter-spacing:2px;cursor:pointer;width:100%;display:flex;align-items:center;justify-content:center;gap:8px;">
        🚀 PUBLISH RELEASE
       </button>
      </div>
     </div>

     <!-- Remote JSON check -->
     <div style="background:#11141a;border:1px solid #272f3d;border-radius:12px;overflow:hidden;margin-bottom:20px;">
      <div style="background:#181d26;border-bottom:1px solid #272f3d;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;">
       <strong style="color:#2980B9;font-size:13px;letter-spacing:1px;">🌐 LIVE UPDATE SERVER STATUS</strong>
       <button onclick="mdCheckRemote()" style="background:#1e2330;border:1px solid #272f3d;padding:6px 14px;border-radius:6px;color:#dde4f0;cursor:pointer;font-size:12px;font-weight:600;" id="check-remote-btn">
        ↻ CHECK NOW
       </button>
      </div>
      <div style="padding:16px 20px;" id="remote-status">
       <div style="color:#4d5e78;font-size:13px;">Click "CHECK NOW" to see what version your update server is currently serving to all sites.</div>
      </div>
     </div>

     <!-- Release history -->
     <?php if ( $history ) : ?>
     <div style="background:#11141a;border:1px solid #272f3d;border-radius:12px;overflow:hidden;">
      <div style="background:#181d26;border-bottom:1px solid #272f3d;padding:14px 20px;">
       <strong style="color:#dde4f0;font-size:13px;letter-spacing:1px;">📋 RELEASE HISTORY</strong>
      </div>
      <div style="padding:0;">
       <?php foreach ( $history as $i => $rel ) :
           $rel_date = isset( $rel['date'] ) ? date( 'M j, Y', strtotime( $rel['date'] ) ) : '—';
       ?>
       <div style="padding:12px 20px;<?php echo $i > 0 ? 'border-top:1px solid #1e2330;' : ''; ?>display:flex;align-items:flex-start;gap:12px;">
        <div style="background:<?php echo $i===0 ? '#F5A623' : '#272f3d'; ?>;color:<?php echo $i===0 ? '#000' : '#dde4f0'; ?>;font-family:'Courier New',monospace;font-weight:700;font-size:12px;padding:3px 10px;border-radius:6px;white-space:nowrap;flex-shrink:0;">
         v<?php echo esc_html( $rel['version'] ); ?>
        </div>
        <div style="flex:1;">
         <div style="font-size:11px;color:#4d5e78;margin-bottom:3px;">
          <?php echo esc_html( $rel_date ); ?>
          <?php if ( ! empty( $rel['by'] ) ) echo ' · by ' . esc_html( $rel['by'] ); ?>
         </div>
         <div style="font-size:12px;color:#aab4c8;line-height:1.5;">
          <?php
          // Show changelog as plain text lines
          $cl = strip_tags( $rel['changelog'] );
          $lines = array_filter( array_slice( explode( "\n", $cl ), 0, 3 ) );
          foreach ( $lines as $l ) echo '• ' . esc_html( trim( $l ) ) . '<br>';
          ?>
         </div>
        </div>
       </div>
       <?php endforeach; ?>
      </div>
     </div>
     <?php endif; ?>

    </div><!-- .wrap -->

    <script>
    function mdPublishRelease() {
     var version   = document.getElementById('rel-version').value.trim();
     var url       = document.getElementById('rel-url').value.trim();
     var clRaw     = document.getElementById('rel-changelog').value.trim();
     var requires  = document.getElementById('rel-requires').value.trim();
     var tested    = document.getElementById('rel-tested').value.trim();
     var php       = document.getElementById('rel-php').value.trim();
     var noticeEl  = document.getElementById('release-notice');
     var btn       = document.getElementById('rel-publish-btn');

     if(!version){ showRelNotice('error','Version number is required.'); return; }
     if(!url)    { showRelNotice('error','Download URL is required.'); return; }
     if(!clRaw)  { showRelNotice('error','Please describe what changed in this version.'); return; }

     // Convert plain text lines → HTML list
     var lines = clRaw.split('\n').map(function(l){ return l.replace(/^[•\-\*]\s*/,'').trim(); }).filter(Boolean);
     var changelog = '<h4>Version '+version+' — <?php echo esc_js( current_time( 'Y-m-d' ) ); ?></h4><ul>'
      + lines.map(function(l){ return '<li>'+l+'</li>'; }).join('') + '</ul>';

     btn.disabled = true;
     btn.textContent = '⏳ Publishing...';

     var fd = new FormData();
     fd.append('action',       'madeya_publish_release');
     fd.append('nonce',        '<?php echo esc_js( $nonce ); ?>');
     fd.append('version',      version);
     fd.append('download_url', url);
     fd.append('changelog',    changelog);
     fd.append('requires',     requires);
     fd.append('tested',       tested);
     fd.append('requires_php', php);

     fetch('<?php echo esc_js( $ajax_url ); ?>', {method:'POST', body:fd})
      .then(function(r){ return r.json(); })
      .then(function(r){
       btn.disabled = false;
       btn.textContent = '🚀 PUBLISH RELEASE';
       if(r.success){
        showRelNotice('success',
         '✅ ' + r.data.message + '\n\n📋 Next step: ' + r.data.next_step
        );
        // Auto-check remote after 2s
        setTimeout(mdCheckRemote, 2000);
       } else {
        showRelNotice('error', '❌ ' + (r.data && r.data.message ? r.data.message : 'Failed.'));
       }
      })
      .catch(function(){
       btn.disabled = false;
       btn.textContent = '🚀 PUBLISH RELEASE';
       showRelNotice('error', '❌ Network error. Please try again.');
      });
    }

    function mdCheckRemote() {
     var btn = document.getElementById('check-remote-btn');
     var box = document.getElementById('remote-status');
     btn.textContent = '⏳ Checking...';
     btn.disabled = true;

     var fd = new FormData();
     fd.append('action', 'madeya_fetch_remote_json');
     fd.append('nonce',  '<?php echo esc_js( $nonce ); ?>');

     fetch('<?php echo esc_js( $ajax_url ); ?>', {method:'POST', body:fd})
      .then(function(r){ return r.json(); })
      .then(function(r){
       btn.textContent = '↻ CHECK NOW';
       btn.disabled = false;
       if(!r.success){
        box.innerHTML = '<div style="color:#C0392B;font-size:13px;">❌ ' + (r.data&&r.data.message?r.data.message:'Could not reach update server.') + '</div>';
        return;
       }
       var d = r.data;
       var installed = '<?php echo esc_js( $current_ver ); ?>';
       var isNewer   = d.version && compareVer(d.version, installed) > 0;
       var isSame    = d.version && compareVer(d.version, installed) === 0;
       var statusCol = isNewer ? '#F5A623' : isSame ? '#27AE60' : '#C0392B';
       var statusTxt = isNewer ? '⚠ Server has a newer version than installed'
                     : isSame ? '✓ Server matches installed version'
                     : '⚠ Server has an older version than installed';
       box.innerHTML =
        '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:12px;">' +
         '<div style="background:#1e2330;border-radius:8px;padding:10px 12px;text-align:center;">' +
          '<div style="font-size:9px;color:#4d5e78;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Server Version</div>' +
          '<div style="font-family:monospace;font-size:18px;font-weight:700;color:#F5A623;">v'+(d.version||'—')+'</div>' +
         '</div>' +
         '<div style="background:#1e2330;border-radius:8px;padding:10px 12px;text-align:center;">' +
          '<div style="font-size:9px;color:#4d5e78;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Last Updated</div>' +
          '<div style="font-size:13px;font-weight:600;color:#dde4f0;">'+(d.last_updated||'—')+'</div>' +
         '</div>' +
         '<div style="background:#1e2330;border-radius:8px;padding:10px 12px;text-align:center;">' +
          '<div style="font-size:9px;color:#4d5e78;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Download URL</div>' +
          '<div style="font-size:10px;color:#2980B9;word-break:break-all;">'+(d.download_url||'—')+'</div>' +
         '</div>' +
        '</div>' +
        '<div style="font-size:12px;font-weight:700;color:'+statusCol+';">'+statusTxt+'</div>';
      })
      .catch(function(){
       btn.textContent = '↻ CHECK NOW';
       btn.disabled = false;
       box.innerHTML = '<div style="color:#C0392B;font-size:13px;">❌ Network error.</div>';
      });
    }

    function compareVer(a, b) {
     var pa = a.split('.').map(Number), pb = b.split('.').map(Number);
     for(var i=0; i<Math.max(pa.length,pb.length); i++){
      var diff = (pa[i]||0) - (pb[i]||0);
      if(diff !== 0) return diff > 0 ? 1 : -1;
     }
     return 0;
    }

    function showRelNotice(type, msg) {
     var el = document.getElementById('release-notice');
     el.style.display    = 'block';
     el.style.background = type==='success' ? 'rgba(39,174,96,.1)' : 'rgba(192,57,43,.08)';
     el.style.border     = '1px solid ' + (type==='success' ? 'rgba(39,174,96,.3)' : 'rgba(192,57,43,.3)');
     el.style.color      = type==='success' ? '#27AE60' : '#C0392B';
     el.style.whiteSpace = 'pre-line';
     el.textContent = msg;
     el.scrollIntoView({behavior:'smooth'});
    }

    // ── ZIP Upload & Install ──────────────────────────────────
    var _zipFile = null;

    function handleZipSelect(file) {
     if (!file || !file.name.endsWith('.zip')) {
      showUploadNotice('error', 'Please select a valid .zip file.');
      return;
     }
     _zipFile = file;
     document.getElementById('zip-file-name').textContent = '📦 ' + file.name + ' (' + (file.size/1024/1024).toFixed(2) + ' MB)';
     document.getElementById('zip-file-name').style.display = 'block';
     var btn = document.getElementById('zip-install-btn');
     btn.disabled = false;
     btn.style.opacity = '1';
    }

    function handleZipDrop(file) {
     handleZipSelect(file);
    }

    function mdInstallZip() {
     if (!_zipFile) { showUploadNotice('error', 'No file selected.'); return; }
     var btn = document.getElementById('zip-install-btn');
     btn.disabled = true;
     btn.textContent = '⏳ Installing...';
     showUploadNotice('info', '⏳ Uploading and installing... please wait.');

     var fd = new FormData();
     fd.append('action',   'madeya_upload_install_zip');
     fd.append('nonce',    '<?php echo esc_js( $nonce ); ?>');
     fd.append('zip_file', _zipFile);

     fetch('<?php echo esc_js( $ajax_url ); ?>', {method:'POST', body:fd})
      .then(function(r){ return r.json(); })
      .then(function(r){
       btn.disabled = false;
       btn.textContent = '⬆️ INSTALL UPDATE';
       if (r.success) {
        showUploadNotice('success', '✅ ' + r.data.message + '\n\nPage will reload in 3 seconds...');
        setTimeout(function(){ location.reload(); }, 3000);
       } else {
        showUploadNotice('error', '❌ ' + (r.data && r.data.message ? r.data.message : 'Installation failed.'));
       }
      })
      .catch(function(){
       btn.disabled = false;
       btn.textContent = '⬆️ INSTALL UPDATE';
       showUploadNotice('error', '❌ Network error. Please try again.');
      });
    }

    function showUploadNotice(type, msg) {
     var el = document.getElementById('upload-notice');
     el.style.display    = 'block';
     var colors = {
      success: {bg:'rgba(39,174,96,.1)',  border:'rgba(39,174,96,.3)',  color:'#27AE60'},
      error:   {bg:'rgba(192,57,43,.08)', border:'rgba(192,57,43,.3)',  color:'#C0392B'},
      info:    {bg:'rgba(41,128,185,.08)',border:'rgba(41,128,185,.3)', color:'#2980B9'},
     };
     var c = colors[type] || colors.info;
     el.style.background = c.bg;
     el.style.border     = '1px solid ' + c.border;
     el.style.color      = c.color;
     el.style.whiteSpace = 'pre-line';
     el.textContent      = msg;
     el.scrollIntoView({behavior:'smooth'});
    }
    </script>
    <?php
}

// ── AJAX: Upload & install plugin zip ─────────────────────────
add_action( 'wp_ajax_madeya_upload_install_zip', 'madeya_ajax_upload_install_zip' );

function madeya_ajax_upload_install_zip() {
    check_ajax_referer( 'madeya_release_nonce', 'nonce' );
    if ( ! current_user_can( 'update_plugins' ) ) {
        wp_send_json_error( array( 'message' => 'Permission denied.' ) );
    }

    if ( empty( $_FILES['zip_file'] ) || $_FILES['zip_file']['error'] !== UPLOAD_ERR_OK ) {
        wp_send_json_error( array( 'message' => 'File upload failed. Check PHP upload_max_filesize.' ) );
    }

    $file = $_FILES['zip_file'];

    // Validate it's a zip
    $finfo = finfo_open( FILEINFO_MIME_TYPE );
    $mime  = finfo_file( $finfo, $file['tmp_name'] );
    finfo_close( $finfo );
    if ( ! in_array( $mime, array( 'application/zip', 'application/x-zip-compressed', 'application/octet-stream' ), true ) ) {
        wp_send_json_error( array( 'message' => 'Invalid file type. Must be a .zip file.' ) );
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    // Move uploaded file to WP tmp
    $tmp_dir  = get_temp_dir();
    $tmp_file = $tmp_dir . 'madeya-upload-' . time() . '.zip';
    if ( ! move_uploaded_file( $file['tmp_name'], $tmp_file ) ) {
        wp_send_json_error( array( 'message' => 'Could not move uploaded file.' ) );
    }

    // Use WP upgrader with a silent skin
    $skin     = new WP_Ajax_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader( $skin );
    $result   = $upgrader->install( $tmp_file, array( 'overwrite_package' => true ) );

    @unlink( $tmp_file );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }
    if ( $result === false ) {
        wp_send_json_error( array( 'message' => 'Installation failed: ' . $skin->get_upgrade_messages() ) );
    }

    // Read new version from the installed plugin
    $plugin_data = get_plugin_data( MADEYA_PLUGIN_DIR . 'madeya-gas-queue.php', false, false );
    $new_version = $plugin_data['Version'] ?? 'unknown';

    wp_send_json_success( array(
        'message' => 'Plugin updated successfully to v' . $new_version . '. Reloading...',
    ) );
}
