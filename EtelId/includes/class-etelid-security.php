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

    /* Cloudflare's published proxy ranges (https://www.cloudflare.com/ips/).
     * CF-Connecting-IP is only believed when the TCP peer (REMOTE_ADDR) is
     * one of these, so a client hitting the origin directly cannot forge it. */
    private static $cloudflare_ranges = array(
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    );

    /* True when $ip falls inside the CIDR block (IPv4 or IPv6). */
    public static function ip_in_cidr( $ip, $cidr ) {
        $parts = explode( '/', $cidr );
        if ( count( $parts ) !== 2 ) return false;
        $a = @inet_pton( $ip );
        $b = @inet_pton( $parts[0] );
        if ( false === $a || false === $b || strlen( $a ) !== strlen( $b ) ) return false;
        $bits = (int) $parts[1];
        $full = (int) floor( $bits / 8 );
        if ( $full > 0 && substr( $a, 0, $full ) !== substr( $b, 0, $full ) ) return false;
        $rem = $bits % 8;
        if ( $rem === 0 ) return true;
        $mask = ( 0xFF << ( 8 - $rem ) ) & 0xFF;
        return ( ord( $a[ $full ] ) & $mask ) === ( ord( $b[ $full ] ) & $mask );
    }

    /* Is $ip a proxy we trust to tell us the real client address?
     * Cloudflare always; anything else only if listed in the
     * ETELID_TRUSTED_PROXIES constant (comma-separated IPs or CIDRs). */
    public static function is_trusted_proxy( $ip, $cloudflare_only = false ) {
        foreach ( self::$cloudflare_ranges as $range ) {
            if ( self::ip_in_cidr( $ip, $range ) ) return true;
        }
        if ( $cloudflare_only || ! defined( 'ETELID_TRUSTED_PROXIES' ) ) return false;
        foreach ( explode( ',', (string) ETELID_TRUSTED_PROXIES ) as $entry ) {
            $entry = trim( $entry );
            if ( $entry === '' ) continue;
            if ( strpos( $entry, '/' ) !== false ? self::ip_in_cidr( $ip, $entry ) : $entry === $ip ) return true;
        }
        return false;
    }

    /* HARDENING (v2): CF-Connecting-IP / X-Forwarded-For are ordinary
     * client-settable headers, so every per-IP rate limit (login, password
     * reset, NFC tap, Service API) could be bypassed by rotating them.
     * They are now honoured ONLY when the TCP peer (REMOTE_ADDR, which
     * cannot be spoofed) is a trusted proxy: Cloudflare's own ranges for
     * CF-Connecting-IP, or an address listed in ETELID_TRUSTED_PROXIES for
     * X-Forwarded-For. Otherwise the real peer address is used. If the site
     * is not behind a proxy this simply returns REMOTE_ADDR. */
    public static function get_ip() {
        $peer = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        if ( $peer === '' || ! filter_var( $peer, FILTER_VALIDATE_IP ) ) return $peer;

        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && self::is_trusted_proxy( $peer, true ) ) {
            $candidate = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) );
            if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) return $candidate;
        }
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) && self::is_trusted_proxy( $peer ) ) {
            $chain = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
            /* the right-most address was added by our own trusted proxy */
            $candidate = trim( end( $chain ) );
            if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) return $candidate;
        }
        return $peer;
    }

    /* ---- Keep personal pages out of page caches ----------------------
     * EtelID sign-ins are NOT WordPress logins, so a page cache (SpeedyCache
     * is active on the live site; LiteSpeed's cache can be too) sees every
     * visitor as anonymous and may store the page it rendered for a signed-in
     * member - then serve that member's card or portal to the next visitor.
     * Call this before rendering anything personal: it sets the "do not
     * cache" constants the common cache plugins honour plus private/no-store
     * headers for browsers, proxies and LiteSpeed. */
    public static function no_cache() {
        foreach ( array( 'DONOTCACHEPAGE', 'DONOTCACHEOBJECT', 'DONOTCACHEDB', 'DONOTMINIFY', 'DONOTCDN', 'DONOTCACHE' ) as $c ) {
            if ( ! defined( $c ) ) define( $c, true );
        }
        if ( function_exists( 'nocache_headers' ) ) nocache_headers();
        if ( ! headers_sent() ) {
            header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
            header( 'Pragma: no-cache' );
            header( 'X-LiteSpeed-Cache-Control: no-cache, no-store' );
            header( 'X-Robots-Tag: noindex, nofollow' );
        }
    }

    /* ---- Public (unauthenticated) view of a member ------------------
     * The public verify page / QR scan is for answering "is this a real,
     * active Etel-ID and whose?" - not for handing out the whole identity.
     * Returns a COPY with the sensitive fields masked: date of birth keeps
     * the year only, phone / FIN / card number keep the last digits, blood
     * type is hidden. Name, photo, tier, status, company and validity dates
     * stay so a genuine card still verifies. */
    public static function public_view( $member ) {
        $m = clone $member;
        $mask = function ( $v, $keep ) {
            $v = (string) $v;
            if ( $v === '' ) return '';
            $n = strlen( $v );
            if ( $n <= $keep ) return str_repeat( '*', $n );
            return str_repeat( '*', $n - $keep ) . substr( $v, -$keep );
        };
        if ( isset( $m->dob ) )         $m->dob         = substr( (string) $m->dob, 0, 4 );
        if ( isset( $m->dob_eth ) )     $m->dob_eth     = '';
        if ( isset( $m->blood_type ) )  $m->blood_type  = '';
        if ( isset( $m->phone ) )       $m->phone       = $mask( $m->phone, 3 );
        if ( isset( $m->email ) )       $m->email       = '';
        if ( isset( $m->fin ) )         $m->fin         = $mask( $m->fin, 4 );
        if ( isset( $m->card_number ) ) $m->card_number = $mask( $m->card_number, 4 );
        return $m;
    }

    /* ---- Failed-attempt lockouts ------------------------------------
     * rate_limit() above counts EVERY call, which is wrong for password
     * checks (a courier who taps many times with the right password would
     * lock themselves out). These count only FAILURES, per account: call
     * fail_blocked() before checking a password, fail_hit() after a wrong
     * one, fail_clear() after a right one. */
    public static function fail_blocked( $key, $max = 8, $window = 900 ) {
        return (int) get_transient( 'etelid_fl_' . md5( $key ) ) >= $max;
    }
    public static function fail_hit( $key, $window = 900 ) {
        $t = 'etelid_fl_' . md5( $key );
        set_transient( $t, (int) get_transient( $t ) + 1, $window );
    }
    public static function fail_clear( $key ) {
        delete_transient( 'etelid_fl_' . md5( $key ) );
    }
}
