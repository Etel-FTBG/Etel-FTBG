<?php
// Image helpers shared by api/upload.php (kept in a lib file so tests can load them without running the upload endpoint).

/* Resizes $path in place if its long side exceeds $maxSide. For JPEG
   input (the overwhelming majority of real uploads - phone cameras and
   camera-roll pickers almost universally save as JPEG), also re-encodes
   at $quality for a much bigger size cut. For PNG/GIF/WEBP input, only
   resizes and re-saves in the SAME format - deliberately not converting
   those to JPEG here, since that would leave a .png file containing
   JPEG bytes (an extension/content mismatch that browsers would likely
   paper over via content-sniffing, but isn't worth the risk for a size
   optimization). Silently does nothing if GD is missing or anything
   goes wrong - never lets a compression failure break the upload. */
/* Rotation (for imagerotate) that makes a JPEG upright from its EXIF Orientation tag: 0 = already upright / unknown.
   Phone cameras store portrait photos sideways and record the turn in EXIF; re-encoding with GD drops that tag,
   so without this a portrait photo shows lying on its side. Orientation 3 = upside down, 6 = needs 90 clockwise,
   8 = needs 90 counter-clockwise (imagerotate turns counter-clockwise, so 6 is -90). Mirrored values are ignored. */
function up_orientation_to_rotation($orientation) {
    switch ((int) $orientation) {
        case 3: return 180;
        case 6: return -90;
        case 8: return 90;
    }
    return 0;
}
function up_exif_rotation($path) {
    if (!function_exists('exif_read_data')) return 0;
    $e = @exif_read_data($path);
    return (is_array($e) && isset($e['Orientation'])) ? up_orientation_to_rotation($e['Orientation']) : 0;
}

function up_compress_image($path, $mime, $maxSide = 1600, $quality = 82) {
    if (!function_exists('imagecreatetruecolor') || !function_exists('getimagesize')) return;
    try {
        $info = @getimagesize($path);
        if (!$info) return;
        list($w, $h) = $info;
        if ($w <= 0 || $h <= 0) return;

        switch ($mime) {
            case 'image/jpeg': $src = @imagecreatefromjpeg($path); break;
            case 'image/png':  $src = @imagecreatefrompng($path);  break;
            case 'image/gif':  $src = @imagecreatefromgif($path);  break;
            case 'image/webp': $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false; break;
            default: return;
        }
        if (!$src) return;

        // Turn a sideways phone photo upright before the EXIF tag is lost (see up_orientation_to_rotation).
        $rotated = false;
        if ($mime === 'image/jpeg') {
            $rot = up_exif_rotation($path);
            if ($rot !== 0 && function_exists('imagerotate')) {
                $r = @imagerotate($src, $rot, 0);
                if ($r) {
                    imagedestroy($src); $src = $r; $rotated = true;
                    if ($rot === 90 || $rot === -90) { $tmpw = $w; $w = $h; $h = $tmpw; }
                }
            }
        }

        $longSide = max($w, $h);
        $needsResize = $longSide > $maxSide;
        // For non-JPEG formats, only bother if a resize is actually
        // needed - re-saving an already-small PNG through GD often makes
        // it BIGGER (GD's PNG encoder isn't as good as most phone/photo
        // apps'), so skip entirely rather than risk that.
        if ($mime !== 'image/jpeg' && !$needsResize) { imagedestroy($src); return; }

        $scale = $needsResize ? ($maxSide / $longSide) : 1.0;
        $nw = $needsResize ? max(1, (int) round($w * $scale)) : $w;
        $nh = $needsResize ? max(1, (int) round($h * $scale)) : $h;

        $out = imagecreatetruecolor($nw, $nh);
        if ($mime === 'image/png' || $mime === 'image/webp' || $mime === 'image/gif') {
            // Preserve transparency for formats that support it, instead
            // of flattening to white - these might not all be plain
            // photos (e.g. a logo asset uploaded through the same path).
            imagealphablending($out, false);
            imagesavealpha($out, true);
            $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
            imagefilledrectangle($out, 0, 0, $nw, $nh, $transparent);
        }
        imagecopyresampled($out, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        $tmp = $path . '.tmp';
        $ok = false;
        if ($mime === 'image/jpeg') {
            $ok = @imagejpeg($out, $tmp, $quality);
        } elseif ($mime === 'image/png') {
            $ok = @imagepng($out, $tmp, 6);
        } elseif ($mime === 'image/gif') {
            $ok = @imagegif($out, $tmp);
        } elseif ($mime === 'image/webp' && function_exists('imagewebp')) {
            $ok = @imagewebp($out, $tmp, $quality);
        }
        imagedestroy($out);

        if ($ok && filesize($tmp) > 0 && ($rotated || filesize($tmp) < filesize($path))) {
            // Only replace the original if the result is actually
            // smaller - never let this step make a file bigger.
            // (A photo that had to be turned upright is always replaced.)
            @rename($tmp, $path);
        } else {
            @unlink($tmp);
        }
    } catch (Exception $e) {
        error_log('[ETEL][upload] compress failed: ' . $e->getMessage());
    }
}
