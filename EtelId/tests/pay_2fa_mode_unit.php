<?php
/**
 * "Etel Pay asks for my PIN, not the 6-digit code" - the member's own choice (EtelID_2FA::pay_skips_code / set_pay_skip and the service routes
 * authenticate and pay_2fa_mode).
 *
 *   php EtelId/tests/pay_2fa_mode_unit.php
 *
 * Runs the REAL EtelID_Service_API, EtelID_Auth and EtelID_2FA (real TOTP codes) on an in-memory SQLite database (etel_code_harness.php).
 * Only the small helpers around them (rate-limit counters, the log, WordPress functions) are stand-ins.
 */
require __DIR__ . '/etel_code_harness.php';

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
    static function update_member( $id, $data ) {}
}
class WP_REST_Request { private $p; function __construct( $p ) { $this->p = $p; } function get_param( $k ) { return isset( $this->p[ $k ] ) ? $this->p[ $k ] : null; } }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function rest_ensure_response( $x ) { return $x; }
function get_transient( $k ) { return isset( $GLOBALS['__transients'][ $k ] ) ? $GLOBALS['__transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; }
function wp_unslash( $x ) { return $x; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function esc_sql( $s ) { return str_replace( "'", "''", (string) $s ); }
function is_ssl() { return true; }
define( 'DAY_IN_SECONDS', 86400 ); define( 'COOKIEPATH', '/' ); define( 'COOKIE_DOMAIN', '' );
ob_start();

/* the harness $wpdb has no update(): add it, and the tables the real classes read */
class Wpdb2 extends FakeWpdb {
    public function update( $table, $data, $where ) {
        $set = array(); foreach ( $data as $k => $v ) $set[] = "$k = " . ( $v === null ? 'NULL' : $this->pdo->quote( (string) $v ) );
        $c = array(); foreach ( $where as $k => $v ) $c[] = "$k = " . $this->pdo->quote( (string) $v );
        return $this->pdo->exec( "UPDATE $table SET " . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $c ) );
    }
}
$GLOBALS['wpdb'] = new Wpdb2(); $wpdb = $GLOBALS['wpdb'];
$wpdb->pdo->exec( "CREATE TABLE wp_etelid_2fa (member_id INTEGER PRIMARY KEY, secret_enc TEXT NOT NULL, enabled INT NOT NULL DEFAULT 0, backup_codes TEXT NULL, last_step INT NOT NULL DEFAULT 0, created_at TEXT NULL, enabled_at TEXT NULL, pay_skip INT NOT NULL DEFAULT 0)" );
$wpdb->pdo->exec( "CREATE TABLE wp_etelid_companies (id INTEGER PRIMARY KEY AUTOINCREMENT, member_id INT, active INT DEFAULT 1, role_at VARCHAR(40) DEFAULT '')" );
$wpdb->pdo->exec( "CREATE TABLE wp_etelid_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, member_id INT NOT NULL, token VARCHAR(64) NOT NULL, ip VARCHAR(45) NOT NULL DEFAULT '', ua VARCHAR(255) NOT NULL DEFAULT '', created_at TEXT, expires_at TEXT)" );
$GLOBALS['__opts']['etelid_2fa_db_version'] = '1.1';   // the table exists: skip dbDelta

require __DIR__ . '/../includes/class-etelid-code.php';
require __DIR__ . '/../includes/class-etelid-auth.php';
require __DIR__ . '/../includes/class-etelid-2fa.php';
require __DIR__ . '/../includes/class-etelid-service-api.php';

function mk( $name, $phone, $pass ) { return etc_member( $name, $phone, array( 'pin_hash' => password_hash( $pass, PASSWORD_BCRYPT, array( 'cost' => 4 ) ) ) ); }
function reset_limits() { $GLOBALS['__counters'] = array(); $GLOBALS['__transients'] = array(); }
function code_of( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : 'ok'; }
function events( $name ) { $n = 0; foreach ( $GLOBALS['__events'] as $e ) if ( $e[1] === $name ) $n++; return $n; }
function svc_auth( $ident, $pass, $more = array() ) { return EtelID_Service_API::authenticate( new WP_REST_Request( array_merge( array( 'identifier' => $ident, 'password' => $pass, 'app' => 'pay' ), $more ) ) ); }
function svc_mode( $ident, $pass, $mode, $more = array() ) { return EtelID_Service_API::pay_2fa_mode( new WP_REST_Request( array_merge( array( 'identifier' => $ident, 'password' => $pass, 'mode' => $mode, 'app' => 'pay' ), $more ) ) ); }
/* turn two-step ON for a member the real way (begin + enable with a real code) and give back a function that makes a fresh valid code */
function enroll( $id ) {
    global $wpdb; $m = $wpdb->get_row( "SELECT * FROM wp_etelid_members WHERE id = $id" );
    $wpdb->pdo->exec( "DELETE FROM wp_etelid_2fa WHERE member_id = $id" );
    $b = EtelID_2FA::begin( $m ); $sec = EtelID_2FA::b32_decode( $b['secret'] ); $step = (int) floor( time() / 30 );
    EtelID_2FA::enable( $m, EtelID_2FA::code_for_step( $sec, $step ) );
    /* a code works once (replay protection), so before each new one the "last used" step is wound back, as if 30 seconds had passed */
    return function () use ( $sec, $id, $wpdb ) { $wpdb->pdo->exec( "UPDATE wp_etelid_2fa SET last_step = 0 WHERE member_id = $id" ); return EtelID_2FA::code_for_step( $sec, (int) floor( time() / 30 ) ); };
}

/* the server sets who is calling from the service key before any route runs: here the default caller is Etel Pay's own key */
$ref = new ReflectionClass( 'EtelID_Service_API' ); $cp = $ref->getProperty( 'caller' ); $cp->setAccessible( true );
$set_caller = function ( $app ) use ( $cp ) { $cp->setValue( null, array( 'app' => $app, 'scopes' => array( 'authenticate' ) ) ); };
$set_caller( 'pay' );

$dagi  = mk( 'Dagi Test', '0911000001', 'DagiPass#1' );
$abebe = mk( 'Abebe Kebede', '+251922000002', 'AbebePass#2' );
$plain = mk( 'Plain Pat', '0933000003', 'PatPass#3' );
$dagiCode  = enroll( $dagi );
$abebeCode = enroll( $abebe );
$dagiRow = function () use ( $wpdb, $dagi ) { return $wpdb->get_row( "SELECT * FROM wp_etelid_2fa WHERE member_id = $dagi" ); };

/* ================= before anyone chooses: nothing changes ================= */
reset_limits();
$r = svc_auth( '0911000001', 'DagiPass#1' );
check( 'default: Etel Pay asks for the code (requires_2fa)', code_of( $r ) === 'requires_2fa' && $r->data['requires_2fa'] === true, code_of( $r ) );
$r = svc_auth( '0911000001', 'DagiPass#1', array( 'totp_code' => $dagiCode() ) );
check( 'default: with a valid code the Etel Pay sign-in works and says the code was verified', is_array( $r ) && $r['ok'] === true && $r['twofa_verified'] === true && $r['twofa_skipped'] === false, json_encode( $r ) );
check( 'default: nobody has the choice switched on', (int) $dagiRow()->pay_skip === 0 && EtelID_2FA::pay_skips_code( $dagi ) === false && EtelID_2FA::pay_skips_code( $abebe ) === false);
$r = svc_mode( '0911000001', 'DagiPass#1', 'status' );
check( 'status: two-step is on, choice is off', is_array( $r ) && $r['ok'] === true && $r['twofa_enabled'] === true && $r['pay_skip'] === false, json_encode( $r ) );
$r = svc_mode( '0933000003', 'PatPass#3', 'status' );
check( 'status for a member without two-step: nothing to skip', is_array( $r ) && $r['twofa_enabled'] === false && $r['pay_skip'] === false );

/* ================= choosing it ================= */
reset_limits();
$r = svc_mode( '0911000001', 'DagiPass#1', 'skip' );
check( 'choosing "skip" without a code is refused: the code is asked for (requires_2fa)', code_of( $r ) === 'requires_2fa' && $r->data['requires_2fa'] === true && (int) $dagiRow()->pay_skip === 0, code_of( $r ) );
$r = svc_mode( '0911000001', 'DagiPass#1', 'skip', array( 'totp_code' => '000000' ) );
check( 'a wrong code is refused (bad_2fa) and nothing changes', code_of( $r ) === 'bad_2fa' && (int) $dagiRow()->pay_skip === 0, code_of( $r ) );
$r = svc_mode( '0911000001', 'WrongPass', 'skip', array( 'totp_code' => $dagiCode() ) );
check( 'a wrong password is refused even with a valid code', code_of( $r ) === 'wrong_password' && (int) $dagiRow()->pay_skip === 0, code_of( $r ) );
reset_limits();
$r = svc_mode( '0911000001', 'DagiPass#1', 'skip', array( 'totp_code' => $dagiCode() ) );
check( 'password + a valid code: the choice is saved', is_array( $r ) && $r['ok'] === true && $r['pay_skip'] === true && (int) $dagiRow()->pay_skip === 1, json_encode( $r ) );
check( 'and it is written to the audit log', events( 'updated' ) >= 3 );
$old = $dagiCode(); $r = svc_mode( '0911000001', 'DagiPass#1', 'skip', array( 'totp_code' => $old ) );
check( 'saving it again with a fresh code is fine (idempotent)', is_array( $r ) && $r['pay_skip'] === true );

/* ================= the effect on sign-in ================= */
reset_limits();
$r = svc_auth( '0911000001', 'DagiPass#1' );
check( 'Etel Pay: password only, no code asked, the sign-in works and says the code was skipped', is_array( $r ) && $r['ok'] === true && $r['twofa_verified'] === false && $r['twofa_skipped'] === true, json_encode( $r ) );
$r = svc_auth( '0911000001', 'WrongPass' );
check( 'Etel Pay: a wrong password is still refused', code_of( $r ) === 'wrong_password' );
$r = svc_auth( '0911000001', 'DagiPass#1', array( 'app' => 'delivery' ) );
check( 'every OTHER app (Delivery) still asks for the code', code_of( $r ) === 'requires_2fa', code_of( $r ) );
foreach ( array( 'gebeya', 'taxi', 'madeya', '' , 'PAYX', 'pay2' ) as $other ) { $r = svc_auth( '0911000001', 'DagiPass#1', array( 'app' => $other ) ); check( "app '$other' still asks for the code", code_of( $r ) === 'requires_2fa', code_of( $r ) ); }
$r = svc_auth( '0911000001', 'DagiPass#1', array( 'app' => 'Pay' ) );
check( 'the app name "Pay" in any letter case counts as Etel Pay', is_array( $r ) && $r['twofa_skipped'] === true );
$r = svc_auth( '0922000002', 'AbebePass#2' );
check( 'another member who did NOT choose it is still asked for the code', code_of( $r ) === 'requires_2fa', code_of( $r ) );
check( 'the sign-in log says the code was skipped by choice', (function () { foreach ( $GLOBALS['__events'] as $e ) if ( $e[1] === 'login' && strpos( $e[2], 'skipped by the member' ) !== false ) return true; return false; })() );

/* ================= only Etel Pay's key may use / benefit from it ================= */
$set_caller( 'delivery' );
reset_limits();
$r = svc_auth( '0911000001', 'DagiPass#1' );
check( 'a key that belongs to another app (delivery) cannot claim "pay" to skip the code', code_of( $r ) === 'requires_2fa', code_of( $r ) );
$r = svc_mode( '0911000001', 'DagiPass#1', 'status' );
check( 'and cannot read or change the choice (403)', code_of( $r ) === 'forbidden' && $r->data['status'] === 403, code_of( $r ) );
$set_caller( 'pay' ); reset_limits();
$r = svc_auth( '0911000001', 'DagiPass#1' );
check( 'Etel Pay\'s own key: skipped', is_array( $r ) && $r['twofa_skipped'] === true );
$set_caller( 'master-key' ); reset_limits();
$r = svc_auth( '0911000001', 'DagiPass#1' );
check( 'the shared master key (installs that have not split their keys): skipped for "pay"', is_array( $r ) && $r['twofa_skipped'] === true );
$set_caller( '' );
$r = svc_mode( '0911000001', 'DagiPass#1', 'status', array( 'app' => 'delivery' ) );
check( 'a call that does not say it is Etel Pay (app field) is refused', code_of( $r ) === 'forbidden' );
$set_caller( 'pay' );   // from here on the calls come from Etel Pay's own key again

/* ================= going back ================= */
reset_limits();
$r = svc_mode( '0911000001', 'DagiPass#1', 'ask' );
check( 'switching back to "ask" needs only the password', is_array( $r ) && $r['ok'] === true && $r['pay_skip'] === false && (int) $dagiRow()->pay_skip === 0, json_encode( $r ) );
$r = svc_auth( '0911000001', 'DagiPass#1' );
check( 'the code is asked again at once', code_of( $r ) === 'requires_2fa' );
$r = svc_mode( '0911000001', 'WrongPass', 'ask' );
check( 'but not by someone who does not know the password', code_of( $r ) === 'wrong_password' );

/* ================= edge cases ================= */
reset_limits();
$r = svc_mode( '0933000003', 'PatPass#3', 'skip' );
check( 'a member without two-step: "skip" does nothing (no code to skip), no error', is_array( $r ) && $r['ok'] === true && $r['twofa_enabled'] === false && $r['pay_skip'] === false );
$r = svc_mode( '0911000001', 'DagiPass#1', 'bogus' );
check( 'an unknown mode is a 400', code_of( $r ) === 'bad_request' );
$r = svc_mode( '', 'x', 'status' );
check( 'identifier and password are required (400)', code_of( $r ) === 'bad_request' );
$r = svc_mode( '0999999999', 'x', 'status' );
check( 'an unknown member is a 404', code_of( $r ) === 'not_found' );

/* turning two-step OFF removes the choice with it; turning it on again starts with the choice off */
$m = $wpdb->get_row( "SELECT * FROM wp_etelid_members WHERE id = $dagi" );
svc_mode( '0911000001', 'DagiPass#1', 'skip', array( 'totp_code' => $dagiCode() ) );
EtelID_2FA::disable( $m, $dagiCode() );
check( 'two-step switched off: the row (and the choice) is gone', $dagiRow() === null && EtelID_2FA::pay_skips_code( $dagi ) === false );
$again = enroll( $dagi );
check( 'switched on again: the choice starts OFF (asked for the code)', (int) $dagiRow()->pay_skip === 0 && code_of( svc_auth( '0911000001', 'DagiPass#1' ) ) === 'requires_2fa' );

/* the owner's kill switch */
reset_limits();
svc_mode( '0911000001', 'DagiPass#1', 'skip', array( 'totp_code' => $again() ) );
check( 'before the kill switch: skipped', ( svc_auth( '0911000001', 'DagiPass#1' )['twofa_skipped'] ?? false ) === true );
define( 'ETELID_PAY_CODE_CHOICE', false );
check( 'kill switch (ETELID_PAY_CODE_CHOICE = false): everyone is asked the code again, even if they chose "skip"', code_of( svc_auth( '0911000001', 'DagiPass#1' ) ) === 'requires_2fa' );
$r = svc_mode( '0911000001', 'DagiPass#1', 'skip', array( 'totp_code' => $again() ) );
check( 'and nobody can choose "skip" while it is off (403)', code_of( $r ) === 'pay_choice_off' && $r->data['status'] === 403, code_of( $r ) );

/* wrong-password guessing through this route is limited like a sign-in */
reset_limits();
$last = null; for ( $i = 0; $i < 10; $i++ ) $last = svc_mode( '0922000002', 'bad' . $i, 'status' );
check( 'guessing the password through pay_2fa_mode is locked out after 8 tries', code_of( $last ) === 'rate_limited', code_of( $last ) );

etc_done();
