<?php
/**
 * ETEL Icon Generator v2  run at:
 *   https://ethelmarket.com/delivery/icons/generate.php
 *
 * Uses favicon.ico as the logo source.
 * Generates all PWA icon sizes + apple-touch-icon.
 * Delete this file after running.
 */
if (!extension_loaded('gd')) die('<b>GD extension not loaded.</b> Enable it in php.ini.');

$fav_path = dirname(__DIR__) . '/favicon.ico';
$favicon  = load_favicon($fav_path);   // GD image resource or null
$dir      = __DIR__ . '/';
$sizes    = [72, 96, 128, 144, 152, 192, 384, 512];
$ok = []; $fail = [];

foreach ($sizes as $sz) {
    $img  = make_icon($sz, $favicon);
    $path = $dir . "icon-{$sz}.png";
    imagepng($img, $path) ? ($ok[] = "icon-{$sz}.png") : ($fail[] = "icon-{$sz}.png");
    imagedestroy($img);
}

// apple-touch-icon (180  180)
$img = make_icon(180, $favicon);
imagepng($img, $dir . 'apple-touch-icon.png') ? ($ok[] = 'apple-touch-icon.png') : ($fail[] = 'apple-touch-icon.png');
imagedestroy($img);

// favicon-32 (for browser tab)
$img = make_icon(32, $favicon, false);
imagepng($img, $dir . 'favicon-32.png') ? ($ok[] = 'favicon-32.png') : ($fail[] = 'favicon-32.png');
imagedestroy($img);

if ($favicon) imagedestroy($favicon);

//  HTML output 
?><!DOCTYPE html>
<html><head><meta charset="UTF-8">
<title>ETEL Icon Generator</title>
<style>
body{font-family:sans-serif;max-width:680px;margin:40px auto;padding:20px;background:#f4f4f4}
h2{color:#8B6E3A;font-size:22px;margin-bottom:6px}
.ok{color:#1a7a1a;font-size:13px;line-height:1.8}
.fail{color:#c00;font-size:13px;line-height:1.8}
.preview img{border:1px solid #ccc;border-radius:10px;margin:4px;background:#fff}
.warn{background:#fff8e1;border:1px solid #ffe082;padding:14px 16px;border-radius:8px;margin-top:24px;font-size:13px}
.splash-link{display:inline-block;margin-top:16px;padding:10px 22px;background:#8B6E3A;color:#fff;border-radius:8px;text-decoration:none;font-weight:700}
</style></head><body>
<h2>ETEL Icon Generator</h2>
<p style="color:#555;font-size:13px;margin-bottom:16px">
    Source: <strong><?= $favicon ? ' favicon.ico (your logo)' : ' favicon.ico not found  used fallback drawing' ?></strong>
</p>
<?php if ($ok): ?>
<p class="ok"><strong> Generated:</strong><br><?= implode('<br>', array_map(fn($f)=>"&nbsp; $f", $ok)) ?></p>
<?php endif; ?>
<?php if ($fail): ?>
<p class="fail"><strong> Failed:</strong><br><?= implode('<br>', array_map(fn($f)=>"&nbsp; $f (check folder write permissions)", $fail)) ?></p>
<?php endif; ?>

<h3 style="margin-top:24px">Preview</h3>
<div class="preview">
<?php foreach ($sizes as $sz): ?>
<img src="icon-<?= $sz ?>.png" width="64" height="64" title="<?= $sz ?><?= $sz ?>">
<?php endforeach; ?>
<img src="apple-touch-icon.png" width="64" height="64" title="apple-touch-icon (180180)">
</div>

<p style="margin-top:20px;font-size:13px">
    <strong>Next step:</strong> Generate splash screens 
    <a class="splash-link" href="splash_gen.php">Generate Splashes</a>
</p>

<div class="warn">
     <strong>Delete or rename this file</strong> after generation to prevent public access.
</div>
</body></html>
<?php

//  FUNCTIONS 

/**
 * Load favicon.ico  extracts the largest embedded image.
 * Supports ICO files with embedded PNG (modern) or BMP (legacy).
 */
function load_favicon($path) {
    if (!file_exists($path)) return null;
    $raw = file_get_contents($path);
    if (!$raw || strlen($raw) < 6) return null;

    // ICO header: reserved(2) + type(2) + count(2)
    $hdr = unpack('vres/vtype/vcount', substr($raw, 0, 6));
    if ($hdr['type'] !== 1 || $hdr['count'] < 1) {
        // Not a standard ICO  try loading directly (could be PNG saved as .ico)
        return @imagecreatefromstring($raw) ?: null;
    }

    $best = null; $bestSz = 0;
    for ($i = 0; $i < $hdr['count']; $i++) {
        $off = 6 + $i * 16;
        if ($off + 16 > strlen($raw)) break;
        $e = unpack('Cw/Ch/Ccolors/Cres/vplanes/vbits/Vbytes/Vimgoff', substr($raw, $off, 16));
        $sz = ($e['w'] === 0) ? 256 : $e['w'];
        if ($sz > $bestSz) { $bestSz = $sz; $best = $e; }
    }
    if (!$best) return null;

    $imgData = substr($raw, $best['imgoff'], $best['bytes']);
    // PNG magic bytes?
    if (substr($imgData, 0, 8) === "\x89PNG\r\n\x1a\n") {
        return @imagecreatefromstring($imgData) ?: null;
    }
    // BMP / DIB  prepend BITMAPFILEHEADER so GD can read it
    return @imagecreatefromstring($imgData) ?: null;
}

/**
 * Create a square icon at $size.
 * If $favicon is provided, center it on the gold background.
 * $maskable = true adds extra gold padding (20%) for maskable safe zone.
 */
function make_icon($size, $favicon, $maskable = true) {
    $img = imagecreatetruecolor($size, $size);
    imageantialias($img, true);
    imagesavealpha($img, true);

    //  Gold gradient background 
    for ($y = 0; $y < $size; $y++) {
        $ratio = $y / $size;
        $r = intval(201 - $ratio * 50);
        $g = intval(169 - $ratio * 50);
        $b = intval(97  - $ratio * 30);
        $col = imagecolorallocate($img, max(0,$r), max(0,$g), max(0,$b));
        imageline($img, 0, $y, $size - 1, $y, $col);
    }

    if ($favicon) {
        //  Place favicon logo on background 
        $pad    = $maskable ? intval($size * 0.18) : intval($size * 0.10);
        $dst_sz = $size - $pad * 2;
        $src_w  = imagesx($favicon);
        $src_h  = imagesy($favicon);

        // White circle behind logo for contrast
        $cx = $size / 2; $cy = $size / 2;
        $cr = intval($dst_sz * 0.58);
        imagefilledellipse($img, intval($cx), intval($cy), $cr * 2, $cr * 2,
            imagecolorallocatealpha($img, 255, 255, 255, 8));

        // Scale favicon onto icon
        $dst_x = intval(($size - $dst_sz) / 2);
        $dst_y = intval(($size - $dst_sz) / 2);
        imagecopyresampled($img, $favicon, $dst_x, $dst_y, 0, 0,
            $dst_sz, $dst_sz, $src_w, $src_h);

    } else {
        //  Fallback: draw ETEL truck logo 
        draw_etel_logo($img, $size, $maskable);
    }

    return $img;
}

function draw_etel_logo($img, $size, $maskable) {
    $white    = imagecolorallocate($img, 255, 255, 255);
    $gold_dk  = imagecolorallocate($img, 139, 110, 58);
    $cx = $size / 2;

    // White circle
    $cr = intval($size * ($maskable ? 0.33 : 0.40));
    $cy_circ = intval($size * 0.42);
    imagefilledellipse($img, intval($cx), $cy_circ, $cr*2, $cr*2, $white);

    // Truck body
    $tw = intval($cr * 1.1); $th = intval($cr * 0.48);
    $tx = intval($cx - $tw * 0.55); $ty = intval($cy_circ - $th * 0.65);
    imagefilledrectangle($img, $tx, $ty, $tx+$tw, $ty+$th, $gold_dk);

    // Cab
    $cw = intval($tw*0.34); $ch = intval($th*0.85);
    imagefilledrectangle($img, $tx+$tw, $ty+intval($th*.15), $tx+$tw+$cw, $ty+$th, $gold_dk);

    // Window
    $wp = intval($cw*.15);
    imagefilledrectangle($img, $tx+$tw+$wp, $ty+intval($th*.22), $tx+$tw+$cw-$wp, $ty+intval($th*.65), $white);

    // Wheels
    $wr = intval($size*.055); $wy = $ty+$th+$wr;
    foreach ([$tx+intval($tw*.25), $tx+intval($tw*.78), $tx+$tw+intval($cw*.5)] as $wx) {
        imagefilledellipse($img, $wx, $wy, $wr*2, $wr*2,
            imagecolorallocate($img, 60, 40, 10));
        imagefilledellipse($img, $wx, $wy, intval($wr*.5), intval($wr*.5), $white);
    }

    // ETEL text (scaled)
    $scale = max(1, intval($size * 0.046 / 9));
    $tmp = imagecreatetruecolor(38, 15);
    $tr  = imagecolorallocatealpha($tmp,0,0,0,127); imagefill($tmp,0,0,$tr); imagesavealpha($tmp,true);
    imagestring($tmp, 5, 2, 0, 'ETEL', $white);
    $dw = 38*$scale; $dh = 15*$scale;
    imagecopyresized($img,$tmp,intval($cx-$dw/2),intval($size*.77),0,0,$dw,$dh,38,15);
    imagedestroy($tmp);
}
