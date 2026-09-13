<?php
/* size_report.php — standalone diagnostic, does NOT require boot.php.
   Upload to the root of your delivery/ folder and visit it directly in
   a browser (e.g. https://ethelmarket.com/delivery/size_report.php).
   Delete it again once you're done — it has no auth of its own and
   lists your folder structure, which you don't want publicly reachable
   long-term. */

function human($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function dirSize($dir) {
    $size = 0;
    $count = 0;
    if (!is_dir($dir)) return array(0, 0);
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($items as $item) {
        if ($item->isFile()) {
            $size += $item->getSize();
            $count++;
        }
    }
    return array($size, $count);
}

$root = __DIR__;
$topLevel = array();
foreach (scandir($root) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $path = $root . '/' . $entry;
    if (is_dir($path)) {
        list($size, $count) = dirSize($path);
        $topLevel[$entry] = array('size' => $size, 'count' => $count, 'isDir' => true);
    } else {
        $topLevel[$entry] = array('size' => filesize($path), 'count' => 1, 'isDir' => false);
    }
}
uasort($topLevel, function ($a, $b) { return $b['size'] <=> $a['size']; });

// Flag common bloat patterns specifically
$flags = array();
if (isset($topLevel['backups'])) {
    $flags[] = "backups/ is " . human($topLevel['backups']['size']) . " across " . $topLevel['backups']['count'] . " files — almost certainly safe to exclude from your zip, or archive elsewhere and delete from the live server.";
}
if (isset($topLevel['uploads'])) {
    $flags[] = "uploads/ (driver selfies, license photos, top-up receipts) is " . human($topLevel['uploads']['size']) . " — this is real user data, not code. It shouldn't be in a code zip at all; back it up separately if needed.";
}
if (isset($topLevel['.git'])) {
    $flags[] = ".git/ is " . human($topLevel['.git']['size']) . " — full version history. Never needed on the live server or in a code-review zip; safe to exclude entirely.";
}
if (isset($topLevel['node_modules'])) {
    $flags[] = "node_modules/ is " . human($topLevel['node_modules']['size']) . " — build-time only (used by build-bundles.js). Never needed on the live server.";
}

?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Folder Size Report</title>
<style>
body{font-family:monospace;background:#0d0d14;color:#EDEDF5;padding:24px;max-width:900px;margin:0 auto}
h1{color:#E5C76B;font-size:18px}
table{width:100%;border-collapse:collapse;margin-top:16px}
td,th{padding:8px 10px;text-align:left;border-bottom:1px solid rgba(255,255,255,.08)}
th{color:#9A9AAE;font-size:12px;text-transform:uppercase}
.big{color:#FF8A80;font-weight:bold}
.med{color:#E5C76B}
.flag{background:rgba(212,175,55,.1);border:1px solid rgba(212,175,55,.35);border-radius:8px;padding:12px;margin:8px 0;font-size:13px}
</style></head><body>
<h1>📦 Folder Size Report — <?= htmlspecialchars($root) ?></h1>
<p>Generated <?= date('Y-m-d H:i:s') ?></p>

<?php if ($flags): ?>
<h2 style="color:#E5C76B;font-size:15px">⚠ Likely bloat sources found:</h2>
<?php foreach ($flags as $f): ?>
<div class="flag"><?= htmlspecialchars($f) ?></div>
<?php endforeach; ?>
<?php else: ?>
<p>No obvious bloat folders (backups/, uploads/, .git/, node_modules/) found at this level.</p>
<?php endif; ?>

<table>
<tr><th>Item</th><th>Type</th><th>Size</th><th>Files</th></tr>
<?php foreach ($topLevel as $name => $info):
    $sizeClass = $info['size'] > 20 * 1024 * 1024 ? 'big' : ($info['size'] > 5 * 1024 * 1024 ? 'med' : '');
?>
<tr>
    <td><?= htmlspecialchars($name) ?><?= $info['isDir'] ? '/' : '' ?></td>
    <td><?= $info['isDir'] ? 'folder' : 'file' ?></td>
    <td class="<?= $sizeClass ?>"><?= human($info['size']) ?></td>
    <td><?= $info['count'] ?></td>
</tr>
<?php endforeach; ?>
</table>

<p style="color:#6B6455;margin-top:24px">Delete this file when you're done — it lists your folder structure and shouldn't stay publicly reachable.</p>
</body></html>
