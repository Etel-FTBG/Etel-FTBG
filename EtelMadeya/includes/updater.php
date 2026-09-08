<?php
/**
 * ETEL MADEYA — Self-Hosted Plugin Updater
 *
 * How it works:
 *   1. WordPress periodically checks for plugin updates (every 12 hours).
 *   2. This class hooks into that check and queries a JSON file hosted on your server.
 *   3. If the remote version is newer than the installed version, WordPress shows
 *      the familiar "Update Available" notice in the Plugins list — with changelog.
 *   4. Admin clicks "Update Now" and WordPress downloads + installs the new zip.
 *
 * Setup (one time):
 *   - Host the update JSON file at: MADEYA_UPDATE_URL (defined below)
 *   - Host the new plugin zip at the URL specified in the JSON "download_url" field
 *   - Each time you release a new version, update the JSON file and upload the new zip.
 *
 * JSON file format (save as madeya-update.json on your server):
 * {
 *   "version"      : "3.1",
 *   "download_url" : "https://ethelmarket.com/updates/madeya-gas-queue.zip",
 *   "requires"     : "5.5",
 *   "tested"       : "6.6",
 *   "requires_php" : "7.4",
 *   "last_updated" : "2025-01-15",
 *   "changelog"    : "<h4>Version 3.1</h4><ul><li>Fixed fuel quota display</li><li>Added priority queue ordering</li></ul>"
 * }
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Configuration ─────────────────────────────────────────────
// Change this to wherever you host your update JSON file
define( 'MADEYA_UPDATE_URL',
    'https://ethelmarket.com/updates/madeya-update.json'
);

// ── Updater class ──────────────────────────────────────────────
class Madeya_Plugin_Updater {

    private $plugin_slug;    // madeya-gas-queue/madeya-gas-queue.php
    private $plugin_file;    // absolute path to main plugin file
    private $current_version;
    private $update_url;
    private $cache_key;
    private $cache_ttl = 43200; // 12 hours in seconds

    public function __construct( $plugin_file, $version, $update_url ) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_slug     = plugin_basename( $plugin_file );
        $this->current_version = $version;
        $this->update_url      = $update_url;
        $this->cache_key       = 'madeya_update_cache_' . md5( $update_url );

        // Hook into WordPress update system
        add_filter( 'pre_set_site_transient_update_plugins',
                    array( $this, 'check_for_update' ) );

        add_filter( 'plugins_api',
                    array( $this, 'plugin_info' ), 20, 3 );

        add_filter( 'upgrader_source_selection',
                    array( $this, 'fix_directory_name' ), 10, 4 );

        // Show update info on plugin row
        add_action( 'in_plugin_update_message-' . $this->plugin_slug,
                    array( $this, 'update_message' ), 10, 2 );

        // Add manual check link to plugin action links
        add_filter( 'plugin_action_links_' . $this->plugin_slug,
                    array( $this, 'add_action_links' ) );

        // Handle manual check request
        add_action( 'admin_init', array( $this, 'handle_manual_check' ) );

        // Show admin notices
        add_action( 'admin_notices', array( $this, 'admin_notice' ) );
    }

    // ── Fetch remote update info (cached) ─────────────────────
    private function get_remote_info() {
        $cached = get_transient( $this->cache_key );
        if ( $cached !== false ) return $cached;

        $data = null;

        // ── Try remote server first ────────────────────────────
        $response = wp_remote_get( $this->update_url, array(
            'timeout'    => 8,
            'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
            'sslverify'  => false,   // allow self-signed certs on shared hosting
        ) );

        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
            $body = wp_remote_retrieve_body( $response );
            $parsed = json_decode( $body );
            if ( $parsed && ! empty( $parsed->version ) ) {
                $data = $parsed;
            }
        }

        // ── Fall back to the local JSON file in the plugin folder ──
        // This means "Check for Updates" works even before you upload
        // the JSON to your server. Once the server file exists it takes over.
        if ( ! $data ) {
            $local_path = MADEYA_PLUGIN_DIR . 'madeya-update.json';
            if ( file_exists( $local_path ) ) {
                $local = json_decode( file_get_contents( $local_path ) );
                if ( $local && ! empty( $local->version ) ) {
                    $data = $local;
                    // Mark it as local so we can show a helpful message
                    $data->_source = 'local';
                }
            }
        }

        if ( ! $data ) {
            set_transient( $this->cache_key, null, 3600 );
            return null;
        }

        set_transient( $this->cache_key, $data, $this->cache_ttl );
        return $data;
    }

    // ── Inject update into WordPress transient ─────────────────
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) return $transient;

        $remote = $this->get_remote_info();
        if ( ! $remote ) return $transient;

        if ( version_compare( $this->current_version, $remote->version, '<' ) ) {
            $update_obj                  = new stdClass();
            $update_obj->slug            = dirname( $this->plugin_slug );
            $update_obj->plugin          = $this->plugin_slug;
            $update_obj->new_version     = $remote->version;
            $update_obj->tested          = $remote->tested          ?? '';
            $update_obj->requires        = $remote->requires        ?? '5.5';
            $update_obj->requires_php    = $remote->requires_php    ?? '7.4';
            $update_obj->last_updated    = $remote->last_updated    ?? '';
            $update_obj->package         = $remote->download_url;
            $update_obj->url             = 'https://ethelmarket.com/etel.Madeya';
            $update_obj->icons           = array(
                '1x' => MADEYA_PLUGIN_URL . 'assets/images/etel-madeya-logo-sm.webp',
                '2x' => MADEYA_PLUGIN_URL . 'assets/images/etel-madeya-logo-sm.webp',
            );

            $transient->response[ $this->plugin_slug ] = $update_obj;
        } else {
            // Tell WP this plugin is up to date (prevents false positives)
            $no_update                         = new stdClass();
            $no_update->id                     = $this->plugin_slug;
            $no_update->slug                   = dirname( $this->plugin_slug );
            $no_update->plugin                 = $this->plugin_slug;
            $no_update->new_version            = $remote->version;
            $no_update->url                    = 'https://ethelmarket.com/etel.Madeya';
            $transient->no_update[ $this->plugin_slug ] = $no_update;
        }

        return $transient;
    }

    // ── Provide plugin info for the "View details" popup ───────
    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) return $result;
        if ( ! isset( $args->slug ) || $args->slug !== dirname( $this->plugin_slug ) ) {
            return $result;
        }

        $remote = $this->get_remote_info();
        if ( ! $remote ) return $result;

        $info                = new stdClass();
        $info->name          = 'Madeya Gas Station Queue';
        $info->slug          = dirname( $this->plugin_slug );
        $info->version       = $remote->version;
        $info->author        = '<a href="https://ethelmarket.com">ETEL Dev</a>';
        $info->homepage      = 'https://ethelmarket.com/etel.Madeya';
        $info->requires      = $remote->requires      ?? '5.5';
        $info->tested        = $remote->tested        ?? '';
        $info->requires_php  = $remote->requires_php  ?? '7.4';
        $info->last_updated  = $remote->last_updated  ?? '';
        $info->download_link = $remote->download_url;
        $info->sections      = array(
            'description' => '<p>Digital fuel queue management for gas stations in Ethiopia. '
                           . 'Enforces ሙሉ/ጎዶሎ plate parity rules, priority tier ordering, '
                           . 'per-controller fuel quotas, and real-time driver position tracking.</p>'
                           . '<p><strong>® ኢE — Registered patent &amp; trademark of ETEL Dev</strong></p>',
            'changelog'   => $remote->changelog ?? '<p>See release notes at ethelmarket.com/etel.Madeya</p>',
        );
        $info->banners = array(
            'low'  => MADEYA_PLUGIN_URL . 'assets/images/etel-madeya-logo.webp',
            'high' => MADEYA_PLUGIN_URL . 'assets/images/etel-madeya-logo.webp',
        );
        $info->icons = array(
            '1x' => MADEYA_PLUGIN_URL . 'assets/images/etel-madeya-logo-sm.webp',
        );

        return $info;
    }

    // ── Fix directory name after download (zip may unpack as "madeya-gas-queue-3.1") ──
    public function fix_directory_name( $source, $remote_source, $upgrader, $hook_extra ) {
        if ( ! isset( $hook_extra['plugin'] ) ) return $source;
        if ( $hook_extra['plugin'] !== $this->plugin_slug ) return $source;

        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $this->plugin_slug ) . '/';
        $source_dir = basename( rtrim( $source, '/' ) );
        $correct    = dirname( $this->plugin_slug );

        if ( $source_dir !== $correct ) {
            $new_source = trailingslashit( dirname( rtrim( $source, '/' ) ) ) . $correct . '/';
            if ( $GLOBALS['wp_filesystem']->move( $source, $new_source ) ) {
                return $new_source;
            }
        }

        return $source;
    }

    // ── Inline changelog on plugin row ─────────────────────────
    public function update_message( $plugin_data, $response ) {
        $remote = $this->get_remote_info();
        if ( $remote && ! empty( $remote->changelog ) ) {
            echo '<br><strong>What&#39;s new:</strong> ';
            // Show first 300 chars of changelog as plain text
            echo wp_kses_post( wp_trim_words( strip_tags( $remote->changelog ), 30 ) );
        }
    }

    // ── Add "Check for Updates" link in plugin row ─────────────
    public function add_action_links( $links ) {
        $check_url = wp_nonce_url(
            add_query_arg( array(
                'madeya_check_update' => '1',
                'plugin'              => $this->plugin_slug,
            ), admin_url( 'plugins.php' ) ),
            'madeya_check_update'
        );

        $custom = array(
            '<a href="' . esc_url( $check_url ) . '" style="color:#27AE60;font-weight:700;">'
            . '<span class="dashicons dashicons-update" style="font-size:14px;vertical-align:middle;margin-right:3px;"></span>'
            . 'Check for Updates</a>',
        );

        return array_merge( $custom, $links );
    }

    // ── Handle manual "Check for Updates" click ────────────────
    public function handle_manual_check() {
        if ( ! isset( $_GET['madeya_check_update'] ) ) return;
        if ( ! current_user_can( 'update_plugins' ) ) return;
        if ( ! check_admin_referer( 'madeya_check_update' ) ) return;

        // Clear cache to force fresh check
        delete_transient( $this->cache_key );

        // Force WordPress to re-check all plugins
        delete_site_transient( 'update_plugins' );
        wp_update_plugins();

        $remote = $this->get_remote_info();

        if ( ! $remote ) {
            $msg = 'Could not connect to update server ('
                 . MADEYA_UPDATE_URL . '). '
                 . 'Make sure you have uploaded madeya-update.json to that URL. '
                 . 'See Gas Queue → 🚀 Release Update for instructions.';
        } elseif ( isset( $remote->_source ) && $remote->_source === 'local' ) {
            $msg = sprintf(
                'Running v%s. Update server (%s) not reachable yet — '
                . 'upload madeya-update.json there to enable remote updates. '
                . 'Local JSON file found and is at v%s.',
                esc_html( $this->current_version ),
                MADEYA_UPDATE_URL,
                esc_html( $remote->version )
            );
        } elseif ( version_compare( $this->current_version, $remote->version, '<' ) ) {
            $msg = sprintf(
                'Update available: version %s is ready to install. Click "Update Now" in the plugin list.',
                esc_html( $remote->version )
            );
        } else {
            $msg = sprintf(
                'Madeya Gas Station Queue v%s is up to date. Server is live at %s.',
                esc_html( $this->current_version ),
                MADEYA_UPDATE_URL
            );
        }

        set_transient( 'madeya_update_notice', $msg, 60 );

        wp_safe_redirect( add_query_arg( 'madeya_checked', '1', admin_url( 'plugins.php' ) ) );
        exit;
    }

    // ── Show admin notice after manual check ───────────────────
    public function admin_notice() {
        $msg = get_transient( 'madeya_update_notice' );
        if ( ! $msg ) return;
        delete_transient( 'madeya_update_notice' );

        $is_update = strpos( $msg, 'Update available' ) !== false;
        $class     = $is_update ? 'notice-warning' : 'notice-success';

        printf(
            '<div class="notice %s is-dismissible" style="border-left-color:%s;">'
            . '<p><strong>🔵 ETEL MADEYA — Madeya Gas Station Queue:</strong> %s</p></div>',
            esc_attr( $class ),
            $is_update ? '#F5A623' : '#27AE60',
            esc_html( $msg )
        );
    }
}

// ── Initialize the updater ─────────────────────────────────────
if ( is_admin() ) {
    new Madeya_Plugin_Updater(
        MADEYA_PLUGIN_DIR . 'madeya-gas-queue.php',
        MADEYA_VERSION,
        MADEYA_UPDATE_URL
    );
}
