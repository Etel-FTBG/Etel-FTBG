<?php
/**
 * EtelID - NTAG 424 DNA "Secure Unique NFC" (SUN / SDM) verifier.
 *
 * A plain NFC card stores a fixed URL, so anyone who photographs or copies the
 * link can clone the card. An NTAG 424 DNA chip rewrites the URL on every tap
 * with two extra values:
 *     e = the chip UID + a tap counter, encrypted with the chip's AES key
 *     c = an AES-CMAC signature (8 bytes) over that data
 * so every tap is different, cannot be forged without the key, and a replayed
 * (older) tap is rejected because the counter must always go up.
 *
 * This class is pure crypto - no WordPress calls - so it can be unit tested.
 * Spec: NXP AN12196 (NTAG 424 DNA features and hints), AES-CMAC = RFC 4493.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_SUN {

    /* AES-128 ECB single block, no padding. */
    protected static function aes_block( $key, $block ) {
        return openssl_encrypt( $block, 'AES-128-ECB', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING );
    }

    protected static function shift_left( $s ) {
        $out = ''; $carry = 0;
        for ( $i = strlen( $s ) - 1; $i >= 0; $i-- ) {
            $b   = ord( $s[ $i ] );
            $out = chr( ( ( $b << 1 ) & 0xFF ) | $carry ) . $out;
            $carry = ( $b & 0x80 ) ? 1 : 0;
        }
        return array( $out, $carry );
    }

    protected static function subkey( $s ) {
        list( $o, $carry ) = self::shift_left( $s );
        if ( $carry ) $o[15] = chr( ord( $o[15] ) ^ 0x87 );
        return $o;
    }

    /** AES-CMAC (RFC 4493). $key and result are raw binary; result is 16 bytes. */
    public static function cmac( $key, $msg ) {
        $L  = self::aes_block( $key, str_repeat( "\0", 16 ) );
        $k1 = self::subkey( $L );
        $k2 = self::subkey( $k1 );
        $n  = strlen( $msg ) === 0 ? 1 : (int) ceil( strlen( $msg ) / 16 );
        $complete = strlen( $msg ) > 0 && strlen( $msg ) % 16 === 0;
        $last = substr( $msg, ( $n - 1 ) * 16 );
        if ( $complete ) {
            $last = $last ^ $k1;
        } else {
            $last = ( $last . "\x80" . str_repeat( "\0", 15 - strlen( $last ) ) ) ^ $k2;
        }
        $x = str_repeat( "\0", 16 );
        for ( $i = 0; $i < $n - 1; $i++ ) {
            $x = self::aes_block( $key, $x ^ substr( $msg, $i * 16, 16 ) );
        }
        return self::aes_block( $key, $x ^ $last );
    }

    /* SDM MAC = the odd-numbered bytes (2nd, 4th, ...) of the 16-byte CMAC. */
    protected static function truncate( $mac ) {
        $t = '';
        for ( $i = 1; $i < 16; $i += 2 ) $t .= $mac[ $i ];
        return $t;
    }

    protected static function clean_key( $hex ) {
        $hex = strtolower( preg_replace( '/[^0-9a-f]/i', '', (string) $hex ) );
        return strlen( $hex ) === 32 ? hex2bin( $hex ) : false;
    }

    /**
     * Verify one tap.
     *
     * @param string $picc_hex  value of the "e" URL parameter (32 hex chars)
     * @param string $mac_hex   value of the "c" URL parameter (16 hex chars)
     * @param string $meta_key  32 hex chars: key that encrypts the UID/counter
     * @param string $file_key  32 hex chars: key that signs the tap (usually the same)
     * @param string $mac_input bytes the chip signed besides the UID/counter ('' by default)
     * @return array|false  array( 'uid' => 14 hex chars, 'counter' => int ) or false
     */
    public static function verify( $picc_hex, $mac_hex, $meta_key, $file_key = '', $mac_input = '' ) {
        if ( ! preg_match( '/^[0-9a-fA-F]{32}$/', (string) $picc_hex ) ) return false;
        if ( ! preg_match( '/^[0-9a-fA-F]{16}$/', (string) $mac_hex ) )  return false;
        $mk = self::clean_key( $meta_key );
        $fk = self::clean_key( $file_key !== '' ? $file_key : $meta_key );
        if ( $mk === false || $fk === false ) return false;

        $plain = openssl_decrypt( hex2bin( $picc_hex ), 'AES-128-CBC', $mk, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat( "\0", 16 ) );
        if ( $plain === false || strlen( $plain ) < 11 ) return false;
        if ( ( ord( $plain[0] ) & 0xC0 ) !== 0xC0 ) return false;       /* UID + counter must both be mirrored */
        if ( ( ord( $plain[0] ) & 0x0F ) !== 7 )     return false;       /* 7-byte UID */

        $uid = substr( $plain, 1, 7 );
        $ctr = substr( $plain, 8, 3 );                                    /* little-endian */

        $sv  = "\x3C\xC3\x00\x01\x00\x80" . $uid . $ctr;
        $ses = self::cmac( $fk, $sv );
        $exp = self::truncate( self::cmac( $ses, $mac_input ) );
        if ( ! hash_equals( $exp, hex2bin( $mac_hex ) ) ) return false;

        $n = ord( $ctr[0] ) | ( ord( $ctr[1] ) << 8 ) | ( ord( $ctr[2] ) << 16 );
        return array( 'uid' => strtoupper( bin2hex( $uid ) ), 'counter' => $n );
    }

    /* Test/tools helper: build the e + c a genuine chip would send. */
    public static function simulate_tap( $uid_hex, $counter, $meta_key, $file_key = '', $mac_input = '' ) {
        $mk = self::clean_key( $meta_key );
        $fk = self::clean_key( $file_key !== '' ? $file_key : $meta_key );
        $uid = hex2bin( $uid_hex );
        $ctr = chr( $counter & 0xFF ) . chr( ( $counter >> 8 ) & 0xFF ) . chr( ( $counter >> 16 ) & 0xFF );
        $plain = "\xC7" . $uid . $ctr . str_repeat( "\0", 5 );
        $e = openssl_encrypt( $plain, 'AES-128-CBC', $mk, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat( "\0", 16 ) );
        $ses = self::cmac( $fk, "\x3C\xC3\x00\x01\x00\x80" . $uid . $ctr );
        return array( strtoupper( bin2hex( $e ) ), strtoupper( bin2hex( self::truncate( self::cmac( $ses, $mac_input ) ) ) ) );
    }
}
