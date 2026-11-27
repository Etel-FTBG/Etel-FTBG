<?php
// ============================================================
// ETEL - Send a package: the courier's photo proof of delivery (optional, after the PIN hand-over).
// The photo is sent as a small JPEG / PNG / WebP data URL (the app shrinks it first), kept in table package_proofs (not in a public folder),
// and only the sender who booked the package can read it back. The courier can add it only for their own delivered package, within 2 hours.
// ============================================================

define('ETEL_PROOF_MAX_CHARS', 700000);   // the data URL text
define('ETEL_PROOF_MAX_BYTES', 500000);   // the picture itself
define('ETEL_PROOF_WINDOW_MIN', 120);     // minutes after delivery in which the courier can still add it

function etel_package_proof_ensure($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS package_proofs (
        order_id INT(11) NOT NULL, courier_id INT(11) NOT NULL, photo MEDIUMTEXT NOT NULL, created_at DATETIME NOT NULL,
        PRIMARY KEY (order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Pure. Is this text a real, small picture? Returns array('ok' => bool, 'message' => string).
function etel_package_proof_check_photo($s) {
    if (!is_string($s) || $s === '') return array('ok' => false, 'message' => 'Take a photo first.');
    if (strlen($s) > ETEL_PROOF_MAX_CHARS) return array('ok' => false, 'message' => 'The photo is too big.');
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $s, $m)) return array('ok' => false, 'message' => 'The photo must be a JPEG, PNG or WebP picture.');
    $raw = base64_decode($m[2], true);
    if ($raw === false || $raw === '') return array('ok' => false, 'message' => 'The photo could not be read.');
    if (strlen($raw) > ETEL_PROOF_MAX_BYTES) return array('ok' => false, 'message' => 'The photo is too big.');
    $info = @getimagesizefromstring($raw);
    if (!$info || empty($info['mime'])) return array('ok' => false, 'message' => 'The photo could not be read.');
    $want = array('jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp');
    if ($info['mime'] !== $want[$m[1]]) return array('ok' => false, 'message' => 'The photo could not be read.');
    if ($info[0] < 16 || $info[1] < 16) return array('ok' => false, 'message' => 'The photo is too small.');
    return array('ok' => true, 'message' => '');
}

// The courier saves the photo for their own delivered package. Returns array('ok', 'message').
function etel_package_proof_save($pdo, $courier_id, $order_id, $photo) {
    $c = etel_package_proof_check_photo($photo);
    if (!$c['ok']) return $c;
    $s = $pdo->prepare("SELECT id, courier_id, status, order_kind, delivered_at, TIMESTAMPDIFF(MINUTE, delivered_at, NOW()) AS mins FROM orders WHERE id = ?");
    $s->execute(array(intval($order_id)));
    $o = $s->fetch();
    if (!$o || $o['order_kind'] !== 'package' || intval($o['courier_id']) !== intval($courier_id)) return array('ok' => false, 'message' => 'This is not your package.');
    if ($o['status'] !== 'delivered' || empty($o['delivered_at'])) return array('ok' => false, 'message' => 'Add the photo after the package is delivered.');
    if ($o['mins'] === null || intval($o['mins']) > ETEL_PROOF_WINDOW_MIN) return array('ok' => false, 'message' => 'It is too late to add a photo to this delivery.');
    etel_package_proof_ensure($pdo);
    $pdo->prepare('REPLACE INTO package_proofs (order_id, courier_id, photo, created_at) VALUES (?,?,?,NOW())')->execute(array(intval($order_id), intval($courier_id), $photo));
    return array('ok' => true, 'message' => 'Photo saved.');
}

// The sender reads the photo of their own package: array('photo' => data URL or null, 'taken_at' => string).
function etel_package_proof_get($pdo, $customer_id, $order_id) {
    etel_package_proof_ensure($pdo);
    $s = $pdo->prepare('SELECT p.photo, p.created_at FROM package_proofs p JOIN orders o ON o.id = p.order_id WHERE p.order_id = ? AND o.customer_id = ? AND o.order_kind = \'package\'');
    $s->execute(array(intval($order_id), intval($customer_id)));
    $r = $s->fetch();
    return $r ? array('photo' => $r['photo'], 'taken_at' => (string) $r['created_at']) : array('photo' => null, 'taken_at' => '');
}

// Is there a photo for this package? (for the tracking screen; never throws)
function etel_package_has_proof($pdo, $order_id) {
    try {
        etel_package_proof_ensure($pdo);
        $s = $pdo->prepare('SELECT 1 FROM package_proofs WHERE order_id = ?');
        $s->execute(array(intval($order_id)));
        return (bool) $s->fetchColumn();
    } catch (Exception $e) { return false; }
}
