<?php
/**
 * EtelID_WebAuthn — real platform-biometric login (Face ID / Touch ID /
 * Android fingerprint & face unlock / Windows Hello) via the browser's
 * native WebAuthn API.
 *
 * This is NOT a photo-upload or hash-paste flow. navigator.credentials
 * .create()/.get() hand the actual biometric prompt to the device's own
 * OS-level authenticator hardware (Secure Enclave / TEE / etc.) -- the
 * raw biometric (face geometry, fingerprint) never leaves the device and
 * never reaches this server. What we store and verify is a public key +
 * credential ID (asymmetric crypto), exactly like every other WebAuthn
 * relying party (GitHub, Google, banks, ...).
 *
 * Scope: ES256 (ECDSA P-256 / SHA-256) only -- the algorithm essentially
 * every platform authenticator (Face ID, Touch ID, Android BiometricPrompt,
 * Windows Hello) actually uses. Verification is done with plain PHP
 * OpenSSL, no Composer dependency, PHP 7+ compatible.
 *
 * @package Etel-ID Premium
 * @since   3.12.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_WebAuthn {

    const CHALLENGE_TTL = 120; // seconds

    /* ── RP identity ─────────────────────────────────────────── */
    private static function rp_id() {
        return wp_parse_url( home_url(), PHP_URL_HOST );
    }
    private static function rp_name() {
        return get_option( 'etelid_org_name' ) ?: 'ኢE Etel-ID';
    }

    /* ── Challenge storage (short-lived, single-use) ─────────── */
    private static function new_challenge( $context_key ) {
        $challenge = function_exists( 'random_bytes' ) ? random_bytes( 32 ) : openssl_random_pseudo_bytes( 32 );
        set_transient( 'etelid_wa_chal_' . $context_key, base64_encode( $challenge ), self::CHALLENGE_TTL );
        return $challenge;
    }
    private static function consume_challenge( $context_key ) {
        $key = 'etelid_wa_chal_' . $context_key;
        $val = get_transient( $key );
        if ( $val === false ) return null;
        delete_transient( $key );
        return base64_decode( $val );
    }

    /* ── base64url helpers (WebAuthn wire format) ────────────── */
    public static function b64url_encode( $bin ) {
        return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
    }
    public static function b64url_decode( $str ) {
        $str = strtr( (string) $str, '-_', '+/' );
        $pad = strlen( $str ) % 4;
        if ( $pad ) $str .= str_repeat( '=', 4 - $pad );
        return base64_decode( $str );
    }

    /* ================================================================
     *  REGISTRATION (enroll THIS device's Face ID / fingerprint / etc.)
     * ================================================================ */

    /** Options for navigator.credentials.create() -- member must already be logged in. */
    public static function registration_options( $member ) {
        $challenge = self::new_challenge( 'reg_' . $member->id );
        $bio = EtelID_DB::get_biometric( $member->id );
        $exclude = array();
        if ( $bio && ! empty( $bio->webauthn_credential_id ) ) {
            $exclude[] = array(
                'type' => 'public-key',
                'id'   => $bio->webauthn_credential_id, // already base64url
            );
        }
        return array(
            'challenge' => self::b64url_encode( $challenge ),
            'rp'        => array( 'name' => self::rp_name(), 'id' => self::rp_id() ),
            'user'      => array(
                'id'          => self::b64url_encode( (string) $member->id ),
                'name'        => $member->phone,
                'displayName' => $member->full_name,
            ),
            'pubKeyCredParams' => array(
                array( 'type' => 'public-key', 'alg' => -7 ), // ES256
            ),
            'authenticatorSelection' => array(
                'authenticatorAttachment' => 'platform',   // this device's own sensor, not a USB key
                'userVerification'        => 'required',   // forces the biometric/PIN prompt
                'residentKey'             => 'preferred',
            ),
            'attestation' => 'none', // we don't need vendor attestation chains, just the key
            'timeout'     => 60000,
            'excludeCredentials' => $exclude,
        );
    }

    /** Verify the browser's attestation response and store the credential. */
    public static function verify_registration( $member, $credential ) {
        $client_data_json = self::b64url_decode( $credential['response']['clientDataJSON'] );
        $client_data = json_decode( $client_data_json, true );
        if ( ! $client_data || $client_data['type'] !== 'webauthn.create' ) {
            return new WP_Error( 'bad_client_data', 'Invalid registration response.' );
        }

        $expected_challenge = self::consume_challenge( 'reg_' . $member->id );
        if ( ! $expected_challenge || self::b64url_decode( $client_data['challenge'] ) !== $expected_challenge ) {
            return new WP_Error( 'bad_challenge', 'Registration expired or invalid -- please try again.' );
        }
        if ( self::origin_mismatch( $client_data['origin'] ) ) {
            return new WP_Error( 'bad_origin', 'Origin mismatch.' );
        }

        $attestation_obj = self::b64url_decode( $credential['response']['attestationObject'] );
        $cbor = self::cbor_decode( $attestation_obj );
        if ( ! isset( $cbor['authData'] ) ) {
            return new WP_Error( 'bad_attestation', 'Could not read attestation data.' );
        }

        $auth_data = self::parse_auth_data( $cbor['authData'] );
        if ( is_wp_error( $auth_data ) ) return $auth_data;

        if ( $auth_data['rp_id_hash'] !== hash( 'sha256', self::rp_id(), true ) ) {
            return new WP_Error( 'bad_rp', 'This credential was not created for this site.' );
        }
        if ( ! $auth_data['user_present'] ) {
            return new WP_Error( 'no_presence', 'User presence was not confirmed.' );
        }
        if ( empty( $auth_data['credential_id'] ) || empty( $auth_data['public_key_cose'] ) ) {
            return new WP_Error( 'no_credential', 'No credential returned by the authenticator.' );
        }

        $pem = self::cose_ec2_to_pem( $auth_data['public_key_cose'] );
        if ( is_wp_error( $pem ) ) return $pem;

        $cred_id_b64url = self::b64url_encode( $auth_data['credential_id'] );

        $result = EtelID_DB::upsert_biometric( $member->id, $member->etel_id, array(
            'webauthn_credential_id' => $cred_id_b64url,
            'webauthn_public_key'    => $pem,
            'webauthn_sign_count'    => $auth_data['sign_count'],
            'liveness_level'         => 3,
            'liveness_confidence'    => 100,
            'enrolled_by'            => 'Self (device biometric)',
        ));
        if ( false === $result ) return new WP_Error( 'db_error', 'Could not save credential.' );

        EtelID_DB::log( $member->id, $member->etel_id, 'bio_enrolled', 'Device biometric (WebAuthn/' . self::guess_device_label() . ') registered' );
        return true;
    }

    /* ================================================================
     *  LOGIN (use an already-enrolled device to sign in, no password)
     * ================================================================ */

    /** Options for navigator.credentials.get() -- identifier is phone or Etel-ID, entered before the prompt. */
    public static function login_options( $identifier ) {
        $member = EtelID_DB::get_by_etel_id( strtoupper( $identifier ) );
        if ( ! $member ) $member = EtelID_DB::find_by_phone( $identifier );
        if ( ! $member ) return new WP_Error( 'not_found', 'No account found for that phone/Etel-ID.' );

        $bio = EtelID_DB::get_biometric( $member->id );
        if ( ! $bio || empty( $bio->webauthn_credential_id ) ) {
            return new WP_Error( 'not_enrolled', 'No device biometric registered for this account yet.' );
        }

        $challenge = self::new_challenge( 'login_' . $member->id );
        return array(
            'challenge'        => self::b64url_encode( $challenge ),
            'rpId'             => self::rp_id(),
            'timeout'          => 60000,
            'userVerification' => 'required',
            'allowCredentials' => array( array(
                'type'       => 'public-key',
                'id'         => $bio->webauthn_credential_id,
                'transports' => array( 'internal' ),
            ) ),
            '_member_id' => $member->id, // returned to the client only to echo back on verify
        );
    }

    /** Verify the browser's assertion response; on success, starts a real login session. */
    public static function verify_login( $member_id, $credential ) {
        $member = EtelID_DB::get_member( (int) $member_id );
        if ( ! $member ) return new WP_Error( 'not_found', 'Account not found.' );

        $bio = EtelID_DB::get_biometric( $member->id );
        if ( ! $bio || empty( $bio->webauthn_public_key ) ) {
            return new WP_Error( 'not_enrolled', 'No device biometric registered for this account.' );
        }
        if ( $credential['id'] !== $bio->webauthn_credential_id ) {
            return new WP_Error( 'wrong_credential', 'This device is not registered to this account.' );
        }

        $client_data_json = self::b64url_decode( $credential['response']['clientDataJSON'] );
        $client_data = json_decode( $client_data_json, true );
        if ( ! $client_data || $client_data['type'] !== 'webauthn.get' ) {
            return new WP_Error( 'bad_client_data', 'Invalid sign-in response.' );
        }

        $expected_challenge = self::consume_challenge( 'login_' . $member->id );
        if ( ! $expected_challenge || self::b64url_decode( $client_data['challenge'] ) !== $expected_challenge ) {
            return new WP_Error( 'bad_challenge', 'Sign-in expired -- please try again.' );
        }
        if ( self::origin_mismatch( $client_data['origin'] ) ) {
            return new WP_Error( 'bad_origin', 'Origin mismatch.' );
        }

        $auth_data_raw = self::b64url_decode( $credential['response']['authenticatorData'] );
        $auth_data = self::parse_auth_data( $auth_data_raw, false );
        if ( is_wp_error( $auth_data ) ) return $auth_data;

        if ( $auth_data['rp_id_hash'] !== hash( 'sha256', self::rp_id(), true ) ) {
            return new WP_Error( 'bad_rp', 'Origin mismatch.' );
        }
        if ( ! $auth_data['user_present'] ) {
            return new WP_Error( 'no_presence', 'Biometric presence was not confirmed.' );
        }

        /* The actual cryptographic proof: authenticatorData || SHA256(clientDataJSON),
           signed by the private key that never leaves the device's secure hardware. */
        $signed_data = $auth_data_raw . hash( 'sha256', $client_data_json, true );
        $signature   = self::b64url_decode( $credential['response']['signature'] );

        $ok = openssl_verify( $signed_data, $signature, $bio->webauthn_public_key, OPENSSL_ALGO_SHA256 );
        if ( $ok !== 1 ) {
            EtelID_DB::log( $member->id, $member->etel_id, 'login_failed', 'WebAuthn signature verification failed' );
            return new WP_Error( 'bad_signature', 'Biometric verification failed.' );
        }

        /* Clone/replay detection: a real authenticator's counter only ever
           increases. Many platform authenticators report 0 always (they
           track state internally instead) -- only enforce the check when
           the stored counter is non-zero, matching common relying-party
           practice for resident platform keys. */
        if ( (int) $bio->webauthn_sign_count > 0 && $auth_data['sign_count'] <= (int) $bio->webauthn_sign_count ) {
            EtelID_DB::log( $member->id, $member->etel_id, 'login_failed', 'WebAuthn possible cloned credential (counter did not increase)' );
            return new WP_Error( 'clone_detected', 'Security check failed. Please contact support.' );
        }

        global $wpdb;
        $wpdb->update( EtelID_DB::t_biometric(), array( 'webauthn_sign_count' => $auth_data['sign_count'] ), array( 'member_id' => $member->id ) );

        if ( $member->status === 'suspended' ) return new WP_Error( 'suspended', 'This account has been suspended.' );
        if ( $member->status === 'revoked' )   return new WP_Error( 'revoked', 'This account has been revoked.' );

        $token = EtelID_Auth::create_session( $member->id );
        EtelID_DB::log( $member->id, $member->etel_id, 'login', 'Signed in with device biometric (' . self::guess_device_label() . ')' );

        return array( 'member' => $member, 'token' => $token );
    }

    /* ================================================================
     *  Helpers
     * ================================================================ */

    private static function origin_mismatch( $origin ) {
        $expected = wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://' . self::rp_id();
        // Allow default https port variance but require exact scheme+host match.
        return rtrim( $origin, '/' ) !== rtrim( $expected, '/' );
    }

    private static function guess_device_label() {
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        if ( stripos( $ua, 'iphone' ) !== false || stripos( $ua, 'ipad' ) !== false ) return 'Face ID / Touch ID';
        if ( stripos( $ua, 'android' ) !== false ) return 'Android biometric';
        if ( stripos( $ua, 'mac os' ) !== false )  return 'Touch ID';
        if ( stripos( $ua, 'windows' ) !== false ) return 'Windows Hello';
        return 'device biometric';
    }

    /** Parses the fixed-layout authenticatorData binary structure (no CBOR needed for this part). */
    private static function parse_auth_data( $raw, $expect_attested_credential_data = true ) {
        if ( strlen( $raw ) < 37 ) return new WP_Error( 'bad_auth_data', 'Authenticator data too short.' );
        $rp_id_hash = substr( $raw, 0, 32 );
        $flags      = ord( $raw[32] );
        $sign_count = unpack( 'N', substr( $raw, 33, 4 ) )[1];

        $out = array(
            'rp_id_hash'    => $rp_id_hash,
            'user_present'  => (bool) ( $flags & 0x01 ),
            'user_verified' => (bool) ( $flags & 0x04 ),
            'sign_count'    => $sign_count,
            'credential_id' => '',
            'public_key_cose' => '',
        );

        $has_attested = (bool) ( $flags & 0x40 );
        if ( ! $has_attested ) {
            if ( $expect_attested_credential_data ) return new WP_Error( 'no_attested_data', 'No credential data returned.' );
            return $out; // login assertions don't include attested credential data
        }

        $offset = 37;
        $offset += 16; // aaguid
        if ( strlen( $raw ) < $offset + 2 ) return new WP_Error( 'bad_auth_data', 'Truncated authenticator data.' );
        $cred_id_len = unpack( 'n', substr( $raw, $offset, 2 ) )[1];
        $offset += 2;
        $out['credential_id'] = substr( $raw, $offset, $cred_id_len );
        $offset += $cred_id_len;

        // Remaining bytes are the CBOR-encoded COSE public key (single item).
        $out['public_key_cose'] = substr( $raw, $offset );
        return $out;
    }

    /** Converts a COSE EC2/ES256 public key (CBOR map) to a PEM string usable by openssl_verify(). */
    private static function cose_ec2_to_pem( $cose_bytes ) {
        $key = self::cbor_decode( $cose_bytes );
        if ( ! is_array( $key ) || ! isset( $key[1], $key[3], $key[-1], $key[-2], $key[-3] ) ) {
            return new WP_Error( 'bad_cose_key', 'Unsupported credential key format.' );
        }
        if ( (int) $key[3] !== -7 || (int) $key[1] !== 2 || (int) $key[-1] !== 1 ) {
            return new WP_Error( 'unsupported_alg', 'Only ES256 (P-256) platform keys are supported.' );
        }
        $x = $key[-2];
        $y = $key[-3];
        if ( strlen( $x ) !== 32 || strlen( $y ) !== 32 ) {
            return new WP_Error( 'bad_cose_key', 'Malformed EC point.' );
        }

        // Build an uncompressed EC point (0x04 || x || y) and wrap it in a
        // minimal SPKI DER structure for the P-256 curve (OID 1.2.840.10045.3.1.7).
        $point = "\x04" . $x . $y;
        $spki  = self::der_ec_spki( $point );
        $pem   = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";

        // Sanity check: OpenSSL must accept it as a valid EC public key.
        $res = openssl_pkey_get_public( $pem );
        if ( ! $res ) return new WP_Error( 'bad_pem', 'Could not construct a usable public key.' );
        return $pem;
    }

    /** Minimal DER SPKI wrapper for a raw P-256 (prime256v1) uncompressed point. */
    private static function der_ec_spki( $point ) {
        // AlgorithmIdentifier: SEQUENCE { OID id-ecPublicKey, OID prime256v1 }
        $oid_ec    = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";             // 1.2.840.10045.2.1
        $oid_curve = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";         // 1.2.840.10045.3.1.7
        $alg_id    = "\x30" . self::der_len( strlen( $oid_ec . $oid_curve ) ) . $oid_ec . $oid_curve;
        $bit_string = "\x03" . self::der_len( strlen( $point ) + 1 ) . "\x00" . $point;
        return "\x30" . self::der_len( strlen( $alg_id . $bit_string ) ) . $alg_id . $bit_string;
    }
    private static function der_len( $n ) {
        if ( $n < 128 ) return chr( $n );
        $bytes = ltrim( pack( 'N', $n ), "\x00" );
        return chr( 0x80 | strlen( $bytes ) ) . $bytes;
    }

    /* ── Minimal CBOR decoder (major types 0,1,2,3,4,5,6,7 -- RFC 8949) ──
       Only what WebAuthn attestation objects and COSE keys actually use. */
    private static function cbor_decode( $data ) {
        $pos = 0;
        $val = self::cbor_read( $data, $pos );
        return $val;
    }
    private static function cbor_read( $data, &$pos ) {
        $byte = ord( $data[ $pos ] );
        $major = $byte >> 5;
        $info  = $byte & 0x1f;
        $pos++;
        $len = self::cbor_read_len( $data, $pos, $info );

        switch ( $major ) {
            case 0: return $len; // unsigned int
            case 1: return -1 - $len; // negative int
            case 2: $v = substr( $data, $pos, $len ); $pos += $len; return $v; // byte string
            case 3: $v = substr( $data, $pos, $len ); $pos += $len; return $v; // text string
            case 4: // array
                $arr = array();
                for ( $i = 0; $i < $len; $i++ ) $arr[] = self::cbor_read( $data, $pos );
                return $arr;
            case 5: // map
                $map = array();
                for ( $i = 0; $i < $len; $i++ ) {
                    $k = self::cbor_read( $data, $pos );
                    $v = self::cbor_read( $data, $pos );
                    $map[ $k ] = $v;
                }
                return $map;
            case 6: return self::cbor_read( $data, $pos ); // tagged -- ignore tag, return content
            case 7:
                if ( $info === 20 ) return false;
                if ( $info === 21 ) return true;
                if ( $info === 22 ) return null;
                return $len; // simple values / floats not needed for our structures
        }
        return null;
    }
    private static function cbor_read_len( $data, &$pos, $info ) {
        if ( $info < 24 ) return $info;
        if ( $info === 24 ) { $v = ord( $data[ $pos ] ); $pos += 1; return $v; }
        if ( $info === 25 ) { $v = unpack( 'n', substr( $data, $pos, 2 ) )[1]; $pos += 2; return $v; }
        if ( $info === 26 ) { $v = unpack( 'N', substr( $data, $pos, 4 ) )[1]; $pos += 4; return $v; }
        if ( $info === 27 ) {
            $hi = unpack( 'N', substr( $data, $pos, 4 ) )[1];
            $lo = unpack( 'N', substr( $data, $pos + 4, 4 ) )[1];
            $pos += 8;
            return ( $hi * 4294967296 ) + $lo;
        }
        return 0; // indefinite-length (0x1f) not used by WebAuthn structures we parse
    }
}
