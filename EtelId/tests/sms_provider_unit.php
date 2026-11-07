<?php
/**
 * EtelID: SMS through SMSEthiopia (no WordPress needed).   php EtelId/tests/sms_provider_unit.php
 *
 * Starts a tiny local web server that behaves like the SMSEthiopia send endpoint (200 {"success":true} for key GOODKEY,
 * 400 with an error_message for a number that is not whitelisted, 401 otherwise) and checks that the reset-code SMS
 * goes there first with the key in the KEY header and the number in 251 form, that a refusal is reported as not sent,
 * and that the SMS choice on the reset page and in phone verification is offered as soon as the key is set.
 */
define( 'ABSPATH', '/x/' );
$FAILS = array(); $PASS = 0;
function check( $name, $cond, $extra = '' ) { global $FAILS, $PASS; if ( $cond ) { $PASS++; } else { $FAILS[] = $name . ( $extra !== '' ? ' -> ' . $extra : '' ); } }

/* --- a mock of the SMSEthiopia endpoint on a free local port --- */
$dir = sys_get_temp_dir() . '/etelid_sms_mock_' . getmypid(); @mkdir( $dir );
$log = $dir . '/log.txt';
file_put_contents( $dir . '/index.php', '<?php
$b = json_decode( file_get_contents( "php://input" ), true );
file_put_contents( ' . var_export( $log, true ) . ', json_encode( array( "key" => isset( $_SERVER["HTTP_KEY"] ) ? $_SERVER["HTTP_KEY"] : "", "body" => $b ) ) . "\n", FILE_APPEND );
header( "Content-Type: application/json" );
if ( ! isset( $_SERVER["HTTP_KEY"] ) || $_SERVER["HTTP_KEY"] !== "GOODKEY" ) { http_response_code( 401 ); echo json_encode( array( "error" => "unauthorized" ) ); exit; }
if ( isset( $b["msisdn"] ) && $b["msisdn"] === "251900000000" ) { http_response_code( 400 ); echo json_encode( array( "error_message" => "DEFAULT_CAMPAIGN_RECIPIENT_NOT_WHITELISTED" ) ); exit; }
echo json_encode( array( "success" => true ) );' );
$port = 18000 + ( getmypid() % 1000 );
$proc = proc_open( 'php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg( $dir ), array( 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ), $pipes );
for ( $i = 0; $i < 30; $i++ ) { $s = @fsockopen( '127.0.0.1', $port, $e, $m, 0.2 ); if ( $s ) { fclose( $s ); break; } usleep( 100000 ); }

/* --- WordPress HTTP stand-ins (real HTTP to the mock) --- */
function add_action() {}
function add_filter() {}
function get_option( $k, $d = false ) { return $d; }
function get_transient() { return false; }
class WP_Error {}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_remote_post( $url, $args ) {
    $h = array(); foreach ( $args['headers'] as $k => $v ) $h[] = $k . ': ' . $v;
    $ctx = stream_context_create( array( 'http' => array( 'method' => 'POST', 'header' => implode( "\r\n", $h ), 'content' => is_array( $args['body'] ) ? http_build_query( $args['body'] ) : $args['body'], 'ignore_errors' => true, 'timeout' => 5 ) ) );
    $body = @file_get_contents( $url, false, $ctx );
    if ( $body === false ) return new WP_Error();
    preg_match( '#HTTP/\S+ (\d+)#', isset( $http_response_header[0] ) ? $http_response_header[0] : '', $m );
    return array( 'code' => isset( $m[1] ) ? (int) $m[1] : 0, 'body' => $body );
}
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }

define( 'ETELID_SMSET_API_URL', 'http://127.0.0.1:' . $port . '/api/sms/send' );
require_once __DIR__ . '/../includes/class-etelid-password-reset.php';
require_once __DIR__ . '/../includes/class-etelid-phone-verify.php';

check( 'no key set: the SMS choice is not offered', ! in_array( 'sms', EtelID_Phone_Verify::channels(), true ) );
$m = (object) array( 'phone' => '0911234567' );
check( 'no key set: sending says not sent', EtelID_Password_Reset::send_via_sms( $m, '123456' ) === false );

putenv( 'ETELID_SMSET_API_KEY=GOODKEY' );
check( 'key set: the SMS choice is offered (reset page and phone verification)', in_array( 'sms', EtelID_Phone_Verify::channels(), true ) );
check( 'key set: the code is sent', EtelID_Password_Reset::send_via_sms( $m, '123456', 'password reset code', 15 ) === true );
$rows = file_exists( $log ) ? array_map( 'json_decode', file( $log, FILE_IGNORE_NEW_LINES ), array_fill( 0, count( file( $log ) ), true ) ) : array();
$last = end( $rows );
check( 'the key goes in the KEY header', isset( $last['key'] ) && $last['key'] === 'GOODKEY' );
check( 'the number is in 251 form', isset( $last['body']['msisdn'] ) && $last['body']['msisdn'] === '251911234567', json_encode( $last ) );
check( 'the text carries the code and the lifetime', isset( $last['body']['text'] ) && strpos( $last['body']['text'], '123456' ) !== false && strpos( $last['body']['text'], '15 minutes' ) !== false );
check( 'a refused number (not whitelisted) is reported as not sent', EtelID_Password_Reset::send_via_sms( (object) array( 'phone' => '0900000000' ), '123456' ) === false );
putenv( 'ETELID_SMSET_API_KEY=WRONG' );
check( 'a wrong key is reported as not sent', EtelID_Password_Reset::send_via_sms( $m, '123456' ) === false );
check( 'a bad phone number is not sent', EtelID_Password_Reset::send_via_sms( (object) array( 'phone' => '12' ), '123456' ) === false );
putenv( 'ETELID_SMSET_API_KEY' );

proc_terminate( $proc ); @unlink( $dir . '/index.php' ); @unlink( $log ); @rmdir( $dir );
echo 'PASSED: ' . $PASS . "\nFAILED: " . count( $FAILS ) . "\n"; foreach ( $FAILS as $f ) echo '  X ' . $f . "\n";
exit( $FAILS ? 1 : 0 );
