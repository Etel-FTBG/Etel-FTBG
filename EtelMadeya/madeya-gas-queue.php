<?php
/**
 * Plugin Name: Madeya Gas Station Queue
 * Description: Gas station fuel queue — admin configures vehicle types and odd/even rules
 * Version: 3.1
 * Author: ETEL Dev
 * Plugin URI: https://ethelmarket.com/etel.Madeya
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MADEYA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MADEYA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MADEYA_VERSION', '3.1' );
// Google Maps API key — stored in DB so it is never exposed in source code.
// Set via: WP Admin → Settings → Madeya Gas Queue (or admin panel Settings tab).
add_action( 'init', function () {
    $key = get_option( 'madeya_google_maps_key', '' );
    // Fallback: if not yet set, seed from the legacy hardcoded value so existing installs keep working.
    if ( ! $key ) {
        $key = get_option( 'madeya_google_maps_key_legacy', '' );
    }
    if ( ! defined( 'MADEYA_GOOGLE_MAPS_KEY' ) ) {
        define( 'MADEYA_GOOGLE_MAPS_KEY', $key );
    }
}, 1 );

// ── Core includes ──────────────────────────────────────────────
require_once MADEYA_PLUGIN_DIR . 'includes/database.php';
require_once MADEYA_PLUGIN_DIR . 'includes/helpers.php';
require_once MADEYA_PLUGIN_DIR . 'includes/fuel.php';
require_once MADEYA_PLUGIN_DIR . 'includes/enqueue.php';

// ── AJAX handlers ──────────────────────────────────────────────
require_once MADEYA_PLUGIN_DIR . 'ajax/auth.php';
require_once MADEYA_PLUGIN_DIR . 'ajax/queue.php';
require_once MADEYA_PLUGIN_DIR . 'ajax/fuel-ajax.php';
require_once MADEYA_PLUGIN_DIR . 'ajax/admin-ajax.php';
require_once MADEYA_PLUGIN_DIR . 'ajax/reset-ajax.php';
require_once MADEYA_PLUGIN_DIR . 'ajax/features-ajax.php';

// ── Shortcodes ─────────────────────────────────────────────────
require_once MADEYA_PLUGIN_DIR . 'views/shortcode-controller.php';
require_once MADEYA_PLUGIN_DIR . 'views/shortcode-driver.php';
require_once MADEYA_PLUGIN_DIR . 'views/shortcode-admin.php';

// ── WordPress admin menu ───────────────────────────────────────
require_once MADEYA_PLUGIN_DIR . 'includes/admin-menu.php';

// ── Self-hosted auto-updater ───────────────────────────────────
require_once MADEYA_PLUGIN_DIR . 'includes/updater.php';

// ── PWA support ────────────────────────────────────────────────
require_once MADEYA_PLUGIN_DIR . 'pwa/pwa-init.php';

// ── Activation hook — must use __FILE__ from the root plugin file ──
register_activation_hook( __FILE__, 'madeya_activate' );
