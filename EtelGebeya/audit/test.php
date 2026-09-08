<?php
header('Content-Type: text/plain');
echo "=== DIAGNOSTIC ===\n";
echo "already_defined: " . (defined('GEMINI_API_KEY') ? constant('GEMINI_API_KEY') : 'NO') . "\n";
define('GEMINI_API_KEY', 'TESTKEY123');
echo "after_define: " . GEMINI_API_KEY . "\n";
echo "file: " . __FILE__ . "\n";
echo "php_version: " . PHP_VERSION . "\n";
