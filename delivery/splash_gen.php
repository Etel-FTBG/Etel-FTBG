<?php
/**
 * ETEL Splash Screen Generator  run at:
 *   https://ethelmarket.com/delivery/icons/splash_gen.php
 *
 * Generates all iOS PWA launch images + a universal Android splash.
 * Uses favicon.ico as the logo. Delete after running.
 */
if (!extension_loaded('gd')) die('<b>GD not available.</b>');

$fav_path = dirname(__DIR__) . '/favicon.ico';
$favicon  = load_favicon($fav_path);
$dir      = __DIR__ . '/';
$ok = []; $fail = [];

// Portrait splash sizes (width  height)  all major iOS devices
$splashes = [
    'splash-640x1136'   => [640,  1136],   // iPhone SE 1st gen
    'splash-750x1334'   => [750,  1334],   // iPhone 8 / 7 / 6
    'splash-1242x2208'  => [1242, 2208],   // iPhone 8 Plus
    'splash-1125x2436'  => [1125, 2436],   // iPhone X / XS / 11 Pro
    'splash-828x1792'   => [828,  1792],   // iPhone XR / 11
    'splash-1242x2688'  => [1242, 2688],   // iPhone XS Max / 11 Pro Max
    'splash-1170x2532'  => [1170, 2532],   // iPhone 12 / 13 / 14
    'splash-1284x2778'  => [1284, 2778],   // iPhone 12 Pro Max / 13 Pro Max
    'splash-1179x2556'  => [1179, 2556],   // iPhone 14 Pro
    'splash-1290x2796'  => [1290, 2796],   // iPhone 14 Pro Max / 15 Pro Max
    'splash-1536x2048'  => [1536, 2048],   // iPad Air / mini (portrait)
    'splash-1668x2388'  => [1668, 2388],   // iPad Pro 11"
    'splash-2048x2732'  => [2048, 2732],   // iPad Pro 12.9"
    // Universal Android splash (shown briefly on install)
    'splash-1080x1920'  => [1080, 1920],
];

foreach ($splashes as $name => [$w, $h]) {
    $img = make_splash($w, $h, $favicon);
    imagepng($img, $dir . $name . '.png') ? ($ok[] = "$name.png") : ($fail[] = "$name.png");
    imagedestroy($img);
}

if ($favicon) imagedestroy($favicon);
?><!DOCTYPE html>
<html><head><meta charset="UTF-8">
<title>ETEL Splash Generator</title>
<style>
body{font-family:sans-serif;max-width:700px;margin:40px auto;padding:20px;background:#f4f4f4}
h2{color:#8B6E3A}
.ok{color:#1a7a1a;font-size:13px;line-height:1.8}
.fail{color:#c00;font-size:13px}
.grid{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px}
.grid img{border-radius:8px;border:1px solid #ccc;background:#fff}
.warn{background:#fff8e1;border:1px solid #ffe082;padding:14px;border-radius:8px;margin-top:24px;font-size:13px}
</style></head><body>
<h2>ETEL Splash Screen Generator</h2>
<p style="font-size:13px;color:#555">Source: <strong><?= $favicon ? ' favicon.ico' : ' fallback drawing' ?></strong></p>
<?php if ($ok): ?>
<p class="ok"><strong> Generated (<?= count($ok) ?> files):</strong><br><?= implode('<br>', array_map(fn($f)=>"&nbsp; $f",$ok)) ?></p>
<?php endif; ?>
<?php if ($fail): ?>
<p class="fail"><strong> Failed:</strong><br><?= implode('<br>', array_map(fn($f)=>"&nbsp; $f",$fail)) ?></p>
<?php endif; ?>

<h3 style="margin-top:24px">Previews (scaled down)</h3>
<div class="grid">
<?php foreach (array_keys($splashes) as $name): ?>
<img src="<?= $name ?>.png" style="height:100px;width:auto" title="<?= $name ?>">
<?php endforeach; ?>
</div>

<div class="warn">
 <strong>Delete or rename this file</strong> after running. Also delete <code>generate.php</code> if you already ran it.
</div>
</body></html>
<?php

//  FUNCTIONS 

function make_splash($w, $h, $favicon) {
    $img = imagecreatetruecolor($w, $h);
    imageantialias($img, true);

    //  Gold gradient background 
    for ($y = 0; $y < $h; $y++) {
        $ratio = $y / $h;
        $r = intval(210 - $ratio * 60);
        $g = intval(175 - $ratio * 55);
        $b = intval(100 - $ratio * 40);
        $col = imagecolorallocate($img, max(0,$r), max(0,$g), max(0,$b));
        imageline($img, 0, $y, $w - 1, $y, $col);
    }

    // Subtle radial highlight (top-left)
    for ($y = 0; $y < intval($h*.5); $y++) {
        for ($x = 0; $x < intval($w*.5); $x++) {
            $dist = sqrt($x*$x + $y*$y) / ($w * .6);
            if ($dist < 1) {
                $alpha = intval(50 * (1 - $dist));
                $hl = imagecolorallocatealpha($img, 255, 235, 150, 127 - $alpha);
                imagesetpixel($img, $x, $y, $hl);
            }
        }
    }

    $white    = imagecolorallocate($img, 255, 255, 255);
    $gold_dk  = imagecolorallocate($img, 139, 110, 58);
    $text_dim = imagecolorallocatealpha($img, 255, 255, 255, 60);

    $cx = $w / 2;
    $cy = $h * 0.40;   // logo sits at 40% of screen height

    //  Large white circle backdrop 
    $circle_r = intval(min($w, $h) * 0.22);
    imagefilledellipse($img, intval($cx), intval($cy),
        $circle_r * 2, $circle_r * 2, $white);

    //  Logo inside circle 
    $logo_sz = intval($circle_r * 1.55);
    $logo_x  = intval($cx - $logo_sz / 2);
    $logo_y  = intval($cy - $logo_sz / 2);

    if ($favicon) {
        $src_w = imagesx($favicon); $src_h = imagesy($favicon);
        imagecopyresampled($img, $favicon,
            $logo_x, $logo_y, 0, 0,
            $logo_sz, $logo_sz, $src_w, $src_h);
    } else {
        draw_truck($img, intval($cx), intval($cy), $logo_sz, $white, $gold_dk);
    }

    //  Decorative ring around circle 
    $ring_col = imagecolorallocatealpha($img, 255, 255, 255, 90);
    for ($t = 0; $t < 360; $t += 1) {
        $rad = deg2rad($t);
        $rx  = intval($cx + ($circle_r + intval($w*.012)) * cos($rad));
        $ry  = intval($cy + ($circle_r + intval($w*.012)) * sin($rad));
        imagesetpixel($img, $rx, $ry, $ring_col);
    }

    //  "ETEL" title 
    $title_y = intval($cy + $circle_r + $h * 0.07);
    draw_scaled_text($img, 'ETEL', $cx, $title_y, $w * 0.48, $white);

    //  "Delivery" subtitle 
    $sub_y = intval($title_y + $h * 0.075);
    draw_scaled_text($img, 'Delivery', $cx, $sub_y, $w * 0.28, $white);

    //  Thin divider 
    $div_y  = intval($sub_y + $h * 0.045);
    $div_hw = intval($w * 0.12);
    $div_h2 = max(2, intval($h * 0.004));
    imagefilledrectangle($img, intval($cx - $div_hw), $div_y,
        intval($cx + $div_hw), $div_y + $div_h2,
        imagecolorallocatealpha($img, 255, 255, 255, 40));

    //  "Hawassa  " tagline 
    $tag_y = intval($div_y + $h * 0.038);
    draw_scaled_text($img, 'Hawassa . Ethiopia', $cx, $tag_y, $w * 0.22, $text_dim);

    return $img;
}

/**
 * Scale-up built-in GD font text, centered at ($cx, $cy).
 * $target_w is the desired pixel width of the rendered text.
 */
function draw_scaled_text($img, $text, $cx, $cy, $target_w, $color) {
    $font   = 5; $char_w = 9; $char_h = 15;
    $n      = strlen($text);
    $scale  = max(1, intval($target_w / ($n * $char_w)));
    $tmp_w  = $n * $char_w + 4; $tmp_h = $char_h + 4;
    $tmp    = imagecreatetruecolor($tmp_w, $tmp_h);
    $tr     = imagecolorallocatealpha($tmp, 0,0,0,127);
    imagefill($tmp, 0, 0, $tr); imagesavealpha($tmp, true);
    imagestring($tmp, $font, 2, 2, $text, $color);
    $dw = $tmp_w * $scale; $dh = $tmp_h * $scale;
    imagecopyresized($img, $tmp, intval($cx - $dw/2), $cy, 0, 0, $dw, $dh, $tmp_w, $tmp_h);
    imagedestroy($tmp);
}

function draw_truck($img, $cx, $cy, $sz, $white, $gold_dk) {
    $tw = intval($sz*.55); $th = intval($sz*.28);
    $tx = intval($cx-$tw*.55); $ty = intval($cy-$th*.6);
    imagefilledrectangle($img,$tx,$ty,$tx+$tw,$ty+$th,$gold_dk);
    $cw=intval($tw*.34);
    imagefilledrectangle($img,$tx+$tw,$ty+intval($th*.15),$tx+$tw+$cw,$ty+$th,$gold_dk);
    $wp=intval($cw*.15);
    imagefilledrectangle($img,$tx+$tw+$wp,$ty+intval($th*.22),$tx+$tw+$cw-$wp,$ty+intval($th*.65),$white);
    $wr=intval($sz*.06); $wy=$ty+$th+$wr;
    foreach([$tx+intval($tw*.25),$tx+intval($tw*.78),$tx+$tw+intval($cw*.5)] as $wx) {
        imagefilledellipse($img,$wx,$wy,$wr*2,$wr*2,$gold_dk);
        imagefilledellipse($img,$wx,$wy,intval($wr*.45),intval($wr*.45),$white);
    }
}

function load_favicon($path) {
    if (!file_exists($path)) return null;
    $raw = file_get_contents($path);
    if (!$raw || strlen($raw) < 6) return null;
    $hdr = unpack('vres/vtype/vcount', substr($raw, 0, 6));
    if ($hdr['type'] !== 1 || $hdr['count'] < 1) return @imagecreatefromstring($raw) ?: null;
    $best=null; $bestSz=0;
    for($i=0;$i<$hdr['count'];$i++){
        $off=6+$i*16; if($off+16>strlen($raw)) break;
        $e=unpack('Cw/Ch/Ccolors/Cres/vplanes/vbits/Vbytes/Vimgoff',substr($raw,$off,16));
        $sz=($e['w']===0)?256:$e['w'];
        if($sz>$bestSz){$bestSz=$sz;$best=$e;}
    }
    if(!$best) return null;
    $imgData=substr($raw,$best['imgoff'],$best['bytes']);
    return @imagecreatefromstring($imgData) ?: null;
}
