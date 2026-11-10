<?php
/**
 * Etel-ID Premium - Admin Console API (standalone /console/ page).
 *
 * Admin-only endpoints for the tools that used to live only in wp-admin: issuing an ID,
 * check-in log, QR cache backfill and settings. Same guard as the portal's Admin Tools
 * (EtelID Admin Panels): nonce 'etelid_auth' + EtelID_Auth::is_site_admin().
 *
 * PHP 5.6 compatible - no arrow functions.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_Console_API {

    public static function init() {
        $map = array(
            'etelid_console_issue_options' => 'issue_options',
            'etelid_console_issue'         => 'issue',
            'etelid_console_checkin'       => 'checkin',
            'etelid_console_qr_batch'      => 'qr_batch',
            'etelid_console_settings_get'  => 'settings_get',
            'etelid_console_settings_save' => 'settings_save',
        );
        foreach ( $map as $action => $method ) {
            add_action( 'wp_ajax_'        . $action, array( __CLASS__, $method ) );
            add_action( 'wp_ajax_nopriv_' . $action, array( __CLASS__, $method ) );
        }
    }

    private static function guard() {
        if ( ! check_ajax_referer( 'etelid_auth', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Your session has expired. Please refresh the page and try again.' ) );
        }
        $member = EtelID_Auth::get_current_member();
        if ( ! $member || ! EtelID_Auth::is_site_admin( $member ) ) {
            wp_send_json_error( array( 'message' => 'Not authorized.' ) );
        }
        return $member;
    }

    /* ---- Issue a new ID ---- */
    public static function issue_options() {
        self::guard();
        $companies = array();
        foreach ( EtelID_Generator::companies() as $slug => $c ) {
            $companies[] = array( 'slug' => $slug, 'name' => $c['name'] );
        }
        $tiers = array();
        foreach ( (array) EtelID_Generator::tiers() as $slug => $t ) {
            $tiers[] = array( 'slug' => $slug, 'label' => $t['label'] );
        }
        wp_send_json_success( array(
            'companies' => $companies,
            'regions'   => array_values( (array) EtelID_Generator::regions() ),
            'tiers'     => $tiers,
            'blood'     => array_values( (array) EtelID_Generator::blood_types() ),
        ) );
    }

    public static function issue() {
        self::guard();
        $fields = array(
            'full_name','full_name_am','dob','sex','blood_type','phone','email',
            'nationality','region','zone','woreda','primary_company_slug','role',
            'department','employee_number','tier','notes',
        );
        $data = array();
        foreach ( $fields as $f ) {
            $data[ $f ] = isset( $_POST[ $f ] ) ? sanitize_text_field( wp_unslash( $_POST[ $f ] ) ) : '';
        }
        if ( ! empty( $_POST['extra_companies'] ) ) {
            $extra = is_array( $_POST['extra_companies'] ) ? $_POST['extra_companies'] : explode( ',', (string) $_POST['extra_companies'] );
            $data['extra_companies'] = array_filter( array_map( 'sanitize_text_field', wp_unslash( $extra ) ) );
        }
        if ( $data['full_name'] === '' || $data['dob'] === '' || $data['phone'] === '' ) {
            wp_send_json_error( array( 'message' => 'Full name, date of birth and phone are required.' ) );
        }
        $result = EtelID_Generator::create( $data );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }
        $new = EtelID_DB::get_member( $result );
        if ( $new && class_exists( 'EtelID_Phone_Verify' ) ) EtelID_Phone_Verify::mark_verified( $new, 'issued by staff' );
        if ( $new && class_exists( 'EtelID_Mailer' ) ) EtelID_Mailer::send_welcome( $new );
        wp_send_json_success( array(
            'id'      => $new ? (int) $new->id : 0,
            'etel_id' => $new ? $new->etel_id : '',
            'name'    => $new ? $new->full_name : '',
            'message' => 'Etel-ID issued.',
        ) );
    }

    /* ---- Biometric check-in log ---- */
    public static function checkin() {
        self::guard();
        $rows = EtelID_DB::get_bio_log( 0, 200 );
        $out  = array();
        foreach ( (array) $rows as $l ) {
            $out[] = array(
                'etel_id'    => isset( $l->etel_id ) ? $l->etel_id : '',
                'method'     => $l->method,
                'result'     => $l->result,
                'confidence' => isset( $l->confidence ) ? (int) $l->confidence : 0,
                'time'       => $l->created_at,
            );
        }
        wp_send_json_success( array( 'stats' => EtelID_DB::bio_checkin_stats(), 'rows' => $out ) );
    }

    /* ---- QR cache backfill (batched) ---- */
    public static function qr_batch() {
        self::guard();
        $offset  = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
        $limit   = 20;
        $total   = EtelID_DB::count_all();
        $members = EtelID_DB::get_all( array( 'limit' => $limit, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC' ) );
        $done = 0;
        foreach ( $members as $m ) {
            if ( empty( $m->etel_id ) ) continue;
            EtelID_QR::img( $m->etel_id, 120 );
            EtelID_QR::img( $m->etel_id, 220, '2A1A06', 'FFFEF8', 160, 6 );
            EtelID_QR::src( $m->etel_id, 200 );
            $done++;
        }
        $next = $offset + $limit;
        wp_send_json_success( array( 'processed' => $done, 'next_offset' => $next, 'total' => (int) $total, 'finished' => $next >= (int) $total ) );
    }

    /* ---- Settings ---- */
    private static function setting_keys() {
        /* post key => option name */
        return array(
            'org_name' => 'etelid_org_name', 'org_website' => 'etelid_org_website', 'org_email' => 'etelid_org_email',
            'id_prefix' => 'etelid_id_prefix', 'expiry_years' => 'etelid_expiry_years', 'require_approval' => 'etelid_require_approval',
            'admin_phone' => 'etelid_admin_phone',
            'tg_bot_username' => 'etelid_tg_bot_username', 'tg_bot_token' => 'etelid_tg_bot_token',
            'wa_phone_id' => 'etelid_wa_phone_id', 'wa_token' => 'etelid_wa_token',
            'driver_app_url' => 'etelid_driver_app_url', 'ride_app_url' => 'etelid_ride_app_url', 'customer_app_url' => 'etelid_customer_app_url',
        );
    }

    public static function settings_get() {
        self::guard();
        $out = array();
        $defaults = array( 'org_name' => 'Etel Group', 'id_prefix' => 'EG', 'expiry_years' => 5, 'require_approval' => 0 );
        foreach ( self::setting_keys() as $k => $opt ) {
            $d = isset( $defaults[ $k ] ) ? $defaults[ $k ] : '';
            $out[ $k ] = get_option( $opt, $d );
        }
        /* Secrets are never sent back in full - show whether one is set and let the admin replace it. */
        foreach ( array( 'tg_bot_token', 'wa_token' ) as $s ) {
            $out[ $s . '_set' ] = $out[ $s ] !== '' && $out[ $s ] !== false;
            $out[ $s ] = '';
        }
        wp_send_json_success( array( 'settings' => $out, 'version' => defined( 'ETELID_VERSION' ) ? ETELID_VERSION : '' ) );
    }

    public static function settings_save() {
        self::guard();
        $p = $_POST;
        $g = function ( $k, $d = '' ) use ( $p ) { return isset( $p[ $k ] ) ? wp_unslash( $p[ $k ] ) : $d; };
        update_option( 'etelid_org_name',         sanitize_text_field( $g( 'org_name' ) ) );
        update_option( 'etelid_org_website',      sanitize_text_field( $g( 'org_website' ) ) );
        update_option( 'etelid_org_email',        sanitize_email( $g( 'org_email' ) ) );
        update_option( 'etelid_expiry_years',     max( 1, absint( $g( 'expiry_years', 5 ) ) ) );
        update_option( 'etelid_id_prefix',        sanitize_text_field( $g( 'id_prefix', 'EG' ) ) );
        update_option( 'etelid_require_approval', absint( $g( 'require_approval', 0 ) ) );
        update_option( 'etelid_admin_phone',      sanitize_text_field( $g( 'admin_phone' ) ) );
        update_option( 'etelid_tg_bot_username',  sanitize_text_field( $g( 'tg_bot_username' ) ) );
        update_option( 'etelid_wa_phone_id',      sanitize_text_field( $g( 'wa_phone_id' ) ) );
        /* Secrets: only replaced when a new value is typed (blank = keep the existing one). */
        if ( $g( 'tg_bot_token' ) !== '' ) update_option( 'etelid_tg_bot_token', sanitize_text_field( $g( 'tg_bot_token' ) ) );
        if ( $g( 'wa_token' ) !== '' )     update_option( 'etelid_wa_token',     sanitize_text_field( $g( 'wa_token' ) ) );
        update_option( 'etelid_driver_app_url',   esc_url_raw( $g( 'driver_app_url' ) ) );
        update_option( 'etelid_ride_app_url',     esc_url_raw( $g( 'ride_app_url' ) ) );
        update_option( 'etelid_customer_app_url', esc_url_raw( $g( 'customer_app_url' ) ) );
        wp_send_json_success( array( 'message' => 'Settings saved.' ) );
    }
}
