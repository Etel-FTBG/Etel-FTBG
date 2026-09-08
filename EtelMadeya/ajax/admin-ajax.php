<?php
/**
 * AJAX: Admin panel — login, stats, controller CRUD, settings, clear queue, force logout.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Admin session check ────────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_admin_check', 'madeya_ajax_admin_check' );
add_action( 'wp_ajax_madeya_admin_check',        'madeya_ajax_admin_check' );

function madeya_ajax_admin_check() {
    $logged = ! empty( $_SESSION['madeya_admin_logged_in'] );

    if ( $logged ) {
        $same_ip = ( $_SESSION['madeya_admin_ip'] ?? '' ) === ( $_SERVER['REMOTE_ADDR'] ?? '' );
        $same_ua = ( $_SESSION['madeya_admin_ua'] ?? '' ) === substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 120 );
        if ( ! $same_ip || ! $same_ua ) {
            unset( $_SESSION['madeya_admin_logged_in'], $_SESSION['madeya_admin_ip'], $_SESSION['madeya_admin_ua'] );
            $logged = false;
        }
    }
    $requires_pw_change = false;
    if ( $logged ) {
        $stored_hash = get_option( 'madeya_admin_password', '' );
        $requires_pw_change = $stored_hash && password_verify( 'CHANGE_ME', $stored_hash );
    }
    wp_send_json_success( array( 'logged_in' => $logged, 'requires_pw_change' => $requires_pw_change ) );
}

// ── Admin login ────────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_admin_login', 'madeya_ajax_admin_login' );
add_action( 'wp_ajax_madeya_admin_login',        'madeya_ajax_admin_login' );

function madeya_ajax_admin_login() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );

    $user = sanitize_text_field( $_POST['username'] ?? '' );
    $pass = $_POST['password'] ?? '';
    if ( ! $user || ! $pass ) wp_send_json_error( array( 'message' => 'Username and password required.' ) );

    // ── Brute-force rate limiting: max 5 failed attempts per IP per 15 min ──
    $rate_key   = 'madeya_admin_fail_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' );
    $fail_count = (int) get_transient( $rate_key );
    if ( $fail_count >= 5 ) {
        wp_send_json_error( array(
            'message' => 'Too many failed attempts. Try again in 15 minutes.',
            'code'    => 'RATE_LIMITED',
        ) );
    }

    $stored_user = get_option( 'madeya_admin_username', 'madeya_admin' );
    $stored_hash = get_option( 'madeya_admin_password', '' );

    // Auto-create default credentials on first login attempt if none exist
    if ( ! $stored_hash ) {
        update_option( 'madeya_admin_username', 'madeya_admin' );
        $default_hash = password_hash( 'CHANGE_ME', PASSWORD_DEFAULT );
        update_option( 'madeya_admin_password', $default_hash );
        $stored_user = 'madeya_admin';
        $stored_hash = $default_hash;
    }

    if ( $user !== $stored_user || ! password_verify( $pass, $stored_hash ) ) {
        set_transient( $rate_key, $fail_count + 1, 15 * MINUTE_IN_SECONDS );
        wp_send_json_error( array( 'message' => 'Invalid username or password.' ) );
    }

    // ── Session fixation protection ──
    if ( session_status() === PHP_SESSION_ACTIVE ) {
        session_regenerate_id( true );
    }

    delete_transient( $rate_key );

    $_SESSION['madeya_admin_logged_in'] = true;
    $_SESSION['madeya_admin_ip']        = $_SERVER['REMOTE_ADDR'] ?? '';
    $_SESSION['madeya_admin_ua']        = substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 120 );

    // ── Warn if still using default credentials ──
    $using_default = password_verify( 'CHANGE_ME', $stored_hash );
    wp_send_json_success( array(
        'message'          => 'Logged in.',
        'requires_pw_change' => $using_default,
    ) );
}

// ── Admin logout ───────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_admin_logout', 'madeya_ajax_admin_logout' );
add_action( 'wp_ajax_madeya_admin_logout',        'madeya_ajax_admin_logout' );

function madeya_ajax_admin_logout() {
    unset( $_SESSION['madeya_admin_logged_in'], $_SESSION['madeya_admin_ip'], $_SESSION['madeya_admin_ua'] );
    wp_send_json_success();
}

// ── Dashboard stats ────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_admin_stats', 'madeya_ajax_admin_stats' );
add_action( 'wp_ajax_madeya_admin_stats',        'madeya_ajax_admin_stats' );

function madeya_ajax_admin_stats() {
    if ( empty( $_SESSION['madeya_admin_logged_in'] ) ) wp_send_json_error( array( 'message' => 'Not authorized.' ) );

    global $wpdb;
    $qt    = $wpdb->prefix . 'madeya_queue';
    $rt    = $wpdb->prefix . 'madeya_report';
    $ct    = $wpdb->prefix . 'madeya_controllers';
    $today = current_time( 'Y-m-d' );

    $in_queue  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$qt}" );
    $served    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rt} WHERE report_date=%s", $today ) );
    $vip       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$qt} WHERE queue_type='vip'" );
    $waiting   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$qt} WHERE status='waiting'" );

    $fuel      = madeya_get_fuel_stock();   // in liters
    $capacity  = (float) get_option( 'madeya_fuel_capacity_liters',
                          get_option( 'madeya_fuel_capacity_gallons', 0 ) * 3.78541 );
    $allocated = (float) $wpdb->get_var( "SELECT COALESCE(SUM(liters),0) FROM {$qt}" );
    $fuel_pct  = $capacity > 0 ? round( ( $fuel / $capacity ) * 100, 1 ) : 0;

    // Gallons dispensed today (sum of gallons from today's report)
    $gal_today = (float) $wpdb->get_var(
        $wpdb->prepare( "SELECT COALESCE(SUM(gallons),0) FROM {$rt} WHERE report_date=%s", $today )
    );

    // Controller counts (total and online — online requires active_token column)
    $ctrl_total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ct}" );
    $cols        = $wpdb->get_col( "SHOW COLUMNS FROM {$ct}" );
    $has_active  = in_array( 'active_token', $cols );
    $ctrl_online = $has_active
        ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ct} WHERE active_token IS NOT NULL AND active_token != ''" )
        : 0;

    // Vehicles served by type today
    $by_type = $wpdb->get_results(
        $wpdb->prepare( "SELECT vehicle_type, COUNT(*) as cnt FROM {$rt} WHERE report_date=%s GROUP BY vehicle_type", $today ),
        ARRAY_A
    );

    wp_send_json_success( array(
        'in_queue'        => $in_queue,
        'served_today'    => $served,
        'vip_queue'       => $vip,
        'waiting'         => $waiting,
        'controllers'     => $ctrl_total,
        'online'          => $ctrl_online,
        'fuel_stock'      => round( $fuel, 2 ),
        'fuel_capacity'   => round( $capacity, 2 ),
        'fuel_allocated'  => round( $allocated, 2 ),
        'fuel_remaining'  => round( $fuel - $allocated, 2 ),
        'fuel_pct'        => $fuel_pct,
        'gal_served_today'=> round( $gal_today, 2 ),
        'served_by_type'  => $by_type,
        'depletion'       => madeya_fuel_depletion_estimate(),
    ) );
}

// ── Get controllers ────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_admin_get_controllers', 'madeya_ajax_admin_get_controllers' );
add_action( 'wp_ajax_madeya_admin_get_controllers',        'madeya_ajax_admin_get_controllers' );

function madeya_ajax_admin_get_controllers() {
    if ( empty( $_SESSION['madeya_admin_logged_in'] ) ) wp_send_json_error( array( 'message' => 'Not authorized.' ) );

    global $wpdb;
    $ct   = $wpdb->prefix . 'madeya_controllers';
    $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$ct}" );

    $has_active = in_array( 'active_token', $cols );
    $sel        = $has_active
        ? 'id,username,full_name,station_name,vehicle_type,allowed_parity,fuel_allocated,fuel_used,liters_allocated,liters_used,station_lat,station_lng,station_address,active_token,active_ip,active_since'
        : 'id,username,full_name,station_name,vehicle_type,allowed_parity,fuel_allocated,fuel_used,liters_allocated,liters_used,station_lat,station_lng,station_address';

    $rows = $wpdb->get_results( "SELECT {$sel} FROM {$ct} ORDER BY id ASC", ARRAY_A );

    // ── Inject today's performance metrics per controller ──
    $rt    = $wpdb->prefix . 'madeya_report';
    $today = current_time( 'Y-m-d' );
    $perf  = $wpdb->get_results( $wpdb->prepare(
        "SELECT controller_id,
                COUNT(*) AS served_today,
                COALESCE(SUM(liters),0) AS liters_today
         FROM {$rt} WHERE report_date=%s GROUP BY controller_id",
        $today ), ARRAY_A );
    $perf_map = array();
    foreach ( (array) $perf as $p ) {
        $perf_map[ (int) $p['controller_id'] ] = $p;
    }
    foreach ( $rows as &$r ) {
        $p                  = isset( $perf_map[ (int) $r['id'] ] ) ? $perf_map[ (int) $r['id'] ] : null;
        $r['served_today']  = $p ? (int) $p['served_today']             : 0;
        $r['liters_today']  = $p ? round( (float) $p['liters_today'], 1 ) : 0.0;
    }
    unset( $r );

    wp_send_json_success( array( 'controllers' => $rows ) );
}

// ── Add controller ─────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_admin_add_ctrl', 'madeya_ajax_admin_add_ctrl' );
add_action( 'wp_ajax_madeya_admin_add_ctrl',        'madeya_ajax_admin_add_ctrl' );

function madeya_ajax_admin_add_ctrl() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );
    if ( empty( $_SESSION['madeya_admin_logged_in'] ) ) wp_send_json_error( array( 'message' => 'Not authorized.' ) );

    global $wpdb;
    $ct      = $wpdb->prefix . 'madeya_controllers';
    $un      = sanitize_text_field( $_POST['username']      ?? '' );
    $pw      = $_POST['password'] ?? '';
    $name    = sanitize_text_field( $_POST['full_name']     ?? '' );
    $station = sanitize_text_field( $_POST['station_name']  ?? 'Madeya Gas Station' );
    $vtype   = sanitize_text_field( $_POST['vehicle_type']  ?? '' );
    $parity  = sanitize_text_field( $_POST['allowed_parity'] ?? 'both' );

    if ( ! $un || ! $pw || ! $vtype ) wp_send_json_error( array( 'message' => 'Username, password and vehicle type required.' ) );
    if ( strlen( $pw ) < 8 )          wp_send_json_error( array( 'message' => 'Password must be at least 8 characters.' ) );
    if ( ! in_array( $parity, array( 'even', 'odd', 'both' ), true ) ) $parity = 'both';

    if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$ct} WHERE username=%s", $un ) ) ) {
        wp_send_json_error( array( 'message' => 'Username already exists.' ) );
    }

    $fuel_alloc = max( 0, (float) ( $_POST['fuel_allocated'] ?? 0 ) );

    $wpdb->insert( $ct, array(
        'username'       => $un,
        'pass_hash'      => password_hash( $pw, PASSWORD_DEFAULT ),
        'full_name'      => $name,
        'station_name'   => $station,
        'vehicle_type'   => $vtype,
        'allowed_parity' => $parity,
        'fuel_allocated' => $fuel_alloc,
        'fuel_used'      => 0,
    ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f' ) );

    wp_send_json_success( array( 'message' => 'Controller added.', 'id' => $wpdb->insert_id ) );
}

// ── Delete controller ──────────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_admin_del_ctrl', 'madeya_ajax_admin_del_ctrl' );
add_action( 'wp_ajax_madeya_admin_del_ctrl',        'madeya_ajax_admin_del_ctrl' );

function madeya_ajax_admin_del_ctrl() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );
    if ( empty( $_SESSION['madeya_admin_logged_in'] ) ) wp_send_json_error( array( 'message' => 'Not authorized.' ) );

    global $wpdb;
    $ct = $wpdb->prefix . 'madeya_controllers';
    $id = intval( $_POST['id'] ?? 0 );
    if ( ! $id ) wp_send_json_error( array( 'message' => 'Invalid ID.' ) );

    $wpdb->delete( $ct, array( 'id' => $id ), array( '%d' ) );
    wp_send_json_success( array( 'message' => 'Controller removed.' ) );
}

// ── Update controller fuel quota ───────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_admin_set_ctrl_fuel', 'madeya_ajax_admin_set_ctrl_fuel' );
add_action( 'wp_ajax_madeya_admin_set_ctrl_fuel',        'madeya_ajax_admin_set_ctrl_fuel' );

function madeya_ajax_admin_set_ctrl_fuel() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );
    if ( empty( $_SESSION['madeya_admin_logged_in'] ) ) wp_send_json_error( array( 'message' => 'Not authorized.' ) );

    global $wpdb;
    $ct   = $wpdb->prefix . 'madeya_controllers';
    $id   = intval( $_POST['id'] ?? 0 );
    $fuel = max( 0, (float) ( $_POST['fuel_allocated'] ?? 0 ) );

    if ( ! $id ) wp_send_json_error( array( 'message' => 'Invalid controller ID.' ) );

    $result = $wpdb->update( $ct, array( 'fuel_allocated' => $fuel ), array( 'id' => $id ), array( '%f' ), array( '%d' ) );
    if ( $result === false ) wp_send_json_error( array( 'message' => 'DB error: ' . $wpdb->last_error ) );

    wp_send_json_success( array( 'message' => 'Quota updated.', 'fuel_allocated' => round( $fuel, 2 ) ) );
}

// ── Force logout controller ────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_admin_force_logout', 'madeya_ajax_admin_force_logout' );
add_action( 'wp_ajax_madeya_admin_force_logout',        'madeya_ajax_admin_force_logout' );

function madeya_ajax_admin_force_logout() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );
    if ( empty( $_SESSION['madeya_admin_logged_in'] ) ) wp_send_json_error( array( 'message' => 'Not authorized.' ) );

    global $wpdb;
    $ct = $wpdb->prefix . 'madeya_controllers';
    $id = intval( $_POST['id'] ?? 0 );
    if ( ! $id ) wp_send_json_error( array( 'message' => 'Invalid ID.' ) );

    $wpdb->update( $ct, array( 'active_token' => '', 'active_ip' => '', 'active_since' => null ), array( 'id' => $id ), array( '%s', '%s', '%s' ), array( '%d' ) );
    wp_send_json_success( array( 'message' => 'Controller force-logged out.' ) );
}

// ── Save station settings ──────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_admin_save_settings', 'madeya_ajax_admin_save_settings' );
add_action( 'wp_ajax_madeya_admin_save_settings',        'madeya_ajax_admin_save_settings' );

function madeya_ajax_admin_save_settings() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );
    if ( empty( $_SESSION['madeya_admin_logged_in'] ) ) wp_send_json_error( array( 'message' => 'Not authorized.' ) );

    $station  = sanitize_text_field( $_POST['station_name']   ?? 'Madeya Gas Station' );
    $parity   = sanitize_text_field( $_POST['allowed_parity'] ?? 'both' );
    $types    = array_map( 'sanitize_text_field', (array) ( $_POST['allowed_types'] ?? array() ) );
    $all_keys = array( 'bajaj', 'qute', 'l_moto', 'private_moto', 'govt_moto', 'private_car', 'minibus', 'damas' );
    $labels   = array();
    foreach ( $all_keys as $k ) {
        $labels[ $k ] = sanitize_text_field( $_POST[ 'type_label_' . $k ] ?? ucfirst( $k ) );
    }

    update_option( 'madeya_station_settings', array(
        'station_name'   => $station,
        'allowed_parity' => $parity,
        'allowed_types'  => $types,
        'type_labels'    => $labels,
    ) );

    $new_user  = sanitize_text_field( $_POST['admin_username'] ?? '' );
    $new_email = sanitize_email( $_POST['admin_email'] ?? '' );
    $new_pass  = $_POST['admin_password'] ?? '';

    if ( $new_user ) update_option( 'madeya_admin_username', $new_user );
    update_option( 'madeya_admin_email', $new_email );
    if ( $new_pass ) update_option( 'madeya_admin_password', password_hash( $new_pass, PASSWORD_DEFAULT ) );

    // Google Maps API key
    $gmaps_key = sanitize_text_field( $_POST['google_maps_key'] ?? '' );
    update_option( 'madeya_google_maps_key', $gmaps_key );
    if ( ! defined( 'MADEYA_GOOGLE_MAPS_KEY' ) ) {
        define( 'MADEYA_GOOGLE_MAPS_KEY', $gmaps_key );
    }

    wp_send_json_success( array( 'message' => 'Settings saved.' ) );
}

// ── Admin clear queue ──────────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_admin_clear_queue', 'madeya_ajax_admin_clear_queue' );
add_action( 'wp_ajax_madeya_admin_clear_queue',        'madeya_ajax_admin_clear_queue' );

function madeya_ajax_admin_clear_queue() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );
    if ( empty( $_SESSION['madeya_admin_logged_in'] ) ) wp_send_json_error( array( 'message' => 'Not authorized.' ) );

    global $wpdb;
    $type = sanitize_text_field( $_POST['queue_type'] ?? 'all' );

    if ( $type === 'all' ) {
        $wpdb->query( "DELETE FROM {$wpdb->prefix}madeya_queue" );
    } else {
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}madeya_queue WHERE queue_type=%s", $type ) );
    }

    wp_send_json_success( array( 'message' => 'Queue cleared.' ) );
}

// ── Reset controller password (and optionally username) ────────
add_action( 'wp_ajax_nopriv_madeya_admin_reset_pass', 'madeya_ajax_admin_reset_pass' );
add_action( 'wp_ajax_madeya_admin_reset_pass',        'madeya_ajax_admin_reset_pass' );

function madeya_ajax_admin_reset_pass() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );
    if ( empty( $_SESSION['madeya_admin_logged_in'] ) ) {
        wp_send_json_error( array( 'message' => 'Not authorized.' ) );
    }

    global $wpdb;
    $ct       = $wpdb->prefix . 'madeya_controllers';
    $id       = intval( $_POST['ctrl_id'] ?? 0 );
    $new_pass = trim( $_POST['new_pass'] ?? '' );
    $new_user = sanitize_text_field( trim( $_POST['new_user'] ?? '' ) );

    if ( ! $id )       wp_send_json_error( array( 'message' => 'Invalid controller ID.' ) );
    if ( ! $new_pass ) wp_send_json_error( array( 'message' => 'New password is required.' ) );
    if ( strlen( $new_pass ) < 8 ) wp_send_json_error( array( 'message' => 'Password must be at least 8 characters.' ) );

    $data   = array( 'pass_hash' => password_hash( $new_pass, PASSWORD_DEFAULT ) );
    $format = array( '%s' );

    if ( $new_user ) {
        // Check username not taken by another controller
        $taken = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$ct} WHERE username=%s AND id != %d", $new_user, $id
        ) );
        if ( $taken ) {
            wp_send_json_error( array( 'message' => 'Username already taken by another controller.' ) );
        }
        $data['username'] = $new_user;
        $format[]         = '%s';
    }

    // Also force-logout the controller so they must re-login with new credentials
    $data['active_token'] = '';
    $data['active_ip']    = '';
    $format[]             = '%s';
    $format[]             = '%s';

    $result = $wpdb->update( $ct, $data, array( 'id' => $id ), $format, array( '%d' ) );

    if ( $result === false ) {
        wp_send_json_error( array( 'message' => 'Database error: ' . $wpdb->last_error ) );
    }

    wp_send_json_success( array( 'message' => 'Password updated. Controller has been logged out.' ) );
}
