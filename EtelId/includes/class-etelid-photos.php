<?php
/**
 * EtelID - profile photo shrinker.
 *
 * WHY: profile photos were stored as full-size camera images (one was 4.4 MB)
 * as base64 text in members.photo_url, and the portal, nav, card and admin
 * pages put that text straight into the HTML. Every page load for that member
 * therefore carried megabytes. The photo is only ever shown as a small avatar
 * or card picture, so it is resized to at most MAX_PX on the long side and
 * re-encoded as JPEG (normally 20-60 KB; quality steps down if it is bigger). The value stays a data: URI, so
 * every place that already displays photo_url keeps working unchanged.
 *
 * SAFETY RULES (do not weaken):
 *  - shrink() NEVER throws and NEVER loses a photo: on any problem (no GD,
 *    not an image, too large to decode safely, result not smaller) it returns
 *    the input unchanged.
 *  - Only data: URIs for jpeg/png/webp are touched; plain URLs and empty
 *    values pass through untouched.
 *  - The bulk shrink is lossy, so it only runs from the admin page
 *    (etelid-photo-shrink.php) after the admin confirms a backup. It has a
 *    preview mode that writes nothing.
 *
 * PHP 5.6 compatible and pure ASCII (this host's WAF has corrupted
 * non-ASCII bytes in EtelID PHP before).
 */
if ( ! defined( 'ABSPATH' ) && ! defined( 'ETELID_PHOTOS_TEST' ) ) exit;

class EtelID_Photos {

    const MAX_PX      = 480;      /* long side of the stored photo */
    const TARGET_BYTES = 61440;   /* try lower JPEG quality until the picture is this small (or quality 50) */
    const KEEP_BYTES  = 92160;    /* a JPEG already this small (decoded) and within MAX_PX: leave alone, so re-runs never re-compress */
    const SCAN_CHARS  = 122880;   /* admin page treats photo_url longer than this as oversized (about 92 KB of image) */
    const MAX_INPUT   = 12582912; /* refuse to decode more than 12 MB of image data */
    const MAX_PIXELS  = 25000000; /* refuse to decode images above 25 megapixels */

    /** True when this server can resize images. */
    public static function available() {
        return function_exists( 'imagecreatefromstring' ) && function_exists( 'imagejpeg' )
            && function_exists( 'imagecreatetruecolor' ) && function_exists( 'getimagesizefromstring' );
    }

    /**
     * Return a smaller data: URI for a photo, or the input unchanged.
     */
    public static function shrink( $value ) {
        if ( ! is_string( $value ) || strlen( $value ) < 64 ) return $value;
        if ( ! preg_match( '#^data:image/(jpeg|jpg|png|webp);base64,#i', $value, $m ) ) return $value;
        if ( ! self::available() ) return $value;
        $raw = base64_decode( substr( $value, strlen( $m[0] ) ), true );
        if ( false === $raw || '' === $raw || strlen( $raw ) > self::MAX_INPUT ) return $value;

        $info = @getimagesizefromstring( $raw );
        if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) return $value;
        $w = (int) $info[0]; $h = (int) $info[1];
        $mime = isset( $info['mime'] ) ? $info['mime'] : '';
        if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) return $value;
        if ( $w * $h > self::MAX_PIXELS ) return $value;
        if ( 'image/webp' === $mime && ! function_exists( 'imagecreatefromwebp' ) ) return $value;
        if ( strlen( $raw ) <= self::KEEP_BYTES && max( $w, $h ) <= self::MAX_PX && 'image/jpeg' === $mime ) return $value;

        @ini_set( 'memory_limit', '256M' );
        $src = @imagecreatefromstring( $raw );
        if ( ! $src ) return $value;

        /* Honour the camera rotation flag so a portrait photo is not stored sideways. */
        $angle = 0;
        if ( 'image/jpeg' === $mime && function_exists( 'exif_read_data' ) ) {
            $exif = false;
            $tmpf = @tempnam( sys_get_temp_dir(), 'etelph' );
            if ( $tmpf ) {
                if ( false !== @file_put_contents( $tmpf, $raw ) ) $exif = @exif_read_data( $tmpf );
                @unlink( $tmpf );
            }
            if ( is_array( $exif ) && isset( $exif['Orientation'] ) ) {
                $o = (int) $exif['Orientation'];
                if ( 3 === $o ) $angle = 180; elseif ( 6 === $o ) $angle = -90; elseif ( 8 === $o ) $angle = 90;
            }
        }
        if ( $angle && function_exists( 'imagerotate' ) ) {
            $rot = @imagerotate( $src, $angle, 0 );
            if ( $rot ) { imagedestroy( $src ); $src = $rot; $w = imagesx( $src ); $h = imagesy( $src ); }
        }

        $scale = min( 1, self::MAX_PX / max( $w, $h ) );
        $nw = max( 1, (int) round( $w * $scale ) );
        $nh = max( 1, (int) round( $h * $scale ) );
        $dst = @imagecreatetruecolor( $nw, $nh );
        if ( ! $dst ) { imagedestroy( $src ); return $value; }
        /* JPEG has no transparency: flatten onto white. */
        $white = imagecolorallocate( $dst, 255, 255, 255 );
        imagefilledrectangle( $dst, 0, 0, $nw, $nh, $white );
        $ok = @imagecopyresampled( $dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h );
        imagedestroy( $src );
        if ( ! $ok ) { imagedestroy( $dst ); return $value; }

        $jpg = '';
        foreach ( array( 82, 72, 62, 50 ) as $q ) {
            ob_start();
            $wrote = @imagejpeg( $dst, null, $q );
            $jpg = ob_get_clean();
            if ( ! $wrote || ! is_string( $jpg ) || '' === $jpg ) { imagedestroy( $dst ); return $value; }
            if ( strlen( $jpg ) <= self::TARGET_BYTES ) break;
        }
        imagedestroy( $dst );

        $out = 'data:image/jpeg;base64,' . base64_encode( $jpg );
        return strlen( $out ) < strlen( $value ) ? $out : $value;
    }

    /* ---------------------------------------------------------------
     *  Admin page support
     * ------------------------------------------------------------- */

    /** Counts for the admin page. */
    public static function status() {
        global $wpdb;
        $t = EtelID_DB::t_members();
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(photo_url)),0) AS chars FROM {$t} WHERE LENGTH(photo_url) > %d", self::SCAN_CHARS ) );
        $all = $wpdb->get_row( "SELECT COALESCE(SUM(LENGTH(photo_url)),0) AS chars FROM {$t}" );
        return array(
            'members_total'   => $total,
            'oversized'       => $row ? (int) $row->n : 0,
            'oversized_mb'    => $row ? round( $row->chars / 1048576, 2 ) : 0,
            'all_photos_mb'   => $all ? round( $all->chars / 1048576, 2 ) : 0,
            'gd_ready'        => self::available(),
            'max_px'          => self::MAX_PX,
        );
    }

    /**
     * Shrink up to $limit oversized photos with id > $after_id.
     * $write false = preview only (nothing is saved).
     * Returns array( processed, last_id, changed => [text], skipped => [text], saved_kb ).
     */
    public static function shrink_batch( $limit, $after_id, $write ) {
        global $wpdb;
        $t = EtelID_DB::t_members();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, photo_url FROM {$t} WHERE id > %d AND LENGTH(photo_url) > %d ORDER BY id ASC LIMIT %d",
            (int) $after_id, self::SCAN_CHARS, (int) $limit ) );
        $res = array( 'processed' => 0, 'last_id' => (int) $after_id, 'changed' => array(), 'skipped' => array(), 'saved_kb' => 0 );
        if ( ! is_array( $rows ) ) return $res;
        foreach ( $rows as $r ) {
            $res['processed']++;
            $res['last_id'] = (int) $r->id;
            $before = strlen( $r->photo_url );
            $new = self::shrink( $r->photo_url );
            if ( $new === $r->photo_url ) {
                $res['skipped'][] = 'member ' . (int) $r->id . ': left as is (' . round( $before / 1024 ) . ' KB, not shrinkable)';
                continue;
            }
            if ( $write ) {
                $ok = $wpdb->update( $t, array( 'photo_url' => $new ), array( 'id' => (int) $r->id ) );
                if ( false === $ok ) { $res['skipped'][] = 'member ' . (int) $r->id . ': database write failed'; continue; }
            }
            $res['saved_kb'] += (int) round( ( $before - strlen( $new ) ) / 1024 );
            $res['changed'][] = 'member ' . (int) $r->id . ': ' . round( $before / 1024 ) . ' KB -> ' . round( strlen( $new ) / 1024 ) . ' KB';
        }
        return $res;
    }
}
