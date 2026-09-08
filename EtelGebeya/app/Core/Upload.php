<?php
declare(strict_types=1);
namespace App\Core;

/** Simple file uploader for product images, avatars, vendor docs. */
final class Upload
{
    public static function image(array $file, string $subdir, int $maxBytes = 5_000_000): ?string
    {
        if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload error: ' . $file['error']);
        }
        if ($file['size'] > $maxBytes) {
            throw new \RuntimeException('File too large (max ' . ($maxBytes / 1_000_000) . ' MB).');
        }
        $info = getimagesize($file['tmp_name']);
        if (!$info) {
            throw new \RuntimeException('Not an image.');
        }
        $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
        if (!isset($allowed[$info[2]])) {
            throw new \RuntimeException('Unsupported image type.');
        }
        $ext = $allowed[$info[2]];
        $dir = rtrim($GLOBALS['__config']['storage']['uploads'], '/\\') . '/' . trim($subdir, '/');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $name = date('Y/m/d') . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
        $abs  = rtrim($GLOBALS['__config']['storage']['uploads'], '/\\') . '/' . $name;
        if (!is_dir(dirname($abs))) @mkdir(dirname($abs), 0775, true);
        if (!move_uploaded_file($file['tmp_name'], $abs)) {
            // Fallback if move_uploaded_file fails (e.g. when running on non-upload tmp)
            if (!@rename($file['tmp_name'], $abs) && !@copy($file['tmp_name'], $abs)) {
                throw new \RuntimeException('Failed to save uploaded file.');
            }
        }
        return '/' . ltrim($name, '/');
    }
}
