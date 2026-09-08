<?php
/**
 * Etel-ID Premium — Patch Builder (CLI/dev tool)
 * Run from terminal to create a patch ZIP from changed files.
 * Not loaded in WordPress — only used by Claude to build patches.
 *
 * Usage (from plugin root):
 *   php includes/class-etelid-patch-builder.php \
 *     --version=3.5.1 \
 *     --description="Fix portal parse error" \
 *     --files="templates/portal.php,includes/class-etelid-db.php"
 */
if ( PHP_SAPI !== 'cli' ) exit;

$opts = getopt('', array('version:', 'description:', 'files:', 'from:'));
$new_version  = isset($opts['version'])     ? $opts['version']     : '0.0.0';
$description  = isset($opts['description']) ? $opts['description'] : 'Patch update';
$files_arg    = isset($opts['files'])       ? $opts['files']       : '';
$from_dir     = isset($opts['from'])        ? $opts['from']        : dirname(dirname(__FILE__));

if (!$files_arg) { echo "Error: --files required\n"; exit(1); }

$files_list = array_map('trim', explode(',', $files_arg));
$patch_name = 'etel-id-patch-' . $new_version . '.zip';
$out_path   = sys_get_temp_dir() . '/' . $patch_name;

if (file_exists($out_path)) unlink($out_path);

$zip = new ZipArchive();
if ($zip->open($out_path, ZipArchive::CREATE) !== true) {
    echo "Error: could not create ZIP\n"; exit(1);
}

/* Add manifest */
$manifest = json_encode(array(
    'patch_for'   => 'etel-id-premium',
    'patch_version' => 'patch-' . $new_version . '-' . date('YmdHis'),
    'new_version' => $new_version,
    'description' => $description,
    'created_at'  => date('c'),
    'files'       => $files_list,
), JSON_PRETTY_PRINT);
$zip->addFromString('patch-manifest.json', $manifest);

/* Add each file */
$added  = array();
$errors = array();
foreach ($files_list as $rel) {
    $abs = rtrim($from_dir, '/') . '/' . ltrim($rel, '/');
    if (!file_exists($abs)) {
        $errors[] = $rel;
        echo "Warning: file not found: $abs\n";
        continue;
    }
    $zip->addFile($abs, 'files/' . $rel);
    $added[] = $rel;
    echo "Added: $rel\n";
}

$zip->close();

echo "\nPatch ZIP created: $out_path\n";
echo "Files added: " . count($added) . "\n";
if ($errors) echo "Files missing: " . implode(', ', $errors) . "\n";
