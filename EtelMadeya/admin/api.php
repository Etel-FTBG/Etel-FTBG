<?php
// ═══════════════════════════════════════════════════════════════════
// ETEL MADEYA - ADMIN API
// ═══════════════════════════════════════════════════════════════════

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Helper function to check admin authentication
function requireAdmin() {
    if (!isset($_SESSION['admin_id'])) {
        jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
    }
}

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
            requireAdmin();
            
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
            requireAdmin();
            
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
            
            jsonResponse([
                'success' => true,
                'stats' => $stats,
                'activity' => $activity,
                'controllers' => $controllers
            ]);
            break;

        case 'get_served_trend':
            requireAdmin();

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
            requireAdmin();

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
            requireAdmin();

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
            requireAdmin();

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
            $id = db()->insert('controllers', [
                'username' => $username,
                'password' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                'full_name' => $fullName,
                'phone' => $phone,
                'station_name' => $stationName ?: 'Etel Madeya Gas Station',
                'odd_even_rule' => $oddEvenRule
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
            
        case 'update_controller':
            requireAdmin();
            
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

            $data = [
                'full_name' => $fullName,
                'phone' => $phone,
                'station_name' => $stationName,
                'is_active' => $isActive,
                'odd_even_rule' => $oddEvenRule
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
            requireAdmin();
            
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
            requireAdmin();
            
            $types = db()->fetchAll(
                "SELECT * FROM vehicle_types ORDER BY sort_order, name"
            );
            
            jsonResponse(['success' => true, 'types' => $types]);
            break;
            
        case 'create_vehicle_type':
            requireAdmin();
            
            $name = sanitize($_POST['name'] ?? '');
            $nameAm = sanitize($_POST['name_am'] ?? '');
            $icon = sanitize($_POST['icon'] ?? '');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            
            if (empty($name)) {
                jsonResponse(['success' => false, 'message' => 'Name required'], 400);
            }
            
            $id = db()->insert('vehicle_types', [
                'name' => $name,
                'name_am' => $nameAm,
                'icon' => $icon,
                'sort_order' => $sortOrder
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
            requireAdmin();
            
            $id = (int)($_POST['id'] ?? 0);
            $name = sanitize($_POST['name'] ?? '');
            $nameAm = sanitize($_POST['name_am'] ?? '');
            $icon = sanitize($_POST['icon'] ?? '');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            $isActive = (int)($_POST['is_active'] ?? 1);
            
            if (!$id || empty($name)) {
                jsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
            }
            
            db()->update('vehicle_types', [
                'name' => $name,
                'name_am' => $nameAm,
                'icon' => $icon,
                'sort_order' => $sortOrder,
                'is_active' => $isActive
            ], 'id = ?', [$id]);
            
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
            requireAdmin();
            
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
            requireAdmin();
            
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
            requireAdmin();
            
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
            
        // ─── SETTINGS ───────────────────────────────────────────────
        
        case 'get_settings':
            requireAdmin();
            
            $settings = db()->fetchAll("SELECT * FROM settings");
            
            $settingsArray = [];
            foreach ($settings as $setting) {
                $settingsArray[$setting['setting_key']] = $setting['setting_value'];
            }
            
            jsonResponse(['success' => true, 'settings' => $settingsArray]);
            break;
            
        case 'update_settings':
            requireAdmin();
            
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
    jsonResponse(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], 500);
}
