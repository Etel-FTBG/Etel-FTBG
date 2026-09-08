<?php
/**
 * Etel-ID Premium — Admin Controller
 *
 * @package    Etel-ID Premium
 * @version    2.0.0
 * @author     Etel Group · ኢትኤል ቡድን
 * @link       https://etelgroup.com
 * @since      2.0.0
 *
 * Registers all WP-admin menu pages, handles form submissions,
 * and dispatches AJAX actions for the admin dashboard.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_Admin {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menus' ) );
        add_action( 'wp_ajax_etelid_admin_action', array( __CLASS__, 'handle_action' ) );
    }

    public static function menus() {
        add_menu_page(
            'Etel-ID',
            'Etel-ID',
            'manage_options',
            'etel-id',
            array( __CLASS__, 'page_dashboard' ),
            'dashicons-id',
            28
        );
        add_submenu_page( 'etel-id', 'All Members',  'All Members',  'manage_options', 'etel-id',               array( __CLASS__, 'page_dashboard' ) );
        add_submenu_page( 'etel-id', 'Issue New ID', 'Issue New ID', 'manage_options', 'etel-id-issue',         array( __CLASS__, 'page_issue' ) );
        add_submenu_page( 'etel-id', '🪪 Fayda Quick Register', '🪪 Fayda Quick Register', 'manage_options', 'etel-id-fayda', array( __CLASS__, 'page_fayda_register' ) ); /* NEW v3.10.0 */
        add_submenu_page( 'etel-id', 'View & Print', 'View & Print', 'manage_options', 'etel-id-print',         array( __CLASS__, 'page_print' ) );
        add_submenu_page( 'etel-id', '🔏 Biometric', '🔏 Biometric', 'manage_options', 'etel-id-biometric',     array( __CLASS__, 'page_biometric' ) );   /* v3.0.0 */
        add_submenu_page( 'etel-id', 'Check-in Log', 'Check-in Log', 'manage_options', 'etel-id-checkin-log',   array( __CLASS__, 'page_checkin_log' ) ); /* v3.0.0 */
        add_submenu_page( 'etel-id', 'Audit Log',    'Audit Log',    'manage_options', 'etel-id-log',           array( __CLASS__, 'page_log' ) );
        add_submenu_page( 'etel-id', 'Settings',     'Settings',     'manage_options', 'etel-id-settings',      array( __CLASS__, 'page_settings' ) );
        /* Hidden page — no submenu entry */
        add_submenu_page( null, 'Edit Member', 'Edit Member', 'manage_options', 'etel-id-edit-member', array( __CLASS__, 'page_edit_member' ) );
        add_submenu_page( 'etel-id', '🔄 Updater',        '🔄 Updater',        'manage_options', 'etel-id-updater',        array( __CLASS__, 'page_updater' ) );
        add_submenu_page( 'etel-id', '🔐 Password Reset', '🔐 Password Reset', 'manage_options', 'etel-id-password-reset', array( __CLASS__, 'page_password_reset' ) );
        add_submenu_page( 'etel-id', '🔳 QR Cache Backfill', '🔳 QR Cache Backfill', 'manage_options', 'etel-id-qr-pregen', array( __CLASS__, 'page_qr_pregenerate' ) );
    }

    public static function page_dashboard() {
        $search  = isset( $_GET['s'] )     ? sanitize_text_field( wp_unslash( $_GET['s'] ) )     : '';
        $status  = isset( $_GET['status'] )? sanitize_text_field( wp_unslash( $_GET['status'] ) ): '';
        $paged   = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
        $limit   = 20;
        $offset  = ( $paged - 1 ) * $limit;
        $members = EtelID_DB::get_all( array( 'search' => $search, 'status' => $status, 'limit' => $limit, 'offset' => $offset ) );
        $total   = EtelID_DB::count_all( array( 'search' => $search, 'status' => $status ) );
        $counts  = EtelID_DB::count_by_status();
        include ETELID_PATH . 'admin/views/dashboard.php';
    }

    public static function page_issue() {
        $companies = EtelID_Generator::companies();
        $regions   = EtelID_Generator::regions();
        $tiers     = EtelID_Generator::tiers();
        $blood     = EtelID_Generator::blood_types();
        $message   = '';
        $new_member = null;
        if ( isset( $_POST['_etelid_issue_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_etelid_issue_nonce'] ) ), 'etelid_issue' ) && current_user_can( 'manage_options' ) ) {
            $fields = array(
                'full_name','full_name_am','dob','sex','blood_type','phone','email',
                'nationality','region','zone','woreda','primary_company_slug','role',
                'department','employee_number','tier','notes',
            );
            $data = array();
            foreach ( $fields as $f ) {
                $data[ $f ] = isset( $_POST[ $f ] ) ? sanitize_text_field( wp_unslash( $_POST[ $f ] ) ) : '';
            }
            if ( ! empty( $_POST['extra_companies'] ) && is_array( $_POST['extra_companies'] ) ) {
                $data['extra_companies'] = array_map( 'sanitize_text_field', wp_unslash( $_POST['extra_companies'] ) );
            }
            $result = EtelID_Generator::create( $data );
            if ( is_wp_error( $result ) ) {
                $message = 'error:' . $result->get_error_message();
            } else {
                $new_member = EtelID_DB::get_member( $result );
                $message    = 'success:' . $new_member->etel_id;
                if ( $new_member ) EtelID_Mailer::send_welcome( $new_member );
            }
        }
        include ETELID_PATH . 'admin/views/issue.php';
    }

    /* ── v3.10.0: Fayda ID photo quick-register (Pass 1) ── */
    public static function page_fayda_register() {
        $companies  = EtelID_Generator::companies();
        $regions    = EtelID_Generator::regions();
        $message    = '';
        $result     = null; /* array('member'=>obj,'temp_password'=>string,'notify'=>array) on success */

        if ( isset( $_POST['_etelid_fayda_nonce'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_etelid_fayda_nonce'] ) ), 'etelid_fayda' )
            && current_user_can( 'manage_options' ) ) {

            $fields = array(
                'full_name','full_name_am','dob','sex','nationality',
                'region','zone','woreda','phone','email','fayda_fin',
                'primary_company_slug','role','department','employee_number','notes',
            );
            $data = array();
            foreach ( $fields as $f ) {
                $data[ $f ] = isset( $_POST[ $f ] ) ? sanitize_text_field( wp_unslash( $_POST[ $f ] ) ) : '';
            }
            /* Large base64 image fields — not sanitize_text_field (would mangle base64). */
            $data['fayda_front_image'] = isset( $_POST['fayda_front_image'] ) ? wp_unslash( $_POST['fayda_front_image'] ) : '';
            $data['fayda_back_image']  = isset( $_POST['fayda_back_image']  ) ? wp_unslash( $_POST['fayda_back_image']  ) : '';
            $data['fayda_ocr_raw']     = isset( $_POST['fayda_ocr_raw']     ) ? sanitize_textarea_field( wp_unslash( $_POST['fayda_ocr_raw'] ) ) : '';
            /* A real member photo, captured separately from the two Fayda
             * ID-document shots above (added - this form previously had
             * no way to attach one at all, so every member registered
             * here ended up with their National ID card photo shown as
             * their profile picture everywhere, which is what this field
             * fixes). Only falls back to the Fayda front image when the
             * admin genuinely didn't provide one, instead of always
             * overwriting it unconditionally like before. */
            $data['photo_url'] = isset( $_POST['photo_url'] ) ? wp_unslash( $_POST['photo_url'] ) : '';
            if ( empty( $data['photo_url'] ) ) {
                $data['photo_url'] = $data['fayda_front_image'];
            }

            $outcome = EtelID_Fayda::quick_register( $data );
            if ( is_wp_error( $outcome ) ) {
                $message = 'error:' . $outcome->get_error_message();
            } else {
                $preferred_channel = isset( $_POST['notify_channel'] ) ? sanitize_key( wp_unslash( $_POST['notify_channel'] ) ) : 'auto';
                $notify = EtelID_Notify::deliver( $outcome['member'], $outcome['temp_password'], $preferred_channel );
                $result = array(
                    'member'        => $outcome['member'],
                    'temp_password' => $outcome['temp_password'],
                    'notify'        => $notify,
                );
                $message = 'success';
            }
        }

        include ETELID_PATH . 'admin/views/fayda-register.php';
    }

    public static function page_print() {
        $etel_id = isset( $_GET['etel_id'] ) ? sanitize_text_field( wp_unslash( $_GET['etel_id'] ) ) : '';
        $member  = $etel_id ? EtelID_DB::get_by_etel_id( $etel_id ) : null;
        if ( $member ) EtelID_DB::log( $member->id, $member->etel_id, 'printed', 'Admin print view' );
        include ETELID_PATH . 'admin/views/print.php';
    }

    public static function page_log() {
        $etel_id = isset( $_GET['etel_id'] ) ? sanitize_text_field( wp_unslash( $_GET['etel_id'] ) ) : '';
        $member  = $etel_id ? EtelID_DB::get_by_etel_id( $etel_id ) : null;
        $log     = $member  ? EtelID_DB::get_log( $member->id, 50 )  : array();
        include ETELID_PATH . 'admin/views/log.php';
    }

    public static function page_settings() {
        if ( isset( $_POST['_etelid_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_etelid_settings_nonce'] ) ), 'etelid_settings' ) && current_user_can( 'manage_options' ) ) {
            update_option( 'etelid_org_name',          sanitize_text_field( wp_unslash( (isset($_POST['org_name'])           ? $_POST['org_name']           : '') ) ) );
            update_option( 'etelid_org_website',       sanitize_text_field( wp_unslash( (isset($_POST['org_website'])        ? $_POST['org_website']        : '') ) ) );
            update_option( 'etelid_org_email',         sanitize_email(      wp_unslash( (isset($_POST['org_email'])          ? $_POST['org_email']          : '') ) ) );
            update_option( 'etelid_expiry_years',      absint(                           (isset($_POST['expiry_years'])       ? $_POST['expiry_years']       : 5) ) );
            update_option( 'etelid_id_prefix',         sanitize_text_field( wp_unslash( (isset($_POST['id_prefix'])          ? $_POST['id_prefix']          : 'EG') ) ) );
            update_option( 'etelid_require_approval',  absint(                           (isset($_POST['require_approval'])   ? $_POST['require_approval']   : 0) ) );
            /* Admin member ID — which Etel-ID member is the site owner */
            if ( isset( $_POST['admin_member_id'] ) ) {
                update_option( 'etelid_admin_member_id', absint( $_POST['admin_member_id'] ) );
            }
            if ( isset( $_POST['admin_phone'] ) ) {
                update_option( 'etelid_admin_phone', sanitize_text_field( wp_unslash( $_POST['admin_phone'] ) ) );
            }
            /* v3.10.0 — Telegram / WhatsApp credential delivery (Fayda Quick Register) */
            update_option( 'etelid_tg_bot_token', sanitize_text_field( wp_unslash( (isset($_POST['tg_bot_token']) ? $_POST['tg_bot_token'] : '') ) ) );
            update_option( 'etelid_tg_bot_username', sanitize_text_field( wp_unslash( (isset($_POST['tg_bot_username']) ? $_POST['tg_bot_username'] : '') ) ) );
            update_option( 'etelid_wa_token',     sanitize_text_field( wp_unslash( (isset($_POST['wa_token'])     ? $_POST['wa_token']     : '') ) ) );
            update_option( 'etelid_wa_phone_id',  sanitize_text_field( wp_unslash( (isset($_POST['wa_phone_id'])  ? $_POST['wa_phone_id']  : '') ) ) );
            /* Quick-launch app links shown on the member portal (Driver
             * App / Rider App / Customer App buttons) - esc_url_raw, not
             * sanitize_text_field, since these must stay valid URLs. */
            update_option( 'etelid_driver_app_url',   esc_url_raw( wp_unslash( (isset($_POST['driver_app_url'])   ? $_POST['driver_app_url']   : '') ) ) );
            update_option( 'etelid_ride_app_url',     esc_url_raw( wp_unslash( (isset($_POST['ride_app_url'])     ? $_POST['ride_app_url']     : '') ) ) );
            update_option( 'etelid_customer_app_url', esc_url_raw( wp_unslash( (isset($_POST['customer_app_url']) ? $_POST['customer_app_url'] : '') ) ) );
        }
        include ETELID_PATH . 'admin/views/settings.php';
    }

    public static function handle_action() {
        check_ajax_referer( 'etelid_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();
        $sub = isset( $_POST['sub'] ) ? sanitize_text_field( wp_unslash( $_POST['sub'] ) ) : '';
        $id  = isset( $_POST['id'] )  ? (int) $_POST['id'] : 0;
        if ( ! $id ) wp_send_json_error();

        if ( $sub === 'delete' ) {
            EtelID_DB::delete_member( $id );
            wp_send_json_success();
        }
        if ( $sub === 'toggle' ) {
            $m  = EtelID_DB::get_member( $id );
            if ( ! $m ) wp_send_json_error();
            $ns = $m->status === 'active' ? 'suspended' : 'active';
            EtelID_DB::set_status( $id, $ns );
            EtelID_DB::log( $id, $m->etel_id, $ns === 'active' ? 'activated' : 'suspended', 'Admin toggle' );
            wp_send_json_success( array( 'status' => $ns ) );
        }
        if ( $sub === 'flush_rewrites' ) {
            flush_rewrite_rules();
            wp_send_json_success();
        }

        /* v3.0.0 — Biometric enrollment */
        if ( $sub === 'bio_enroll' ) {
            $mid     = (int) ( isset( $_POST['member_id'] ) ? $_POST['member_id'] : 0 );
            $method  = sanitize_key( isset( $_POST['method'] ) ? $_POST['method'] : '' );
            $value   = sanitize_text_field( isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '' );
            $quality = min( 100, max( 0, (int) ( isset( $_POST['quality'] ) ? $_POST['quality'] : 80 ) ) );
            $m = $mid ? EtelID_DB::get_member( $mid ) : null;
            if ( ! $m || ! $method ) wp_send_json_error( 'Missing data.' );

            /* Physical biometrics stored directly — no hashing */
            if ( $method === 'physical' ) {
                $raw = json_decode( $value, true );
                if ( ! $raw ) wp_send_json_error( 'Invalid physical data.' );
                $phys = array(
                    'height_cm'           => (int) ( isset($raw['height']) ? $raw['height'] : 0 ),
                    'weight_kg'           => (int) ( isset($raw['weight']) ? $raw['weight'] : 0 ),
                    'eye_color'           => sanitize_text_field( isset($raw['eye']) ? $raw['eye'] : '' ),
                    'hair_color'          => sanitize_text_field( isset($raw['hair']) ? $raw['hair'] : '' ),
                    'skin_tone'           => sanitize_text_field( isset($raw['skin']) ? $raw['skin'] : '' ),
                    'distinguishing_marks'=> sanitize_textarea_field( isset($raw['marks']) ? $raw['marks'] : '' ),
                    'enrolled_by'         => wp_get_current_user()->display_name,
                );
                EtelID_DB::upsert_biometric( $mid, $m->etel_id, $phys );
                EtelID_DB::log( $mid, $m->etel_id, 'bio_enrolled', 'physical biometrics updated' );
                $bio = EtelID_DB::get_biometric( $mid );
                wp_send_json_success( array( 'method' => 'physical', 'score' => EtelID_Biometric::enrollment_score( $bio ) ) );
            }

            if ( ! $value ) wp_send_json_error( 'Missing value.' );
            $result = EtelID_Biometric::enroll( $mid, $m->etel_id, $method, $value, $quality, wp_get_current_user()->display_name );
            if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );
            $bio   = EtelID_DB::get_biometric( $mid );
            wp_send_json_success( array(
                'method' => $method,
                'score'  => EtelID_Biometric::enrollment_score( $bio ),
            ));
        }

        wp_send_json_error();
    }

    /* ── v3.0.0: Biometric enrollment page ── */
    public static function page_password_reset() {
        include ETELID_PATH . 'admin/views/password-reset.php';
    }

    public static function page_updater() {
        /* Handle clear log */
        if ( isset( $_GET['clear_log'] ) && wp_verify_nonce( (isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : ''), 'etelid_clear_log' ) ) {
            EtelID_Updater::clear_log();
            wp_redirect( admin_url('admin.php?page=etel-id-updater') );
            exit;
        }
        include ETELID_PATH . 'admin/views/updater.php';
    }

    public static function page_edit_member() {
        $member_id = isset( $_GET['member_id'] ) ? (int) $_GET['member_id'] : 0;
        $member    = $member_id ? EtelID_DB::get_member( $member_id ) : null;
        include ETELID_PATH . 'admin/views/member-edit.php';
    }

    public static function page_biometric() {
        include ETELID_PATH . 'admin/views/biometric.php';
    }

    /* ── v3.11.0: One-time QR cache backfill tool ──
     * Companion to the class-etelid-qr.php local-caching fix - lets an
     * admin pre-warm the cache for every EXISTING member in one go,
     * instead of each member's cache only getting built the next time
     * their card happens to be viewed naturally. Purely a convenience:
     * skipping this and doing nothing still works fine, just means
     * each member's very first card view after the caching fix ships
     * takes the (usually fast) external round-trip once. */
    public static function page_qr_pregenerate() {
        include ETELID_PATH . 'admin/views/qr-pregenerate.php';
    }

    /* ── v3.0.0: Check-in log page ── */
    public static function page_checkin_log() {
        include ETELID_PATH . 'admin/views/checkin-log.php';
    }
}
