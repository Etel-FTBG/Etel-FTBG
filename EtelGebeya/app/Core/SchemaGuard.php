<?php
declare(strict_types=1);
namespace App\Core;

/**
 * Defensive "add this column if it's missing" helper -- this app has no
 * migration runner and deploys are manual file uploads, so new features
 * bring their own schema self-heal instead of requiring a separate SQL
 * step on the live server. Mirrors the pattern already used ad hoc in
 * EscrowService/api/admin.php, just shared instead of copy-pasted.
 */
final class SchemaGuard
{
    /** @param array<string,string> $columns column name => full ADD COLUMN SQL fragment */
    public static function ensureColumns(Database $db, string $table, array $columns): void
    {
        if (!$db->isConnected()) return;
        $existing = [];
        try {
            $rows = $db->fetchAll(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$table]
            );
            foreach ($rows as $r) { $existing[$r['COLUMN_NAME']] = true; }
        } catch (\Throwable $e) { return; }

        foreach ($columns as $col => $sql) {
            if (!isset($existing[$col])) {
                try { $db->pdo()->exec("ALTER TABLE `$table` ADD COLUMN $sql"); } catch (\Throwable $e) {}
            }
        }
    }
}
