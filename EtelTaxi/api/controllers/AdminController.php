<?php
require_once __DIR__ . '/../lib/taxi_payments.php';
require_once __DIR__ . '/../lib/etelid_bridge.php';

switch ($action) {

case 'admin_etelid_login': {
    $pdo = db();
    $identifier = isset($body['identifier']) ? trim($body['identifier']) : '';
    $password   = isset($body['password']) ? (string) $body['password'] : '';
    if (!$identifier || !$password) fail('identifier and password are required.');
    if (!defined('ETELID_SERVICE_KEY') || ETELID_SERVICE_KEY === '') fail('EtelID sign-in is not configured on this server yet.', 503);

    $url = 'https://' . ETELID_HOST . '/EtelId/wp-json/etelid/v1/service/authenticate';
    $ctx = stream_context_create(array('http' => array(
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nX-Etel-Service-Key: " . ETELID_SERVICE_KEY . "\r\n",
        'content' => json_encode(array('identifier' => $identifier, 'password' => $password, 'create_session' => true)),
        'timeout' => 15,
        'ignore_errors' => true,
    )));
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) fail('Could not reach EtelID right now.', 503);
    $decoded = json_decode($resp, true);
    if (!is_array($decoded) || empty($decoded['ok']) || empty($decoded['session_token'])) {
        fail(isset($decoded['message']) ? $decoded['message'] : 'Incorrect EtelID or password.', 401);
    }
    $member = $decoded['member'];

    // Security boundary, on purpose: this only recognizes an EtelID
    // that already matches an EXISTING admin account's phone number -
    // it never creates a new admin. Admin accounts are still only
    // ever created deliberately (admin_register / seed-admin.php),
    // same as before this feature. First successful match auto-links
    // the etel_id for faster sign-in next time.
    $s = $pdo->prepare('SELECT * FROM ' . tbl('taxi_admins') . ' WHERE etel_id = ? OR phone = ? LIMIT 1');
    $s->execute(array($member['etel_id'], $member['phone']));
    $admin = $s->fetch();

    // Always check Etel Group's CURRENT role assignment - keeps
    // staff_role in sync (a role renamed or removed in EtelGroup's
    // Employees panel takes effect on the very next login here) rather
    // than only mattering the one time an account gets auto-created.
    // Traffic-officer-specific access is handled separately in
    // OfficerController.php - a role here always grants this admin
    // dashboard, never the officer tool.
    $egRole = taxi_egid_check_role($member['phone']);
    $egGrantsAdmin = $egRole && strtolower(trim($egRole['role_at'])) !== 'traffic officer';

    if (!$admin) {
        if (!$egGrantsAdmin) fail('No admin account found for this EtelID. Ask a super admin to add you first.', 403);
        $pdo->prepare(
            'INSERT INTO ' . tbl('taxi_admins') . ' (association_id, full_name, phone, pin_hash, role, staff_role, is_etelgroup_managed, etel_id, created_at)
             VALUES (NULL, ?, ?, ?, "super_admin", ?, 1, ?, NOW())'
        )->execute(array($member['full_name'], $member['phone'], taxi_unusable_pin_hash(), $egRole['role_at'], $member['etel_id']));
        $s->execute(array($member['etel_id'], $member['phone']));
        $admin = $s->fetch();
    } else {
        // Already an existing admin account. If it's already confirmed
        // bridge-managed, EtelGroup must currently grant a role - that's
        // how "revoke instantly" is enforced. If not yet confirmed
        // managed, this could be a true pre-existing admin (created via
        // admin_register, should stay untouched forever) - but if
        // EtelGroup DOES grant a role for this phone right now, that's
        // the owner's deliberate signal to adopt this account into
        // managed status starting now.
        $wasManaged = !empty($admin['is_etelgroup_managed']);
        if ($wasManaged && !$egGrantsAdmin) {
            fail('Your Etel Group role at Etel Transport has been removed. Ask a super admin to reassign it.', 403);
        }
        if ($egGrantsAdmin) {
            $sets = array();
            $params = array();
            if ($egRole['role_at'] !== $admin['staff_role']) { $sets[] = 'staff_role=?'; $params[] = $egRole['role_at']; }
            if (!$wasManaged) { $sets[] = 'is_etelgroup_managed=1'; }
            if ($sets) {
                $params[] = $admin['id'];
                $pdo->prepare('UPDATE ' . tbl('taxi_admins') . ' SET ' . implode(', ', $sets) . ' WHERE id=?')->execute($params);
                $s->execute(array($member['etel_id'], $member['phone']));
                $admin = $s->fetch();
            }
        }
        // No egRole and never managed: true legacy admin - untouched.
    }
    if (!$admin) {
        fail('No admin account found for this EtelID. Ask a super admin to add you first.', 403);
    }
    if (empty($admin['etel_id'])) {
        $pdo->prepare('UPDATE ' . tbl('taxi_admins') . ' SET etel_id = ? WHERE id = ?')->execute(array($member['etel_id'], $admin['id']));
    }

    // Real EtelID session tokens work directly with require_admin_auth
    // too - but that function only checks taxi_admin_sessions right
    // now, so issue a normal admin session backed by this verified
    // identity rather than trying to reuse the EtelID token itself
    // (keeps admin auth's trust boundary in one place).
    $adminToken = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO ' . tbl('taxi_admin_sessions') . ' (admin_id, token, expires_at, created_at)
                   VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())')->execute(array($admin['id'], $adminToken));

    ok(array('token' => $adminToken, 'admin' => array(
        'full_name' => $admin['full_name'], 'role' => $admin['role'], 'association_id' => $admin['association_id'],
        'staff_role' => $admin['staff_role'] ?? null,
    )));
    break;
}

case 'admin_login': {
    $pdo = db();
    $phone = isset($body['phone']) ? trim($body['phone']) : '';
    $pin   = isset($body['pin']) ? (string) $body['pin'] : '';
    if (!$phone || !$pin) fail('phone and pin are required.');

    $s = $pdo->prepare('SELECT * FROM ' . tbl('taxi_admins') . ' WHERE phone = ?');
    $s->execute(array($phone));
    $admin = $s->fetch();
    if (!$admin || !password_verify($pin, $admin['pin_hash'])) fail('Incorrect phone number or PIN.', 401);

    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO ' . tbl('taxi_admin_sessions') . ' (admin_id, token, expires_at, created_at)
                   VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())')->execute(array($admin['id'], $token));

    ok(array('token' => $token, 'admin' => array(
        'full_name' => $admin['full_name'], 'role' => $admin['role'], 'association_id' => $admin['association_id'],
    )));
    break;
}

case 'admin_list_associations': {
    $pdo = db();
    require_admin_auth($pdo, 'associations.view');
    $s = $pdo->query('SELECT * FROM ' . tbl('taxi_associations') . ' ORDER BY name_am ASC');
    ok(array('associations' => $s->fetchAll()));
    break;
}

case 'admin_create_association': {
    $pdo = db();
    $admin = require_admin_auth($pdo, 'associations.manage');
    if ($admin['role'] !== 'super_admin') fail('Only a super admin can create associations.', 403);
    $nameAm = isset($body['name_am']) ? trim($body['name_am']) : '';
    if (!$nameAm) fail('name_am is required.');

    $pdo->prepare('INSERT INTO ' . tbl('taxi_associations') . '
        (name_am, name_en, route_am, route_en, monthly_due_amount, penalty_amount, grace_period_days, active, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())')->execute(array(
        $nameAm,
        isset($body['name_en']) ? trim($body['name_en']) : null,
        isset($body['route_am']) ? trim($body['route_am']) : null,
        isset($body['route_en']) ? trim($body['route_en']) : null,
        isset($body['monthly_due_amount']) ? floatval($body['monthly_due_amount']) : 100.00,
        isset($body['penalty_amount']) ? floatval($body['penalty_amount']) : 100.00,
        isset($body['grace_period_days']) ? intval($body['grace_period_days']) : 5,
    ));
    ok(array('id' => intval($pdo->lastInsertId())));
    break;
}

case 'admin_update_association': {
    $pdo = db();
    $admin = require_admin_auth($pdo, 'associations.manage');
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if (!$id) fail('id is required.');
    if ($admin['role'] !== 'super_admin' && intval($admin['association_id']) !== $id) fail('Not authorized for this association.', 403);

    $fields = array(); $vals = array();
    foreach (array('name_am','name_en','route_am','route_en') as $f) {
        if (isset($body[$f])) { $fields[] = "$f = ?"; $vals[] = trim($body[$f]); }
    }
    foreach (array('monthly_due_amount','penalty_amount') as $f) {
        if (isset($body[$f])) { $fields[] = "$f = ?"; $vals[] = floatval($body[$f]); }
    }
    if (isset($body['grace_period_days'])) { $fields[] = 'grace_period_days = ?'; $vals[] = intval($body['grace_period_days']); }
    if (isset($body['active'])) { $fields[] = 'active = ?'; $vals[] = intval($body['active']) ? 1 : 0; }
    if (!$fields) fail('No fields to update.');
    $vals[] = $id;
    $pdo->prepare('UPDATE ' . tbl('taxi_associations') . ' SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
    ok(array('message' => 'Association updated.'));
    break;
}

case 'admin_list_drivers': {
    $pdo = db();
    $admin = require_admin_auth($pdo, 'drivers.view');
    $assocFilter = isset($body['association_id']) ? intval($body['association_id']) : ($admin['association_id'] ? intval($admin['association_id']) : null);

    $sql = 'SELECT d.*, a.name_am AS association_name FROM ' . tbl('taxi_drivers') . ' d
            JOIN ' . tbl('taxi_associations') . ' a ON a.id = d.association_id';
    $params = array();
    if ($assocFilter) { $sql .= ' WHERE d.association_id = ?'; $params[] = $assocFilter; }
    $sql .= ' ORDER BY (d.identity_match = "mismatch") DESC, d.full_name ASC';
    $s = $pdo->prepare($sql);
    $s->execute($params);
    $drivers = $s->fetchAll();
    foreach ($drivers as &$d) {
        $st = taxi_compute_driver_status($pdo, $d['id'], $d['association_id']);
        $d['permit_status'] = $st['status'];
        unset($d['pin_hash']);
    }
    unset($d);
    ok(array('drivers' => $drivers));
    break;
}

case 'admin_register_driver': {
    $pdo = db();
    $admin = require_admin_auth($pdo, 'drivers.manage');
    $assocId = isset($body['association_id']) ? intval($body['association_id']) : intval($admin['association_id']);
    if ($admin['role'] !== 'super_admin' && intval($admin['association_id']) !== $assocId) fail('Not authorized for this association.', 403);

    $fullName = isset($body['full_name']) ? trim($body['full_name']) : '';
    $phone    = isset($body['phone']) ? trim($body['phone']) : '';
    $plate    = isset($body['vehicle_plate']) ? trim($body['vehicle_plate']) : '';
    $licenseNum  = isset($body['license_number']) ? trim($body['license_number']) : '';
    $licenseName = isset($body['license_full_name']) ? trim($body['license_full_name']) : '';
    $licenseDob  = isset($body['license_dob']) ? trim($body['license_dob']) : '';
    if (!$fullName || !$phone || !$plate || !$assocId) fail('full_name, phone, vehicle_plate, and association_id are required.');
    if (!$licenseNum || !$licenseName || !$licenseDob) fail('license_number, license_full_name, and license_dob are all required.');

    $dup = $pdo->prepare('SELECT id FROM ' . tbl('taxi_drivers') . ' WHERE phone = ?');
    $dup->execute(array($phone));
    if ($dup->fetch()) fail('A driver is already registered with that phone number.', 409);

    $identityMatch = taxi_check_license_identity($phone, $licenseName, $licenseDob);

    // A staff-registered driver gets a random starter PIN they can
    // change after their first login - avoids the association having
    // to ask the driver to pick one in person right away.
    $starterPin = (string) random_int(1000, 9999);
    $pdo->prepare('INSERT INTO ' . tbl('taxi_drivers') . '
        (association_id, full_name, phone, pin_hash, vehicle_plate, vehicle_type, license_number, license_full_name, license_dob, identity_match, status, joined_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active", NOW())')->execute(array(
        $assocId, $fullName, $phone, password_hash($starterPin, PASSWORD_DEFAULT), $plate,
        isset($body['vehicle_type']) ? trim($body['vehicle_type']) : null,
        $licenseNum, $licenseName, $licenseDob, $identityMatch,
    ));
    $resp = array('driver_id' => intval($pdo->lastInsertId()), 'starter_pin' => $starterPin,
              'message' => 'Driver registered. Give them this starter PIN to log in - they should change it.');
    if ($identityMatch !== 'matched') {
        $resp['identity_match'] = $identityMatch;
        $resp['message'] .= $identityMatch === 'mismatch'
            ? ' Note: the license name/DOB entered does not match this phone\'s EtelID record - review before relying on it.'
            : ' Note: could not verify against EtelID right now - this will be checked automatically once reachable.';
    }
    ok($resp);
    break;
}

case 'admin_list_pending_payments': {
    $pdo = db();
    $admin = require_admin_auth($pdo, 'payments.view');
    $sql = 'SELECT p.*, d.full_name, d.vehicle_plate, d.phone, a.name_am AS association_name
            FROM ' . tbl('taxi_dues_payments') . ' p
            JOIN ' . tbl('taxi_drivers') . ' d ON d.id = p.driver_id
            JOIN ' . tbl('taxi_associations') . ' a ON a.id = d.association_id
            WHERE p.status = "pending_verification"';
    $params = array();
    if ($admin['role'] !== 'super_admin') { $sql .= ' AND d.association_id = ?'; $params[] = intval($admin['association_id']); }
    $sql .= ' ORDER BY p.submitted_at ASC';
    $s = $pdo->prepare($sql);
    $s->execute($params);
    ok(array('payments' => $s->fetchAll()));
    break;
}

case 'admin_verify_payment': {
    $pdo = db();
    $admin = require_admin_auth($pdo, 'payments.manage');
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if (!$id) fail('id is required.');
    // Non-super-admins are scoped to their own association elsewhere
    // (admin_list_pending_payments already filters this way) - without
    // the same check here, an association-scoped admin with
    // payments.manage could verify/reject ANY other association's
    // pending payment just by guessing/incrementing the id. Mirrors the
    // ownership check already applied in admin_update_association.
    if ($admin['role'] !== 'super_admin') {
        $own = $pdo->prepare('SELECT p.id FROM ' . tbl('taxi_dues_payments') . ' p
            JOIN ' . tbl('taxi_drivers') . ' d ON d.id = p.driver_id
            WHERE p.id = ? AND d.association_id = ?');
        $own->execute(array($id, intval($admin['association_id'])));
        if (!$own->fetch()) fail('Not authorized for this payment.', 403);
    }
    $pdo->prepare('UPDATE ' . tbl('taxi_dues_payments') . '
        SET status = "verified", verified_by = ?, verified_at = NOW() WHERE id = ? AND status = "pending_verification"')
        ->execute(array($admin['id'], $id));
    ok(array('message' => 'Payment verified.'));
    break;
}

case 'admin_reject_payment': {
    $pdo = db();
    $admin = require_admin_auth($pdo, 'payments.manage');
    $id = isset($body['id']) ? intval($body['id']) : 0;
    $reason = isset($body['reason']) ? trim($body['reason']) : '';
    if (!$id) fail('id is required.');
    // Same association-ownership check as admin_verify_payment above.
    if ($admin['role'] !== 'super_admin') {
        $own = $pdo->prepare('SELECT p.id FROM ' . tbl('taxi_dues_payments') . ' p
            JOIN ' . tbl('taxi_drivers') . ' d ON d.id = p.driver_id
            WHERE p.id = ? AND d.association_id = ?');
        $own->execute(array($id, intval($admin['association_id'])));
        if (!$own->fetch()) fail('Not authorized for this payment.', 403);
    }
    $pdo->prepare('UPDATE ' . tbl('taxi_dues_payments') . '
        SET status = "rejected", verified_by = ?, verified_at = NOW(), notes = ? WHERE id = ? AND status = "pending_verification"')
        ->execute(array($admin['id'], $reason ?: null, $id));
    ok(array('message' => 'Payment rejected. The driver can resubmit.'));
    break;
}

case 'admin_get_bank_accounts': {
    $pdo = db();
    require_admin_auth($pdo, 'finance.view');
    $s = $pdo->query('SELECT id, bank_key, display_name, account_name, account_number, active FROM ' . tbl('taxi_bank_accounts') . ' ORDER BY display_name');
    ok(array('accounts' => $s->fetchAll()));
    break;
}

case 'admin_update_bank_account': {
    $pdo = db();
    require_admin_auth($pdo, 'finance.manage');
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if (!$id) fail('id is required.');
    $displayName = isset($body['display_name']) ? trim($body['display_name']) : '';
    $accountNumber = isset($body['account_number']) ? trim($body['account_number']) : '';
    if (!$displayName || !$accountNumber) fail('display_name and account_number are required.');
    $pdo->prepare('UPDATE ' . tbl('taxi_bank_accounts') . '
        SET display_name=?, account_name=?, account_number=?, active=? WHERE id=?')->execute(array(
        $displayName,
        isset($body['account_name']) ? trim($body['account_name']) : '',
        $accountNumber,
        isset($body['active']) ? (intval($body['active']) ? 1 : 0) : 1,
        $id,
    ));
    ok(array('message' => 'Bank account saved.'));
    break;
}

case 'admin_get_dashboard_stats': {
    $pdo = db();
    $admin = require_admin_auth($pdo, 'associations.view');
    $assocFilter = $admin['role'] === 'super_admin' ? null : intval($admin['association_id']);

    $where = $assocFilter ? ' WHERE d.association_id = ' . intval($assocFilter) : '';
    $totalDrivers = $pdo->query('SELECT COUNT(*) FROM ' . tbl('taxi_drivers') . ' d' . $where)->fetchColumn();
    $pendingCount = $pdo->query('SELECT COUNT(*) FROM ' . tbl('taxi_dues_payments') . ' p
        JOIN ' . tbl('taxi_drivers') . ' d ON d.id = p.driver_id
        WHERE p.status = "pending_verification"' . ($assocFilter ? ' AND d.association_id = ' . intval($assocFilter) : ''))->fetchColumn();

    // Overdue count isn't a plain SQL count - due/grace/penalty status is
    // computed per driver from real calendar month lengths (see
    // taxi_compute_driver_status), same as admin_list_overdue does.
    $driverRows = $pdo->query('SELECT id, association_id FROM ' . tbl('taxi_drivers') . ' d' . $where)->fetchAll();
    $overdueCount = 0;
    foreach ($driverRows as $dr) {
        $st = taxi_compute_driver_status($pdo, $dr['id'], $dr['association_id']);
        if ($st['status'] === 'overdue') $overdueCount++;
    }

    $associationCount = $pdo->query('SELECT COUNT(*) FROM ' . tbl('taxi_associations'))->fetchColumn();

    ok(array(
        'total_drivers'    => intval($totalDrivers),
        'pending_payments' => intval($pendingCount),
        'overdue_count'    => intval($overdueCount),
        'association_count' => intval($associationCount),
    ));
    break;
}

case 'admin_get_revenue_trend': {
    $pdo = db();
    $admin = require_admin_auth($pdo, 'finance.view');
    $assocFilter = $admin['role'] === 'super_admin' ? null : intval($admin['association_id']);
    $sql = "SELECT DATE(p.verified_at) AS d, SUM(p.amount + p.penalty_amount) AS total
            FROM " . tbl('taxi_dues_payments') . " p
            JOIN " . tbl('taxi_drivers') . " d ON d.id = p.driver_id
            WHERE p.status = 'verified' AND p.verified_at >= (NOW() - INTERVAL 29 DAY)"
            . ($assocFilter ? ' AND d.association_id = ' . intval($assocFilter) : '') . "
            GROUP BY DATE(p.verified_at)";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
    $days = array();
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $days[] = array('date' => $d, 'total' => isset($rows[$d]) ? round(floatval($rows[$d]), 2) : 0);
    }
    ok(array('days' => $days));
    break;
}

case 'admin_export_drivers_csv': {
    $pdo = db();
    $admin = require_admin_auth($pdo, 'drivers.view');
    $assocFilter = $admin['role'] === 'super_admin' ? null : intval($admin['association_id']);
    $sql = "SELECT d.id, d.full_name, d.phone, d.vehicle_plate, a.name_am AS association_name, d.created_at
            FROM " . tbl('taxi_drivers') . " d
            JOIN " . tbl('taxi_associations') . " a ON a.id = d.association_id"
            . ($assocFilter ? ' WHERE d.association_id = ' . intval($assocFilter) : '') . "
            ORDER BY d.full_name ASC";
    $rows = $pdo->query($sql)->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="etel-taxi-drivers.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array('ID', 'Full Name', 'Phone', 'Vehicle Plate', 'Association', 'Registered'));
    foreach ($rows as $r) {
        fputcsv($out, array($r['id'], $r['full_name'], $r['phone'], $r['vehicle_plate'], $r['association_name'], $r['created_at']));
    }
    fclose($out);
    exit;
}

case 'admin_list_overdue': {
    $pdo = db();
    $admin = require_admin_auth($pdo, 'payments.view');
    $sql = 'SELECT d.*, a.name_am AS association_name, a.id AS assoc_id
            FROM ' . tbl('taxi_drivers') . ' d
            JOIN ' . tbl('taxi_associations') . ' a ON a.id = d.association_id';
    $params = array();
    if ($admin['role'] !== 'super_admin') { $sql .= ' WHERE d.association_id = ?'; $params[] = intval($admin['association_id']); }
    $s = $pdo->prepare($sql);
    $s->execute($params);
    $overdue = array();
    foreach ($s->fetchAll() as $d) {
        $st = taxi_compute_driver_status($pdo, $d['id'], $d['assoc_id']);
        if ($st['status'] === 'overdue') {
            $overdue[] = array(
                'full_name' => $d['full_name'], 'phone' => $d['phone'], 'vehicle_plate' => $d['vehicle_plate'],
                'association_name' => $d['association_name'], 'current_period' => $st['current_period'],
                'penalty_owed' => $st['penalty_owed'],
            );
        }
    }
    ok(array('overdue' => $overdue));
    break;
}

case 'admin_register_officer': {
    // Traffic officers aren't tied to one association - they check any
    // driver, from any association, city-wide - so only a super admin
    // provisions them, same restriction as admin_create_association.
    $pdo = db();
    $admin = require_admin_auth($pdo, 'drivers.manage');
    if ($admin['role'] !== 'super_admin') fail('Only a super admin can add traffic officers.', 403);

    $fullName = isset($body['full_name']) ? trim($body['full_name']) : '';
    $phone    = isset($body['phone']) ? trim($body['phone']) : '';
    if (!$fullName || !$phone) fail('full_name and phone are required.');

    $dup = $pdo->prepare('SELECT id FROM ' . tbl('taxi_officers') . ' WHERE phone = ?');
    $dup->execute(array($phone));
    if ($dup->fetch()) fail('An officer is already registered with that phone number.', 409);

    $pdo->prepare('INSERT INTO ' . tbl('taxi_officers') . ' (full_name, phone, created_at) VALUES (?, ?, NOW())')
        ->execute(array($fullName, $phone));
    ok(array('id' => intval($pdo->lastInsertId())));
    break;
}

case 'admin_list_officers': {
    $pdo = db();
    require_admin_auth($pdo, 'drivers.view');
    $s = $pdo->query('SELECT id, full_name, phone, etel_id, created_at FROM ' . tbl('taxi_officers') . ' ORDER BY created_at DESC');
    ok(array('officers' => $s->fetchAll()));
    break;
}

case 'admin_list_role_permissions': {
    $pdo = db();
    require_admin_auth($pdo, 'owner.only');
    $s = $pdo->query('SELECT id, role_name, capabilities, updated_at FROM ' . tbl('taxi_role_permissions') . ' ORDER BY role_name ASC');
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['capabilities'] = json_decode($r['capabilities'], true) ?: array();
    ok(array('roles' => $rows, 'all_capabilities' => array(
        'drivers.view', 'drivers.manage', 'associations.view', 'associations.manage',
        'payments.view', 'payments.manage', 'finance.view', 'finance.manage', 'owner.only',
    )));
    break;
}

case 'admin_update_role_permissions': {
    $pdo = db();
    require_admin_auth($pdo, 'owner.only');
    $role_name = isset($body['role_name']) ? trim($body['role_name']) : '';
    $caps = isset($body['capabilities']) && is_array($body['capabilities']) ? $body['capabilities'] : array();
    if ($role_name === '') fail('role_name is required', 400);
    $caps = array_values(array_unique(array_map('strval', $caps)));
    $pdo->prepare(
        'INSERT INTO ' . tbl('taxi_role_permissions') . ' (role_name, capabilities) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE capabilities = VALUES(capabilities)'
    )->execute(array($role_name, json_encode($caps)));
    ok(array('message' => "Permissions saved for \"$role_name\""));
    break;
}

case 'admin_delete_role_permissions': {
    $pdo = db();
    require_admin_auth($pdo, 'owner.only');
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if (!$id) fail('id is required', 400);
    $pdo->prepare('DELETE FROM ' . tbl('taxi_role_permissions') . ' WHERE id = ?')->execute(array($id));
    ok(array('message' => 'Role deleted'));
    break;
}

case 'admin_get_my_capabilities': {
    $pdo = db();
    $admin = require_admin_auth($pdo);
    ok(array('capabilities' => taxi_admin_capabilities($pdo, $admin), 'staff_role' => $admin['staff_role'] ?? null));
    break;
}

}
