<?php
/**
 * EtelID - two-step sign-in with an authenticator app (TOTP, RFC 6238).
 *
 * Works like the two-step sign-in in Delivery & Ride:
 *  - opt-in per member: nobody is forced, a member who has not switched it on
 *    signs in exactly as before;
 *  - once on, the password alone is not enough: the 6-digit code from an
 *    authenticator app (Google Authenticator, Authy, Microsoft Authenticator,
 *    Aegis ...) or one of 8 single-use backup codes is needed too;
 *  - the secret is encrypted in the database, a used code cannot be replayed,
 *    and wrong codes are limited to 5 per 10 minutes per ACCOUNT;
 *  - an admin can reset a member who lost phone AND backup codes.
 *
 * A passkey (Face ID / fingerprint) sign-in is NOT asked for a code: it already
 * needs the device and the person's biometric, which is a stronger pair.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_2FA {

    const TABLE        = 'etelid_2fa';
    const DB_VERSION   = '1.0';
    const MAX_FAILURES = 5;
    const FAIL_WINDOW  = 600;

    /* ---- base32 + TOTP (RFC 4648 / RFC 6238) ------------------------- */
    private static $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function b32_encode( $bin ) {
        $bits = '';
        for ( $i = 0, $n = strlen( $bin ); $i < $n; $i++ ) $bits .= str_pad( decbin( ord( $bin[ $i ] ) ), 8, '0', STR_PAD_LEFT );
        $out = '';
        foreach ( str_split( $bits, 5 ) as $chunk ) {
            if ( strlen( $chunk ) < 5 ) $chunk = str_pad( $chunk, 5, '0', STR_PAD_RIGHT );
            $out .= self::$alphabet[ bindec( $chunk ) ];
        }
        return $out;
    }

    public static function b32_decode( $b32 ) {
        $b32  = strtoupper( preg_replace( '/[^A-Za-z2-7]/', '', (string) $b32 ) );
        $bits = '';
        for ( $i = 0, $n = strlen( $b32 ); $i < $n; $i++ ) $bits .= str_pad( decbin( strpos( self::$alphabet, $b32[ $i ] ) ), 5, '0', STR_PAD_LEFT );
        $out = '';
        foreach ( str_split( $bits, 8 ) as $byte ) { if ( strlen( $byte ) === 8 ) $out .= chr( bindec( $byte ) ); }
        return $out;
    }

    /** 6-digit code for one 30-second step. */
    public static function code_for_step( $secret_bin, $step ) {
        $hash   = hash_hmac( 'sha1', pack( 'N*', 0, $step ), $secret_bin, true );
        $offset = ord( $hash[19] ) & 0x0F;
        $bin    = ( ( ord( $hash[ $offset ] ) & 0x7F ) << 24 ) | ( ( ord( $hash[ $offset + 1 ] ) & 0xFF ) << 16 )
                | ( ( ord( $hash[ $offset + 2 ] ) & 0xFF ) << 8 ) | ( ord( $hash[ $offset + 3 ] ) & 0xFF );
        return str_pad( (string) ( $bin % 1000000 ), 6, '0', STR_PAD_LEFT );
    }

    /** The matching time step (so replays can be refused) or false. One step of clock drift allowed either way. */
    public static function match_step( $secret_b32, $code, $now = null ) {
        $code = preg_replace( '/\D/', '', (string) $code );
        if ( strlen( $code ) !== 6 ) return false;
        $secret = self::b32_decode( $secret_b32 );
        if ( $secret === '' ) return false;
        $step = (int) floor( ( $now === null ? time() : $now ) / 30 );
        for ( $d = -1; $d <= 1; $d++ ) {
            if ( hash_equals( self::code_for_step( $secret, $step + $d ), $code ) ) return $step + $d;
        }
        return false;
    }

    public static function new_secret() { return self::b32_encode( random_bytes( 20 ) ); } // 160 bits

    /* ---- secret encrypted at rest ------------------------------------ */
    private static function key() {
        $salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'etelid-test-salt';
        return hash( 'sha256', 'etelid-2fa|' . $salt, true );
    }
    public static function encrypt( $plain ) {
        $iv = random_bytes( 16 );
        return 'v1:' . base64_encode( $iv . openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv ) );
    }
    public static function decrypt( $stored ) {
        if ( strpos( (string) $stored, 'v1:' ) !== 0 ) return '';
        $raw = base64_decode( substr( $stored, 3 ) );
        if ( $raw === false || strlen( $raw ) < 17 ) return '';
        $plain = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
        return $plain === false ? '' : $plain;
    }

    /* ---- storage ------------------------------------------------------ */
    private static function table() { global $wpdb; return $wpdb->prefix . self::TABLE; }

    public static function ensure_table() {
        if ( get_option( 'etelid_2fa_db_version' ) === self::DB_VERSION ) return;
        if ( ! defined( 'ABSPATH' ) || ! file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( "CREATE TABLE " . self::table() . " (
            member_id BIGINT UNSIGNED NOT NULL,
            secret_enc TEXT NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            backup_codes TEXT NULL,
            last_step BIGINT NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            enabled_at DATETIME NULL,
            PRIMARY KEY  (member_id)
        ) " . $wpdb->get_charset_collate() . ";" );
        update_option( 'etelid_2fa_db_version', self::DB_VERSION );
    }

    public static function row( $member_id ) {
        global $wpdb;
        self::ensure_table();
        $r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE member_id = %d', (int) $member_id ) );
        return $r ? $r : null;
    }

    public static function is_enabled( $member_id ) {
        $r = self::row( $member_id );
        return $r && (int) $r->enabled === 1;
    }

    /* ---- backup codes ------------------------------------------------- */
    public static function new_backup_codes() {
        $alpha = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O/1/I/L
        $out = array();
        for ( $i = 0; $i < 8; $i++ ) {
            $c = '';
            for ( $j = 0; $j < 10; $j++ ) $c .= $alpha[ random_int( 0, strlen( $alpha ) - 1 ) ];
            $out[] = substr( $c, 0, 5 ) . '-' . substr( $c, 5 );
        }
        return $out;
    }
    public static function hash_backup( $code ) {
        return hash( 'sha256', 'etel-bk|' . strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $code ) ) );
    }

    /* ---- wrong-code limit: per ACCOUNT, not per IP ------------------- */
    private static function fail_key( $member_id ) { return 'totp_' . (int) $member_id; }
    public static function is_locked( $member_id ) { return EtelID_Security::fail_blocked( self::fail_key( $member_id ), self::MAX_FAILURES, self::FAIL_WINDOW ); }
    private static function fail_hit( $member_id ) { EtelID_Security::fail_hit( self::fail_key( $member_id ), self::FAIL_WINDOW ); }
    private static function fail_clear( $member_id ) { EtelID_Security::fail_clear( self::fail_key( $member_id ) ); }

    /** Check an authenticator code or a backup code for an ENABLED account. A used code cannot be replayed. */
    public static function check_code( $member_id, $code ) {
        global $wpdb;
        $row = self::row( $member_id );
        if ( ! $row || (int) $row->enabled !== 1 ) return false;
        $code = trim( (string) $code );
        if ( $code === '' ) return false;
        if ( preg_match( '/^\s*\d{3}\s?\d{3}\s*$/', $code ) ) {
            $secret = self::decrypt( $row->secret_enc );
            if ( $secret === '' ) return false;
            $step = self::match_step( $secret, $code );
            if ( $step !== false && $step > (int) $row->last_step ) {
                $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET last_step = %d WHERE member_id = %d AND last_step < %d', $step, (int) $member_id, $step ) );
                return true;
            }
            return false;
        }
        $codes = json_decode( (string) $row->backup_codes, true );
        if ( ! is_array( $codes ) ) return false;
        $h = self::hash_backup( $code );
        foreach ( $codes as $i => $stored ) {
            if ( hash_equals( (string) $stored, $h ) ) {
                unset( $codes[ $i ] );
                $wpdb->update( self::table(), array( 'backup_codes' => wp_json_encode( array_values( $codes ) ) ), array( 'member_id' => (int) $member_id ) );
                return true;
            }
        }
        return false;
    }

    /**
     * The sign-in gate. Call it after the password is verified and BEFORE a
     * session is created. True when the member has no two-step sign-in or the
     * code is right; otherwise a WP_Error whose code tells the screen what to do:
     *   requires_2fa  - ask for the code
     *   bad_2fa       - the code was wrong, ask again
     *   twofa_locked  - too many wrong codes, wait
     */
    public static function gate( $member, $code ) {
        if ( ! $member || ! self::is_enabled( $member->id ) ) return true;
        $code = trim( (string) $code );
        if ( $code === '' ) return new WP_Error( 'requires_2fa', 'Enter the 6-digit code from your authenticator app (or a backup code).', array( 'status' => 401, 'requires_2fa' => true ) );
        if ( self::is_locked( $member->id ) ) {
            return new WP_Error( 'twofa_locked', 'Too many wrong codes. Try again in 10 minutes.', array( 'status' => 429 ) );
        }
        if ( ! self::check_code( $member->id, $code ) ) {
            self::fail_hit( $member->id );
            EtelID_DB::log( $member->id, $member->etel_id, 'login_failed', 'Two-step sign-in: wrong code' );
            return new WP_Error( 'bad_2fa', 'That code is not right. Check the time on your phone and try again.', array( 'status' => 401, 'requires_2fa' => true ) );
        }
        self::fail_clear( $member->id );
        return true;
    }

    /* ---- set-up / switch off (member must be signed in) --------------- */

    /** Start set-up: a fresh secret (not active yet). Returns secret + otpauth link. */
    public static function begin( $member ) {
        global $wpdb;
        self::ensure_table();
        if ( self::is_enabled( $member->id ) ) return new WP_Error( 'already_on', 'Two-step sign-in is already on.' );
        $secret = self::new_secret();
        $wpdb->query( $wpdb->prepare(
            'REPLACE INTO ' . self::table() . ' (member_id, secret_enc, enabled, backup_codes, last_step, created_at) VALUES (%d, %s, 0, NULL, 0, %s)',
            (int) $member->id, self::encrypt( $secret ), gmdate( 'Y-m-d H:i:s' )
        ) );
        $label = rawurlencode( 'Etel-ID:' . $member->etel_id );
        return array(
            'secret' => $secret,
            'uri'    => 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . rawurlencode( 'Etel-ID' ) . '&algorithm=SHA1&digits=6&period=30',
        );
    }

    /** Finish set-up with the first code from the app. Returns the 8 backup codes (shown once) or WP_Error. */
    public static function enable( $member, $code ) {
        global $wpdb;
        $row = self::row( $member->id );
        if ( ! $row || (int) $row->enabled === 1 ) return new WP_Error( 'not_in_setup', 'Start the set-up first.' );
        if ( self::is_locked( $member->id ) ) return new WP_Error( 'twofa_locked', 'Too many wrong codes. Try again in 10 minutes.' );
        $secret = self::decrypt( $row->secret_enc );
        $step   = $secret !== '' ? self::match_step( $secret, $code ) : false;
        if ( $step === false ) {
            self::fail_hit( $member->id );
            return new WP_Error( 'bad_2fa', 'That code is not right. Check the time on your phone and try again.' );
        }
        $codes  = self::new_backup_codes();
        $hashes = array(); foreach ( $codes as $c ) $hashes[] = self::hash_backup( $c );
        $wpdb->update( self::table(),
            array( 'enabled' => 1, 'backup_codes' => wp_json_encode( $hashes ), 'last_step' => (int) $step, 'enabled_at' => gmdate( 'Y-m-d H:i:s' ) ),
            array( 'member_id' => (int) $member->id ) );
        self::fail_clear( $member->id );
        EtelID_DB::log( $member->id, $member->etel_id, 'updated', 'Two-step sign-in turned on' );
        return $codes;
    }

    /** Switch off (needs a current code). */
    public static function disable( $member, $code ) {
        global $wpdb;
        if ( self::is_locked( $member->id ) ) return new WP_Error( 'twofa_locked', 'Too many wrong codes. Try again in 10 minutes.' );
        if ( ! self::check_code( $member->id, $code ) ) { self::fail_hit( $member->id ); return new WP_Error( 'bad_2fa', 'That code is not right.' ); }
        self::fail_clear( $member->id );
        $wpdb->delete( self::table(), array( 'member_id' => (int) $member->id ) );
        EtelID_DB::log( $member->id, $member->etel_id, 'updated', 'Two-step sign-in turned off by the member' );
        return true;
    }

    /** Replace all backup codes with 8 fresh ones (needs a current code). */
    public static function regenerate_backup( $member, $code ) {
        global $wpdb;
        if ( self::is_locked( $member->id ) ) return new WP_Error( 'twofa_locked', 'Too many wrong codes. Try again in 10 minutes.' );
        if ( ! self::check_code( $member->id, $code ) ) { self::fail_hit( $member->id ); return new WP_Error( 'bad_2fa', 'That code is not right.' ); }
        self::fail_clear( $member->id );
        $codes = self::new_backup_codes();
        $hashes = array(); foreach ( $codes as $c ) $hashes[] = self::hash_backup( $c );
        $wpdb->update( self::table(), array( 'backup_codes' => wp_json_encode( $hashes ) ), array( 'member_id' => (int) $member->id ) );
        return $codes;
    }

    public static function backup_codes_left( $member_id ) {
        $r = self::row( $member_id );
        $c = $r ? json_decode( (string) $r->backup_codes, true ) : null;
        return is_array( $c ) ? count( $c ) : 0;
    }

    /** Admin reset for a member who lost the phone AND the backup codes. */
    public static function admin_reset( $member_id ) {
        global $wpdb;
        self::ensure_table();
        $wpdb->delete( self::table(), array( 'member_id' => (int) $member_id ) );
        self::fail_clear( $member_id );
    }
}
