<?php
/**
 * EtelID: a member cannot change their own phone number, or take another member's email, through the profile form
 * (no WordPress needed).   php EtelId/tests/profile_phone_unit.php
 *
 * The phone number is how Delivery, Ride, Pay and EtelGroup find a person's account, so editing it would let anyone
 * type another person's number and sign in as them in those systems.
 */
define( 'ABSPATH', '/x/' );
class Done extends Exception { public $ok; public $payload; function __construct( $ok, $p ) { parent::__construct( 'done' ); $this->ok = $ok; $this->payload = $p; } }
function check_ajax_referer() { return true; }
function wp_send_json_error( $p = null ) { throw new Done( false, $p ); }
function wp_send_json_success( $p = null ) { throw new Done( true, $p ); }
function wp_unslash( $s ) { return $s; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_email( $s ) { return trim( (string) $s ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function esc_url_raw( $s ) { return (string) $s; }
function is_email( $e ) { return strpos( (string) $e, '@' ) !== false; }
class EtelID_Auth { static $member; static function get_current_member() { return self::$member; }
    static function verify_password( $plain, $hash ) { return $plain === 'right-password' && $hash === 'HASH'; } }
class EtelID_DB { static $saved = array(); static $email_owner = null;
    static function update_member( $id, $d ) { self::$saved = $d; return 1; }
    static function log() {} static function find_by_email( $e ) { return self::$email_owner; } }
class EtelID_Security { static $fails = array();
    static function member_refresh_limited() { return true; } static function member_is_limited() { return false; }
    static function fail_blocked( $k, $max = 8, $w = 900 ) { return ( self::$fails[ $k ] ?? 0 ) >= $max; }
    static function fail_hit( $k, $w = 900 ) { self::$fails[ $k ] = ( self::$fails[ $k ] ?? 0 ) + 1; }
    static function fail_clear( $k ) { unset( self::$fails[ $k ] ); } }
require __DIR__ . '/../includes/class-etelid-auth-ajax.php';

$fails = 0;
function check( $name, $cond ) { global $fails; echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . $name . "\n"; if ( ! $cond ) $fails++; }
function submit( $post ) { $_POST = $post; EtelID_DB::$saved = array();
    try { EtelID_Auth_Ajax::profile_update(); } catch ( Done $d ) { return $d; } return null; }

EtelID_Auth::$member = (object) array( 'id' => 5, 'etel_id' => 'EG-2026-00005', 'phone' => '+251924445206', 'email' => 'me@example.com', 'full_name' => 'Me', 'pin_hash' => 'HASH' );

$r = submit( array( 'full_name' => 'Me', 'phone' => '0911223344' ) );
check( 'a different phone number is refused', $r && ! $r->ok && strpos( $r->payload['message'], 'phone number' ) !== false );
check( '...and nothing is saved', EtelID_DB::$saved === array() );

$r = submit( array( 'full_name' => 'Me', 'phone' => '0924445206', 'region' => 'Sidama' ) );
check( 'the same number in another written form is accepted', $r && $r->ok );
check( '...the phone is not written at all', ! array_key_exists( 'phone', EtelID_DB::$saved ) && ( EtelID_DB::$saved['region'] ?? '' ) === 'Sidama' );

EtelID_DB::$email_owner = (object) array( 'id' => 9 );
$r = submit( array( 'full_name' => 'Me', 'phone' => '+251924445206', 'email' => 'someone@else.com' ) );
check( 'an email that belongs to another account is refused', $r && ! $r->ok && strpos( $r->payload['message'], 'email' ) !== false );
EtelID_DB::$email_owner = (object) array( 'id' => 5 );
$r = submit( array( 'full_name' => 'Me', 'phone' => '+251924445206', 'email' => 'new@mine.com', 'current_password' => 'right-password' ) );
check( 'an email that is already this member\'s own is accepted', $r && $r->ok );
EtelID_DB::$email_owner = null;
$r = submit( array( 'full_name' => 'Me', 'phone' => '+251924445206', 'email' => 'free@example.com', 'current_password' => 'right-password' ) );
check( 'a free email is accepted with the right current password', $r && $r->ok && EtelID_DB::$saved['email'] === 'free@example.com' );

/* Changing the email needs the current password (a stolen session alone must not move the account's reset address). */
$r = submit( array( 'full_name' => 'Me', 'phone' => '+251924445206', 'email' => 'free@example.com' ) );
check( 'a new email without the current password is refused', $r && ! $r->ok && ! empty( $r->payload['need_password'] ) );
check( '...and nothing is saved', EtelID_DB::$saved === array() );
$r = submit( array( 'full_name' => 'Me', 'phone' => '+251924445206', 'email' => 'free@example.com', 'current_password' => 'wrong' ) );
check( 'a new email with a wrong password is refused', $r && ! $r->ok && strpos( $r->payload['message'], 'not correct' ) !== false );
EtelID_Security::$fails = array();
for ( $i = 0; $i < 5; $i++ ) submit( array( 'full_name' => 'Me', 'phone' => '+251924445206', 'email' => 'free@example.com', 'current_password' => 'wrong' ) );
$r = submit( array( 'full_name' => 'Me', 'phone' => '+251924445206', 'email' => 'free@example.com', 'current_password' => 'right-password' ) );
check( 'after 5 wrong passwords even the right one is blocked for a while', $r && ! $r->ok && strpos( $r->payload['message'], 'Too many' ) !== false );
EtelID_Security::$fails = array();
$r = submit( array( 'full_name' => 'Me', 'phone' => '+251924445206', 'region' => 'Sidama' ) );
check( 'changing something other than the email needs no password', $r && $r->ok );
$r = submit( array( 'full_name' => 'Me', 'phone' => '+251924445206', 'email' => 'ME@example.com' ) );
check( 'the same email in another letter case needs no password', $r && $r->ok );

/* Names are shown inside admin pages: no angle brackets. */
$r = submit( array( 'full_name' => 'A <b>x</b> test', 'phone' => '+251924445206' ) );
check( 'a name with angle brackets is refused', $r && ! $r->ok && strpos( $r->payload['message'], 'cannot contain' ) !== false );
$r = submit( array( 'full_name' => "O'Brien \"Q\" test", 'phone' => '+251924445206' ) );
check( 'quotes in a name are fine', $r && $r->ok );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
