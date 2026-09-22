<?php
/**
 * Etel-ID Premium - Member Social & Bank Accounts (v1.0)
 *
 * Lets a member attach their own social media handles and bank/mobile
 * money accounts to their EtelID (managed from their portal). Once a
 * scanning person proves their own EtelID login on the tapped card's
 * public NFC page (see Etelid_Nfc::ajax_reveal()), these are returned
 * alongside the rest of the card so the scanner can tap straight
 * through to the member's social profile or copy their account number
 * to pay them - no separate app/contact exchange needed.
 *
 * PHP 5.6+ compatible.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_Links {

    public static function init() {
        add_action( 'wp_ajax_etelid_links_list',        array( __CLASS__, 'ajax_list' ) );
        add_action( 'wp_ajax_nopriv_etelid_links_list',  array( __CLASS__, 'ajax_list' ) );
        add_action( 'wp_ajax_etelid_links_save',         array( __CLASS__, 'ajax_save' ) );
        add_action( 'wp_ajax_nopriv_etelid_links_save',  array( __CLASS__, 'ajax_save' ) );
        add_action( 'wp_ajax_etelid_links_delete',        array( __CLASS__, 'ajax_delete' ) );
        add_action( 'wp_ajax_nopriv_etelid_links_delete', array( __CLASS__, 'ajax_delete' ) );

        /* Admin-only: one uploaded logo per platform, used on every
           member's card - see class doc on platform_meta(). */
        add_action( 'wp_ajax_etelid_links_admin_logos_list',         array( __CLASS__, 'ajax_admin_logos_list' ) );
        add_action( 'wp_ajax_nopriv_etelid_links_admin_logos_list',  array( __CLASS__, 'ajax_admin_logos_list' ) );
        add_action( 'wp_ajax_etelid_links_admin_logo_upload',        array( __CLASS__, 'ajax_admin_logo_upload' ) );
        add_action( 'wp_ajax_nopriv_etelid_links_admin_logo_upload', array( __CLASS__, 'ajax_admin_logo_upload' ) );
        add_action( 'wp_ajax_etelid_links_admin_logo_remove',        array( __CLASS__, 'ajax_admin_logo_remove' ) );
        add_action( 'wp_ajax_nopriv_etelid_links_admin_logo_remove', array( __CLASS__, 'ajax_admin_logo_remove' ) );
    }

    /* Curated catalog - label, brand color, and a short monogram used as
     * a stand-in "logo" badge (no external logo images are fetched, so
     * this always renders correctly regardless of network/WAF issues on
     * this host). 'other' always exists in both groups as a safe
     * fallback for anything not in the list. */
    public static function platforms() {
        return array(
            'social' => array(
                'facebook'  => array( 'label' => 'Facebook',    'color' => '#1877F2', 'mono' => 'FB',  'url' => 'https://facebook.com/{v}' ),
                'instagram' => array( 'label' => 'Instagram',   'color' => '#C13584', 'mono' => 'IG',  'url' => 'https://instagram.com/{v}' ),
                'telegram'  => array( 'label' => 'Telegram',    'color' => '#26A5E4', 'mono' => 'TG',  'url' => 'https://t.me/{v}' ),
                'tiktok'    => array( 'label' => 'TikTok',      'color' => '#111111', 'mono' => 'TT',  'url' => 'https://tiktok.com/@{v}' ),
                'x'         => array( 'label' => 'X (Twitter)', 'color' => '#000000', 'mono' => 'X',   'url' => 'https://x.com/{v}' ),
                'linkedin'  => array( 'label' => 'LinkedIn',    'color' => '#0A66C2', 'mono' => 'IN',  'url' => 'https://linkedin.com/in/{v}' ),
                'youtube'   => array( 'label' => 'YouTube',     'color' => '#FF0000', 'mono' => 'YT',  'url' => 'https://youtube.com/@{v}' ),
                'whatsapp'  => array( 'label' => 'WhatsApp',    'color' => '#25D366', 'mono' => 'WA',  'url' => 'https://wa.me/{v}' ),
                'snapchat'  => array( 'label' => 'Snapchat',    'color' => '#FFFC00', 'mono' => 'SC',  'url' => 'https://snapchat.com/add/{v}' ),
                'website'   => array( 'label' => 'Website',     'color' => '#8B6914', 'mono' => 'WEB', 'url' => '{v}' ),
                'other'     => array( 'label' => 'Other',       'color' => '#A07820', 'mono' => '?',   'url' => '{v}' ),
            ),
            'bank' => array(
                'cbe'      => array( 'label' => 'Commercial Bank of Ethiopia', 'color' => '#5B2E91', 'mono' => 'CBE' ),
                'boa'      => array( 'label' => 'Bank of Abyssinia',          'color' => '#F58220', 'mono' => 'BOA' ),
                'dashen'   => array( 'label' => 'Dashen Bank',                'color' => '#005BAA', 'mono' => 'DB' ),
                'awash'    => array( 'label' => 'Awash Bank',                 'color' => '#8B1E3F', 'mono' => 'AWB' ),
                'wegagen'  => array( 'label' => 'Wegagen Bank',               'color' => '#006838', 'mono' => 'WB' ),
                'nib'      => array( 'label' => 'Nib International Bank',    'color' => '#004B87', 'mono' => 'NIB' ),
                'zemen'    => array( 'label' => 'Zemen Bank',                 'color' => '#1B1B1B', 'mono' => 'ZB' ),
                'hibret'   => array( 'label' => 'Hibret Bank',                'color' => '#0072BC', 'mono' => 'HB' ),
                'telebirr' => array( 'label' => 'telebirr',                  'color' => '#8DC63F', 'mono' => 'TB' ),
                'cbebirr'  => array( 'label' => 'CBE Birr',                   'color' => '#5B2E91', 'mono' => 'CBR' ),
                'other'    => array( 'label' => 'Other Bank',                 'color' => '#A07820', 'mono' => '?' ),
            ),
        );
    }

    /* An admin can upload one real logo image per platform (Admin Tools
       -> Platform Logos, portal-only, gated the same way as the
       Media/Branding panels). That single upload is what every
       member's card shows for that platform from then on - members
       never upload their own, they just pick from this catalog. Falls
       back to the color+monogram badge (meta['logo'] === '') until an
       admin sets one. */
    public static function platform_meta( $link_type, $platform ) {
        $catalog = self::platforms();
        $type    = isset( $catalog[ $link_type ] ) ? $link_type : 'social';
        $meta    = isset( $catalog[ $type ][ $platform ] ) ? $catalog[ $type ][ $platform ] : $catalog[ $type ]['other'];
        $logos   = self::stored_logos();
        $key     = $type . ':' . $platform;
        $meta['logo'] = isset( $logos[ $key ] ) ? $logos[ $key ] : '';
        return $meta;
    }

    protected static function stored_logos() {
        $logos = get_option( 'etelid_link_platform_logos', array() );
        return is_array( $logos ) ? $logos : array();
    }

    /* Full catalog with each platform's stored logo (if any) merged in -
       used by the portal's account picker and the admin Platform Logos
       manager, so both always reflect the same live state. */
    public static function platforms_admin_view() {
        $catalog = self::platforms();
        $out = array();
        foreach ( $catalog as $type => $group ) {
            $out[ $type ] = array();
            foreach ( $group as $key => $meta ) {
                $out[ $type ][ $key ] = self::platform_meta( $type, $key );
            }
        }
        return $out;
    }

    protected static function require_admin() {
        $member = EtelID_Auth::get_current_member();
        if ( ! $member || ! EtelID_Auth::is_site_admin( $member ) ) {
            wp_send_json_error( array( 'message' => 'Not authorized.' ) );
        }
        return $member;
    }

    public static function ajax_admin_logos_list() {
        check_ajax_referer( 'etelid_auth', 'nonce' );
        self::require_admin();
        wp_send_json_success( array( 'platforms' => self::platforms_admin_view() ) );
    }

    public static function ajax_admin_logo_upload() {
        check_ajax_referer( 'etelid_auth', 'nonce' );
        self::require_admin();

        $link_type = isset( $_POST['link_type'] ) ? sanitize_key( wp_unslash( $_POST['link_type'] ) ) : '';
        $platform  = isset( $_POST['platform'] ) ? sanitize_key( wp_unslash( $_POST['platform'] ) ) : '';
        $catalog   = self::platforms();
        if ( ! isset( $catalog[ $link_type ][ $platform ] ) ) {
            wp_send_json_error( array( 'message' => 'Unknown platform.' ) );
        }
        if ( empty( $_FILES['file'] ) ) {
            wp_send_json_error( array( 'message' => 'No file received.' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_handle_upload( 'file', 0 );
        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
        }

        $url   = wp_get_attachment_url( $attachment_id );
        $logos = self::stored_logos();
        $logos[ $link_type . ':' . $platform ] = $url;
        update_option( 'etelid_link_platform_logos', $logos );

        wp_send_json_success( array( 'url' => $url, 'platforms' => self::platforms_admin_view() ) );
    }

    public static function ajax_admin_logo_remove() {
        check_ajax_referer( 'etelid_auth', 'nonce' );
        self::require_admin();

        $link_type = isset( $_POST['link_type'] ) ? sanitize_key( wp_unslash( $_POST['link_type'] ) ) : '';
        $platform  = isset( $_POST['platform'] ) ? sanitize_key( wp_unslash( $_POST['platform'] ) ) : '';
        $logos     = self::stored_logos();
        unset( $logos[ $link_type . ':' . $platform ] );
        update_option( 'etelid_link_platform_logos', $logos );

        wp_send_json_success( array( 'platforms' => self::platforms_admin_view() ) );
    }

    /* -- Portal self-service (the account owner manages their own list) -- */

    public static function ajax_list() {
        check_ajax_referer( 'etelid_auth', 'nonce' );
        $member = EtelID_Auth::get_current_member();
        if ( ! $member ) wp_send_json_error( array( 'message' => 'Please sign in again.' ) );
        wp_send_json_success( array(
            'links'     => self::rows_for_portal( $member->id ),
            'platforms' => self::platforms_admin_view(),
            'is_admin'  => EtelID_Auth::is_site_admin( $member ),
        ) );
    }

    public static function ajax_save() {
        check_ajax_referer( 'etelid_auth', 'nonce' );
        $member = EtelID_Auth::get_current_member();
        if ( ! $member ) wp_send_json_error( array( 'message' => 'Please sign in again.' ) );

        $id            = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $link_type     = isset( $_POST['link_type'] ) ? sanitize_key( wp_unslash( $_POST['link_type'] ) ) : '';
        $platform      = isset( $_POST['platform'] ) ? sanitize_key( wp_unslash( $_POST['platform'] ) ) : '';
        $label         = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
        $account_value = isset( $_POST['account_value'] ) ? sanitize_text_field( wp_unslash( $_POST['account_value'] ) ) : '';
        $account_name  = isset( $_POST['account_name'] ) ? sanitize_text_field( wp_unslash( $_POST['account_name'] ) ) : '';

        if ( ! in_array( $link_type, array( 'social', 'bank' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Choose an account type.' ) );
        }
        if ( '' === trim( $account_value ) ) {
            wp_send_json_error( array( 'message' => 'social' === $link_type ? 'Enter your handle or profile link.' : 'Enter the account number.' ) );
        }

        $catalog = self::platforms();
        if ( ! isset( $catalog[ $link_type ][ $platform ] ) ) $platform = 'other';

        global $wpdb;
        $table = EtelID_DB::t_links();
        $data  = array(
            'member_id'     => $member->id,
            'link_type'     => $link_type,
            'platform'      => $platform,
            'label'         => $label,
            'account_value' => $account_value,
            'account_name'  => $account_name,
        );

        if ( $id > 0 ) {
            $owned = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND member_id = %d", $id, $member->id ) );
            if ( ! $owned ) wp_send_json_error( array( 'message' => 'Account not found.' ) );
            $wpdb->update( $table, $data, array( 'id' => $id ) );
        } else {
            $wpdb->insert( $table, $data );
        }

        wp_send_json_success( array( 'links' => self::rows_for_portal( $member->id ) ) );
    }

    public static function ajax_delete() {
        check_ajax_referer( 'etelid_auth', 'nonce' );
        $member = EtelID_Auth::get_current_member();
        if ( ! $member ) wp_send_json_error( array( 'message' => 'Please sign in again.' ) );

        $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        global $wpdb;
        $wpdb->delete( EtelID_DB::t_links(), array( 'id' => $id, 'member_id' => $member->id ) );

        wp_send_json_success( array( 'links' => self::rows_for_portal( $member->id ) ) );
    }

    protected static function rows_for_portal( $member_id ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . EtelID_DB::t_links() . " WHERE member_id = %d ORDER BY link_type, sort_order, id",
            $member_id
        ) );
        $out = array();
        foreach ( $rows as $r ) {
            $meta  = self::platform_meta( $r->link_type, $r->platform );
            $out[] = array(
                'id'            => (int) $r->id,
                'link_type'     => $r->link_type,
                'platform'      => $r->platform,
                'label'         => $r->label ? $r->label : $meta['label'],
                'account_value' => $r->account_value,
                'account_name'  => $r->account_name,
                'meta'          => $meta,
            );
        }
        return $out;
    }

    /* -- Public NFC card payload (scanner has already proven their own
     *    EtelID login - see Etelid_Nfc::ajax_reveal()) -- */
    public static function public_payload_for_member( $member_id ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . EtelID_DB::t_links() . " WHERE member_id = %d ORDER BY link_type, sort_order, id",
            $member_id
        ) );
        $out = array();
        foreach ( $rows as $r ) {
            $meta     = self::platform_meta( $r->link_type, $r->platform );
            $open_url = '';
            if ( 'social' === $r->link_type ) {
                $raw = trim( $r->account_value );
                if ( in_array( $r->platform, array( 'website', 'other' ), true ) ) {
                    $open_url = $raw && ! preg_match( '#^https?://#i', $raw ) ? 'https://' . $raw : $raw;
                } elseif ( 'whatsapp' === $r->platform ) {
                    $open_url = 'https://wa.me/' . preg_replace( '/[^0-9]/', '', $raw );
                } elseif ( preg_match( '#^https?://#i', $raw ) ) {
                    $open_url = $raw; // member already pasted a full profile URL
                } else {
                    $open_url = str_replace( '{v}', rawurlencode( ltrim( $raw, '@' ) ), $meta['url'] );
                }
            }
            $out[] = array(
                'link_type'     => $r->link_type,
                'platform'      => $r->platform,
                'label'         => $r->label ? $r->label : $meta['label'],
                'account_value' => $r->account_value,
                'account_name'  => $r->account_name,
                'color'         => $meta['color'],
                'mono'          => $meta['mono'],
                'logo'          => $meta['logo'],
                'open_url'      => $open_url,
            );
        }
        return $out;
    }
}

EtelID_Links::init();
