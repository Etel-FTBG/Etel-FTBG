<?php
/**
 * Etel Madeya - station operations: fuel deliveries log, fuel-shortage alerts (Telegram), day closing, wait estimate.
 * Everything self-heals (tables / columns are created the first time they are needed). Shown timestamps use PHP's clock (Addis Ababa);
 * report.served_at is stamped by the database clock, so madeyaToDbClock() converts before the two are compared.
 */

function ensureOpsTables() {
    static $done = false; if ($done) return; $done = true;
    try {
        db()->query("CREATE TABLE IF NOT EXISTS fuel_deliveries (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            controller_id INT UNSIGNED NOT NULL,
            kind VARCHAR(10) NOT NULL DEFAULT 'delivery',
            liters DECIMAL(10,1) NOT NULL,
            supplier VARCHAR(120) NULL,
            note VARCHAR(200) NULL,
            tank_after DECIMAL(10,1) NULL,
            entered_by VARCHAR(120) NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_fd_ctrl (controller_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->query("CREATE TABLE IF NOT EXISTS day_closings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            controller_id INT UNSIGNED NOT NULL,
            close_date DATE NOT NULL,
            closed_at DATETIME NOT NULL,
            closed_by VARCHAR(120) NULL,
            served INT NOT NULL DEFAULT 0,
            liters DECIMAL(10,1) NOT NULL DEFAULT 0,
            queued_left INT NOT NULL DEFAULT 0,
            fuel_remaining DECIMAL(10,1) NULL,
            note VARCHAR(200) NULL,
            reopened_at DATETIME NULL,
            reopened_by VARCHAR(120) NULL,
            UNIQUE KEY uq_dc (controller_id, close_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $have = [];
        foreach (db()->fetchAll("SHOW COLUMNS FROM controllers") as $c) $have[$c['Field']] = true;
        if (empty($have['fuel_alert_state'])) db()->query("ALTER TABLE controllers ADD COLUMN fuel_alert_state VARCHAR(10) NULL DEFAULT NULL");
        if (empty($have['fuel_alert_at'])) db()->query("ALTER TABLE controllers ADD COLUMN fuel_alert_at DATETIME NULL DEFAULT NULL");
    } catch (Exception $e) { error_log('ensureOpsTables: ' . $e->getMessage()); }
}

/** Seconds the database clock is ahead of PHP's (Africa/Addis_Ababa) clock; report.served_at is stamped by the database. */
function madeyaDbClockShift() {
    static $shift = null; if ($shift !== null) return $shift;
    try { $r = db()->fetchOne("SELECT NOW() AS n"); $shift = (int)(round((strtotime($r['n']) - time()) / 900) * 900); } catch (Exception $e) { $shift = 0; }
    return $shift;
}
/** A PHP-clock "Y-m-d H:i:s" stamp expressed on the database clock, for comparing with report.served_at. */
function madeyaToDbClock($phpStamp) { return date('Y-m-d H:i:s', strtotime($phpStamp) + madeyaDbClockShift()); }

function madeyaSettingGet($key, $default = '') {
    try { $r = db()->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]); return $r ? (string)$r['setting_value'] : $default; }
    catch (Exception $e) { return $default; }
}
function madeyaSettingSet($key, $value) {
    $r = db()->fetchOne("SELECT id FROM settings WHERE setting_key = ?", [$key]);
    if ($r) db()->update('settings', ['setting_value' => $value], 'setting_key = ?', [$key]);
    else db()->insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
}

// ─── Fuel deliveries ────────────────────────────────────────────────────────
/** A delivery arrived: tank = what is left now + the delivered liters. Returns the new fuel status. */
function madeyaAddFuelDelivery($controllerId, $liters, $supplier, $note, $enteredBy) {
    ensureOpsTables(); ensureStationColumns();
    $f = madeyaFuelStatus($controllerId);
    $after = round(($f['set'] ? (float)$f['remaining'] : 0.0) + (float)$liters, 1);
    db()->query("INSERT INTO fuel_deliveries (controller_id, kind, liters, supplier, note, tank_after, entered_by, created_at) VALUES (?,?,?,?,?,?,?,?)",
        [(int)$controllerId, 'delivery', round((float)$liters, 1), $supplier !== '' ? $supplier : null, $note !== '' ? $note : null, $after, $enteredBy, date('Y-m-d H:i:s')]);
    db()->query("UPDATE controllers SET fuel_available_liters = ?, fuel_updated_at = ? WHERE id = ?", [$after, date('Y-m-d H:i:s'), (int)$controllerId]);
    return madeyaFuelStatus($controllerId);
}
/** The controller measured the tank: overwrite what the system thinks is left (logged as a "reading"). */
function madeyaSetFuelReading($controllerId, $liters, $enteredBy) {
    ensureOpsTables(); ensureStationColumns();
    db()->query("INSERT INTO fuel_deliveries (controller_id, kind, liters, tank_after, entered_by, created_at) VALUES (?,?,?,?,?,?)",
        [(int)$controllerId, 'reading', round((float)$liters, 1), round((float)$liters, 1), $enteredBy, date('Y-m-d H:i:s')]);
    db()->query("UPDATE controllers SET fuel_available_liters = ?, fuel_updated_at = ? WHERE id = ?", [round((float)$liters, 1), date('Y-m-d H:i:s'), (int)$controllerId]);
    return madeyaFuelStatus($controllerId);
}

// ─── Telegram fuel-shortage alert ───────────────────────────────────────────
function madeyaTgToken() {
    if (defined('MADEYA_TG_BOT_TOKEN') && MADEYA_TG_BOT_TOKEN !== '') return MADEYA_TG_BOT_TOKEN;
    $e = getenv('TG_BOT_TOKEN');
    if ($e) return $e;
    return trim(madeyaSettingGet('fuel_alert_bot_token', ''));
}
function madeyaTgCall($method, $payload) {
    $tok = madeyaTgToken();
    if ($tok === '' || !function_exists('curl_init')) return null;
    $ch = curl_init('https://api.telegram.org/bot' . $tok . '/' . $method);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $resp = curl_exec($ch); curl_close($ch);
    $j = $resp ? json_decode($resp, true) : null;
    return is_array($j) ? $j : null;
}
function madeyaTgSend($chatId, $text) {
    if (!empty($GLOBALS['MADEYA_TG_SENDER']) && is_callable($GLOBALS['MADEYA_TG_SENDER'])) return (bool)call_user_func($GLOBALS['MADEYA_TG_SENDER'], $chatId, $text);   // tests
    $j = madeyaTgCall('sendMessage', ['chat_id' => $chatId, 'text' => $text]);
    return is_array($j) && !empty($j['ok']);
}
function madeyaAlertChatIds() {
    $out = [];
    foreach (preg_split('/[\s,;]+/', madeyaSettingGet('fuel_alert_chat_ids', '')) as $c) if ($c !== '' && preg_match('/^-?\d{3,20}$/', $c)) $out[] = $c;
    return array_values(array_unique($out));
}
function madeyaFuelAlertEnabled() { return madeyaSettingGet('fuel_alert_enabled', '0') === '1' && madeyaAlertChatIds() && madeyaTgToken() !== ''; }

/** Sends the alert to every admin chat. $force = test message. Returns how many chats accepted it. */
function madeyaFuelAlertText($controllerId, $f) {
    $st = db()->fetchOne("SELECT station_name, region FROM controllers WHERE id = ?", [(int)$controllerId]);
    $name = $st ? $st['station_name'] . ($st['region'] ? ' (' . $st['region'] . ')' : '') : 'Station #' . $controllerId;
    if ($f['status'] === 'out') return "FUEL FINISHED - $name\nNo fuel left. " . $f['queued'] . " vehicle(s) still waiting.\n" . date('d/m/Y H:i');
    return "FUEL SHORTAGE - $name\n" . $f['queued'] . " vehicle(s) waiting need about " . $f['queue_liters'] . " L, only " . $f['remaining'] . " L left (short by about " . $f['short_by'] . " L).\n" . date('d/m/Y H:i');
}
/** Call after anything that changes the queue or the fuel. Sends once when a station turns short/out, again every 3 hours while it stays that way. Never throws. */
function madeyaFuelAlertCheck($controllerId) {
    try {
        ensureOpsTables();
        $f = madeyaFuelStatus($controllerId);
        $row = db()->fetchOne("SELECT fuel_alert_state, fuel_alert_at, (fuel_alert_at IS NOT NULL AND fuel_alert_at > DATE_SUB(NOW(), INTERVAL 3 HOUR)) AS recent FROM controllers WHERE id = ?", [(int)$controllerId]);
        if (!$row) return;
        $bad = in_array($f['status'], ['short', 'out'], true);
        if (!$bad) { if (!empty($row['fuel_alert_state'])) db()->query("UPDATE controllers SET fuel_alert_state = NULL WHERE id = ?", [(int)$controllerId]); return; }
        if ($row['fuel_alert_state'] === $f['status'] && (int)$row['recent'] === 1) return;
        if (!madeyaFuelAlertEnabled()) return;
        $txt = madeyaFuelAlertText($controllerId, $f);
        $ok = 0; foreach (madeyaAlertChatIds() as $c) if (madeyaTgSend($c, $txt)) $ok++;
        if ($ok) db()->query("UPDATE controllers SET fuel_alert_state = ?, fuel_alert_at = NOW() WHERE id = ?", [$f['status'], (int)$controllerId]);
    } catch (Exception $e) { error_log('madeyaFuelAlertCheck: ' . $e->getMessage()); }
}

// ─── Day closing ────────────────────────────────────────────────────────────
/** The closing row for this station's day (today by default) when the day is closed, else null. */
function madeyaDayClosed($controllerId, $date = null) {
    ensureOpsTables();
    $date = $date ?: date('Y-m-d');
    $r = db()->fetchOne("SELECT * FROM day_closings WHERE controller_id = ? AND close_date = ? AND reopened_at IS NULL", [(int)$controllerId, $date]);
    return $r ?: null;
}
function madeyaDayClosedResult($c) {
    return ['success' => false, 'code' => 'DAY_CLOSED', 'http_code' => 403, 'message' => 'The day is closed at this station (' . date('H:i', strtotime($c['closed_at'])) . '). Ask an admin to reopen it.'];
}
function madeyaCloseDay($controllerId, $closedBy, $note) {
    ensureOpsTables();
    $date = date('Y-m-d');
    if (madeyaDayClosed($controllerId, $date)) return ['success' => false, 'message' => 'The day is already closed'];
    $L = madeyaLiterStats((int)$controllerId, $date);
    $F = madeyaFuelStatus((int)$controllerId);
    $old = db()->fetchOne("SELECT id FROM day_closings WHERE controller_id = ? AND close_date = ?", [(int)$controllerId, $date]);
    if ($old) db()->query("DELETE FROM day_closings WHERE id = ?", [$old['id']]);   // a reopened day that is closed again
    db()->query("INSERT INTO day_closings (controller_id, close_date, closed_at, closed_by, served, liters, queued_left, fuel_remaining, note) VALUES (?,?,?,?,?,?,?,?,?)",
        [(int)$controllerId, $date, date('Y-m-d H:i:s'), $closedBy, (int)$L['served'], (float)$L['liters'], (int)$L['queued'], $F['set'] ? (float)$F['remaining'] : null, $note !== '' ? $note : null]);
    return ['success' => true, 'date' => $date, 'served' => (int)$L['served'], 'liters' => (float)$L['liters'], 'queued_left' => (int)$L['queued']];
}

// ─── Waiting-time estimate for drivers ──────────────────────────────────────
/** Minutes a vehicle arriving now would wait at this station. Based on the last 90 minutes of serving, or today's average. null = not enough data. */
function madeyaWaitEstimate($controllerId, $queued) {
    if ($queued <= 0) return ['minutes' => 0, 'basis' => 'none'];
    $r = db()->fetchOne("SELECT COUNT(*) n FROM report WHERE controller_id = ? AND served_at >= DATE_SUB(NOW(), INTERVAL 90 MINUTE)", [(int)$controllerId]);
    $n = $r ? (int)$r['n'] : 0;
    if ($n >= 3) { $perMin = $n / 90.0; $basis = 'recent'; }
    else {
        $t = db()->fetchOne("SELECT COUNT(*) n, TIMESTAMPDIFF(MINUTE, MIN(served_at), NOW()) m FROM report WHERE controller_id = ? AND report_date = ?", [(int)$controllerId, date('Y-m-d')]);
        if (!$t || (int)$t['n'] < 3 || (int)$t['m'] < 10) return ['minutes' => null, 'basis' => null];
        $perMin = (int)$t['n'] / max(30.0, (float)$t['m']); $basis = 'today';
    }
    if ($perMin <= 0) return ['minutes' => null, 'basis' => null];
    $min = $queued / $perMin;
    return ['minutes' => (int)(max(5, round($min / 5) * 5)), 'basis' => $basis];
}
