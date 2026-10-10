<?php
/**
 * Unit tests for EtelID_Files (Fayda ID photos as private files).
 * Run: php EtelId/tests/fayda_files_unit.php
 * Uses a temp folder and an in-memory SQLite stand-in for $wpdb. No WordPress needed.
 */
define( 'ETELID_FILES_TEST', true );
$tmp = sys_get_temp_dir() . '/etelid_files_test_' . getmypid() . '_' . mt_rand();
@mkdir( $tmp, 0777, true );
define( 'WP_CONTENT_DIR', $tmp );

$GLOBALS['t_pass'] = 0; $GLOBALS['t_fail'] = 0;
function check( $name, $cond ) {
    if ( $cond ) { $GLOBALS['t_pass']++; echo "  PASS  $name\n"; }
    else { $GLOBALS['t_fail']++; echo "  FAIL  $name\n"; }
}

/* ---- minimal WordPress stand-ins ---- */
$GLOBALS['opts'] = array();
function get_option( $k, $d = false ) { return isset( $GLOBALS['opts'][ $k ] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; }
class EtelID_DB { public static function t_members() { return 'members'; } }

class FakeWpdb {
    public $pdo;
    public function __construct() {
        $this->pdo = new PDO( 'sqlite::memory:' );
        $this->pdo->exec( 'CREATE TABLE members (id INTEGER PRIMARY KEY, fayda_front_image TEXT, fayda_back_image TEXT,
            fayda_front_file TEXT, fayda_back_file TEXT, fayda_front_sha TEXT, fayda_back_sha TEXT)' );
    }
    public function prepare( $sql, $args = null ) {
        $args = func_get_args(); array_shift( $args );
        if ( count( $args ) === 1 && is_array( $args[0] ) ) $args = $args[0];
        $sql = str_replace( '%%', "\x01", $sql );
        $i = 0;
        $sql = preg_replace_callback( '/%[ds]/', function ( $m ) use ( &$i, $args ) {
            $v = $args[ $i++ ];
            return $m[0] === '%d' ? (string) (int) $v : $this->pdo->quote( (string) $v );
        }, $sql );
        return str_replace( "\x01", '%', $sql );
    }
    public function get_col( $sql ) {
        if ( strpos( $sql, 'SHOW COLUMNS' ) === 0 ) {
            $out = array();
            foreach ( $this->pdo->query( 'PRAGMA table_info(members)' ) as $r ) $out[] = $r['name'];
            return $out;
        }
        return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_COLUMN, 0 );
    }
    public function get_var( $sql ) { $r = $this->pdo->query( $sql )->fetch( PDO::FETCH_NUM ); return $r ? $r[0] : null; }
    public function get_row( $sql ) { $r = $this->pdo->query( $sql )->fetch( PDO::FETCH_OBJ ); return $r ? $r : null; }
    public function update( $t, $data, $where ) {
        $set = array();
        foreach ( $data as $k => $v ) $set[] = $k . ' = ' . $this->pdo->quote( (string) $v );
        $w = array();
        foreach ( $where as $k => $v ) $w[] = $k . ' = ' . (int) $v;
        return $this->pdo->exec( "UPDATE $t SET " . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $w ) );
    }
}
$wpdb = new FakeWpdb();

require dirname( __FILE__ ) . '/../includes/class-etelid-files.php';

function img( $kind, $pad = 64 ) {
    $magic = array( 'jpeg' => "\xFF\xD8\xFF\xE0", 'png' => "\x89PNG\r\n\x1a\n", 'webp' => "RIFF\x10\x00\x00\x00WEBP" );
    $bytes = $magic[ $kind ] . str_repeat( chr( mt_rand( 65, 90 ) ), $pad );
    return array( 'uri' => 'data:image/' . $kind . ';base64,' . base64_encode( $bytes ), 'bytes' => $bytes );
}
function add_member( $id, $front, $back ) {
    global $wpdb;
    $s = $wpdb->pdo->prepare( 'INSERT INTO members (id, fayda_front_image, fayda_back_image) VALUES (?,?,?)' );
    $s->execute( array( $id, $front, $back ) );
}
function row( $id ) { global $wpdb; return $wpdb->get_row( 'SELECT * FROM members WHERE id = ' . (int) $id ); }

echo "data URI parsing\n";
$j = img( 'jpeg' ); $p = img( 'png' ); $w = img( 'webp' );
check( 'jpeg accepted', EtelID_Files::parse_data_uri( $j['uri'] ) !== null );
check( 'png accepted',  EtelID_Files::parse_data_uri( $p['uri'] ) !== null );
check( 'webp accepted', EtelID_Files::parse_data_uri( $w['uri'] ) !== null );
$r = EtelID_Files::parse_data_uri( $j['uri'] );
check( 'decoded bytes are exact', $r['bytes'] === $j['bytes'] );
check( 'svg rejected', EtelID_Files::parse_data_uri( 'data:image/svg+xml;base64,' . base64_encode( '<svg/>' ) ) === null );
check( 'gif rejected', EtelID_Files::parse_data_uri( 'data:image/gif;base64,' . base64_encode( 'GIF89a' ) ) === null );
check( 'jpeg label with PNG bytes rejected', EtelID_Files::parse_data_uri( 'data:image/jpeg;base64,' . base64_encode( $p['bytes'] ) ) === null );
check( 'php code disguised as jpeg rejected', EtelID_Files::parse_data_uri( 'data:image/jpeg;base64,' . base64_encode( '<?php echo 1;' ) ) === null );
check( 'invalid base64 rejected', EtelID_Files::parse_data_uri( 'data:image/jpeg;base64,@@@not base64@@@' ) === null );
check( 'plain URL rejected', EtelID_Files::parse_data_uri( 'https://example.com/a.jpg' ) === null );
check( 'non-string rejected', EtelID_Files::parse_data_uri( null ) === null );
$big = "\xFF\xD8\xFF\xE0" . str_repeat( 'A', EtelID_Files::MAX_BYTES );
check( 'over 10 MB rejected', EtelID_Files::parse_data_uri( 'data:image/jpeg;base64,' . base64_encode( $big ) ) === null );

echo "path safety\n";
check( 'good path valid', EtelID_Files::valid_rel( 'm12/front-0123456789abcdef.jpg' ) );
check( 'traversal rejected', ! EtelID_Files::valid_rel( '../etc/passwd' ) );
check( 'embedded traversal rejected', ! EtelID_Files::valid_rel( 'm1/../front-0123456789abcdef.jpg' ) );
check( 'php extension rejected', ! EtelID_Files::valid_rel( 'm1/front-0123456789abcdef.php' ) );
check( 'absolute path rejected', ! EtelID_Files::valid_rel( '/etc/passwd' ) );
check( 'wrong side name rejected', ! EtelID_Files::valid_rel( 'm1/side-0123456789abcdef.jpg' ) );
check( 'read of an invalid path is false', EtelID_Files::read( '../x', str_repeat( 'a', 64 ) ) === false );

echo "storage\n";
$base = EtelID_Files::base_dir();
check( 'private dir created', is_dir( $base ) );
check( '.htaccess denies direct access', strpos( (string) @file_get_contents( $base . '/.htaccess' ), 'Require all denied' ) !== false );
check( 'index.html present', file_exists( $base . '/index.html' ) );
$st = EtelID_Files::store( 7, 'front', EtelID_Files::parse_data_uri( $j['uri'] ) );
check( 'store returns rel + sha', is_array( $st ) && EtelID_Files::valid_rel( $st['rel'] ) && strlen( $st['sha'] ) === 64 );
check( 'file contents equal the image', @file_get_contents( $base . '/' . $st['rel'] ) === $j['bytes'] );
check( 'read with the right sha returns the bytes', EtelID_Files::read( $st['rel'], $st['sha'] ) === $j['bytes'] );
check( 'read with a wrong sha is refused', EtelID_Files::read( $st['rel'], str_repeat( '0', 64 ) ) === false );
check( 'data_uri round-trips', EtelID_Files::data_uri( $st['rel'], $st['sha'] ) === $j['uri'] );
file_put_contents( $base . '/' . $st['rel'], 'tampered' );
check( 'tampered file is detected', EtelID_Files::read( $st['rel'], $st['sha'] ) === false );
check( 'tampered file gives no data URI', EtelID_Files::data_uri( $st['rel'], $st['sha'] ) === '' );
check( 'invalid side refused', EtelID_Files::store( 7, 'left', EtelID_Files::parse_data_uri( $j['uri'] ) ) === false );
check( 'member id 0 refused', EtelID_Files::store( 0, 'front', EtelID_Files::parse_data_uri( $j['uri'] ) ) === false );

echo "write path (sync_after_write)\n";
check( 'columns detected as ready', EtelID_Files::columns_ready() );
$a = img( 'jpeg', 100 ); $b = img( 'png', 100 );
add_member( 1, $a['uri'], $b['uri'] );
EtelID_Files::sync_after_write( 1, array( 'fayda_front_image' => $a['uri'], 'fayda_back_image' => $b['uri'] ) );
$m = row( 1 );
check( 'files and sha recorded', EtelID_Files::valid_rel( $m->fayda_front_file ) && EtelID_Files::valid_rel( $m->fayda_back_file ) && strlen( $m->fayda_front_sha ) === 64 );
check( 'database copy kept by default (rollback safe)', $m->fayda_front_image === $a['uri'] );
$old_front = $m->fayda_front_file;
$a2 = img( 'jpeg', 200 );
EtelID_Files::sync_after_write( 1, array( 'fayda_front_image' => $a2['uri'] ) );
$m = row( 1 );
check( 'replacing a photo writes a new file', $m->fayda_front_file !== $old_front );
check( 'replaced photo removes the old file', ! file_exists( $base . '/' . $old_front ) );
EtelID_Files::sync_after_write( 1, array( 'full_name' => 'x' ) );
check( 'a write without photos changes nothing', row( 1 )->fayda_front_file === $m->fayda_front_file );
add_member( 2, 'https://example.com/front.jpg', '' );
EtelID_Files::sync_after_write( 2, array( 'fayda_front_image' => 'https://example.com/front.jpg' ) );
check( 'URL photo is left alone', row( 2 )->fayda_front_file === null && row( 2 )->fayda_front_image === 'https://example.com/front.jpg' );
EtelID_Files::set_db_copy( false );
$c = img( 'webp', 100 );
add_member( 3, $c['uri'], '' );
EtelID_Files::sync_after_write( 3, array( 'fayda_front_image' => $c['uri'] ) );
check( 'with database copy off the base64 is cleared', row( 3 )->fayda_front_image === '' && EtelID_Files::valid_rel( row( 3 )->fayda_front_file ) );
$h = EtelID_Files::hydrate( row( 3 ) );
check( 'hydrate rebuilds the image from the file', $h->fayda_front_image === $c['uri'] );
EtelID_Files::set_db_copy( true );

echo "migration, verify and cleanup\n";
$GLOBALS['wpdb']->pdo->exec( 'DELETE FROM members' );
$m1 = img( 'jpeg', 300 ); $m2 = img( 'png', 300 ); $m4 = img( 'jpeg', 150 );
add_member( 10, $m1['uri'], $m2['uri'] );
add_member( 11, 'https://example.com/x.jpg', '' );
add_member( 12, 'data:image/jpeg;base64,/9j/BADBASE64!!', '' );
add_member( 13, '', $m4['uri'] );
add_member( 14, '', '' );
$s = EtelID_Files::status();
check( 'status: members with database photos', $s['with_db_photos'] === 3 );
check( 'status: still to copy', $s['to_copy'] === 3 );
$r1 = EtelID_Files::migrate_batch( 2, 0 );
check( 'batch 1 processes two members and moves the cursor', $r1['processed'] === 2 && $r1['last_id'] === 12 );
check( 'batch 1 reports the corrupt photo', count( $r1['failed'] ) === 1 && strpos( $r1['failed'][0], 'member 12' ) !== false );
$r2 = EtelID_Files::migrate_batch( 2, $r1['last_id'] );
check( 'batch 2 finishes with member 13', $r2['processed'] === 1 && $r2['copied'] === 1 );
$r3 = EtelID_Files::migrate_batch( 2, $r2['last_id'] );
check( 'batch 3 has nothing left', $r3['processed'] === 0 );
check( 'migration never touches the database copies', row( 10 )->fayda_front_image === $m1['uri'] && row( 13 )->fayda_back_image === $m4['uri'] );
$v = EtelID_Files::verify_batch( 10, 0 );
check( 'verify: every file matches', count( $v['bad'] ) === 0 && $v['ok'] === 3 );
// tamper with member 10 back, corrupt-check cleanup protection
file_put_contents( $base . '/' . row( 10 )->fayda_back_file, 'bad bytes' );
$v2 = EtelID_Files::verify_batch( 10, 0 );
check( 'verify flags the tampered file', count( $v2['bad'] ) === 1 && strpos( $v2['bad'][0], 'member 10 back' ) !== false );
$cl = EtelID_Files::cleanup_batch( 10, 0 );
check( 'cleanup skips the mismatching photo and keeps it', row( 10 )->fayda_back_image === $m2['uri'] && count( $cl['skipped'] ) === 1 );
check( 'cleanup removes the verified database copies', row( 10 )->fayda_front_image === '' && row( 13 )->fayda_back_image === '' );
check( 'cleanup leaves URL and corrupt entries alone', row( 11 )->fayda_front_image === 'https://example.com/x.jpg' && row( 12 )->fayda_front_image !== '' );
$h10 = EtelID_Files::hydrate( row( 10 ) );
check( 'photo with no database copy still reads from its file', $h10->fayda_front_image === $m1['uri'] );
check( 'a photo skipped by cleanup is still served from its database copy', $h10->fayda_back_image === $m2['uri'] );
file_put_contents( $base . '/' . row( 13 )->fayda_back_file, 'tampered after cleanup' );
check( 'no database copy + tampered file shows nothing, never wrong data', EtelID_Files::hydrate( row( 13 ) )->fayda_back_image === '' );

echo "delete\n";
$dir = $base . '/m10';
check( 'member directory exists before delete', is_dir( $dir ) );
EtelID_Files::delete_member( 10 );
check( 'delete_member removes the directory', ! is_dir( $dir ) );

/* cleanup of the temp tree */
function rrm( $d ) { if ( ! is_dir( $d ) ) return; foreach ( scandir( $d ) as $f ) { if ( $f === '.' || $f === '..' ) continue; $p = $d . '/' . $f; is_dir( $p ) ? rrm( $p ) : @unlink( $p ); } @rmdir( $d ); }
rrm( $tmp );

echo "\n" . $GLOBALS['t_pass'] . " passed, " . $GLOBALS['t_fail'] . " failed\n";
if ( $GLOBALS['t_fail'] > 0 ) exit( 1 );
echo "All EtelID Fayda-file tests passed\n";
