<?php
/**
 * Etel Code in EtelPay: "Send money" to a code (E1616) instead of a phone number.
 *
 *   php EtelId/tests/etel_code_pay_unit.php
 *
 * Runs the REAL EtelPay helpers (Etel.Pay/wallet/api/etelid_kyc.php: ep_looks_like_code, ep_code_member, ep_resolve_throttled and the real
 * etelid_call over real HTTP) against a small local web server that answers like EtelID's service/resolve. The 'ep_resolve_recipient'
 * and 'ep_send' cases themselves live inside ep_r1.php next to the wallet database, so they are checked by reading the source
 * (they must go through these helpers, throttle before the lookup, ask for the PIN first, and never send the phone back to the sender).
 * No database needed.
 */
if ( ! function_exists( 'curl_init' ) ) { echo "SKIP: the curl extension is not installed\n"; exit( 0 ); }
define( 'EP_DB_HOST', 'unused' );

$fails = 0;
function check( $name, $cond ) { global $fails; echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . $name . "\n"; if ( ! $cond ) $fails++; }

/* ---- a stand-in EtelID service: POST /etelid/v1/service/resolve ---- */
$tmp = sys_get_temp_dir() . '/etel_pay_code_' . getmypid();
@mkdir( $tmp, 0700, true );
$key = 'test-service-key-0123456789abcdef0123456789';
file_put_contents( $tmp . '/router.php', '<?php
$log = ' . var_export( $tmp . '/calls.log', true ) . ';
$path = parse_url( $_SERVER["REQUEST_URI"], PHP_URL_PATH );
$hdr = isset( $_SERVER["HTTP_X_ETEL_SERVICE_KEY"] ) ? $_SERVER["HTTP_X_ETEL_SERVICE_KEY"] : "";
$in = json_decode( file_get_contents( "php://input" ), true );
header( "Content-Type: application/json" );
if ( $hdr !== ' . var_export( $key, true ) . ' ) { http_response_code( 403 ); echo json_encode( array( "code" => "forbidden", "message" => "Invalid service key." ) ); return; }
if ( $path !== "/etelid/v1/service/resolve" ) { http_response_code( 404 ); echo json_encode( array( "code" => "no_route" ) ); return; }
$id = isset( $in["identifier"] ) ? (string) $in["identifier"] : "";
file_put_contents( $log, $id . "|" . ( isset( $_SERVER["HTTP_X_ETEL_APP"] ) ? $_SERVER["HTTP_X_ETEL_APP"] : "" ) . "\n", FILE_APPEND );
$norm = preg_replace( "/[\\s\\-_]+/", "", strtoupper( trim( $id ) ) );
$people = array(
  "E1616"    => array( "etel_id" => "EG-2026-00001", "full_name" => "Dagi Test",   "phone" => "0911000001", "status" => "active" ),
  "EABEBE"   => array( "etel_id" => "EG-2026-00002", "full_name" => "Abebe Kebede", "phone" => "+251922000002", "status" => "active" ),
  "ENOPHONE" => array( "etel_id" => "EG-2026-00003", "full_name" => "No Phone",    "phone" => "", "status" => "active" ),
  "ESUSP1"   => array( "etel_id" => "EG-2026-00004", "full_name" => "Suspended",   "phone" => "0933000003", "status" => "suspended" ),
  "EREVK1"   => array( "etel_id" => "EG-2026-00005", "full_name" => "Revoked",     "phone" => "0944000004", "status" => "revoked" ),
);
if ( isset( $people[ $norm ] ) ) { echo json_encode( array( "ok" => true, "member" => $people[ $norm ] ) ); return; }
http_response_code( 404 );
echo json_encode( array( "code" => "not_found", "message" => "No EtelID member matches that identifier.", "data" => array( "status" => 404 ) ) );
' );
$sock = stream_socket_server( 'tcp://127.0.0.1:0', $en, $es ); $addr = stream_socket_get_name( $sock, false ); fclose( $sock );
$port = (int) substr( strrchr( $addr, ':' ), 1 );
$proc = proc_open( array( PHP_BINARY, '-S', '127.0.0.1:' . $port, $tmp . '/router.php' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $tmp . '/server.out', 'w' ), 2 => array( 'file', $tmp . '/server.err', 'w' ) ), $pipes );
for ( $i = 0; $i < 40; $i++ ) { $c = @fsockopen( '127.0.0.1', $port, $e1, $e2, 0.2 ); if ( $c ) { fclose( $c ); break; } usleep( 100000 ); }
define( 'ETELID_SERVICE_URL', 'http://127.0.0.1:' . $port . '/etelid/v1' );
define( 'ETELID_SERVICE_KEY', $key );
require __DIR__ . '/../../Etel.Pay/wallet/api/etelid_kyc.php';
function calls() { return is_file( $GLOBALS['tmp'] . '/calls.log' ) ? file( $GLOBALS['tmp'] . '/calls.log', FILE_IGNORE_NEW_LINES ) : array(); }

/* ---- what looks like a code (and so is looked up) and what is a phone ---- */
foreach ( array( 'E1616', 'e1616', ' E1616 ', 'E-1616', 'e 16 16', 'EABEBE', 'EDAGI', 'E_1616', 'ETELPAY' ) as $c ) check( "looks like a code: '$c'", ep_looks_like_code( $c ) );
foreach ( array( '0911000001', '+251911000001', '251911000001', '911000001', '+251 911 000 001', '', '   ', 'Dagi', 'E', 'E12', 'E1', 'EABCDEFGHIJKLMNOP', 'E1616;DROP', 'E16$16', "E16\n16", '1616E' ) as $c ) {
    check( "does NOT look like a code: '" . str_replace( "\n", '\n', $c ) . "'", ! ep_looks_like_code( $c ) );
}
check( 'a code needs 3 to 14 more characters after the E', ep_looks_like_code( 'E123' ) && ep_looks_like_code( 'E' . str_repeat( 'A', 14 ) ) && ! ep_looks_like_code( 'E' . str_repeat( 'A', 15 ) ) );

/* ---- who owns this code: real HTTP to the stand-in service ---- */
$m = ep_code_member( 'E1616' );
check( 'a code gives the member (name and phone)', is_array( $m ) && $m['full_name'] === 'Dagi Test' && $m['phone'] === '0911000001' );
check( 'the call carried the service key and the app name', count( calls() ) === 1 && strpos( calls()[0], 'E1616|EtelPay' ) === 0 );
$m = ep_code_member( '  e-abebe ' );
check( 'spaces, dash and lower case are fine', is_array( $m ) && $m['full_name'] === 'Abebe Kebede' );
check( 'the typed code is trimmed before it is sent', calls()[1] === 'e-abebe|EtelPay/2.0' );
check( 'an unknown code gives null', ep_code_member( 'ENOBODY' ) === null );
check( 'a member with no phone on file gives null (nothing to pay)', ep_code_member( 'ENOPHONE' ) === null );
check( 'a suspended member gives null', ep_code_member( 'ESUSP1' ) === null );
check( 'a revoked member gives null', ep_code_member( 'EREVK1' ) === null );
check( 'an empty code gives null', ep_code_member( '' ) === null );

/* ---- at most 20 look-ups a minute per member, so the code list cannot be walked ---- */
$uid = 910000 + ( getmypid() % 1000 );
@unlink( rtrim( sys_get_temp_dir(), '/\\' ) . '/ep_resolve_' . $uid . '.txt' );
$blockedAt = 0; for ( $i = 1; $i <= 25; $i++ ) { if ( ep_resolve_throttled( $uid ) ) { $blockedAt = $i; break; } }
check( 'the 21st look-up in a minute is refused (the first 20 pass)', $blockedAt === 21 );
check( 'another member is not affected', ep_resolve_throttled( $uid + 1 ) === false );
@unlink( rtrim( sys_get_temp_dir(), '/\\' ) . '/ep_resolve_' . $uid . '.txt' ); @unlink( rtrim( sys_get_temp_dir(), '/\\' ) . '/ep_resolve_' . ( $uid + 1 ) . '.txt' );

/* ---- the 'Send money' cases in ep_r1.php (read as source) ---- */
$src = file_get_contents( __DIR__ . '/../../Etel.Pay/wallet/api/ep_r1.php' );
$a = strpos( $src, "case 'ep_resolve_recipient': {" ); $b = strpos( $src, "case 'ep_send':", $a );
check( 'found the ep_resolve_recipient case', $a !== false && $b !== false );
$resolve = substr( $src, $a, $b - $a );
check( 'resolve: needs a signed-in sender', strpos( $resolve, 'ep_auth_id()' ) !== false );
check( 'resolve: a phone number is answered locally (EtelID is not asked)', preg_match( "/if \(!ep_looks_like_code\(\\\$in\)\) \{ ep_ok\(array\('kind' => 'phone'/", $resolve ) === 1 );
check( 'resolve: look-ups are throttled BEFORE EtelID is asked', ( $t = strpos( $resolve, 'ep_resolve_throttled(' ) ) !== false && $t < strpos( $resolve, 'ep_code_member(' ) );
check( 'resolve: a throttled sender gets HTTP 429', strpos( $resolve, 'Too many look-ups' ) !== false && strpos( $resolve, '429' ) !== false );
check( 'resolve: shows the owner\'s NAME so the sender can check before paying', strpos( $resolve, "'name' => " ) !== false && strpos( $resolve, 'full_name' ) !== false );
preg_match( "/ep_ok\(array\('kind' => 'code'.*?\)\);/s", $resolve, $ok );
check( 'resolve: the answer for a code does NOT contain the phone number', ! empty( $ok[0] ) && strpos( $ok[0], 'phone' ) === false );
check( 'resolve: a person with no active EtelPay wallet is refused', strpos( $resolve, 'no active Etel Pay wallet' ) !== false );
check( 'resolve: an unknown code answers "Etel Code not found" (404)', strpos( $resolve, "ep_err('Etel Code not found', 404)" ) !== false );

$c = strpos( $src, "case 'etel_pay_send': {", $b ); $d = strpos( $src, 'Load sender', $c );
$send = substr( $src, $c, $d - $c );
check( 'send: found the send case', $c !== false && $d !== false );
check( 'send: the sender\'s PIN is checked BEFORE any code is looked up', ( $p = strpos( $send, 'ep_require_pin(' ) ) !== false && $p < strpos( $send, 'ep_code_member(' ) );
check( 'send: a code is turned into the member\'s phone from EtelID, never used as typed', strpos( $send, 'ep_code_member($receiver_in)' ) !== false && strpos( $send, "ep_normalize_phone(\$cm['phone'])" ) !== false );
check( 'send: an unknown code stops the payment', strpos( $send, "ep_err('Etel Code not found')" ) !== false );
check( 'send: no amount is taken before the receiver is known', strpos( $send, 'in_float(\'amount\')' ) > strpos( $send, 'ep_code_member(' ) );

/* ---- EtelID not reachable: the code cannot be used as a phone number ---- */
proc_terminate( $proc ); proc_close( $proc );
for ( $i = 0; $i < 20; $i++ ) { $c = @fsockopen( '127.0.0.1', $port, $e1, $e2, 0.2 ); if ( ! $c ) break; fclose( $c ); usleep( 100000 ); }
check( 'EtelID not reachable: ep_code_member gives null (the payment stops, nothing is sent to a guessed number)', ep_code_member( 'E1616' ) === null );

foreach ( glob( $tmp . '/*' ) as $f ) @unlink( $f ); @rmdir( $tmp );
echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
