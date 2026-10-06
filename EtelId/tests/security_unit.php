<?php
/**
 * EtelID week-1 security unit tests (no WordPress needed).
 *
 *   php EtelId/tests/security_unit.php
 *
 * Loads the REAL plugin classes against tiny WordPress stubs and checks the
 * logic that matters: spoof-proof client IP, failed-attempt lockouts, the
 * public masked view, per-app service keys + scopes, session revocation SQL
 * and the NFC auto-revoke SQL. Exit code 0 = all passed.
 */
define( 'ABSPATH', '/x/' );

/* ---- WordPress stubs ------------------------------------------------ */
$GLOBALS['__transients'] = array();
$GLOBALS['__log']        = array();
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function wp_unslash( $s ) { return $s; }
function get_transient( $k ) { return isset( $GLOBALS['__transients'][ $k ] ) ? $GLOBALS['__transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; }
function get_option( $k, $d = '' ) { return $d; }
function add_action() {}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error { public $code; public $msg; public $data;
    function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->msg = $m; $this->data = $d; }
    function get_error_code() { return $this->code; } function get_error_message() { return $this->msg; } }
class EtelID_DB { static function log( $mid, $eid, $ev, $detail = '' ) { $GLOBALS['__log'][] = array( $ev, $detail ); }
    static function get_member( $id ) { return isset( $GLOBALS['__members'][ $id ] ) ? $GLOBALS['__members'][ $id ] : null; }
    static function get_biometric( $id ) { return isset( $GLOBALS['__bio'][ $id ] ) ? $GLOBALS['__bio'][ $id ] : null; }
    static function get_by_etel_id( $e ) { return null; } static function find_by_phone( $p ) { return null; }
    static function t_biometric() { return 'wp_bio'; } }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function home_url( $p = '' ) { return 'https://ethelmarket.com' . $p; }
function is_ssl() { return true; }
define( 'COOKIEPATH', '/' ); define( 'COOKIE_DOMAIN', '' ); define( 'ETELID_COOKIE_NAME', 'etelid_sess' );
class FakeRequest { private $h; function __construct( $h ) { $this->h = $h; }
    function get_header( $n ) { return isset( $this->h[ $n ] ) ? $this->h[ $n ] : null; } }
class FakeWpdb { public $prefix = 'wp_'; public $queries = array();
    function prepare( $q ) { $a = func_get_args(); array_shift( $a ); $i = 0;
        return preg_replace_callback( '/%[ds]/', function ( $m ) use ( &$i, $a ) { $v = $a[ $i++ ]; return $m[0] === '%d' ? (int) $v : "'" . $v . "'"; }, $q ); }
    function query( $q ) { $this->queries[] = $q; return 1; }
    function get_col( $q ) { $this->queries[] = $q; return array( '3', '2' ); } }

require __DIR__ . '/../includes/class-etelid-security.php';
require __DIR__ . '/../includes/class-etelid-service-api.php';
require __DIR__ . '/../includes/class-etelid-auth.php';
require __DIR__ . '/../includes/class-etelid-sun.php';
require __DIR__ . '/../includes/class-etelid-webauthn.php';
require __DIR__ . '/../includes/class-etelid-nfc.php';
function current_time( $t ) { return '2026-10-06 00:00:00'; }
function random_bytes_stub() {}
class FakeWpdb2 extends FakeWpdb { public $insert_id = 0; function insert( $t, $d ) { $this->queries[] = 'INSERT ' . $t; return 1; } }

/* ---- tiny test runner ----------------------------------------------- */
$fails = 0;
function check( $name, $cond, $detail = '' ) {
    global $fails;
    echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . $name . ( $cond ? '' : '  -> ' . $detail ) . "\n";
    if ( ! $cond ) $fails++;
}
function with_server( array $s, $fn ) {
    $old = $_SERVER; $_SERVER = array_merge( $old, $s ); $r = $fn(); $_SERVER = $old; return $r;
}

echo "ip_in_cidr / trusted proxies\n";
check( 'ipv4 inside /20',  EtelID_Security::ip_in_cidr( '173.245.50.9', '173.245.48.0/20' ) );
check( 'ipv4 outside /20', ! EtelID_Security::ip_in_cidr( '173.245.64.1', '173.245.48.0/20' ) );
check( 'ipv6 inside /32',  EtelID_Security::ip_in_cidr( '2606:4700::1111', '2606:4700::/32' ) );
check( 'ipv6 outside /32', ! EtelID_Security::ip_in_cidr( '2606:4701::1', '2606:4700::/32' ) );
check( 'mixed families never match', ! EtelID_Security::ip_in_cidr( '1.2.3.4', '2606:4700::/32' ) );

echo "get_ip cannot be spoofed\n";
check( 'direct client forging CF header is ignored',
    with_server( array( 'REMOTE_ADDR' => '41.66.0.5', 'HTTP_CF_CONNECTING_IP' => '9.9.9.9' ), function () { return EtelID_Security::get_ip(); } ) === '41.66.0.5' );
check( 'direct client forging X-Forwarded-For is ignored',
    with_server( array( 'REMOTE_ADDR' => '41.66.0.5', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9' ), function () { return EtelID_Security::get_ip(); } ) === '41.66.0.5' );
check( 'real Cloudflare peer: CF-Connecting-IP is honoured',
    with_server( array( 'REMOTE_ADDR' => '104.16.1.1', 'HTTP_CF_CONNECTING_IP' => '41.66.0.77' ), function () { return EtelID_Security::get_ip(); } ) === '41.66.0.77' );
check( 'Cloudflare peer but garbage header falls back to peer',
    with_server( array( 'REMOTE_ADDR' => '104.16.1.1', 'HTTP_CF_CONNECTING_IP' => 'not-an-ip' ), function () { return EtelID_Security::get_ip(); } ) === '104.16.1.1' );

echo "failed-attempt lockout\n";
$k = 'nfc_acct_eg-2026-00001';
check( 'not blocked initially', ! EtelID_Security::fail_blocked( $k, 3, 900 ) );
EtelID_Security::fail_hit( $k ); EtelID_Security::fail_hit( $k );
check( 'not blocked after 2 of 3', ! EtelID_Security::fail_blocked( $k, 3, 900 ) );
EtelID_Security::fail_hit( $k );
check( 'blocked after 3 of 3', EtelID_Security::fail_blocked( $k, 3, 900 ) );
EtelID_Security::fail_clear( $k );
check( 'a correct password clears the lock', ! EtelID_Security::fail_blocked( $k, 3, 900 ) );

echo "public masked view\n";
$m = (object) array( 'full_name' => 'Abebe K', 'dob' => '1990-05-12', 'dob_eth' => '1982', 'blood_type' => 'O+',
    'phone' => '+251911000123', 'email' => 'a@b.c', 'fin' => '123456789012', 'card_number' => '1234567890123456',
    'status' => 'active', 'etel_id' => 'EG-2026-00001' );
$v = EtelID_Security::public_view( $m );
check( 'original object untouched', $m->phone === '+251911000123' && $m->fin === '123456789012' );
check( 'dob keeps year only', $v->dob === '1990' && $v->dob_eth === '' );
check( 'blood type and email hidden', $v->blood_type === '' && $v->email === '' );
check( 'phone shows last 3 only', substr( $v->phone, -3 ) === '123' && strpos( $v->phone, '+2519' ) === false );
check( 'FIN shows last 4 only', $v->fin === '********9012' );
check( 'card number shows last 4 only', $v->card_number === '************3456' );
check( 'name / status / id kept so a real card still verifies', $v->full_name === 'Abebe K' && $v->status === 'active' && $v->etel_id === 'EG-2026-00001' );

echo "limited-account helpers work on installs without the feature (EtelID_DB has no is_limited)\n";
check( 'test stub really lacks EtelID_DB::is_limited (simulates the older live class)', ! method_exists( 'EtelID_DB', 'is_limited' ) );
check( 'member_is_limited: flag 0 -> false', EtelID_Security::member_is_limited( (object) array( 'profile_limited' => 0 ) ) === false );
check( 'member_is_limited: flag 1 -> true', EtelID_Security::member_is_limited( (object) array( 'profile_limited' => 1 ) ) === true );
check( 'member_is_limited: no column at all -> false (nothing is limited)', EtelID_Security::member_is_limited( (object) array( 'id' => 1 ) ) === false );
check( 'member_missing_items lists the 3 missing documents',
    EtelID_Security::member_missing_items( (object) array( 'photo_url' => '', 'fayda_front_image' => 'x' ) ) === array( 'photo', 'fayda_back' ) );
check( 'member_refresh_limited is a harmless true when the feature is absent', EtelID_Security::member_refresh_limited( 5 ) === true );

echo "personal pages are kept out of page caches\n";
EtelID_Security::no_cache();
check( 'DONOTCACHEPAGE is set (SpeedyCache / WP cache plugins honour it)', defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE === true );
check( 'DONOTMINIFY / DONOTCDN set', defined( 'DONOTMINIFY' ) && defined( 'DONOTCDN' ) );

echo "service API keys and scopes\n";
define( 'ETELID_SERVICE_KEY', 'MASTER_master_master_master_1234' );
define( 'ETELID_SERVICE_KEYS', json_encode( array(
    array( 'app' => 'delivery', 'key' => 'DELIVERY_key_delivery_key_12345', 'scopes' => array( 'resolve', 'authenticate' ) ),
    array( 'app' => 'pinned',   'key' => 'PINNED_key_pinned_key_pinned_12', 'scopes' => array( 'resolve' ), 'ips' => array( '203.0.113.0/24' ) ),
    array( 'app' => 'weak',     'key' => 'short', 'scopes' => array( '*' ) ),
) ) );
function svc( $key, $scope, $ip = '41.66.0.5' ) {
    return with_server( array( 'REMOTE_ADDR' => $ip ), function () use ( $key, $scope ) {
        return EtelID_Service_API::check_service_key( new FakeRequest( array( 'x-etel-service-key' => $key ) ), $scope ); } );
}
check( 'per-app key works inside its scope', svc( 'DELIVERY_key_delivery_key_12345', 'resolve' ) === true );
check( 'caller is named for the audit log', EtelID_Service_API::caller_label() === 'delivery' );
$r = svc( 'DELIVERY_key_delivery_key_12345', 'set_company_role' );
check( 'per-app key is REFUSED outside its scope (cannot grant roles)', is_wp_error( $r ) && $r->data['status'] === 403, print_r( $r, true ) );
check( 'IP-pinned key refused from another address', is_wp_error( svc( 'PINNED_key_pinned_key_pinned_12', 'resolve', '41.66.0.5' ) ) );
check( 'IP-pinned key accepted from its own network', svc( 'PINNED_key_pinned_key_pinned_12', 'resolve', '203.0.113.9' ) === true );
check( 'keys shorter than 24 chars are ignored', is_wp_error( svc( 'short', 'resolve' ) ) );
check( 'legacy master key still works on every route', svc( 'MASTER_master_master_master_1234', 'set_company_role' ) === true );
check( 'unknown key rejected', is_wp_error( svc( 'nope', 'resolve' ) ) );
for ( $i = 0; $i < 25; $i++ ) svc( 'guess' . $i, 'resolve', '198.51.100.7' );
$r = svc( 'MASTER_master_master_master_1234', 'resolve', '198.51.100.7' );
check( 'key guessing is throttled per IP (429 even for a right key afterwards)', is_wp_error( $r ) && $r->data['status'] === 429 );

echo "sessions are revoked on password change / capped\n";
$GLOBALS['wpdb'] = new FakeWpdb();
EtelID_Auth::revoke_all_sessions( 7 );
check( 'reset: deletes ALL sessions of the member', preg_match( "/DELETE FROM wp_etelid_sessions WHERE member_id = 7\$/", end( $GLOBALS['wpdb']->queries ) ) === 1, end( $GLOBALS['wpdb']->queries ) );
EtelID_Auth::revoke_all_sessions( 7, 'RAWTOKEN' );
$q = end( $GLOBALS['wpdb']->queries );
check( 'password change: keeps only the caller session (by hash)', strpos( $q, "token <> '" . hash( 'sha256', 'RAWTOKEN' ) . "'" ) !== false && strpos( $q, 'member_id = 7' ) !== false, $q );
EtelID_Auth::prune_member_sessions( 7, 15 );
$all = implode( "\n", $GLOBALS['wpdb']->queries );
check( 'cap: selects sessions beyond the newest 15 and deletes them', strpos( $all, 'OFFSET 15' ) !== false && strpos( $all, 'DELETE FROM wp_etelid_sessions WHERE id IN (3,2)' ) !== false, $all );

echo "NFC: a new card revokes the old ones\n";
$GLOBALS['wpdb'] = new FakeWpdb2();
$tok = Etelid_Nfc::issue_card( 4 );
$all = implode( "\n", $GLOBALS['wpdb']->queries );
check( 'issue_card revokes earlier active cards of that member', strpos( $all, "SET status = 'revoked' WHERE member_id = 4 AND status = 'active' AND token <> '" . $tok . "'" ) !== false, $all );
$GLOBALS['wpdb'] = new FakeWpdb2();
Etelid_Nfc::issue_card( 4, false );
check( 'keep_old issues an extra card without revoking', strpos( implode( "\n", $GLOBALS['wpdb']->queries ), 'revoked' ) === false );
Etelid_Nfc::revoke_all_for_member( 4 );
check( 'lost-card button revokes every active card', strpos( end( $GLOBALS['wpdb']->queries ), "member_id = 4 AND status = 'active'" ) !== false );

echo "NTAG 424 DNA signed taps (SUN)\n";
$rfc = hex2bin( '2b7e151628aed2a6abf7158809cf4f3c' );
check( 'AES-CMAC matches RFC 4493 (empty message)', strtoupper( bin2hex( EtelID_SUN::cmac( $rfc, '' ) ) ) === 'BB1D6929E95937287FA37D129B756746' );
check( 'AES-CMAC matches RFC 4493 (16-byte message)', strtoupper( bin2hex( EtelID_SUN::cmac( $rfc, hex2bin( '6bc1bee22e409f96e93d7e117393172a' ) ) ) ) === '070A16B46B4D4144F79BDD9DD04A287C' );
$zero = str_repeat( '0', 32 );
$real = EtelID_SUN::verify( 'EF963FF7828658A599F3041510671E88', '94EED9EE65337086', $zero );
check( 'a real NXP demo tap verifies (UID 04DE5F1EACC040, counter 61)', $real && $real['uid'] === '04DE5F1EACC040' && $real['counter'] === 61 );
check( 'a flipped signature bit is rejected', EtelID_SUN::verify( 'EF963FF7828658A599F3041510671E88', '94EED9EE65337087', $zero ) === false );
check( 'the wrong key is rejected', EtelID_SUN::verify( 'EF963FF7828658A599F3041510671E88', '94EED9EE65337086', str_repeat( '1', 32 ) ) === false );
check( 'malformed input is rejected', EtelID_SUN::verify( 'zz', 'zz', $zero ) === false && EtelID_SUN::verify( 'EF963FF7828658A599F3041510671E88', '94EED9EE65337086', 'short' ) === false );

define( 'ETELID_SUN_MODE', 'enforce' );
define( 'ETELID_SUN_META_KEY', '00112233445566778899AABBCCDDEEFF' );
class FakeWpdb3 extends FakeWpdb { function query( $q ) { $this->queries[] = $q; return 1; } }
$GLOBALS['wpdb'] = new FakeWpdb3();
$card = (object) array( 'id' => 9, 'member_id' => 4, 'token' => 'TOK123', 'card_uid' => null, 'sun_counter' => 0 );
list( $e1, $c1 ) = EtelID_SUN::simulate_tap( '04AABBCCDDEE80', 1, ETELID_SUN_META_KEY );
check( 'no signed data = none', Etelid_Nfc::sun_evaluate( $card, '', '' ) === 'none' );
check( 'enforce: no tap proof yet, so card actions are refused', Etelid_Nfc::has_tap_proof( 'TOK123' ) === false );
check( 'first genuine tap is ok', Etelid_Nfc::sun_evaluate( $card, $e1, $c1 ) === 'ok' );
check( 'tap stores counter and binds the chip UID', strpos( end( $GLOBALS['wpdb']->queries ), "sun_counter = 1, card_uid = '04AABBCCDDEE80'" ) !== false );
check( 'a genuine tap grants a tap proof', Etelid_Nfc::has_tap_proof( 'TOK123' ) === true );
$card->card_uid = '04AABBCCDDEE80'; $card->sun_counter = 1;
check( 'reloading the same tap while fresh is still ok', Etelid_Nfc::sun_evaluate( $card, $e1, $c1 ) === 'ok' );
delete_transient( 'etelid_tap_' . md5( 'TOK123' ) );
check( 'replaying an old tap after the window is rejected', Etelid_Nfc::sun_evaluate( $card, $e1, $c1 ) === 'replay' );
list( $e2, $c2 ) = EtelID_SUN::simulate_tap( '04AABBCCDDEE80', 2, ETELID_SUN_META_KEY );
check( 'the next tap (counter 2) is ok', Etelid_Nfc::sun_evaluate( $card, $e2, $c2 ) === 'ok' );
$card->sun_counter = 2;
list( $e3, $c3 ) = EtelID_SUN::simulate_tap( '04112233445566', 3, ETELID_SUN_META_KEY );
check( 'a different chip on this card is rejected', Etelid_Nfc::sun_evaluate( $card, $e3, $c3 ) === 'uid' );
check( 'forged signature is rejected', Etelid_Nfc::sun_evaluate( $card, $e2, '0000000000000000' ) === 'bad' );

echo "passkey / device sign-in (WebAuthn)\n";
class FakeWpdb4 extends FakeWpdb { public $pk = array(); public $updates = array(); public $deletes = array();
    function get_var( $q ) { $this->queries[] = $q; return 0; }
    function get_charset_collate() { return ''; }
    function get_row( $q ) { $this->queries[] = $q;
        if ( preg_match( "/FROM wp_etelid_passkeys WHERE credential_id = '([^']*)'/", $q, $m ) ) { foreach ( $this->pk as $r ) if ( $r->credential_id === $m[1] ) return clone $r; return null; }
        if ( preg_match( "/FROM wp_bio WHERE webauthn_credential_id = '([^']*)'/", $q, $m ) ) { foreach ( $GLOBALS['__bio'] as $mid => $b ) if ( $b->webauthn_credential_id === $m[1] ) { $c = clone $b; $c->member_id = $mid; return $c; } return null; }
        return null; }
    function get_results( $q ) { $this->queries[] = $q;
        if ( preg_match( '/FROM wp_etelid_passkeys WHERE member_id = (\d+)/', $q, $m ) ) { $o = array(); foreach ( $this->pk as $r ) if ( (int) $r->member_id === (int) $m[1] ) $o[] = clone $r; return $o; }
        return array(); }
    function update( $t, $d, $w ) { $this->updates[] = array( $t, $d, $w );
        if ( $t === 'wp_bio' ) { foreach ( $d as $k => $v ) $GLOBALS['__bio'][ $w['member_id'] ]->$k = $v; }
        if ( $t === 'wp_etelid_passkeys' ) { foreach ( $this->pk as $r ) if ( $r->credential_id === $w['credential_id'] ) foreach ( $d as $k => $v ) $r->$k = $v; }
        return 1; }
    function insert( $t, $d ) { if ( $t === 'wp_etelid_passkeys' ) { $this->pk[] = (object) $d; } return 1; }
    function delete( $t, $w ) { $this->deletes[] = array( $t, $w );
        if ( $t === 'wp_etelid_passkeys' ) { $this->pk = array_values( array_filter( $this->pk, function ( $r ) use ( $w ) { return ! ( (int) $r->member_id === (int) $w['member_id'] && ( ! isset( $w['credential_id'] ) || $r->credential_id === $w['credential_id'] ) ); } ) ); }
        return 1; } }
$wdb = new FakeWpdb4(); $GLOBALS['wpdb'] = $wdb;
$key = openssl_pkey_new( array( 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ) );
$pem = openssl_pkey_get_details( $key )['key'];
$cred_id = EtelID_WebAuthn::b64url_encode( random_bytes( 16 ) );
$GLOBALS['__members'][7] = (object) array( 'id' => 7, 'etel_id' => 'EG-2026-00007', 'status' => 'active' );
$GLOBALS['__bio'][7]     = (object) array( 'webauthn_credential_id' => $cred_id, 'webauthn_public_key' => $pem, 'webauthn_sign_count' => 0 );
function make_assertion( $key, $cred_id, $challenge_b64, $flags, $counter, $type = 'webauthn.get' ) {
    $cd   = json_encode( array( 'type' => $type, 'challenge' => $challenge_b64, 'origin' => 'https://ethelmarket.com' ) );
    $auth = hash( 'sha256', 'ethelmarket.com', true ) . chr( $flags ) . pack( 'N', $counter );
    openssl_sign( $auth . hash( 'sha256', $cd, true ), $sig, $key, OPENSSL_ALGO_SHA256 );
    return array( 'id' => $cred_id, 'response' => array(
        'clientDataJSON' => EtelID_WebAuthn::b64url_encode( $cd ), 'authenticatorData' => EtelID_WebAuthn::b64url_encode( $auth ),
        'signature' => EtelID_WebAuthn::b64url_encode( $sig ) ) );
}
set_error_handler( function () { return true; } );   /* create_session sets a cookie; CLI has no headers */

$o = EtelID_WebAuthn::login_options_discoverable();
check( 'passkey-first options list no credentials (device picks) and require verification', $o['allowCredentials'] === array() && $o['userVerification'] === 'required' && strlen( $o['_ses'] ) >= 16 );
check( 'account is found from the credential id', EtelID_WebAuthn::member_id_for_credential( $cred_id ) === 7 );
check( 'oversized/empty credential ids are refused', EtelID_WebAuthn::member_id_for_credential( '' ) === 0 && EtelID_WebAuthn::member_id_for_credential( str_repeat( 'a', 2000 ) ) === 0 );

$r = EtelID_WebAuthn::verify_login( 7, make_assertion( $key, $cred_id, $o['challenge'], 0x05, 0 ), 'dlogin_' . $o['_ses'] );
check( 'valid passkey assertion signs in', is_array( $r ) && $r['member']->id === 7 );

$o = EtelID_WebAuthn::login_options_discoverable();
$r = EtelID_WebAuthn::verify_login( 7, make_assertion( $key, $cred_id, $o['challenge'], 0x01, 0 ), 'dlogin_' . $o['_ses'] );
check( 'no user-verification flag (touch only) is refused', is_wp_error( $r ) && $r->get_error_code() === 'no_verification' );

$o = EtelID_WebAuthn::login_options_discoverable();
$c = make_assertion( $key, $cred_id, $o['challenge'], 0x05, 0 );
EtelID_WebAuthn::verify_login( 7, $c, 'dlogin_' . $o['_ses'] );
$r = EtelID_WebAuthn::verify_login( 7, $c, 'dlogin_' . $o['_ses'] );
check( 'a challenge works only once (replay refused)', is_wp_error( $r ) && $r->get_error_code() === 'bad_challenge' );

$other = openssl_pkey_new( array( 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ) );
$o = EtelID_WebAuthn::login_options_discoverable();
$r = EtelID_WebAuthn::verify_login( 7, make_assertion( $other, $cred_id, $o['challenge'], 0x05, 0 ), 'dlogin_' . $o['_ses'] );
check( 'assertion signed by a different key is refused', is_wp_error( $r ) && $r->get_error_code() === 'bad_signature' );

$o = EtelID_WebAuthn::login_options_discoverable();
$r = EtelID_WebAuthn::verify_login( 7, make_assertion( $key, $cred_id, EtelID_WebAuthn::b64url_encode( 'wrong-challenge-wrong-challenge-12' ), 0x05, 0 ), 'dlogin_' . $o['_ses'] );
check( 'wrong challenge is refused', is_wp_error( $r ) && $r->get_error_code() === 'bad_challenge' );

$GLOBALS['__bio'][7]->webauthn_sign_count = 5;
$o = EtelID_WebAuthn::login_options_discoverable();
$r = EtelID_WebAuthn::verify_login( 7, make_assertion( $key, $cred_id, $o['challenge'], 0x05, 5 ), 'dlogin_' . $o['_ses'] );
check( 'a counter that did not increase (cloned key) is refused', is_wp_error( $r ) && $r->get_error_code() === 'clone_detected' );

/* ---- more than one device per member ---- */
$GLOBALS['__bio'][7]->webauthn_sign_count = 0;
$key2 = openssl_pkey_new( array( 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ) );
$cred2 = EtelID_WebAuthn::b64url_encode( random_bytes( 16 ) );
$wdb->insert( 'wp_etelid_passkeys', array( 'member_id' => 7, 'credential_id' => $cred2, 'public_key' => openssl_pkey_get_details( $key2 )['key'], 'sign_count' => 0, 'label' => 'Work phone', 'created_at' => '2026-01-01 00:00:00' ) );
check( 'device list shows the first device and the added one', count( EtelID_WebAuthn::devices_for_member( 7 ) ) === 2 );
$GLOBALS['__members'][8] = (object) array( 'id' => 8, 'etel_id' => 'EG-2026-00008', 'status' => 'active' );
check( "another member sees none of member 7's devices", EtelID_WebAuthn::devices_for_member( 8 ) === array() );
$o = EtelID_WebAuthn::login_options_discoverable();
$r = EtelID_WebAuthn::verify_login( 7, make_assertion( $key2, $cred2, $o['challenge'], 0x05, 0 ), 'dlogin_' . $o['_ses'] );
check( 'the second device signs in', is_array( $r ) && $r['member']->id === 7 );
check( 'the second device is found from its credential id', EtelID_WebAuthn::member_id_for_credential( $cred2 ) === 7 );
$o = EtelID_WebAuthn::login_options_discoverable();
$r = EtelID_WebAuthn::verify_login( 7, make_assertion( $key, $cred2, $o['challenge'], 0x05, 0 ), 'dlogin_' . $o['_ses'] );
check( "device 1's key cannot sign for device 2's credential id", is_wp_error( $r ) && $r->get_error_code() === 'bad_signature' );
$GLOBALS['__members'][8]->id = 8;
$o = EtelID_WebAuthn::login_options_discoverable();
$r = EtelID_WebAuthn::verify_login( 8, make_assertion( $key2, $cred2, $o['challenge'], 0x05, 0 ), 'dlogin_' . $o['_ses'] );
check( "a credential cannot be used to sign in as a different member", is_wp_error( $r ) && $r->get_error_code() === 'wrong_credential' );
check( 'removing a device that is not yours does nothing', EtelID_WebAuthn::remove_device( 8, $cred2 ) === false && count( EtelID_WebAuthn::devices_for_member( 7 ) ) === 2 );
check( 'removing your own extra device works', EtelID_WebAuthn::remove_device( 7, $cred2 ) === true && count( EtelID_WebAuthn::devices_for_member( 7 ) ) === 1 );
$o = EtelID_WebAuthn::login_options_discoverable();
$r = EtelID_WebAuthn::verify_login( 7, make_assertion( $key2, $cred2, $o['challenge'], 0x05, 1 ), 'dlogin_' . $o['_ses'] );
check( 'a removed device can no longer sign in', is_wp_error( $r ) && $r->get_error_code() === 'wrong_credential' );
check( 'removing the first device clears the old biometric row', EtelID_WebAuthn::remove_device( 7, $cred_id ) === true && $GLOBALS['__bio'][7]->webauthn_credential_id === '' && EtelID_WebAuthn::devices_for_member( 7 ) === array() );
restore_error_handler();

check( 'unknown account and not-enrolled give the same message (no account enumeration)',
    EtelID_WebAuthn::login_options( '+251900000000' )->get_error_message() === EtelID_WebAuthn::NO_DEVICE_MSG );

echo "front-end assets are self-hosted (no third-party CSS/fonts in the critical path)\n";
$root = dirname( __DIR__ );
$bad = array(); $icon_css = file_get_contents( $root . '/public/css/etelid-icons.css' ); $used = array();
foreach ( array_merge( glob( $root . '/templates/*.php' ), glob( $root . '/includes/*.php' ), glob( $root . '/admin/views/*.php' ), glob( $root . '/public/css/*.css' ), glob( $root . '/admin/css/*.css' ), array( $root . '/etel-id.php' ) ) as $f ) {
    $c = file_get_contents( $f );
    if ( basename( $f ) !== 'class-etelid-security.php' /* its CSP allow-list names the hosts on purpose */ && preg_match( '#fonts\.googleapis\.com|fonts\.gstatic\.com|tabler-icons-webfont|cdn\.jsdelivr\.net|unpkg\.com|cdnjs\.cloudflare\.com#', $c ) ) $bad[] = basename( $f );
    if ( substr( $f, -4 ) === '.php' && basename( $f ) !== 'etel-logo-b64.php' && basename( $f ) !== 'etel-coin-b64.php' ) {
        preg_match_all( '/\bti-([a-z0-9]+(?:-[a-z0-9]+)*)/', $c, $m ); foreach ( $m[1] as $n ) $used[ $n ] = 1;
    }
}
check( 'no Google Fonts or script/icon CDN left in templates, includes, admin views or css', ! $bad, implode( ', ', $bad ) );
$missing = array(); foreach ( array_keys( $used ) as $n ) { if ( $n !== 'spin' && strpos( $icon_css, '.ti-' . $n . '{' ) === false ) $missing[] = $n; }
check( 'every ti-* icon used anywhere has a rule in etelid-icons.css', ! $missing, implode( ', ', $missing ) );
foreach ( array( 'syne-latin-wght-normal', 'jetbrains-mono-latin-wght-normal', 'noto-sans-latin-wght-normal', 'cormorant-garamond-latin-wght-normal', 'noto-sans-ethiopic-ethiopic-wght-normal', 'oswald-latin-600-normal' ) as $ff ) {
    if ( ! file_exists( $root . '/public/fonts/' . $ff . '.woff2' ) ) $missing[] = $ff;
}
check( 'all self-hosted font files exist', ! $missing, implode( ', ', $missing ) );

echo "browser security headers and CSP reports\n";
check( 'default CSP mode is report-only (nothing is blocked until you choose enforce)', EtelID_Security::csp_mode() === 'report' );
$pol = EtelID_Security::csp_policy( 'https://x/y' );
check( 'policy forbids plugins, foreign frames, foreign forms and a changed base URL',
    strpos( $pol, "object-src 'none'" ) !== false && strpos( $pol, "frame-ancestors 'self'" ) !== false && strpos( $pol, "form-action 'self'" ) !== false && strpos( $pol, "base-uri 'self'" ) !== false );
check( 'policy allows no Google Fonts or other font/icon hosts', strpos( $pol, 'googleapis' ) === false && strpos( $pol, 'gstatic' ) === false && strpos( $pol, "font-src 'self' data:" ) !== false );
check( 'policy carries the report address', substr( $pol, -18 ) === 'report-uri https://x/y' || strpos( $pol, 'report-uri https://x/y' ) !== false );
$GLOBALS['__log'] = array();
$rep = json_encode( array( 'csp-report' => array( 'effective-directive' => 'script-src-elem', 'blocked-uri' => 'https://evil.example/a.js', 'document-uri' => 'https://ethelmarket.com/EtelId/login/?token=SECRET' ) ) );
check( 'a CSP report is stored once', EtelID_Security::record_csp_report( $rep ) === true && count( $GLOBALS['__log'] ) === 1 );
check( 'the same report again within the hour is ignored (log cannot be flooded)', EtelID_Security::record_csp_report( $rep ) === false && count( $GLOBALS['__log'] ) === 1 );
check( 'the stored report drops the query string of the page address', strpos( $GLOBALS['__log'][0][1], 'SECRET' ) === false && strpos( $GLOBALS['__log'][0][1], 'script-src-elem' ) !== false );
check( 'junk reports are refused', EtelID_Security::record_csp_report( 'not json' ) === false && EtelID_Security::record_csp_report( '{"csp-report":{}}' ) === false );

echo "\n" . ( $fails ? "FAILED: $fails" : 'All EtelID security unit tests passed' ) . "\n";
exit( $fails ? 1 : 0 );
