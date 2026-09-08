<?php
/**
 * Etel-ID Premium — Patch Updater v2.0
 *
 * Uses WordPress built-in unzip_file() + WP_Filesystem
 * instead of ZipArchive — works on ALL PHP hosts including
 * cPanel shared hosting without ZipArchive extension.
 *
 * Falls back to PclZip (bundled with WordPress) if needed.
 *
 * PHP 5.6+ compatible.
 *
 * @package Etel-ID Premium
 * @since   3.6.3
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_Updater {

    const PATCH_LOG_OPTION = 'etelid_patch_log';

    public static function init() {
        add_action( 'wp_ajax_etelid_apply_patch',        array( __CLASS__, 'handle_patch_upload' ) );
        add_action( 'wp_ajax_etelid_apply_patch_multi',  array( __CLASS__, 'handle_patch_upload_multi' ) );
        /* Portal admin action — uses EtelID session auth, not WP capability */
        add_action( 'wp_ajax_nopriv_etelid_portal_patch', array( __CLASS__, 'handle_portal_patch' ) );
        add_action( 'wp_ajax_etelid_portal_patch',        array( __CLASS__, 'handle_portal_patch' ) );
        add_action( 'wp_ajax_nopriv_etelid_portal_patch_multi', array( __CLASS__, 'handle_portal_patch_multi' ) );
        add_action( 'wp_ajax_etelid_portal_patch_multi',        array( __CLASS__, 'handle_portal_patch_multi' ) );
    }

    /* ── Shared portal auth check — same priority order used by
     * handle_portal_patch(): WP admin, EtelID session, EtelID token,
     * shared secret. Returns true/false instead of sending JSON, so
     * both the zip and multi-file handlers can reuse it. ────────── */
    private static function portal_auth_ok() {
        if ( empty( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'etelid_patch_upload' ) ) {
            return false;
        }
        if ( current_user_can( 'manage_options' ) ) return true;

        $token = '';
        if ( isset( $_COOKIE['etelid_session'] ) ) $token = $_COOKIE['etelid_session'];
        if ( ! $token && isset( $_COOKIE['etelid_token'] ) ) $token = $_COOKIE['etelid_token'];
        if ( $token ) {
            $token  = sanitize_text_field( wp_unslash( $token ) );
            $member = EtelID_Auth::member_from_token( $token );
            if ( $member && EtelID_Auth::is_site_admin( $member ) ) return true;
        }

        if ( ! empty( $_POST['eid_token'] ) ) {
            $token  = sanitize_text_field( wp_unslash( $_POST['eid_token'] ) );
            $member = EtelID_Auth::member_from_token( $token );
            if ( $member && EtelID_Auth::is_site_admin( $member ) ) return true;
        }

        if ( ! empty( $_POST['updater_secret'] ) && defined( 'ETELID_UPDATER_SECRET' ) ) {
            if ( hash_equals( ETELID_UPDATER_SECRET, sanitize_text_field( wp_unslash( $_POST['updater_secret'] ) ) ) ) return true;
        }

        return false;
    }

    /* ── Multi-file portal handler (no zip needed) ────────────── */
    public static function handle_portal_patch_multi() {
        if ( ! self::portal_auth_ok() ) {
            wp_send_json_error( array(
                'message' => 'Forbidden. You must be logged in as an EtelID admin to use the updater.',
                'hint'    => 'Add ETELID_UPDATER_SECRET to wp-config.php for secret-key auth.',
            ) );
        }
        self::do_multi_upload();
    }

    /* ── WP Admin multi-file handler ─────────────────────────── */
    public static function handle_patch_upload_multi() {
        check_ajax_referer( 'etelid_patch_upload', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden.' ) );
        }
        self::do_multi_upload();
    }

    /* ── Portal patch handler — EtelID session auth ──────────────
     * Auth priority:
     *   1. WP admin session (manage_options)
     *   2. EtelID session cookie (etelid_session)
     *   3. EtelID token passed as POST param
     *   4. Shared updater secret from .env/wp-config
     * ─────────────────────────────────────────────────────────── */
    public static function handle_portal_patch() {
        /* Verify nonce — wp_verify_nonce works even without WP session */
        if ( empty( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'etelid_patch_upload' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid nonce. Please refresh the page and try again.' ) );
        }

        $authorized = false;

        /* Check 1: WP admin */
        if ( current_user_can( 'manage_options' ) ) {
            $authorized = true;
        }

        /* Check 2: EtelID session cookie */
        if ( ! $authorized ) {
            $token = '';
            if ( isset( $_COOKIE['etelid_session'] ) ) $token = $_COOKIE['etelid_session'];
            if ( ! $token && isset( $_COOKIE['etelid_token'] ) ) $token = $_COOKIE['etelid_token'];
            if ( $token ) {
                $token  = sanitize_text_field( wp_unslash( $token ) );
                $member = EtelID_Auth::member_from_token( $token );
                if ( $member && EtelID_Auth::is_site_admin( $member ) ) {
                    $authorized = true;
                }
            }
        }

        /* Check 3: EtelID token in POST body */
        if ( ! $authorized && ! empty( $_POST['eid_token'] ) ) {
            $token  = sanitize_text_field( wp_unslash( $_POST['eid_token'] ) );
            $member = EtelID_Auth::member_from_token( $token );
            if ( $member && EtelID_Auth::is_site_admin( $member ) ) {
                $authorized = true;
            }
        }

        /* Check 4: shared updater secret (define ETELID_UPDATER_SECRET in wp-config.php) */
        if ( ! $authorized && ! empty( $_POST['updater_secret'] ) && defined( 'ETELID_UPDATER_SECRET' ) ) {
            if ( hash_equals( ETELID_UPDATER_SECRET, sanitize_text_field( wp_unslash( $_POST['updater_secret'] ) ) ) ) {
                $authorized = true;
            }
        }

        if ( ! $authorized ) {
            wp_send_json_error( array(
                'message' => 'Forbidden. You must be logged in as an EtelID admin to use the updater.',
                'hint'    => 'Add ETELID_UPDATER_SECRET to wp-config.php for secret-key auth.',
            ) );
        }

        /* All auth passed — run the patch upload */
        self::do_patch_upload();
    }

    /* WP Admin handler — keeps original WP capability check */
    public static function handle_patch_upload() {
        check_ajax_referer( 'etelid_patch_upload', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden.' ) );
        }
        self::do_patch_upload();
    }

    /* ── Core patch upload logic ─────────────────────────────────
     * Called after auth verified. v3.0: no manifest needed.
     * ─────────────────────────────────────────────────────────── */
    private static function do_patch_upload() {
        if ( empty( $_FILES['patch_zip'] ) || $_FILES['patch_zip']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( array( 'message' => 'No file uploaded or upload error.' ) );
        }

        $file    = $_FILES['patch_zip'];
        $tmpfile = $file['tmp_name'];

        $filename = sanitize_file_name( $file['name'] );
        if ( strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) !== 'zip' ) {
            wp_send_json_error( array( 'message' => 'Please upload a .zip file.' ) );
        }

        /* Move to WP temp dir */
        $wp_temp  = get_temp_dir();
        $temp_zip = $wp_temp . 'etelid-patch-' . time() . '.zip';
        if ( ! move_uploaded_file( $tmpfile, $temp_zip ) ) {
            wp_send_json_error( array( 'message' => 'Could not move uploaded file. Check temp directory permissions.' ) );
        }

        /* WP Filesystem */
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            @unlink( $temp_zip );
            wp_send_json_error( array( 'message' => 'WordPress Filesystem not available.' ) );
        }

        /* Extract ZIP */
        $extract_dir = $wp_temp . 'etelid-patch-extract-' . time() . '/';
        $wp_filesystem->mkdir( $extract_dir );
        $result = unzip_file( $temp_zip, $extract_dir );
        @unlink( $temp_zip );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => 'Could not extract ZIP: ' . $result->get_error_message() ) );
        }

        /* ── Read optional manifest ────────────────────────────── */
        $patch_version = 'manual';
        $new_version   = '';
        $description   = '';
        $manifest_files = array(); /* from manifest, if present */

        $manifest_file = $extract_dir . 'patch-manifest.json';
        if ( file_exists( $manifest_file ) ) {
            $manifest = json_decode( file_get_contents( $manifest_file ), true );
            if ( $manifest ) {
                $patch_version  = isset( $manifest['patch_version'] ) ? sanitize_text_field( $manifest['patch_version'] ) : 'manual';
                $new_version    = isset( $manifest['new_version'] )   ? sanitize_text_field( $manifest['new_version'] )   : '';
                $description    = isset( $manifest['description'] )   ? sanitize_text_field( $manifest['description'] )   : '';
                $manifest_files = isset( $manifest['files'] )         ? (array) $manifest['files']                        : array();
            }
        }

        $plugin_dir = ETELID_PATH;
        $applied    = array();
        $failed     = array();
        $skipped    = array();

        /* ── MODE A: manifest with files list ──────────────────── */
        if ( ! empty( $manifest_files ) ) {
            foreach ( $manifest_files as $rel ) {
                $rel = ltrim( sanitize_text_field( $rel ), '/' );
                if ( strpos( $rel, '..' ) !== false ) { $failed[] = $rel . ' (blocked)'; continue; }
                /* Try files/ subfolder first, then root */
                $source = file_exists( $extract_dir . 'files/' . $rel )
                    ? $extract_dir . 'files/' . $rel
                    : $extract_dir . $rel;
                self::deploy_file( $source, $plugin_dir . $rel, $rel, $applied, $failed );
            }

        /* ── MODE B/C: no manifest — scan ZIP contents ─────────── */
        } else {
            /* Collect all PHP/CSS/JS/JSON/HTML/SQL files from the ZIP */
            $all_files = self::scan_dir( $extract_dir );
            foreach ( $all_files as $abs_path ) {
                /* Skip the manifest itself and backup files */
                $basename = basename( $abs_path );
                if ( $basename === 'patch-manifest.json' ) continue;
                if ( strpos( $basename, '.bak.' ) !== false ) continue;

                /* Compute relative path inside ZIP */
                $in_zip = str_replace( $extract_dir, '', $abs_path );
                $in_zip = ltrim( $in_zip, '/' );

                /* Strip leading files/ prefix if present (Mode B) */
                $rel = preg_replace( '#^files/#', '', $in_zip );

                /* Security: block path traversal */
                if ( strpos( $rel, '..' ) !== false || strpos( $rel, '//' ) !== false ) {
                    $skipped[] = $rel . ' (blocked)';
                    continue;
                }

                /* Only deploy files that have a known extension */
                $ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
                $allowed_exts = array( 'php', 'css', 'js', 'json', 'html', 'htm', 'sql', 'txt', 'svg', 'png', 'jpg', 'gif' );
                if ( ! in_array( $ext, $allowed_exts, true ) ) {
                    $skipped[] = $rel . ' (extension not allowed)';
                    continue;
                }

                $dest = $plugin_dir . $rel;
                self::deploy_file( $abs_path, $dest, $rel, $applied, $failed );
            }
        }

        /* Cleanup */
        self::cleanup( $extract_dir );

        if ( empty( $applied ) && empty( $failed ) ) {
            wp_send_json_error( array( 'message' => 'No deployable files found in ZIP. Make sure the ZIP contains PHP/CSS/JS files.' ) );
        }

        /* Update BOTH version locations if new version provided */
        if ( $new_version ) {
            self::update_version( $new_version );
        }

        /* Log */
        $log   = get_option( self::PATCH_LOG_OPTION, array() );
        $log[] = array(
            'patch_version' => $patch_version,
            'new_version'   => $new_version ? $new_version : ETELID_VERSION,
            'description'   => $description,
            'files_applied' => $applied,
            'files_failed'  => $failed,
            'applied_by'    => wp_get_current_user()->display_name,
            'applied_at'    => current_time( 'mysql' ),
        );
        if ( count( $log ) > 20 ) {
            $log = array_slice( $log, -20 );
        }
        update_option( self::PATCH_LOG_OPTION, $log );

        /* Flush rewrite rules — critical after any URL-related file changes */
        flush_rewrite_rules();
        update_option( 'etelid_flush_needed', 0 ); /* clear any pending flag */

        if ( empty( $failed ) ) {
            wp_send_json_success( array(
                'message' => 'Patch ' . $patch_version . ' applied! ' . count($applied) . ' file(s) updated.',
                'applied' => $applied,
                'failed'  => array(),
                'version' => $new_version ? $new_version : ETELID_VERSION,
            ) );
        } else {
            wp_send_json_success( array(
                'message' => 'Patch partially applied. ' . count($applied) . ' succeeded, ' . count($failed) . ' failed.',
                'applied' => $applied,
                'failed'  => $failed,
                'version' => $new_version ? $new_version : ETELID_VERSION,
            ) );
        }
    }

    /* ── Update version in BOTH the header and the constant ── */
    public static function update_version( $new_version ) {
        $main_file = ETELID_PATH . 'etel-id.php';
        if ( ! file_exists( $main_file ) ) return;

        $content = file_get_contents( $main_file );

        /* Fix 1: Plugin header — "Version: X.X.X" */
        $content = preg_replace(
            '/(\*\s*Version:\s*)[\d\.]+/',
            '${1}' . $new_version,
            $content
        );

        /* Fix 2: PHP constant — define( 'ETELID_VERSION', 'X.X.X' ) */
        $content = preg_replace(
            "/define\(\s*'ETELID_VERSION',\s*'[^']+'\s*\)/",
            "define( 'ETELID_VERSION', '" . $new_version . "' )",
            $content
        );

        file_put_contents( $main_file, $content );

        /* Clear WordPress plugin cache so the new version shows in Plugins list */
        if ( function_exists( 'get_plugin_data' ) ) {
            wp_cache_delete( 'etel-id-v3/etel-id.php', 'plugins' );
        }
        delete_transient( 'plugins_info' );
        delete_site_transient( 'update_plugins' );
    }

    /* ── Deploy a single file with auto-backup ─────────────────── */
    /* ── Multi-file upload — no zip, each file deployed to an
     * explicit path sent by the front-end (paths[] parallel array,
     * same order as patch_files[]). Reuses deploy_file() so backups
     * and permission handling stay identical to the ZIP path. ──── */
    private static function do_multi_upload() {
        if ( empty( $_FILES['patch_files'] ) || empty( $_FILES['patch_files']['name'] ) || ! is_array( $_FILES['patch_files']['name'] ) ) {
            wp_send_json_error( array( 'message' => 'No files uploaded.' ) );
        }

        $files = $_FILES['patch_files'];
        $paths = isset( $_POST['paths'] ) ? (array) $_POST['paths'] : array();
        $count = count( $files['name'] );
        if ( ! $count ) wp_send_json_error( array( 'message' => 'No files uploaded.' ) );

        $plugin_dir   = ETELID_PATH;
        $allowed_exts = array( 'php', 'css', 'js', 'json', 'html', 'htm', 'sql', 'txt', 'svg', 'png', 'jpg', 'gif' );
        $applied = array();
        $failed  = array();

        for ( $i = 0; $i < $count; $i++ ) {
            $orig_name = isset( $files['name'][ $i ] ) ? $files['name'][ $i ] : ( 'file-' . $i );

            if ( ! isset( $files['error'][ $i ] ) || $files['error'][ $i ] !== UPLOAD_ERR_OK ) {
                $failed[] = $orig_name . ' (upload error)';
                continue;
            }

            $rel = isset( $paths[ $i ] ) ? sanitize_text_field( wp_unslash( $paths[ $i ] ) ) : '';
            $rel = ltrim( str_replace( '\\', '/', $rel ), '/' );
            if ( ! $rel ) {
                $failed[] = $orig_name . ' (no destination path given)';
                continue;
            }
            if ( strpos( $rel, '..' ) !== false || strpos( $rel, '//' ) !== false ) {
                $failed[] = $rel . ' (blocked)';
                continue;
            }

            $ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
            if ( ! in_array( $ext, $allowed_exts, true ) ) {
                $failed[] = $rel . ' (extension not allowed)';
                continue;
            }

            self::deploy_file( $files['tmp_name'][ $i ], $plugin_dir . $rel, $rel, $applied, $failed );
        }

        if ( empty( $applied ) && empty( $failed ) ) {
            wp_send_json_error( array( 'message' => 'No files were processed.' ) );
        }

        $log   = get_option( self::PATCH_LOG_OPTION, array() );
        $log[] = array(
            'patch_version' => 'manual-multi',
            'new_version'   => ETELID_VERSION,
            'description'   => 'Multi-file deploy (no ZIP)',
            'files_applied' => $applied,
            'files_failed'  => $failed,
            'applied_by'    => wp_get_current_user()->display_name,
            'applied_at'    => current_time( 'mysql' ),
        );
        if ( count( $log ) > 50 ) $log = array_slice( $log, -50 );
        update_option( self::PATCH_LOG_OPTION, $log );

        wp_send_json_success( array(
            'message'       => count( $applied ) . ' file(s) deployed' . ( $failed ? ', ' . count( $failed ) . ' failed' : '' ) . '.',
            'files_applied' => $applied,
            'files_failed'  => $failed,
        ) );
    }

    private static function deploy_file( $source, $dest, $label, &$applied, &$failed ) {
        if ( ! file_exists( $source ) ) {
            $failed[] = $label . ' (not found in ZIP)';
            return;
        }
        $dest_dir = dirname( $dest );
        if ( ! is_dir( $dest_dir ) ) {
            wp_mkdir_p( $dest_dir );
        }
        if ( file_exists( $dest ) ) {
            @copy( $dest, $dest . '.bak.' . date( 'YmdHis' ) );
        }
        if ( @copy( $source, $dest ) ) {
            $applied[] = $label;
        } else {
            $failed[] = $label . ' (copy failed — check permissions)';
        }
    }

    /* ── Recursively list all files under a directory ─────────── */
    private static function scan_dir( $dir ) {
        $out = array();
        if ( ! is_dir( $dir ) ) return $out;
        foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $item ) {
            $path = rtrim( $dir, '/' ) . '/' . $item;
            if ( is_dir( $path ) ) {
                $out = array_merge( $out, self::scan_dir( $path ) );
            } else {
                $out[] = $path;
            }
        }
        return $out;
    }

    /* ── Recursively delete extract directory ── */
    private static function cleanup( $dir ) {
        if ( ! is_dir( $dir ) ) return;
        $files = array_diff( scandir( $dir ), array( '.', '..' ) );
        foreach ( $files as $file ) {
            $path = $dir . $file;
            if ( is_dir( $path ) ) {
                self::cleanup( $path . '/' );
            } else {
                @unlink( $path );
            }
        }
        @rmdir( $dir );
    }

    /* ── Get patch history ── */
    public static function get_log() {
        return array_reverse( (array) get_option( self::PATCH_LOG_OPTION, array() ) );
    }

    /* ── Clear patch log ── */
    public static function clear_log() {
        delete_option( self::PATCH_LOG_OPTION );
    }
}
