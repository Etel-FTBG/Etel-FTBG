<?php
/**
 * AJAX: New Features
 * - Daily news / station location posting (admin)
 * - Per-controller private quota management (liters)
 * - Government priority tier fuel access rules
 * - Station locations for driver map
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Admin: Post daily news / station location update ───────────
add_action( 'wp_ajax_nopriv_madeya_admin_post_news', 'madeya_ajax_admin_post_news' );
add_action( 'wp_ajax_madeya_admin_post_news',        'madeya_ajax_admin_post_news' );

function madeya_ajax_admin_post_news() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );
    if ( empty( $_SESSION['madeya_admin_logged_in'] ) ) {
        wp_send_json_error( array( 'message' => 'Not authorized.' ) );
    }

    $news_text    = sanitize_textarea_field( $_POST['news_text']    ?? '' );
    $active_types = array_map( 'sanitize_text_field', (array)( $_POST['active_vehicle_types'] ?? array() ) );
    $stations     = json_decode( stripslashes( $_POST['stations'] ?? '[]' ), true );
    $expires_hrs  = max( 1, intval( $_POST['expires_hours'] ?? 24 ) );

    if ( ! $news_text ) {
        wp_send_json_error( array( 'message' => 'News text is required.' ) );
    }

    // Sanitize station entries
    $clean_stations = array();
    foreach ( (array) $stations as $s ) {
        if ( empty( $s['name'] ) || empty( $s['lat'] ) || empty( $s['lng'] ) ) continue;
        $clean_stations[] = array(
            'id'          => intval( $s['id'] ?? 0 ),
            'name'        => sanitize_text_field( $s['name'] ),
            'address'     => sanitize_text_field( $s['address'] ?? '' ),
            'lat'         => (float) $s['lat'],
            'lng'         => (float) $s['lng'],
            'vehicle_types'=> array_map( 'sanitize_text_field', (array)( $s['vehicle_types'] ?? array() ) ),
            'liters_today' => max( 0, (float)( $s['liters_today'] ?? 0 ) ),
        );
    }

    $news = array(
        'text'          => $news_text,
        'active_types'  => $active_types,
        'stations'      => $clean_stations,
        'posted_at'     => current_time( 'mysql' ),
        'posted_by'     => get_option( 'madeya_admin_username', 'admin' ),
        'expires_at'    => date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( $expires_hrs * 3600 ) ),
    );

    update_option( 'madeya_daily_news', $news );

    wp_send_json_success( array(
        'message'  => 'Daily update posted successfully.',
        'stations' => count( $clean_stations ),
        'expires'  => $news['expires_at'],
    ) );
}

// ── Public: Get daily news + station locations ─────────────────
add_action( 'wp_ajax_nopriv_madeya_get_daily_news', 'madeya_ajax_get_daily_news' );
add_action( 'wp_ajax_madeya_get_daily_news',        'madeya_ajax_get_daily_news' );

function madeya_ajax_get_daily_news() {
    $news = get_option( 'madeya_daily_news', null );

    if ( ! $news ) {
        wp_send_json_success( array( 'has_news' => false, 'stations' => array() ) );
    }

    // Check if expired
    if ( ! empty( $news['expires_at'] ) && strtotime( $news['expires_at'] ) < current_time( 'timestamp' ) ) {
        wp_send_json_success( array(
            'has_news' => false,
            'expired'  => true,
            'stations' => array(),
        ) );
    }

    // Filter by vehicle type if requested
    $vehicle_type = sanitize_text_field( $_POST['vehicle_type'] ?? $_GET['vehicle_type'] ?? '' );
    $stations = $news['stations'] ?? array();

    if ( $vehicle_type ) {
        $stations = array_filter( $stations, function( $s ) use ( $vehicle_type ) {
            return empty( $s['vehicle_types'] ) || in_array( $vehicle_type, $s['vehicle_types'], true );
        } );
        $stations = array_values( $stations );
    }

    wp_send_json_success( array(
        'has_news'     => true,
        'text'         => $news['text'],
        'active_types' => $news['active_types'] ?? array(),
        'stations'     => $stations,
        'posted_at'    => $news['posted_at'],
        'expires_at'   => $news['expires_at'],
        'posted_by'    => $news['posted_by'] ?? '',
    ) );
}

// ── Admin: Get single controller quota (private) ───────────────
add_action( 'wp_ajax_nopriv_madeya_admin_get_ctrl_quota', 'madeya_ajax_admin_get_ctrl_quota' );
add_action( 'wp_ajax_madeya_admin_get_ctrl_quota',        'madeya_ajax_admin_get_ctrl_quota' );

function madeya_ajax_admin_get_ctrl_quota() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );
    if ( empty( $_SESSION['madeya_admin_logged_in'] ) ) {
        wp_send_json_error( array( 'message' => 'Not authorized.' ) );
    }

    $id = intval( $_POST['id'] ?? 0 );
    if ( ! $id ) wp_send_json_error( array( 'message' => 'Controller ID required.' ) );

    global $wpdb;
    $ct   = $wpdb->prefix . 'madeya_controllers';
    $ctrl = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, username, full_name, station_name, vehicle_type,
                allowed_parity, liters_allocated, liters_used,
                station_lat, station_lng, station_address
         FROM {$ct} WHERE id=%d",
        $id
    ), ARRAY_A );

    if ( ! $ctrl ) wp_send_json_error( array( 'message' => 'Controller not found.' ) );

    $alloc    = (float)( $ctrl['liters_allocated'] ?? 0 );
    $used     = (float)( $ctrl['liters_used']      ?? 0 );
    $remaining= max( 0, $alloc - $used );
    $pct      = $alloc > 0 ? round( ( $remaining / $alloc ) * 100, 1 ) : null;

    // Fetch today's queue entries for this controller
    $qt    = $wpdb->prefix . 'madeya_queue';
    $rt    = $wpdb->prefix . 'madeya_report';
    $today = current_time( 'Y-m-d' );

    $queue_entries = $wpdb->get_results( $wpdb->prepare(
        "SELECT plate, liters, queue_type, status, position, registered_at
         FROM {$qt} WHERE vehicle_type=%s ORDER BY position ASC",
        $ctrl['vehicle_type']
    ), ARRAY_A );

    $served_today = $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(liters),0) FROM {$rt}
         WHERE vehicle_type=%s AND report_date=%s",
        $ctrl['vehicle_type'], $today
    ) );

    wp_send_json_success( array(
        'controller'     => array(
            'id'          => (int)$ctrl['id'],
            'username'    => $ctrl['username'],
            'full_name'   => $ctrl['full_name'],
            'station_name'=> $ctrl['station_name'],
            'vehicle_type'=> $ctrl['vehicle_type'],
            'parity'      => $ctrl['allowed_parity'],
            'lat'         => (float)( $ctrl['station_lat'] ?? 0 ),
            'lng'         => (float)( $ctrl['station_lng'] ?? 0 ),
            'address'     => $ctrl['station_address'] ?? '',
        ),
        'quota'          => array(
            'liters_allocated' => round( $alloc, 1 ),
            'liters_used'      => round( $used, 1 ),
            'liters_remaining' => round( $remaining, 1 ),
            'pct'              => $pct,
        ),
        'queue'          => $queue_entries ?: array(),
        'served_liters_today' => round( (float)$served_today, 1 ),
    ) );
}

// ── Admin: Set controller quota in liters + station location ──
add_action( 'wp_ajax_nopriv_madeya_admin_set_ctrl_quota', 'madeya_ajax_admin_set_ctrl_quota' );
add_action( 'wp_ajax_madeya_admin_set_ctrl_quota',        'madeya_ajax_admin_set_ctrl_quota' );

function madeya_ajax_admin_set_ctrl_quota() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );
    if ( empty( $_SESSION['madeya_admin_logged_in'] ) ) {
        wp_send_json_error( array( 'message' => 'Not authorized.' ) );
    }

    global $wpdb;
    $ct             = $wpdb->prefix . 'madeya_controllers';
    $id             = intval( $_POST['id'] ?? 0 );
    $liters         = max( 0, (float)( $_POST['liters_allocated'] ?? 0 ) );
    $reset_used     = ! empty( $_POST['reset_used'] );
    $lat            = (float)( $_POST['station_lat']     ?? 0 );
    $lng            = (float)( $_POST['station_lng']     ?? 0 );
    $address        = sanitize_text_field( $_POST['station_address'] ?? '' );

    if ( ! $id ) wp_send_json_error( array( 'message' => 'Invalid controller ID.' ) );

    $update = array(
        'liters_allocated' => $liters,
        'station_lat'      => $lat,
        'station_lng'      => $lng,
        'station_address'  => $address,
    );
    $formats = array( '%f', '%f', '%f', '%s' );

    if ( $reset_used ) {
        $update['liters_used'] = 0;
        $formats[]             = '%f';
    }

    $result = $wpdb->update( $ct, $update, array( 'id' => $id ), $formats, array( '%d' ) );
    if ( $result === false ) {
        wp_send_json_error( array( 'message' => 'DB error: ' . $wpdb->last_error ) );
    }

    wp_send_json_success( array(
        'message'          => 'Controller quota and location updated.',
        'liters_allocated' => round( $liters, 1 ),
    ) );
}

// ── Controller: Get my private quota ──────────────────────────
add_action( 'wp_ajax_nopriv_madeya_get_my_quota', 'madeya_ajax_get_my_quota' );
add_action( 'wp_ajax_madeya_get_my_quota',        'madeya_ajax_get_my_quota' );

function madeya_ajax_get_my_quota() {
    if ( ! madeya_is_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Not logged in.' ) );
    }
    global $wpdb;
    $ctrl_id = (int) $_SESSION['madeya_ctrl_id'];
    $ct      = $wpdb->prefix . 'madeya_controllers';

    $ctrl = $wpdb->get_row( $wpdb->prepare(
        "SELECT liters_allocated, liters_used FROM {$ct} WHERE id=%d", $ctrl_id
    ), ARRAY_A );

    if ( ! $ctrl ) wp_send_json_error( array( 'message' => 'Controller not found.' ) );

    $alloc     = (float)( $ctrl['liters_allocated'] ?? 0 );
    $used      = (float)( $ctrl['liters_used']      ?? 0 );
    $remaining = max( 0, $alloc - $used );
    $pct       = $alloc > 0 ? round( ( $remaining / $alloc ) * 100, 1 ) : null;

    wp_send_json_success( array(
        'liters_allocated' => round( $alloc, 1 ),
        'liters_used'      => round( $used, 1 ),
        'liters_remaining' => round( $remaining, 1 ),
        'pct'              => $pct,
        'unlimited'        => ( $alloc <= 0 ),
    ) );
}

// ── Admin: Get priority tiers config ──────────────────────────
add_action( 'wp_ajax_nopriv_madeya_get_priority_tiers', 'madeya_ajax_get_priority_tiers' );
add_action( 'wp_ajax_madeya_get_priority_tiers',        'madeya_ajax_get_priority_tiers' );

function madeya_ajax_get_priority_tiers() {
    $tiers   = madeya_get_priority_tiers();
    $current = get_option( 'madeya_priority_rules', array() );

    $result  = array();
    foreach ( $tiers as $num => $tier ) {
        $result[] = array_merge( $tier, array(
            'tier'    => $num,
            'enabled' => isset( $current[ $num ] ) ? (bool)$current[ $num ]['enabled'] : true,
            'note'    => $current[ $num ]['note'] ?? '',
        ) );
    }
    wp_send_json_success( array( 'tiers' => $result ) );
}

// ── Admin: Save priority rules ─────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_admin_save_priority', 'madeya_ajax_admin_save_priority' );
add_action( 'wp_ajax_madeya_admin_save_priority',        'madeya_ajax_admin_save_priority' );

function madeya_ajax_admin_save_priority() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );
    if ( empty( $_SESSION['madeya_admin_logged_in'] ) ) {
        wp_send_json_error( array( 'message' => 'Not authorized.' ) );
    }

    $rules_raw = json_decode( stripslashes( $_POST['rules'] ?? '{}' ), true );
    $clean     = array();
    for ( $i = 1; $i <= 7; $i++ ) {
        $clean[ $i ] = array(
            'enabled' => ! empty( $rules_raw[ $i ]['enabled'] ),
            'note'    => sanitize_text_field( $rules_raw[ $i ]['note'] ?? '' ),
        );
    }
    update_option( 'madeya_priority_rules', $clean );
    wp_send_json_success( array( 'message' => 'Priority rules saved.' ) );
}

// ── Admin: Get all controllers with locations (for map) ────────
add_action( 'wp_ajax_nopriv_madeya_get_station_locations', 'madeya_ajax_get_station_locations' );
add_action( 'wp_ajax_madeya_get_station_locations',        'madeya_ajax_get_station_locations' );

function madeya_ajax_get_station_locations() {
    global $wpdb;
    $ct   = $wpdb->prefix . 'madeya_controllers';
    $rows = $wpdb->get_results(
        "SELECT id, full_name, station_name, vehicle_type, allowed_parity,
                liters_allocated, liters_used,
                station_lat, station_lng, station_address
         FROM {$ct}
         WHERE station_lat != 0 AND station_lng != 0
         ORDER BY id ASC",
        ARRAY_A
    );

    $stations = array();
    foreach ( (array) $rows as $r ) {
        $alloc     = (float)( $r['liters_allocated'] ?? 0 );
        $used      = (float)( $r['liters_used']      ?? 0 );
        $remaining = max( 0, $alloc - $used );

        $stations[] = array(
            'id'           => (int)$r['id'],
            'name'         => $r['station_name'],
            'vehicle_type' => $r['vehicle_type'],
            'parity'       => $r['allowed_parity'],
            'lat'          => (float)$r['station_lat'],
            'lng'          => (float)$r['station_lng'],
            'address'      => $r['station_address'],
        );
    }

    wp_send_json_success( array( 'stations' => $stations ) );
}

// ── Override register to deduct from liters quota ─────────────
// Hook into after a successful register to update liters_used
add_action( 'madeya_after_register', 'madeya_update_liters_after_register', 10, 2 );
function madeya_update_liters_after_register( $ctrl_id, $liters ) {
    if ( ! $ctrl_id || $liters <= 0 ) return;
    global $wpdb;
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->prefix}madeya_controllers
         SET liters_used = liters_used + %f WHERE id = %d",
        (float) $liters, (int) $ctrl_id
    ) );
}
