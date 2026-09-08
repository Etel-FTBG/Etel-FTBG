<?php
/**
 * Fuel: stock helpers used by both AJAX handlers and queue logic.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function madeya_get_fuel_stock() {
    // Prefer liters key, fall back to legacy gallons key
    $liters = get_option( 'madeya_fuel_stock_liters', null );
    if ( $liters !== null ) return (float) $liters;
    // Migrate legacy gallons → liters on first read
    $gal = (float) get_option( 'madeya_fuel_stock_gallons', 0 );
    if ( $gal > 0 ) { update_option( 'madeya_fuel_stock_liters', madeya_gal_to_liter($gal) ); }
    return madeya_gal_to_liter( $gal );
}

function madeya_set_fuel_stock( $gallons, $note = '', $action = 'set' ) {
    $gallons = max( 0, (float) $gallons );
    update_option( 'madeya_fuel_stock_liters', $gallons );
    update_option( 'madeya_fuel_stock_gallons', madeya_liter_to_gal( $gallons ) ); // legacy compat

    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'madeya_fuel_log',
        array(
            'action'     => $action,
            'gallons'    => $gallons,
            'note'       => sanitize_text_field( $note ),
            'created_at' => current_time( 'mysql' ),
        ),
        array( '%s', '%f', '%s', '%s' )
    );
}

function madeya_deduct_fuel( $gallons ) {
    $cur = madeya_get_fuel_stock();
    $new = max( 0, $cur - (float) $gallons );
    update_option( 'madeya_fuel_stock_liters', $new );
    update_option( 'madeya_fuel_stock_gallons', madeya_liter_to_gal( $new ) ); // legacy compat
    return $new;
}
