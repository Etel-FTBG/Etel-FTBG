<?php
/**
 * Etel-ID - phone number verification.
 *
 * Why: the phone number is how Delivery, Ride, Pay and EtelGroup find a person's account. A sign-up used to accept any
 * number without checking that the person receives messages on it, so someone could sign up with another person's
 * number and then sign in to that person's account in the other Etel systems.
 *
 * How: a new self-service member asks for a 6-digit code; it is sent to the NUMBER ITSELF by SMS or WhatsApp (not to
 * an email address or a Telegram chat, which prove nothing about the phone), and entering it marks the number as
 * verified (members.phone_verified_at). Staff-issued accounts are verified at creation; every member that existed when
 * this was introduced is verified too.
 *
 * Enforcement (the Service API refuses an unverified member's sign-in to the other apps) follows the setting
 *   define('ETELID_PHONE_VERIFY', 'auto' | 'on' | 'off');   // or the option etelid_phone_verify_mode
 *   auto (default): on as soon as a way to send the code to a phone number is configured, off until then
 *   on:             always required        off: never required (codes can still be sent)
 *
 * PHP 5.6+ compatible.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_Phone_Verify {

    const CODE_MINUTES = 10;
    const MAX_TRIES    = 5;

    public static function init() {
        foreach ( array( 'etelid_phone_verify_send', 'etelid_phone_verify_check' ) as $a ) {
            add_action( 'wp_ajax_' . $a,        array( __CLASS__, str_replace( 'etelid_phone_verify_', '', $a ) ) );
            add_action( 'wp_ajax_nopriv_' . $a, array( __CLASS__, str_replace( 'etelid_phone_verify_', '', $a ) ) );
        }
    }

    /* ---- state ---------------------------------------------------------- */

    public static function is_verified( $member ) {
        if ( ! is_object( $member ) ) return false;
        $v = isset( $member->phone_verified_at ) ? (string) $member->phone_verified_at : '';
        return '' !== $v && 0 !== strpos( $v, '0000-00-00' );
    }

    /** Ways a code can reach the phone number itself, as configured on this site: array of 'sms' / 'whatsapp'. */
    public static function channels() {
        $out = array();
        $at = defined( 'ETELID_AT_API_KEY' ) ? ETELID_AT_API_KEY : getenv( 'ETELID_AT_API_KEY' );
        if ( $at ) $out[] = 'sms';
        $wt = defined( 'ETELID_WA_ACCESS_TOKEN' ) ? ETELID_WA_ACCESS_TOKEN : getenv( 'ETELID_WA_ACCESS_TOKEN' );
        $wp = defined( 'ETELID_WA_PHONE_ID' )     ? ETELID_WA_PHONE_ID     : getenv( 'ETELID_WA_PHONE_ID' );
        if ( $wt && $wp ) $out[] = 'whatsapp';
        return $out;
    }

    /** 'auto' | 'on' | 'off' */
    public static function mode() {
        $m = defined( 'ETELID_PHONE_VERIFY' ) ? ETELID_PHONE_VERIFY : get_option( 'etelid_phone_verify_mode', 'auto' );
        $m = strtolower( (string) $m );
        return in_array( $m, array( 'auto', 'on', 'off' ), true ) ? $m : 'auto';
    }

    /** True when an unverified member must verify before signing in to the other Etel apps. */
    public static function enforced() {
        $m = self::mode();
        if ( 'on' === $m )  return true;
        if ( 'off' === $m ) return false;
        return count( self::channels() ) > 0;
    }

    /** What the other apps may rely on: the number is verified, or verification is not required (yet). */
    public static function trusted( $member ) {
        return ! self::enforced() || self::is_verified( $member );
    }

    /** Marks the number verified and writes it to the audit log. $how: 'code' or the staff member's name. */
    public static function mark_verified( $member, $how ) {
        EtelID_DB::update_member( (int) $member->id, array( 'phone_verified_at' => current_time( 'mysql' ) ) );
        EtelID_DB::log( (int) $member->id, $member->etel_id, 'phone_verified', 'Phone number verified (' . $how . ')' );
    }

    public static function mask( $phone ) {
        $d = preg_replace( '/[^0-9]/', '', (string) $phone );
        return strlen( $d ) >= 6 ? substr( $d, 0, 2 ) . str_repeat( '•', max( 3, strlen( $d ) - 4 ) ) . substr( $d, -2 ) : '••••';
    }

    /** What the portal needs to draw the verify card. */
    public static function portal_state( $member ) {
        return array(
            'verified' => self::is_verified( $member ),
            'required' => self::enforced(),
            'channels' => self::channels(),
            'masked'   => self::mask( isset( $member->phone ) ? $member->phone : '' ),
        );
    }

    /* ---- AJAX: send a code ------------------------------------------------ */

    public static function send() {
        if ( ! check_ajax_referer( 'etelid_auth', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Your session has expired. Please refresh the page and try again.' ) );
        }
        $member = EtelID_Auth::get_current_member();
        if ( ! $member ) wp_send_json_error( array( 'message' => 'Please sign in first.' ) );
        if ( self::is_verified( $member ) ) wp_send_json_success( array( 'already' => true, 'message' => 'Your phone number is already verified.' ) );

        $channels = self::channels();
        if ( ! $channels ) {
            wp_send_json_error( array( 'message' => 'Sending codes is not set up yet. Please contact Etel support to verify your number.' ) );
        }
        $choice = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : 'auto';
        if ( 'auto' === $choice ) $choice = $channels[0];
        if ( ! in_array( $choice, $channels, true ) ) {
            wp_send_json_error( array( 'message' => 'That way of sending the code is not available.' ) );
        }

        /* One code a minute, five an hour per member, twenty an hour per network. */
        $cool = 'etelid_pv_cool_' . (int) $member->id;
        if ( get_transient( $cool ) ) wp_send_json_error( array( 'message' => 'Please wait a minute before asking for another code.' ) );
        if ( ! EtelID_Security::rate_limit( 'pv_send_m_' . (int) $member->id, 5, 3600 )
          || ! EtelID_Security::rate_limit( 'pv_send_ip_' . EtelID_Security::get_ip(), 20, 3600 ) ) {
            wp_send_json_error( array( 'message' => 'Too many codes requested. Please try again in an hour.' ) );
        }
        set_transient( $cool, 1, 60 );

        $code = str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
        $sent = ( 'whatsapp' === $choice )
            ? EtelID_Password_Reset::send_via_whatsapp( $member, $code )
            : EtelID_Password_Reset::send_via_sms( $member, $code, 'phone verification code', self::CODE_MINUTES );
        if ( ! $sent ) {
            EtelID_DB::log( (int) $member->id, $member->etel_id, 'phone_verify_send_failed', 'Verification code could not be sent by ' . $choice );
            wp_send_json_error( array( 'message' => 'We could not send the code. Try the other way, or contact Etel support.' ) );
        }
        set_transient( 'etelid_pv_' . (int) $member->id, array(
            'hash'  => password_hash( $code, PASSWORD_DEFAULT ),
            'phone' => substr( preg_replace( '/[^0-9]/', '', (string) $member->phone ), -9 ),
            'tries' => 0,
        ), self::CODE_MINUTES * 60 );
        EtelID_DB::log( (int) $member->id, $member->etel_id, 'phone_verify_sent', 'Verification code sent by ' . $choice );
        wp_send_json_success( array(
            'channel' => $choice,
            'message' => 'We sent a 6-digit code to ' . self::mask( $member->phone ) . ' by ' . ( 'whatsapp' === $choice ? 'WhatsApp' : 'SMS' ) . '. It works for ' . self::CODE_MINUTES . ' minutes.',
        ) );
    }

    /* ---- AJAX: check the code --------------------------------------------- */

    public static function check() {
        if ( ! check_ajax_referer( 'etelid_auth', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Your session has expired. Please refresh the page and try again.' ) );
        }
        $member = EtelID_Auth::get_current_member();
        if ( ! $member ) wp_send_json_error( array( 'message' => 'Please sign in first.' ) );
        if ( self::is_verified( $member ) ) wp_send_json_success( array( 'verified' => true, 'message' => 'Your phone number is already verified.' ) );
        if ( ! EtelID_Security::rate_limit( 'pv_chk_m_' . (int) $member->id, 15, 3600 ) ) {
            wp_send_json_error( array( 'message' => 'Too many tries. Please ask for a new code in an hour.' ) );
        }
        $code = isset( $_POST['code'] ) ? preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_POST['code'] ) ) : '';
        $key  = 'etelid_pv_' . (int) $member->id;
        $st   = get_transient( $key );
        if ( ! is_array( $st ) ) wp_send_json_error( array( 'message' => 'That code has expired. Ask for a new one.' ) );
        if ( (int) $st['tries'] >= self::MAX_TRIES ) {
            delete_transient( $key );
            wp_send_json_error( array( 'message' => 'Too many wrong codes. Ask for a new one.' ) );
        }
        $now9 = substr( preg_replace( '/[^0-9]/', '', (string) $member->phone ), -9 );
        if ( strlen( $code ) !== 6 || $now9 !== $st['phone'] || ! password_verify( $code, $st['hash'] ) ) {
            $st['tries'] = (int) $st['tries'] + 1;
            set_transient( $key, $st, self::CODE_MINUTES * 60 );
            wp_send_json_error( array( 'message' => 'That code is not right. Check it and try again.' ) );
        }
        delete_transient( $key );
        self::mark_verified( $member, 'code' );
        wp_send_json_success( array( 'verified' => true, 'message' => 'Your phone number is verified.' ) );
    }
}

EtelID_Phone_Verify::init();
