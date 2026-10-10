<?php
/**
 * EtelID - private file storage for Fayda national-ID photos.
 *
 * WHY: the front/back ID photos used to live as base64 text inside the
 * members table (about 4 MB per member), which made every database read
 * heavy, bloated every backup, and put national-ID images in a row that
 * lots of code paths read. They now live as files in a private folder, and
 * the database keeps only a relative file name plus a SHA-256 of the bytes.
 *
 * SAFETY RULES (do not weaken):
 *  - Additive first: nothing here deletes a database photo except
 *    cleanup_batch(), which only runs from the admin migration page after the
 *    admin confirms a backup, and only for photos whose file was re-read and
 *    matched the SHA-256 of the database copy.
 *  - A failed file write NEVER loses the photo: the database copy is kept.
 *  - Files are never served by URL. Reads go through EtelID_DB::get_member_full()
 *    (admin only). The folder also carries a deny-all .htaccess.
 *  - Only jpeg/png/webp with matching magic bytes, max 10 MB, are accepted.
 *
 * PHP 5.6 compatible and pure ASCII (this host's WAF has corrupted
 * non-ASCII bytes in EtelID PHP before).
 */
if ( ! defined( 'ABSPATH' ) && ! defined( 'ETELID_FILES_TEST' ) ) exit;

class EtelID_Files {

    const MAX_BYTES = 10485760;

    private static $types = array(
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    );

    private static $cols_ready = null;

    /* ---------------------------------------------------------------
     *  Locations and validation
     * ------------------------------------------------------------- */

    /** Absolute private directory (created and protected on first use), or false. */
    public static function base_dir() {
        if ( defined( 'ETELID_PRIVATE_DIR' ) && ETELID_PRIVATE_DIR ) {
            $dir = rtrim( ETELID_PRIVATE_DIR, '/\\' );
        } elseif ( defined( 'WP_CONTENT_DIR' ) ) {
            $dir = rtrim( WP_CONTENT_DIR, '/\\' ) . '/etelid-private';
        } else {
            return false;
        }
        if ( ! is_dir( $dir ) ) {
            if ( ! @mkdir( $dir, 0750, true ) && ! is_dir( $dir ) ) return false;
        }
        self::protect( $dir );
        return $dir;
    }

    private static function protect( $dir ) {
        $ht = $dir . '/.htaccess';
        if ( ! file_exists( $ht ) ) {
            @file_put_contents( $ht,
                "# EtelID private ID photos - never serve directly\n" .
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" );
        }
        $ix = $dir . '/index.html';
        if ( ! file_exists( $ix ) ) @file_put_contents( $ix, '' );
    }

    /** True when $rel looks like m<id>/front-<16hex>.jpg|png|webp (no traversal possible). */
    public static function valid_rel( $rel ) {
        return is_string( $rel ) && preg_match( '#^m[0-9]{1,12}/(front|back)-[a-f0-9]{16}\.(jpg|png|webp)$#', $rel ) === 1;
    }

    private static function path_for( $rel ) {
        if ( ! self::valid_rel( $rel ) ) return false;
        $base = self::base_dir();
        return $base ? $base . '/' . $rel : false;
    }

    private static function rand_hex( $bytes ) {
        if ( function_exists( 'random_bytes' ) ) return bin2hex( random_bytes( $bytes ) );
        $b = openssl_random_pseudo_bytes( $bytes );
        return bin2hex( $b );
    }

    private static function magic_ok( $mime, $b ) {
        if ( $mime === 'image/jpeg' ) return substr( $b, 0, 3 ) === "\xFF\xD8\xFF";
        if ( $mime === 'image/png' )  return substr( $b, 0, 8 ) === "\x89PNG\r\n\x1a\n";
        if ( $mime === 'image/webp' ) return substr( $b, 0, 4 ) === 'RIFF' && substr( $b, 8, 4 ) === 'WEBP';
        return false;
    }

    /** Decode a data:image/...;base64,... string. Returns array(mime, ext, bytes) or null. */
    public static function parse_data_uri( $s ) {
        if ( ! is_string( $s ) || strpos( $s, 'data:image/' ) !== 0 ) return null;
        $comma = strpos( $s, ',' );
        if ( $comma === false || $comma > 64 ) return null;
        $meta = substr( $s, 5, $comma - 5 );
        if ( substr( $meta, -7 ) !== ';base64' ) return null;
        $mime = strtolower( substr( $meta, 0, -7 ) );
        if ( ! isset( self::$types[ $mime ] ) ) return null;
        $bytes = base64_decode( substr( $s, $comma + 1 ), true );
        if ( $bytes === false || $bytes === '' || strlen( $bytes ) > self::MAX_BYTES ) return null;
        if ( ! self::magic_ok( $mime, $bytes ) ) return null;
        return array( 'mime' => $mime, 'ext' => self::$types[ $mime ], 'bytes' => $bytes );
    }

    /* ---------------------------------------------------------------
     *  File operations
     * ------------------------------------------------------------- */

    /** Write a photo file for a member. Returns array(rel, sha) after a read-back check, or false. */
    public static function store( $member_id, $side, $parsed ) {
        $member_id = (int) $member_id;
        if ( $member_id < 1 || ! in_array( $side, array( 'front', 'back' ), true ) || ! is_array( $parsed ) ) return false;
        $base = self::base_dir();
        if ( ! $base ) return false;
        $sub = 'm' . $member_id;
        $dir = $base . '/' . $sub;
        if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0750, true ) && ! is_dir( $dir ) ) return false;
        $rel  = $sub . '/' . $side . '-' . self::rand_hex( 8 ) . '.' . $parsed['ext'];
        $path = $base . '/' . $rel;
        $tmp  = $path . '.tmp';
        if ( @file_put_contents( $tmp, $parsed['bytes'], LOCK_EX ) === false ) { @unlink( $tmp ); return false; }
        @chmod( $tmp, 0640 );
        if ( ! @rename( $tmp, $path ) ) { @unlink( $tmp ); return false; }
        $sha  = hash( 'sha256', $parsed['bytes'] );
        $back = @file_get_contents( $path );
        if ( $back === false || ! hash_equals( $sha, hash( 'sha256', $back ) ) ) { @unlink( $path ); return false; }
        return array( 'rel' => $rel, 'sha' => $sha );
    }

    /** File bytes, but only when they still match $sha (tamper / corruption check). */
    public static function read( $rel, $sha ) {
        $path = self::path_for( $rel );
        if ( ! $path || ! is_file( $path ) ) return false;
        $bytes = @file_get_contents( $path );
        if ( $bytes === false ) return false;
        if ( ! is_string( $sha ) || strlen( $sha ) !== 64 || ! hash_equals( $sha, hash( 'sha256', $bytes ) ) ) return false;
        return $bytes;
    }

    /** data: URI for the admin screen, or '' when the file is missing or fails its SHA check. */
    public static function data_uri( $rel, $sha ) {
        $bytes = self::read( $rel, $sha );
        if ( $bytes === false ) return '';
        $ext  = substr( $rel, strrpos( $rel, '.' ) + 1 );
        $mime = $ext === 'png' ? 'image/png' : ( $ext === 'webp' ? 'image/webp' : 'image/jpeg' );
        return 'data:' . $mime . ';base64,' . base64_encode( $bytes );
    }

    public static function delete_file( $rel ) {
        $path = self::path_for( $rel );
        if ( $path && is_file( $path ) ) @unlink( $path );
    }

    /** Remove every file of a member (used when the member is deleted). */
    public static function delete_member( $member_id ) {
        $member_id = (int) $member_id;
        if ( $member_id < 1 ) return;
        $base = self::base_dir();
        if ( ! $base ) return;
        $dir = $base . '/m' . $member_id;
        if ( ! is_dir( $dir ) ) return;
        $list = @scandir( $dir );
        if ( is_array( $list ) ) {
            foreach ( $list as $f ) {
                if ( $f === '.' || $f === '..' ) continue;
                @unlink( $dir . '/' . $f );
            }
        }
        @rmdir( $dir );
    }

    /* ---------------------------------------------------------------
     *  Database side
     * ------------------------------------------------------------- */

    /** True once the four new columns exist (schema upgrade has run). */
    public static function columns_ready() {
        if ( self::$cols_ready !== null ) return self::$cols_ready;
        global $wpdb;
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM " . EtelID_DB::t_members() );
        self::$cols_ready = is_array( $cols ) && in_array( 'fayda_front_file', $cols, true ) && in_array( 'fayda_back_file', $cols, true )
            && in_array( 'fayda_front_sha', $cols, true ) && in_array( 'fayda_back_sha', $cols, true );
        return self::$cols_ready;
    }

    /** Should new uploads ALSO keep the base64 copy in the database? (default yes until cut over) */
    public static function db_copy() {
        if ( defined( 'ETELID_FAYDA_DB_COPY' ) && ! ETELID_FAYDA_DB_COPY ) return false;
        return get_option( 'etelid_fayda_db_copy', '1' ) !== '0';
    }

    /**
     * Called by EtelID_DB after a member row was written. If the write carried
     * new Fayda photos as data URIs, store them as files and record file + sha.
     * Anything that is not a valid data URI (e.g. an attachment URL) or any
     * failure leaves the database copy exactly as it was.
     */
    public static function sync_after_write( $member_id, $data ) {
        if ( ! is_array( $data ) ) return;
        /* Cheap exit first: most member writes carry no photos (and must not cost a schema query). */
        if ( empty( $data['fayda_front_image'] ) && empty( $data['fayda_back_image'] ) ) return;
        if ( ! self::columns_ready() ) return;
        global $wpdb;
        $t   = EtelID_DB::t_members();
        $upd = array();
        $old = array();
        foreach ( array( 'front', 'back' ) as $side ) {
            $key = 'fayda_' . $side . '_image';
            if ( empty( $data[ $key ] ) || ! is_string( $data[ $key ] ) ) continue;
            $parsed = self::parse_data_uri( $data[ $key ] );
            if ( ! $parsed ) continue;
            $res = self::store( $member_id, $side, $parsed );
            if ( ! $res ) {
                error_log( '[EtelID] could not store the Fayda ' . $side . ' photo as a file for member ' . (int) $member_id . '; it stays in the database' );
                continue;
            }
            $old[ $side ] = $wpdb->get_var( $wpdb->prepare( "SELECT fayda_{$side}_file FROM {$t} WHERE id = %d", (int) $member_id ) );
            $upd[ 'fayda_' . $side . '_file' ] = $res['rel'];
            $upd[ 'fayda_' . $side . '_sha' ]  = $res['sha'];
            if ( ! self::db_copy() ) $upd[ $key ] = '';
        }
        if ( empty( $upd ) ) return;
        $ok = $wpdb->update( $t, $upd, array( 'id' => (int) $member_id ) );
        if ( $ok === false ) {
            error_log( '[EtelID] could not record Fayda photo files for member ' . (int) $member_id );
            return;
        }
        foreach ( $old as $side => $rel ) {
            if ( $rel && $rel !== $upd[ 'fayda_' . $side . '_file' ] ) self::delete_file( $rel );
        }
    }

    /** Fill fayda_*_image on a full member row from files when the database copy is empty. */
    public static function hydrate( $row ) {
        if ( ! is_object( $row ) ) return $row;
        foreach ( array( 'front', 'back' ) as $side ) {
            $img  = 'fayda_' . $side . '_image';
            $file = 'fayda_' . $side . '_file';
            $sha  = 'fayda_' . $side . '_sha';
            if ( ! empty( $row->$img ) ) continue;
            if ( empty( $row->$file ) || empty( $row->$sha ) ) continue;
            $uri = self::data_uri( $row->$file, $row->$sha );
            if ( $uri === '' ) {
                error_log( '[EtelID] Fayda ' . $side . ' photo file for member ' . ( isset( $row->id ) ? (int) $row->id : 0 ) . ' is missing or failed its integrity check' );
                continue;
            }
            $row->$img = $uri;
        }
        return $row;
    }

    /* ---------------------------------------------------------------
     *  Migration (driven by etelid-fayda-migrate.php, admin only)
     * ------------------------------------------------------------- */

    /** Counts for the migration page. */
    public static function status() {
        global $wpdb;
        $t = EtelID_DB::t_members();
        $has = "(fayda_front_image LIKE 'data:image/%' OR fayda_back_image LIKE 'data:image/%')";
        $s = array( 'columns_ready' => self::columns_ready() );
        $s['members_total']    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
        $s['with_db_photos']   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$has}" );
        $s['db_photo_mb']      = round( (float) $wpdb->get_var( "SELECT SUM(IFNULL(LENGTH(fayda_front_image),0) + IFNULL(LENGTH(fayda_back_image),0)) FROM {$t}" ) / 1048576, 1 );
        $s['db_copy_on']       = self::db_copy();
        $s['dir_ok']           = (bool) self::base_dir();
        if ( ! $s['columns_ready'] ) return $s;
        $s['to_copy'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE
            (fayda_front_image LIKE 'data:image/%' AND (fayda_front_file IS NULL OR fayda_front_file = ''))
            OR (fayda_back_image LIKE 'data:image/%' AND (fayda_back_file IS NULL OR fayda_back_file = ''))" );
        $s['to_clean'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE
            (fayda_front_image LIKE 'data:image/%' AND fayda_front_file <> '')
            OR (fayda_back_image LIKE 'data:image/%' AND fayda_back_file <> '')" );
        $s['files_recorded']   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE fayda_front_file <> '' OR fayda_back_file <> ''" );
        return $s;
    }

    /** Copy photos to files (database copy untouched). Cursor = after_id. */
    public static function migrate_batch( $limit, $after_id ) {
        global $wpdb;
        $t = EtelID_DB::t_members();
        $out = array( 'processed' => 0, 'copied' => 0, 'failed' => array(), 'last_id' => (int) $after_id );
        if ( ! self::columns_ready() ) { $out['failed'][] = 'columns missing - open the EtelID portal once so the schema upgrade runs'; return $out; }
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE id > %d AND (
            (fayda_front_image LIKE 'data:image/%%' AND (fayda_front_file IS NULL OR fayda_front_file = ''))
            OR (fayda_back_image LIKE 'data:image/%%' AND (fayda_back_file IS NULL OR fayda_back_file = '')) )
            ORDER BY id ASC LIMIT %d", (int) $after_id, max( 1, (int) $limit ) ) );
        foreach ( (array) $ids as $id ) {
            $id  = (int) $id;
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT id, fayda_front_image, fayda_back_image, fayda_front_file, fayda_back_file FROM {$t} WHERE id = %d", $id ) );
            $out['processed']++;
            $out['last_id'] = $id;
            if ( ! $row ) continue;
            $upd = array();
            foreach ( array( 'front', 'back' ) as $side ) {
                $img  = 'fayda_' . $side . '_image';
                $file = 'fayda_' . $side . '_file';
                if ( empty( $row->$img ) || strpos( $row->$img, 'data:image/' ) !== 0 ) continue;
                if ( ! empty( $row->$file ) ) continue;
                $parsed = self::parse_data_uri( $row->$img );
                if ( ! $parsed ) { $out['failed'][] = 'member ' . $id . ' ' . $side . ': not a valid jpeg/png/webp data image'; continue; }
                $res = self::store( $id, $side, $parsed );
                if ( ! $res ) { $out['failed'][] = 'member ' . $id . ' ' . $side . ': could not write/verify the file'; continue; }
                $upd[ $file ] = $res['rel'];
                $upd[ 'fayda_' . $side . '_sha' ] = $res['sha'];
                $out['copied']++;
            }
            if ( ! empty( $upd ) ) {
                if ( $wpdb->update( $t, $upd, array( 'id' => $id ) ) === false ) $out['failed'][] = 'member ' . $id . ': could not save file names';
            }
        }
        return $out;
    }

    /**
     * READ-ONLY check before any cleanup: every recorded file must exist and match
     * its SHA-256, and (while the database copy still exists) equal that copy too.
     */
    public static function verify_batch( $limit, $after_id ) {
        global $wpdb;
        $t = EtelID_DB::t_members();
        $out = array( 'processed' => 0, 'ok' => 0, 'bad' => array(), 'last_id' => (int) $after_id );
        if ( ! self::columns_ready() ) { $out['bad'][] = 'columns missing'; return $out; }
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE id > %d AND (
            (fayda_front_file IS NOT NULL AND fayda_front_file <> '') OR (fayda_back_file IS NOT NULL AND fayda_back_file <> '') )
            ORDER BY id ASC LIMIT %d", (int) $after_id, max( 1, (int) $limit ) ) );
        foreach ( (array) $ids as $id ) {
            $id  = (int) $id;
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT id, fayda_front_image, fayda_back_image, fayda_front_file, fayda_back_file, fayda_front_sha, fayda_back_sha FROM {$t} WHERE id = %d", $id ) );
            $out['processed']++;
            $out['last_id'] = $id;
            if ( ! $row ) continue;
            foreach ( array( 'front', 'back' ) as $side ) {
                $img  = 'fayda_' . $side . '_image';
                $file = 'fayda_' . $side . '_file';
                $sha  = 'fayda_' . $side . '_sha';
                if ( empty( $row->$file ) ) continue;
                $bytes = self::read( $row->$file, $row->$sha );
                if ( $bytes === false ) { $out['bad'][] = 'member ' . $id . ' ' . $side . ': file missing or changed'; continue; }
                if ( ! empty( $row->$img ) ) {
                    $parsed = self::parse_data_uri( $row->$img );
                    if ( $parsed && ! hash_equals( hash( 'sha256', $parsed['bytes'] ), hash( 'sha256', $bytes ) ) ) {
                        $out['bad'][] = 'member ' . $id . ' ' . $side . ': file differs from the database copy';
                        continue;
                    }
                }
                $out['ok']++;
            }
        }
        return $out;
    }

    /**
     * Remove the base64 copy from the database, ONLY where the file re-reads
     * and its SHA-256 equals both the stored sha and the sha of the database copy.
     */
    public static function cleanup_batch( $limit, $after_id ) {
        global $wpdb;
        $t = EtelID_DB::t_members();
        $out = array( 'processed' => 0, 'cleaned' => 0, 'skipped' => array(), 'last_id' => (int) $after_id );
        if ( ! self::columns_ready() ) { $out['skipped'][] = 'columns missing'; return $out; }
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE id > %d AND (
            (fayda_front_image LIKE 'data:image/%%' AND fayda_front_file <> '')
            OR (fayda_back_image LIKE 'data:image/%%' AND fayda_back_file <> '') )
            ORDER BY id ASC LIMIT %d", (int) $after_id, max( 1, (int) $limit ) ) );
        foreach ( (array) $ids as $id ) {
            $id  = (int) $id;
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT id, fayda_front_image, fayda_back_image, fayda_front_file, fayda_back_file, fayda_front_sha, fayda_back_sha FROM {$t} WHERE id = %d", $id ) );
            $out['processed']++;
            $out['last_id'] = $id;
            if ( ! $row ) continue;
            $upd = array();
            foreach ( array( 'front', 'back' ) as $side ) {
                $img  = 'fayda_' . $side . '_image';
                $file = 'fayda_' . $side . '_file';
                $sha  = 'fayda_' . $side . '_sha';
                if ( empty( $row->$img ) || strpos( $row->$img, 'data:image/' ) !== 0 || empty( $row->$file ) ) continue;
                $parsed = self::parse_data_uri( $row->$img );
                $bytes  = self::read( $row->$file, $row->$sha );
                if ( ! $parsed || $bytes === false || ! hash_equals( hash( 'sha256', $parsed['bytes'] ), hash( 'sha256', $bytes ) ) ) {
                    $out['skipped'][] = 'member ' . $id . ' ' . $side . ': file does not match the database copy - kept in database';
                    continue;
                }
                $upd[ $img ] = '';
            }
            if ( ! empty( $upd ) ) {
                if ( $wpdb->update( $t, $upd, array( 'id' => $id ) ) === false ) { $out['skipped'][] = 'member ' . $id . ': update failed'; continue; }
                $out['cleaned'] += count( $upd );
            }
        }
        return $out;
    }

    /** Stop keeping a database copy of NEW uploads (call only after cleanup finished). */
    public static function set_db_copy( $on ) {
        update_option( 'etelid_fayda_db_copy', $on ? '1' : '0' );
    }
}
