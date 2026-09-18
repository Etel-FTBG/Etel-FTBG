<?php
/**
 * Etel-ID Premium — Security Utilities
 *
 * @package    Etel-ID Premium
 * @version    2.1.0
 * @author     Etel Group · ኢትኤል ቡድን
 * @link       https://etelgroup.com
 * @since      2.0.0
 *
 * PIN hashing, rate limiting via WP transients, and IP resolution.
 * Compatible with PHP 5.6+ and shared/proxy hosting environments.
 *
 * v2.1.0 — Upgraded hash_pin() from static-salted SHA-256 to bcrypt
 *           (password_hash PASSWORD_BCRYPT, cost 10).
 *           verify_pin() transparently accepts both old SHA-256 hashes
 *           (len=64) and new bcrypt hashes, upgrading on first successful
 *           verify so no forced migration run is needed.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_Security {

    /**
     * Hash a PIN/password with bcrypt (cost 10).
     * Returns a 60-char bcrypt hash string.
     * PHP 5.5+ required — met by our PHP 5.6+ baseline.
     */
    public static function hash_pin( $pin ) {
        return password_hash( (string) $pin, PASSWORD_BCRYPT, array( 'cost' => 10 ) );
    }

    /**
     * Verify a PIN against a stored hash.
     *
     * Handles two hash formats transparently:
     *   - bcrypt  (len != 64)  : uses password_verify()
     *   - legacy SHA-256 (len == 64) : uses hash_equals() with old salt
     *     Returns true so existing users are not locked out.
     *     Caller should re-hash and save immediately after a legacy match
     *     (see upgrade note in class-etelid-auth.php::login()).
     */
    public static function verify_pin( $pin, $hash ) {
        if ( strlen( $hash ) === 64 ) {
            /* Legacy SHA-256 — accept but mark for upgrade */
            return hash_equals( $hash, hash( 'sha256', 'etelid_' . $pin . '_salt_v2' ) );
        }
        return password_verify( (string) $pin, $hash );
    }

    /* HARDENING: registration (both the full form and Quick Register) only
     * ever checked that the phone field was non-empty - no length or
     * format check at all. A value like "09" passed straight through
     * class-etelid-auth-ajax.php's quick_register() (which never even
     * calls EtelID_Auth::register() - it writes directly via
     * EtelID_Generator::create()) and became a real account with an
     * unusable phone number. Accepts the three real shapes seen
     * elsewhere in this codebase (see EtelID_DB::find_by_phone()'s
     * last-9-digits comparison): a bare 9-digit subscriber number
     * (924445206), a local 10-digit number with the trunk 0
     * (0912345678), or a full +251-prefixed 12-digit number
     * (+251912345678 or 251912345678 - the + is stripped along with any
     * other non-digit before checking length). Ethiopian mobile numbers
     * start with 7 or 9 after the trunk/country code is removed. */
    public static function valid_ethiopian_phone( $phone ) {
        $digits = preg_replace( '/[^0-9]/', '', (string) $phone );
        if ( strlen( $digits ) === 12 && substr( $digits, 0, 3 ) === '251' ) {
            $digits = substr( $digits, 3 );
        } elseif ( strlen( $digits ) === 10 && $digits[0] === '0' ) {
            $digits = substr( $digits, 1 );
        }
        return (bool) preg_match( '/^[79][0-9]{8}$/', $digits );
    }

    public static function rate_limit( $key, $max = 10, $window = 3600 ) {
        $transient = 'etelid_rl_' . md5( $key );
        $count     = (int) get_transient( $transient );
        if ( $count >= $max ) return false;
        set_transient( $transient, $count + 1, $window );
        return true;
    }

    /* HARDENING: previously trusted CF-Connecting-IP/X-Forwarded-For
     * unconditionally - both are ordinary client-settable HTTP headers
     * unless something in front of this server (a CDN, a reverse proxy)
     * strips any inbound copy before setting its own. This code has no
     * way to confirm that's actually happening, so a request that
     * reaches the origin directly can set either header to any value it
     * wants and rotate through them, bypassing every per-IP rate limit
     * in the system (login, password reset, Service API auth all key
     * off this). Validating the header as a real IP at least blocks
     * garbage/injection values; it does NOT by itself prove the header
     * wasn't spoofed by the client - full protection also requires the
     * server firewall to only accept inbound traffic from the CDN's own
     * IP ranges (an infra-level setting, not something this code can
     * enforce). REMOTE_ADDR is never spoofable (it's the actual TCP
     * peer), so it stays last and always wins if nothing else validates. */
    public static function get_ip() {
        $keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR' );
        foreach ( $keys as $k ) {
            if ( ! empty( $_SERVER[ $k ] ) ) {
                $candidate = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) ) )[0] );
                if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) return $candidate;
            }
        }
        return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
    }
}
