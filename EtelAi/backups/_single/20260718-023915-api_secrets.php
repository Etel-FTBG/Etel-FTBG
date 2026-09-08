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

// Admin key for one-time maintenance endpoints (memory-install.php).
// No hardcoded fallback on purpose: set ETELAI_ADMIN_KEY in the
// environment, or replace the empty string below with your own long
// random value before running the installer. An empty key means the
// installer refuses to run - it will never accept a guessable default.
if (!defined('ETELAI_ADMIN_KEY')) define('ETELAI_ADMIN_KEY', getenv('ETELAI_ADMIN_KEY') ?: '');

// Gemini API key - powers any model in models-config.json with
// "provider": "gemini". No hardcoded fallback: leave empty until you
// have a real key, and Gemini-provider models simply won't work until
// then (models.php still lists them so the UI is honest about what
// exists, chat.php returns a clear error instead of silently failing).
if (!defined('GEMINI_API_KEY')) define('GEMINI_API_KEY', getenv('ETELAI_GEMINI_KEY') ?: 'CHANGE_ME');

