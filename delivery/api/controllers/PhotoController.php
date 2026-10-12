<?php
// ============================================================
// ETEL Delivery - photo coverage tools (admin only)
//   admin_photo_coverage     {}  -> items without a photo, shops without logo/cover, coverage totals
//   admin_set_shop_branding  { service_giver_id, logo_url?, cover_url? }
// Items are updated with the existing admin_update_item (image_url on its own).
// ============================================================

switch ($action) {

case 'admin_photo_coverage': {
    $pdo = db();
    require_admin($pdo, 'items.view');
    $items = $pdo->query("SELECT i.id, i.name, i.category, sg.business_name
        FROM items i LEFT JOIN service_givers sg ON sg.id = i.service_giver_id
        WHERE (i.image_url IS NULL OR i.image_url = '')
        ORDER BY sg.business_name, i.name LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    $shops = array();
    try {
        $shops = $pdo->query("SELECT id, business_name, COALESCE(logo_url,'') AS logo_url, COALESCE(cover_url,'') AS cover_url, status
            FROM service_givers
            WHERE (logo_url IS NULL OR logo_url = '' OR cover_url IS NULL OR cover_url = '')
            ORDER BY (status = 'verified') DESC, business_name LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* branding columns not created yet: nothing to list */ }
    $t = array('items' => 0, 'items_with_photo' => 0, 'shops' => 0, 'shops_with_logo' => 0, 'shops_with_cover' => 0);
    try {
        $r = $pdo->query("SELECT COUNT(*) AS n, SUM(image_url IS NOT NULL AND image_url <> '') AS p FROM items")->fetch(PDO::FETCH_ASSOC);
        $t['items'] = intval($r['n']); $t['items_with_photo'] = intval($r['p']);
        $r = $pdo->query("SELECT COUNT(*) AS n, SUM(logo_url IS NOT NULL AND logo_url <> '') AS l, SUM(cover_url IS NOT NULL AND cover_url <> '') AS c FROM service_givers")->fetch(PDO::FETCH_ASSOC);
        $t['shops'] = intval($r['n']); $t['shops_with_logo'] = intval($r['l']); $t['shops_with_cover'] = intval($r['c']);
    } catch (Exception $e) {}
    ok(array('items' => $items, 'shops' => $shops, 'totals' => $t));
    break;
}

case 'admin_set_shop_branding': {
    $pdo = db();
    require_admin($pdo, 'items.manage');
    $id = isset($body['service_giver_id']) ? intval($body['service_giver_id']) : 0;
    if (!$id) fail('service_giver_id is required', 400);
    $sets = array(); $params = array();
    foreach (array('logo_url', 'cover_url') as $f) {
        if (!isset($body[$f])) continue;
        $v = trim((string) $body[$f]);
        // Only pictures uploaded through this system are accepted.
        if ($v === '' || !preg_match('#^https?://[^\s"<>]+/uploads/[A-Za-z0-9_./-]+$#', $v) || strlen($v) > 500) fail('That picture address is not valid', 400);
        $sets[] = "$f = ?"; $params[] = $v;
    }
    if (!$sets) fail('Nothing to update', 400);
    $params[] = $id;
    $st = $pdo->prepare('UPDATE service_givers SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $st->execute($params);
    ok(array('updated' => true));
    break;
}

}
