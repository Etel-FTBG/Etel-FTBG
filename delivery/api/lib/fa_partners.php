<?php
// ============================================================
// ETEL Field Agents - partner onboarding library.
// An agent registers a courier, a driver or a shop (with its products). The submission is staged in fa_partners; only when the owner approves
// does it become a real account. The agent is then paid a fixed fee once (an fa_earnings row, kind partner_fee, so it shows in the agent's
// balance and in the normal payout batches). Nothing here is ASCII-sensitive text for the user except the messages, which are plain English.
// ============================================================
require_once __DIR__ . '/fa_core.php';

if (!function_exists('fa_partner_kinds')) {

function fa_partner_kinds() { return array('courier', 'driver', 'shop'); }

function fa_partner_labels() { return array('courier' => 'Courier', 'driver' => 'Driver', 'shop' => 'Shop / service'); }

/* Lets fa_earnings hold the fee row (older installs have kind ENUM('first_trip','repeat3','clawback')). Idempotent, done once per request. */
function fa_partner_ensure_schema($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $c = $pdo->query("SHOW COLUMNS FROM fa_earnings LIKE 'kind'")->fetch(PDO::FETCH_ASSOC);
        if ($c && stripos($c['Type'], 'partner_fee') === false) {
            $pdo->exec("ALTER TABLE fa_earnings MODIFY COLUMN kind ENUM('first_trip','repeat3','clawback','partner_fee') NOT NULL");
        }
    } catch (Exception $e) { error_log('[ETEL][partners] schema: ' . $e->getMessage()); }
}

function fa_partner_fee($pdo, $kind) {
    $k = 'partner_fee_' . $kind;
    $v = fa_setting($pdo, $k);
    return $v === null ? 0 : floatval($v);
}

function fa_partner_clean_str($v, $max) {
    $v = trim((string) $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v);
    return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}

/* Only our own uploaded files, never a link to somebody else's server. */
function fa_partner_clean_url($v) {
    $v = trim((string) $v);
    if ($v === '') return '';
    if (strlen($v) > 500) return '';
    if (preg_match('#^(https?:)?//#i', $v) && !preg_match('#^https://(www\.)?ethelmarket\.com/#i', $v)) return '';
    if (preg_match('/^(javascript|data):/i', $v)) return '';
    return $v;
}

function fa_partner_clean_items($items) {
    $out = array();
    if (!is_array($items)) return $out;
    $cats = array('food', 'grocery', 'package', 'pharmacy', 'flower');
    foreach ($items as $it) {
        if (!is_array($it) || count($out) >= 200) continue;
        $name = fa_partner_clean_str(isset($it['name']) ? $it['name'] : '', 100);
        if ($name === '') continue;
        $cat = isset($it['category']) ? strtolower(trim((string) $it['category'])) : 'food';
        if (!in_array($cat, $cats, true)) $cat = 'food';
        $price = isset($it['price']) && is_numeric($it['price']) ? round(floatval($it['price']), 2) : 0;
        $out[] = array(
            'name' => $name, 'name_am' => fa_partner_clean_str(isset($it['name_am']) ? $it['name_am'] : '', 100),
            'description' => fa_partner_clean_str(isset($it['description']) ? $it['description'] : '', 500),
            'category' => $cat, 'price' => $price, 'image_url' => fa_partner_clean_url(isset($it['image_url']) ? $it['image_url'] : ''),
            'prep_time' => isset($it['prep_time']) && is_numeric($it['prep_time']) ? max(0, min(240, intval($it['prep_time']))) : 15,
        );
    }
    return $out;
}

/* The details we keep for each kind: clean text, known keys only. */
function fa_partner_clean_data($kind, $d) {
    if (!is_array($d)) $d = array();
    $str = function ($k, $max) use ($d) { return fa_partner_clean_str(isset($d[$k]) ? $d[$k] : '', $max); };
    $url = function ($k) use ($d) { return fa_partner_clean_url(isset($d[$k]) ? $d[$k] : ''); };
    $out = array('address' => $str('address', 200), 'zone' => $str('zone', 80), 'city' => $str('city', 60), 'notes' => $str('notes', 500),
        'id_type' => $str('id_type', 30), 'id_photo_url' => $url('id_photo_url'), 'selfie_url' => $url('selfie_url'),
        'emergency_name' => $str('emergency_name', 100), 'emergency_phone' => $str('emergency_phone', 20));
    if ($kind === 'courier') {
        $out += array('vehicle_type' => $str('vehicle_type', 40), 'plate_number' => $str('plate_number', 30), 'licence_number' => $str('licence_number', 60), 'licence_photo_url' => $url('licence_photo_url'));
    } elseif ($kind === 'driver') {
        $out += array('vehicle_type' => $str('vehicle_type', 40), 'vehicle_make' => $str('vehicle_make', 60), 'vehicle_color' => $str('vehicle_color', 40),
            'vehicle_year' => isset($d['vehicle_year']) && is_numeric($d['vehicle_year']) ? intval($d['vehicle_year']) : 0, 'vehicle_plate' => $str('vehicle_plate', 30),
            'license_name' => $str('license_name', 100), 'license_number' => $str('license_number', 60), 'license_dob' => $str('license_dob', 10), 'license_photo_url' => $url('license_photo_url'));
    } else {
        $lat = isset($d['lat']) && is_numeric($d['lat']) ? round(floatval($d['lat']), 7) : null;
        $lng = isset($d['lng']) && is_numeric($d['lng']) ? round(floatval($d['lng']), 7) : null;
        $out += array('business_name' => $str('business_name', 120), 'service_type' => $str('service_type', 40), 'description' => $str('description', 500),
            'logo_url' => $url('logo_url'), 'cover_url' => $url('cover_url'), 'opens_at' => $str('opens_at', 5), 'closes_at' => $str('closes_at', 5),
            'business_licence' => $str('business_licence', 60), 'lat' => $lat, 'lng' => $lng);
    }
    return $out;
}

/* What is still missing before a submission can go to the owner. Returns array(field => message); empty = complete. */
function fa_partner_missing($pdo, $kind, $name, $phone, $d, $items) {
    $m = array();
    if (mb_strlen($name, 'UTF-8') < 2) $m['name'] = 'Write the full name.';
    if (etel_validate_phone($phone) === false) $m['phone'] = 'Enter a valid Ethiopian mobile number, e.g. 0911234567.';
    if ($d['id_photo_url'] === '') $m['id_photo_url'] = 'Add a clear photo of the ID card.';
    if ($d['selfie_url'] === '') $m['selfie_url'] = 'Add a selfie of the person.';
    if ($d['emergency_phone'] === '' || etel_validate_phone($d['emergency_phone']) === false) $m['emergency_phone'] = 'Add a contact number of a relative or friend.';
    if ($kind === 'courier') {
        if ($d['vehicle_type'] === '') $m['vehicle_type'] = 'Choose the vehicle.';
    } elseif ($kind === 'driver') {
        if ($d['vehicle_type'] === '') $m['vehicle_type'] = 'Choose the vehicle.';
        if ($d['vehicle_plate'] === '') $m['vehicle_plate'] = 'Write the plate number.';
        if ($d['license_number'] === '') $m['license_number'] = 'Write the driving licence number.';
        if ($d['license_photo_url'] === '') $m['license_photo_url'] = 'Add a photo of the driving licence.';
    } else {
        if ($d['business_name'] === '') $m['business_name'] = 'Write the shop name.';
        if ($d['service_type'] === '') $m['service_type'] = 'Choose what the shop sells.';
        if ($d['address'] === '') $m['address'] = 'Write the shop address.';
        if ($d['lat'] === null || $d['lng'] === null) $m['location'] = 'Stand at the shop and use "Use my location".';
        if ($d['logo_url'] === '') $m['logo_url'] = 'Add the shop logo or a photo of the front.';
        $need = intval(fa_setting($pdo, 'partner_shop_min_items'));
        if (count($items) < $need) $m['items'] = 'Add at least ' . $need . ' products with name, price and photo (you have ' . count($items) . ').';
        foreach ($items as $i => $it) { if ($it['price'] <= 0) { $m['items'] = 'Every product needs a price (check "' . $it['name'] . '").'; break; } }
    }
    return $m;
}

/* Flags for the owner. Score is only a guide; nothing is paid or created before a person approves. Returns array(score, flags[]). */
function fa_partner_risk($pdo, $agent, $row) {
    $flags = array(); $score = 0;
    $last9 = fa_phone_last9($row['phone']);
    $q = $pdo->prepare("SELECT COUNT(*) FROM fa_partners WHERE phone_last9 = ? AND id <> ? AND status IN ('submitted','approved','changes')");
    $q->execute(array($last9, intval($row['id'])));
    if (intval($q->fetchColumn()) > 0) { $flags[] = array('code' => 'PHONE_IN_OTHER_SUBMISSION', 'text' => 'The same phone number is in another partner submission.'); $score += 40; }
    if ($row['id_number'] !== '') {
        $q = $pdo->prepare("SELECT COUNT(*) FROM fa_partners WHERE id_number = ? AND id <> ? AND status IN ('submitted','approved','changes')");
        $q->execute(array($row['id_number'], intval($row['id'])));
        if (intval($q->fetchColumn()) > 0) { $flags[] = array('code' => 'ID_IN_OTHER_SUBMISSION', 'text' => 'The same ID number is in another partner submission.'); $score += 40; }
    }
    if ($last9 !== '' && $last9 === fa_phone_last9(isset($agent['phone']) ? $agent['phone'] : '')) { $flags[] = array('code' => 'AGENT_OWN_PHONE', 'text' => 'The partner phone is the agent\'s own phone.'); $score += 50; }
    if ($last9 !== '' && $last9 === fa_phone_last9(isset($agent['payout_phone']) ? $agent['payout_phone'] : '')) { $flags[] = array('code' => 'PAYOUT_IS_PARTNER', 'text' => 'The agent\'s payout number is the partner\'s phone.'); $score += 30; }
    $q = $pdo->prepare("SELECT SUM(status = 'rejected') AS rej, COUNT(*) AS n FROM fa_partners WHERE agent_id = ? AND status IN ('approved','rejected')");
    $q->execute(array(intval($agent['id'])));
    $h = $q->fetch(PDO::FETCH_ASSOC);
    if ($h && intval($h['n']) >= 3 && intval($h['rej']) * 100 / max(1, intval($h['n'])) >= 40) { $flags[] = array('code' => 'AGENT_REJECT_RATE', 'text' => 'Many of this agent\'s earlier submissions were rejected.'); $score += 20; }
    $q = $pdo->prepare("SELECT COUNT(*) FROM fa_partners WHERE agent_id = ? AND submitted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $q->execute(array(intval($agent['id'])));
    if (intval($q->fetchColumn()) >= 5) { $flags[] = array('code' => 'AGENT_BURST', 'text' => 'This agent sent many submissions in one day.'); $score += 15; }
    return array(min(100, $score), $flags);
}

/* ---- making the real account on approval ---- */

/* The new person sets their own password with "Forgot password" and the phone number; nobody ever sees one. */
function fa_partner_random_hash() { return password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT); }

function fa_partner_create_account($pdo, $row, $adminId) {
    require_once __DIR__ . '/../validate.php';
    require_once __DIR__ . '/schema_helpers.php';
    $d = json_decode((string) $row['data'], true); if (!is_array($d)) $d = array();
    $items = json_decode((string) $row['items'], true); if (!is_array($items)) $items = array();
    $phone = etel_validate_phone($row['phone']);
    if ($phone === false) throw new Exception('The phone number is not valid any more.');
    if (!etel_phone_lock($pdo, $phone)) throw new Exception('Please try again in a moment.');
    if (etel_phone_taken($pdo, $phone)) { etel_phone_unlock($pdo, $phone); throw new Exception('That phone number already has an ETEL account.'); }
    $kind = $row['kind'];
    $result = array('user_id' => 0, 'sg_id' => 0, 'code' => '', 'claim' => null);
    try {
        if ($kind === 'courier') { require_once __DIR__ . '/courier_details.php'; require_once __DIR__ . '/courier_approval.php'; etel_courier_details_ensure($pdo); etel_courier_approval_ready($pdo); }
        if ($kind === 'driver') {
            rides_add_missing_cols($pdo, 'ride_drivers', array('vehicle_plate' => "vehicle_plate VARCHAR(30) DEFAULT ''", 'vehicle_type' => "vehicle_type VARCHAR(40) DEFAULT 'Bajaj'",
                'vehicle_make' => "vehicle_make VARCHAR(60) DEFAULT ''", 'vehicle_color' => "vehicle_color VARCHAR(40) DEFAULT ''", 'vehicle_year' => 'vehicle_year SMALLINT UNSIGNED DEFAULT 0', 'onboarded' => 'onboarded TINYINT(1) DEFAULT 0'));
        }
        if ($kind === 'shop') {
            try { $pdo->query('SELECT lat,lng FROM service_givers LIMIT 1'); } catch (Exception $e) { try { $pdo->exec('ALTER TABLE service_givers ADD COLUMN lat DECIMAL(10,7) NULL, ADD COLUMN lng DECIMAL(10,7) NULL'); } catch (Exception $e2) {} }
            require_once __DIR__ . '/menu_catalog.php'; etel_ensure_catalog_schema($pdo);
            require_once __DIR__ . '/sg_claim.php';
        }
        $role = $kind === 'shop' ? 'service_giver' : $kind;
        $uc = etel_table_columns($pdo, 'users');
        $f = array('name', 'phone', 'password_hash', 'role'); $m = array('?', '?', '?', '?');
        $v = array(fa_partner_clean_str($row['name'], 100), $phone, fa_partner_random_hash(), $role);
        if (in_array('active', $uc, true)) { $f[] = 'active'; $m[] = '1'; }
        $f[] = 'created_at'; $m[] = 'NOW()';
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO users (' . implode(',', $f) . ') VALUES (' . implode(',', $m) . ')')->execute($v);
        $uid = intval($pdo->lastInsertId());
        if (!$uid) throw new Exception('no user id');
        $result['user_id'] = $uid;
        if ($kind === 'courier') {
            $code = 'C-' . str_pad($uid, 5, '0', STR_PAD_LEFT); $result['code'] = $code;
            $cc = etel_table_columns($pdo, 'couriers');
            $cf = array('user_id', 'courier_code'); $cm = array('?', '?'); $cv = array($uid, $code);
            if (in_array('vehicle_type', $cc, true)) { $cf[] = 'vehicle_type'; $cm[] = '?'; $cv[] = $d['vehicle_type']; }
            if ($d['zone'] !== '') { foreach (array('home_zone', 'zone') as $zc) { if (in_array($zc, $cc, true)) { $cf[] = $zc; $cm[] = '?'; $cv[] = $d['zone']; } } }
            $cf[] = 'status'; $cm[] = "'offline'";
            if (in_array('rating', $cc, true)) { $cf[] = 'rating'; $cm[] = '0.00'; }
            if (in_array('total_deliveries', $cc, true)) { $cf[] = 'total_deliveries'; $cm[] = '0'; }
            if (in_array('active', $cc, true)) { $cf[] = 'active'; $cm[] = '1'; }
            if (in_array('approval_status', $cc, true)) { $cf[] = 'approval_status'; $cm[] = "'approved'"; }
            if (in_array('created_at', $cc, true)) { $cf[] = 'created_at'; $cm[] = 'NOW()'; }
            $pdo->prepare('INSERT INTO couriers (' . implode(',', $cf) . ') VALUES (' . implode(',', $cm) . ')')->execute($cv);
            $pdo->prepare('INSERT INTO courier_details (user_id, id_type, id_number, id_photo_url, selfie_url, licence_number, licence_photo_url, emergency_name, emergency_phone, address, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute(array($uid, (array_key_exists($d['id_type'], etel_courier_id_types()) ? $d['id_type'] : 'national_id'), $row['id_number'], $d['id_photo_url'], $d['selfie_url'], $d['licence_number'], $d['licence_photo_url'], $d['emergency_name'], $d['emergency_phone'], $d['address'], intval($adminId)));
        } elseif ($kind === 'driver') {
            $cols = array('user_id' => $uid, 'online' => 0, 'vehicle_type' => $d['vehicle_type'], 'vehicle_make' => $d['vehicle_make'], 'vehicle_color' => $d['vehicle_color'], 'vehicle_year' => $d['vehicle_year'],
                'vehicle_plate' => $d['vehicle_plate'], 'license_number' => $d['license_number'], 'license_name' => $d['license_name'], 'license_dob' => ($d['license_dob'] !== '' ? $d['license_dob'] : null),
                'selfie_url' => $d['selfie_url'], 'license_photo_url' => $d['license_photo_url'], 'onboarded' => 1);
            $have = etel_table_columns($pdo, 'ride_drivers');
            foreach (array_keys($cols) as $n) { if (!in_array($n, $have, true)) unset($cols[$n]); }
            $names = array_keys($cols);
            $pdo->prepare('INSERT INTO ride_drivers (' . implode(',', $names) . ', last_seen) VALUES (' . implode(',', array_fill(0, count($names), '?')) . ', NOW())')->execute(array_values($cols));
        } else {
            $sgCode = 'SG-' . str_pad($uid, 5, '0', STR_PAD_LEFT); $result['code'] = $sgCode;
            $pdo->prepare("INSERT INTO service_givers (user_id, service_giver_code, business_name, service_type, zone, address, status, is_online, average_rating, total_deliveries, completed_deliveries, failed_deliveries, total_earnings, rating_count, created_at)
                           VALUES (?,?,?,?,?,?, 'verified', 0, 0.00, 0, 0, 0, 0, 0, NOW())")
                ->execute(array($uid, $sgCode, $d['business_name'], $d['service_type'], $d['zone'], $d['address']));
            $sgId = intval($pdo->lastInsertId()); $result['sg_id'] = $sgId;
            $sc = etel_table_columns($pdo, 'service_givers');
            $set = array(); $val = array();
            foreach (array('lat' => $d['lat'], 'lng' => $d['lng'], 'city' => $d['city'], 'logo_url' => $d['logo_url'], 'cover_url' => $d['cover_url'], 'opens_at' => $d['opens_at'], 'closes_at' => $d['closes_at']) as $col => $x) {
                if (in_array($col, $sc, true) && $x !== null && $x !== '') { $set[] = "$col = ?"; $val[] = $x; }
            }
            if ($set) { $val[] = $sgId; $pdo->prepare('UPDATE service_givers SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($val); }
            $ins = $pdo->prepare('INSERT INTO items (service_giver_id, item_code, name, name_am, description, category, price, original_price, stock_quantity, preparation_time, image_url, is_available) VALUES (?,?,?,?,?,?,?,?,100,?,?,1)');
            foreach ($items as $it) {
                $ins->execute(array($sgId, etel_catalog_new_item_code($pdo, $it['category']), $it['name'], ($it['name_am'] !== '' ? $it['name_am'] : null), $it['description'], $it['category'], $it['price'], $it['price'], $it['prep_time'], $it['image_url']));
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        etel_phone_unlock($pdo, $phone);
        throw $e;
    }
    return $result;
}

/* The fixed fee, once. pay_days = 0: payable at once; otherwise pending until the date (the normal earnings job releases it). Returns the earning id. */
function fa_partner_pay_fee($pdo, $row) {
    fa_partner_ensure_schema($pdo);
    $fee = fa_partner_fee($pdo, $row['kind']);
    if ($fee <= 0) return array(0, 0);
    $days = intval(fa_setting($pdo, 'partner_pay_days'));
    $status = $days > 0 ? 'pending' : 'payable';
    $payAt = date('Y-m-d H:i:s', time() + $days * 86400);
    $pdo->prepare("INSERT IGNORE INTO fa_earnings (referral_id, agent_id, kind, parent_id, amount, status, reason_note, earned_at, payable_at, created_at, updated_at)
                   VALUES (?, ?, 'partner_fee', 0, ?, ?, ?, NOW(), ?, NOW(), NOW())")
        ->execute(array(-intval($row['id']), intval($row['agent_id']), $fee, $status, 'Partner ' . $row['kind'] . ' #' . intval($row['id']), $payAt));
    return array(intval($pdo->lastInsertId()), $fee);
}

}
