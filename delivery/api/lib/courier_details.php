<?php
/* What ETEL knows about a courier beyond the sign-in and the vehicle: the ID document and number, the selfie, the licence (for motorcycles and cars),
 * and who to call in an emergency. Filled by staff in the Courier desk (admin_register_courier). The photos live in uploads/driver_documents/, which is
 * private: API answers carry signed, expiring links (lib/private_files.php). The table is also created by migration 100_courier_details.sql;
 * etel_courier_details_ensure() makes it on first use.
 */

function etel_courier_details_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS courier_details (
        user_id INT NOT NULL PRIMARY KEY,
        id_type VARCHAR(20) NOT NULL DEFAULT '',
        id_number VARCHAR(60) NOT NULL DEFAULT '',
        id_photo_url VARCHAR(500) NOT NULL DEFAULT '',
        selfie_url VARCHAR(500) NOT NULL DEFAULT '',
        licence_number VARCHAR(60) NOT NULL DEFAULT '',
        licence_photo_url VARCHAR(500) NOT NULL DEFAULT '',
        emergency_name VARCHAR(100) NOT NULL DEFAULT '',
        emergency_phone VARCHAR(30) NOT NULL DEFAULT '',
        address VARCHAR(200) NOT NULL DEFAULT '',
        created_by INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_courier_id_number (id_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function etel_courier_id_types() { return array('national_id' => 'National ID', 'kebele_id' => 'Kebele ID', 'passport' => 'Passport', 'driving_licence' => 'Driving licence'); }

/* How a courier travels: the vehicles that need a plate (and, for the desk's checklist, a licence). */
function etel_courier_vehicle_needs_plate($vehicle) { return $vehicle === 'motorcycle' || $vehicle === 'car'; }

/* The details row for one courier, or null. */
function etel_courier_details_get($pdo, $user_id) {
    try {   // read only: a missing table just means no details yet
        $q = $pdo->prepare('SELECT id_type, id_number, id_photo_url, selfie_url, licence_number, licence_photo_url, emergency_name, emergency_phone, address, created_at FROM courier_details WHERE user_id = ?');
        $q->execute(array(intval($user_id)));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ? $r : null;
    } catch (Exception $e) { return null; }
}
