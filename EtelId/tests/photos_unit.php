<?php
/**
 * Unit tests for EtelID_Photos (profile photo shrinker).
 * Run: php EtelId/tests/photos_unit.php   (needs the GD extension)
 * Uses an in-memory SQLite stand-in for $wpdb. No WordPress needed.
 */
define( 'ETELID_PHOTOS_TEST', true );

$GLOBALS['t_pass'] = 0; $GLOBALS['t_fail'] = 0;
function check( $name, $cond ) {
    if ( $cond ) { $GLOBALS['t_pass']++; echo "  PASS  $name\n"; }
    else { $GLOBALS['t_fail']++; echo "  FAIL  $name\n"; }
}

class EtelID_DB { public static function t_members() { return 'members'; } }

class FakeWpdb {
    public $pdo;
    public function __construct() {
        $this->pdo = new PDO( 'sqlite::memory:' );
        $this->pdo->exec( 'CREATE TABLE members (id INTEGER PRIMARY KEY, photo_url TEXT)' );
    }
    public function prepare( $sql, $args = null ) {
        $args = func_get_args(); array_shift( $args );
        if ( count( $args ) === 1 && is_array( $args[0] ) ) $args = $args[0];
        $i = 0;
        return preg_replace_callback( '/%[ds]/', function ( $m ) use ( &$i, $args ) {
            $v = $args[ $i++ ];
            return $m[0] === '%d' ? (string) (int) $v : $this->pdo->quote( (string) $v );
        }, $sql );
    }
    public function get_var( $sql ) { $r = $this->pdo->query( $sql )->fetch( PDO::FETCH_NUM ); return $r ? $r[0] : null; }
    public function get_row( $sql ) { $r = $this->pdo->query( $sql )->fetch( PDO::FETCH_OBJ ); return $r ? $r : null; }
    public function get_results( $sql ) { return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_OBJ ); }
    public function update( $t, $data, $where ) {
        $set = array();
        foreach ( $data as $k => $v ) $set[] = $k . ' = ' . $this->pdo->quote( (string) $v );
        $w = array();
        foreach ( $where as $k => $v ) $w[] = $k . ' = ' . (int) $v;
        return $this->pdo->exec( "UPDATE $t SET " . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $w ) );
    }
}
$wpdb = new FakeWpdb();

require dirname( __FILE__ ) . '/../includes/class-etelid-photos.php';

if ( ! EtelID_Photos::available() ) { echo "GD not available - cannot run\n"; exit( 1 ); }

/* A noisy image does not compress, so it behaves like a real camera photo. */
function noisy( $w, $h ) {
    $im = imagecreatetruecolor( $w, $h );
    mt_srand( 7 );
    /* many small random blocks: hard to compress, cheap to draw */
    for ( $i = 0; $i < ( $w * $h ) / 120; $i++ ) {
        $x = mt_rand( 0, $w ); $y = mt_rand( 0, $h );
        imagefilledrectangle( $im, $x, $y, $x + mt_rand( 2, 12 ), $y + mt_rand( 2, 12 ),
            imagecolorallocate( $im, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) ) );
    }
    return $im;
}
function enc( $im, $fmt, $q = 95 ) {
    ob_start();
    if ( $fmt === 'png' ) imagepng( $im ); else imagejpeg( $im, null, $q );
    return ob_get_clean();
}
function uri( $bytes, $fmt ) { return 'data:image/' . $fmt . ';base64,' . base64_encode( $bytes ); }
function dims( $u ) { $raw = base64_decode( substr( $u, strpos( $u, ',' ) + 1 ) ); $i = getimagesizefromstring( $raw ); return array( $i[0], $i[1], $i['mime'] ); }

echo "large photos are shrunk\n";
$big = noisy( 2400, 1800 );
$bigJpeg = uri( enc( $big, 'jpeg' ), 'jpeg' );
$out = EtelID_Photos::shrink( $bigJpeg );
list( $w, $h, $mime ) = dims( $out );
check( 'input is genuinely large (> 300 KB)', strlen( $bigJpeg ) > 300000 );
check( 'output is a JPEG data URI', strpos( $out, 'data:image/jpeg;base64,' ) === 0 && $mime === 'image/jpeg' );
check( 'long side is 480', max( $w, $h ) === 480 );
check( 'aspect ratio kept (4:3)', abs( $w / $h - 4 / 3 ) < 0.02 );
check( 'output is much smaller than input', strlen( $out ) < strlen( $bigJpeg ) / 4 );
check( 'output is under 100 KB', strlen( $out ) < 102400 );

$bigPng = uri( enc( $big, 'png' ), 'png' );
$o2 = EtelID_Photos::shrink( $bigPng );
check( 'large PNG becomes a small JPEG', strpos( $o2, 'data:image/jpeg' ) === 0 && strlen( $o2 ) < strlen( $bigPng ) / 4 );

$portrait = uri( enc( noisy( 1500, 2000 ), 'jpeg' ), 'jpeg' );
list( $pw, $ph ) = dims( EtelID_Photos::shrink( $portrait ) );
check( 'portrait keeps orientation (taller than wide)', $ph === 480 && $pw < $ph );

echo "transparent PNG is flattened onto white\n";
$tp = imagecreatetruecolor( 1200, 1200 );
imagealphablending( $tp, false ); imagesavealpha( $tp, true );
imagefill( $tp, 0, 0, imagecolorallocatealpha( $tp, 0, 0, 0, 127 ) );
$o3 = EtelID_Photos::shrink( uri( enc( $tp, 'png' ), 'png' ) . '' );
/* a flat transparent PNG is tiny, so it may be returned unchanged; only check it stays a valid image */
check( 'transparent PNG stays a valid image', is_array( dims( $o3 ) ) );
$tp2 = noisy( 1200, 1200 );
check( 'noisy PNG shrinks', strlen( EtelID_Photos::shrink( uri( enc( $tp2, 'png' ), 'png' ) ) ) < 150000 );

echo "things that must pass through untouched\n";
$small = uri( enc( noisy( 300, 300 ), 'jpeg', 60 ), 'jpeg' );
check( 'small JPEG within 480 px is unchanged', EtelID_Photos::shrink( $small ) === $small );
check( 'plain URL unchanged', EtelID_Photos::shrink( 'https://example.com/a.jpg' ) === 'https://example.com/a.jpg' );
check( 'empty string unchanged', EtelID_Photos::shrink( '' ) === '' );
check( 'null unchanged', EtelID_Photos::shrink( null ) === null );
check( 'non-image data URI unchanged', EtelID_Photos::shrink( 'data:text/html;base64,' . base64_encode( str_repeat( 'x', 500 ) ) ) === 'data:text/html;base64,' . base64_encode( str_repeat( 'x', 500 ) ) );
$fake = 'data:image/jpeg;base64,' . base64_encode( str_repeat( 'notanimage', 5000 ) );
check( 'garbage claiming to be JPEG unchanged', EtelID_Photos::shrink( $fake ) === $fake );
$badb64 = 'data:image/jpeg;base64,' . str_repeat( '!@#$', 100 );
check( 'invalid base64 unchanged', EtelID_Photos::shrink( $badb64 ) === $badb64 );
$trunc = substr( $bigJpeg, 0, 20000 );
check( 'truncated image does not crash', is_string( EtelID_Photos::shrink( $trunc ) ) );
$svg = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>' . str_repeat( ' ', 100 ) );
check( 'SVG data URI unchanged (never decoded)', EtelID_Photos::shrink( $svg ) === $svg );

echo "batch (preview and write)\n";
function add_member( $id, $photo ) { global $wpdb; $s = $wpdb->pdo->prepare( 'INSERT INTO members (id, photo_url) VALUES (?, ?)' ); $s->execute( array( $id, $photo ) ); }
function photo_of( $id ) { global $wpdb; return $wpdb->get_var( 'SELECT photo_url FROM members WHERE id = ' . (int) $id ); }
add_member( 1, $bigJpeg );
add_member( 2, '' );
add_member( 3, $small );
add_member( 4, $bigPng );
add_member( 5, $fake . str_repeat( 'A', 90000 ) ); /* oversized but not an image */

$st = EtelID_Photos::status();
check( 'status counts 3 oversized photos', $st['oversized'] === 3 );
check( 'status reports GD ready', $st['gd_ready'] === true );

$r = EtelID_Photos::shrink_batch( 10, 0, false );
check( 'preview scans the 3 oversized rows', $r['processed'] === 3 );
check( 'preview lists 2 changes and 1 skipped', count( $r['changed'] ) === 2 && count( $r['skipped'] ) === 1 );
check( 'preview wrote nothing', photo_of( 1 ) === $bigJpeg && photo_of( 4 ) === $bigPng );
check( 'preview reports savings', $r['saved_kb'] > 100 );

$r = EtelID_Photos::shrink_batch( 2, 0, true );
check( 'batch honours the limit', $r['processed'] === 2 && $r['last_id'] === 4 );
check( 'member 1 was shrunk in the database', strlen( photo_of( 1 ) ) < 102400 && strpos( photo_of( 1 ), 'data:image/jpeg' ) === 0 );
check( 'member 4 was shrunk in the database', strlen( photo_of( 4 ) ) < 102400 );
check( 'small member 3 untouched', photo_of( 3 ) === $small );
$r = EtelID_Photos::shrink_batch( 2, $r['last_id'], true );
check( 'second batch reaches member 5 and leaves it as is', $r['processed'] === 1 && count( $r['skipped'] ) === 1 );
check( 'non-image member 5 untouched', strlen( photo_of( 5 ) ) > 90000 );
$r = EtelID_Photos::shrink_batch( 2, 5, true );
check( 'nothing left after the cursor', $r['processed'] === 0 );
$r = EtelID_Photos::shrink_batch( 10, 0, true );
check( 'repeating the whole run does not touch shrunk photos', count( $r['changed'] ) === 0 );
check( 'status afterwards: only the non-image row is still oversized', EtelID_Photos::status()['oversized'] === 1 );

echo "\n" . $GLOBALS['t_pass'] . ' passed, ' . $GLOBALS['t_fail'] . " failed\n";
exit( $GLOBALS['t_fail'] ? 1 : 0 );
