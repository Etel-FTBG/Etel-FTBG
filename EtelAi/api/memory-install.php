<?php
// ============================================================
// ETEL AI - Memory schema installer  v1.0
// Deploy as: EtelAi/api/memory-install.php
//
// Creates the two tables that give ETEL AI real, server-side,
// per-member memory (keyed to the EtelID member id - NOT browser
// IndexedDB, which dies with the tab and never follows the user):
//
//   {prefix}etelai_facts    - durable facts/preferences per member
//   {prefix}etelai_messages - conversation history per member
//
// Run once in the browser (admin only, guarded by the same secret
// used elsewhere):  /EtelAi/api/memory-install.php?key=...
// Idempotent - safe to run again after updates.
// ============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/secrets.php';

function out($ok, $msg, $extra = array()) {
    echo json_encode(array_merge(array('ok' => $ok, 'message' => $msg), $extra));
    exit;
}

// Reuse the AI service secret so this cannot be run by random visitors.
$key = isset($_GET['key']) ? $_GET['key'] : '';
if (!defined('ETELAI_ADMIN_KEY') || ETELAI_ADMIN_KEY === '' || $key !== ETELAI_ADMIN_KEY) {
    out(false, 'Unauthorized - set a real ETELAI_ADMIN_KEY in secrets.php first, then call ?key=THAT_VALUE');
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
} catch (Exception $e) {
    out(false, 'DB connection failed: ' . $e->getMessage());
}

$p = DB_PREFIX;

$created = array();

// Durable facts: one row per (member, fact_key). fact_key examples:
//   preferred_language, tone, dietary, nickname, note:<n>
$pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}etelai_facts` (
    `id`         INT(11) NOT NULL AUTO_INCREMENT,
    `member_id`  INT(11) NOT NULL,
    `fact_key`   VARCHAR(64)  NOT NULL,
    `fact_value` VARCHAR(500) NOT NULL,
    `source`     VARCHAR(20)  NOT NULL DEFAULT 'stated',
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_member_fact` (`member_id`, `fact_key`),
    KEY `idx_member` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$created[] = $p . 'etelai_facts';

// Conversation history: every turn, per member, per project surface.
$pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}etelai_messages` (
    `id`         INT(11) NOT NULL AUTO_INCREMENT,
    `member_id`  INT(11) NOT NULL,
    `project`    VARCHAR(40) NOT NULL DEFAULT 'unknown',
    `role`       VARCHAR(10) NOT NULL,
    `content`    TEXT NOT NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_member_time` (`member_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$created[] = $p . 'etelai_messages';

out(true, 'Memory tables ready', array('tables' => $created));
