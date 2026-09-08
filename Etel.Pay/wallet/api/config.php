<?php
/**
 * ኢE Pay — Standalone API Config
 * InfinityFree/cPanel shared hosting
 */

// ── Database (InfinityFree requires ethelmar_ prefix) ─────────
define('EP_DB_HOST',    'localhost');
define('EP_DB_NAME',    'etelpay');
define('EP_DB_USER',    'etelpay');
define('EP_DB_PASS',    'CHANGE_ME');
define('EP_DB_CHARSET', 'utf8mb4');

// Table prefix — confirmed from phpMyAdmin: Etelpay_ep_users etc.
define('EP_TBL_PREFIX', 'Etelpay_');

// ── Security ──────────────────────────────────────────────────
define('EP_SECRET',       'CHANGE_ME');
define('EP_UPDATER_PASS', 'CHANGE_ME');

// ── App ───────────────────────────────────────────────────────
define('EP_APP_NAME',    'ኢE Pay');
define('EP_APP_VERSION', '1.0.1');
define('EP_TIMEZONE',    'Africa/Addis_Ababa');
define('EP_SESSION_TTL', 604800);   // 7 days
define('EP_DAILY_LIMIT', 0);

// ── Table helpers ─────────────────────────────────────────────
define('EP_TBL_USERS',    EP_TBL_PREFIX . 'ep_users');
define('EP_TBL_TX',       EP_TBL_PREFIX . 'ep_transactions');
define('EP_TBL_SESSIONS', EP_TBL_PREFIX . 'ep_sessions');
define('EP_TBL_QR',       EP_TBL_PREFIX . 'ep_qr_codes');
define('EP_TBL_RATES',    EP_TBL_PREFIX . 'ep_rates');
define('EP_TBL_SETTINGS', EP_TBL_PREFIX . 'ep_settings');
define('EP_TBL_UPDLOG',   EP_TBL_PREFIX . 'ep_updater_logs');

// EtelGroup Service Key 2026-06-15
define('ETELID_SERVICE_KEY',     'CHANGE_ME');
define('ETELID_SERVICE_URL', 'https://ethelmarket.com/EtelId/wp-json/etelid/v1');
