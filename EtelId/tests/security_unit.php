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
class EtelID_DB { static function log( $mid, $eid, $ev, $detail = '' ) { $GLOBALS['__log'][] = array( $ev, $detail ); } }
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
check( 'cap: selects sessions beyond the newest 14 and deletes them', strpos( $all, 'OFFSET 14' ) !== false && strpos( $all, 'DELETE FROM wp_etelid_sessions WHERE id IN (3,2)' ) !== false, $all );

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

echo "\n" . ( $fails ? "FAILED: $fails" : 'All EtelID security unit tests passed' ) . "\n";
exit( $fails ? 1 : 0 );
