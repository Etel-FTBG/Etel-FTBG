<?php
/**
 * Helpers: session bootstrap, auth check, station settings, plate utilities.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Session ────────────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
    if ( ! headers_sent() && session_status() === PHP_SESSION_NONE ) {
        session_start();
    }
}, 1 );

// ── Auth ───────────────────────────────────────────────────────
function madeya_is_logged_in() {
    return ! empty( $_SESSION['madeya_ctrl_id'] );
}

// ── Station settings ───────────────────────────────────────────
function madeya_get_settings() {
    $defaults = array(
        'station_name'   => 'Madeya Gas Station',
        'allowed_parity' => 'both',
        'allowed_types'  => array( 'bajaj', 'qute', 'l_moto', 'private_moto', 'govt_moto', 'private_car', 'minibus', 'damas' ),
        'type_labels'    => array(
            'bajaj'       => 'ባጃጅ (Bajaj)',
            'qute'        => 'ኪዪት (Qute)',
            'l_moto'      => 'L ሞተር ሳይክል (L Motorcycle)',
            'private_moto'=> 'የግል ሞተር ሳይክል (Private Motorcycle)',
            'govt_moto'   => 'የመንግስት ሞተር ሳይክል (Govt Motorcycle)',
            'private_car' => 'የቤት መኪና (Private Car)',
            'minibus'     => 'ሚንባስ ታክስ (Minibus Taxi)',
            'damas'       => 'ዳማስ (Damas)',
        ),
    );
    $saved = get_option( 'madeya_station_settings', array() );
    return wp_parse_args( $saved, $defaults );
}

// ── Plate parity ───────────────────────────────────────────────
function madeya_plate_parity( $plate ) {
    preg_match_all( '/\d/', $plate, $m );
    if ( empty( $m[0] ) ) return 'unknown';
    $last = intval( end( $m[0] ) );
    return ( $last % 2 === 0 ) ? 'even' : 'odd';
}

function madeya_get_parity_label( $parity, $short = false ) {
    if ( $short ) return $parity === 'even' ? 'ሙሉ' : ( $parity === 'odd' ? 'ጎዶሎ' : '—' );
    return $parity === 'even' ? 'ሙሉ (Even)' : ( $parity === 'odd' ? 'ጎዶሎ (Odd)' : 'Unknown' );
}

// ── Unit conversion ────────────────────────────────────────────
// Internal storage stays in liters. "gallons" columns are legacy — 
// we now store liters in the liters column.
define( 'MADEYA_UNIT', 'L' );  // Display unit

function madeya_gal_to_liter( $gal ) { return round( (float)$gal * 3.78541, 2 ); }
function madeya_liter_to_gal( $liter ) { return round( (float)$liter / 3.78541, 2 ); }
function madeya_fmt_liter( $liters ) { return number_format( (float)$liters, 1 ) . ' L'; }

// ── Government Priority Tiers ──────────────────────────────────
// Based on the official government oil supply priority order
function madeya_get_priority_tiers() {
    return array(
        1 => array(
            'key'   => 'oil_transport',
            'label' => 'Oil Transport Trucks',
            'amh'   => 'ነዳጅ ማጓጓዣ',
            'icon'  => 'fa-truck',
            'color' => '#e74c3c',
            'desc'  => 'Vehicles transporting fuel/oil to stations',
        ),
        2 => array(
            'key'   => 'industry_export',
            'label' => 'Industry & Export Companies',
            'amh'   => 'ኢንዱስትሪ እና ወጪ ኢንተርፕራይዝ',
            'icon'  => 'fa-industry',
            'color' => '#e67e22',
            'desc'  => 'High-level industry & export company vehicles',
        ),
        3 => array(
            'key'   => 'construction_govt',
            'label' => 'Government Construction',
            'amh'   => 'የመንግስት ፕሮጀክት ግንባታ',
            'icon'  => 'fa-hard-hat',
            'color' => '#f39c12',
            'desc'  => 'Approved government & big project construction vehicles',
        ),
        4 => array(
            'key'   => 'cargo_goods',
            'label' => 'Cargo & Goods Transport',
            'amh'   => 'ጭነት እና ምርት ማጓጓዣ',
            'icon'  => 'fa-boxes',
            'color' => '#27ae60',
            'desc'  => 'Loaded production, food, goods & cargo vehicles',
        ),
        5 => array(
            'key'   => 'agriculture',
            'label' => 'Agriculture Vehicles',
            'amh'   => 'የግብርና ማሽነሪዎች',
            'icon'  => 'fa-tractor',
            'color' => '#16a085',
            'desc'  => 'Active agricultural development vehicles',
        ),
        6 => array(
            'key'   => 'public_transport',
            'label' => 'Public Transportation',
            'amh'   => 'የህዝብ ትራንስፖርት',
            'icon'  => 'fa-bus',
            'color' => '#2980b9',
            'desc'  => 'City & intercity passenger transport vehicles',
        ),
        7 => array(
            'key'   => 'diesel_other',
            'label' => 'Other Diesel Vehicles',
            'amh'   => 'ሌሎች የናፍጣ ተሸከርካሪዎች',
            'icon'  => 'fa-car',
            'color' => '#8e44ad',
            'desc'  => 'Other diesel-powered private vehicles',
        ),
    );
}

// ── Intelligence: Average serve time (minutes per vehicle) ────
// Calculates rolling average from today's serve history for a controller+type.
// Returns null if there is not enough data.
function madeya_avg_serve_time_minutes( $ctrl_id, $vtype ) {
    global $wpdb;
    $rt = $wpdb->prefix . 'madeya_report';
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT served_at FROM {$rt}
         WHERE controller_id=%d AND vehicle_type=%s
         AND DATE(served_at) = CURDATE()
         ORDER BY served_at DESC LIMIT 20",
        (int) $ctrl_id, $vtype ), ARRAY_A );

    if ( ! $rows || count( $rows ) < 2 ) return null;

    $times = array();
    foreach ( $rows as $r ) {
        $times[] = strtotime( $r['served_at'] );
    }
    rsort( $times );

    $diffs = array();
    for ( $i = 0; $i < count( $times ) - 1; $i++ ) {
        $diff = $times[ $i ] - $times[ $i + 1 ];
        if ( $diff > 0 && $diff < 3600 ) {
            $diffs[] = $diff;
        }
    }
    if ( ! $diffs ) return null;

    return round( array_sum( $diffs ) / count( $diffs ) / 60, 1 );
}

// ── Intelligence: Fuel depletion estimate ─────────────────────
// Based on today's serve rate, predicts when the stock will run out.
function madeya_fuel_depletion_estimate() {
    global $wpdb;
    $rt    = $wpdb->prefix . 'madeya_report';
    $today = current_time( 'Y-m-d' );

    $result = $wpdb->get_row( $wpdb->prepare(
        "SELECT COALESCE(SUM(liters),0) AS total_liters,
                COUNT(*) AS served_count,
                MIN(served_at) AS first_serve,
                MAX(served_at) AS last_serve
         FROM {$rt} WHERE report_date=%s",
        $today ), ARRAY_A );

    if ( ! $result || ! (int) $result['served_count'] ) return null;

    $elapsed_sec = strtotime( $result['last_serve'] ) - strtotime( $result['first_serve'] );
    if ( $elapsed_sec < 60 ) return null;  // Too early — less than 1 min of data

    $liters_per_min = (float) $result['total_liters'] / ( $elapsed_sec / 60 );
    if ( $liters_per_min <= 0 ) return null;

    $stock_remaining = madeya_get_fuel_stock();
    $mins_left       = (int) round( $stock_remaining / $liters_per_min );

    return array(
        'liters_per_hour'   => round( $liters_per_min * 60, 1 ),
        'minutes_remaining' => $mins_left,
        'estimated_empty'   => date( 'H:i', current_time( 'timestamp' ) + $mins_left * 60 ),
        'stock_remaining'   => round( $stock_remaining, 1 ),
    );
}

function madeya_get_tier_by_vehicle( $vehicle_type ) {
    $map = array(
        // Hawassa city vehicle types
        'bajaj'        => 6,  // Public transport
        'qute'         => 6,  // Public transport
        'l_moto'       => 7,  // L motorcycle
        'private_moto' => 7,  // Private motorcycle
        'govt_moto'    => 3,  // Government vehicle
        'private_car'  => 7,  // Private car
        'minibus'      => 6,  // Public transport
        'damas'        => 6,  // Public transport
        // Legacy keys (backward compat)
        'motorcycle'   => 7,
        'private'      => 7,
        'Qute'         => 6,
        'pickup'       => 4,
        'government'   => 3,
        'truck'        => 4,
        'oil_transport'   => 1,
        'industry_export' => 2,
        'construction'    => 3,
        'cargo'           => 4,
        'agriculture'     => 5,
        'public_bus'      => 6,
    );
    return $map[ $vehicle_type ] ?? 7;
}
