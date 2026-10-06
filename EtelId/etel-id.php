<?php
/**
 * Plugin Name:  Etel-ID Premium
 * Plugin URI:   https://etelgroup.com/etel-id
 * Description:  Premium corporate identity system for Etel Group. Issues biometric-grade ID cards linked across all 8 companies with unique QR codes, FIN numbers, and a full member portal.
 * Version:      3.10.0
 * Author:       Etel Group · ኢትኤል ቡድን
 * Author URI:   https://etelgroup.com
 * Text Domain:  etel-id
 * Requires PHP: 5.6
 * Requires at least: 5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Constants ─────────────────────────────────────────────── */
define( 'ETELID_VERSION', '3.10.0' );
define( 'ETELID_PATH',      plugin_dir_path( __FILE__ ) );
define( 'ETELID_URL',       plugin_dir_url( __FILE__ ) );
define( 'ETELID_SLUG',      'etel-id' );

/* ── Autoload includes ──────────────────────────────────────── */
$includes = array(
    'includes/etel-logo-b64.php',
    'includes/class-etelid-db.php',
    'includes/class-etelid-biometric.php',      /* NEW v3.0.0 */
    'includes/class-etelid-webauthn.php',       /* NEW v3.12.0 — real device Face ID/Touch ID/fingerprint via WebAuthn */
    'includes/class-etelid-generator.php',
    'includes/class-etelid-fayda.php',          /* NEW v3.10.0 — Fayda photo quick-register (Pass 1) */
    'includes/class-etelid-notify.php',         /* NEW v3.10.0 — Telegram/WhatsApp/email credential delivery */
    'includes/class-etelid-telegram.php',       /* NEW — Telegram account linking (deep link + webhook) */
    'includes/class-etelid-links.php',          /* NEW - member's own social/bank accounts, shown on NFC card */
    'includes/class-etelid-qr.php',
    'includes/class-etelid-sun.php',        /* NTAG 424 DNA signed-tap verifier */
    'includes/class-etelid-nfc.php',        /* NEW - NFC card: /m/{token} service-selector, courier pickup/delivery confirmation */
    'includes/class-etelid-barcode.php',    /* v3.9.2 — pure PHP Code128, no TEC-IT */
    'includes/class-etelid-security.php',
    'includes/class-etelid-mailer.php',
    'includes/class-etelid-smtp.php',           /* NEW — real Gmail SMTP delivery, see wp-config.php setup note in the file itself */
    'includes/class-etelid-auth.php',
    'includes/class-etelid-auth-ajax.php',
    'includes/class-etelid-admin-panels.php',  /* NEW v3.11.0 — in-portal Members/Pages/Media/Menus/Branding controller */
    'includes/class-etelid-service-api.php',   /* NEW v3.9.1 - SSO service endpoints */
    'includes/class-etelid-service-kyc.php',   /* v3.10.0 — fixed & wired up: EtelPay/Delivery/Ride eligibility */
    'includes/class-etelid-login-interceptor.php',
    'includes/class-etelid-updater.php',
    'includes/etel-coin-b64.php',
    'includes/class-etelid-nav.php',
    'includes/class-etelid-auto-inject.php',
    'admin/class-etelid-admin.php',
    'includes/class-etelid-shortcodes.php',
    'includes/class-etelid-ajax.php',
    'includes/class-etelid-password-reset.php',   /* self-service Forgot Password (email + Telegram) */
    'includes/class-etelid-rewrite.php',
);
foreach ( $includes as $file ) {
    require_once ETELID_PATH . $file;
}

/* ── Lifecycle hooks ────────────────────────────────────────── */
register_activation_hook(   __FILE__, array( 'EtelID_DB',   'install' ) );
register_activation_hook(   __FILE__, array( 'EtelID_Auth', 'install' ) );
register_deactivation_hook( __FILE__, array( 'EtelID_DB',   'deactivate' ) );
register_uninstall_hook(    __FILE__, array( 'EtelID_DB',   'uninstall' ) );

/* ── Boot on plugins_loaded ─────────────────────────────────── */
add_action( 'plugins_loaded', 'etelid_boot', 5 );

function etelid_boot() {
    /* Intercept ALL WordPress login URLs first — priority 1 */
    EtelID_Login_Interceptor::init();

    /* Run DB upgrades (idempotent — checks column type before altering) */
    EtelID_DB::upgrade_schema();

    EtelID_Admin::init();
    EtelID_Shortcodes::init();
    EtelID_Ajax::init();
    EtelID_Auth_Ajax::init();
    EtelID_Admin_Panels::init();
    Etelid_Nfc::init();
    EtelID_Rewrite::init();
    EtelID_Updater::init();
    EtelID_Nav::init();
    EtelID_Auto_Inject::init();

    add_action( 'wp_enqueue_scripts',    'etelid_frontend_assets' );
    add_action( 'admin_enqueue_scripts', 'etelid_admin_assets' );
}

function etelid_frontend_assets() {
    wp_enqueue_style(
        'etelid-card',
        ETELID_URL . 'public/css/etelid-card.css',
        array(),
        ETELID_VERSION
    );
    wp_enqueue_style(
        'etelid-public',
        ETELID_URL . 'public/css/etelid-public.css',
        array( 'etelid-card' ),
        ETELID_VERSION
    );
    wp_enqueue_style(
        'etelid-front',
        ETELID_URL . 'public/css/etelid-front.css',
        array( 'etelid-card', 'etelid-public' ),
        ETELID_VERSION
    );
    /* Print stylesheet — media=print, loads on all pages but only activates on print */
    wp_enqueue_style(
        'etelid-print',
        ETELID_URL . 'public/css/etelid-print.css',
        array( 'etelid-card' ),
        ETELID_VERSION,
        'print'
    );
    wp_enqueue_script(
        'etelid-front',
        ETELID_URL . 'public/js/etelid-front.js',
        array( 'jquery' ),
        ETELID_VERSION,
        true
    );
    wp_localize_script( 'etelid-front', 'EtelIDConfig', array(
        'ajax'      => admin_url( 'admin-ajax.php' ),
        'nonce'     => wp_create_nonce( 'etelid_public' ),
        'verify_base' => home_url( '/verify/' ),
        'version'   => ETELID_VERSION,
    ));
}

function etelid_admin_assets( $hook ) {
    if ( strpos( $hook, 'etel-id' ) === false ) return;
    wp_enqueue_style(
        'etelid-admin',
        ETELID_URL . 'admin/css/etelid-admin.css',
        array(),
        ETELID_VERSION
    );
    wp_enqueue_script(
        'etelid-admin',
        ETELID_URL . 'admin/js/etelid-admin.js',
        array( 'jquery' ),
        ETELID_VERSION,
        true
    );
    wp_localize_script( 'etelid-admin', 'EtelIDAdmin', array(
        'ajax'    => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'etelid_admin' ),
        'confirm_delete' => __( 'Permanently delete this Etel-ID? This cannot be undone.', 'etel-id' ),
    ));
}
