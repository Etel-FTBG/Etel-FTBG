<?php
// ============================================================
// Investor Relations - digital Investor Deal Sheet
//   deal_config            (public)  the standard terms and the units still open
//   deal_submit            (public)  an investor fills in and signs the sheet
//   admin_deal_list / admin_deal_get / admin_deal_review / admin_deal_enroll   (EtelGroup admin)
// ASCII only.
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/../lib/Deals.php';

function ir_deal_row_public(array $r): array {
    unset($r['signature_png'], $r['ip_hash'], $r['user_agent']);
    return $r;
}

switch ($action) {

case 'deal_config': {
    $pdo = eg_db();
    ir_ensure_core_tables($pdo);
    ok(ir_deal_config($pdo));
    break;
}

case 'deal_submit': {
    $pdo = eg_db();
    ir_ensure_core_tables($pdo);
    ir_deal_ensure_table($pdo);
    // Honeypot: real people never fill the hidden "website" field.
    if (trim((string)($body['website'] ?? '')) !== '') fail('Could not submit.', 400);
    $ipHash = ir_deal_ip_hash();
    $recent = $pdo->prepare('SELECT COUNT(*) FROM ir_deal_sheets WHERE ip_hash = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
    $recent->execute([$ipHash]);
    if ((int)$recent->fetchColumn() >= 5) fail('Too many submissions from this connection. Please try again later.', 429);

    $lang = (($body['lang'] ?? 'en') === 'am') ? 'am' : 'en';
    $name = trim(preg_replace('/\s+/', ' ', (string)($body['investor_name'] ?? '')));
    $idNo = trim((string)($body['id_number'] ?? ''));
    $phone = trim((string)($body['phone'] ?? ''));
    $email = trim((string)($body['email'] ?? ''));
    $units = (int)($body['units'] ?? 0);
    $period = (string)($body['payout_period'] ?? 'monthly');
    $days = (int)($body['payout_days'] ?? 0);
    $closing = trim((string)($body['closing_date'] ?? ''));
    $bank = trim((string)($body['bank_account'] ?? ''));
    $sigName = trim(preg_replace('/\s+/', ' ', (string)($body['signature_name'] ?? '')));
    $sigPng = (string)($body['signature_png'] ?? '');

    if (mb_strlen($name) < 3 || mb_strlen($name) > 150) fail('Enter your full legal name.', 400);
    if (mb_strlen($idNo) < 3 || mb_strlen($idNo) > 80) fail('Enter your ID, passport or registration number.', 400);
    if (strlen(preg_replace('/\D/', '', $phone)) < 9 || strlen($phone) > 30) fail('Enter a valid phone number.', 400);
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190)) fail('Enter a valid email address or leave it empty.', 400);
    if (!in_array($period, ['monthly', 'quarterly', 'other'], true)) $period = 'monthly';
    if ($days < 0 || $days > 365) fail('Payout days must be between 0 and 365.', 400);
    if ($closing !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $closing);
        if (!$d || $d->format('Y-m-d') !== $closing) fail('Closing date must be a valid date.', 400);
    }
    if (mb_strlen($bank) > 190) fail('Bank account text is too long.', 400);
    if (empty($body['agree'])) fail('Please confirm the declaration before signing.', 400);
    if (mb_strlen($sigName) < 3) fail('Type your full name to sign.', 400);
    $pngSize = ir_deal_png_size($sigPng);
    if ($pngSize < 400 || $pngSize > 300000) fail('Please draw your signature in the box.', 400);

    $avail = ir_deal_available_units($pdo);
    if ($units < 1) fail('Enter how many Profit Shares you want to buy.', 400);
    if ($units > $avail) fail('Only ' . $avail . ' Profit Shares are open right now.', 409);

    $price = (float)IR_SHARE_PRICE;
    $amount = $units * $price;
    $pct = $units / IR_INVESTOR_POOL_SHARES * 100;
    $now = date('Y-m-d H:i:s');
    $ref = ir_deal_new_ref($pdo);
    $hash = hash('sha256', json_encode([$ref, $lang, $name, $idNo, $phone, $email, $units, $price, $period, $days, $closing, $bank, $sigName, hash('sha256', $sigPng), $now]));
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 190);
    $pdo->prepare('INSERT INTO ir_deal_sheets (ref_code, lang, investor_name, id_number, phone, email, units, unit_price, amount, pool_share_pct, payout_period, payout_days, closing_date, bank_account, signature_name, signature_png, signed_at, doc_hash, ip_hash, user_agent) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$ref, $lang, $name, $idNo, $phone, $email, $units, $price, $amount, $pct, $period, $days, $closing === '' ? null : $closing, $bank, $sigName, $sigPng, $now, $hash, $ipHash, $ua]);
    ir_log($pdo, 'public', 'deal_sheet_submitted', $ref . ': ' . $units . ' units');
    ok(['ref_code' => $ref, 'signed_at' => $now, 'doc_hash' => $hash, 'units' => $units, 'amount' => $amount, 'pool_share_pct' => $pct]);
    break;
}

case 'admin_deal_list': {
    eg_require_admin();
    $pdo = eg_db();
    ir_ensure_core_tables($pdo);
    ir_deal_ensure_table($pdo);
    $rows = $pdo->query('SELECT id, ref_code, lang, status, investor_name, phone, email, units, amount, pool_share_pct, created_at, reviewed_by, investor_id FROM ir_deal_sheets ORDER BY id DESC LIMIT 500')->fetchAll();
    ok(['sheets' => $rows, 'config' => ir_deal_config($pdo)]);
    break;
}

case 'admin_deal_get': {
    eg_require_admin();
    $pdo = eg_db();
    ir_deal_ensure_table($pdo);
    $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
    $s = $pdo->prepare('SELECT * FROM ir_deal_sheets WHERE id = ?');
    $s->execute([$id]);
    $r = $s->fetch();
    if (!$r) fail('Deal sheet not found.', 404);
    unset($r['ip_hash']);
    ok($r);
    break;
}

case 'admin_deal_review': {
    eg_require_admin();
    $pdo = eg_db();
    ir_deal_ensure_table($pdo);
    $id = (int)($body['id'] ?? 0);
    $decision = (string)($body['decision'] ?? '');
    $note = trim((string)($body['note'] ?? ''));
    if (!in_array($decision, ['accepted', 'rejected'], true)) fail('Decision must be accepted or rejected.', 400);
    if ($decision === 'rejected' && $note === '') fail('Add a short note saying why it is rejected.', 400);
    $s = $pdo->prepare('SELECT status FROM ir_deal_sheets WHERE id = ?');
    $s->execute([$id]);
    $cur = $s->fetchColumn();
    if ($cur === false) fail('Deal sheet not found.', 404);
    if ($cur === 'enrolled') fail('This sheet is already enrolled as an investor.', 409);
    $actor = eg_current_actor()['full_name'];
    $pdo->prepare('UPDATE ir_deal_sheets SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?')
        ->execute([$decision, $actor, mb_substr($note, 0, 500), $id]);
    ir_log($pdo, $actor, 'deal_sheet_' . $decision, '#' . $id);
    ok(['id' => $id, 'status' => $decision]);
    break;
}

case 'admin_deal_enroll': {
    eg_require_admin();
    $pdo = eg_db();
    ir_ensure_core_tables($pdo);
    ir_deal_ensure_table($pdo);
    $id = (int)($body['id'] ?? 0);
    $s = $pdo->prepare('SELECT * FROM ir_deal_sheets WHERE id = ?');
    $s->execute([$id]);
    $d = $s->fetch();
    if (!$d) fail('Deal sheet not found.', 404);
    if ($d['status'] !== 'accepted') fail('Accept the sheet first, then enroll the investor.', 409);
    $identifier = trim((string)($body['login_identifier'] ?? ''));
    if ($identifier === '') $identifier = $d['email'] !== '' ? $d['email'] : $d['phone'];
    $record = !empty($body['record_payment']);
    $tranche = (int)($body['tranche_number'] ?? 1);
    $date = trim((string)($body['purchase_date'] ?? date('Y-m-d')));
    $amountPaid = isset($body['amount_paid']) && $body['amount_paid'] !== '' ? (float)$body['amount_paid'] : (float)$d['amount'];
    if ($record) {
        if (!in_array($tranche, [1, 2], true)) fail('Tranche must be 1 or 2.', 400);
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) fail('Payment date must be a valid date.', 400);
        if ($amountPaid < 0) fail('Amount paid cannot be negative.', 400);
        $tr = $pdo->prepare('SELECT is_unlocked FROM ir_tranches WHERE tranche_number = ?');
        $tr->execute([$tranche]);
        if (!$tr->fetchColumn()) fail('That tranche is not unlocked yet.', 409);
        if ((int)$d['units'] > ir_deal_available_units($pdo)) fail('Not enough Profit Shares are open for this sheet any more.', 409);
    }
    $password = ir_deal_random_password(10);
    try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO ir_investors (name, contact_email, contact_phone, login_identifier, password_hash) VALUES (?,?,?,?,?)')
            ->execute([$d['investor_name'], $d['email'], $d['phone'], $identifier, password_hash($password, PASSWORD_DEFAULT)]);
        $invId = (int)$pdo->lastInsertId();
        if ($record) {
            $pdo->prepare('INSERT INTO ir_share_purchases (investor_id, purchase_date, shares, tranche_number, amount_paid) VALUES (?,?,?,?,?)')
                ->execute([$invId, $date, (int)$d['units'], $tranche, $amountPaid]);
        }
        $pdo->prepare("UPDATE ir_deal_sheets SET status = 'enrolled', investor_id = ? WHERE id = ?")->execute([$invId, $id]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->getCode() === '23000') fail('That login identifier is already in use by another investor. Choose a different one.', 409);
        throw $e;
    }
    ir_log($pdo, eg_current_actor()['full_name'], 'deal_sheet_enrolled', '#' . $id . ' -> investor #' . $invId . ($record ? ' with purchase' : ' without purchase'));
    ok(['investor_id' => $invId, 'login_identifier' => $identifier, 'temp_password' => $password, 'purchase_recorded' => $record]);
    break;
}

}
