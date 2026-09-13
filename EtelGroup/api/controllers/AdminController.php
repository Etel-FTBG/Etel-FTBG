<?php
// ============================================================
// Etel Group super-page — Admin controller
// Manages the one real dynamic data source this marketing site has:
// contact form submissions (contact_messages table). Included by
// api/boot.php inside a try/switch; each case calls ok()/fail() which
// exit(), so falling through to the end means "unknown action".
// ============================================================
declare(strict_types=1);

switch ($action) {

case 'admin_login': {
    $identifier = trim((string)($body['identifier'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($identifier === '' || $password === '') {
        fail('Enter your EtelID phone/identifier and password.', 400);
    }
    $token = null;
    try {
        $member = egid_authenticate($identifier, $password);
        if (!egid_is_company_admin($member['phone'])) {
            fail('This EtelID is not registered as the admin/owner for Etel Group. Ask an association admin to link your EtelID first.', 403);
        }
        $token = eg_issue_token($member['etel_id'], $member['full_name']);
    } catch (Exception $e) {
        fail($e->getMessage(), 502);
    }
    eg_log_activity('login', $member['full_name'] . ' signed in');
    ok(array(
        'token' => $token,
        'member' => array('full_name' => $member['full_name'], 'etel_id' => $member['etel_id']),
    ));
    break;
}

case 'admin_get_stats': {
    eg_require_admin();
    $pdo = eg_db();
    $total = (int)$pdo->query('SELECT COUNT(*) FROM contact_messages')->fetchColumn();
    $new = (int)$pdo->query("SELECT COUNT(*) FROM contact_messages WHERE status='new'")->fetchColumn();
    $replied = (int)$pdo->query("SELECT COUNT(*) FROM contact_messages WHERE status='replied'")->fetchColumn();
    $thisWeek = (int)$pdo->query('SELECT COUNT(*) FROM contact_messages WHERE created_at >= (NOW() - INTERVAL 7 DAY)')->fetchColumn();
    ok(array(
        'total' => $total,
        'new' => $new,
        'replied' => $replied,
        'this_week' => $thisWeek,
        'reply_rate' => $total > 0 ? round(($replied / $total) * 100) : 0,
    ));
    break;
}

case 'admin_get_message_trend': {
    eg_require_admin();
    $pdo = eg_db();
    $rows = $pdo->query(
        "SELECT DATE(created_at) AS d, COUNT(*) AS c
         FROM contact_messages
         WHERE created_at >= (NOW() - INTERVAL 13 DAY)
         GROUP BY DATE(created_at)"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $days = array();
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $days[] = array('date' => $d, 'count' => isset($rows[$d]) ? (int)$rows[$d] : 0);
    }
    ok(array('days' => $days));
    break;
}

case 'admin_list_messages': {
    eg_require_admin();
    $pdo = eg_db();
    $status = isset($body['status']) ? trim((string)$body['status']) : (isset($_GET['status']) ? trim((string)$_GET['status']) : 'all');
    $q = isset($body['q']) ? trim((string)$body['q']) : (isset($_GET['q']) ? trim((string)$_GET['q']) : '');
    $page = max(1, intval($body['page'] ?? ($_GET['page'] ?? 1)));
    $perPage = 25;
    $offset = ($page - 1) * $perPage;

    $where = array();
    $params = array();
    if ($status !== 'all' && in_array($status, array('new', 'read', 'replied'), true)) {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    if ($q !== '') {
        $where[] = '(name LIKE ? OR email LIKE ? OR subject LIKE ? OR message LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM contact_messages $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT id, name, email, subject, company, status, created_at,
                LEFT(message, 140) AS message_preview
         FROM contact_messages $whereSql
         ORDER BY created_at DESC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    ok(array(
        'messages' => $stmt->fetchAll(),
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => max(1, (int)ceil($total / $perPage)),
    ));
    break;
}

case 'admin_get_message': {
    eg_require_admin();
    $pdo = eg_db();
    $id = intval($body['id'] ?? ($_GET['id'] ?? 0));
    if (!$id) fail('id required', 400);
    $stmt = $pdo->prepare('SELECT * FROM contact_messages WHERE id = ?');
    $stmt->execute(array($id));
    $msg = $stmt->fetch();
    if (!$msg) fail('Message not found', 404);
    // Viewing a "new" message automatically marks it read - matches
    // how every normal inbox behaves, no separate click needed.
    if ($msg['status'] === 'new') {
        $pdo->prepare("UPDATE contact_messages SET status='read' WHERE id=?")->execute(array($id));
        $msg['status'] = 'read';
    }
    ok(array('message' => $msg));
    break;
}

case 'admin_update_message_status': {
    eg_require_admin();
    $pdo = eg_db();
    $id = intval($body['id'] ?? 0);
    $status = (string)($body['status'] ?? '');
    if (!$id) fail('id required', 400);
    if (!in_array($status, array('new', 'read', 'replied'), true)) fail('Invalid status', 400);
    $pdo->prepare('UPDATE contact_messages SET status = ? WHERE id = ?')->execute(array($status, $id));
    eg_log_activity('message_status_updated', "Message #$id -> $status");
    ok(array('message' => 'Status updated'));
    break;
}

case 'admin_delete_message': {
    eg_require_admin();
    $pdo = eg_db();
    $id = intval($body['id'] ?? 0);
    if (!$id) fail('id required', 400);
    $pdo->prepare('DELETE FROM contact_messages WHERE id = ?')->execute(array($id));
    eg_log_activity('message_deleted', "Message #$id");
    ok(array('message' => 'Message deleted'));
    break;
}

case 'admin_export_messages_csv': {
    eg_require_admin();
    $pdo = eg_db();
    $rows = $pdo->query('SELECT id, name, email, subject, company, status, created_at, message FROM contact_messages ORDER BY created_at DESC')->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="etelgroup-contact-messages.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array('ID', 'Name', 'Email', 'Subject', 'Company', 'Status', 'Received', 'Message'));
    foreach ($rows as $r) {
        fputcsv($out, array($r['id'], $r['name'], $r['email'], $r['subject'], $r['company'], $r['status'], $r['created_at'], $r['message']));
    }
    fclose($out);
    exit;
}

// ---------- public homepage data (no auth) ----------

case 'member_count': {
    // Public, no auth - real registered-member count for the homepage's
    // counter. Falls back to a neutral response rather than a hard error
    // if EtelID is briefly unreachable, since this is decorative, not
    // critical, homepage data.
    try {
        ok(array('count' => egid_member_count()));
    } catch (Exception $e) {
        ok(array('count' => null));
    }
    break;
}

// ---------- companies (public read, admin write) ----------

case 'list_companies': {
    // Public, no auth - this is what the homepage itself fetches to
    // render the nav dropdown, mobile nav, company grid, and launcher
    // grid. Only meaningful fields, no internal timestamps needed.
    $pdo = eg_db();
    eg_ensure_companies_table($pdo);
    $rows = $pdo->query(
        'SELECT id, slug, name, tagline, tagline_am, description_short, description_long,
                url, logo_path, icon_key, dot_color, tags, status, sort_order
         FROM companies ORDER BY sort_order ASC, name ASC'
    )->fetchAll();
    ok(array('companies' => $rows));
    break;
}

case 'admin_list_companies': {
    eg_require_admin();
    $pdo = eg_db();
    eg_ensure_companies_table($pdo);
    $rows = $pdo->query('SELECT * FROM companies ORDER BY sort_order ASC, name ASC')->fetchAll();
    ok(array('companies' => $rows));
    break;
}

case 'admin_create_company': {
    eg_require_admin();
    $pdo = eg_db();
    eg_ensure_companies_table($pdo);
    $d = eg_company_input($body);
    if ($d['slug'] === '' || $d['name'] === '') fail('Name and slug are required', 400);
    try {
        $s = $pdo->prepare(
            'INSERT INTO companies (slug, name, tagline, tagline_am, description_short, description_long, url, logo_path, icon_key, dot_color, tags, status, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $s->execute(array(
            $d['slug'], $d['name'], $d['tagline'], $d['tagline_am'], $d['description_short'], $d['description_long'],
            $d['url'], $d['logo_path'], $d['icon_key'], $d['dot_color'], $d['tags'], $d['status'], $d['sort_order'],
        ));
    } catch (Exception $e) {
        fail('Could not create company - is the slug already used?', 400);
    }
    eg_log_activity('company_created', $d['name'] . ' (' . $d['slug'] . ')');
    ok(array('id' => intval($pdo->lastInsertId())));
    break;
}

case 'admin_update_company': {
    eg_require_admin();
    $pdo = eg_db();
    eg_ensure_companies_table($pdo);
    $id = intval($body['id'] ?? 0);
    if (!$id) fail('id required', 400);
    $d = eg_company_input($body);
    if ($d['slug'] === '' || $d['name'] === '') fail('Name and slug are required', 400);
    try {
        $s = $pdo->prepare(
            'UPDATE companies SET slug=?, name=?, tagline=?, tagline_am=?, description_short=?, description_long=?,
                                   url=?, logo_path=?, icon_key=?, dot_color=?, tags=?, status=?, sort_order=?
             WHERE id=?'
        );
        $s->execute(array(
            $d['slug'], $d['name'], $d['tagline'], $d['tagline_am'], $d['description_short'], $d['description_long'],
            $d['url'], $d['logo_path'], $d['icon_key'], $d['dot_color'], $d['tags'], $d['status'], $d['sort_order'], $id,
        ));
    } catch (Exception $e) {
        fail('Could not update company - is the slug already used?', 400);
    }
    eg_log_activity('company_updated', $d['name'] . ' (' . $d['slug'] . ')');
    ok(array('message' => 'Company updated'));
    break;
}

case 'admin_delete_company': {
    eg_require_admin();
    $pdo = eg_db();
    eg_ensure_companies_table($pdo);
    $id = intval($body['id'] ?? 0);
    if (!$id) fail('id required', 400);
    // Look up name/slug before deleting so the log entry still says what
    // was removed, not just an id nobody can identify afterward.
    $existing = $pdo->prepare('SELECT name, slug FROM companies WHERE id = ?');
    $existing->execute(array($id));
    $row = $existing->fetch();
    $pdo->prepare('DELETE FROM companies WHERE id = ?')->execute(array($id));
    eg_log_activity('company_deleted', $row ? ($row['name'] . ' (' . $row['slug'] . ')') : "id #$id");
    ok(array('message' => 'Company deleted'));
    break;
}

case 'admin_list_id_companies': {
    // Admin-only - EtelID's canonical slug/name/status registry, used to
    // populate the Companies form's slug picker so it can't drift from
    // what EtelID's role/auth routes actually recognize.
    eg_require_admin();
    try {
        ok(array('companies' => egid_list_id_companies()));
    } catch (Exception $e) {
        fail($e->getMessage(), 502);
    }
    break;
}

case 'admin_reorder_companies': {
    eg_require_admin();
    $pdo = eg_db();
    eg_ensure_companies_table($pdo);
    $order = $body['order'] ?? array(); // array of {id, sort_order}
    if (!is_array($order)) fail('order must be an array', 400);
    $s = $pdo->prepare('UPDATE companies SET sort_order = ? WHERE id = ?');
    foreach ($order as $row) {
        if (!isset($row['id'])) continue;
        $s->execute(array(intval($row['sort_order'] ?? 0), intval($row['id'])));
    }
    eg_log_activity('companies_reordered', count($order) . ' compan' . (count($order) === 1 ? 'y' : 'ies'));
    ok(array('message' => 'Order saved'));
    break;
}

case 'admin_list_employee_roles': {
    eg_require_admin();
    $slug = trim((string)($body['company_slug'] ?? ''));
    if ($slug === '') fail('company_slug required', 400);
    try {
        ok(array('roles' => egid_list_company_roles($slug)));
    } catch (Exception $e) {
        fail($e->getMessage(), 502);
    }
    break;
}

case 'admin_set_employee_role': {
    eg_require_admin();
    $identifier = trim((string)($body['identifier'] ?? ''));
    $slug = trim((string)($body['company_slug'] ?? ''));
    $role = trim((string)($body['role_at'] ?? ''));
    if ($identifier === '') fail('Enter the employee\'s phone or EtelID', 400);
    if ($slug === '') fail('company_slug required', 400);
    if ($role === '') fail('Choose or enter a role', 400);
    try {
        $result = egid_set_company_role($identifier, $slug, $role);
        eg_log_activity('employee_role_set', "$identifier -> $role at $slug");
        ok($result);
    } catch (Exception $e) {
        fail($e->getMessage(), 502);
    }
    break;
}

case 'admin_remove_employee_role': {
    eg_require_admin();
    $id = intval($body['id'] ?? 0);
    $slug = trim((string)($body['company_slug'] ?? ''));
    if (!$id) fail('id required', 400);
    if ($slug === '') fail('company_slug required', 400);
    try {
        egid_remove_company_role($id, $slug);
        eg_log_activity('employee_role_removed', "role link #$id at $slug");
        ok(array('message' => 'Role removed'));
    } catch (Exception $e) {
        fail($e->getMessage(), 502);
    }
    break;
}

// ---------- activity log (admin only) ----------

case 'admin_list_activity': {
    eg_require_admin();
    $pdo = eg_db();
    eg_ensure_activity_log_table($pdo);
    $page = max(1, intval($body['page'] ?? ($_GET['page'] ?? 1)));
    $perPage = 30;
    $offset = ($page - 1) * $perPage;
    $total = (int)$pdo->query('SELECT COUNT(*) FROM admin_activity_log')->fetchColumn();
    $stmt = $pdo->prepare(
        "SELECT actor_etel_id, actor_name, action, detail, created_at
         FROM admin_activity_log ORDER BY created_at DESC LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute();
    ok(array(
        'entries' => $stmt->fetchAll(),
        'total' => $total,
        'page' => $page,
        'total_pages' => max(1, (int)ceil($total / $perPage)),
    ));
    break;
}

}
