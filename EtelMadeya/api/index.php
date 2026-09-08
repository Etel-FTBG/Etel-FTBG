<?php
// ═══════════════════════════════════════════════════════════════════
// ETEL MADEYA - DRIVER & CONTROLLER API
// ═══════════════════════════════════════════════════════════════════

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        
        // ─── DRIVER ENDPOINTS ───────────────────────────────────────
        
        case 'check_position':
            $plate = sanitize($_GET['plate'] ?? '');
            if (empty($plate)) {
                jsonResponse(['success' => false, 'message' => 'Plate number required'], 400);
            }
            
            $sql = "SELECT q.*, vt.name as vehicle_type_name, vt.name_am as vehicle_type_name_am, 
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
            
            if (empty($plate) || !$vehicleTypeId) {
                jsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
            }
            
            // Check if already in queue
            $existing = db()->fetchOne(
                "SELECT id FROM queue WHERE plate_number = ? AND status = 'queued'",
                [$plate]
            );
            
            if ($existing) {
                jsonResponse(['success' => false, 'message' => 'Vehicle already in queue']);
            }
            
            // Get next position
            $maxPos = db()->fetchOne(
                "SELECT COALESCE(MAX(position), 0) as max_pos FROM queue 
                 WHERE controller_id = ? AND vehicle_type_id = ? AND status = 'queued'",
                [$controllerId, $vehicleTypeId]
            );
            $nextPosition = $maxPos['max_pos'] + 1;
            
            // Generate token
            $token = strtoupper(substr(md5(uniqid()), 0, 8));
            
            // Determine plate parity
            $parity = isOddPlate($plate);
            $parityStr = $parity === true ? 'odd' : ($parity === false ? 'even' : '');
            
            // Insert into queue
            // Single registrations join the latest batch (or batch 1 if none)
            $curBatch = db()->fetchOne(
                "SELECT COALESCE(MAX(batch_id), 0) as max_batch FROM queue 
                 WHERE controller_id = ? AND status = 'queued'",
                [$controllerId]
            );
            $batchId = max(1, (int)$curBatch['max_batch']);
            
            $id = db()->insert('queue', [
                'controller_id' => $controllerId,
                'vehicle_type_id' => $vehicleTypeId,
                'plate_number' => $plate,
                'plate_parity' => $parityStr,
                'driver_name' => $driverName,
                'driver_phone' => $driverPhone,
                'token' => $token,
                'position' => $nextPosition,
                'queue_type' => 'normal',
                'status' => 'queued',
                'batch_id' => $batchId
            ]);
            
            jsonResponse([
                'success' => true,
                'message' => 'Successfully registered in queue',
                'data' => [
                    'id' => $id,
                    'token' => $token,
                    'position' => $nextPosition,
                    'plate_number' => $plate
                ]
            ]);
            break;
            
        case 'get_vehicle_types':
            $types = db()->fetchAll(
                "SELECT * FROM vehicle_types WHERE is_active = 1 ORDER BY sort_order"
            );
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
            $username = sanitize($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            
            if (empty($username) || empty($password)) {
                jsonResponse(['success' => false, 'message' => 'Username and password required'], 400);
            }
            
            $controller = db()->fetchOne(
                "SELECT * FROM controllers WHERE username = ? AND is_active = 1",
                [$username]
            );
            
            if (!$controller || !password_verify($password, $controller['password'])) {
                jsonResponse(['success' => false, 'message' => 'Invalid credentials']);
            }
            
            // Update last login
            db()->update('controllers', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$controller['id']]);
            
            // Set session
            $_SESSION['controller_id'] = $controller['id'];
            $_SESSION['controller_username'] = $controller['username'];
            $_SESSION['controller_name'] = $controller['full_name'];
            
            unset($controller['password']);
            jsonResponse(['success' => true, 'controller' => $controller]);
            break;
            
        case 'controller_logout':
            session_destroy();
            jsonResponse(['success' => true, 'message' => 'Logged out successfully']);
            break;
            
        case 'check_session':
            if (isset($_SESSION['controller_id'])) {
                $controller = db()->fetchOne(
                    "SELECT id, username, full_name, station_name FROM controllers WHERE id = ?",
                    [$_SESSION['controller_id']]
                );
                jsonResponse(['success' => true, 'logged_in' => true, 'controller' => $controller]);
            } else {
                jsonResponse(['success' => false, 'logged_in' => false]);
            }
            break;
            
        case 'get_queue':
            if (!isset($_SESSION['controller_id'])) {
                jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
            }
            
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
            if (!isset($_SESSION['controller_id'])) {
                jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
            }
            
            $queueId = (int)($_POST['queue_id'] ?? 0);
            $fuelGallons = (float)($_POST['fuel_gallons'] ?? 0);
            $fuelLiters = (float)($_POST['fuel_liters'] ?? 0);
            
            if (!$queueId) {
                jsonResponse(['success' => false, 'message' => 'Queue ID required'], 400);
            }
            
            // Get vehicle from queue
            $vehicle = db()->fetchOne("SELECT * FROM queue WHERE id = ?", [$queueId]);
            
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
                jsonResponse(['success' => false, 'message' => 'Failed to serve vehicle: ' . $e->getMessage()], 500);
            }
            break;
            
        case 'move_to_vip':
            if (!isset($_SESSION['controller_id'])) {
                jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
            }
            
            $queueId = (int)($_POST['queue_id'] ?? 0);
            
            if (!$queueId) {
                jsonResponse(['success' => false, 'message' => 'Queue ID required'], 400);
            }
            
            db()->update('queue', ['queue_type' => 'vip'], 'id = ?', [$queueId]);
            
            jsonResponse(['success' => true, 'message' => 'Moved to VIP queue']);
            break;
            
        case 'move_to_waiting':
            if (!isset($_SESSION['controller_id'])) {
                jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
            }
            
            $queueId = (int)($_POST['queue_id'] ?? 0);
            
            if (!$queueId) {
                jsonResponse(['success' => false, 'message' => 'Queue ID required'], 400);
            }
            
            db()->update('queue', ['queue_type' => 'waiting'], 'id = ?', [$queueId]);
            
            jsonResponse(['success' => true, 'message' => 'Moved to waiting queue']);
            break;
            
        case 'bulk_import':
            if (!isset($_SESSION['controller_id'])) {
                jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
            }
            
            $plates = $_POST['plates'] ?? '';
            $vehicleTypeId = (int)($_POST['vehicle_type_id'] ?? 0);
            $controllerId = $_SESSION['controller_id'];
            
            if (empty($plates) || !$vehicleTypeId) {
                jsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
            }
            
            $plateArray = array_filter(array_map('trim', explode("\n", $plates)));
            
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
                
                jsonResponse([
                    'success' => true,
                    'message' => "Imported: $imported, Skipped: $skipped",
                    'imported' => $imported,
                    'skipped' => $skipped,
                    'batch_id' => $batchId
                ]);
                
            } catch (Exception $e) {
                db()->rollback();
                jsonResponse(['success' => false, 'message' => 'Import failed: ' . $e->getMessage()], 500);
            }
            break;
            
        case 'clear_queue':
            if (!isset($_SESSION['controller_id'])) {
                jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
            }
            
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
            if (!isset($_SESSION['controller_id'])) {
                jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
            }
            
            $controllerId = $_SESSION['controller_id'];
            
            $stats = [
                'total_queued' => db()->count('queue', 'controller_id = ? AND status = ?', [$controllerId, 'queued']),
                'total_served_today' => db()->count('report', 'controller_id = ? AND report_date = ?', [$controllerId, date('Y-m-d')]),
                'vip_count' => db()->count('queue', 'controller_id = ? AND queue_type = ? AND status = ?', [$controllerId, 'vip', 'queued']),
                'waiting_count' => db()->count('queue', 'controller_id = ? AND queue_type = ? AND status = ?', [$controllerId, 'waiting', 'queued'])
            ];
            
            jsonResponse(['success' => true, 'stats' => $stats]);
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
    
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], 500);
}
