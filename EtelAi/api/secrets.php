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
// Generated fresh - not a leaked value, this is yours alone. Keep this
// file off any public repo/backup and don't paste this key anywhere
// public. If you ever suspect it's been seen by someone else, replace
// it with a new random string and nothing else needs to change.
if (!defined('ETELAI_ADMIN_KEY')) define('ETELAI_ADMIN_KEY', getenv('ETELAI_ADMIN_KEY') ?: 'CHANGE_ME');

// Gemini API key - powers any model in models-config.json with
// "provider": "gemini". Taken directly from Google AI Studio's own key
// details dialog (the authoritative source - not re-typed from a
// screenshot of a text editor, which can introduce line-wrap misreads).
if (!defined('GEMINI_API_KEY')) define('GEMINI_API_KEY', getenv('ETELAI_GEMINI_KEY') ?: 'CHANGE_ME');
// Confirmed against the real API: this key authenticates successfully on
// api.moonshot.ai (international) - api.moonshot.cn rejected it outright
// with "Invalid Authentication", so that's the wrong region for this key.
// The account itself was suspended for insufficient balance at the time
// of testing; recharge at Moonshot's billing page before expecting real
// replies, but the key/endpoint pairing itself is correct.
if (!defined('KIMI_API_KEY'))  define('KIMI_API_KEY',  getenv('ETELAI_KIMI_KEY')  ?: 'CHANGE_ME');
if (!defined('KIMI_API_BASE')) define('KIMI_API_BASE', getenv('ETELAI_KIMI_BASE') ?: 'https://api.moonshot.ai/v1');

// Delivery agent connector - lets EtelAI actually book rides on a
// member's behalf (see api/controllers/AgentController.php on the
// Delivery side). DELIVERY_SERVICE_KEY must be the EXACT SAME value as
// ETELID_SERVICE_KEY configured in Delivery's api/.env - this is the
// same shared-secret pattern SETUP-SSO-KEY.txt already established for
// EtelID<->Delivery calls, reused here for EtelAI<->Delivery. No
// hardcoded default: until this is set, ride-booking tool calls fail
// with a clear "not configured" error rather than silently doing
// nothing or using a guessable key.
if (!defined('DELIVERY_API_BASE'))     define('DELIVERY_API_BASE',     getenv('ETELAI_DELIVERY_API_BASE') ?: 'https://ethelmarket.com/delivery/api/boot.php');
if (!defined('DELIVERY_SERVICE_KEY'))  define('DELIVERY_SERVICE_KEY',  getenv('ETELAI_DELIVERY_SERVICE_KEY') ?: 'CHANGE_ME');

