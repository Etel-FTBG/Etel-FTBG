<?php
/**
 * Etel Pay: the member's choice "ask me for my PIN, not the 6-digit code" (Etel.Pay/wallet/api/etelid_kyc.php etelid_pay_code_mode and the
 * ep_pay_code_mode case in ep_r3.php).
 *
 *   php EtelId/tests/etel_pay_code_mode_unit.php
 *
 * Runs the REAL etelid_pay_code_mode and etelid_call over real HTTP against a small local web server that answers like EtelID's new
 * service/pay_2fa_mode route (the route itself is tested for real in pay_2fa_mode_unit.php). The ep_pay_code_mode case lives inside ep_r3.php next to the wallet
 * database, so it is checked by reading the source. No database needed.
 */
if ( ! function_exists( 'curl_init' ) ) { echo "SKIP: the curl extension is not installed\n"; exit( 0 ); }
define( 'EP_DB_HOST', 'unused' );
$fails = 0;
function check( $name, $cond ) { global $fails; echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . $name . "\n"; if ( ! $cond ) $fails++; }

$tmp = sys_get_temp_dir() . '/etel_pay_mode_' . getmypid();
@mkdir( $tmp, 0700, true );
$key = 'test-service-key-0123456789abcdef0123456789';
file_put_contents( $tmp . '/router.php', '<?php
$log = ' . var_export( $tmp . '/calls.log', true ) . ';
$path = parse_url( $_SERVER["REQUEST_URI"], PHP_URL_PATH );
$hdr = isset( $_SERVER["HTTP_X_ETEL_SERVICE_KEY"] ) ? $_SERVER["HTTP_X_ETEL_SERVICE_KEY"] : "";
$in = json_decode( file_get_contents( "php://input" ), true );
header( "Content-Type: application/json" );
function err( $http, $code, $msg, $extra = array() ) { http_response_code( $http ); echo json_encode( array( "code" => $code, "message" => $msg, "data" => array_merge( array( "status" => $http ), $extra ) ) ); }
if ( $hdr !== ' . var_export( $key, true ) . ' ) { err( 403, "forbidden", "Invalid service key." ); return; }
if ( $path !== "/etelid/v1/service/pay_2fa_mode" ) { err( 404, "rest_no_route", "No route was found" ); return; }
$id = isset( $in["identifier"] ) ? (string) $in["identifier"] : "";
file_put_contents( $log, json_encode( $in ) . "\n", FILE_APPEND );
if ( $id === "OLD" ) { err( 404, "rest_no_route", "No route was found matching the URL and request method." ); return; }
if ( $id === "LOCKED" ) { err( 429, "rate_limited", "Too many wrong passwords for this account. Please wait 15 minutes, or reset the password." ); return; }
if ( ( isset( $in["password"] ) ? $in["password"] : "" ) !== "PassOk#1" ) { err( 401, "wrong_password", "Incorrect EtelID password." ); return; }
$mode = isset( $in["mode"] ) ? $in["mode"] : ""; $code = isset( $in["totp_code"] ) ? $in["totp_code"] : "";
if ( $id === "NOTWOFA" ) { echo json_encode( array( "ok" => true, "twofa_enabled" => false, "pay_skip" => false ) ); return; }
if ( $mode === "skip" ) {
  if ( $id === "KILL" ) { err( 403, "pay_choice_off", "Etel Pay always asks for the 6-digit code at the moment." ); return; }
  if ( $code === "" ) { err( 401, "requires_2fa", "Enter the 6-digit code from your authenticator app (or a backup code).", array( "requires_2fa" => true ) ); return; }
  if ( $code !== "123456" ) { err( 401, "bad_2fa", "That code is not right. Check the time on your phone and try again.", array( "requires_2fa" => true ) ); return; }
  echo json_encode( array( "ok" => true, "twofa_enabled" => true, "pay_skip" => true ) ); return;
}
echo json_encode( array( "ok" => true, "twofa_enabled" => true, "pay_skip" => false ) );
' );
$sock = stream_socket_server( 'tcp://127.0.0.1:0', $en, $es ); $addr = stream_socket_get_name( $sock, false ); fclose( $sock );
$port = (int) substr( strrchr( $addr, ':' ), 1 );
$proc = proc_open( array( PHP_BINARY, '-S', '127.0.0.1:' . $port, $tmp . '/router.php' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $tmp . '/server.out', 'w' ), 2 => array( 'file', $tmp . '/server.err', 'w' ) ), $pipes );
for ( $i = 0; $i < 40; $i++ ) { $c = @fsockopen( '127.0.0.1', $port, $e1, $e2, 0.2 ); if ( $c ) { fclose( $c ); break; } usleep( 100000 ); }
define( 'ETELID_SERVICE_URL', 'http://127.0.0.1:' . $port . '/etelid/v1' );
define( 'ETELID_SERVICE_KEY', $key );
require __DIR__ . '/../../Etel.Pay/wallet/api/etelid_kyc.php';
function calls() { $f = $GLOBALS['tmp'] . '/calls.log'; $o = array(); if ( is_file( $f ) ) foreach ( file( $f, FILE_IGNORE_NEW_LINES ) as $l ) $o[] = json_decode( $l, true ); return $o; }

/* status / ask */
list( $h, $j ) = etelid_pay_code_mode( '0911000001', 'PassOk#1', 'status' );
check( 'status: 200, success, two-step on, choice off', $h === 200 && $j['success'] === true && $j['data']['twofa_enabled'] === true && $j['data']['pay_skip'] === false, json_encode( $j ) );
check( 'the call says it is Etel Pay and carries identifier, password and mode', ( $c = calls() ) && $c[0]['app'] === 'pay' && $c[0]['identifier'] === '0911000001' && $c[0]['password'] === 'PassOk#1' && $c[0]['mode'] === 'status' && ! isset( $c[0]['totp_code'] ), json_encode( $c ) );
list( $h, $j ) = etelid_pay_code_mode( '0911000001', 'PassOk#1', 'ask' );
check( 'ask: 200 and the choice is off', $h === 200 && $j['data']['pay_skip'] === false );

/* skip: the code is needed */
list( $h, $j ) = etelid_pay_code_mode( '0911000001', 'PassOk#1', 'skip' );
check( 'skip without a code: 401 requires_2fa (so the box asks for it)', $h === 401 && $j['success'] === false && $j['data']['requires_2fa'] === true && $j['data']['code'] === 'requires_2fa' && strpos( $j['data']['message'], '6-digit' ) !== false, json_encode( $j ) );
list( $h, $j ) = etelid_pay_code_mode( '0911000001', 'PassOk#1', 'skip', '000000' );
check( 'skip with a wrong code: 401 bad_2fa with the reason, asked again', $h === 401 && $j['data']['requires_2fa'] === true && $j['data']['code'] === 'bad_2fa' && strpos( $j['data']['message'], 'not right' ) !== false );
list( $h, $j ) = etelid_pay_code_mode( '0911000001', 'PassOk#1', 'skip', '123456' );
check( 'skip with the right code: 200 and the choice is on', $h === 200 && $j['success'] === true && $j['data']['pay_skip'] === true );
$c = calls(); $last = $c[count( $c ) - 1];
check( 'the code is passed on (and only when there is one)', $last['totp_code'] === '123456' && $last['mode'] === 'skip' );

/* refusals */
list( $h, $j ) = etelid_pay_code_mode( '0911000001', 'WrongPass', 'skip', '123456' );
check( 'a wrong password: 401 with EtelID\'s own words, not asked for a code', $h === 401 && $j['success'] === false && $j['data']['message'] === 'Incorrect EtelID password.' && empty( $j['data']['requires_2fa'] ), json_encode( $j ) );
list( $h, $j ) = etelid_pay_code_mode( 'LOCKED', 'x', 'status' );
check( 'a locked account: 429 with the message', $h === 429 && strpos( $j['data']['message'], '15 minutes' ) !== false );
list( $h, $j ) = etelid_pay_code_mode( 'KILL', 'PassOk#1', 'skip', '123456' );
check( 'the owner\'s kill switch: 403 pay_choice_off with the message', $h === 403 && $j['data']['code'] === 'pay_choice_off' && strpos( $j['data']['message'], 'always asks' ) !== false, json_encode( $j ) );
list( $h, $j ) = etelid_pay_code_mode( 'OLD', 'PassOk#1', 'skip', '123456' );
check( 'an EtelID that has not been updated yet: a plain "not available yet" (404), no raw error', $h === 404 && $j['data']['code'] === 'not_available' && strpos( $j['data']['message'], 'not available yet' ) !== false && strpos( json_encode( $j ), 'rest_no_route' ) === false, json_encode( $j ) );
list( $h, $j ) = etelid_pay_code_mode( 'NOTWOFA', 'PassOk#1', 'skip' );
check( 'a member without two-step: 200, nothing to skip', $h === 200 && $j['data']['twofa_enabled'] === false && $j['data']['pay_skip'] === false );

/* secrets */
check( 'the answer never contains the service key', strpos( json_encode( $j ), $key ) === false );

/* the ep_pay_code_mode case (read as source) */
$src = file_get_contents( __DIR__ . '/../../Etel.Pay/wallet/api/ep_r3.php' );
$a = strpos( $src, "case 'ep_pay_code_mode': {" ); $b = strpos( $src, "case 'ep_set_wallet_pin': {", $a );
check( 'found the ep_pay_code_mode case', $a !== false && $b !== false );
$case = substr( $src, $a, $b - $a );
check( 'case: phone and password are required', strpos( $case, "ep_err('Phone and password required')" ) !== false );
check( 'case: only status, skip and ask are accepted (400 otherwise)', strpos( $case, "array('status', 'skip', 'ask')" ) !== false && strpos( $case, '400' ) !== false );
check( 'case: it goes through etelid_pay_code_mode with the typed code', strpos( $case, "etelid_pay_code_mode(\$phone, \$pw, \$mode, in_str('totp_code'))" ) !== false );
check( 'case: the HTTP status of the helper is sent back as it is', strpos( $case, 'http_response_code($res[0])' ) !== false );
check( 'case: the answer is JSON with the version, and stops', strpos( $case, "\$out['v'] = EP_VER" ) !== false && strpos( $case, 'exit;' ) !== false );
check( 'case: the password is never logged or echoed', strpos( $case, 'error_log' ) === false && strpos( $case, "echo json_encode(\$out)" ) !== false );
$k = file_get_contents( __DIR__ . '/../../Etel.Pay/wallet/api/etelid_kyc.php' );
check( 'files stay plain ASCII', ! preg_match( '/[^\x00-\x7F]/', $src ) && ! preg_match( '/[^\x00-\x7F]/', $k ) );
check( 'files stay under 30 KB', strlen( $src ) < 30000 && strlen( $k ) < 30000, strlen( $src ) );

proc_terminate( $proc ); @array_map( 'unlink', glob( $tmp . '/*' ) ); @rmdir( $tmp );
echo $fails ? "\nFAILED: $fails\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
