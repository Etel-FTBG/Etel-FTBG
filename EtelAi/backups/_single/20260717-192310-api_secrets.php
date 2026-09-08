<?php
// ============================================================
// ETEL AI — Local secrets (NOT for version control, NOT for backups/)
// Blocked from direct HTTP access by api/.htaccess (see DenyAll below).
// ============================================================

if (!defined('DB_HOST'))    define('DB_HOST',    getenv('ETELAI_DB_HOST')    ?: 'localhost');
if (!defined('DB_NAME'))    define('DB_NAME',    getenv('ETELAI_DB_NAME')    ?: 'etelid');
if (!defined('DB_USER'))    define('DB_USER',    getenv('ETELAI_DB_USER')    ?: 'etelid');
if (!defined('DB_PASS'))    define('DB_PASS',    getenv('ETELAI_DB_PASS')    ?: 'CHANGE_ME');
if (!defined('DB_PREFIX'))  define('DB_PREFIX',  getenv('ETELAI_DB_PREFIX')  ?: 'Etelid_');

if (!defined('ETELID_KEY')) define('ETELID_KEY', getenv('ETELAI_ETELID_KEY') ?: 'CHANGE_ME');
