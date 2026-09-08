<?php
/**
 * AJAX: Controller authentication — login, logout, session check.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Login ──────────────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_login', 'madeya_ajax_login' );
add_action( 'wp_ajax_madeya_login',        'madeya_ajax_login' );

function madeya_ajax_login() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );

    $user = sanitize_text_field( $_POST['username'] ?? '' );
    $pass = $_POST['password'] ?? '';
    if ( ! $user || ! $pass ) wp_send_json_error( array( 'message' => 'Username and password required.' ) );

    // ── Brute-force rate limiting: max 5 failed attempts per IP per 15 min ──
    $rate_key   = 'madeya_ctrl_fail_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' );
    $fail_count = (int) get_transient( $rate_key );
    if ( $fail_count >= 5 ) {
        wp_send_json_error( array(
            'message' => 'Too many failed attempts. Try again in 15 minutes.',
            'code'    => 'RATE_LIMITED',
        ) );
    }

    global $wpdb;
    $ct  = $wpdb->prefix . 'madeya_controllers';
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ct} WHERE username=%s", $user ) );

    if ( ! $row || ! password_verify( $pass, $row->pass_hash ) ) {
        set_transient( $rate_key, $fail_count + 1, 15 * MINUTE_IN_SECONDS );
        wp_send_json_error( array( 'message' => 'Invalid username or password.' ) );
    }

    // ── Session fixation protection ──
    if ( session_status() === PHP_SESSION_ACTIVE ) {
        session_regenerate_id( true );
    }

    $_SESSION['madeya_ctrl_id']         = (int) $row->id;
    $_SESSION['madeya_ctrl_name']       = $row->full_name ?: $row->username;
    $_SESSION['madeya_ctrl_station']    = $row->station_name;
    $_SESSION['madeya_ctrl_vtype']      = $row->vehicle_type;
    $_SESSION['madeya_ctrl_parity']     = $row->allowed_parity;
    $_SESSION['madeya_ctrl_fuel_alloc'] = (float) ( $row->fuel_allocated ?? 0 );
    $_SESSION['madeya_ctrl_ip']         = $_SERVER['REMOTE_ADDR'] ?? '';
    $_SESSION['madeya_ctrl_ua']         = substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 120 );

    delete_transient( $rate_key );

    $alloc     = (float) ( $row->fuel_allocated ?? 0 );
    $used      = (float) ( $row->fuel_used ?? 0 );
    $remaining = $alloc > 0 ? max( 0.0, $alloc - $used ) : null;

    wp_send_json_success( array(
        'ctrl_id'        => (int) $row->id,
        'name'           => $_SESSION['madeya_ctrl_name'],
        'station'        => $row->station_name,
        'allowed_parity' => $row->allowed_parity,
        'allowed_types'  => $row->vehicle_type ? array( $row->vehicle_type ) : array(),
        'type_labels'    => madeya_get_settings()['type_labels'],
        'fuel_allocated' => $alloc,
        'fuel_used'      => $used,
        'fuel_remaining' => $remaining,
    ) );
}

// ── Logout ─────────────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_logout', 'madeya_ajax_logout' );
add_action( 'wp_ajax_madeya_logout',        'madeya_ajax_logout' );

function madeya_ajax_logout() {
    unset( $_SESSION['madeya_ctrl_id'], $_SESSION['madeya_ctrl_name'] );
    wp_send_json_success();
}

// ── Session check ──────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_check_session', 'madeya_ajax_check_session' );
add_action( 'wp_ajax_madeya_check_session',        'madeya_ajax_check_session' );

function madeya_ajax_check_session() {
    $cfg    = madeya_get_settings();
    $logged = madeya_is_logged_in();

    $ctrl_vtype   = $_SESSION['madeya_ctrl_vtype']   ?? '';
    $ctrl_parity  = $_SESSION['madeya_ctrl_parity']  ?? 'both';
    $ctrl_station = $_SESSION['madeya_ctrl_station'] ?? $cfg['station_name'];

    wp_send_json_success( array(
        'logged_in'      => $logged,
        'ctrl_id'        => (int)( $_SESSION['madeya_ctrl_id'] ?? 0 ),
        'name'           => $_SESSION['madeya_ctrl_name'] ?? '',
        'station'        => $ctrl_station,
        'allowed_parity' => $ctrl_parity,
        'allowed_types'  => $ctrl_vtype ? array( $ctrl_vtype ) : $cfg['allowed_types'],
        'type_labels'    => $cfg['type_labels'],
        'fuel_allocated' => (float) ( $_SESSION['madeya_ctrl_fuel_alloc'] ?? 0 ),
    ) );
}

// ── Nonce refresh ──────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_refresh_nonce', 'madeya_ajax_refresh_nonce' );
add_action( 'wp_ajax_madeya_refresh_nonce',        'madeya_ajax_refresh_nonce' );

function madeya_ajax_refresh_nonce() {
    wp_send_json_success( array( 'nonce' => wp_create_nonce( 'madeya_nonce' ) ) );
}
