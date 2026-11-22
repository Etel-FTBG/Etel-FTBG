<?php
/* ETEL Delivery - keeps the real reason of a server-side PHP error where an admin can read it.
   The apps only show "Server fatal error" or "Could not place the order"; the cause (message, file and line) goes into the table client_errors
   (the same list as the page errors, System health > page errors; app = "server"). One row per distinct error, hits counted. Never throws.
   PHP 5 style, ASCII only. */
if (!function_exists('etel_log_server_error')) {
function etel_log_server_error($kind, $action, $message, $file, $line) {
    try {
        if (!function_exists('db')) return false;
        $pdo = db();
        $act = substr((string) $action, 0, 120);
        $msg = substr((string) $message, 0, 300);
        $src = substr(basename((string) $file), 0, 160);
        $ln = (int) $line;
        $kind = substr((string) $kind, 0, 12);
        $pdo->exec("CREATE TABLE IF NOT EXISTS client_errors (
            id CHAR(32) NOT NULL, app VARCHAR(20) NOT NULL DEFAULT '', kind VARCHAR(12) NOT NULL DEFAULT '', page VARCHAR(120) NOT NULL DEFAULT '',
            message VARCHAR(300) NOT NULL DEFAULT '', source VARCHAR(160) NOT NULL DEFAULT '', line_no INT NOT NULL DEFAULT 0, stack VARCHAR(1200) NOT NULL DEFAULT '',
            rid VARCHAR(16) NOT NULL DEFAULT '', ua VARCHAR(200) NOT NULL DEFAULT '', user_id INT NOT NULL DEFAULT 0,
            hits INT NOT NULL DEFAULT 1, first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY idx_client_errors_seen (last_seen)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $fp = md5('server|' . $kind . '|' . $act . '|' . preg_replace('/[0-9a-f]{8,}|\d+/i', '#', $msg) . '|' . $src . '|' . $ln);
        $up = $pdo->prepare('UPDATE client_errors SET hits = hits + 1, last_seen = NOW() WHERE id = ?');
        $up->execute(array($fp));
        if ($up->rowCount() === 0) {
            $pdo->prepare('INSERT IGNORE INTO client_errors (id, app, kind, page, message, source, line_no) VALUES (?,?,?,?,?,?,?)')
                ->execute(array($fp, 'server', $kind, $act, $msg, $src, $ln));
        }
        return true;
    } catch (Exception $e) {
        return false;
    } catch (Throwable $e) {
        return false;
    }
}
}
