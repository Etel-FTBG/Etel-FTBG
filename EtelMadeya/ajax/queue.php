<?php
/**
 * AJAX: Queue operations — register, serve, wait, get queues, report, bulk import, clear.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Register car ───────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_register', 'madeya_ajax_register' );
add_action( 'wp_ajax_madeya_register',        'madeya_ajax_register' );

function madeya_ajax_register() {
    // Rate limit: 1 request per 2 seconds per IP + plate (prevents double-submit, not cross-plate blocking)
    $rate_plate = preg_replace( '/[^A-Z0-9]/', '', strtoupper( $_POST['plate'] ?? '' ) );
    $rate_key   = 'madeya_rate_' . md5( ( $_SERVER['REMOTE_ADDR'] ?? '' ) . $rate_plate );
    if ( get_transient( $rate_key ) ) {
        wp_send_json_error( array( 'message' => 'Too many requests. Wait a moment.' ) );
    }
    set_transient( $rate_key, 1, 2 );

    check_ajax_referer( 'madeya_nonce', 'nonce' );
    if ( ! madeya_is_logged_in() ) wp_send_json_error( array( 'message' => 'Not logged in.', 'code' => 'NOT_AUTH' ) );

    $plate      = preg_replace( '/[^A-Z0-9]/', '', strtoupper( $_POST['plate'] ?? '' ) );
    $vtype      = sanitize_text_field( $_POST['vehicle_type'] ?? '' );
    $queue_type = sanitize_text_field( $_POST['queue_type'] ?? 'normal' );
    // Accept liters param (new) or gallons (legacy — treated as liters)
    $gallons    = (float) ( $_POST['liters'] ?? $_POST['gallons'] ?? 1 );  // stored as liters

    if ( ! in_array( $queue_type, array( 'normal', 'vip' ), true ) ) $queue_type = 'normal';
    if ( ! $plate )   wp_send_json_error( array( 'message' => 'Plate number is required.' ) );
    if ( ! $vtype )   wp_send_json_error( array( 'message' => 'Select a vehicle type first.' ) );

    $cfg = madeya_get_settings();
    if ( ! in_array( $vtype, $cfg['allowed_types'], true ) ) {
        wp_send_json_error( array( 'message' => 'This vehicle type is not served at this station today.' ) );
    }

    // Parity check
    $parity         = madeya_plate_parity( $plate );
    $allowed_parity = ! empty( $_SESSION['madeya_ctrl_parity'] ) ? $_SESSION['madeya_ctrl_parity'] : $cfg['allowed_parity'];
    if ( $allowed_parity !== 'both' && $parity !== $allowed_parity && $parity !== 'unknown' ) {
        $need = $allowed_parity === 'even' ? 'ሙሉ (Even)' : 'ጎዶሎ (Odd)';
        $got  = madeya_get_parity_label( $parity );
        wp_send_json_error( array(
            'message' => "This controller accepts {$need} plates only. This plate is {$got}.",
            'code'    => 'WRONG_PARITY',
        ) );
    }

    global $wpdb;
    $qt    = $wpdb->prefix . 'madeya_queue';
    $rt    = $wpdb->prefix . 'madeya_report';
    $today = current_time( 'Y-m-d' );

    // Already in queue? (Checking plate + vehicle type for Ethiopian categories)
    $in_queue = $wpdb->get_row(
        $wpdb->prepare( "SELECT id, token, position FROM {$qt} WHERE UPPER(plate) = %s AND vehicle_type = %s LIMIT 1", $plate, $vtype ),
        ARRAY_A
    );
    if ( $in_queue ) {
        wp_send_json_error( array(
            'message'  => 'ይህ ታርጋ ቀድሞ ተሰልፏል — መስመር #' . $in_queue['position'] . ' | Token #' . $in_queue['token'],
            'code'     => 'IN_QUEUE',
            'position' => $in_queue['position'],
            'token'    => $in_queue['token'],
        ) );
    }

    // Already served today? (Checking plate + vehicle type)
    $served = $wpdb->get_row(
        $wpdb->prepare( "SELECT id, served_at FROM {$rt} WHERE UPPER(plate) = %s AND vehicle_type = %s AND report_date = %s LIMIT 1", $plate, $vtype, $today ),
        ARRAY_A
    );
    if ( $served ) {
        $t = mysql2date( 'H:i', $served['served_at'] );
        wp_send_json_error( array(
            'message'   => "ይህ መኪና ዛሬ {$t} ሰዓት ነዳጅ ወስዷል! (Served today at {$t})",
            'code'      => 'SERVED_TODAY',
            'served_at' => $t,
        ) );
    }

    // ── Fuel check — use controller's own quota ────────────────
    // If controller has a personal quota (liters_allocated > 0), enforce it.
    // If quota is 0, the controller has unlimited access (skip fuel check).
    $ctrl_id_reg = (int) ( $_SESSION['madeya_ctrl_id'] ?? 0 );
    $ctrl_row    = $ctrl_id_reg
        ? $wpdb->get_row( $wpdb->prepare(
            "SELECT liters_allocated, liters_used FROM {$wpdb->prefix}madeya_controllers WHERE id=%d",
            $ctrl_id_reg ), ARRAY_A )
        : null;

    $ctrl_quota     = $ctrl_row ? (float)( $ctrl_row['liters_allocated'] ?? 0 ) : 0;
    $ctrl_used      = $ctrl_row ? (float)( $ctrl_row['liters_used']      ?? 0 ) : 0;
    $ctrl_remaining = max( 0, $ctrl_quota - $ctrl_used );

    if ( $ctrl_quota > 0 ) {
        // Controller has an assigned quota — check against it
        if ( $ctrl_remaining < $gallons ) {
            wp_send_json_error( array(
                'message'   => sprintf(
                    'Your fuel quota only has %.1f L remaining. Requested: %.1f L.',
                    $ctrl_remaining, $gallons
                ),
                'code'      => 'QUOTA_LOW',
                'available' => round( $ctrl_remaining, 1 ),
                'requested' => round( $gallons, 1 ),
            ) );
        }
        $stock     = $ctrl_quota;
        $available = $ctrl_remaining;
    } else {
        // Controller has unlimited quota — check global station stock
        $stock     = madeya_get_fuel_stock();   // in liters
        $allocated = (float) $wpdb->get_var( "SELECT COALESCE(SUM(liters),0) FROM {$qt}" );
        $available = $stock - $allocated;
        if ( $stock > 0 && $available < $gallons ) {
            wp_send_json_error( array(
                'message'   => sprintf(
                    'Station stock only has %.1f L available. Requested: %.1f L.',
                    $available, $gallons
                ),
                'code'      => 'LOW_FUEL',
                'available' => round( $available, 1 ),
                'requested' => round( $gallons, 1 ),
            ) );
        }
        if ( $stock === 0.0 ) {
            // No global stock set and no controller quota — allow (no limit configured)
            $available = PHP_INT_MAX;
        }
    }

    // Atomic token assignment
    $wpdb->query( "LOCK TABLES {$qt} WRITE, {$rt} WRITE" );
    $max_q    = (int) $wpdb->get_var( "SELECT MAX(CAST(token AS UNSIGNED)) FROM {$qt}" );
    $max_r    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(CAST(token AS UNSIGNED)) FROM {$rt} WHERE report_date = %s", $today ) );
    $token    = str_pad( max( $max_q, $max_r ) + 1, 6, '0', STR_PAD_LEFT );
    $wpdb->query( "UNLOCK TABLES" );

    // Assign position: new vehicles always go to the END of the queue
    // Find the highest position number for this vehicle type and add 1
    $priority_tier = madeya_get_tier_by_vehicle( $vtype );
    $max_position = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT MAX(position) FROM {$qt}
         WHERE status='active' AND queue_type=%s AND vehicle_type=%s",
        $queue_type, $vtype
    ) );
    $position = $max_position + 1;

    $inserted = $wpdb->insert( $qt, array(
        'plate'         => $plate,
        'vehicle_type'  => $vtype,
        'plate_parity'  => $parity,
        'token'         => $token,
        'position'      => $position,
        'queue_type'    => $queue_type,
        'gallons'       => madeya_liter_to_gal( $gallons ),
        'liters'        => $gallons,
        'priority_tier' => isset($_POST['priority_tier'])
            ? max(1, min(7, intval($_POST['priority_tier'])))
            : madeya_get_tier_by_vehicle( $vtype ),
        'controller_id' => (int)( $_SESSION['madeya_ctrl_id'] ?? 0 ),
        'registered_at' => current_time( 'mysql' ),
    ), array( '%s', '%s', '%s', '%s', '%d', '%s', '%f', '%f', '%d', '%d', '%s' ) );

    if ( $inserted === false ) {
        wp_send_json_error( array( 'message' => 'Database error: ' . $wpdb->last_error, 'code' => 'DB_ERROR' ) );
    }

    $new_allocated  = (float) $wpdb->get_var( "SELECT COALESCE(SUM(liters),0) FROM {$qt}" ); // liters
    $remaining_fuel = $stock - madeya_liter_to_gal( $new_allocated );

    // Update controller liters_used
    do_action( 'madeya_after_register', (int)( $_SESSION['madeya_ctrl_id'] ?? 0 ), $gallons );

    $pt = isset($_POST['priority_tier']) ? max(1,min(7,intval($_POST['priority_tier']))) : madeya_get_tier_by_vehicle($vtype);

    wp_send_json_success( array(
        'token'          => $token,
        'position'       => $position,
        'plate'          => $plate,
        'vehicle_type'   => $vtype,
        'type_label'     => $cfg['type_labels'][ $vtype ] ?? $vtype,
        'parity'         => $parity,
        'parity_label'   => madeya_get_parity_label( $parity ),
        'queue_type'     => $queue_type,
        'gallons'        => round( $gallons, 1 ),
        'remaining_fuel' => round( $available - $gallons, 1 ),
        'fuel_stock'     => round( $stock, 1 ),
        'priority_tier'  => $pt,
        'ctrl_quota_rem' => $ctrl_quota > 0 ? round( $ctrl_remaining - $gallons, 1 ) : null,
    ) );
}

// ── Get active queue ───────────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_get_queue', 'madeya_ajax_get_queue' );
add_action( 'wp_ajax_madeya_get_queue',        'madeya_ajax_get_queue' );

function madeya_ajax_get_queue() {
    global $wpdb;
    $qt      = $wpdb->prefix . 'madeya_queue';
    $ctrl_id = (int)( $_SESSION['madeya_ctrl_id'] ?? 0 );
    if ( ! $ctrl_id ) { wp_send_json_success( array( 'queue' => array(), 'total' => 0 ) ); return; }

    $cfg            = madeya_get_settings();
    $allowed_parity = ! empty( $_SESSION['madeya_ctrl_parity'] ) ? $_SESSION['madeya_ctrl_parity'] : $cfg['allowed_parity'];

    // Pagination parameters (no search filter - we highlight instead)
    $page = max(1, (int)($_POST['page'] ?? 1));
    $per_page = max(1, min(100, (int)($_POST['per_page'] ?? 50)));
    $offset = ($page - 1) * $per_page;

    // Build WHERE clause
    $where = "controller_id=%d AND status='active' AND queue_type='normal'";
    $params = array($ctrl_id);

    if ( $allowed_parity !== 'both' ) {
        $where .= " AND (plate_parity=%s OR plate_parity='unknown')";
        $params[] = $allowed_parity;
    }

    // Get total count
    $count_sql = "SELECT COUNT(*) FROM {$qt} WHERE {$where}";
    $total_records = (int)$wpdb->get_var($wpdb->prepare($count_sql, $params));
    $total_pages = ceil($total_records / $per_page);

    // Get paginated results - ORDER BY position first for correct queue order
    $sql = "SELECT * FROM {$qt} WHERE {$where} ORDER BY position ASC, priority_tier ASC, registered_at ASC LIMIT %d OFFSET %d";
    $params[] = $per_page;
    $params[] = $offset;
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

    wp_send_json_success(array(
        'queue' => $rows ?: array(),
        'total' => count((array)$rows),
        'pagination' => array(
            'current_page' => $page,
            'per_page' => $per_page,
            'total_records' => $total_records,
            'total_pages' => $total_pages,
            'has_prev' => $page > 1,
            'has_next' => $page < $total_pages
        )
    ));
}

// ── Get waiting queue ──────────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_get_waiting', 'madeya_ajax_get_waiting' );
add_action( 'wp_ajax_madeya_get_waiting',        'madeya_ajax_get_waiting' );

function madeya_ajax_get_waiting() {
    global $wpdb;
    $qt      = $wpdb->prefix . 'madeya_queue';
    $ctrl_id = (int)( $_SESSION['madeya_ctrl_id'] ?? 0 );
    if ( ! $ctrl_id ) { wp_send_json_success( array( 'queue' => array(), 'total' => 0 ) ); return; }
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$qt} WHERE controller_id=%d AND status='waiting' ORDER BY priority_tier ASC, registered_at ASC",
        $ctrl_id ), ARRAY_A );
    wp_send_json_success( array( 'queue' => $rows ?: array(), 'total' => count( (array) $rows ) ) );
}

// ── Get VIP queue ──────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_get_vip_queue', 'madeya_ajax_get_vip_queue' );
add_action( 'wp_ajax_madeya_get_vip_queue',        'madeya_ajax_get_vip_queue' );

function madeya_ajax_get_vip_queue() {
    global $wpdb;
    $qt      = $wpdb->prefix . 'madeya_queue';
    $ctrl_id = (int)( $_SESSION['madeya_ctrl_id'] ?? 0 );
    if ( ! $ctrl_id ) { wp_send_json_success( array( 'queue' => array(), 'total' => 0 ) ); return; }
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$qt} WHERE controller_id=%d AND queue_type='vip' AND status='active' ORDER BY priority_tier ASC, registered_at ASC",
        $ctrl_id ), ARRAY_A );
    wp_send_json_success( array( 'queue' => $rows ?: array(), 'total' => count( (array) $rows ) ) );
}

// ── Serve a car ────────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_serve', 'madeya_ajax_serve' );
add_action( 'wp_ajax_madeya_serve',        'madeya_ajax_serve' );

function madeya_ajax_serve() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );
    if ( ! madeya_is_logged_in() ) wp_send_json_error( array( 'message' => 'Not logged in.' ) );

    $id = intval( $_POST['id'] ?? 0 );
    if ( ! $id ) wp_send_json_error( array( 'message' => 'Invalid ID.' ) );

    global $wpdb;
    $qt      = $wpdb->prefix . 'madeya_queue';
    $rt      = $wpdb->prefix . 'madeya_report';
    $ctrl_id = $_SESSION['madeya_ctrl_id'] ?? 0;

    $car        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$qt} WHERE id=%d", $id ), ARRAY_A );
    $controller = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}madeya_controllers WHERE id=%d", $ctrl_id ), ARRAY_A );

    if ( ! $car ) wp_send_json_error( array( 'message' => 'Car not found in queue.' ) );
    // Ensure controller can only serve their own registrations
    if ( $ctrl_id && (int)( $car['controller_id'] ?? 0 ) !== $ctrl_id ) {
        wp_send_json_error( array( 'message' => 'This vehicle was not registered by you.' ) );
    }

    // Move to report
    $wpdb->insert( $rt, array(
        'plate'         => $car['plate'],
        'vehicle_type'  => $car['vehicle_type'],
        'plate_parity'  => $car['plate_parity'],
        'token'         => $car['token'],
        'position'      => $car['position'],
        'queue_type'    => $car['queue_type'] ?? 'normal',
        'gallons'       => (float) ( $car['gallons'] ?? 0 ),
        'controller_id' => (int)( $car['controller_id'] ?? $ctrl_id ),
        'served_at'     => current_time( 'mysql' ),
        'report_date'   => current_time( 'Y-m-d' ),
    ), array( '%s', '%s', '%s', '%s', '%d', '%s', '%f', '%d', '%s', '%s' ) );

    // Deduct from global fuel stock
    $remaining = madeya_deduct_fuel( (float) ( $car['gallons'] ?? 0 ) );

    // Update this controller's personal fuel_used
    if ( $ctrl_id && (float) ( $car['gallons'] ?? 0 ) > 0 ) {
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}madeya_controllers SET fuel_used = fuel_used + %f WHERE id = %d",
            (float) $car['gallons'], $ctrl_id
        ) );
    }

    // Remove from queue and renumber
    $wpdb->delete( $qt, array( 'id' => $id ), array( '%d' ) );
    
    // Renumber ALL positions in the queue preserving original order
    // This ensures positions are always 1, 2, 3, 4... without gaps
    // IMPORTANT: ORDER BY position ASC keeps the original import order intact
    $queue_type = $car['queue_type'] ?? 'normal';
    $ctrl_id_for_renum = (int)( $car['controller_id'] ?? $ctrl_id );
    
    // Get IDs in the correct original order (by position, which reflects import order)
    $ordered_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT id FROM {$qt}
        WHERE controller_id=%d AND status='active' AND queue_type=%s
        ORDER BY position ASC",
        $ctrl_id_for_renum, $queue_type
    ) );
    
    // Update positions sequentially: 1, 2, 3...
    $new_pos = 1;
    foreach ( $ordered_ids as $vehicle_id ) {
        $wpdb->update(
            $qt,
            array( 'position' => $new_pos ),
            array( 'id' => (int)$vehicle_id ),
            array( '%d' ),
            array( '%d' )
        );
        $new_pos++;
    }
    
    wp_send_json_success( array(
        'plate'          => $car['plate'],
        'token'          => $car['token'],
        'gallons_served' => round( (float)( $car['liters'] ?? $car['gallons'] ?? 0 ), 1 ),
        'remaining_fuel' => round( $remaining, 2 ),
    ) );
}

// ── Move car to waiting ────────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_wait_car', 'madeya_ajax_wait_car' );
add_action( 'wp_ajax_madeya_wait_car',        'madeya_ajax_wait_car' );

function madeya_ajax_wait_car() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );
    if ( ! madeya_is_logged_in() ) wp_send_json_error( array( 'message' => 'Not logged in.' ) );

    $id = intval( $_POST['id'] ?? 0 );
    if ( ! $id ) wp_send_json_error( array( 'message' => 'Invalid ID.' ) );

    global $wpdb;
    $qt         = $wpdb->prefix . 'madeya_queue';
    $ctrl_id    = $_SESSION['madeya_ctrl_id'] ?? 0;
    $car        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$qt} WHERE id=%d", $id ), ARRAY_A );
    $controller = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}madeya_controllers WHERE id=%d", $ctrl_id ), ARRAY_A );

    if ( ! $car ) wp_send_json_error( array( 'message' => 'Car not found in queue.' ) );
    if ( $ctrl_id && (int)( $car['controller_id'] ?? 0 ) !== $ctrl_id ) {
        wp_send_json_error( array( 'message' => 'This vehicle was not registered by you.' ) );
    }

    $result = $wpdb->update( $qt, array( 'status' => 'waiting' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
    if ( $result === false ) wp_send_json_error( array( 'message' => 'Database error: ' . $wpdb->last_error ) );

    wp_send_json_success( array( 'plate' => $car['plate'], 'token' => $car['token'] ) );
}

// ── Driver lookup ──────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_driver_lookup', 'madeya_ajax_driver_lookup' );
add_action( 'wp_ajax_madeya_driver_lookup',        'madeya_ajax_driver_lookup' );

function madeya_ajax_driver_lookup() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );

    $plate = strtoupper( trim( sanitize_text_field( $_POST['plate'] ?? '' ) ) );
    // Strip hyphens/spaces for flexible matching (AA-12345 → AA12345)
    $plate_clean = preg_replace( '/[^A-Z0-9]/', '', $plate );
    if ( ! $plate_clean ) wp_send_json_error( array( 'message' => 'Enter your plate number.' ) );

    $parity = madeya_plate_parity( $plate_clean );

    global $wpdb;
    $qt  = $wpdb->prefix . 'madeya_queue';
    $ct  = $wpdb->prefix . 'madeya_controllers';

    // JOIN controllers to filter by parity (even/odd) so drivers only see their matching station
    $car = $wpdb->get_row( $wpdb->prepare(
        "SELECT q.*, c.allowed_parity AS ctrl_parity
         FROM {$qt} q
         LEFT JOIN {$ct} c ON q.controller_id = c.id
         WHERE UPPER(REPLACE(q.plate,'-','')) = %s
         LIMIT 1",
        $plate_clean ), ARRAY_A );

    // If parity filter mismatch, treat as not-in-queue (prevents cross-station confusion)
    if ( $car ) {
        $ctrl_parity = isset( $car['ctrl_parity'] ) ? $car['ctrl_parity'] : 'both';
        if ( $ctrl_parity !== 'both' && $parity !== 'unknown' && $parity !== $ctrl_parity ) {
            $car = null;
        }
    }

    if ( ! $car ) {
        // Check if already served today
        $rt       = $wpdb->prefix . 'madeya_report';
        $today    = current_time( 'Y-m-d' );
        $served   = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$rt} WHERE UPPER(REPLACE(plate,'-',''))=%s AND report_date=%s LIMIT 1",
            $plate_clean, $today ), ARRAY_A );

        if ( $served ) {
            $labels    = madeya_get_settings()['type_labels'];
            $tier_defs = madeya_get_priority_tiers();
            $s_tier    = (int)( $served['priority_tier'] ?? 7 );
            $served_at = mysql2date( 'H:i', $served['served_at'] );
            wp_send_json_success( array(
                'plate'         => $served['plate'],
                'token'         => $served['token'],
                // Use token as the serve-number (sequential), not the old queue slot position
                'position'      => intval( $served['token'] ),
                'vehicle_type'  => $served['vehicle_type'],
                'type_label'    => $labels[ $served['vehicle_type'] ] ?? $served['vehicle_type'],
                'parity'        => $served['plate_parity'],
                'parity_label'  => madeya_get_parity_label( $served['plate_parity'] ),
                'priority_tier' => $s_tier,
                'tier_label'    => $tier_defs[ $s_tier ]['label'] ?? 'Other',
                'tier_amh'      => $tier_defs[ $s_tier ]['amh']   ?? '',
                'tier_color'    => $tier_defs[ $s_tier ]['color'] ?? '#8e44ad',
                'tier_icon'     => $tier_defs[ $s_tier ]['icon']  ?? 'fa-car',
                'total'         => 0,
                'ahead'         => 0,
                'same_tier_ahead' => 0,
                'tier_breakdown'  => array(),
                'notify30'      => false,
                'notify_same'   => false,
                'station'       => null,
                'already_served'=> true,
                'served_at'     => $served_at,
                'liters'        => (float)( $served['liters'] ?? $served['gallons'] ?? 0 ),
                'warning'       => "ይህ መኪና ዛሬ {$served_at} ሰዓት ነዳጅ ወስዷል! (Already served today at {$served_at})",
            ) );
        }

        wp_send_json_error( array(
            'message' => "Your plate is not in today's queue.",
            'code'    => 'NOT_FOUND',
        ) );
    }

    $my_tier     = (int)( $car['priority_tier'] ?? 7 );
    $my_vtype    = $car['vehicle_type'] ?? '';
    $queue_type  = $car['queue_type']   ?? 'normal';
    $my_ctrl_id  = (int)( $car['controller_id'] ?? 0 );
    $my_db_pos   = (int) $car['position'];

    // Count how many active cars in the same controller have a lower position (i.e. are ahead)
    $ahead_total = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$qt}
         WHERE controller_id=%d AND queue_type=%s AND vehicle_type=%s AND status='active'
         AND position < %d",
        $my_ctrl_id, $queue_type, $my_vtype, $my_db_pos ) );

    $my_pos = $ahead_total + 1;

    // Total cars in queue scoped to this controller
    $total = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$qt}
         WHERE controller_id=%d AND queue_type=%s AND vehicle_type=%s AND status='active'",
        $my_ctrl_id, $queue_type, $my_vtype ) );

    // Cars ahead of same priority tier scoped to this controller
    $same_tier_ahead = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$qt}
         WHERE controller_id=%d AND queue_type=%s AND vehicle_type=%s AND status='active'
         AND priority_tier=%d AND position < %d",
        $my_ctrl_id, $queue_type, $my_vtype, $my_tier, $my_db_pos ) );

    // Cars ahead per tier scoped to this controller
    $tier_breakdown = $wpdb->get_results( $wpdb->prepare(
        "SELECT priority_tier, COUNT(*) as cnt
         FROM {$qt}
         WHERE controller_id=%d AND queue_type=%s AND vehicle_type=%s AND status='active'
         AND position < %d
         GROUP BY priority_tier
         ORDER BY priority_tier ASC",
        $my_ctrl_id, $queue_type, $my_vtype, $my_db_pos ), ARRAY_A );
		
    // Station location — find the controller handling my vehicle type
    $station = $wpdb->get_row( $wpdb->prepare(
        "SELECT station_name, station_lat, station_lng, station_address
         FROM {$ct}
         WHERE vehicle_type=%s AND station_lat != 0 AND station_lng != 0
         LIMIT 1",
        $my_vtype ), ARRAY_A );

    // If no direct match, get any station with a location set
    if ( ! $station ) {
        $station = $wpdb->get_row(
            "SELECT station_name, station_lat, station_lng, station_address
             FROM {$ct} WHERE station_lat != 0 AND station_lng != 0 LIMIT 1",
            ARRAY_A );
    }

    $labels    = madeya_get_settings()['type_labels'];
    $tier_defs = madeya_get_priority_tiers();

    // ── Predictive wait time ──
    $avg_serve_min = madeya_avg_serve_time_minutes( $my_ctrl_id, $my_vtype );
    $est_wait_min  = ( $avg_serve_min !== null && $ahead_total > 0 )
        ? (int) round( $avg_serve_min * $ahead_total )
        : null;

    wp_send_json_success( array(
        'plate'            => $car['plate'],
        'token'            => $car['token'],
        'position'         => $my_pos,
        'controller_id'    => $my_ctrl_id,
        'vehicle_type'     => $my_vtype,
        'type_label'       => $labels[ $my_vtype ] ?? $my_vtype,
        'parity'           => $car['plate_parity'],
        'parity_label'     => madeya_get_parity_label( $car['plate_parity'] ),
        'priority_tier'    => $my_tier,
        'tier_label'       => $tier_defs[ $my_tier ]['label'] ?? 'Other',
        'tier_amh'         => $tier_defs[ $my_tier ]['amh']   ?? '',
        'tier_color'       => $tier_defs[ $my_tier ]['color'] ?? '#8e44ad',
        'tier_icon'        => $tier_defs[ $my_tier ]['icon']  ?? 'fa-car',
        'total'            => $total,
        'ahead'            => $ahead_total,
        'same_tier_ahead'  => $same_tier_ahead,
        'tier_breakdown'   => $tier_breakdown,
        'notify30'         => ( $ahead_total <= 30 && $ahead_total >= 0 ),
        'notify_same'      => ( $same_tier_ahead <= 5 ),
        'avg_serve_min'    => $avg_serve_min,
        'est_wait_min'     => $est_wait_min,
        'station'          => $station ? array(
            'name'    => $station['station_name'],
            'lat'     => (float)$station['station_lat'],
            'lng'     => (float)$station['station_lng'],
            'address' => $station['station_address'],
        ) : null,
    ) );
}

// ── Driver: get active queue for a specific controller ─────────
add_action( 'wp_ajax_nopriv_madeya_driver_active_queue', 'madeya_ajax_driver_active_queue' );
add_action( 'wp_ajax_madeya_driver_active_queue',        'madeya_ajax_driver_active_queue' );

function madeya_ajax_driver_active_queue() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );

    $ctrl_id = (int)( $_POST['controller_id'] ?? 0 );
    if ( ! $ctrl_id ) {
        wp_send_json_error( array( 'message' => 'No controller specified.' ) );
    }

    global $wpdb;
    $qt = $wpdb->prefix . 'madeya_queue';
    $ct = $wpdb->prefix . 'madeya_controllers';

    // Get station info
    $station = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, station_name, station_address FROM {$ct} WHERE id=%d",
        $ctrl_id
    ), ARRAY_A );

    // Get full active queue for this controller, in position order
    $cfg    = madeya_get_settings();
    $labels = $cfg['type_labels'] ?? array();

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT plate, vehicle_type, plate_parity, position, queue_type, token, priority_tier, registered_at
         FROM {$qt}
         WHERE controller_id=%d AND status='active' AND queue_type='normal'
         ORDER BY position ASC",
        $ctrl_id
    ), ARRAY_A );

    // Add type labels
    foreach ( $rows as &$r ) {
        $r['type_label'] = isset( $labels[ $r['vehicle_type'] ] ) ? $labels[ $r['vehicle_type'] ] : $r['vehicle_type'];
    }
    unset( $r );

    wp_send_json_success( array(
        'station' => $station,
        'queue'   => $rows ?: array(),
        'total'   => count( $rows ),
    ) );
}

// ── Daily report ───────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_report', 'madeya_ajax_report' );
add_action( 'wp_ajax_madeya_report',        'madeya_ajax_report' );

function madeya_ajax_report() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );

    $date    = sanitize_text_field( $_POST['date'] ?? current_time( 'Y-m-d' ) );
    $ctrl_id = (int)( $_SESSION['madeya_ctrl_id'] ?? 0 );
    global $wpdb;
    $rt   = $wpdb->prefix . 'madeya_report';
    $rows = $ctrl_id
        ? $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$rt} WHERE controller_id=%d AND report_date=%s ORDER BY served_at ASC",
            $ctrl_id, $date ), ARRAY_A )
        : array();

    wp_send_json_success( array( 'report' => $rows ?: array(), 'total' => count( (array) $rows ) ) );
}

// ── Clear queue ────────────────────────────────────────────────
add_action( 'wp_ajax_madeya_clear_queue', 'madeya_ajax_clear_queue' );

function madeya_ajax_clear_queue() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );
    if ( ! madeya_is_logged_in() && ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Not allowed.' ) );
    }
    
    // Only clear the current controller's queue, not all controllers
    $ctrl_id = (int)( $_SESSION['madeya_ctrl_id'] ?? 0 );
    if ( ! $ctrl_id ) {
        wp_send_json_error( array( 'message' => 'No controller session found.' ) );
    }
    
    global $wpdb;
    $deleted = $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}madeya_queue WHERE controller_id = %d",
        $ctrl_id
    ) );
    
    wp_send_json_success( array(
        'message' => sprintf( 'Cleared %d vehicle(s) from your queue.', $deleted ),
        'deleted' => $deleted
    ) );
}

// ── Import pre-served (already served, block from queue) ──────
add_action( 'wp_ajax_nopriv_madeya_import_served', 'madeya_ajax_import_served' );
add_action( 'wp_ajax_madeya_import_served',        'madeya_ajax_import_served' );

function madeya_ajax_import_served() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );
    if ( ! madeya_is_logged_in() ) wp_send_json_error( array( 'message' => 'Not logged in.' ) );

    $plates_raw = $_POST['plates'] ?? '';
    $vtype      = sanitize_text_field( $_POST['vehicle_type'] ?? '' );
    if ( ! $plates_raw ) wp_send_json_error( array( 'message' => 'No plates provided.' ) );
    if ( ! $vtype )      wp_send_json_error( array( 'message' => 'Vehicle type required.' ) );

    $lines  = preg_split( '/[\r\n]+/', $plates_raw );
    $plates = array();
    foreach ( $lines as $line ) {
        $p = preg_replace( '/[^A-Z0-9]/', '', strtoupper( trim( $line ) ) );
        if ( $p && strlen( $p ) >= 3 ) $plates[] = $p;
    }
    $plates = array_unique( $plates );
    if ( ! $plates ) wp_send_json_error( array( 'message' => 'No valid plates found.' ) );

    global $wpdb;
    $rt      = $wpdb->prefix . 'madeya_report';
    $qt      = $wpdb->prefix . 'madeya_queue';
    $today   = current_time( 'Y-m-d' );
    $results = array();

    foreach ( $plates as $plate ) {
        // Already in report today (served or pre_served)?
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$rt} WHERE UPPER(plate)=%s AND vehicle_type=%s AND report_date=%s LIMIT 1",
            $plate, $vtype, $today
        ) );
        if ( $exists ) {
            $results[] = array( 'plate' => $plate, 'status' => 'skipped', 'reason' => 'Already in report' );
            continue;
        }

        // Remove from queue if present
        $wpdb->delete( $qt, array( 'plate' => $plate, 'vehicle_type' => $vtype ), array( '%s', '%s' ) );

        $parity = madeya_plate_parity( $plate );
        $ins = $wpdb->insert( $rt, array(
            'plate'        => $plate,
            'vehicle_type' => $vtype,
            'plate_parity' => $parity,
            'token'        => '000000',
            'position'     => 0,
            'queue_type'   => 'normal',
            'gallons'      => 0,
            'liters'       => 0,
            'status'       => 'pre_served',
            'served_at'    => current_time( 'mysql' ),
            'report_date'  => $today,
        ), array( '%s','%s','%s','%s','%d','%s','%f','%f','%s','%s','%s' ) );

        if ( $ins === false ) {
            $results[] = array( 'plate' => $plate, 'status' => 'error', 'reason' => 'DB: ' . $wpdb->last_error );
        } else {
            $results[] = array( 'plate' => $plate, 'status' => 'ok' );
        }
    }

    $ok      = count( array_filter( $results, fn($r) => $r['status'] === 'ok' ) );
    $skipped = count( array_filter( $results, fn($r) => $r['status'] === 'skipped' ) );
    $errors  = count( array_filter( $results, fn($r) => $r['status'] === 'error' ) );

    // Renumber remaining positions so there are no gaps (1, 2, 3...)
    // This is critical — without it, positions stay at their old numbers
    // even after many plates have been removed
    $ctrl_id = (int)( $_SESSION['madeya_ctrl_id'] ?? 0 );
    if ( $ctrl_id && $ok > 0 ) {
        $ordered_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$qt}
            WHERE controller_id=%d AND status='active' AND queue_type='normal'
            ORDER BY position ASC",
            $ctrl_id
        ) );

        $new_pos = 1;
        foreach ( $ordered_ids as $vehicle_id ) {
            $wpdb->update(
                $qt,
                array( 'position' => $new_pos ),
                array( 'id' => (int)$vehicle_id ),
                array( '%d' ),
                array( '%d' )
            );
            $new_pos++;
        }
    }

    wp_send_json_success( array(
        'results' => $results,
        'summary' => array( 'saved' => $ok, 'skipped' => $skipped, 'errors' => $errors ),
    ) );
}

// ── Bulk import ────────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_bulk_import', 'madeya_ajax_bulk_import' );
add_action( 'wp_ajax_madeya_bulk_import',        'madeya_ajax_bulk_import' );

function madeya_ajax_bulk_import() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );
    if ( ! madeya_is_logged_in() ) wp_send_json_error( array( 'message' => 'Not logged in.' ) );

    $plates_raw = $_POST['plates'] ?? '';
    $vtype      = sanitize_text_field( $_POST['vehicle_type'] ?? '' );
    $queue_type = sanitize_text_field( $_POST['queue_type'] ?? 'normal' );
    if ( ! in_array( $queue_type, array( 'normal', 'vip' ), true ) ) $queue_type = 'normal';
    if ( ! $plates_raw ) wp_send_json_error( array( 'message' => 'No plates provided.' ) );
    if ( ! $vtype )      wp_send_json_error( array( 'message' => 'Vehicle type required.' ) );

    $cfg = madeya_get_settings();
    if ( ! in_array( $vtype, $cfg['allowed_types'], true ) ) {
        wp_send_json_error( array( 'message' => 'Vehicle type not served at this station.' ) );
    }

    // Parse lines — handles "Page,Plate" CSV or plain list
    $lines  = preg_split( '/[\r\n]+/', $plates_raw );
    $plates = array();
    foreach ( $lines as $line ) {
        $parts = explode( ',', $line );
        $p     = preg_replace( '/[^A-Z0-9]/', '', strtoupper( trim( end( $parts ) ) ) );
        if ( $p && strlen( $p ) >= 3 ) $plates[] = $p;
    }
    $plates = array_unique( $plates );
    if ( ! $plates ) wp_send_json_error( array( 'message' => 'No valid plates found.' ) );

    global $wpdb;
    $qt             = $wpdb->prefix . 'madeya_queue';
    $rt             = $wpdb->prefix . 'madeya_report';
    $today          = current_time( 'Y-m-d' );
    $allowed_parity = ! empty( $_SESSION['madeya_ctrl_parity'] ) ? $_SESSION['madeya_ctrl_parity'] : $cfg['allowed_parity'];
    $results        = array();

    foreach ( $plates as $plate ) {
        $parity = madeya_plate_parity( $plate );

        if ( $allowed_parity !== 'both' && $parity !== $allowed_parity && $parity !== 'unknown' ) {
            $need      = $allowed_parity === 'even' ? 'ሙሉ (Even)' : 'ጎዶሎ (Odd)';
            $results[] = array( 'plate' => $plate, 'status' => 'skipped', 'reason' => 'Wrong parity — controller accepts ' . $need . ' only' );
            continue;
        }

        $in_q = $wpdb->get_row( $wpdb->prepare( "SELECT id,token,position FROM {$qt} WHERE UPPER(plate)=%s AND vehicle_type=%s LIMIT 1", $plate, $vtype ), ARRAY_A );
        if ( $in_q ) {
            $results[] = array( 'plate' => $plate, 'status' => 'skipped', 'reason' => 'Already in queue #' . $in_q['position'] . ' Token #' . $in_q['token'] );
            continue;
        }
// Check Served Today (Plate + Type)
        $srv = $wpdb->get_row( $wpdb->prepare( "SELECT served_at FROM {$rt} WHERE UPPER(plate)=%s AND vehicle_type=%s AND report_date=%s LIMIT 1", $plate, $vtype, $today ), ARRAY_A );
        if ( $srv ) {
            $results[] = array( 'plate' => $plate, 'status' => 'skipped', 'reason' => 'Served today at ' . mysql2date( 'H:i', $srv['served_at'] ) );
            continue;
        }

        $wpdb->query( "LOCK TABLES {$qt} WRITE, {$rt} WRITE" );
        $mq       = (int) $wpdb->get_var( "SELECT MAX(CAST(token AS UNSIGNED)) FROM {$qt}" );
        $mr       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(CAST(token AS UNSIGNED)) FROM {$rt} WHERE report_date=%s", $today ) );
        $token    = str_pad( max( $mq, $mr ) + 1, 6, '0', STR_PAD_LEFT );
        $ctrl_id  = (int)( $_SESSION['madeya_ctrl_id'] ?? 0 );
        $position = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(position) FROM {$qt} WHERE queue_type=%s AND vehicle_type=%s AND controller_id=%d",
            $queue_type, $vtype, $ctrl_id
        ) ) + 1;
        $ins      = $wpdb->insert( $qt, array(
            'plate'         => $plate,
            'vehicle_type'  => $vtype,
            'plate_parity'  => $parity,
            'token'         => $token,
            'position'      => $position,
            'queue_type'    => $queue_type,
            'controller_id' => $ctrl_id,
            'registered_at' => current_time( 'mysql' ),
        ), array( '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ) );
        $wpdb->query( "UNLOCK TABLES" );

        if ( $ins === false ) {
            $results[] = array( 'plate' => $plate, 'status' => 'error', 'reason' => 'DB: ' . $wpdb->last_error );
        } else {
            $results[] = array( 'plate' => $plate, 'status' => 'ok', 'token' => $token, 'position' => $position, 'parity' => $parity );
        }
    }

    $ok      = count( array_filter( $results, fn( $r ) => $r['status'] === 'ok' ) );
    $skipped = count( array_filter( $results, fn( $r ) => $r['status'] === 'skipped' ) );
    $errors  = count( array_filter( $results, fn( $r ) => $r['status'] === 'error' ) );

    wp_send_json_success( array(
        'results' => $results,
        'summary' => array( 'imported' => $ok, 'skipped' => $skipped, 'errors' => $errors ),
    ) );
}
