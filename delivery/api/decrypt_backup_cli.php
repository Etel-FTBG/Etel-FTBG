<?php
// Restores an off-site backup copy:  php decrypt_backup_cli.php etel-....sql.gz.enc restored.sql.gz "your passphrase"
// Command line only. The result is the normal .sql.gz backup (import it with phpMyAdmin or gunzip | mysql).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib/backup_offsite.php';
if ($argc < 4) { fwrite(STDERR, "usage: php decrypt_backup_cli.php <file.enc> <out.sql.gz> <passphrase>\n"); exit(2); }
if (!etel_backup_decrypt_file($argv[1], $argv[2], $argv[3])) { fwrite(STDERR, "Could not decrypt: wrong passphrase, or the file is damaged.\n"); exit(1); }
echo "Decrypted to " . $argv[2] . "\n";
