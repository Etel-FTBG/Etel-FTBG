<?php
require_once __DIR__ . '/../lib/taxi_payments.php';
require_once __DIR__ . '/../lib/etelid_bridge.php';

switch ($action) {

case 'officer_etelid_login': {
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

    // Same security boundary as admin EtelID sign-in: only recognizes an
    // EtelID that already matches an EXISTING officer account's phone
    // number - it never creates a new officer. Officer accounts are
    // only ever created deliberately (admin_register_officer, by a
    // super admin). First successful match auto-links the etel_id for
    // faster sign-in next time.
    $s = $pdo->prepare('SELECT * FROM ' . tbl('taxi_officers') . ' WHERE etel_id = ? OR phone = ? LIMIT 1');
    $s->execute(array($member['etel_id'], $member['phone']));
    $officer = $s->fetch();
    if (!$officer) {
        // Same bridge as admin_etelid_login, but the opposite filter:
        // only the "Traffic Officer" role (case-insensitive) grants
        // this tool - any other Etel-Group-assigned role (Manager,
        // Dispatcher, ...) belongs to the admin dashboard instead, not
        // here, even though both check the same underlying role table.
        $egRole = taxi_egid_check_role($member['phone']);
        if ($egRole && strtolower(trim($egRole['role_at'])) === 'traffic officer') {
            $pdo->prepare(
                'INSERT INTO ' . tbl('taxi_officers') . ' (full_name, phone, etel_id, created_at)
                 VALUES (?, ?, ?, NOW())'
            )->execute(array($member['full_name'], $member['phone'], $member['etel_id']));
            $s->execute(array($member['etel_id'], $member['phone']));
            $officer = $s->fetch();
        }
    }
    if (!$officer) fail('No traffic officer account found for this EtelID. Ask an association admin to add you first.', 403);
    if (empty($officer['etel_id'])) {
        $pdo->prepare('UPDATE ' . tbl('taxi_officers') . ' SET etel_id = ? WHERE id = ?')->execute(array($member['etel_id'], $officer['id']));
    }

    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO ' . tbl('taxi_officer_sessions') . ' (officer_id, token, expires_at, created_at)
                   VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())')->execute(array($officer['id'], $token));

    ok(array('token' => $token, 'officer' => array('full_name' => $officer['full_name'])));
    break;
}

case 'officer_verify_by_plate': {
    // A traffic officer pulling a car over doesn't always get to scan
    // the driver's phone (screen off, phone out of reach, etc) - this
    // lets them check by plate number alone. Gated by a real signed-in
    // officer session (see officer_etelid_login above), not a shared
    // secret key, so this doesn't become the globally-searchable "look
    // up anyone's plate" endpoint public_verify_permit was specifically
    // designed to avoid, while still being tied to one accountable
    // person rather than a key that could leak or get passed around.
    $pdo = db();
    $officer = require_officer_auth($pdo);

    $plateRaw = isset($body['vehicle_plate']) ? trim($body['vehicle_plate']) : '';
    if ($plateRaw === '') fail('vehicle_plate is required.');
    // Normalize both sides (case + spacing) so small typing differences
    // ("1-SD-10181" vs "1 sd 10181") still match the same plate.
    $normalized = strtoupper(preg_replace('/\s+/', '', $plateRaw));

    $s = $pdo->query('SELECT * FROM ' . tbl('taxi_drivers'));
    $matches = array();
    foreach ($s->fetchAll() as $row) {
        if (strtoupper(preg_replace('/\s+/', '', $row['vehicle_plate'])) === $normalized) $matches[] = $row;
    }
    if (!$matches) fail('No driver found with that plate number.', 404);
    if (count($matches) > 1) fail('More than one driver is registered with that plate number - contact the association to resolve the duplicate.', 409);
    $driver = $matches[0];

    $status = taxi_compute_driver_status($pdo, $driver['id'], $driver['association_id']);
    $as = $pdo->prepare('SELECT name_am, name_en, route_am, route_en FROM ' . tbl('taxi_associations') . ' WHERE id = ?');
    $as->execute(array($driver['association_id']));
    $association = $as->fetch();

    ok(array(
        'full_name'     => $driver['full_name'],
        'vehicle_plate' => $driver['vehicle_plate'],
        'association'   => $association,
        'permit_status' => $status['status'], // active | grace_period | overdue
        'valid_through' => $status['window_end'],
    ));
    break;
}

case 'officer_logout': {
    // Invalidated server-side too (not just cleared locally) so a lost
    // or shared device can't keep using an old session indefinitely.
    $pdo = db();
    require_officer_auth($pdo);
    $token = '';
    $hdrs = function_exists('getallheaders') ? getallheaders() : array();
    foreach ($hdrs as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $token = trim(str_ireplace('Bearer', '', $v));
    if ($token) $pdo->prepare('DELETE FROM ' . tbl('taxi_officer_sessions') . ' WHERE token = ?')->execute(array($token));
    ok(array('message' => 'Signed out.'));
    break;
}

}
