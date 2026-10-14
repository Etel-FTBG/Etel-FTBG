<?php
// Uploaded portrait photos must not come out lying on their side.
// Phone photos carry an EXIF Orientation tag; re-encoding with GD drops it, so api/lib/image_tools.php turns the picture upright first.
// Needs the GD and exif PHP extensions; SKIPs (exit 0) when they are missing. No database.
require_once __DIR__ . '/../api/lib/image_tools.php';

$fails = 0;
function check($name, $cond) { global $fails; echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n"; if (!$cond) $fails++; }

echo "image orientation\n";
check('orientation 1 or unknown = no turn', up_orientation_to_rotation(1) === 0 && up_orientation_to_rotation(0) === 0 && up_orientation_to_rotation('x') === 0);
check('orientation 3 = upside down = 180', up_orientation_to_rotation(3) === 180);
check('orientation 6 = turn 90 clockwise = -90 for imagerotate', up_orientation_to_rotation(6) === -90);
check('orientation 8 = turn 90 counter-clockwise = 90', up_orientation_to_rotation(8) === 90);
check('mirrored values are left alone', up_orientation_to_rotation(2) === 0 && up_orientation_to_rotation(5) === 0 && up_orientation_to_rotation(7) === 0);

if (!function_exists('imagecreatetruecolor') || !function_exists('exif_read_data') || !function_exists('imagerotate')) {
    echo "SKIP: GD/exif not available, file tests not run\n";
    echo $fails ? "FAILED: $fails\n" : "ALL PASSED\n"; exit($fails ? 1 : 0);
}

// 40x20 landscape JPEG: left half red, right half blue
function make_jpeg($path, $orientation) {
    $im = imagecreatetruecolor(40, 20);
    imagefilledrectangle($im, 0, 0, 19, 19, imagecolorallocate($im, 230, 20, 20));
    imagefilledrectangle($im, 20, 0, 39, 19, imagecolorallocate($im, 20, 20, 230));
    ob_start(); imagejpeg($im, null, 95); $jpg = ob_get_clean(); imagedestroy($im);
    if ($orientation) {
        // EXIF APP1 segment with one tag: Orientation (0x0112), SHORT, value $orientation (big-endian TIFF)
        $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orientation) . "\x00\x00" . "\x00\x00\x00\x00";
        $app1 = "\xFF\xE1" . pack('n', 2 + 6 + strlen($tiff)) . "Exif\x00\x00" . $tiff;
        $jpg = substr($jpg, 0, 2) . $app1 . substr($jpg, 2);
    }
    file_put_contents($path, $jpg);
}
function px($path, $x, $y) { $im = imagecreatefromjpeg($path); $c = imagecolorat($im, $x, $y); imagedestroy($im); return array(($c >> 16) & 255, ($c >> 8) & 255, $c & 255); }

$dir = sys_get_temp_dir() . '/etel_orient_' . getmypid(); @mkdir($dir);
$f = $dir . '/a.jpg';

make_jpeg($f, 0);
check('test file has no orientation tag (reads as 0)', up_exif_rotation($f) === 0);
up_compress_image($f, 'image/jpeg');
$s = getimagesize($f);
check('upright photo keeps its size (40x20)', $s[0] === 40 && $s[1] === 20);

make_jpeg($f, 6);
check('test file reads as orientation 6 (-90)', up_exif_rotation($f) === -90);
up_compress_image($f, 'image/jpeg');
$s = getimagesize($f);
check('orientation 6 photo becomes portrait (20x40)', $s[0] === 20 && $s[1] === 40);
$top = px($f, 10, 4); $bot = px($f, 10, 35);
check('turned clockwise: the left (red) half is now on top', $top[0] > 150 && $top[2] < 100);
check('turned clockwise: the right (blue) half is now at the bottom', $bot[2] > 150 && $bot[0] < 100);

make_jpeg($f, 3);
up_compress_image($f, 'image/jpeg');
$s = getimagesize($f); $left = px($f, 5, 10);
check('orientation 3 photo keeps its size and is turned 180 (blue now on the left)', $s[0] === 40 && $s[1] === 20 && $left[2] > 150);

make_jpeg($f, 8);
up_compress_image($f, 'image/jpeg');
$s = getimagesize($f); $top = px($f, 10, 4);
check('orientation 8 photo becomes portrait with the blue half on top', $s[0] === 20 && $s[1] === 40 && $top[2] > 150);

@unlink($f); @rmdir($dir);
echo $fails ? "FAILED: $fails\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);
