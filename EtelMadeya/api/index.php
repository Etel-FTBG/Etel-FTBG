<?php
// ═══════════════════════════════════════════════════════════════════
// ETEL MADEYA - DRIVER & CONTROLLER API
// ═══════════════════════════════════════════════════════════════════

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/queue_logic.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ─── DUPLICATE / RATIONING PROTECTION ────────────────────────────────
// Ported from the EtelMadeya WordPress plugin's madeya_ajax_register()
// (ajax/queue.php) - same three guards, same order: is this vehicle
// type served at this station today, does the plate's parity match
// this station's odd/even rule, and has this exact plate+type already
// received fuel today at ANY station (cross-station on purpose - the
// whole point is stopping a vehicle from double-dipping the daily fuel
// ration by visiting a second station after already being served).
// Implemented in includes/queue_logic.php (madeyaVehicleTypeAllowed,
// madeyaParityViolation, madeyaServedTodayConflict, madeyaRegisterVehicle)
// so the NFC auto-register endpoint can reuse the exact same rules.

try {
    switch ($action) {
        
        // ─── DRIVER ENDPOINTS ───────────────────────────────────────
        
        case 'check_position':
            $plate = sanitize($_GET['plate'] ?? '');
            if (empty($plate)) {
                jsonResponse(['success' => false, 'message' => 'Plate number required'], 400);
            }
            
            // Public, unauthenticated lookup by plate - select explicit
            // columns rather than q.* so driver_name/driver_phone (another
            // person's PII, not shown by any caller of this action) are
            // never sent back just because someone knows or guesses a
            // plate number. Matches driver_active_queue's own column list.
            $sql = "SELECT q.id, q.controller_id, q.vehicle_type_id, q.plate_number, q.plate_parity,
                           q.token, q.position, q.queue_type, q.status, q.batch_id, q.registered_at,
                           vt.name as vehicle_type_name, vt.name_am as vehicle_type_name_am,
                           vt.icon, c.station_name, c.station_name as station_name_am
                    FROM queue q
                    JOIN vehicle_types vt ON q.vehicle_type_id = vt.id
                    JOIN controllers c ON q.controller_id = c.id
                    WHERE q.plate_number = ? AND q.status = 'queued'
                    ORDER BY q.registered_at DESC LIMIT 1";
            
            $vehicle = db()->fetchOne($sql, [$plate]);
            
            if (!$vehicle) {
                jsonResponse(['success' => false, 'message' => 'Vehicle not found in queue']);
            }
            
            // Count how many vehicles are ahead (same controller, lower batch first, then lower position)
            $sql = "SELECT COUNT(*) as ahead FROM queue 
                    WHERE controller_id = ? AND status = 'queued' AND queue_type = 'normal'
                    AND (batch_id < ? OR (batch_id = ? AND position < ?))";
            $ahead = db()->fetchOne($sql, [
                $vehicle['controller_id'],
                $vehicle['batch_id'],
                $vehicle['batch_id'],
                $vehicle['position']
            ]);
            
            $vehicle['ahead'] = (int)$ahead['ahead'];
            $vehicle['position'] = $vehicle['ahead'] + 1;
            $vehicle['alert'] = $vehicle['ahead'] <= ALERT_THRESHOLD;
            
            jsonResponse(['success' => true, 'vehicle' => $vehicle]);
            break;
            
        case 'register':
            $plate = sanitize($_POST['plate_number'] ?? '');
            $vehicleTypeId = (int)($_POST['vehicle_type_id'] ?? 0);
            $driverName = sanitize($_POST['driver_name'] ?? '');
            $driverPhone = sanitize($_POST['driver_phone'] ?? '');
            $controllerId = (int)($_POST['controller_id'] ?? 1);

            $result = madeyaRegisterVehicle($controllerId, $vehicleTypeId, $plate, $driverName, $driverPhone);

            // Remember this phone -> plate/type association so a future
            // NFC tap of this same driver's card can auto-register them
            // without the controller re-typing anything.
            if (!empty($driverPhone) && !empty($vehicleTypeId)) {
                madeyaRememberDriverVehicle($driverPhone, $plate, $vehicleTypeId, $driverName);
            }

            $httpCode = isset($result['http_code']) ? $result['http_code'] : 200;
            unset($result['http_code']);
            jsonResponse($result, $httpCode);
            break;
            
        case 'get_vehicle_types':
            $types = db()->fetchAll(
                "SELECT * FROM vehicle_types WHERE is_active = 1 ORDER BY sort_order"
            );

            // When a controller is logged in, flag which types their
            // station is actually allowed to register today - no
            // assignment rows means unrestricted (every type allowed).
            if (isset($_SESSION['controller_id'])) {
                $allowedRows = db()->fetchAll(
                    "SELECT vehicle_type_id FROM controller_vehicle_types WHERE controller_id = ?",
                    [$_SESSION['controller_id']]
                );
                if ($allowedRows) {
                    $allowedIds = [];
                    foreach ($allowedRows as $r) { $allowedIds[(int)$r['vehicle_type_id']] = true; }
                    foreach ($types as &$t) {
                        $t['allowed'] = isset($allowedIds[(int)$t['id']]);
                    }
                    unset($t);
                } else {
                    foreach ($types as &$t) { $t['allowed'] = true; }
                    unset($t);
                }
            }

            jsonResponse(['success' => true, 'types' => $types]);
            break;
            
        case 'get_controllers':
            $controllers = db()->fetchAll(
                "SELECT id, station_name, station_address FROM controllers WHERE is_active = 1"
            );
            jsonResponse(['success' => true, 'controllers' => $controllers]);
            break;
            
        case 'driver_active_queue':
            // Drivers view the active queue for the controller where their plate is registered
            $plate = sanitize($_GET['plate'] ?? '');
            $controllerId = (int)($_GET['controller_id'] ?? 0);
            
            // If plate is provided, find which controller it belongs to
            if (!empty($plate) && !$controllerId) {
                $found = db()->fetchOne(
                    "SELECT controller_id FROM queue WHERE plate_number = ? AND status = 'queued' LIMIT 1",
                    [$plate]
                );
                if ($found) {
                    $controllerId = (int)$found['controller_id'];
                }
            }
            
            if (!$controllerId) {
                jsonResponse(['success' => false, 'message' => 'Could not find your station. Make sure your plate is registered.']);
            }
            
            // Get controller/station info
            $station = db()->fetchOne(
                "SELECT id, station_name, station_address FROM controllers WHERE id = ?",
                [$controllerId]
            );
            
            // Get the full active queue for this controller, ordered by batch then position
            $queue = db()->fetchAll(
                "SELECT q.plate_number, q.position, q.queue_type, q.token, q.batch_id,
                        q.registered_at, q.status,
                        vt.name as vehicle_type_name, vt.name_am as vehicle_type_name_am, vt.icon
                 FROM queue q
                 JOIN vehicle_types vt ON q.vehicle_type_id = vt.id
                 WHERE q.controller_id = ? AND q.status = 'queued' AND q.queue_type = 'normal'
                 ORDER BY q.batch_id ASC, q.position ASC",
                [$controllerId]
            );
            
            // Add sequential display numbers
            $seq = 1;
            foreach ($queue as &$row) {
                $row['display_position'] = $seq++;
            }
            unset($row);
            
            // Group by batch for page-based display
            $pages = [];
            foreach ($queue as $row) {
                $bid = (int)($row['batch_id'] ?? 0);
                if (!isset($pages[$bid])) {
                    $pages[$bid] = [];
                }
                $pages[$bid][] = $row;
            }
            // Re-index pages to 1, 2, 3...
            $pages = array_values($pages);
            
            jsonResponse([
                'success' => true,
                'station' => $station,
                'queue' => $queue,
                'pages' => $pages,
                'total' => count($queue),
                'total_pages' => count($pages)
            ]);
            break;
            
        // ─── CONTROLLER ENDPOINTS ───────────────────────────────────
        
        case 'controller_login':
            // Local username/password login has been retired - EtelID SSO
            // (controller_etelid_login below) is the only supported sign-in method.
            jsonResponse(['success' => false, 'message' => 'Local login has been disabled. Please sign in with EtelID.'], 403);
            break;

        // ─── ETELID SINGLE SIGN-ON ──────────────────────────────────
        // Sign in with an EtelID (phone/EtelID/FIN + EtelID password)
        // instead of a locally-issued controller username/password.
        // Same pattern as Delivery & Ride's own etelid_login: verifies
        // server-to-server against the EtelID system, then finds-or-
        // creates the local controller account. Only ever grants
        // controller access, and only when either an existing
        // controllers row already matches this phone, or Etel Group's
        // central Employees panel has assigned this phone a role at
        // 'etel-madeya' - that assignment IS the deliberate-creation
        // act, so a random EtelID holder can't self-provision a
        // controller account just by signing in.
        case 'controller_etelid_login':
            require_once __DIR__ . '/../includes/etelid_bridge.php';

            $identifier = sanitize($_POST['identifier'] ?? '');
            $password   = $_POST['password'] ?? '';
            if (empty($identifier) || empty($password)) {
                jsonResponse(['success' => false, 'message' => 'Enter your EtelID and password'], 400);
            }

            if (!checkRateLimit('madeya_ctrl_etelid_' . getClientIP(), 5, 900)) {
                jsonResponse(['success' => false, 'message' => 'Too many attempts. Try again in 15 minutes.'], 429);
            }

            $auth = etelid_bridge_authenticate($identifier, $password);
            if (isset($auth['error'])) {
                jsonResponse(['success' => false, 'message' => $auth['error']], 401);
            }
            $member = $auth['member'];

            // Match on the last 9 digits, not an exact string - a
            // controller phone saved locally as '0936533606' must
            // still match an EtelID phone of '+251936533606' (both
            // valid, interchangeable ways to write the same Ethiopian
            // mobile number), not be treated as a different person.
            $normalizedTarget = etelid_bridge_normalize_phone($member['phone']);
            $controller = null;
            if ($normalizedTarget !== '') {
                foreach (db()->fetchAll("SELECT * FROM controllers WHERE is_active = 1") as $cand) {
                    if (etelid_bridge_normalize_phone($cand['phone']) === $normalizedTarget) {
                        $controller = $cand;
                        break;
                    }
                }
            }

            // Always check Etel Group's CURRENT role assignment, not just at
            // first-time creation - a role renamed or removed in the
            // Employees panel takes effect on the very next login here.
            $egRole = etelid_bridge_check_role('etel-madeya', $member['phone']);

            if (!$controller) {
                if (!$egRole) {
                    jsonResponse(['success' => false, 'message' => 'No controller account found for this EtelID. Ask an admin to add you first.'], 403);
                }
                $placeholderHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
                $controllerId = db()->insert('controllers', [
                    'username'             => 'eid_' . preg_replace('/[^0-9]/', '', $member['phone']),
                    'password'             => $placeholderHash,
                    'full_name'            => $member['full_name'],
                    'phone'                => $member['phone'],
                    'staff_role'           => $egRole['role_at'],
                    'is_etelgroup_managed' => 1,
                    'is_active'            => 1,
                ]);
                $controller = db()->fetchOne("SELECT * FROM controllers WHERE id = ?", [$controllerId]);
            } else {
                $wasManaged = !empty($controller['is_etelgroup_managed']);
                if ($wasManaged && !$egRole) {
                    jsonResponse(['success' => false, 'message' => 'Your Etel Group role at Etel Madeya has been removed. Ask an admin to reassign it.'], 403);
                }
                if ($egRole) {
                    $updates = [];
                    if ($egRole['role_at'] !== $controller['staff_role']) $updates['staff_role'] = $egRole['role_at'];
                    if (!$wasManaged) $updates['is_etelgroup_managed'] = 1;
                    if ($updates) {
                        db()->update('controllers', $updates, 'id = ?', [$controller['id']]);
                        $controller = db()->fetchOne("SELECT * FROM controllers WHERE id = ?", [$controller['id']]);
                    }
                }
                // No egRole and never managed: true legacy controller - left untouched.
            }

            db()->update('controllers', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$controller['id']]);

            if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
            $_SESSION['controller_id']       = $controller['id'];
            $_SESSION['controller_username'] = $controller['username'];
            $_SESSION['controller_name']     = $controller['full_name'];

            unset($controller['password']);
            jsonResponse(['success' => true, 'controller' => $controller, 'sso' => true]);
            break;

        case 'controller_logout':
            session_destroy();
            jsonResponse(['success' => true, 'message' => 'Logged out successfully']);
            break;
            
        case 'check_session':
            if (isset($_SESSION['controller_id'])) {
                $controller = db()->fetchOne(
                    "SELECT id, username, full_name, station_name, odd_even_rule FROM controllers WHERE id = ?",
                    [$_SESSION['controller_id']]
                );
                jsonResponse(['success' => true, 'logged_in' => true, 'controller' => $controller]);
            } else {
                jsonResponse(['success' => false, 'logged_in' => false]);
            }
            break;
            
        case 'get_queue':
            requireControllerAuth();
            
            $controllerId = $_SESSION['controller_id'];
            $vehicleTypeId = (int)($_GET['vehicle_type_id'] ?? 0);
            $queueType = $_GET['queue_type'] ?? 'normal';
            
            $sql = "SELECT q.*, vt.name as vehicle_type_name, vt.name_am as vehicle_type_name_am, vt.icon
                    FROM queue q
                    JOIN vehicle_types vt ON q.vehicle_type_id = vt.id
                    WHERE q.controller_id = ? AND q.status = 'queued'";
            $params = [$controllerId];
            
            if ($vehicleTypeId > 0) {
                $sql .= " AND q.vehicle_type_id = ?";
                $params[] = $vehicleTypeId;
            }
            
            if ($queueType !== 'all') {
                $sql .= " AND q.queue_type = ?";
                $params[] = $queueType;
            }
            
            $sql .= " ORDER BY q.batch_id ASC, q.position ASC";
            
            $queue = db()->fetchAll($sql, $params);
            
            // Assign sequential display numbers (1, 2, 3...) regardless of DB position gaps
            $seq = 1;
            foreach ($queue as &$row) {
                $row['display_position'] = $seq++;
            }
            unset($row);
            
            jsonResponse(['success' => true, 'queue' => $queue, 'count' => count($queue)]);
            break;
            
        case 'serve_vehicle':
            requireControllerAuth();

            $queueId = (int)($_POST['queue_id'] ?? 0);
            $fuelGallons = (float)($_POST['fuel_gallons'] ?? 0);
            $fuelLiters = (float)($_POST['fuel_liters'] ?? 0);

            if (!$queueId) {
                jsonResponse(['success' => false, 'message' => 'Queue ID required'], 400);
            }

            // Get vehicle from queue - scoped to this controller's own
            // station so one controller can't serve (and thereby delete
            // from the queue, and credit fuel-used stats to their own
            // station) a vehicle that belongs to a different station.
            $vehicle = db()->fetchOne(
                "SELECT * FROM queue WHERE id = ? AND controller_id = ?",
                [$queueId, $_SESSION['controller_id']]
            );

            if (!$vehicle) {
                jsonResponse(['success' => false, 'message' => 'Vehicle not found']);
            }
            
            db()->beginTransaction();
            
            try {
                // Move to report
                db()->insert('report', [
                    'controller_id' => $vehicle['controller_id'],
                    'vehicle_type_id' => $vehicle['vehicle_type_id'],
                    'plate_number' => $vehicle['plate_number'],
                    'plate_parity' => $vehicle['plate_parity'],
                    'driver_name' => $vehicle['driver_name'],
                    'driver_phone' => $vehicle['driver_phone'],
                    'token' => $vehicle['token'],
                    'queue_type' => $vehicle['queue_type'],
                    'position' => $vehicle['position'],
                    'priority_tier' => $vehicle['priority_tier'],
                    'fuel_amount_gallons' => $fuelGallons,
                    'fuel_amount_liters' => $fuelLiters,
                    'registered_at' => $vehicle['registered_at'],
                    'report_date' => date('Y-m-d')
                ]);
                
                // Delete from queue
                db()->delete('queue', 'id = ?', [$queueId]);
                
                // Update fuel usage
                if ($fuelGallons > 0 || $fuelLiters > 0) {
                    db()->query(
                        "UPDATE controllers SET fuel_used = fuel_used + ?, liters_used = liters_used + ? WHERE id = ?",
                        [$fuelGallons, $fuelLiters, $vehicle['controller_id']]
                    );
                }
                
                db()->commit();
                
                jsonResponse(['success' => true, 'message' => 'Vehicle served successfully']);
                
            } catch (Exception $e) {
                db()->rollback();
                error_log("serve_vehicle failed: " . $e->getMessage());
                jsonResponse(['success' => false, 'message' => 'Failed to serve vehicle. Please try again.'], 500);
            }
            break;
            
        case 'move_to_vip':
            requireControllerAuth();

            $queueId = (int)($_POST['queue_id'] ?? 0);

            if (!$queueId) {
                jsonResponse(['success' => false, 'message' => 'Queue ID required'], 400);
            }

            // Scoped to this controller's own station - see serve_vehicle
            // above for why (otherwise any controller could reorder any
            // other station's queue by guessing a queue_id).
            db()->update('queue', ['queue_type' => 'vip'], 'id = ? AND controller_id = ?', [$queueId, $_SESSION['controller_id']]);

            jsonResponse(['success' => true, 'message' => 'Moved to VIP queue']);
            break;

        case 'move_to_waiting':
            requireControllerAuth();

            $queueId = (int)($_POST['queue_id'] ?? 0);

            if (!$queueId) {
                jsonResponse(['success' => false, 'message' => 'Queue ID required'], 400);
            }

            db()->update('queue', ['queue_type' => 'waiting'], 'id = ? AND controller_id = ?', [$queueId, $_SESSION['controller_id']]);

            jsonResponse(['success' => true, 'message' => 'Moved to waiting queue']);
            break;
            
        case 'bulk_import':
            requireControllerAuth();
            
            $plates = $_POST['plates'] ?? '';
            $vehicleTypeId = (int)($_POST['vehicle_type_id'] ?? 0);
            $controllerId = $_SESSION['controller_id'];
            
            if (empty($plates) || !$vehicleTypeId) {
                jsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
            }

            if (!madeyaVehicleTypeAllowed($controllerId, $vehicleTypeId)) {
                jsonResponse(['success' => false, 'message' => 'This vehicle type is not served at this station today.', 'code' => 'TYPE_NOT_ALLOWED'], 400);
            }

            // sanitize() each plate the same way the manual 'register'
            // action already does - bulk_import previously only trimmed,
            // so a plate containing raw HTML could be stored unescaped
            // and later rendered unescaped on the public driver pages
            // (check_position / driver_active_queue).
            $plateArray = array_filter(array_map(fn($p) => sanitize(trim($p)), explode("\n", $plates)));
            
            if (empty($plateArray)) {
                jsonResponse(['success' => false, 'message' => 'No valid plates provided'], 400);
            }
            
            // Get next position (continue from max across ALL batches for this controller+type)
            $maxPos = db()->fetchOne(
                "SELECT COALESCE(MAX(position), 0) as max_pos FROM queue 
                 WHERE controller_id = ? AND vehicle_type_id = ? AND status = 'queued'",
                [$controllerId, $vehicleTypeId]
            );
            $nextPosition = $maxPos['max_pos'];
            
            // Get next batch_id — each import file gets its own batch so it groups onto one page
            $maxBatch = db()->fetchOne(
                "SELECT COALESCE(MAX(batch_id), 0) as max_batch FROM queue 
                 WHERE controller_id = ? AND status = 'queued'",
                [$controllerId]
            );
            $batchId = (int)$maxBatch['max_batch'] + 1;
            
            $imported = 0;
            $skipped = 0;
            $skippedServed = 0;
            $skippedParity = 0;

            // Fetched once here rather than per-plate inside the loop below.
            $controllerParityRow = db()->fetchOne("SELECT odd_even_rule FROM controllers WHERE id = ?", [$controllerId]);
            $stationRule = $controllerParityRow['odd_even_rule'] ?? 'all';

            db()->beginTransaction();

            try {
                foreach ($plateArray as $plate) {
                    $plate = trim($plate);
                    if (empty($plate)) continue;

                    // Check if already in queue
                    $existing = db()->fetchOne(
                        "SELECT id FROM queue WHERE plate_number = ? AND status = 'queued'",
                        [$plate]
                    );

                    if ($existing) {
                        $skipped++;
                        continue;
                    }

                    if (!empty($stationRule) && $stationRule !== 'all') {
                        $isOdd = isOddPlate($plate);
                        if ($isOdd !== null) {
                            $plateParity = $isOdd ? 'odd' : 'even';
                            if ($plateParity !== $stationRule) {
                                $skipped++;
                                $skippedParity++;
                                continue;
                            }
                        }
                    }

                    if (madeyaServedTodayConflict($plate, $vehicleTypeId)) {
                        $skipped++;
                        $skippedServed++;
                        continue;
                    }

                    $nextPosition++;
                    $token = strtoupper(substr(md5(uniqid() . $plate), 0, 8));
                    $parity = isOddPlate($plate);
                    $parityStr = $parity === true ? 'odd' : ($parity === false ? 'even' : '');
                    
                    db()->insert('queue', [
                        'controller_id' => $controllerId,
                        'vehicle_type_id' => $vehicleTypeId,
                        'plate_number' => $plate,
                        'plate_parity' => $parityStr,
                        'token' => $token,
                        'position' => $nextPosition,
                        'queue_type' => 'normal',
                        'status' => 'queued',
                        'batch_id' => $batchId
                    ]);
                    
                    $imported++;
                }
                
                db()->commit();

                $skipDetail = [];
                if ($skippedServed) $skipDetail[] = "$skippedServed already served today";
                if ($skippedParity) $skipDetail[] = "$skippedParity wrong parity";
                $skipMsg = $skipDetail ? ' (' . implode(', ', $skipDetail) . ')' : '';

                jsonResponse([
                    'success' => true,
                    'message' => "Imported: $imported, Skipped: $skipped{$skipMsg}",
                    'imported' => $imported,
                    'skipped' => $skipped,
                    'skipped_served_today' => $skippedServed,
                    'skipped_parity' => $skippedParity,
                    'batch_id' => $batchId
                ]);
                
            } catch (Exception $e) {
                db()->rollback();
                error_log("bulk_import failed: " . $e->getMessage());
                jsonResponse(['success' => false, 'message' => 'Import failed. Please try again.'], 500);
            }
            break;

        case 'clear_queue':
            requireControllerAuth();
            
            $controllerId = $_SESSION['controller_id'];
            $vehicleTypeId = (int)($_POST['vehicle_type_id'] ?? 0);
            
            $sql = "DELETE FROM queue WHERE controller_id = ? AND status = 'queued'";
            $params = [$controllerId];
            
            if ($vehicleTypeId > 0) {
                $sql .= " AND vehicle_type_id = ?";
                $params[] = $vehicleTypeId;
            }
            
            db()->query($sql, $params);
            
            jsonResponse(['success' => true, 'message' => 'Queue cleared successfully']);
            break;
            
        case 'get_stats':
            requireControllerAuth();
            
            $controllerId = $_SESSION['controller_id'];

            $stats = [
                'total_queued' => db()->count('queue', 'controller_id = ? AND status = ?', [$controllerId, 'queued']),
                'total_served_today' => db()->count('report', 'controller_id = ? AND report_date = ?', [$controllerId, date('Y-m-d')]),
                'vip_count' => db()->count('queue', 'controller_id = ? AND queue_type = ? AND status = ?', [$controllerId, 'vip', 'queued']),
                'waiting_count' => db()->count('queue', 'controller_id = ? AND queue_type = ? AND status = ?', [$controllerId, 'waiting', 'queued'])
            ];

            $controllerRow = db()->fetchOne(
                "SELECT odd_even_rule, fuel_allocated, fuel_used, liters_allocated, liters_used FROM controllers WHERE id = ?",
                [$controllerId]
            );
            if ($controllerRow) {
                $stats['odd_even_rule'] = $controllerRow['odd_even_rule'];
                $stats['fuel_allocated'] = (float)$controllerRow['fuel_allocated'];
                $stats['fuel_used'] = (float)$controllerRow['fuel_used'];
                $stats['liters_allocated'] = (float)$controllerRow['liters_allocated'];
                $stats['liters_used'] = (float)$controllerRow['liters_used'];
            }

            jsonResponse(['success' => true, 'stats' => $stats]);
            break;

        case 'check_import':
            requireControllerAuth();
            $controllerId = $_SESSION['controller_id'];
            $rawPlates = $_POST['plates'] ?? '';
            if (!$rawPlates) jsonResponse(['success' => false, 'message' => 'No plates provided'], 400);

            // Parse and normalise
            $lines = preg_split('/[\r\n,;]+/', $rawPlates);
            $allPlates = [];
            foreach ($lines as $line) {
                $p = strtoupper(trim(preg_replace('/[^A-Z0-9\-]/', '', strtoupper(trim($line)))));
                if (strlen($p) >= 2) $allPlates[] = $p;
            }

            // Duplicates within the file
            $counts = array_count_values($allPlates);
            $inFileDups = array_keys(array_filter($counts, fn($c) => $c > 1));

            // Unique set to check against DB
            $unique = array_values(array_unique($allPlates));
            if (empty($unique)) jsonResponse(['success' => false, 'message' => 'No valid plates found'], 400);

            // Build placeholders
            $ph = implode(',', array_fill(0, count($unique), '?'));

            // Already in queue
            $qRows = db()->fetchAll(
                "SELECT plate_number FROM queue WHERE controller_id = ? AND status = 'queued' AND UPPER(plate_number) IN ($ph)",
                array_merge([$controllerId], $unique)
            );
            $inQueue = array_column($qRows, 'plate_number');

            // Already served today
            $rRows = db()->fetchAll(
                "SELECT plate_number FROM report WHERE controller_id = ? AND report_date = ? AND UPPER(plate_number) IN ($ph)",
                array_merge([$controllerId, date('Y-m-d')], $unique)
            );
            $inReport = array_column($rRows, 'plate_number');

            // Clean = unique, not in queue, not in report
            $blocked = array_unique(array_merge(array_map('strtoupper', $inQueue), array_map('strtoupper', $inReport), $inFileDups));
            $clean = array_values(array_filter($unique, fn($p) => !in_array(strtoupper($p), $blocked)));

            jsonResponse([
                'success'    => true,
                'total'      => count($unique),
                'in_file'    => array_values($inFileDups),
                'in_queue'   => array_values($inQueue),
                'in_report'  => array_values($inReport),
                'clean'      => $clean,
            ]);
            break;

        case 'get_report':
            requireControllerAuth();
            $controllerId = $_SESSION['controller_id'];
            $date = $_GET['date'] ?? $_POST['date'] ?? date('Y-m-d');
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
            $rows = db()->fetchAll(
                "SELECT r.*, vt.name as vehicle_type_name, vt.icon as vehicle_type_icon
                 FROM report r
                 LEFT JOIN vehicle_types vt ON vt.id = r.vehicle_type_id
                 WHERE r.controller_id = ? AND r.report_date = ?
                 ORDER BY r.served_at ASC",
                [$controllerId, $date]
            );
            jsonResponse(['success' => true, 'report' => $rows ?: [], 'total' => count($rows ?: [])]);
            break;

        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
    
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Server error. Please try again.'], 500);
}
