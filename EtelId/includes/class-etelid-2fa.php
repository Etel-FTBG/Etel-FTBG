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
    const DB_VERSION   = '1.1';
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
            pay_skip TINYINT(1) NOT NULL DEFAULT 0,
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

    /* ---- "Etel Pay asks for my PIN, not the code" (the member's own choice) ----------------------------------------------
     * Two-step sign-in stays ON for the portal and every other app. Only when Etel Pay signs the member in to pay, and only when the
     * member chose it, the 6-digit code is not asked (the wallet PIN still protects every payment). Off by default. Switching it ON needs a
     * valid code, so someone who only knows the password cannot lower the protection; switching it OFF again needs nothing extra. */
    /** Kill-switch for the whole choice: define( 'ETELID_PAY_CODE_CHOICE', false ); in wp-config.php and every member is asked the code again. */
    public static function pay_choice_allowed() {
        return ! ( defined( 'ETELID_PAY_CODE_CHOICE' ) && ! ETELID_PAY_CODE_CHOICE );
    }

    public static function pay_skips_code( $member_id ) {
        if ( ! self::pay_choice_allowed() ) return false;
        $r = self::row( $member_id );
        return $r && (int) $r->enabled === 1 && isset( $r->pay_skip ) && (int) $r->pay_skip === 1;
    }

    /** $skip true = do not ask the code for Etel Pay (needs a current code); false = ask it again (needs none). True or WP_Error. */
    public static function set_pay_skip( $member, $skip, $code = '' ) {
        global $wpdb;
        $row = self::row( $member->id );
        if ( ! $row || (int) $row->enabled !== 1 ) return new WP_Error( 'no_2fa', 'Two-step sign-in is not on for this account, so there is no code to skip.', array( 'status' => 400 ) );
        if ( $skip && ! self::pay_choice_allowed() ) return new WP_Error( 'pay_choice_off', 'Etel Pay always asks for the 6-digit code at the moment.', array( 'status' => 403 ) );
        if ( $skip ) {
            $code = trim( (string) $code );
            if ( $code === '' ) return new WP_Error( 'requires_2fa', 'Enter the 6-digit code from your authenticator app (or a backup code).', array( 'status' => 401, 'requires_2fa' => true ) );
            if ( self::is_locked( $member->id ) ) return new WP_Error( 'twofa_locked', 'Too many wrong codes. Try again in 10 minutes.', array( 'status' => 429 ) );
            if ( ! self::check_code( $member->id, $code ) ) {
                self::fail_hit( $member->id );
                EtelID_DB::log( $member->id, $member->etel_id, 'login_failed', 'Etel Pay code setting: wrong code' );
                return new WP_Error( 'bad_2fa', 'That code is not right. Check the time on your phone and try again.', array( 'status' => 401, 'requires_2fa' => true ) );
            }
            self::fail_clear( $member->id );
        }
        $wpdb->update( self::table(), array( 'pay_skip' => $skip ? 1 : 0 ), array( 'member_id' => (int) $member->id ) );
        EtelID_DB::log( $member->id, $member->etel_id, 'updated', $skip ? 'Etel Pay: the member chose to pay with the PIN, without the 6-digit code' : 'Etel Pay: the member chose to be asked for the 6-digit code again' );
        return true;
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

    /* ---- Policy: who MUST have two-step sign-in -------------------------
     * Kill-switch: define( 'ETELID_REQUIRE_2FA', false ); in wp-config.php
     * turns every requirement off (admin and staff). Without that line the
     * admin always needs it, and the staff mode is set from the console
     * (Settings > Two-step sign-in): off | log (default) | enforce. */
    const OPT_STAFF_MODE = 'etelid_2fa_staff_mode';

    public static function killed() {
        return defined( 'ETELID_REQUIRE_2FA' ) && ! ETELID_REQUIRE_2FA;
    }

    public static function admin_required() { return ! self::killed(); }

    public static function staff_mode() {
        if ( self::killed() ) return 'off';
        $m = get_option( self::OPT_STAFF_MODE, 'log' );
        return in_array( $m, array( 'off', 'log', 'enforce' ), true ) ? $m : 'log';
    }

    const OPT_STAFF_EXCLUDE = 'etelid_2fa_staff_exclude';

    /** Roles an admin has marked "not staff" (drivers and the like). Editable in the Console. */
    public static function excluded_roles() {
        $raw = get_option( self::OPT_STAFF_EXCLUDE, 'driver,courier,rider,agent' );
        $out = array();
        foreach ( explode( ',', strtolower( (string) $raw ) ) as $r ) {
            $r = trim( preg_replace( '/[^a-z0-9 _-]/', '', $r ) );
            if ( $r !== '' ) $out[] = $r;
        }
        return array_values( array_unique( $out ) );
    }

    /** SQL list of role names that are NOT staff: blank, member, customer, plus the excluded roles. */
    public static function plain_roles_sql() {
        $list = array_merge( array( '', 'member', 'customer' ), self::excluded_roles() );
        $q = array();
        foreach ( array_unique( $list ) as $r ) $q[] = "'" . esc_sql( $r ) . "'";
        return implode( ', ', $q );
    }

    /** True when the member has an active company link with a staff role. */
    public static function is_staff( $member ) {
        global $wpdb;
        if ( ! $member ) return false;
        $n = $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . EtelID_DB::t_companies() . ' WHERE member_id = %d AND active = 1 AND LOWER(role_at) NOT IN (' . self::plain_roles_sql() . ')',
            (int) $member->id
        ) );
        return (int) $n > 0;
    }

    /** Staff member whose staff mode is "enforce" (used by the service API). */
    public static function staff_enforced( $member ) {
        return self::staff_mode() === 'enforce' && self::is_staff( $member );
    }

    /** True when this member must have two-step sign-in switched on. */
    public static function required_for( $member ) {
        if ( ! $member ) return false;
        if ( self::admin_required() && class_exists( 'EtelID_Auth' ) && EtelID_Auth::is_site_admin_raw( $member ) ) return true;
        return self::staff_enforced( $member );
    }

    /** Staff mode "log": note (at most once a day per member) a staff member who signs in without two-step. */
    public static function log_if_unenrolled( $member, $where ) {
        if ( ! $member || self::staff_mode() !== 'log' ) return;
        if ( self::is_enabled( $member->id ) || ! self::is_staff( $member ) ) return;
        $k = 'etelid_2fa_warn_' . (int) $member->id;
        if ( get_transient( $k ) ) return;
        set_transient( $k, 1, DAY_IN_SECONDS );
        EtelID_DB::log( $member->id, $member->etel_id, 'twofa_staff_not_enrolled', 'Staff role without two-step sign-in at ' . substr( (string) $where, 0, 60 ) . ' (not enforced)' );
    }

    /** SQL fragment: a STAFF role needs two-step on (non-staff roles pass). Empty unless staff mode is "enforce". */
    public static function enrolled_sql( $member_id_col, $role_col = 'c.role_at' ) {
        global $wpdb;
        if ( self::staff_mode() !== 'enforce' ) return '';
        return ' AND ( LOWER(' . $role_col . ') IN (' . self::plain_roles_sql() . ') OR EXISTS (SELECT 1 FROM ' . $wpdb->prefix . self::TABLE . ' f2 WHERE f2.member_id = ' . $member_id_col . ' AND f2.enabled = 1) )';
    }

    /** Admin reset for a member who lost the phone AND the backup codes. */
    public static function admin_reset( $member_id ) {
        global $wpdb;
        self::ensure_table();
        $wpdb->delete( self::table(), array( 'member_id' => (int) $member_id ) );
        self::fail_clear( $member_id );
    }
}
