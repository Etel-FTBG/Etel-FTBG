<?php
/**
 * EtelID: one phone number = one account, whichever door was used (no WordPress needed).
 *
 *   php EtelId/tests/duplicate_phone_unit.php
 *
 * EtelID_Generator::create() is the single place every sign-up goes through (public apply form, Register, Quick Register,
 * Fayda form, wp-admin Issue). It must refuse a number that already has an account, in any written form, and must always
 * release the per-number lock it takes.
 */
define( 'ABSPATH', '/x/' );
class WP_Error { public $code; public $msg;
    function __construct( $c = '', $m = '' ) { $this->code = $c; $this->msg = $m; }
    function get_error_code() { return $this->code; } function get_error_message() { return $this->msg; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class EtelID_DB { static $taken = array();
    static function find_by_phone( $p ) {
        $d = preg_replace( '/[^0-9]/', '', $p ); $l9 = substr( $d, -9 );
        return in_array( $l9, self::$taken, true ) ? (object) array( 'id' => 7 ) : null; } }
class FakeWpdb { public $sql = array();
    function prepare( $q, $a ) { return str_replace( '%s', "'" . $a . "'", $q ); }
    function get_var( $q ) { $this->sql[] = $q; return 1; } }
$GLOBALS['wpdb'] = new FakeWpdb();

/* Load only the class; stub what its constants/statics need. create_member() is never reached for a duplicate. */
require __DIR__ . '/../includes/class-etelid-generator.php';

$fails = 0;
function check( $name, $cond ) { global $fails; echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . $name . "\n"; if ( ! $cond ) $fails++; }

EtelID_DB::$taken = array( '924445206' );
foreach ( array( '0924445206', '+251924445206', '251924445206', '924445206', '+251 924 445 206', '0924-445-206' ) as $form ) {
    $GLOBALS['wpdb']->sql = array();
    $r = EtelID_Generator::create( array( 'phone' => $form, 'full_name' => 'Dup' ) );
    check( "$form is refused as a duplicate", is_wp_error( $r ) && $r->get_error_code() === 'phone_exists' );
    $locks = implode( ' ', $GLOBALS['wpdb']->sql );
    check( "$form: the number lock is taken and released", strpos( $locks, 'GET_LOCK' ) !== false && strpos( $locks, 'RELEASE_LOCK' ) !== false );
}
echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
