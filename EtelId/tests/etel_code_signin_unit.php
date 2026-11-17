<?php
/**
 * Etel Code: who is this? (service/resolve) and sign in with a code (service/authenticate and the EtelID portal login).
 *
 *   php EtelId/tests/etel_code_signin_unit.php
 *
 * Runs the REAL EtelID_Service_API (includes/class-etelid-service-api.php), the REAL EtelID_Auth (login, password check, session) and the REAL
 * Etelid_Code on an in-memory SQLite database (etel_code_harness.php). Only the small helpers around them (rate-limit counters, the log,
 * WordPress functions) are stand-ins. Every app that asks EtelID "who is this?" goes through service/resolve, so one check covers them all.
 */
require __DIR__ . '/etel_code_harness.php';

/* ---- stand-ins for what the two classes lean on ---- */
$GLOBALS['__counters'] = array(); $GLOBALS['__transients'] = array(); $GLOBALS['__events'] = array();
class EtelID_Security {
    static function get_ip() { return '10.0.0.9'; }
    static function rate_limit( $k, $max, $win ) { $c = &$GLOBALS['__counters']; $c[ 'rl_' . $k ] = ( isset( $c[ 'rl_' . $k ] ) ? $c[ 'rl_' . $k ] : 0 ) + 1; return $c[ 'rl_' . $k ] <= $max; }
    static function fail_blocked( $k, $max, $win ) { return ( isset( $GLOBALS['__counters'][ 'f_' . $k ] ) ? $GLOBALS['__counters'][ 'f_' . $k ] : 0 ) >= $max; }
    static function fail_hit( $k, $win ) { $GLOBALS['__counters'][ 'f_' . $k ] = ( isset( $GLOBALS['__counters'][ 'f_' . $k ] ) ? $GLOBALS['__counters'][ 'f_' . $k ] : 0 ) + 1; }
    static function fail_clear( $k ) { unset( $GLOBALS['__counters'][ 'f_' . $k ] ); }
    static function member_is_limited( $m ) { return false; }
    static function member_missing_items( $m ) { return array(); }
}
class EtelID_DB {
    static function t_members() { return 'wp_etelid_members'; }
    static function t_companies() { return 'wp_etelid_companies'; }
    static function get_by_etel_id( $id ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM wp_etelid_members WHERE etel_id = %s', $id ) ); }
    static function find_by_phone( $p ) {
        global $wpdb; $d = preg_replace( '/[^0-9]/', '', $p ); if ( strlen( $d ) < 9 ) return null;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM wp_etelid_members WHERE RIGHT(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''), 9) = %s LIMIT 1", substr( $d, -9 ) ) );
    }
    static function log( $mid, $eid, $event, $detail = '' ) { $GLOBALS['__events'][] = array( (int) $mid, $event, $detail ); }
    static function log_kyc_access( $mid, $why ) {}
    static function update_member( $id, $data ) { global $wpdb; foreach ( $data as $k => $v ) $wpdb->query( "UPDATE wp_etelid_members SET $k = " . $wpdb->pdo->quote( $v ) . " WHERE id = " . (int) $id ); }
}
class WP_REST_Request { private $p; function __construct( $p ) { $this->p = $p; } function get_param( $k ) { return isset( $this->p[ $k ] ) ? $this->p[ $k ] : null; } }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function rest_ensure_response( $x ) { return $x; }
function get_transient( $k ) { return isset( $GLOBALS['__transients'][ $k ] ) ? $GLOBALS['__transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; }
function wp_unslash( $x ) { return $x; }
function is_ssl() { return true; }
define( 'COOKIEPATH', '/' ); define( 'COOKIE_DOMAIN', '' );
ob_start();   // setcookie() in a command-line run would complain that output has started

$wpdb->pdo->exec( "CREATE TABLE wp_etelid_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, member_id INT NOT NULL, token VARCHAR(64) NOT NULL, ip VARCHAR(45) NOT NULL DEFAULT '', ua VARCHAR(255) NOT NULL DEFAULT '', created_at DATETIME NULL, expires_at DATETIME NOT NULL, UNIQUE (token))" );

require __DIR__ . '/../includes/class-etelid-code.php';
require __DIR__ . '/../includes/class-etelid-auth.php';
require __DIR__ . '/../includes/class-etelid-service-api.php';

function mk( $name, $phone, $pass, $extra = array() ) { return etc_member( $name, $phone, array_merge( array( 'pin_hash' => $pass === null ? '' : password_hash( $pass, PASSWORD_BCRYPT, array( 'cost' => 4 ) ) ), $extra ) ); }
function reset_limits() { $GLOBALS['__counters'] = array(); $GLOBALS['__transients'] = array(); }
function svc_resolve( $ident ) { return EtelID_Service_API::resolve( new WP_REST_Request( array( 'identifier' => $ident ) ) ); }
function svc_auth( $ident, $pass, $more = array() ) { return EtelID_Service_API::authenticate( new WP_REST_Request( array_merge( array( 'identifier' => $ident, 'password' => $pass, 'app' => 'delivery' ), $more ) ) ); }
function code_of( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : 'ok'; }
function events( $name ) { $n = 0; foreach ( $GLOBALS['__events'] as $e ) if ( $e[1] === $name ) $n++; return $n; }

$dagi  = mk( 'Dagi Test', '0911000001', 'DagiPass#1' );
$abebe = mk( 'Abebe Kebede', '+251922000002', 'AbebePass#2' );
$sus   = mk( 'Suspended Sam', '0933000003', 'SamPass#3', array( 'status' => 'suspended' ) );
$rev   = mk( 'Revoked Rita', '0944000004', 'RitaPass#4', array( 'status' => 'revoked' ) );
$nopw  = mk( 'No Password Nia', '0955000005', null );
foreach ( array( array( $dagi, 'E1616' ), array( $abebe, 'EABEBE' ), array( $sus, 'ESAM12' ), array( $rev, 'ERITA1' ), array( $nopw, 'ENIA12' ) ) as $p ) {
    $wpdb->pdo->exec( "INSERT INTO wp_etelid_codes (member_id, code_norm, status, created_at) VALUES ({$p[0]}, '{$p[1]}', 'active', '" . gmdate( 'Y-m-d H:i:s' ) . "')" );
}
$dagiId = $wpdb->get_row( "SELECT etel_id FROM wp_etelid_members WHERE id = $dagi" )->etel_id;
$wpdb->pdo->exec( "INSERT INTO wp_etelid_codes (member_id, code_norm, status, created_at, released_at) VALUES ($abebe, 'EOLD99', 'released', '" . gmdate( 'Y-m-d H:i:s' ) . "', '" . gmdate( 'Y-m-d H:i:s' ) . "')" );

/* ================= service/resolve: "who is this?" ================= */
$r = svc_resolve( 'E1616' );
check( 'resolve by Etel Code returns the member', is_array( $r ) && $r['member']['full_name'] === 'Dagi Test' && $r['member']['etel_id'] === $dagiId );
$r = svc_resolve( ' e-1616 ' );
check( 'resolve: lower case, dash and spaces are fine', is_array( $r ) && $r['member']['full_name'] === 'Dagi Test' );
$r = svc_resolve( 'e 1616' );
check( 'resolve: a space inside the code is fine', is_array( $r ) && $r['member']['full_name'] === 'Dagi Test' );
$r = svc_resolve( $dagiId );
check( 'resolve by long Etel ID returns the same member', is_array( $r ) && $r['member']['full_name'] === 'Dagi Test' );
$r = svc_resolve( 'https://ethelmarket.com/EtelId/verify/' . $dagiId );
check( 'resolve by the QR verify link returns the same member', is_array( $r ) && $r['member']['full_name'] === 'Dagi Test' );
foreach ( array( '0911000001', '+251911000001', '251 911 000 001', '911-000-001' ) as $ph ) { $r = svc_resolve( $ph ); check( "resolve by phone $ph returns the same member", is_array( $r ) && $r['member']['full_name'] === 'Dagi Test' ); }
$r = svc_resolve( 'ENOBODY' );
check( 'resolve: an unknown code is 404 not_found', code_of( $r ) === 'not_found' && $r->data['status'] === 404 );
$r = svc_resolve( 'EOLD99' );
check( 'resolve: a RELEASED code is not found', code_of( $r ) === 'not_found' );
$r = svc_resolve( '' );
check( 'resolve: an empty identifier is 400', code_of( $r ) === 'bad_request' );
$r = svc_resolve( 'E1616' );
check( 'resolve never returns the password hash', is_array( $r ) && strpos( json_encode( $r ), 'pin_hash' ) === false && strpos( json_encode( $r ), '$2y$' ) === false );
check( 'resolve is written to the audit log', events( 'service_resolve' ) >= 5 );

/* ================= service/authenticate: sign in with a code ================= */
reset_limits();
$r = svc_auth( 'E1616', 'DagiPass#1' );
check( 'sign in by Etel Code + password works', is_array( $r ) && $r['ok'] === true && $r['member']['etel_id'] === $dagiId );
$r = svc_auth( 'e1616', 'DagiPass#1' );
check( 'sign in by lower-case code works', is_array( $r ) && $r['ok'] === true );
$r = svc_auth( '0911000001', 'DagiPass#1' );
check( 'the same account signs in by phone', is_array( $r ) && $r['member']['etel_id'] === $dagiId );
$r = svc_auth( $dagiId, 'DagiPass#1' );
check( 'the same account signs in by long Etel ID', is_array( $r ) && $r['member']['etel_id'] === $dagiId );
$r = svc_auth( 'E1616', 'DagiPass#1', array( 'create_session' => 'true' ) );
check( 'a session token is returned when asked (create_session)', is_array( $r ) && !empty( $r['session_token'] ) && strlen( $r['session_token'] ) >= 32 );
check( 'and only a hash of it is stored in the database', (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_etelid_sessions WHERE member_id = $dagi AND token = " . $wpdb->pdo->quote( $r['session_token'] ) ) === 0
    && (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_etelid_sessions WHERE member_id = $dagi" ) >= 1 );
$r = svc_auth( 'E1616', 'DagiPass#1' );
check( 'no session token unless asked', is_array( $r ) && !isset( $r['session_token'] ) );
check( 'sign-in is written to the audit log', events( 'login' ) >= 5 );

reset_limits();
$r = svc_auth( 'E1616', 'wrong' );
check( 'wrong password by code: 401 wrong_password', code_of( $r ) === 'wrong_password' && $r->data['status'] === 401 );
$r = svc_auth( 'ENOBODY', 'whatever' );
check( 'unknown code: 404 not_found', code_of( $r ) === 'not_found' );
$r = svc_auth( 'EOLD99', 'AbebePass#2' );
check( 'a released (old) code cannot sign in even with the right password', code_of( $r ) === 'not_found' );
$r = svc_auth( 'E1616', '' );
check( 'empty password: 400', code_of( $r ) === 'bad_request' );
$r = svc_auth( '', 'x' );
check( 'empty identifier: 400', code_of( $r ) === 'bad_request' );
$r = svc_auth( 'ESAM12', 'SamPass#3' );
check( 'a suspended account cannot sign in by code (403 suspended)', code_of( $r ) === 'suspended' );
$r = svc_auth( 'ERITA1', 'RitaPass#4' );
check( 'a revoked account cannot sign in by code (403 revoked)', code_of( $r ) === 'revoked' );
$r = svc_auth( 'ENIA12', 'anything' );
check( 'an account with no password cannot sign in by code (403 no_password)', code_of( $r ) === 'no_password' );

/* one lock-out counter per ACCOUNT, whichever way the person typed who they are */
reset_limits();
for ( $i = 0; $i < 8; $i++ ) svc_auth( 'EABEBE', 'bad' . $i );
$r = svc_auth( '0922000002', 'AbebePass#2' );
check( '8 wrong passwords by CODE lock the account for the phone door too (429)', code_of( $r ) === 'rate_limited' && $r->data['status'] === 429 );
$r = svc_auth( 'EABEBE', 'AbebePass#2' );
check( 'and the code door is locked as well, even with the right password', code_of( $r ) === 'rate_limited' );
reset_limits();
$r = svc_auth( 'EABEBE', 'AbebePass#2' );
check( 'after the window passes, the right password works again', is_array( $r ) && $r['ok'] === true );
reset_limits();
for ( $i = 0; $i < 7; $i++ ) svc_auth( 'E1616', 'bad' . $i );
svc_auth( 'E1616', 'DagiPass#1' );
for ( $i = 0; $i < 7; $i++ ) svc_auth( 'E1616', 'bad' . $i );
check( 'a correct sign-in clears the wrong-password count (7 + ok + 7 does not lock)', code_of( svc_auth( 'E1616', 'DagiPass#1' ) ) === 'ok' );
reset_limits();
for ( $i = 0; $i < EtelID_Service_API::RATE_LIMIT; $i++ ) svc_auth( 'E1616', 'DagiPass#1' );
check( 'the per-IP limit still applies to code sign-in', code_of( svc_auth( 'E1616', 'DagiPass#1' ) ) === 'rate_limited' );

/* ================= the EtelID portal login (phone/code + password) ================= */
reset_limits();
$r = EtelID_Auth::login( 'E1616', 'DagiPass#1' );
check( 'portal login by Etel Code works and returns a member + session token', is_array( $r ) && $r['member']->etel_id === $dagiId && !empty( $r['token'] ) );
$r = EtelID_Auth::login( 'e-1616', 'DagiPass#1' );
check( 'portal login: lower case with a dash works', is_array( $r ) && $r['member']->etel_id === $dagiId );
$r = EtelID_Auth::login( '0911000001', 'DagiPass#1' );
check( 'portal login by phone still works', is_array( $r ) && $r['member']->etel_id === $dagiId );
$r = EtelID_Auth::login( 'E1616', 'nope' );
check( 'portal login: wrong password by code is refused', code_of( $r ) === 'wrong_password' );
$r = EtelID_Auth::login( 'ENOBODY', 'x' );
check( 'portal login: unknown code says "phone number or Etel Code" not found', code_of( $r ) === 'not_found' && strpos( $r->get_error_message(), 'Etel Code' ) !== false );
$r = EtelID_Auth::login( 'EOLD99', 'AbebePass#2' );
check( 'portal login: a released code does not sign anyone in', code_of( $r ) === 'not_found' );
$r = EtelID_Auth::login( 'ESAM12', 'SamPass#3' );
check( 'portal login: a suspended account is refused by code', code_of( $r ) === 'suspended' );
reset_limits();
$last = null; for ( $i = 0; $i < 10; $i++ ) $last = EtelID_Auth::login( 'EABEBE', 'bad' . $i );
check( 'portal login: guessing passwords for one code is rate limited', code_of( $last ) === 'rate_limited' );

etc_done();
