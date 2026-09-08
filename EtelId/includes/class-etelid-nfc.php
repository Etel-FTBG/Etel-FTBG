<?php
/**
 * EtelID NFC Card module.
 *
 * Adds a new table (etelid_nfc_cards), a public route at /m/{token} that
 * resolves to a member's public profile, and AJAX endpoints for the admin
 * "Write NFC Card" tool to issue/revoke tokens.
 *
 * ASSUMPTIONS (confirm against actual plugin code before deploying):
 *  - Members table name: $wpdb->prefix . 'etelid_members'
 *  - Member columns used: id, etelid_number, full_name, photo_url, tier, status
 *  - Admin capability used elsewhere in the portal: 'manage_options'
 *    (swap ETELID_NFC_ADMIN_CAP below if the portal uses a custom capability)
 *
 * If any of the above do not match the real schema, only the three spots
 * marked "ADJUST HERE" need to change - the rest of the module does not
 * care about exact column names.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ETELID_NFC_ADMIN_CAP')) {
    define('ETELID_NFC_ADMIN_CAP', 'manage_options'); // ADJUST HERE if needed
}

class Etelid_Nfc {

    const TABLE = 'etelid_nfc_cards';
    const QUERY_VAR = 'etelid_nfc_token';
    const TOKEN_BYTES = 18; // -> 24 char base64url token

    public static function init() {
        add_action('init', array(__CLASS__, 'register_rewrite'));
        add_filter('query_vars', array(__CLASS__, 'register_query_var'));
        add_action('template_redirect', array(__CLASS__, 'maybe_render_profile'));

        add_action('wp_ajax_etelid_nfc_issue', array(__CLASS__, 'ajax_issue'));
        add_action('wp_ajax_etelid_nfc_revoke', array(__CLASS__, 'ajax_revoke'));
        add_action('wp_ajax_etelid_nfc_lookup_member', array(__CLASS__, 'ajax_lookup_member'));
    }

    /* ---------------------------------------------------------------
     * Install / migration
     * ------------------------------------------------------------- */

    public static function install() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id BIGINT UNSIGNED NOT NULL,
            token VARCHAR(40) NOT NULL,
            card_uid VARCHAR(64) NULL DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            issued_at DATETIME NOT NULL,
            last_tapped_at DATETIME NULL DEFAULT NULL,
            tap_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY member_id (member_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('etelid_nfc_db_version', '1.0');
    }

    /* ---------------------------------------------------------------
     * Token helpers
     * ------------------------------------------------------------- */

    protected static function generate_token() {
        $raw = random_bytes(self::TOKEN_BYTES);
        $token = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        return $token;
    }

    public static function issue_card($member_id) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $member_id = (int) $member_id;

        if ($member_id <= 0) {
            return new WP_Error('bad_member', 'Invalid member id.');
        }

        $token = self::generate_token();
        $now = current_time('mysql');

        $inserted = $wpdb->insert(
            $table,
            array(
                'member_id'  => $member_id,
                'token'      => $token,
                'status'     => 'active',
                'issued_at'  => $now,
            ),
            array('%d', '%s', '%s', '%s')
        );

        if (false === $inserted) {
            return new WP_Error('db_error', 'Could not issue card token.');
        }

        return $token;
    }

    public static function revoke_card($token) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        return $wpdb->update(
            $table,
            array('status' => 'revoked'),
            array('token' => sanitize_text_field($token)),
            array('%s'),
            array('%s')
        );
    }

    protected static function get_card_row($token) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $token = sanitize_text_field($token);

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE token = %s", $token)
        );

        return $row;
    }

    protected static function get_member($member_id) {
        global $wpdb;
        // ADJUST HERE: members table name + column names
        $members_table = $wpdb->prefix . 'etelid_members';

        $member = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, etelid_number, full_name, photo_url, tier, status
                 FROM {$members_table} WHERE id = %d",
                (int) $member_id
            )
        );

        return $member;
    }

    /* ---------------------------------------------------------------
     * Public route: /m/{token}
     * ------------------------------------------------------------- */

    public static function register_rewrite() {
        add_rewrite_rule('^m/([A-Za-z0-9_-]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top');
    }

    public static function register_query_var($vars) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public static function maybe_render_profile() {
        $token = get_query_var(self::QUERY_VAR);

        if (empty($token)) {
            return;
        }

        $card = self::get_card_row($token);

        if (!$card || 'active' !== $card->status) {
            status_header(404);
            $member = null;
        } else {
            $member = self::get_member($card->member_id);

            global $wpdb;
            $table = $wpdb->prefix . self::TABLE;
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET tap_count = tap_count + 1, last_tapped_at = %s WHERE id = %d",
                    current_time('mysql'),
                    $card->id
                )
            );
        }

        $template = plugin_dir_path(__FILE__) . '../templates/nfc-profile.php';

        if (file_exists($template)) {
            include $template;
        } else {
            wp_die('Profile template not found.');
        }

        exit;
    }

    /* ---------------------------------------------------------------
     * AJAX: admin "Write NFC Card" tool
     * ------------------------------------------------------------- */

    public static function ajax_issue() {
        check_ajax_referer('etelid_nfc_admin', 'nonce');

        if (!current_user_can(ETELID_NFC_ADMIN_CAP)) {
            wp_send_json_error(array('message' => 'Not allowed.'), 403);
        }

        $member_id = isset($_POST['member_id']) ? (int) $_POST['member_id'] : 0;
        $token = self::issue_card($member_id);

        if (is_wp_error($token)) {
            wp_send_json_error(array('message' => $token->get_error_message()), 400);
        }

        wp_send_json_success(array(
            'token' => $token,
            'url'   => home_url('/m/' . $token),
        ));
    }

    public static function ajax_revoke() {
        check_ajax_referer('etelid_nfc_admin', 'nonce');

        if (!current_user_can(ETELID_NFC_ADMIN_CAP)) {
            wp_send_json_error(array('message' => 'Not allowed.'), 403);
        }

        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
        self::revoke_card($token);

        wp_send_json_success();
    }

    public static function ajax_lookup_member() {
        check_ajax_referer('etelid_nfc_admin', 'nonce');

        if (!current_user_can(ETELID_NFC_ADMIN_CAP)) {
            wp_send_json_error(array('message' => 'Not allowed.'), 403);
        }

        global $wpdb;
        // ADJUST HERE: members table / column names, same as get_member() above
        $members_table = $wpdb->prefix . 'etelid_members';
        $search = isset($_POST['q']) ? sanitize_text_field($_POST['q']) : '';
        $like = '%' . $wpdb->esc_like($search) . '%';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, etelid_number, full_name FROM {$members_table}
                 WHERE etelid_number LIKE %s OR full_name LIKE %s
                 LIMIT 10",
                $like,
                $like
            )
        );

        wp_send_json_success($rows);
    }
}
