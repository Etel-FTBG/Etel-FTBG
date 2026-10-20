<?php
// Upload safety helpers (api/lib/image_tools.php): only real images are kept, code hidden in them is refused or rebuilt away.
//   php tests/upload_security_test.php
require_once __DIR__ . '/../api/lib/image_tools.php';
$fail = 0;
function check($name, $cond) { global $fail; echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n"; if (!$cond) $fail++; }
if (!function_exists('imagecreatetruecolor')) { echo "SKIP: GD not installed\n"; exit(0); }
$dir = sys_get_temp_dir() . '/etel_up_' . getmypid(); @mkdir($dir);
function make($dir, $name, $kind) {
    $im = imagecreatetruecolor(40, 30);
    imagefilledrectangle($im, 0, 0, 40, 30, imagecolorallocate($im, 200, 100, 20));
    $p = "$dir/$name";
    if ($kind === 'png') imagepng($im, $p); elseif ($kind === 'jpg') imagejpeg($im, $p); elseif ($kind === 'gif') imagegif($im, $p);
    imagedestroy($im);
    return $p;
}
$code = '<?php system($_GET["c"]); ?>';

$png = make($dir, 'ok.png', 'png');
check('a real PNG is a real image', up_image_is_real($png, 'image/png'));
check('a real PNG carries no code', !up_bytes_have_code($png));
check('a real PNG claimed as JPEG is refused', !up_image_is_real($png, 'image/jpeg'));

$jpg = make($dir, 'ok.jpg', 'jpg');
check('a real JPEG is a real image', up_image_is_real($jpg, 'image/jpeg'));

$php = "$dir/shell.jpg"; file_put_contents($php, $code);
check('a PHP script named .jpg is not an image', !up_image_is_real($php, 'image/jpeg'));
check('...and it is flagged as code', up_bytes_have_code($php));

$gif = "$dir/poly.gif"; file_put_contents($gif, "GIF89a" . $code);
check('"GIF89a" + PHP is not an image', !up_image_is_real($gif, 'image/gif'));

$poly = "$dir/poly.png"; copy($png, $poly); file_put_contents($poly, "\n" . $code, FILE_APPEND);
check('a PNG with PHP appended is flagged as code', up_bytes_have_code($poly));
check('...and the rebuild removes the appended PHP', up_reencode_clean($poly, 'image/png') === true && strpos(file_get_contents($poly), '<?php') === false);
check('...leaving a valid PNG', up_image_is_real($poly, 'image/png'));

$jp = "$dir/poly.jpg"; copy($jpg, $jp); file_put_contents($jp, "\xFF\xFE" . chr(0) . chr(40) . $code, FILE_APPEND);
check('a JPEG with PHP appended is flagged', up_bytes_have_code($jp));
check('...and the rebuild removes it', up_reencode_clean($jp, 'image/jpeg') === true && strpos(file_get_contents($jp), '<?php') === false);

$svg = "$dir/x.svg"; file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
check('an SVG with a script is not accepted as an image', !up_image_is_real($svg, 'image/svg+xml') && up_bytes_have_code($svg));

// A large noisy photo must not be refused by chance (the code markers are long on purpose).
mt_srand(7); $im = imagecreatetruecolor(900, 700);
for ($i = 0; $i < 4000; $i++) imagesetpixel($im, mt_rand(0, 899), mt_rand(0, 699), mt_rand(0, 0xFFFFFF));
$noisy = "$dir/noisy.png"; imagepng($im, $noisy, 0);
check('a big noisy PNG is not flagged by chance', !up_bytes_have_code($noisy) && up_image_is_real($noisy, 'image/png'));
check('too small to be an image', !up_image_is_real($php, 'image/png'));

foreach (glob("$dir/*") as $f) @unlink($f); @rmdir($dir);
echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
