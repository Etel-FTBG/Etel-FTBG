<?php
/**
 * AJAX: Fuel stock — read, set, and log history.
 * All values stored and returned in LITERS.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Get current fuel stock (public) ───────────────────────────
add_action( 'wp_ajax_nopriv_madeya_get_fuel', 'madeya_ajax_get_fuel' );
add_action( 'wp_ajax_madeya_get_fuel',        'madeya_ajax_get_fuel' );

function madeya_ajax_get_fuel() {
    global $wpdb;
    $qt = $wpdb->prefix . 'madeya_queue';

    // If a controller is logged in, return THEIR quota data
    $ctrl_id = (int)( $_SESSION['madeya_ctrl_id'] ?? 0 );
    if ( $ctrl_id ) {
        $ct   = $wpdb->prefix . 'madeya_controllers';
        $ctrl = $wpdb->get_row( $wpdb->prepare(
            "SELECT liters_allocated, liters_used FROM {$ct} WHERE id=%d", $ctrl_id
        ), ARRAY_A );

        $ctrl_quota = $ctrl ? (float)( $ctrl['liters_allocated'] ?? 0 ) : 0;
        $ctrl_used  = $ctrl ? (float)( $ctrl['liters_used']      ?? 0 ) : 0;
        $ctrl_rem   = max( 0, $ctrl_quota - $ctrl_used );

        // Also get how much is allocated IN the queue for this controller's vehicle type
        $vtype     = $_SESSION['madeya_ctrl_vtype'] ?? '';
        $allocated = $vtype
            ? (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(liters),0) FROM {$qt} WHERE vehicle_type=%s AND status='active'", $vtype ) )
            : 0;

        $capacity  = $ctrl_quota > 0 ? $ctrl_quota : (float) get_option( 'madeya_fuel_capacity_liters', 0 );
        $stock     = $ctrl_quota > 0 ? $ctrl_rem   : madeya_get_fuel_stock();
        $pct       = $ctrl_quota > 0 && $ctrl_quota > 0
            ? round( ( $ctrl_rem / $ctrl_quota ) * 100, 1 )
            : ( $capacity > 0 ? round( ( $stock / $capacity ) * 100, 1 ) : 0 );

        wp_send_json_success( array(
            'stock'          => round( $stock, 1 ),
            'capacity'       => round( $capacity, 1 ),
            'allocated'      => round( $allocated, 1 ),
            'remaining'      => round( $stock - $allocated, 1 ),
            'pct'            => $pct,
            // Controller-specific quota fields
            'ctrl_quota'     => round( $ctrl_quota, 1 ),
            'ctrl_used'      => round( $ctrl_used, 1 ),
            'ctrl_remaining' => round( $ctrl_rem, 1 ),
            'has_quota'      => $ctrl_quota > 0,
        ) );
        return;
    }

    // No controller session — return global station stock
    $stock    = madeya_get_fuel_stock();
    $capacity = (float) get_option( 'madeya_fuel_capacity_liters', 0 );
    $allocated = (float) $wpdb->get_var( "SELECT COALESCE(SUM(liters),0) FROM {$qt}" );
    $remaining = max( 0, $stock - $allocated );

    wp_send_json_success( array(
        'stock'     => round( $stock, 1 ),
        'capacity'  => round( $capacity, 1 ),
        'allocated' => round( $allocated, 1 ),
        'remaining' => round( $remaining, 1 ),
        'pct'       => $capacity > 0 ? round( ( $stock / $capacity ) * 100, 1 ) : 0,
        'has_quota' => false,
    ) );
}

// ── Admin: set fuel stock (liters) ────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_admin_set_fuel', 'madeya_ajax_admin_set_fuel' );
add_action( 'wp_ajax_madeya_admin_set_fuel',        'madeya_ajax_admin_set_fuel' );

function madeya_ajax_admin_set_fuel() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );
    if ( empty( $_SESSION['madeya_admin_logged_in'] ) ) wp_send_json_error( array( 'message' => 'Not authorized.' ) );

    $liters   = (float) ( $_POST['liters']   ?? $_POST['gallons'] ?? 0 );   // accept both keys
    $capacity = (float) ( $_POST['capacity'] ?? 0 );
    $note     = sanitize_text_field( $_POST['note'] ?? '' );

    if ( $liters < 0 ) wp_send_json_error( array( 'message' => 'Amount cannot be negative.' ) );

    madeya_set_fuel_stock( $liters, $note ?: 'Admin update', 'set' );

    if ( $capacity > 0 ) {
        update_option( 'madeya_fuel_capacity_liters', $capacity );
        // Legacy compat
        update_option( 'madeya_fuel_capacity_gallons', madeya_liter_to_gal( $capacity ) );
    }

    wp_send_json_success( array( 'message' => 'Fuel stock updated.', 'stock' => round( $liters, 1 ) ) );
}

// ── Admin: fuel log history ────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_admin_fuel_log', 'madeya_ajax_admin_fuel_log' );
add_action( 'wp_ajax_madeya_admin_fuel_log',        'madeya_ajax_admin_fuel_log' );

function madeya_ajax_admin_fuel_log() {
    if ( empty( $_SESSION['madeya_admin_logged_in'] ) ) wp_send_json_error( array( 'message' => 'Not authorized.' ) );

    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}madeya_fuel_log ORDER BY created_at DESC LIMIT 50",
        ARRAY_A
    );

    wp_send_json_success( array( 'log' => $rows ?: array() ) );
}
