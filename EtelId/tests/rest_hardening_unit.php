<?php
/**
 * EtelID: the public REST index and user list stay private (no WordPress needed).
 *   php EtelId/tests/rest_hardening_unit.php
 */
define( 'ABSPATH', '/x/' );
$GLOBALS['__logged_in'] = false; $GLOBALS['__filters'] = array();
function add_filter( $n, $cb, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $n ][] = $cb; }
function add_action( $n, $cb, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $n ][] = $cb; }
function is_user_logged_in() { return $GLOBALS['__logged_in']; }
class FakeResponse { public $d; function __construct( $d ) { $this->d = $d; } function set_data( $d ) { $this->d = $d; } function get_data() { return $this->d; } }
require __DIR__ . '/../includes/class-etelid-rest-hardening.php';

$fails = 0;
function check( $name, $cond ) { global $fails; echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . $name . "\n"; if ( ! $cond ) $fails++; }
function run_filter( $name, $arg ) { foreach ( $GLOBALS['__filters'][ $name ] as $cb ) $arg = call_user_func( $cb, $arg ); return $arg; }

$index = array( 'name' => 'Etel', 'url' => 'https://ethelmarket.com', 'namespaces' => array( 'wp/v2', 'etelid/v1' ), 'routes' => array( '/wp/v2/users' => 1 ) );
$GLOBALS['__logged_in'] = false;
$r = run_filter( 'rest_index', new FakeResponse( $index ) )->get_data();
check( 'visitor: index has no routes', $r['routes'] === array() );
check( 'visitor: index has no namespaces', $r['namespaces'] === array() );
check( 'visitor: index has no site name or address', $r['name'] === '' && $r['url'] === '' );

$GLOBALS['__logged_in'] = true;
$r = run_filter( 'rest_index', new FakeResponse( $index ) )->get_data();
check( 'signed-in user: index unchanged', $r === $index );

$eps = array( '/wp/v2/users' => 1, '/wp/v2/users/(?P<id>[\\d]+)' => 1, '/wp/v2/users/me' => 1, '/wp/v2/posts' => 1, '/etelid/v1/service/check_role' => 1 );
$GLOBALS['__logged_in'] = false;
$out = run_filter( 'rest_endpoints', $eps );
check( 'visitor: user list endpoints removed', ! isset( $out['/wp/v2/users'] ) && ! isset( $out['/wp/v2/users/(?P<id>[\\d]+)'] ) && ! isset( $out['/wp/v2/users/me'] ) );
check( 'visitor: EtelID service routes still work', isset( $out['/etelid/v1/service/check_role'] ) );
check( 'visitor: other routes untouched', isset( $out['/wp/v2/posts'] ) );
$GLOBALS['__logged_in'] = true;
check( 'signed-in user: all endpoints kept', run_filter( 'rest_endpoints', $eps ) === $eps );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
