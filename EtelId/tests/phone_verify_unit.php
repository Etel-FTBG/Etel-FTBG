<?php
/**
 * EtelID: phone number verification (no WordPress needed).   php EtelId/tests/phone_verify_unit.php
 *
 * A number is only "verified" after a code that was sent to that number is entered; the code is hashed, expires, allows
 * a few wrong tries, is tied to the number it was sent to, and can only go by SMS or WhatsApp (not Telegram or email,
 * which prove nothing about the phone).
 */
define( 'ABSPATH', '/x/' );
class Done extends Exception { public $ok; public $payload; function __construct( $ok, $p ) { parent::__construct( 'done' ); $this->ok = $ok; $this->payload = $p; } }
$GLOBALS['opts'] = array(); $GLOBALS['tr'] = array(); $GLOBALS['sent'] = array(); $GLOBALS['limit_ok'] = true;
function add_action() {}
function check_ajax_referer() { return true; }
function wp_send_json_error( $p = null ) { throw new Done( false, $p ); }
function wp_send_json_success( $p = null ) { throw new Done( true, $p ); }
function wp_unslash( $s ) { return $s; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['tr'] ) ? $GLOBALS['tr'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tr'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['tr'][ $k ] ); return true; }
function wp_rand( $a, $b ) { return 123456; }
function current_time() { return '2026-10-21 12:00:00'; }
class EtelID_Auth { static $member; static function get_current_member() { return self::$member; } }
class EtelID_Security { static function rate_limit() { return $GLOBALS['limit_ok']; } static function get_ip() { return '1.2.3.4'; } }
class EtelID_DB { static $saved = array(); static $log = array();
    static function update_member( $id, $d ) { self::$saved[] = array( $id, $d ); return 1; }
    static function log( $id, $etel, $type, $msg = '' ) { self::$log[] = $type; } }
class EtelID_Password_Reset {
    static $ok = true;
    static function send_via_sms( $m, $code, $what = '', $min = 0 ) { $GLOBALS['sent'][] = array( 'sms', $code, $what, $min ); return self::$ok; }
    static function send_via_whatsapp( $m, $code ) { $GLOBALS['sent'][] = array( 'whatsapp', $code ); return self::$ok; }
}
require __DIR__ . '/../includes/class-etelid-phone-verify.php';

$fails = 0;
function check( $name, $cond ) { global $fails; echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . $name . "\n"; if ( ! $cond ) $fails++; }
function run( $fn, $post ) { $_POST = $post; try { EtelID_Phone_Verify::$fn(); } catch ( Done $d ) { return $d; } return null; }
function reset_state() { $GLOBALS['tr'] = array(); $GLOBALS['sent'] = array(); EtelID_DB::$saved = array(); EtelID_DB::$log = array(); $GLOBALS['limit_ok'] = true; EtelID_Password_Reset::$ok = true; }
$m = function ( $over = array() ) { return (object) array_merge( array( 'id' => 7, 'etel_id' => 'EG-7', 'phone' => '+251911223344', 'phone_verified_at' => null ), $over ); };

echo "State\n";
check( 'a member with no date is not verified', ! EtelID_Phone_Verify::is_verified( $m() ) );
check( 'a real date is verified', EtelID_Phone_Verify::is_verified( $m( array( 'phone_verified_at' => '2026-01-01 10:00:00' ) ) ) );
check( 'a zero date is not verified', ! EtelID_Phone_Verify::is_verified( $m( array( 'phone_verified_at' => '0000-00-00 00:00:00' ) ) ) );
putenv( 'ETELID_AT_API_KEY' ); putenv( 'ETELID_WA_ACCESS_TOKEN' ); putenv( 'ETELID_WA_PHONE_ID' );
check( 'with nothing configured there is no way to send a code', EtelID_Phone_Verify::channels() === array() );
check( 'auto mode is off until a way to send is configured', ! EtelID_Phone_Verify::enforced() && EtelID_Phone_Verify::trusted( $m() ) );
putenv( 'ETELID_AT_API_KEY=abc' );
check( 'an SMS key makes SMS available', EtelID_Phone_Verify::channels() === array( 'sms' ) );
check( 'auto mode turns on once a way to send exists', EtelID_Phone_Verify::enforced() );
check( '...an unverified member is then not trusted', ! EtelID_Phone_Verify::trusted( $m() ) );
check( '...a verified member is', EtelID_Phone_Verify::trusted( $m( array( 'phone_verified_at' => '2026-01-01 10:00:00' ) ) ) );
putenv( 'ETELID_WA_ACCESS_TOKEN=t' );
check( 'WhatsApp needs both a token and a phone id', EtelID_Phone_Verify::channels() === array( 'sms' ) );
putenv( 'ETELID_WA_PHONE_ID=p' );
check( '...and is listed when it has both', EtelID_Phone_Verify::channels() === array( 'sms', 'whatsapp' ) );
$GLOBALS['opts']['etelid_phone_verify_mode'] = 'off';
check( 'mode off never requires it', ! EtelID_Phone_Verify::enforced() );
$GLOBALS['opts']['etelid_phone_verify_mode'] = 'on'; putenv( 'ETELID_AT_API_KEY' ); putenv( 'ETELID_WA_ACCESS_TOKEN' );
check( 'mode on requires it even with no way to send', EtelID_Phone_Verify::enforced() );
$GLOBALS['opts']['etelid_phone_verify_mode'] = 'bogus';
check( 'an unknown mode falls back to auto', EtelID_Phone_Verify::mode() === 'auto' );
$GLOBALS['opts']['etelid_phone_verify_mode'] = 'auto';
check( 'the number is masked (only the first 2 and last 2 digits show)', strpos( EtelID_Phone_Verify::mask( '+251911223344' ), '911223' ) === false && substr( EtelID_Phone_Verify::mask( '+251911223344' ), -2 ) === '44' );

echo "Sending a code\n";
putenv( 'ETELID_AT_API_KEY=abc' ); reset_state(); EtelID_Auth::$member = $m();
$r = run( 'send', array( 'channel' => 'sms' ) );
check( 'a code goes out by SMS', $r && $r->ok && count( $GLOBALS['sent'] ) === 1 && $GLOBALS['sent'][0][0] === 'sms' && $GLOBALS['sent'][0][1] === '123456' );
check( '...the text says it is a phone verification code that lasts 10 minutes', $GLOBALS['sent'][0][2] === 'phone verification code' && $GLOBALS['sent'][0][3] === 10 );
check( '...the code is stored hashed, not in the clear', is_array( get_transient( 'etelid_pv_7' ) ) && get_transient( 'etelid_pv_7' )['hash'] !== '123456' && password_verify( '123456', get_transient( 'etelid_pv_7' )['hash'] ) );
check( '...the answer does not contain the code', strpos( json_encode( $r->payload ), '123456' ) === false );
$r = run( 'send', array( 'channel' => 'sms' ) );
check( 'a second code within a minute is refused', $r && ! $r->ok && count( $GLOBALS['sent'] ) === 1 );
reset_state();
$r = run( 'send', array( 'channel' => 'telegram' ) );
check( 'Telegram is not a way to prove a phone number', $r && ! $r->ok && $GLOBALS['sent'] === array() );
$r = run( 'send', array( 'channel' => 'whatsapp' ) );
check( 'a channel that is not configured is refused', $r && ! $r->ok && $GLOBALS['sent'] === array() );
reset_state(); $GLOBALS['limit_ok'] = false;
$r = run( 'send', array( 'channel' => 'sms' ) );
check( 'the hourly limit stops it', $r && ! $r->ok && $GLOBALS['sent'] === array() );
reset_state(); EtelID_Password_Reset::$ok = false;
$r = run( 'send', array( 'channel' => 'sms' ) );
check( 'when the SMS provider fails the member is told, and no code is kept', $r && ! $r->ok && get_transient( 'etelid_pv_7' ) === false );
reset_state(); EtelID_Auth::$member = $m( array( 'phone_verified_at' => '2026-01-01 10:00:00' ) );
$r = run( 'send', array( 'channel' => 'sms' ) );
check( 'an already verified member is told so and nothing is sent', $r && $r->ok && ! empty( $r->payload['already'] ) && $GLOBALS['sent'] === array() );
reset_state(); EtelID_Auth::$member = null;
$r = run( 'send', array( 'channel' => 'sms' ) );
check( 'nobody signed in, nothing sent', $r && ! $r->ok && $GLOBALS['sent'] === array() );
putenv( 'ETELID_AT_API_KEY' ); reset_state(); EtelID_Auth::$member = $m();
$r = run( 'send', array( 'channel' => 'sms' ) );
check( 'with no way to send configured the member is sent to support', $r && ! $r->ok && strpos( $r->payload['message'], 'support' ) !== false );

echo "Checking the code\n";
putenv( 'ETELID_AT_API_KEY=abc' ); reset_state(); EtelID_Auth::$member = $m();
run( 'send', array( 'channel' => 'sms' ) );
$r = run( 'check', array( 'code' => '000000' ) );
check( 'a wrong code is refused and counted', $r && ! $r->ok && get_transient( 'etelid_pv_7' )['tries'] === 1 && EtelID_DB::$saved === array() );
$r = run( 'check', array( 'code' => '12345' ) );
check( 'a short code is refused', $r && ! $r->ok && EtelID_DB::$saved === array() );
$r = run( 'check', array( 'code' => '123456' ) );
check( 'the right code verifies the number', $r && $r->ok && count( EtelID_DB::$saved ) === 1 && EtelID_DB::$saved[0][0] === 7 && isset( EtelID_DB::$saved[0][1]['phone_verified_at'] ) );
check( '...and is used up', get_transient( 'etelid_pv_7' ) === false && in_array( 'phone_verified', EtelID_DB::$log, true ) );
reset_state(); run( 'send', array( 'channel' => 'sms' ) );
for ( $i = 0; $i < 5; $i++ ) run( 'check', array( 'code' => '999999' ) );
$r = run( 'check', array( 'code' => '123456' ) );
check( 'after five wrong tries even the right code no longer works', $r && ! $r->ok && EtelID_DB::$saved === array() && get_transient( 'etelid_pv_7' ) === false );
reset_state(); run( 'send', array( 'channel' => 'sms' ) ); EtelID_Auth::$member = $m( array( 'phone' => '0900000000' ) );
$r = run( 'check', array( 'code' => '123456' ) );
check( 'a code sent to one number cannot verify another', $r && ! $r->ok && EtelID_DB::$saved === array() );
reset_state(); EtelID_Auth::$member = $m();
$r = run( 'check', array( 'code' => '123456' ) );
check( 'no code asked for, nothing to check', $r && ! $r->ok && EtelID_DB::$saved === array() );

echo "Wiring (the checks that keep the rule from being bypassed)\n";
$svc = file_get_contents( __DIR__ . '/../includes/class-etelid-service-api.php' );
$gate = strpos( $svc, "! EtelID_Phone_Verify::trusted( \$m )" );
check( 'the Service API sign-in refuses an unverified member', false !== $gate && false !== strpos( $svc, "'phone_unverified'" ) );
check( '...after the password and two-step checks, before the app check', false !== $gate && $gate > strpos( $svc, 'EtelID_2FA::gate' ) && $gate < strpos( $svc, '! self::app_allowed_for( $m, $app )' ) );
check( 'the member payload tells the apps phone_verified and phone_trusted', false !== strpos( $svc, "'phone_trusted'" ) && false !== strpos( $svc, "'phone_verified'" ) );
$db = file_get_contents( __DIR__ . '/../includes/class-etelid-db.php' );
check( 'members that exist when the column is added are grandfathered as verified', false !== strpos( $db, 'ADD COLUMN phone_verified_at' ) && false !== strpos( $db, 'SET phone_verified_at = NOW() WHERE phone_verified_at IS NULL' ) );
check( 'staff-issued members are verified at creation (wp-admin Issue and Fayda quick-register)',
    false !== strpos( file_get_contents( __DIR__ . '/../admin/class-etelid-admin.php' ), "mark_verified( \$new_member, 'issued by staff' )" )
    && false !== strpos( file_get_contents( __DIR__ . '/../includes/class-etelid-fayda.php' ), "mark_verified( \$member, 'issued by staff' )" ) );
check( 'self-service sign-up leaves the number unverified (no mark_verified in the public paths)',
    false === strpos( file_get_contents( __DIR__ . '/../includes/class-etelid-auth-ajax.php' ), 'mark_verified' )
    && false === strpos( file_get_contents( __DIR__ . '/../includes/class-etelid-auth.php' ), 'mark_verified' )
    && false === strpos( file_get_contents( __DIR__ . '/../includes/class-etelid-ajax.php' ), 'mark_verified' ) );
check( 'the code can only go by SMS or WhatsApp', false === stripos( file_get_contents( __DIR__ . '/../includes/class-etelid-phone-verify.php' ), 'send_via_telegram' ) );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
