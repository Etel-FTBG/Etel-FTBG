<?php
// ═══════════════════════════════════════════════════════════════════
// ETEL MADEYA - ADMIN API
// ═══════════════════════════════════════════════════════════════════

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/stations.php';
require_once __DIR__ . '/../includes/driver_account.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        
        // ─── AUTHENTICATION ─────────────────────────────────────────
        
        case 'login':
            // Local username/password login has been retired - EtelID SSO
            // (admin_etelid_login below) is the only supported sign-in method.
            jsonResponse(['success' => false, 'message' => 'Local login has been disabled. Please sign in with EtelID.'], 403);
            break;

        // ─── ETELID SINGLE SIGN-ON (ADMIN) ──────────────────────────
        // Separate from the controller_etelid_login in api/index.php on
        // purpose - this one only ever grants the admin dashboard, and
        // only when either an existing admin_users row already matches
        // this phone, or Etel Group's Employees panel has assigned this
        // phone a role at 'etel-madeya'. Same trust boundary as before,
        // just performed centrally instead of in this app's own tools.
        case 'admin_etelid_login':
            require_once __DIR__ . '/../includes/etelid_bridge.php';

            $identifier = sanitize($_POST['identifier'] ?? '');
            $password   = $_POST['password'] ?? '';
            if (empty($identifier) || empty($password)) {
                jsonResponse(['success' => false, 'message' => 'Enter your EtelID and password'], 400);
            }

            if (!checkRateLimit('madeya_admin_etelid_' . getClientIP(), 5, 900)) {
                jsonResponse(['success' => false, 'message' => 'Too many attempts. Try again in 15 minutes.'], 429);
            }

            $auth = etelid_bridge_authenticate($identifier, $password);
            if (isset($auth['error'])) {
                jsonResponse(['success' => false, 'message' => $auth['error']], 401);
            }
            $member = $auth['member'];

            // Match on the last 9 digits, not an exact string - an
            // admin phone saved locally as '0924445206' must still
            // match an EtelID phone of '+251924445206' (both valid,
            // interchangeable ways to write the same Ethiopian mobile
            // number), not be treated as a different person.
            $normalizedTarget = etelid_bridge_normalize_phone($member['phone']);
            $admin = null;
            if ($normalizedTarget !== '') {
                foreach (db()->fetchAll("SELECT * FROM admin_users WHERE is_active = 1") as $cand) {
                    if (etelid_bridge_normalize_phone($cand['phone']) === $normalizedTarget) {
                        $admin = $cand;
                        break;
                    }
                }
            }

            $egRole = etelid_bridge_check_role('etel-madeya', $member['phone']);

            // check_role() only confirms SOME role at etel-madeya - a
            // controller's role qualifies just as much as an admin's.
            // The Admin Panel must only auto-provision/keep access for
            // people whose EtelGroup role_at is specifically "admin",
            // never on the mere presence of any etel-madeya role -
            // otherwise any controller signing in here by mistake (or
            // on purpose) gets full admin access. Treat anything else
            // the same as no role at all.
            if ($egRole && strcasecmp(trim($egRole['role_at']), 'admin') !== 0) {
                $egRole = null;
            }

            if (!$admin) {
                if (!$egRole) {
                    jsonResponse(['success' => false, 'message' => 'No admin account found for this EtelID. Ask a super admin to add you first.'], 403);
                }
                $placeholderHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
                $adminId = db()->insert('admin_users', [
                    'username'             => 'eid_' . preg_replace('/[^0-9]/', '', $member['phone']),
                    'password'             => $placeholderHash,
                    'full_name'            => $member['full_name'],
                    'phone'                => $member['phone'],
                    'role'                 => 'admin',
                    'staff_role'           => $egRole['role_at'],
                    'is_etelgroup_managed' => 1,
                    'is_active'            => 1,
                ]);
                $admin = db()->fetchOne("SELECT * FROM admin_users WHERE id = ?", [$adminId]);
            } else {
                $wasManaged = !empty($admin['is_etelgroup_managed']);
                if ($wasManaged && !$egRole) {
                    jsonResponse(['success' => false, 'message' => 'Your Etel Group role at Etel Madeya has been removed. Ask a super admin to reassign it.'], 403);
                }
                if ($egRole) {
                    $updates = [];
                    if ($egRole['role_at'] !== $admin['staff_role']) $updates['staff_role'] = $egRole['role_at'];
                    if (!$wasManaged) $updates['is_etelgroup_managed'] = 1;
                    if ($updates) {
                        db()->update('admin_users', $updates, 'id = ?', [$admin['id']]);
                        $admin = db()->fetchOne("SELECT * FROM admin_users WHERE id = ?", [$admin['id']]);
                    }
                }
                // No egRole and never managed: true legacy admin - left untouched.
            }

            db()->update('admin_users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$admin['id']]);
            db()->insert('activity_log', [
                'admin_id'   => $admin['id'],
                'action'     => 'login',
                'details'    => 'Admin logged in via EtelID SSO',
                'ip_address' => getClientIP()
            ]);

            if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
            $_SESSION['admin_id']       = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['admin_name']     = $admin['full_name'];
            $_SESSION['admin_role']     = $admin['role'];

            unset($admin['password']);
            jsonResponse(['success' => true, 'admin' => $admin, 'sso' => true]);
            break;

        case 'logout':
            requireAdminAuth();
            
            db()->insert('activity_log', [
                'admin_id' => $_SESSION['admin_id'],
                'action' => 'logout',
                'details' => 'Admin logged out',
                'ip_address' => getClientIP()
            ]);
            
            session_destroy();
            jsonResponse(['success' => true, 'message' => 'Logged out successfully']);
            break;
            
        case 'check_session':
            if (isset($_SESSION['admin_id'])) {
                $admin = db()->fetchOne(
                    "SELECT id, username, full_name, email, role FROM admin_users WHERE id = ?",
                    [$_SESSION['admin_id']]
                );
                jsonResponse(['success' => true, 'logged_in' => true, 'admin' => $admin]);
            } else {
                jsonResponse(['success' => false, 'logged_in' => false]);
            }
            break;
            
        // ─── DASHBOARD ──────────────────────────────────────────────
        
        case 'get_dashboard_stats':
            requireAdminAuth();
            
            $stats = [
                'controllers' => db()->count('controllers', 'is_active = 1'),
                'total_queued' => db()->count('queue', 'status = ?', ['queued']),
                'served_today' => db()->count('report', 'report_date = ?', [date('Y-m-d')]),
                'vehicle_types' => db()->count('vehicle_types', 'is_active = 1')
            ];
            
            // Recent activity
            $activity = db()->fetchAll(
                "SELECT al.*, au.username, au.full_name 
                 FROM activity_log al
                 JOIN admin_users au ON al.admin_id = au.id
                 ORDER BY al.created_at DESC LIMIT 10"
            );
            
            // Controller status
            $controllers = db()->fetchAll(
                "SELECT c.*, 
                        (SELECT COUNT(*) FROM queue WHERE controller_id = c.id AND status = 'queued') as queued_count
                 FROM controllers c
                 WHERE c.is_active = 1
                 ORDER BY c.station_name"
            );
            
            $stationLit = madeyaStationLiters(date('Y-m-d'));
            foreach ($controllers as &$cRow) {
                $sl = $stationLit[(int)$cRow['id']] ?? ['served' => 0, 'liters' => 0, 'avg' => 0, 'queue_liters' => 0];
                $cRow['fuel'] = madeyaFuelStatus((int)$cRow['id']);
                $cRow['served_today'] = $sl['served']; $cRow['est_liters'] = $sl['liters']; $cRow['avg_liters'] = $sl['avg']; $cRow['queue_liters'] = $sl['queue_liters'];
            }
            unset($cRow);
            $stats['liters'] = madeyaLiterStats(null, date('Y-m-d'));
            jsonResponse([
                'success' => true,
                'stats' => $stats,
                'activity' => $activity,
                'controllers' => $controllers
            ]);
            break;

        case 'get_served_trend':
            requireAdminAuth();

            $days = db()->fetchAll(
                "SELECT report_date, COUNT(*) served
                 FROM report
                 WHERE report_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
                 GROUP BY report_date
                 ORDER BY report_date ASC"
            );

            jsonResponse(['success' => true, 'trend' => $days]);
            break;

        case 'get_fuel_snapshot':
            requireAdminAuth();

            $rows = db()->fetchAll(
                "SELECT fuel_type,
                        SUM(current_stock_liters) stock_liters,
                        SUM(allocated_quota_liters) quota_liters,
                        SUM(used_today_liters) used_today_liters
                 FROM fuel_stock
                 GROUP BY fuel_type"
            );

            jsonResponse(['success' => true, 'fuel' => $rows]);
            break;

        // ─── CONTROLLERS ────────────────────────────────────────────
        
        case 'get_controllers':
            requireAdminAuth();
            ensureStationColumns();

            $controllers = db()->fetchAll(
                "SELECT * FROM controllers ORDER BY created_at DESC"
            );

            // Attach each controller's assigned vehicle types (empty array =
            // unrestricted / all types allowed, per controller_vehicle_types'
            // no-rows-means-unrestricted convention).
            $assignments = db()->fetchAll("SELECT controller_id, vehicle_type_id FROM controller_vehicle_types");
            $byController = [];
            foreach ($assignments as $a) {
                $byController[(int)$a['controller_id']][] = (int)$a['vehicle_type_id'];
            }
            foreach ($controllers as &$c) {
                $c['vehicle_type_ids'] = $byController[(int)$c['id']] ?? [];
            }
            unset($c);

            jsonResponse(['success' => true, 'controllers' => $controllers]);
            break;
            
        case 'create_controller':
            requireAdminAuth();

            $username = sanitize($_POST['username'] ?? '');
            $fullName = sanitize($_POST['full_name'] ?? '');
            $phone = sanitize($_POST['phone'] ?? '');
            $stationName = sanitize($_POST['station_name'] ?? '');
            $oddEvenRule = sanitize($_POST['odd_even_rule'] ?? 'all');
            if (!in_array($oddEvenRule, ['all', 'odd', 'even'], true)) $oddEvenRule = 'all';
            $vehicleTypeIds = array_map('intval', (array)($_POST['vehicle_type_ids'] ?? []));

            if (empty($username) || empty($fullName) || empty($phone)) {
                jsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
            }

            // Check if username exists
            $existing = db()->fetchOne("SELECT id FROM controllers WHERE username = ?", [$username]);
            if ($existing) {
                jsonResponse(['success' => false, 'message' => 'Username already exists']);
            }

            // Sign-in is EtelID-only (see controller_etelid_login in
            // api/index.php, matched on the phone number above) - the
            // local password column is legacy/NOT NULL schema and is
            // never checked against on login, so it's filled with an
            // unusable random value rather than collected from the admin.
            ensureStationColumns();
            $id = db()->insert('controllers', [
                'username' => $username,
                'password' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                'full_name' => $fullName,
                'phone' => $phone,
                'station_name' => $stationName ?: 'Etel Madeya Gas Station',
                'odd_even_rule' => $oddEvenRule,
                'latitude' => parseCoord($_POST['latitude'] ?? null, -90, 90),
                'longitude' => parseCoord($_POST['longitude'] ?? null, -180, 180),
                'is_open' => isset($_POST['is_open']) ? (int)(bool)$_POST['is_open'] : 1,
                'open_hours' => sanitize($_POST['open_hours'] ?? ''),
                'region' => ($rg = trim(sanitize($_POST['region'] ?? ''))) !== '' ? mb_substr($rg, 0, 60) : null
            ]);

            foreach ($vehicleTypeIds as $vtId) {
                if ($vtId > 0) {
                    db()->insert('controller_vehicle_types', ['controller_id' => $id, 'vehicle_type_id' => $vtId]);
                }
            }

            // Log activity
            db()->insert('activity_log', [
                'admin_id' => $_SESSION['admin_id'],
                'action' => 'create_controller',
                'details' => "Created controller: $username",
                'ip_address' => getClientIP()
            ]);

            jsonResponse(['success' => true, 'message' => 'Controller created successfully', 'id' => $id]);
            break;
            
        case 'open_station':
            /* Station Console: let the signed-in admin work a station exactly like its controller
             * (register, serve, VIP/waiting, reports, bulk import). Sets the controller session
             * for the chosen active controller; admin-only and logged. */
            requireAdminAuth();
            $cid = (int)($_POST['controller_id'] ?? 0);
            $ctl = $cid ? db()->fetchOne("SELECT id, username, full_name, station_name, is_active FROM controllers WHERE id = ?", [$cid]) : null;
            if (!$ctl) {
                jsonResponse(['success' => false, 'message' => 'Controller not found'], 404);
            }
            if (!(int)$ctl['is_active']) {
                jsonResponse(['success' => false, 'message' => 'This controller is inactive - activate it first'], 400);
            }
            $_SESSION['controller_id']       = $ctl['id'];
            $_SESSION['controller_username'] = $ctl['username'];
            $_SESSION['controller_name']     = $ctl['full_name'];
            db()->insert('activity_log', [
                'admin_id' => $_SESSION['admin_id'],
                'action' => 'open_station',
                'details' => 'Opened station console: ' . $ctl['username'],
                'ip_address' => getClientIP()
            ]);
            jsonResponse(['success' => true, 'station' => $ctl['station_name'], 'name' => $ctl['full_name']]);
            break;

        case 'close_station':
            requireAdminAuth();
            unset($_SESSION['controller_id'], $_SESSION['controller_username'], $_SESSION['controller_name']);
            jsonResponse(['success' => true]);
            break;

        case 'update_controller':
            requireAdminAuth();
            
            $id = (int)($_POST['id'] ?? 0);
            $fullName = sanitize($_POST['full_name'] ?? '');
            $phone = sanitize($_POST['phone'] ?? '');
            $stationName = sanitize($_POST['station_name'] ?? '');
            $isActive = (int)($_POST['is_active'] ?? 1);
            $oddEvenRule = sanitize($_POST['odd_even_rule'] ?? 'all');
            if (!in_array($oddEvenRule, ['all', 'odd', 'even'], true)) $oddEvenRule = 'all';
            $vehicleTypeIds = array_map('intval', (array)($_POST['vehicle_type_ids'] ?? []));

            if (!$id || empty($fullName)) {
                jsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
            }

            ensureStationColumns();
            $data = [
                'full_name' => $fullName,
                'phone' => $phone,
                'station_name' => $stationName,
                'is_active' => $isActive,
                'odd_even_rule' => $oddEvenRule,
                'latitude' => parseCoord($_POST['latitude'] ?? null, -90, 90),
                'longitude' => parseCoord($_POST['longitude'] ?? null, -180, 180),
                'is_open' => isset($_POST['is_open']) ? (int)(bool)$_POST['is_open'] : 1,
                'open_hours' => sanitize($_POST['open_hours'] ?? ''),
                'region' => ($rg = trim(sanitize($_POST['region'] ?? ''))) !== '' ? mb_substr($rg, 0, 60) : null
            ];

            // Local password is never checked at sign-in (EtelID-only,
            // matched on phone) - nothing to update here.
            db()->update('controllers', $data, 'id = ?', [$id]);

            // Replace the vehicle-type assignment wholesale rather than
            // diffing - the checkbox grid always submits the complete
            // current selection, so delete+reinsert is simplest and correct.
            db()->query("DELETE FROM controller_vehicle_types WHERE controller_id = ?", [$id]);
            foreach ($vehicleTypeIds as $vtId) {
                if ($vtId > 0) {
                    db()->insert('controller_vehicle_types', ['controller_id' => $id, 'vehicle_type_id' => $vtId]);
                }
            }

            // Log activity
            db()->insert('activity_log', [
                'admin_id' => $_SESSION['admin_id'],
                'action' => 'update_controller',
                'details' => "Updated controller ID: $id",
                'ip_address' => getClientIP()
            ]);
            
            jsonResponse(['success' => true, 'message' => 'Controller updated successfully']);
            break;
            
        case 'delete_controller':
            requireAdminAuth();
            
            $id = (int)($_POST['id'] ?? 0);
            
            if (!$id) {
                jsonResponse(['success' => false, 'message' => 'Controller ID required'], 400);
            }
            
            db()->delete('controllers', 'id = ?', [$id]);
            
            // Log activity
            db()->insert('activity_log', [
                'admin_id' => $_SESSION['admin_id'],
                'action' => 'delete_controller',
                'details' => "Deleted controller ID: $id",
                'ip_address' => getClientIP()
            ]);
            
            jsonResponse(['success' => true, 'message' => 'Controller deleted successfully']);
            break;
            
        // ─── VEHICLE TYPES ──────────────────────────────────────────
        
        case 'get_vehicle_types':
            requireAdminAuth();
            ensureExtraVehicleTypes();
            ensureCooldownColumn();
            ensureTankColumn();
            
            $types = db()->fetchAll(
                "SELECT * FROM vehicle_types ORDER BY sort_order, name"
            );
            
            jsonResponse(['success' => true, 'types' => $types]);
            break;
            
        case 'create_vehicle_type':
            requireAdminAuth();
            ensureVehicleIconLen();
            
            $name = sanitize($_POST['name'] ?? '');
            $nameAm = sanitize($_POST['name_am'] ?? '');
            $icon = sanitize($_POST['icon'] ?? '');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            ensureCooldownColumn();
            $cooldown = max(0, min(365, (int)($_POST['cooldown_days'] ?? 1)));
            ensureTankColumn();
            $tank = (isset($_POST['tank_liters']) && trim($_POST['tank_liters']) !== '') ? max(0, min(2000, (float)$_POST['tank_liters'])) : null;
            
            if (empty($name)) {
                jsonResponse(['success' => false, 'message' => 'Name required'], 400);
            }
            $img = saveVehicleTypeImage('image');
            if (!empty($img['error'])) jsonResponse(['success' => false, 'message' => $img['error']], 400);
            if (!empty($img['path'])) $icon = $img['path'];
            
            $id = db()->insert('vehicle_types', [
                'name' => $name,
                'name_am' => $nameAm,
                'icon' => $icon,
                'sort_order' => $sortOrder,
                'cooldown_days' => $cooldown,
                'tank_liters' => $tank
            ]);
            
            // Log activity
            db()->insert('activity_log', [
                'admin_id' => $_SESSION['admin_id'],
                'action' => 'create_vehicle_type',
                'details' => "Created vehicle type: $name",
                'ip_address' => getClientIP()
            ]);
            
            jsonResponse(['success' => true, 'message' => 'Vehicle type created successfully', 'id' => $id]);
            break;
            
        case 'update_vehicle_type':
            requireAdminAuth();
            ensureVehicleIconLen();
            
            $id = (int)($_POST['id'] ?? 0);
            $name = sanitize($_POST['name'] ?? '');
            $nameAm = sanitize($_POST['name_am'] ?? '');
            $icon = sanitize($_POST['icon'] ?? '');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            $isActive = (int)($_POST['is_active'] ?? 1);
            ensureCooldownColumn();
            $cooldown = isset($_POST['cooldown_days']) ? max(0, min(365, (int)$_POST['cooldown_days'])) : null;
            ensureTankColumn();
            $tank = array_key_exists('tank_liters', $_POST) ? ((trim($_POST['tank_liters']) !== '') ? max(0, min(2000, (float)$_POST['tank_liters'])) : false) : null; // false = clear, null = untouched
            
            if (!$id || empty($name)) {
                jsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
            }
            $img = saveVehicleTypeImage('image');
            if (!empty($img['error'])) jsonResponse(['success' => false, 'message' => $img['error']], 400);
            if (!empty($img['path'])) $icon = $img['path'];
            elseif (!empty($_POST['remove_image'])) $icon = '';
            
            db()->update('vehicle_types', [
                'name' => $name,
                'name_am' => $nameAm,
                'icon' => $icon,
                'sort_order' => $sortOrder,
                'is_active' => $isActive
            ] + ($cooldown !== null ? ['cooldown_days' => $cooldown] : []) + ($tank !== null ? ['tank_liters' => ($tank === false ? null : $tank)] : []), 'id = ?', [$id]);
            
            // Log activity
            db()->insert('activity_log', [
                'admin_id' => $_SESSION['admin_id'],
                'action' => 'update_vehicle_type',
                'details' => "Updated vehicle type ID: $id",
                'ip_address' => getClientIP()
            ]);
            
            jsonResponse(['success' => true, 'message' => 'Vehicle type updated successfully']);
            break;
            
        case 'delete_vehicle_type':
            requireAdminAuth();
            
            $id = (int)($_POST['id'] ?? 0);
            
            if (!$id) {
                jsonResponse(['success' => false, 'message' => 'Vehicle type ID required'], 400);
            }
            
            db()->delete('vehicle_types', 'id = ?', [$id]);
            
            // Log activity
            db()->insert('activity_log', [
                'admin_id' => $_SESSION['admin_id'],
                'action' => 'delete_vehicle_type',
                'details' => "Deleted vehicle type ID: $id",
                'ip_address' => getClientIP()
            ]);
            
            jsonResponse(['success' => true, 'message' => 'Vehicle type deleted successfully']);
            break;
            
        // ─── QUEUE MONITOR ──────────────────────────────────────────
        
        case 'get_queue_monitor':
            requireAdminAuth();
            
            $sql = "SELECT q.*, vt.name as vehicle_type_name, vt.icon, 
                           c.station_name, c.full_name as controller_name
                    FROM queue q
                    JOIN vehicle_types vt ON q.vehicle_type_id = vt.id
                    JOIN controllers c ON q.controller_id = c.id
                    WHERE q.status = 'queued'
                    ORDER BY c.station_name, q.priority_tier, q.position";
            
            $queue = db()->fetchAll($sql);
            
            // Group by controller
            $grouped = [];
            foreach ($queue as $item) {
                $controllerId = $item['controller_id'];
                if (!isset($grouped[$controllerId])) {
                    $grouped[$controllerId] = [
                        'controller_name' => $item['controller_name'],
                        'station_name' => $item['station_name'],
                        'vehicles' => []
                    ];
                }
                $grouped[$controllerId]['vehicles'][] = $item;
            }
            
            jsonResponse(['success' => true, 'queue' => array_values($grouped)]);
            break;
            
        // ─── REPORTS ────────────────────────────────────────────────
        
        case 'get_reports':
            requireAdminAuth();
            
            $date = $_GET['date'] ?? date('Y-m-d');
            
            $sql = "SELECT r.*, vt.name as vehicle_type_name, c.station_name
                    FROM report r
                    JOIN vehicle_types vt ON r.vehicle_type_id = vt.id
                    JOIN controllers c ON r.controller_id = c.id
                    WHERE r.report_date = ?
                    ORDER BY r.served_at DESC";
            
            $reports = db()->fetchAll($sql, [$date]);
            
            // Summary stats
            $stats = [
                'total_served' => count($reports),
                'total_fuel_gallons' => array_sum(array_column($reports, 'fuel_amount_gallons')),
                'total_fuel_liters' => array_sum(array_column($reports, 'fuel_amount_liters'))
            ];
            
            jsonResponse(['success' => true, 'reports' => $reports, 'stats' => $stats]);
            break;
            
        // ─── PLATE REGISTRY (first vehicle type of each plate) ──────
        case 'get_plate_registry':
            requireAdminAuth();
            ensurePlateRegistry();
            $q = strtoupper(trim(sanitize($_GET['q'] ?? $_POST['q'] ?? '')));
            $fCat = preg_replace('/[^1-5]/', '', $_GET['cat'] ?? '');
            $fReg = preg_replace('/[^A-Z]/', '', strtoupper($_GET['reg'] ?? ''));
            $fType = (int)($_GET['type'] ?? 0);
            if ($q === '' && $fCat === '' && $fReg === '' && !$fType) jsonResponse(['success' => true, 'rows' => []]);
            $where = []; $par = [];
            if ($q !== '') { $where[] = "pr.plate_number LIKE ?"; $par[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%'; }
            if ($fCat !== '') { $where[] = "pr.plate_number LIKE ?"; $par[] = $fCat . '-%'; }
            if ($fReg !== '') { $where[] = "(pr.plate_number LIKE ? OR pr.plate_number LIKE ?)"; $par[] = $fReg . '-%'; $par[] = '_-' . $fReg . '-%'; }
            if ($fType) { $where[] = "pr.vehicle_type_id = ?"; $par[] = $fType; }
            $rows = db()->fetchAll(
                "SELECT pr.plate_number, pr.vehicle_type_id, vt.name AS vehicle_type_name, pr.station_name, pr.first_registered_at
                 FROM plate_registry pr LEFT JOIN vehicle_types vt ON vt.id = pr.vehicle_type_id
                 WHERE " . implode(' AND ', $where) . " ORDER BY pr.first_registered_at DESC LIMIT 300", $par);
            if (!$rows && $q !== '' && $fCat === '' && $fReg === '' && !$fType) { // maybe an older plate not in the registry yet
                $o = madeyaPlateOwner($q);
                if ($o) $rows = [$o];
            }
            ensureDriverVehicles();
            foreach ($rows as &$rr) { $pv = madeyaPlateVariants($rr['plate_number']); $pp = implode(',', array_fill(0, count($pv), '?')); $rr['claimed'] = (bool)db()->fetchOne("SELECT id FROM driver_vehicles WHERE plate_number IN ($pp)", $pv); }
            unset($rr);
            jsonResponse(['success' => true, 'rows' => $rows]);
            break;

        case 'set_plate_registry':
            requireAdminAuth();
            ensurePlateRegistry();
            $plate = strtoupper(trim(sanitize($_POST['plate_number'] ?? '')));
            $typeId = (int)($_POST['vehicle_type_id'] ?? 0);
            if ($plate === '' || !$typeId) jsonResponse(['success' => false, 'message' => 'Plate and vehicle type required'], 400);
            $newPlate = strtoupper(trim(sanitize($_POST['new_plate'] ?? '')));
            if ($newPlate === '') $newPlate = $plate;
            if (!preg_match('/^([1-5]-)?([A-Z]{2}-)?[0-9A-Z]+$/', $newPlate)) jsonResponse(['success' => false, 'message' => 'Invalid plate'], 400);
            $before = db()->fetchOne("SELECT vehicle_type_id FROM plate_registry WHERE plate_number = ?", [$plate]);
            if ($newPlate !== $plate) {
                if (db()->fetchOne("SELECT plate_number FROM plate_registry WHERE plate_number = ?", [$newPlate])) {
                    jsonResponse(['success' => false, 'message' => "Plate $newPlate already exists in the registry"], 409);
                }
                db()->query("UPDATE plate_registry SET plate_number = ? WHERE plate_number = ?", [$newPlate, $plate]);
            }
            db()->query("INSERT INTO plate_registry (plate_number, vehicle_type_id, first_registered_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE vehicle_type_id = VALUES(vehicle_type_id)", [$newPlate, $typeId, date('Y-m-d H:i:s')]);
            db()->insert('activity_log', ['admin_id' => $_SESSION['admin_id'], 'action' => 'set_plate_registry', 'details' => "Plate $plate set to $newPlate, vehicle type $typeId", 'ip_address' => getClientIP()]);
            madeyaPlateLog('edit', $plate, $newPlate, $before ? madeyaVehicleTypeName($before['vehicle_type_id']) : null, madeyaVehicleTypeName($typeId), $before ? $before['vehicle_type_id'] : null, $typeId);
            jsonResponse(['success' => true, 'message' => 'Plate registry updated', 'plate_number' => $newPlate]);
            break;

        case 'bulk_plate_registry':
            requireAdminAuth();
            ensurePlateRegistry();
            $plates = json_decode($_POST['plates'] ?? '[]', true);
            if (!is_array($plates) || !$plates) jsonResponse(['success' => false, 'message' => 'Select at least one plate'], 400);
            if (count($plates) > 300) jsonResponse(['success' => false, 'message' => 'Select at most 300 plates at a time'], 400);
            $nCat = (string)($_POST['new_cat'] ?? '');   // '' keep, 'none' remove, 1-5 set
            $nReg = strtoupper((string)($_POST['new_reg'] ?? '')); // '' keep, 'NONE' remove, XX set
            $nType = (int)($_POST['vehicle_type_id'] ?? 0); // 0 keep
            if ($nCat !== '' && $nCat !== 'none' && !preg_match('/^[1-5]$/', $nCat)) jsonResponse(['success' => false, 'message' => 'Invalid category'], 400);
            if ($nReg !== '' && $nReg !== 'NONE' && !preg_match('/^[A-Z]{2}$/', $nReg)) jsonResponse(['success' => false, 'message' => 'Invalid region'], 400);
            if ($nCat === '' && $nReg === '' && !$nType) jsonResponse(['success' => false, 'message' => 'Choose what to change'], 400);
            $newTypeName = $nType ? madeyaVehicleTypeName($nType) : null;
            $done = 0; $skipped = [];
            foreach ($plates as $old) {
                $old = strtoupper(trim((string)$old));
                $row = db()->fetchOne("SELECT plate_number, vehicle_type_id FROM plate_registry WHERE plate_number = ?", [$old]);
                if (!$row) { $skipped[] = "$old (not found)"; continue; }
                list($c, $g, $d) = madeyaPlateParts($old);
                if ($nCat === 'none') $c = ''; elseif ($nCat !== '') $c = $nCat;
                if ($nReg === 'NONE') $g = ''; elseif ($nReg !== '') $g = $nReg;
                $new = ($c !== '' ? $c . '-' : '') . ($g !== '' ? $g . '-' : '') . $d;
                if ($new !== $old) {
                    if (db()->fetchOne("SELECT plate_number FROM plate_registry WHERE plate_number = ?", [$new])) { $skipped[] = "$old -> $new (already exists)"; continue; }
                    db()->query("UPDATE plate_registry SET plate_number = ? WHERE plate_number = ?", [$new, $old]);
                }
                if ($nType) db()->query("UPDATE plate_registry SET vehicle_type_id = ? WHERE plate_number = ?", [$nType, $new]);
                if ($new === $old && (!$nType || (int)$row['vehicle_type_id'] === $nType)) continue;
                madeyaPlateLog('bulk', $old, $new, madeyaVehicleTypeName($row['vehicle_type_id']), $nType ? $newTypeName : madeyaVehicleTypeName($row['vehicle_type_id']), $row['vehicle_type_id'], $nType ?: $row['vehicle_type_id']);
                $done++;
            }
            db()->insert('activity_log', ['admin_id' => $_SESSION['admin_id'], 'action' => 'bulk_plate_registry', 'details' => "Bulk plate registry fix: $done changed, " . count($skipped) . " skipped", 'ip_address' => getClientIP()]);
            jsonResponse(['success' => true, 'message' => "$done plate(s) updated" . ($skipped ? ', ' . count($skipped) . ' skipped' : ''), 'changed' => $done, 'skipped' => $skipped]);
            break;

        case 'delete_plate_registry':
            requireAdminAuth();
            ensurePlateRegistry();
            $plate = strtoupper(trim(sanitize($_POST['plate_number'] ?? '')));
            if ($plate === '') jsonResponse(['success' => false, 'message' => 'Plate required'], 400);
            $before = db()->fetchOne("SELECT vehicle_type_id FROM plate_registry WHERE plate_number = ?", [$plate]);
            db()->query("DELETE FROM plate_registry WHERE plate_number = ?", [$plate]);
            db()->insert('activity_log', ['admin_id' => $_SESSION['admin_id'], 'action' => 'delete_plate_registry', 'details' => "Plate $plate removed from the plate registry", 'ip_address' => getClientIP()]);
            madeyaPlateLog('release', $plate, null, $before ? madeyaVehicleTypeName($before['vehicle_type_id']) : null, null, $before ? $before['vehicle_type_id'] : null, null);
            jsonResponse(['success' => true, 'message' => 'Plate released - it can be registered under any vehicle type again']);
            break;

        case 'get_plate_registry_log':
            requireAdminAuth();
            ensurePlateLog();
            $q = strtoupper(trim(sanitize($_GET['q'] ?? '')));
            $w = ''; $par = [];
            if ($q !== '') { $w = " WHERE old_plate LIKE ? OR new_plate LIKE ? OR admin_name LIKE ?"; $l = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%'; $par = [$l, $l, $l]; }
            $rows = db()->fetchAll("SELECT id, admin_name, action, old_plate, new_plate, old_type, new_type, restored_at, created_at FROM plate_registry_log" . $w . " ORDER BY id DESC LIMIT 150", $par);
            jsonResponse(['success' => true, 'rows' => $rows ?: []]);
            break;

        case 'undo_plate_registry':
            requireAdminAuth();
            ensurePlateRegistry(); ensurePlateLog();
            $logId = (int)($_POST['log_id'] ?? 0);
            $L = db()->fetchOne("SELECT * FROM plate_registry_log WHERE id = ?", [$logId]);
            if (!$L) jsonResponse(['success' => false, 'message' => 'Log entry not found'], 404);
            if (!empty($L['restored_at']) || $L['action'] === 'undo') jsonResponse(['success' => false, 'message' => 'This change cannot be undone again'], 400);
            $oldTypeId = (int)$L['old_type_id'];
            if (!$oldTypeId && $L['old_type']) { $t = db()->fetchOne("SELECT id FROM vehicle_types WHERE name = ?", [$L['old_type']]); $oldTypeId = $t ? (int)$t['id'] : 0; }
            if ($L['action'] === 'release') {
                if (!$oldTypeId) jsonResponse(['success' => false, 'message' => 'The old vehicle type is unknown - set the plate by hand'], 400);
                if (db()->fetchOne("SELECT plate_number FROM plate_registry WHERE plate_number = ?", [$L['old_plate']])) jsonResponse(['success' => false, 'message' => 'This plate is already in the registry'], 409);
                db()->query("INSERT INTO plate_registry (plate_number, vehicle_type_id, first_registered_at) VALUES (?,?,?)", [$L['old_plate'], $oldTypeId, date('Y-m-d H:i:s')]);
                madeyaPlateLog('undo', null, $L['old_plate'], null, $L['old_type'], null, $oldTypeId);
            } else {
                $cur = db()->fetchOne("SELECT plate_number, vehicle_type_id FROM plate_registry WHERE plate_number = ?", [$L['new_plate']]);
                if (!$cur) jsonResponse(['success' => false, 'message' => 'Plate ' . $L['new_plate'] . ' is no longer in the registry'], 409);
                if (!$oldTypeId && !$L['old_type']) {          // the plate was not registered before this change -> remove it again
                    db()->query("DELETE FROM plate_registry WHERE plate_number = ?", [$L['new_plate']]);
                    madeyaPlateLog('undo', $L['new_plate'], null, madeyaVehicleTypeName($cur['vehicle_type_id']), null, $cur['vehicle_type_id'], null);
                } else {
                    if ($L['old_plate'] !== $L['new_plate'] && db()->fetchOne("SELECT plate_number FROM plate_registry WHERE plate_number = ?", [$L['old_plate']])) {
                        jsonResponse(['success' => false, 'message' => 'Plate ' . $L['old_plate'] . ' already exists in the registry'], 409);
                    }
                    if ($L['old_plate'] !== $L['new_plate']) db()->query("UPDATE plate_registry SET plate_number = ? WHERE plate_number = ?", [$L['old_plate'], $L['new_plate']]);
                    if ($oldTypeId) db()->query("UPDATE plate_registry SET vehicle_type_id = ? WHERE plate_number = ?", [$oldTypeId, $L['old_plate']]);
                    madeyaPlateLog('undo', $L['new_plate'], $L['old_plate'], madeyaVehicleTypeName($cur['vehicle_type_id']), $oldTypeId ? madeyaVehicleTypeName($oldTypeId) : $L['old_type'], $cur['vehicle_type_id'], $oldTypeId ?: null);
                }
            }
            db()->query("UPDATE plate_registry_log SET restored_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $logId]);
            db()->insert('activity_log', ['admin_id' => $_SESSION['admin_id'], 'action' => 'undo_plate_registry', 'details' => "Undid plate registry change #$logId", 'ip_address' => getClientIP()]);
            jsonResponse(['success' => true, 'message' => 'Change undone']);
            break;

        case 'get_fuel_deliveries_report':
            requireAdminAuth();
            ensureOpsTables(); ensureTankColumn();
            $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-6 days'));
            $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
            if ($from > $to) { $t = $from; $from = $to; $to = $t; }
            if (strtotime($to) - strtotime($from) > 92 * 86400) jsonResponse(['success' => false, 'message' => 'Choose at most 92 days'], 400);
            $cid = (int)($_GET['controller_id'] ?? 0);
            $cw = $cid ? ' AND c.id = ' . $cid : '';
            $stations = db()->fetchAll("SELECT c.id, c.station_name, c.region FROM controllers c WHERE c.is_active = 1" . $cw . " ORDER BY c.region, c.station_name");
            $out = [];
            foreach ($stations as $st) {
                $d = db()->fetchOne("SELECT COALESCE(SUM(liters),0) l, COUNT(*) n FROM fuel_deliveries WHERE controller_id = ? AND kind = 'delivery' AND DATE(created_at) BETWEEN ? AND ?", [(int)$st['id'], $from, $to]);
                $sv = db()->fetchOne("SELECT COUNT(*) n, COALESCE(SUM(COALESCE(NULLIF(r.fuel_amount_liters,0), vt.tank_liters, 0)),0) l FROM report r JOIN vehicle_types vt ON vt.id = r.vehicle_type_id WHERE r.controller_id = ? AND r.report_date BETWEEN ? AND ?", [(int)$st['id'], $from, $to]);
                $rows = db()->fetchAll("SELECT kind, liters, supplier, note, entered_by, created_at FROM fuel_deliveries WHERE controller_id = ? AND DATE(created_at) BETWEEN ? AND ? ORDER BY id DESC LIMIT 60", [(int)$st['id'], $from, $to]);
                $out[] = ['id' => (int)$st['id'], 'station' => $st['station_name'], 'region' => $st['region'], 'delivered' => round((float)$d['l'], 1), 'deliveries' => (int)$d['n'],
                          'served' => (int)$sv['n'], 'served_liters' => round((float)$sv['l'], 1), 'balance' => round((float)$d['l'] - (float)$sv['l'], 1), 'fuel' => madeyaFuelStatus((int)$st['id']), 'rows' => $rows ?: []];
            }
            jsonResponse(['success' => true, 'from' => $from, 'to' => $to, 'stations' => $out]);
            break;

        case 'get_day_closings':
            requireAdminAuth();
            ensureOpsTables();
            $rows = db()->fetchAll("SELECT d.id, d.controller_id, c.station_name, d.close_date, d.closed_at, d.closed_by, d.served, d.liters, d.queued_left, d.fuel_remaining, d.note, d.reopened_at, d.reopened_by
                                    FROM day_closings d JOIN controllers c ON c.id = d.controller_id ORDER BY d.close_date DESC, d.id DESC LIMIT 40");
            jsonResponse(['success' => true, 'rows' => $rows ?: []]);
            break;

        case 'reopen_day':
            requireAdminAuth();
            ensureOpsTables();
            $id = (int)($_POST['id'] ?? 0);
            $row = db()->fetchOne("SELECT id, reopened_at FROM day_closings WHERE id = ?", [$id]);
            if (!$row) jsonResponse(['success' => false, 'message' => 'Not found'], 404);
            if ($row['reopened_at']) jsonResponse(['success' => false, 'message' => 'Already reopened'], 400);
            db()->query("UPDATE day_closings SET reopened_at = ?, reopened_by = ? WHERE id = ?", [date('Y-m-d H:i:s'), $_SESSION['admin_name'] ?? 'admin', $id]);
            db()->insert('activity_log', ['admin_id' => $_SESSION['admin_id'], 'action' => 'reopen_day', 'details' => "Reopened day closing #$id", 'ip_address' => getClientIP()]);
            jsonResponse(['success' => true, 'message' => 'Day reopened - the station can register and serve again']);
            break;

        case 'get_fuel_alert_config':
            requireAdminAuth();
            jsonResponse(['success' => true, 'enabled' => madeyaSettingGet('fuel_alert_enabled', '0') === '1', 'chat_ids' => madeyaSettingGet('fuel_alert_chat_ids', ''), 'token_set' => madeyaTgToken() !== '']);
            break;

        case 'save_fuel_alert_config':
            requireAdminAuth();
            $ids = [];
            foreach (preg_split('/[\s,;]+/', (string)($_POST['chat_ids'] ?? '')) as $c) if ($c !== '' && preg_match('/^-?\d{3,20}$/', $c)) $ids[] = $c;
            madeyaSettingSet('fuel_alert_chat_ids', implode(',', array_unique($ids)));
            $tok = trim((string)($_POST['bot_token'] ?? ''));
            if ($tok !== '') { if (!preg_match('/^\d{6,12}:[A-Za-z0-9_-]{30,60}$/', $tok)) jsonResponse(['success' => false, 'message' => 'That does not look like a Telegram bot token (from @BotFather)'], 400); madeyaSettingSet('fuel_alert_bot_token', $tok); }
            if (!empty($_POST['clear_token'])) madeyaSettingSet('fuel_alert_bot_token', '');
            madeyaSettingSet('fuel_alert_enabled', !empty($_POST['enabled']) && $_POST['enabled'] !== '0' ? '1' : '0');
            jsonResponse(['success' => true, 'message' => 'Fuel alert settings saved', 'chat_ids' => implode(',', array_unique($ids))]);
            break;

        case 'test_fuel_alert':
            requireAdminAuth();
            if (madeyaTgToken() === '') jsonResponse(['success' => false, 'message' => 'The Telegram bot token is not saved yet'], 400);
            $ids = madeyaAlertChatIds();
            if (!$ids) jsonResponse(['success' => false, 'message' => 'Add at least one Telegram chat ID and save first'], 400);
            $ok = 0; foreach ($ids as $c) if (madeyaTgSend($c, "Etel Madeya test: fuel alerts will arrive here.\n" . date('d/m/Y H:i'))) $ok++;
            jsonResponse(['success' => $ok > 0, 'message' => $ok ? "Test message sent to $ok chat(s)" : 'Telegram refused the message - check the chat ID, and that the person pressed Start on the bot']);
            break;

        case 'telegram_chats':
            requireAdminAuth();
            if (madeyaTgToken() === '') jsonResponse(['success' => false, 'message' => 'The Telegram bot token is not set on the server yet'], 400);
            $j = madeyaTgCall('getUpdates', ['limit' => 50, 'timeout' => 0]);
            $chats = [];
            if ($j && !empty($j['ok'])) foreach ($j['result'] as $u) {
                $m = $u['message'] ?? $u['channel_post'] ?? $u['my_chat_member'] ?? null; if (!$m || empty($m['chat']['id'])) continue;
                $ch = $m['chat']; $nm = trim(($ch['title'] ?? '') . ' ' . ($ch['first_name'] ?? '') . ' ' . ($ch['last_name'] ?? '') . (isset($ch['username']) ? ' @' . $ch['username'] : ''));
                $chats[(string)$ch['id']] = ['id' => (string)$ch['id'], 'name' => $nm ?: 'chat'];
            }
            jsonResponse(['success' => true, 'chats' => array_values($chats)]);
            break;

        case 'unlink_driver_vehicle':
            requireAdminAuth();
            ensureDriverVehicles();
            $plate = strtoupper(trim(sanitize($_POST['plate_number'] ?? '')));
            if ($plate === '') jsonResponse(['success' => false, 'message' => 'Plate required'], 400);
            $vars = madeyaPlateVariants($plate);
            $ph = implode(',', array_fill(0, count($vars), '?'));
            db()->query("DELETE FROM driver_vehicles WHERE plate_number IN ($ph)", $vars);
            db()->insert('activity_log', ['admin_id' => $_SESSION['admin_id'], 'action' => 'unlink_driver_vehicle', 'details' => "Plate $plate unlinked from its driver account", 'ip_address' => getClientIP()]);
            jsonResponse(['success' => true, 'message' => 'Plate unlinked from the driver account']);
            break;

        case 'get_liters_report':
            requireAdminAuth();
            ensureTankColumn();
            $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-6 days'));
            $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
            if ($from > $to) { $t = $from; $from = $to; $to = $t; }
            if (strtotime($to) - strtotime($from) > 92 * 86400) jsonResponse(['success' => false, 'message' => 'Choose at most 92 days'], 400);
            $cid = (int)($_GET['controller_id'] ?? 0);
            $w = ' WHERE r.report_date BETWEEN ? AND ?'; $par = [$from, $to];
            if ($cid) { $w .= ' AND r.controller_id = ?'; $par[] = $cid; }
            $rows = db()->fetchAll(
                "SELECT r.report_date AS day, c.id AS controller_id, c.station_name, vt.id AS vehicle_type_id, vt.name AS vehicle_type, vt.tank_liters,
                        COUNT(*) AS served, SUM(COALESCE(NULLIF(r.fuel_amount_liters,0), vt.tank_liters, 0)) AS liters,
                        SUM(CASE WHEN COALESCE(NULLIF(r.fuel_amount_liters,0), vt.tank_liters) IS NULL THEN 1 ELSE 0 END) AS unknown
                 FROM report r JOIN controllers c ON c.id = r.controller_id JOIN vehicle_types vt ON vt.id = r.vehicle_type_id" . $w .
                " GROUP BY r.report_date, c.id, c.station_name, vt.id, vt.name, vt.tank_liters ORDER BY r.report_date DESC, c.station_name ASC, served DESC", $par);
            $out = []; $tServed = 0; $tLit = 0.0; $tUnk = 0;
            foreach ($rows as $r) {
                $out[] = ['day' => $r['day'], 'controller_id' => (int)$r['controller_id'], 'station' => $r['station_name'], 'vehicle_type' => $r['vehicle_type'],
                          'tank_liters' => $r['tank_liters'] === null ? null : (float)$r['tank_liters'], 'served' => (int)$r['served'], 'liters' => round((float)$r['liters'], 1), 'unknown' => (int)$r['unknown']];
                $tServed += (int)$r['served']; $tLit += (float)$r['liters']; $tUnk += (int)$r['unknown'];
            }
            jsonResponse(['success' => true, 'from' => $from, 'to' => $to, 'rows' => $out, 'totals' => ['served' => $tServed, 'liters' => round($tLit, 1), 'unknown' => $tUnk]]);
            break;

        // ─── SETTINGS ───────────────────────────────────────────────
        
        case 'get_settings':
            requireAdminAuth();
            
            $settings = db()->fetchAll("SELECT * FROM settings");
            
            $settingsArray = [];
            foreach ($settings as $setting) {
                if ($setting['setting_key'] === 'fuel_alert_bot_token') continue; // never sent back to the browser
                $settingsArray[$setting['setting_key']] = $setting['setting_value'];
            }
            
            jsonResponse(['success' => true, 'settings' => $settingsArray]);
            break;
            
        case 'update_settings':
            requireAdminAuth();
            
            $settings = $_POST['settings'] ?? [];
            
            if (empty($settings)) {
                jsonResponse(['success' => false, 'message' => 'No settings provided'], 400);
            }
            
            foreach ($settings as $key => $value) {
                $existing = db()->fetchOne("SELECT id FROM settings WHERE setting_key = ?", [$key]);
                
                if ($existing) {
                    db()->update('settings', ['setting_value' => $value], 'setting_key = ?', [$key]);
                } else {
                    db()->insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
                }
            }
            
            // Log activity
            db()->insert('activity_log', [
                'admin_id' => $_SESSION['admin_id'],
                'action' => 'update_settings',
                'details' => 'Updated system settings',
                'ip_address' => getClientIP()
            ]);
            
            jsonResponse(['success' => true, 'message' => 'Settings updated successfully']);
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
    
} catch (Exception $e) {
    error_log("Admin API Error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Server error. Please try again.'], 500);
}
