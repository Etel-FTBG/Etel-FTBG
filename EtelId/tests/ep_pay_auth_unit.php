<?php
/**
 * Etel Pay: "how should Etel Pay check me when I pay" (Etel.Pay/wallet/api/ep_r5.php): PIN only (remembered phone, pay-only session), password, password + code.
 *   php EtelId/tests/ep_pay_auth_unit.php
 * Runs the REAL helper functions of ep_r5.php over an in-memory SQLite database (no MySQL, no EtelID). The route cases themselves are checked by reading the source.
 */
if ( ! in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) { echo "SKIP: pdo_sqlite is not installed\n"; exit( 0 ); }
define( 'EP_TBL_PREFIX', 'T_' ); define( 'EP_TBL_SESSIONS', 'T_ep_sessions' ); define( 'EP_TBL_USERS', 'T_ep_users' ); define( 'EP_SESSION_TTL', 604800 ); define( 'EP_VER', 't' );
$fails = 0;
function check( $name, $cond ) { global $fails; echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . $name . "\n"; if ( ! $cond ) $fails++; }
$GLOBALS['_body'] = array(); $action = '';
function in_str( $k, $d = '' ) { global $_body; return isset( $_body[ $k ] ) ? trim( (string) $_body[ $k ] ) : $d; }
class EpErr extends Exception {}
function ep_err( $m, $c = 400 ) { throw new EpErr( $m, $c ); }
function ep_ok( $d = null, $m = '' ) {}
require __DIR__ . '/../../Etel.Pay/wallet/api/ep_pin.php';
require __DIR__ . '/../../Etel.Pay/wallet/api/ep_r5.php';

$pdo = new PDO( 'sqlite::memory:' ); $pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$pdo->exec( 'CREATE TABLE T_ep_sessions (ep_user_id INTEGER, token TEXT, expires_at TEXT, created_at TEXT)' );
$pdo->exec( 'CREATE TABLE T_ep_users (id INTEGER PRIMARY KEY, phone TEXT, status TEXT, pin_hash TEXT)' );
$pdo->exec( "INSERT INTO T_ep_users VALUES (1, '+251911000001', 'active', '" . '$2y$10$abcdefghijklmnopqrstuuvwxyz0123456789ABCDEFGHIJKLMNOPQ' . "'), (2, '+251922000002', 'active', 'ETELID_SSO')" );

check( 'the default choice is PIN only', ep_pd_mode( $pdo, 1 ) === 'pin' );
check( 'only pin, password and code are valid choices', ep_pd_valid_mode( 'pin' ) && ep_pd_valid_mode( 'password' ) && ep_pd_valid_mode( 'code' ) && ! ep_pd_valid_mode( 'none' ) && ! ep_pd_valid_mode( '' ) );

$tok = ep_pd_register( $pdo, 1 );
check( 'a remembered phone gets a 64-character random token', preg_match( '/^[0-9a-f]{64}$/', $tok ) === 1 );
check( 'only the SHA-256 of the token is stored, never the token', (int) $pdo->query( "SELECT COUNT(*) FROM T_ep_pay_devices WHERE token_hash = '" . hash( 'sha256', $tok ) . "'" )->fetchColumn() === 1 && (int) $pdo->query( "SELECT COUNT(*) FROM T_ep_pay_devices WHERE token_hash = '$tok'" )->fetchColumn() === 0 );
check( 'the remembered phone is recognised', ep_pd_device_user( $pdo, $tok ) === 1 );
check( 'a wrong, short or odd token is not', ep_pd_device_user( $pdo, str_repeat( 'a', 64 ) ) === 0 && ep_pd_device_user( $pdo, 'abc' ) === 0 && ep_pd_device_user( $pdo, "' OR 1=1 --" ) === 0 && ep_pd_device_user( $pdo, '' ) === 0 );
$tok2 = ep_pd_register( $pdo, 1 );
check( 'two phones of the same person work separately', $tok2 !== $tok && ep_pd_device_user( $pdo, $tok ) === 1 && ep_pd_device_user( $pdo, $tok2 ) === 1 );

$pdo->exec( "UPDATE T_ep_pay_devices SET expires_at = '2000-01-01 00:00:00' WHERE token_hash = '" . hash( 'sha256', $tok2 ) . "'" );
check( 'a remembered phone older than 90 days is not accepted', ep_pd_device_user( $pdo, $tok2 ) === 0 );

ep_pd_set_mode( $pdo, 1, 'password' );
check( 'choosing password: the choice is saved', ep_pd_mode( $pdo, 1 ) === 'password' );
check( '... every remembered phone is forgotten at once', ep_pd_device_user( $pdo, $tok ) === 0 && (int) $pdo->query( 'SELECT COUNT(*) FROM T_ep_pay_devices WHERE ep_user_id = 1' )->fetchColumn() === 0 );
check( '... and no phone can be remembered while the choice is not PIN only', ep_pd_register( $pdo, 1 ) === '' );
ep_pd_set_mode( $pdo, 1, 'code' );
check( 'choosing code works too (and replaces the old row)', ep_pd_mode( $pdo, 1 ) === 'code' && (int) $pdo->query( 'SELECT COUNT(*) FROM T_ep_pay_prefs WHERE ep_user_id = 1' )->fetchColumn() === 1 );
check( 'a bad choice is refused', ep_pd_set_mode( $pdo, 1, 'nothing' ) === false && ep_pd_mode( $pdo, 1 ) === 'code' );
ep_pd_set_mode( $pdo, 1, 'pin' );
$t3 = ep_pd_register( $pdo, 1 );
check( 'back to PIN only: a phone can be remembered again', ep_pd_device_user( $pdo, $t3 ) === 1 );
check( 'one member\'s choice does not touch another\'s', ep_pd_mode( $pdo, 2 ) === 'pin' );

$pdo->exec( "UPDATE T_ep_pay_devices SET expires_at = '2000-01-01 00:00:00'" );
$t4 = ep_pd_register( $pdo, 1 );
check( 'registering a phone also cleans out old expired rows', (int) $pdo->query( 'SELECT COUNT(*) FROM T_ep_pay_devices' )->fetchColumn() === 1 && ep_pd_device_user( $pdo, $t4 ) === 1 );

// pay-only session
check( 'a pay-only sign-in may send an ETEL payment', ep_pd_scope_allows( 'ep_send', 'ETEL Ride #12' ) && ep_pd_scope_allows( 'etel_pay_send', 'ETEL Delivery order #5' ) );
check( '... and nothing else: not another note, not balance, history, PIN or password changes, not a transfer to a friend', ! ep_pd_scope_allows( 'ep_send', 'for lunch' ) && ! ep_pd_scope_allows( 'ep_send', '' ) && ! ep_pd_scope_allows( 'ep_balance', 'ETEL Ride #1' ) && ! ep_pd_scope_allows( 'ep_change_pin', '' ) && ! ep_pd_scope_allows( 'ep_set_wallet_pin', 'ETEL x' ) && ! ep_pd_scope_allows( 'ep_my_transactions', '' ) );
list( $pt ) = ep_pd_new_session( $pdo, 1, 'pay' );
list( $ft ) = ep_pd_new_session( $pdo, 1, 'full' );
check( 'a pay-only session is marked as pay-only; a full one is not', ep_pd_session_scope( $pdo, $pt ) === 'pay' && ep_pd_session_scope( $pdo, $ft ) === '' );
check( 'a pay-only session lasts one day, a full one seven', strtotime( $pdo->query( "SELECT expires_at FROM T_ep_sessions WHERE token = '$pt'" )->fetchColumn() ) - time() <= 86400 + 5 && strtotime( $pdo->query( "SELECT expires_at FROM T_ep_sessions WHERE token = '$ft'" )->fetchColumn() ) - time() > 86400 * 6 );
$GLOBALS['action'] = 'ep_balance'; $_body = array();
$blocked = false; try { ep_pd_scope_guard( $pdo, $pt ); } catch ( EpErr $e ) { $blocked = $e->getCode() === 403; }
check( 'the guard refuses a pay-only session on any other action (403)', $blocked );
$GLOBALS['action'] = 'ep_send'; $_body = array( 'note' => 'ETEL Ride #7' ); $passed = true; try { ep_pd_scope_guard( $pdo, $pt ); } catch ( EpErr $e ) { $passed = false; }
check( '... and lets the ride payment through', $passed );
$GLOBALS['action'] = 'ep_balance'; $passed = true; try { ep_pd_scope_guard( $pdo, $ft ); } catch ( EpErr $e ) { $passed = false; }
check( 'a normal full session is not limited', $passed );

// after-login hook
$_body = array( 'remember_device' => '1' );
$o = ep_pd_after_login( $pdo, array( 'id' => 1, 'pin_hash' => '$2y$10$abc' ), array( 'token' => 'x' ) );
check( 'after a full sign-in with remember_device and a PIN: the phone is remembered', ! empty( $o['device_token'] ) && $o['pay_mode'] === 'pin' && ep_pd_device_user( $pdo, $o['device_token'] ) === 1 );
$o = ep_pd_after_login( $pdo, array( 'id' => 2, 'pin_hash' => 'ETELID_SSO' ), array( 'token' => 'x' ) );
check( '... but not while the wallet has no PIN yet', empty( $o['device_token'] ) );
$_body = array(); $o = ep_pd_after_login( $pdo, array( 'id' => 1, 'pin_hash' => '$2y$10$abc' ), array() );
check( '... and not unless the page asks for it', empty( $o['device_token'] ) );
ep_pd_set_mode( $pdo, 1, 'code' ); $_body = array( 'remember_device' => '1' );
$o = ep_pd_after_login( $pdo, array( 'id' => 1, 'pin_hash' => '$2y$10$abc' ), array() );
check( '... and never when the member chose password or code', empty( $o['device_token'] ) && $o['pay_mode'] === 'code' );

// route cases: read the source
$src = file_get_contents( __DIR__ . '/../../Etel.Pay/wallet/api/ep_r5.php' ); $v2 = file_get_contents( __DIR__ . '/../../Etel.Pay/wallet/api/v2_verify.php' ); $r3 = file_get_contents( __DIR__ . '/../../Etel.Pay/wallet/api/ep_r3.php' );
check( 'route: ep_login_device and ep_pay_auth_mode exist', strpos( $src, "case 'ep_login_device'" ) !== false && strpos( $src, "case 'ep_pay_auth_mode'" ) !== false );
check( 'route: changing the choice goes through EtelID (password, and the code when there is one)', strpos( $src, "etelid_pay_code_mode(\$phone, \$pw, (\$mode === 'code') ? 'ask' : 'skip'" ) !== false );
check( 'route: PIN only is refused while the wallet has no PIN', strpos( $src, "'code' => 'no_pin'" ) !== false );
check( 'wired in: dispatcher, the sign-in guard in ep_auth, and the sign-in hook in ep_login_etelid', strpos( $v2, 'ep_route_part5' ) !== false && strpos( $v2, 'ep_pd_scope_guard' ) !== false && strpos( $r3, 'ep_pd_after_login' ) !== false );
check( 'ASCII only', ! preg_match( '/[^\x00-\x7F]/', $src ) );
echo $fails ? "FAILED: $fails\n" : "ALL PASS\n"; exit( $fails ? 1 : 0 );
