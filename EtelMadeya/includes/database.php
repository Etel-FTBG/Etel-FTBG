<?php
/**
 * Database: table creation, activation hook, and live upgrades.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Activation hook ────────────────────────────────────────────
// Activation hook registered in madeya-gas-queue.php

function madeya_activate() {
    madeya_setup_db();

    global $wpdb;
    $ct = $wpdb->prefix . 'madeya_controllers';

    // Default controller account
    if ( ! (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ct}" ) ) {
        $wpdb->insert( $ct, array(
            'username'       => 'madeya',
            'pass_hash'      => password_hash( 'CHANGE_ME', PASSWORD_DEFAULT ),
            'full_name'      => 'Station Controller',
            'station_name'   => 'Madeya Gas Station',
            'vehicle_type'   => 'bajaj',
            'allowed_parity' => 'both',
        ) );
    }

    // ── Default ADMIN panel credentials (only set if not already configured) ──
    if ( ! get_option( 'madeya_admin_password' ) ) {
        update_option( 'madeya_admin_username', 'madeya_admin' );
        update_option( 'madeya_admin_password', password_hash( 'CHANGE_ME', PASSWORD_DEFAULT ) );
    }

    // ── Default priority rules (7 government tiers, all enabled) ──
    if ( ! get_option( 'madeya_priority_rules' ) ) {
        $default_rules = array();
        for ( $i = 1; $i <= 7; $i++ ) {
            $default_rules[$i] = array( 'enabled' => true, 'note' => '' );
        }
        update_option( 'madeya_priority_rules', $default_rules );
    }

    // ── Default fuel stock in liters ──
    if ( ! get_option( 'madeya_fuel_stock_liters' ) ) {
        update_option( 'madeya_fuel_stock_liters', 0 );
        update_option( 'madeya_fuel_capacity_liters', 0 );
    }
}

// ── Create all tables ──────────────────────────────────────────
function madeya_setup_db() {
    global $wpdb;
    $cs = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}madeya_queue (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        plate         VARCHAR(50)   NOT NULL,
        vehicle_type  VARCHAR(60)   NOT NULL DEFAULT '',
        plate_parity  VARCHAR(10)   NOT NULL DEFAULT '',
        token         VARCHAR(8)    NOT NULL DEFAULT '',
        status        VARCHAR(20)   NOT NULL DEFAULT 'active',
        position      INT           NOT NULL DEFAULT 1,
        queue_type    VARCHAR(10)   NOT NULL DEFAULT 'normal',
        gallons       DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
        liters        DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
        priority_tier TINYINT       NOT NULL DEFAULT 7,
        registered_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_plate (plate),
        INDEX idx_pos   (position)
    ) {$cs};" );

    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}madeya_report (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        plate         VARCHAR(50)   NOT NULL,
        vehicle_type  VARCHAR(60)   NOT NULL DEFAULT '',
        plate_parity  VARCHAR(10)   NOT NULL DEFAULT '',
        token         VARCHAR(8)    NOT NULL DEFAULT '',
        status        VARCHAR(20)   NOT NULL DEFAULT 'served',
        position      INT           NOT NULL DEFAULT 0,
        queue_type    VARCHAR(10)   NOT NULL DEFAULT 'normal',
        gallons       DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
        liters        DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
        priority_tier TINYINT       NOT NULL DEFAULT 7,
        served_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        report_date   DATE          NOT NULL,
        INDEX idx_plate (plate),
        INDEX idx_date  (report_date)
    ) {$cs};" );

    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}madeya_fuel_log (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        action     VARCHAR(20)   NOT NULL DEFAULT 'set',
        gallons    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        note       VARCHAR(255)  NOT NULL DEFAULT '',
        created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) {$cs};" );

    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}madeya_controllers (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username        VARCHAR(80)   NOT NULL,
        pass_hash       VARCHAR(255)  NOT NULL,
        full_name       VARCHAR(120)  NOT NULL DEFAULT '',
        station_name    VARCHAR(120)  NOT NULL DEFAULT 'Madeya Gas Station',
        vehicle_type    VARCHAR(60)   NOT NULL DEFAULT '',
        allowed_parity  VARCHAR(10)   NOT NULL DEFAULT 'both',
        fuel_allocated  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        fuel_used       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        liters_allocated DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        liters_used      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        station_lat      DECIMAL(10,7) NOT NULL DEFAULT 0.0000000,
        station_lng      DECIMAL(10,7) NOT NULL DEFAULT 0.0000000,
        station_address  VARCHAR(255)  NOT NULL DEFAULT '',
        UNIQUE KEY uq_user (username)
    ) {$cs};" );
}

// ── Live upgrades on plugins_loaded ───────────────────────────
add_action( 'plugins_loaded', 'madeya_db_upgrade', 5 );

function madeya_db_upgrade() {
    global $wpdb;
    $qt = $wpdb->prefix . 'madeya_queue';
    $rt = $wpdb->prefix . 'madeya_report';
    $ct = $wpdb->prefix . 'madeya_controllers';

    $upgrades = array(
        $qt => array( 'queue_type' => "VARCHAR(10) NOT NULL DEFAULT 'normal'",
                      'gallons'    => 'DECIMAL(8,2) NOT NULL DEFAULT 0.00',
                      'liters'     => 'DECIMAL(8,2) NOT NULL DEFAULT 0.00',
                      'priority_tier'  => 'TINYINT NOT NULL DEFAULT 7',
                      'controller_id'  => 'INT UNSIGNED NOT NULL DEFAULT 0' ),
        $rt => array( 'queue_type' => "VARCHAR(10) NOT NULL DEFAULT 'normal'",
                      'gallons'    => 'DECIMAL(8,2) NOT NULL DEFAULT 0.00',
                      'liters'     => 'DECIMAL(8,2) NOT NULL DEFAULT 0.00',
                      'priority_tier'  => 'TINYINT NOT NULL DEFAULT 7',
                      'controller_id'  => 'INT UNSIGNED NOT NULL DEFAULT 0' ),
        $ct => array( 'fuel_allocated'  => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                      'fuel_used'       => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                      'liters_allocated'=> 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                      'liters_used'     => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                      'station_lat'     => "DECIMAL(10,7) NOT NULL DEFAULT 0.0000000",
                      'station_lng'     => "DECIMAL(10,7) NOT NULL DEFAULT 0.0000000",
                      'station_address' => "VARCHAR(255) NOT NULL DEFAULT ''" ),
    );

    foreach ( $upgrades as $table => $columns ) {
        foreach ( $columns as $col => $definition ) {
            if ( empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE '{$col}'" ) ) ) {
                $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$col} {$definition}" );
            }
        }
    }

    // Ensure fuel_log exists on older installs
    $cs = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}madeya_fuel_log (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        action     VARCHAR(20)   NOT NULL DEFAULT 'set',
        gallons    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        note       VARCHAR(255)  NOT NULL DEFAULT '',
        created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) {$cs};" );
}
