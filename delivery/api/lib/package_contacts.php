<?php
// ============================================================
// ETEL - Send a package: the customer's address book (people they send to or from: a name, a phone and an address).
// Own contacts only. A saved contact fills the pickup or the drop-off side of the wizard. At most 30 per customer.
// ============================================================

define('ETEL_CONTACTS_MAX', 30);

function etel_contacts_ensure($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS package_contacts (
        id INT(11) NOT NULL AUTO_INCREMENT, user_id INT(11) NOT NULL, label VARCHAR(40) NOT NULL DEFAULT '', name VARCHAR(120) NOT NULL, phone VARCHAR(25) NOT NULL,
        address VARCHAR(255) NOT NULL, lat DECIMAL(10,7) NULL DEFAULT NULL, lng DECIMAL(10,7) NULL DEFAULT NULL, zone VARCHAR(60) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Pure. The cleaned fields of a contact, or a string with what is wrong. $phone_ok is the already validated phone (etel_validate_phone) or false.
function etel_contacts_clean($in, $phone_ok) {
    $in = is_array($in) ? $in : array();
    $name = isset($in['name']) ? trim((string) $in['name']) : '';
    if ($name === '' || strlen($name) > 120) return 'Enter the name (up to 120 letters).';
    if (!$phone_ok) return 'Enter a valid Ethiopian mobile number.';
    $addr = isset($in['address']) ? trim((string) $in['address']) : '';
    if ($addr === '' || strlen($addr) > 255) return 'Enter the address.';
    $label = isset($in['label']) ? trim((string) $in['label']) : '';
    if (strlen($label) > 40) $label = substr($label, 0, 40);
    $lat = (isset($in['lat']) && is_numeric($in['lat'])) ? floatval($in['lat']) : null;
    $lng = (isset($in['lng']) && is_numeric($in['lng'])) ? floatval($in['lng']) : null;
    if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0 && $lng == 0)) { $lat = null; $lng = null; }
    $zone = isset($in['zone']) ? substr(trim((string) $in['zone']), 0, 60) : '';
    return array('label' => $label, 'name' => $name, 'phone' => (string) $phone_ok, 'address' => $addr, 'lat' => $lat, 'lng' => $lng, 'zone' => $zone);
}

// Pure. One row -> what the app gets.
function etel_contacts_row($r) {
    $num = function ($v) { return ($v !== null && $v !== '' && is_numeric($v)) ? floatval($v) : null; };
    return array('id' => intval($r['id']), 'label' => (string) $r['label'], 'name' => (string) $r['name'], 'phone' => (string) $r['phone'], 'address' => (string) $r['address'],
        'lat' => $num($r['lat']), 'lng' => $num($r['lng']), 'zone' => (string) $r['zone']);
}

function etel_contacts_list($pdo, $user_id) {
    etel_contacts_ensure($pdo);
    $s = $pdo->prepare('SELECT id, label, name, phone, address, lat, lng, zone FROM package_contacts WHERE user_id = ? ORDER BY id DESC LIMIT ' . ETEL_CONTACTS_MAX);
    $s->execute(array(intval($user_id)));
    $out = array();
    foreach ($s->fetchAll() as $r) $out[] = etel_contacts_row($r);
    return $out;
}

// Saves a contact (the same phone + address updates the old one). Returns array('ok', 'message', 'contact').
function etel_contacts_save($pdo, $user_id, $clean) {
    etel_contacts_ensure($pdo);
    $user_id = intval($user_id);
    $f = $pdo->prepare('SELECT id FROM package_contacts WHERE user_id = ? AND phone = ? AND address = ? LIMIT 1');
    $f->execute(array($user_id, $clean['phone'], $clean['address']));
    $id = intval($f->fetchColumn());
    if ($id) {
        $pdo->prepare('UPDATE package_contacts SET label = ?, name = ?, lat = ?, lng = ?, zone = ? WHERE id = ? AND user_id = ?')
            ->execute(array($clean['label'], $clean['name'], $clean['lat'], $clean['lng'], $clean['zone'], $id, $user_id));
    } else {
        $n = $pdo->prepare('SELECT COUNT(*) FROM package_contacts WHERE user_id = ?'); $n->execute(array($user_id));
        if (intval($n->fetchColumn()) >= ETEL_CONTACTS_MAX) return array('ok' => false, 'message' => 'Your address book is full (' . ETEL_CONTACTS_MAX . '). Delete one first.', 'contact' => null);
        $pdo->prepare('INSERT INTO package_contacts (user_id, label, name, phone, address, lat, lng, zone, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())')
            ->execute(array($user_id, $clean['label'], $clean['name'], $clean['phone'], $clean['address'], $clean['lat'], $clean['lng'], $clean['zone']));
        $id = intval($pdo->lastInsertId());
    }
    $g = $pdo->prepare('SELECT id, label, name, phone, address, lat, lng, zone FROM package_contacts WHERE id = ?'); $g->execute(array($id));
    return array('ok' => true, 'message' => 'Saved to your address book.', 'contact' => etel_contacts_row($g->fetch()));
}

// Deletes one of the customer's own contacts. Returns true when a row went.
function etel_contacts_delete($pdo, $user_id, $id) {
    etel_contacts_ensure($pdo);
    $d = $pdo->prepare('DELETE FROM package_contacts WHERE id = ? AND user_id = ?');
    $d->execute(array(intval($id), intval($user_id)));
    return $d->rowCount() > 0;
}
