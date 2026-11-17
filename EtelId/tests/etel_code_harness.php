<?php
/**
 * Shared test harness for the Etel Code tests (no WordPress, no MySQL server): an in-memory SQLite database behind a small $wpdb look-alike,
 * plus the few WordPress functions the Etel Code files call. The REAL Etelid_Code class runs on top of it, so the SQL rules
 * (UNIQUE code, 30-day change, 90-day hold, transactions) are tested for real, not mocked.
 *
 * Used by etel_code_unit.php and etel_code_signin_unit.php. Needs pdo_sqlite (skips the tests when it is missing).
 */
define( 'ABSPATH', '/x/' );
if ( ! extension_loaded( 'pdo_sqlite' ) ) { echo "SKIP: pdo_sqlite is not installed\n"; exit( 0 ); }

class WP_Error { public $code; public $msg; public $data;
    function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->msg = $m; $this->data = $d; }
    function get_error_code() { return $this->code; } function get_error_message() { return $this->msg; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }

$GLOBALS['__opts'] = array( 'etelid_code_db_ver' => '1' );       // the table exists: skip dbDelta
$GLOBALS['__blocked_words'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function apply_filters( $tag, $v ) { return $tag === 'etelid_code_blocked_words' ? $GLOBALS['__blocked_words'] : $v; }
function current_time( $t ) { return gmdate( 'Y-m-d H:i:s' ); }
function add_action() {} function add_shortcode() {} function add_filter() {}

class FakeWpdb {
    public $prefix = 'wp_';
    public $pdo;
    public $log = array();            // every statement run (so a test can see BEGIN / ROLLBACK)
    public $before_begin = null;      // callable run just before the claim's transaction starts (simulates a rival claim landing after the availability check)
    public function __construct() {
        $this->pdo = new PDO( 'sqlite::memory:' );
        $this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
        $this->pdo->sqliteCreateFunction( 'RIGHT', function ( $s, $n ) { return substr( (string) $s, -1 * (int) $n ); }, 2 );
        $this->pdo->exec( "CREATE TABLE wp_etelid_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, member_id INT NOT NULL, code_norm VARCHAR(16) NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL, released_at DATETIME NULL, UNIQUE (code_norm))" );
        $this->pdo->exec( "CREATE TABLE wp_etelid_members (id INTEGER PRIMARY KEY AUTOINCREMENT, etel_id VARCHAR(30) NOT NULL DEFAULT '', full_name VARCHAR(120) NOT NULL DEFAULT '',
            phone VARCHAR(30) NOT NULL DEFAULT '', email VARCHAR(120) NOT NULL DEFAULT '', tg_chat_id VARCHAR(40) NOT NULL DEFAULT '', status VARCHAR(12) NOT NULL DEFAULT 'active',
            pin_hash VARCHAR(255) NOT NULL DEFAULT '', fin VARCHAR(20) NOT NULL DEFAULT '', card_number VARCHAR(20) NOT NULL DEFAULT '')" );
    }
    public function get_charset_collate() { return ''; }
    public function prepare( $q, $args = null ) {
        $a = func_get_args(); array_shift( $a );
        if ( count( $a ) === 1 && is_array( $a[0] ) ) $a = $a[0];
        $i = 0; $pdo = $this->pdo;
        return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $a, $pdo ) {
            $v = isset( $a[ $i ] ) ? $a[ $i ] : ''; $i++;
            return $m[0] === '%d' ? (string) (int) $v : $pdo->quote( (string) $v );
        }, $q );
    }
    private function run( $sql ) {
        $this->log[] = $sql;
        $sql = preg_replace( '/^\s*START TRANSACTION/i', 'BEGIN', $sql );
        return $this->pdo->query( $sql );
    }
    public function get_row( $sql ) { $r = $this->run( $sql )->fetch( PDO::FETCH_OBJ ); return $r ? $r : null; }
    public function get_var( $sql ) { $r = $this->run( $sql )->fetch( PDO::FETCH_NUM ); return $r ? $r[0] : null; }
    public function get_results( $sql ) { return $this->run( $sql )->fetchAll( PDO::FETCH_OBJ ); }
    public function query( $sql ) {
        if ( $this->before_begin && preg_match( '/^\s*START TRANSACTION/i', $sql ) ) { $f = $this->before_begin; $this->before_begin = null; $f( $this ); }
        $this->log[] = $sql; $sql = preg_replace( '/^\s*START TRANSACTION/i', 'BEGIN', $sql ); return $this->pdo->exec( $sql ); }
    public function suppress_errors( $b = true ) { return false; }
    public function insert( $table, $data, $fmt = null ) {
        $cols = implode( ',', array_keys( $data ) );
        $vals = implode( ',', array_map( array( $this->pdo, 'quote' ), array_map( 'strval', array_values( $data ) ) ) );
        try { $this->pdo->exec( "INSERT INTO $table ($cols) VALUES ($vals)" ); return 1; } catch ( Exception $e ) { return false; }
    }
    public function delete( $table, $where ) {
        $c = array(); foreach ( $where as $k => $v ) $c[] = "$k = " . $this->pdo->quote( (string) $v );
        return $this->pdo->exec( "DELETE FROM $table WHERE " . implode( ' AND ', $c ) );
    }
}
$GLOBALS['wpdb'] = new FakeWpdb();

function etc_member( $name, $phone, $extra = array() ) {
    global $wpdb;
    static $n = 0; $n++;
    $row = array_merge( array( 'etel_id' => sprintf( 'EG-2026-%05d', $n ), 'full_name' => $name, 'phone' => $phone, 'status' => 'active' ), $extra );
    $wpdb->insert( 'wp_etelid_members', $row );
    return (int) $wpdb->pdo->lastInsertId();
}
function etc_age_code( $member_id, $days_ago_created, $released_days_ago = null ) {
    global $wpdb;
    $c = gmdate( 'Y-m-d H:i:s', time() - $days_ago_created * 86400 );
    $wpdb->pdo->exec( "UPDATE wp_etelid_codes SET created_at = '$c'" . ( $released_days_ago !== null ? ", released_at = '" . gmdate( 'Y-m-d H:i:s', time() - $released_days_ago * 86400 ) . "'" : '' ) . " WHERE member_id = " . (int) $member_id . ( $released_days_ago !== null ? " AND status = 'released'" : " AND status = 'active'" ) );
}
$fails = 0;
function check( $name, $cond ) { global $fails; echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . $name . "\n"; if ( ! $cond ) $fails++; }
function etc_done() { global $fails; echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n"; exit( $fails ? 1 : 0 ); }
