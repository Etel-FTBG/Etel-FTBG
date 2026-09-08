<?php
/**
 * Etel-ID Premium — AJAX Handlers
 *
 * @package    Etel-ID Premium
 * @version    2.0.0
 * @author     Etel Group · ኢትኤል ቡድን
 * @link       https://etelgroup.com
 * @since      2.0.0
 *
 * Handles wp_ajax_etelid_apply (public application)
 * and wp_ajax_etelid_verify (public ID lookup).
 * Rate-limited via EtelID_Security::rate_limit().
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_Ajax {
    public static function init() {
        add_action( 'wp_ajax_etelid_apply',        array( __CLASS__, 'apply' ) );
        add_action( 'wp_ajax_nopriv_etelid_apply', array( __CLASS__, 'apply' ) );
        add_action( 'wp_ajax_etelid_verify',        array( __CLASS__, 'verify' ) );
        add_action( 'wp_ajax_nopriv_etelid_verify', array( __CLASS__, 'verify' ) );
        /* Admin-only - no nopriv variant, and correctly so this time:
         * this is only ever called from a page inside /wp-admin/ itself
         * (admin/views/qr-pregenerate.php), gated by add_submenu_page()'s
         * own manage_options requirement - unlike the EtelID-portal-facing
         * actions above, whoever's calling this has necessarily already
         * passed a real WordPress-core login to have loaded that page at
         * all. current_user_can() below is still checked explicitly as
         * defense-in-depth, not relying on the hook registration alone. */
        add_action( 'wp_ajax_etelid_admin_pregenerate_qr', array( __CLASS__, 'admin_pregenerate_qr' ) );
    }

    /* ── One-time QR cache backfill ───────────────────────────
     * Walks existing members in small batches (avoids PHP execution
     * time limits on shared hosting for accounts with many members) and
     * calls EtelID_QR::img() for each - that call already does the
     * fetch-once-and-cache-locally work itself (see class-etelid-qr.php),
     * so this is just "touch every member once" to build the cache for
     * everyone who already existed before that caching was added,
     * rather than waiting for each person's card to naturally be viewed
     * for the first time. Only pre-generates the one size/style
     * combination actually used anywhere in the codebase
     * (card-back.php calls EtelID_QR::img($etel_id, 120) - default
     * style 'dark') - confirmed via grep, not assumed, since generating
     * variants nothing displays would just waste time and disk space. */
    public static function admin_pregenerate_qr() {
        check_ajax_referer( 'etelid_admin_qr_pregen', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Not authorized.' ) );
        }

        $offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
        $limit  = 20; // small batch - each one is a real HTTP fetch to api.qrserver.com on cache-miss, keep batches short so this can't itself time out

        $total   = EtelID_DB::count_all();
        $members = EtelID_DB::get_all( array( 'limit' => $limit, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC' ) );

        $done = 0;
        foreach ( $members as $m ) {
            if ( empty( $m->etel_id ) ) continue;
            EtelID_QR::img( $m->etel_id, 120 ); // card-back.php's variant
            EtelID_QR::img( $m->etel_id, 220, '2A1A06', 'FFFEF8', 160, 6 ); // portal.php's Identity Numbers variant
            EtelID_QR::src( $m->etel_id, 200 ); // card_qr_src API field
            $done++;
        }

        $next_offset = $offset + $limit;
        wp_send_json_success( array(
            'processed_this_batch' => $done,
            'next_offset'          => $next_offset,
            'total'                => (int) $total,
            'finished'             => $next_offset >= (int) $total,
        ) );
    }

    public static function apply() {
        // check_ajax_referer's default behavior on failure is to die
        // with the raw string "-1" (not a JSON object) - the frontend's
        // r.json() doesn't throw on that (a bare number IS valid JSON),
        // it just ends up with no res.data at all, showing a vague
        // fallback message instead of the real reason. Passing false as
        // the third argument makes it return false instead of dying, so
        // we can send a proper, specific JSON error here.
        if ( ! check_ajax_referer( 'etelid_public', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Your session has expired - please refresh the page and try again.' ) );
        }
        $ip = EtelID_Security::get_ip();
        if ( ! EtelID_Security::rate_limit( 'apply_' . $ip, 5, 3600 ) ) {
            wp_send_json_error( array( 'message' => 'Too many requests. Please try again later.' ) );
        }
        $fields = array(
            'full_name','full_name_am','dob','sex','blood_type','phone','email',
            'nationality','region','zone','woreda','primary_company_slug','role',
            'department','employee_number','tier','notes',
        );
        $data = array();
        foreach ( $fields as $f ) {
            $data[ $f ] = isset( $_POST[ $f ] ) ? sanitize_text_field( wp_unslash( $_POST[ $f ] ) ) : '';
        }
        if ( empty( $data['full_name'] ) || empty( $data['phone'] ) || empty( $data['dob'] ) ) {
            wp_send_json_error( array( 'message' => 'Full name, phone, and date of birth are required.' ) );
        }
        if ( ! empty( $data['email'] ) && ! is_email( $data['email'] ) ) {
            wp_send_json_error( array( 'message' => 'Invalid email address.' ) );
        }
        $result = EtelID_Generator::create( $data );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }
        $member = EtelID_DB::get_member( $result );
        if ( $member ) EtelID_Mailer::send_welcome( $member );
        wp_send_json_success( array(
            'etel_id'    => $member->etel_id,
            'fin'        => $member->fin,
            'verify_url' => EtelID_QR::verify_url( $member->etel_id ),
            'message'    => 'Your Etel-ID has been issued successfully.',
        ));
    }

    public static function verify() {
        if ( ! check_ajax_referer( 'etelid_public', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Your session has expired - please refresh the page and try again.' ) );
        }
        $q = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
        if ( ! $q ) wp_send_json_error( array( 'message' => 'Please enter an ID or FIN.' ) );
        $member = EtelID_DB::get_by_etel_id( $q );
        if ( ! $member ) $member = EtelID_DB::get_by_fin( $q );
        if ( ! $member ) wp_send_json_error( array( 'message' => 'ID not found.' ) );
        EtelID_DB::increment_scan( $member->id );
        EtelID_DB::log( $member->id, $member->etel_id, 'verified', 'Public AJAX lookup' );
        wp_send_json_success( array(
            'etel_id' => $member->etel_id,
            'name'    => $member->full_name,
            'status'  => $member->status,
            'company' => $member->primary_company,
            'url'     => EtelID_QR::verify_url( $member->etel_id ),
        ));
    }
}
