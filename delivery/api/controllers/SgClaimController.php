<?php
// ============================================================
// ETEL Delivery - pre-registered businesses: admin hands one over, and its owner takes it over (public: the one-time claim code is the credential)
//   admin_sg_update_owner  { id, name?, phone? }  -> change the owner's name / sign-in phone                       (service_givers.manage)
//   admin_sg_claim_invite  { id }                 -> a new one-time claim code (shown once; older ones stop working) (service_givers.manage)
//   admin_sg_set_hours     { id, opens_at, closes_at }    -> opening hours ('HH:MM'; both empty clears them)               (service_givers.manage)
//   sg_claim_info      { code }                              -> which business this code opens (so the page can greet the owner)
//   sg_claim_complete  { code, phone, password, name? }      -> the owner sets their own phone number and password
// The business stays "pending": the owner can sign in to finish setting up, but it is hidden from customers and takes no orders until admin presses Verify. Wrong codes are rate limited.
// ============================================================

require_once __DIR__ . '/../lib/sg_claim.php';

switch ($action) {

case 'admin_sg_set_hours': {
    $pdo = db();
    $u = require_admin($pdo, 'service_givers.manage');
    if (etel_is_ride_only($pdo, $u)) fail('You can only change ride accounts', 403);
    require_once __DIR__ . '/../lib/shop_hours.php';
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if ($id <= 0) fail('id required', 400);
    $o_raw = isset($body['opens_at']) ? trim($body['opens_at']) : '';
    $c_raw = isset($body['closes_at']) ? trim($body['closes_at']) : '';
    if ($o_raw === '' && $c_raw === '') { $o = null; $c = null; }
    else {
        $o = etel_hours_clean($o_raw); $c = etel_hours_clean($c_raw);
        if ($o === null || $c === null) fail('Enter both times as HH:MM, for example 08:00 and 21:00', 400);
        if ($o === $c) fail('Opening and closing time cannot be the same', 400);
    }
    $chk = $pdo->prepare('SELECT id FROM service_givers WHERE id = ?');
    $chk->execute(array($id));
    if (!$chk->fetchColumn()) fail('Service giver not found', 404);
    etel_hours_ensure_columns($pdo);
    try { $pdo->prepare('UPDATE service_givers SET opens_at = ?, closes_at = ? WHERE id = ?')->execute(array($o, $c, $id)); }
    catch (Exception $e) { fail_ex($e, 'Update failed', 500); }
    ok(array('opens_at' => $o, 'closes_at' => $c, 'message' => $o === null ? 'Opening hours cleared' : 'Opening hours saved'));
    break;
}

case 'sg_claim_info': {
    $pdo = db();
    $code = isset($body['code']) ? trim($body['code']) : (isset($_GET['code']) ? trim($_GET['code']) : '');
    if ($code === '') fail('Enter your claim code', 400);
    rate_limit_check($pdo, 'sg_claim', 15, 600, 900);
    try { $c = etel_sg_claim_find($pdo, $code); } catch (Exception $e) { fail_ex($e, 'Could not check the code', 500); }
    if (!$c) { rate_limit_fail($pdo, 'sg_claim'); fail('This code is not valid, or it has expired. Ask ETEL for a new one.', 404); }
    ok(array('business_name' => $c['business_name'], 'service_type' => $c['service_type'], 'zone' => $c['zone'],
             'owner_name' => $c['owner_name'], 'expires_at' => $c['expires_at']));
    break;
}

case 'sg_claim_complete': {
    $pdo = db();
    $code = isset($body['code']) ? trim($body['code']) : '';
    $phone = isset($body['phone']) ? preg_replace('/[^0-9+]/', '', trim($body['phone'])) : '';
    $pw = isset($body['password']) ? (string) $body['password'] : '';
    $name = isset($body['name']) ? trim($body['name']) : '';
    if ($code === '') fail('Enter your claim code', 400);
    rate_limit_check($pdo, 'sg_claim', 15, 600, 900);
    if (strlen(preg_replace('/[^0-9]/', '', $phone)) < 9) fail('Enter your full phone number', 400);
    if (strlen($pw) < 8) fail('Password must be at least 8 characters', 400);
    try { $c = etel_sg_claim_find($pdo, $code); } catch (Exception $e) { fail_ex($e, 'Could not check the code', 500); }
    if (!$c) { rate_limit_fail($pdo, 'sg_claim'); fail('This code is not valid, or it has expired. Ask ETEL for a new one.', 404); }
    if ($c['status'] === 'suspended') fail('This account is suspended. Contact ETEL.', 403);

    if (!etel_phone_lock($pdo, $phone)) fail('Please try again in a moment', 429);
    $v = etel_phone_variants($phone);
    $q = $pdo->prepare('SELECT id FROM users WHERE id <> ? AND phone IN (' . implode(',', array_fill(0, count($v), '?')) . ') LIMIT 1');
    $q->execute(array_merge(array(intval($c['user_id'])), $v));
    if ($q->fetch()) fail('This phone number already has an ETEL account. Use a different number, or contact ETEL.', 409);

    try {
        $pdo->beginTransaction();
        $mark = $pdo->prepare('UPDATE sg_claim_codes SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
        $mark->execute(array(intval($c['claim_id'])));
        if ($mark->rowCount() !== 1) { $pdo->rollBack(); fail('This code was just used. Ask ETEL for a new one.', 409); }
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        if ($name !== '') $pdo->prepare('UPDATE users SET name = ?, phone = ?, password_hash = ? WHERE id = ?')->execute(array($name, $phone, $hash, intval($c['user_id'])));
        else $pdo->prepare('UPDATE users SET phone = ?, password_hash = ? WHERE id = ?')->execute(array($phone, $hash, intval($c['user_id'])));
        $pdo->prepare('UPDATE sg_claim_codes SET phone_is_placeholder = 0 WHERE sg_id = ?')->execute(array(intval($c['sg_id'])));
        $pdo->commit();
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); fail_ex($e, 'Could not save your details', 500); }
    etel_bump_token_version($pdo, intval($c['user_id']));
    rate_limit_clear($pdo, 'sg_claim');
    ok(array('message' => 'Your details are saved. You can sign in now to finish setting up your business. ETEL will check it and switch it on.',
             'business_name' => $c['business_name'], 'status' => $c['status']));
    break;
}

case 'admin_sg_update_owner': {
    $pdo = db();
    $u = require_admin($pdo, 'service_givers.manage');
    if (etel_is_ride_only($pdo, $u)) fail('You can only change ride accounts', 403);
    require_once __DIR__ . '/../lib/sg_claim.php';
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if ($id <= 0) fail('id required', 400);
    $name = isset($body['name']) ? trim($body['name']) : '';
    $phone = isset($body['phone']) ? preg_replace('/[^0-9+]/', '', trim($body['phone'])) : '';
    if ($name === '' && $phone === '') fail('Nothing to change', 400);
    if ($phone !== '' && strlen(preg_replace('/[^0-9]/', '', $phone)) < 9) fail('Enter a full phone number', 400);
    $g = $pdo->prepare('SELECT sg.user_id FROM service_givers sg WHERE sg.id = ? LIMIT 1');
    $g->execute(array($id));
    $row = $g->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('Service giver not found', 404);
    $uid = intval($row['user_id']);
    etel_sg_claim_ensure($pdo);
    if ($phone !== '') {
        if (!etel_phone_lock($pdo, $phone)) fail('Please try again in a moment', 429);
        $v = etel_phone_variants($phone);
        $q = $pdo->prepare('SELECT id FROM users WHERE id <> ? AND phone IN (' . implode(',', array_fill(0, count($v), '?')) . ') LIMIT 1');
        $q->execute(array_merge(array($uid), $v));
        if ($q->fetch()) fail('That phone number belongs to another account', 409);
    }
    try {
        if ($name !== '') $pdo->prepare('UPDATE users SET name = ? WHERE id = ?')->execute(array($name, $uid));
        if ($phone !== '') {
            $pdo->prepare('UPDATE users SET phone = ? WHERE id = ?')->execute(array($phone, $uid));
            $pdo->prepare('UPDATE sg_claim_codes SET phone_is_placeholder = 0 WHERE sg_id = ?')->execute(array($id));
        }
    } catch (Exception $e) { fail_ex($e, 'Could not save', 500); }
    ok(array('message' => 'Saved', 'id' => $id));
    break;
}

case 'admin_sg_claim_invite': {
    $pdo = db();
    $u = require_admin($pdo, 'service_givers.manage');
    if (etel_is_ride_only($pdo, $u)) fail('You can only change ride accounts', 403);
    require_once __DIR__ . '/../lib/sg_claim.php';
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if ($id <= 0) fail('id required', 400);
    $g = $pdo->prepare('SELECT sg.user_id, sg.status FROM service_givers sg WHERE sg.id = ? LIMIT 1');
    $g->execute(array($id));
    $row = $g->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('Service giver not found', 404);
    etel_sg_claim_ensure($pdo);
    $was = etel_sg_claim_state($pdo, $id);
    try { $c = etel_sg_claim_issue($pdo, $id, intval($row['user_id']), $was['placeholder_phone']); }
    catch (Exception $e) { fail_ex($e, 'Could not make the claim link', 500); }
    ok(array('message' => 'Claim code ready', 'id' => $id, 'claim_code' => $c['code'], 'claim_expires_at' => $c['expires_at']));
    break;
}

}
