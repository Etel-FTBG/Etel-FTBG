<?php
// ============================================================
// ETEL AI Agent OS · ኢቴሌ
// PHP Database API — upload this to ethelmarket.com/etel/api/
// File: api.php
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://ethelmarket.com');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// ── CONFIG — edit these with your MySQL details ──
define('DB_HOST', 'localhost');
define('DB_NAME', 'EtelAI');           // your existing database name
define('DB_USER', 'YOUR_CPANEL_USERNAME');  // e.g. cpses_etej5v16e6 from the error
define('DB_PASS', 'YOUR_PASSWORD');   // your database password
define('DB_CHARSET', 'utf8mb4');
define('ETEL_SECRET', 'CHANGE_THIS_SECRET_KEY_TO_SOMETHING_LONG');

// ── DATABASE CONNECTION ──
function db() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed']));
        }
    }
    return $pdo;
}

function ok($data)  { echo json_encode(['ok' => true,  'data' => $data]);  exit; }
function err($msg, $code = 400) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit; }

// ── ROUTER ──
$method = $_SERVER['REQUEST_METHOD'];
$path   = trim($_GET['path'] ?? '', '/');
$parts  = explode('/', $path);
$route  = $parts[0] ?? '';

// Handle preflight
if ($method === 'OPTIONS') { http_response_code(200); exit; }

// ── ROUTES ──
switch ($route) {

    // GET /skills — all skills
    case 'skills':
        if ($method === 'GET') {
            $cat = $_GET['category'] ?? null;
            $sql = 'SELECT skill_id,name,category,version,description,size_label,color,bg_color,is_free,downloads FROM skills WHERE is_active=1';
            $params = [];
            if ($cat) { $sql .= ' AND category=?'; $params[] = $cat; }
            $sql .= ' ORDER BY category, name';
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            ok($stmt->fetchAll());
        }
        break;

    // GET /user_skills?user_id=1 — installed skills for a user
    // POST /user_skills — install a skill
    // DELETE /user_skills — uninstall a skill
    case 'user_skills':
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $uid  = (int)($_GET['user_id'] ?? $body['user_id'] ?? 0);
        if (!$uid) err('user_id required');

        if ($method === 'GET') {
            $stmt = db()->prepare(
                'SELECT us.skill_id, us.is_active, us.installed_at, us.last_used, us.use_count,
                        s.name, s.category, s.color, s.bg_color, s.size_label
                 FROM user_skills us
                 JOIN skills s ON s.skill_id = us.skill_id
                 WHERE us.user_id=? AND us.is_active=1
                 ORDER BY s.category, s.name'
            );
            $stmt->execute([$uid]);
            ok($stmt->fetchAll());
        }

        if ($method === 'POST') {
            $sid = $body['skill_id'] ?? '';
            if (!$sid) err('skill_id required');
            $stmt = db()->prepare(
                'INSERT INTO user_skills (user_id, skill_id) VALUES (?,?)
                 ON DUPLICATE KEY UPDATE is_active=1, installed_at=NOW()'
            );
            $stmt->execute([$uid, $sid]);
            // increment downloads
            db()->prepare('UPDATE skills SET downloads=downloads+1 WHERE skill_id=?')->execute([$sid]);
            ok(['installed' => true, 'skill_id' => $sid]);
        }

        if ($method === 'DELETE') {
            $sid = $body['skill_id'] ?? '';
            if (!$sid) err('skill_id required');
            $stmt = db()->prepare('UPDATE user_skills SET is_active=0 WHERE user_id=? AND skill_id=?');
            $stmt->execute([$uid, $sid]);
            ok(['uninstalled' => true, 'skill_id' => $sid]);
        }
        break;

    // POST /sessions — create session
    // GET /sessions?user_id=1 — list sessions
    case 'sessions':
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        if ($method === 'POST') {
            $uid   = (int)($body['user_id'] ?? 0);
            $uuid  = $body['session_uuid'] ?? bin2hex(random_bytes(16));
            $dev   = $body['device'] ?? 'web';
            $model = $body['model'] ?? 'claude-sonnet-4';
            $stmt  = db()->prepare(
                'INSERT INTO agent_sessions (session_uuid, user_id, device, model) VALUES (?,?,?,?)'
            );
            $stmt->execute([$uuid, $uid ?: null, $dev, $model]);
            ok(['session_id' => db()->lastInsertId(), 'session_uuid' => $uuid]);
        }

        if ($method === 'GET') {
            $uid  = (int)($_GET['user_id'] ?? 0);
            $stmt = db()->prepare(
                'SELECT id, session_uuid, title, device, model, message_count, started_at
                 FROM agent_sessions WHERE user_id=? AND is_archived=0
                 ORDER BY started_at DESC LIMIT 50'
            );
            $stmt->execute([$uid]);
            ok($stmt->fetchAll());
        }
        break;

    // POST /messages — save a message
    case 'messages':
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        if ($method === 'POST') {
            $sid     = (int)($body['session_id'] ?? 0);
            $role    = $body['role'] ?? 'user';
            $content = $body['content'] ?? '';
            $tokens  = (int)($body['tokens'] ?? 0);
            if (!$sid || !$content) err('session_id and content required');
            $stmt = db()->prepare(
                'INSERT INTO messages (session_id, role, content, tokens) VALUES (?,?,?,?)'
            );
            $stmt->execute([$sid, $role, $content, $tokens]);
            // update session message count
            db()->prepare('UPDATE agent_sessions SET message_count=message_count+1 WHERE id=?')->execute([$sid]);
            ok(['message_id' => db()->lastInsertId()]);
        }
        break;

    // GET /memory?user_id=1 — get memory
    // POST /memory — save memory entry
    case 'memory':
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        if ($method === 'GET') {
            $uid   = (int)($_GET['user_id'] ?? 0);
            $type  = $_GET['type'] ?? null;
            $search = $_GET['q'] ?? null;
            $sql   = 'SELECT id,type,title,LEFT(content,300) AS preview,tags,importance,created_at FROM memory WHERE user_id=?';
            $params = [$uid];
            if ($type)   { $sql .= ' AND type=?';   $params[] = $type; }
            if ($search) { $sql .= ' AND MATCH(title,content) AGAINST(? IN BOOLEAN MODE)'; $params[] = $search . '*'; }
            $sql .= ' ORDER BY importance DESC, created_at DESC LIMIT 100';
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            ok($stmt->fetchAll());
        }

        if ($method === 'POST') {
            $uid   = (int)($body['user_id'] ?? 0);
            $type  = $body['type'] ?? 'fact';
            $title = $body['title'] ?? '';
            $cont  = $body['content'] ?? '';
            $tags  = $body['tags'] ?? null;
            $imp   = (int)($body['importance'] ?? 3);
            $vault = $body['vault_path'] ?? null;
            if (!$uid || !$title || !$cont) err('user_id, title and content required');
            $stmt = db()->prepare(
                'INSERT INTO memory (user_id,type,title,content,tags,importance,vault_path)
                 VALUES (?,?,?,?,?,?,?)'
            );
            $stmt->execute([$uid, $type, $title, $cont, $tags, $imp, $vault]);
            ok(['memory_id' => db()->lastInsertId()]);
        }
        break;

    // GET /tasks?user_id=1 — get tasks
    // POST /tasks — create task
    // PUT /tasks — update task status
    case 'tasks':
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        if ($method === 'GET') {
            $uid    = (int)($_GET['user_id'] ?? 0);
            $status = $_GET['status'] ?? null;
            $sql    = 'SELECT id,title,description,status,priority,due_date,tags,created_at FROM tasks WHERE user_id=?';
            $params = [$uid];
            if ($status) { $sql .= ' AND status=?'; $params[] = $status; }
            $sql .= ' ORDER BY FIELD(priority,"urgent","high","normal","low"), due_date ASC';
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            ok($stmt->fetchAll());
        }

        if ($method === 'POST') {
            $uid   = (int)($body['user_id'] ?? 0);
            $title = $body['title'] ?? '';
            if (!$uid || !$title) err('user_id and title required');
            $stmt = db()->prepare(
                'INSERT INTO tasks (user_id,title,description,priority,due_date,tags,agent_note)
                 VALUES (?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $uid, $title,
                $body['description'] ?? null,
                $body['priority'] ?? 'normal',
                $body['due_date'] ?? null,
                $body['tags'] ?? null,
                $body['agent_note'] ?? null,
            ]);
            ok(['task_id' => db()->lastInsertId()]);
        }

        if ($method === 'PUT') {
            $tid    = (int)($body['task_id'] ?? 0);
            $status = $body['status'] ?? '';
            if (!$tid || !$status) err('task_id and status required');
            $done = $status === 'done' ? 'NOW()' : 'NULL';
            db()->prepare("UPDATE tasks SET status=?, completed_at=$done WHERE id=?")->execute([$status, $tid]);
            ok(['updated' => true]);
        }
        break;

    // GET /dashboard?user_id=1 — full dashboard stats
    case 'dashboard':
        $uid  = (int)($_GET['user_id'] ?? 0);
        if (!$uid) err('user_id required');
        $stmt = db()->prepare('SELECT * FROM v_user_dashboard WHERE id=?');
        $stmt->execute([$uid]);
        $dash = $stmt->fetch();

        // recent memory
        $m = db()->prepare('SELECT type,title,created_at FROM memory WHERE user_id=? ORDER BY created_at DESC LIMIT 5');
        $m->execute([$uid]);

        // recent sessions
        $s = db()->prepare('SELECT session_uuid,title,device,message_count,started_at FROM agent_sessions WHERE user_id=? ORDER BY started_at DESC LIMIT 5');
        $s->execute([$uid]);

        ok([
            'stats'    => $dash,
            'memory'   => $m->fetchAll(),
            'sessions' => $s->fetchAll(),
        ]);
        break;

    // GET /sync?user_id=1 — sync status
    // POST /sync — log a sync event
    case 'sync':
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        if ($method === 'GET') {
            $uid  = (int)($_GET['user_id'] ?? 0);
            $stmt = db()->prepare(
                'SELECT device,action,records,status,synced_at FROM sync_log
                 WHERE user_id=? ORDER BY synced_at DESC LIMIT 20'
            );
            $stmt->execute([$uid]);
            ok($stmt->fetchAll());
        }

        if ($method === 'POST') {
            $uid    = (int)($body['user_id'] ?? 0);
            $device = $body['device'] ?? 'web';
            $action = $body['action'] ?? 'skills.sync';
            $recs   = (int)($body['records'] ?? 0);
            $status = $body['status'] ?? 'success';
            $stmt   = db()->prepare(
                'INSERT INTO sync_log (user_id,device,action,records,status) VALUES (?,?,?,?,?)'
            );
            $stmt->execute([$uid ?: null, $device, $action, $recs, $status]);
            ok(['logged' => true]);
        }
        break;

    default:
        // Health check
        if ($route === '' || $route === 'health') {
            ok([
                'status'  => 'ETEL API running',
                'version' => '1.0.0',
                'db'      => DB_NAME,
                'time'    => date('Y-m-d H:i:s'),
            ]);
        }
        err('Unknown route: ' . $route, 404);
}
