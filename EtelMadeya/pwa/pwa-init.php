<?php
/**
 * Etel Madeya PWA — injects manifest, theme-color, and registers service worker.
 * Include this file from madeya-gas-queue.php:
 *   require_once plugin_dir_path(__FILE__) . 'pwa/pwa-init.php';
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Inject <head> tags ─────────────────────────────────────────
add_action( 'wp_head', 'madeya_pwa_head_tags' );

// ── Serve sw.js — fires at init priority 1 (before WP 404 logic) ──
add_action( 'init', 'madeya_pwa_serve_sw', 1 );
function madeya_pwa_serve_sw() {
    $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
    $path        = parse_url( $request_uri, PHP_URL_PATH );

    // Match /sw.js at site root or any subdirectory depth (e.g. /etel.madeya/sw.js)
    if ( substr( $path, -6 ) !== '/sw.js' && $path !== '/sw.js' ) {
        return;
    }

    $sw_file = plugin_dir_path( __FILE__ ) . 'sw.js';
    if ( ! file_exists( $sw_file ) ) {
        status_header( 404 );
        exit( '/* sw.js not found */' );
    }

    header( 'Content-Type: application/javascript; charset=utf-8' );
    header( 'Service-Worker-Allowed: /' );
    header( 'Cache-Control: no-store, must-revalidate' );
    header( 'X-Content-Type-Options: nosniff' );
    readfile( $sw_file );
    exit;
}

function madeya_pwa_head_tags() {
    $plugin_url = plugin_dir_url( dirname( __FILE__ ) );
    ?>
<!-- Etel Madeya PWA -->
<link rel="manifest" href="<?php echo esc_url( $plugin_url . 'pwa/manifest.json' ); ?>">
<meta name="theme-color" content="#F5A623">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Etel Madeya">
<link rel="apple-touch-icon" href="<?php echo esc_url( $plugin_url . 'assets/images/etel-madeya-logo.webp' ); ?>">
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    // Determine SW path relative to the site's subdirectory
    var parts = window.location.pathname.split('/').filter(Boolean);
    var swPath = (parts.length > 0 ? '/' + parts[0] : '') + '/sw.js';
    var swScope = (parts.length > 0 ? '/' + parts[0] : '') + '/';
    navigator.serviceWorker.register(swPath, {scope: swScope})
     .catch(function(err){ console.warn('SW registration failed:', err); });
  });
}
</script>
    <?php
}
