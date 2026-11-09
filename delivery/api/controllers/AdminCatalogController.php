<?php
// ============================================================
// ETEL - Admin: shop items (add, edit, import, extras, delete, show or hide) (split out of AdminController.php to keep that file small).
// Loaded just before the main switch, in the same scope, so $pdo, $u, $body and $action are the ones it set up.
// An action that is not listed here falls through to the main switch, which answers "Unknown action" as before.
// ============================================================
if (!defined('APP_VER')) { http_response_code(403); exit; } // direct access guard

switch ($action) {

case 'admin_add_item': {
    // POST: (requires admin auth)  service_giver_id, name, description, category, price, etc.
    // Admin adds item for a service giver
    $pdo = db();
    $admin = require_admin($pdo, 'items.manage');
    
    $sg_id = isset($body['service_giver_id']) ? intval($body['service_giver_id']) : 0;
    if (!$sg_id) fail('service_giver_id is required', 400);
    
    $name = isset($body['name']) ? trim($body['name']) : '';
    $description = isset($body['description']) ? trim($body['description']) : '';
    $category = isset($body['category']) ? trim($body['category']) : '';
    $stock_quantity = isset($body['stock_quantity']) ? intval($body['stock_quantity']) : 0;
    $preparation_time = isset($body['preparation_time']) ? intval($body['preparation_time']) : 30;
    $image_url = isset($body['image_url']) ? trim($body['image_url']) : '';

    if (!$name) fail('Item name is required', 400);
    if (!$category || !in_array($category, array('food','grocery','package','pharmacy','flower'))) {
        fail('Valid category is required', 400);
    }
    $price = etel_validate_amount(isset($body['price']) ? $body['price'] : null);
    if ($price === false) fail('Price must be a valid amount greater than 0', 400);
    $original_price = isset($body['original_price']) && $body['original_price'] !== ''
        ? etel_validate_amount($body['original_price'])
        : $price;
    if ($original_price === false) fail('Original price must be a valid amount greater than 0', 400);

    try {
        require_once __DIR__ . '/../lib/menu_catalog.php';
        $item_code = etel_catalog_new_item_code($pdo, $category);   // checked against existing codes (a random 4-digit one collided as menus grew)

        $ins = $pdo->prepare("
            INSERT INTO items (
                service_giver_id, item_code, name, description, category,
                price, original_price, stock_quantity, preparation_time, image_url, is_available
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $ins->execute(array(
            $sg_id, $item_code, $name, $description, $category,
            $price, $original_price, $stock_quantity, $preparation_time, $image_url
        ));
        
        $item_id = $pdo->lastInsertId();

        // Menu section ("Burger", "Drinks"...) and "Most popular": columns made on first use by the menu catalog code.
        if (isset($body['menu_section']) || !empty($body['is_popular'])) {
            require_once __DIR__ . '/../lib/menu_catalog.php';
            etel_ensure_catalog_schema($pdo);
            $pdo->prepare('UPDATE items SET menu_section = ?, is_popular = ? WHERE id = ?')->execute(array(
                etel_item_clean_section(isset($body['menu_section']) ? $body['menu_section'] : ''), etel_item_clean_popular(isset($body['is_popular']) ? $body['is_popular'] : 0), $item_id));
        }

        // Amharic name, quality and portion (the Item studio form).
        if (!empty($body['name_am']) || !empty($body['quality']) || !empty($body['portion'])) {
            require_once __DIR__ . '/../lib/menu_catalog.php';
            etel_ensure_catalog_schema($pdo);
            $pt = isset($body['portion']) ? trim(substr((string)$body['portion'], 0, 40)) : '';
            $pdo->prepare('UPDATE items SET name_am = ?, quality = ?, `portion` = ? WHERE id = ?')->execute(array(
                !empty($body['name_am']) ? trim($body['name_am']) : null, etel_catalog_clean_quality(isset($body['quality']) ? $body['quality'] : ''), ($pt !== '' ? $pt : null), $item_id));
        }

        ok(array(
            'item_id' => $item_id,
            'item_code' => $item_code,
            'message' => 'Item added successfully'
        ));
    } catch (Exception $e) {
        fail_ex($e, 'Failed to add item', 500);
    }
    break;
}

case 'admin_items_import': {
    // POST service_giver_id, rows:[{name, price, [name_am], [category], [quality], [portion], [section], [popular], [image_file|image_url]}] (max 300), [mode: skip|update], [dry_run]
    // Loads a shop's whole menu from a CSV. Dishes whose name matches the shared catalog are linked to it; the rest become
    // custom items. Dishes the shop already sells are skipped. dry_run saves nothing and shows the same counts.
    $pdo = db();
    $admin = require_admin($pdo, 'items.manage');
    require_once __DIR__ . '/../lib/menu_catalog.php';
    $sg_id = isset($body['service_giver_id']) ? intval($body['service_giver_id']) : 0;
    if ($sg_id <= 0) fail('Choose the shop first', 400);
    $rows = isset($body['rows']) ? $body['rows'] : null;
    if (!is_array($rows) || !count($rows)) fail('The file has no rows to import', 400);
    if (count($rows) > 300) fail('Import up to 300 items at a time', 400);
    try {
        $chk = $pdo->prepare('SELECT business_name FROM service_givers WHERE id = ?');
        $chk->execute(array($sg_id));
        $shop = $chk->fetchColumn();
        if ($shop === false) fail('Shop not found', 404);
        $dry = !empty($body['dry_run']);
        etel_ensure_catalog_schema($pdo);
        $pdo->beginTransaction();
        try {
            $res = etel_catalog_import_rows($pdo, $sg_id, $rows, 0, (isset($body['mode']) && $body['mode'] === 'update') ? 'update' : 'skip');
            if ($dry) $pdo->rollBack(); else $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        $res['dry_run'] = $dry;
        $res['shop'] = (string)$shop;
        ok($res);
    } catch (Exception $e) { fail_ex($e, 'Could not import the items', 500); }
    catch (Throwable $e) { fail_ex($e, 'Could not import the items', 500); }
    break;
}

case 'admin_get_all_items': {
    // GET: (requires admin auth)  ?category=food&service_giver_id=X
    // Admin views all items
    $pdo = db();
    $admin = require_admin($pdo, 'items.view');
    
    $category = isset($_GET['category']) ? trim($_GET['category']) : '';
    $sg_id = isset($_GET['service_giver_id']) ? intval($_GET['service_giver_id']) : 0;
    
    $where = 'WHERE 1=1';
    $params = array();
    
    if ($category && in_array($category, array('food','grocery','package','pharmacy','flower'))) {
        $where .= ' AND i.category = ?';
        $params[] = $category;
    }
    
    if ($sg_id > 0) {
        $where .= ' AND i.service_giver_id = ?';
        $params[] = $sg_id;
    }
    
    try {
        $s = $pdo->prepare("
            SELECT 
                i.*,
                sg.business_name,
                sg.zone,
                sg.service_type
            FROM items i
            JOIN service_givers sg ON i.service_giver_id = sg.id
            $where
            ORDER BY i.created_at DESC
        ");
        $s->execute($params);
        $items = $s->fetchAll();
    } catch (Exception $e) {
        fail_ex($e, 'Query failed', 500);
    }
    // Extras set up on each item (for the Edit form); the extra photos are already in the row as JSON text.
    require_once __DIR__ . '/../lib/menu_catalog.php';
    $xr = etel_item_extras_for($pdo, array_map(function ($i) { return $i['id']; }, $items));
    foreach ($items as &$it) { $it['extras'] = isset($xr[(int)$it['id']]) ? $xr[(int)$it['id']] : array(); }
    unset($it);
    
    ok(array('items' => $items, 'count' => count($items)));
    break;
}

case 'admin_update_item': {
    $pdo = db();
    require_admin($pdo, 'items.manage');
    $item_id = isset($body['item_id']) ? intval($body['item_id']) : 0;
    if (!$item_id) fail('item_id is required', 400);
    $allowed = array('name','name_am','description','category','price','original_price','stock_quantity','preparation_time','image_url','is_available');
    $updates = array(); $params = array();
    foreach ($allowed as $f) {
        if (isset($body[$f])) { $updates[] = "$f=?"; $params[] = $body[$f]; }
    }
    // Menu section and "Most popular" (an empty section clears it).
    if (array_key_exists('menu_section', $body) || array_key_exists('is_popular', $body)) {
        require_once __DIR__ . '/../lib/menu_catalog.php';
        etel_ensure_catalog_schema($pdo);
        if (array_key_exists('menu_section', $body)) { $updates[] = 'menu_section=?'; $params[] = etel_item_clean_section($body['menu_section']); }
        if (array_key_exists('is_popular', $body)) { $updates[] = 'is_popular=?'; $params[] = etel_item_clean_popular($body['is_popular']); }
    }
    if (array_key_exists('quality', $body) || array_key_exists('portion', $body)) {
        require_once __DIR__ . '/../lib/menu_catalog.php';
        etel_ensure_catalog_schema($pdo);
        if (array_key_exists('quality', $body)) { $updates[] = 'quality=?'; $params[] = etel_catalog_clean_quality($body['quality']); }
        if (array_key_exists('portion', $body)) { $pt = trim(substr((string)$body['portion'], 0, 40)); $updates[] = '`portion`=?'; $params[] = ($pt !== '' ? $pt : null); }
    }
    if (empty($updates)) fail('No fields to update', 400);
    $params[] = $item_id;
    try {
        $pdo->prepare('UPDATE items SET '.implode(',',$updates).' WHERE id=?')->execute($params);
        ok(array('updated'=>true));
    } catch (Exception $e) { fail_ex($e, 'Update failed', 500); }
    break;
}

case 'admin_item_extras_save': {
    // POST: item_id, [extras: [{id?, name, name_am?, price}] - replaces the item's extras], [gallery: ["/delivery/img/menu/a.jpg", ...] - replaces the extra photos]
    // The Edit item form's Extras and More photos sections. Admin only (items.manage).
    $pdo = db();
    require_admin($pdo, 'items.manage');
    require_once __DIR__ . '/../lib/menu_catalog.php';
    $item_id = isset($body['item_id']) ? intval($body['item_id']) : 0;
    if (!$item_id) fail('item_id is required', 400);
    $chk = $pdo->prepare('SELECT id FROM items WHERE id = ?');
    $chk->execute(array($item_id));
    if (!$chk->fetchColumn()) fail('Item not found', 404);
    etel_ensure_catalog_schema($pdo);
    try {
        $saved = null;
        if (array_key_exists('extras', $body)) {
            $list = array();
            foreach ((is_array($body['extras']) ? $body['extras'] : array()) as $e) {
                if (!is_array($e)) continue;
                $price = etel_validate_amount(isset($e['price']) && $e['price'] !== '' ? $e['price'] : 0, true);
                if ($price === false) fail('Every extra needs a valid price (0 or more)', 400);
                $list[] = array(isset($e['name']) ? $e['name'] : '', $price, isset($e['name_am']) ? $e['name_am'] : '', isset($e['id']) ? $e['id'] : 0);
            }
            $saved = etel_item_extras_save($pdo, $item_id, $list);
        }
        if (array_key_exists('gallery', $body)) {
            $g = etel_item_gallery_from(is_array($body['gallery']) ? $body['gallery'] : array());
            if ($g[2] !== null) fail($g[2], 400);
            $pdo->prepare('UPDATE items SET gallery = ? WHERE id = ?')->execute(array($g[0] ? json_encode(array_values($g[0]), JSON_UNESCAPED_SLASHES) : null, $item_id));
        }
        ok(array('saved' => true, 'extras' => $saved));
    } catch (Exception $e) { fail_ex($e, 'Could not save', 500); }
    break;
}

case 'admin_delete_item': {
    $pdo = db();
    require_admin($pdo, 'items.manage');
    $item_id = isset($body['item_id']) ? intval($body['item_id']) : 0;
    if (!$item_id) fail('item_id is required', 400);
    try {
        $pdo->prepare('DELETE FROM items WHERE id=?')->execute(array($item_id));
        ok(array('deleted'=>true));
    } catch (Exception $e) { fail_ex($e, 'Delete failed', 500); }
    break;
}

case 'admin_toggle_item': {
    $pdo = db();
    require_admin($pdo, 'items.manage');
    $item_id      = isset($body['item_id'])      ? intval($body['item_id'])      : 0;
    $is_available = isset($body['is_available']) ? intval($body['is_available']) : 0;
    if (!$item_id) fail('item_id is required', 400);
    try {
        $pdo->prepare('UPDATE items SET is_available=? WHERE id=?')->execute(array($is_available,$item_id));
        ok(array('toggled'=>true,'is_available'=>$is_available));
    } catch (Exception $e) { fail_ex($e, 'Toggle failed', 500); }
    break;
}

}
