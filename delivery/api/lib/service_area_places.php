<?php
// ============================================================
// ETEL Delivery and Ride - quick zones per city (the "address shortcuts" riders tap when they set pickup or drop-off).
//   * One row = one place (name, Amharic name, point) that belongs to a service area (city) by its code.
//   * Admin edits them: Admin > Service areas > Places. Riders in a city only see that city's places.
//   * Hawassa's twelve zones (the list that used to be fixed inside the rider app) are seeded once, the first time the table is created.
//     After that the admin owns the list: deleting all of them does not bring them back.
//   * A city with no places simply shows no shortcuts. Nothing here blocks a booking. ASCII only (Amharic as \u escapes).
// ============================================================

if (!function_exists('etel_area_places_ensure')) {

function etel_area_places_ensure($pdo) {
    static $done = null;
    if ($done !== null) return $done;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `service_area_places` (
            `id` INT(11) NOT NULL AUTO_INCREMENT, `area_code` VARCHAR(40) NOT NULL, `name` VARCHAR(80) NOT NULL, `name_am` VARCHAR(80) NOT NULL DEFAULT '',
            `lat` DECIMAL(10,7) NOT NULL, `lng` DECIMAL(10,7) NOT NULL, `active` TINYINT(1) NOT NULL DEFAULT 1, `sort_order` INT(11) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`), KEY `idx_place_area` (`area_code`, `active`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (function_exists('etel_areas_ensure')) etel_areas_ensure($pdo);
        $seeded = $pdo->query("SELECT v FROM service_area_settings WHERE k = 'places_seeded'")->fetchColumn();
        if ($seeded === false) {
            $has = $pdo->query("SELECT COUNT(*) FROM service_areas WHERE code = 'hawassa'")->fetchColumn();
            if (intval($has) > 0) {
                $ins = $pdo->prepare("INSERT INTO service_area_places (area_code, name, name_am, lat, lng, active, sort_order, created_at, updated_at) VALUES ('hawassa', ?, ?, ?, ?, 1, ?, NOW(), NOW())");
                $zones = array(
                    array('Mehal Ketema', '\u1218\u1203\u120d \u12a8\u1270\u121b', 7.0606, 38.4760),
                    array('Tabor', '\u1273\u1266\u122d', 7.0395, 38.4895),
                    array('Addis Ketema', '\u12a0\u12f2\u1235 \u12a8\u1270\u121b', 7.0700, 38.4720),
                    array('Haik Dar', '\u1210\u12ed\u1245 \u12f3\u122d', 7.0648, 38.4560),
                    array('Tulu Dimtu', '\u1271\u1209 \u12f5\u121d\u1271', 7.0500, 38.4990),
                    array('Misrak', '\u121d\u1235\u122b\u1245', 7.0620, 38.4945),
                    array('Referral', '\u122a\u1348\u122b\u120d', 7.0532, 38.4812),
                    array('Hawela', '\u1210\u12cb\u120b', 7.0795, 38.4850),
                    array('Dore Bafana', '\u12f6\u122c \u1263\u134b\u1293', 7.0895, 38.4700),
                    array('Stadium', '\u1235\u1273\u12f2\u12e8\u121d', 7.0560, 38.4690),
                    array('Liqe Mender', '\u120d\u1240 \u1218\u1295\u12f0\u122d', 7.0480, 38.4725),
                    array('University', '\u12e9\u1292\u1268\u122d\u1232\u1272', 7.0452, 38.4958),
                );
                foreach ($zones as $i => $z) $ins->execute(array($z[0], json_decode('"' . $z[1] . '"'), $z[2], $z[3], $i + 1));
            }
            $pdo->exec("INSERT IGNORE INTO service_area_settings (k, v, updated_at) VALUES ('places_seeded', '1', NOW())");
        }
        $done = true;
    } catch (Exception $e) { error_log('[ETEL][places] ' . $e->getMessage()); $done = false; }
    return $done;
}

/* Places of one city as plain arrays. Active ones only unless asked. */
function etel_area_places_list($pdo, $areaCode, $activeOnly = true) {
    $out = array();
    if (!etel_area_places_ensure($pdo)) return $out;
    try {
        $q = $pdo->prepare('SELECT * FROM service_area_places WHERE area_code = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY sort_order ASC, id ASC');
        $q->execute(array(strtolower(trim((string)$areaCode))));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = array('id' => intval($r['id']), 'area_code' => $r['area_code'], 'name' => $r['name'], 'name_am' => $r['name_am'],
                'lat' => floatval($r['lat']), 'lng' => floatval($r['lng']), 'active' => intval($r['active']) === 1, 'sort_order' => intval($r['sort_order']));
        }
    } catch (Exception $e) { /* none */ }
    return $out;
}

} // end function_exists guard
