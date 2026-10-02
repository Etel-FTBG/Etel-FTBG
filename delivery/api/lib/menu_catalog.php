<?php
// Shared menu catalog.
//  Shops used to type every dish by hand (name EN/AM, description, photo...) - the same "Doro Wot" for every
//  restaurant. The catalog is one master list of common dishes (managed by admin, grown by shop suggestions);
//  a shop ticks the dishes it sells and types only its own price. Every pick becomes a normal row in `items`
//  (so ordering, stock, availability etc. are untouched) with items.catalog_id remembering where it came from.
//  Prices, photos, quality and portion stay per shop.

function etel_catalog_categories() { return array('food', 'grocery', 'package', 'pharmacy', 'flower'); }
function etel_catalog_qualities()  { return array('regular', 'special', 'premium'); }

// Create the catalog table / items columns on first use (migration 067 does the same; either order works).
function etel_ensure_catalog_schema($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS menu_catalog (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            name_am VARCHAR(150) NULL,
            description TEXT NULL,
            description_am TEXT NULL,
            category VARCHAR(20) NOT NULL DEFAULT 'food',
            cuisine VARCHAR(40) NULL,
            packs VARCHAR(160) NULL,
            image_url VARCHAR(500) NULL,
            prep_time INT NOT NULL DEFAULT 20,
            status VARCHAR(10) NOT NULL DEFAULT 'active',
            suggested_by INT NULL,
            sort_order INT NOT NULL DEFAULT 100,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_catalog_name (name, category),
            KEY idx_catalog_status (status, category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cols = etel_table_columns($pdo, 'items');
        if ($cols) {
            if (!in_array('catalog_id', $cols, true)) $pdo->exec("ALTER TABLE items ADD COLUMN catalog_id INT NULL DEFAULT NULL");
            if (!in_array('quality', $cols, true))    $pdo->exec("ALTER TABLE items ADD COLUMN quality VARCHAR(12) NULL DEFAULT NULL");
            if (!in_array('portion', $cols, true))    $pdo->exec("ALTER TABLE items ADD COLUMN portion VARCHAR(40) NULL DEFAULT NULL");
        }
    } catch (Exception $e) {
        error_log('[ETEL] etel_ensure_catalog_schema: ' . $e->getMessage());
    }
}

// Pure: normalise a quality label; '' / unknown -> null.
function etel_catalog_clean_quality($q) {
    $q = strtolower(trim((string)$q));
    return in_array($q, etel_catalog_qualities(), true) ? $q : null;
}

// Pure: normalise pack tags ("Ethiopian Restaurant, cafe") -> "ethiopian_restaurant,cafe" (max 6).
function etel_catalog_clean_packs($packs) {
    if (is_string($packs)) $packs = explode(',', $packs);
    if (!is_array($packs)) return '';
    $out = array();
    foreach ($packs as $p) {
        $p = strtolower(trim((string)$p));
        $p = trim(preg_replace('/[^a-z0-9]+/', '_', $p), '_');
        if ($p !== '' && !in_array($p, $out, true)) $out[] = $p;
        if (count($out) >= 6) break;
    }
    return implode(',', $out);
}

// Pure: pick rows out of a request. Keeps the first entry per catalog_id, drops bad ids, caps at 150.
// Returns array of array(catalog_id, price(float|false), quality, portion, image_url, original_price(float|null|false)).
function etel_catalog_parse_picks($picks) {
    $out = array(); $seen = array();
    if (!is_array($picks)) return $out;
    foreach ($picks as $p) {
        if (!is_array($p)) continue;
        $cid = isset($p['catalog_id']) ? (int)$p['catalog_id'] : 0;
        if ($cid <= 0 || isset($seen[$cid])) continue;
        $seen[$cid] = true;
        $price = function_exists('etel_validate_amount') ? etel_validate_amount(isset($p['price']) ? $p['price'] : null) : false;
        $orig = null;
        if (isset($p['original_price']) && $p['original_price'] !== '' && $p['original_price'] !== null) {
            $orig = function_exists('etel_validate_amount') ? etel_validate_amount($p['original_price']) : false;
        }
        $portion = isset($p['portion']) ? trim(substr((string)$p['portion'], 0, 40)) : '';
        $img = isset($p['image_url']) ? trim(substr((string)$p['image_url'], 0, 500)) : '';
        $out[] = array($cid, $price, etel_catalog_clean_quality(isset($p['quality']) ? $p['quality'] : ''),
                       $portion !== '' ? $portion : null, $img !== '' ? $img : null, $orig);
        if (count($out) >= 150) break;
    }
    return $out;
}

function etel_catalog_new_item_code($pdo, $category) {
    $prefix = strtoupper(substr($category, 0, 4));
    for ($i = 0; $i < 8; $i++) {
        $code = $prefix . rand(10000, 99999);
        $s = $pdo->prepare('SELECT 1 FROM items WHERE item_code = ?');
        $s->execute(array($code));
        if (!$s->fetchColumn()) return $code;
    }
    return $prefix . substr((string)microtime(true) * 1000, -8);
}

// Active catalog rows for a shop screen + which ones the shop already sells.
function etel_catalog_for_shop($pdo, $sg_id, $category = '', $q = '') {
    etel_ensure_catalog_schema($pdo);
    $w = "WHERE status = 'active'"; $p = array();
    if ($category !== '' && in_array($category, etel_catalog_categories(), true)) { $w .= ' AND category = ?'; $p[] = $category; }
    if ($q !== '') { $w .= ' AND (name LIKE ? OR name_am LIKE ?)'; $lk = '%' . $q . '%'; $p[] = $lk; $p[] = $lk; }
    $st = $pdo->prepare("SELECT id, name, name_am, description, description_am, category, cuisine, packs, image_url, prep_time
                         FROM menu_catalog $w ORDER BY category, COALESCE(cuisine,''), sort_order, name LIMIT 600");
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $mine = array();
    $m = $pdo->prepare('SELECT id, catalog_id FROM items WHERE service_giver_id = ? AND catalog_id IS NOT NULL');
    $m->execute(array((int)$sg_id));
    foreach ($m->fetchAll(PDO::FETCH_ASSOC) as $r) $mine[(int)$r['catalog_id']] = (int)$r['id'];
    $packs = array();
    foreach ($rows as $r) {
        foreach (array_filter(explode(',', (string)$r['packs'])) as $pk) $packs[$pk] = isset($packs[$pk]) ? $packs[$pk] + 1 : 1;
    }
    ksort($packs);
    return array('catalog' => $rows, 'picked' => $mine, 'packs' => $packs);
}

// Create the shop's items from its picks. Returns array('added'=>n,'skipped'=>[catalog ids already sold],
// 'invalid'=>[catalog ids with bad price / not in the catalog], 'failed'=>[{catalog_id, error}] for rows the DB rejected).
function etel_catalog_add_picks($pdo, $sg_id, $picks) {
    etel_ensure_catalog_schema($pdo);
    $added = 0; $skipped = array(); $invalid = array();
    $ex = $pdo->prepare('SELECT 1 FROM items WHERE service_giver_id = ? AND catalog_id = ? LIMIT 1');
    $cs = $pdo->prepare("SELECT * FROM menu_catalog WHERE id = ? AND status = 'active'");
    $ins = $pdo->prepare('INSERT INTO items (service_giver_id, item_code, name, name_am, description, description_am, category,
                          price, original_price, stock_quantity, preparation_time, image_url, is_available, catalog_id, quality, portion)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 1, ?, ?, ?)');
    $failed = array();
    foreach ($picks as $pk) {
        list($cid, $price, $quality, $portion, $img, $orig) = $pk;
        if ($price === false || $orig === false) { $invalid[] = $cid; continue; }
        try {
            $cs->execute(array($cid));
            $c = $cs->fetch(PDO::FETCH_ASSOC);
            if (!$c) { $invalid[] = $cid; continue; }
            $ex->execute(array((int)$sg_id, $cid));
            if ($ex->fetchColumn()) { $skipped[] = $cid; continue; }
            $prep = (int)$c['prep_time'];
            $ins->execute(array(
                (int)$sg_id, etel_catalog_new_item_code($pdo, $c['category']), $c['name'], $c['name_am'] ? $c['name_am'] : null,
                (string)$c['description'], $c['description_am'] ? $c['description_am'] : null, $c['category'],
                $price, $orig === null ? $price : $orig, $prep ? $prep : 20,
                $img !== null ? $img : (string)$c['image_url'], $cid, $quality, $portion
            ));
            $added++;
        } catch (Exception $e) {
            error_log('[ETEL] etel_catalog_add_picks #' . $cid . ': ' . $e->getMessage());
            $failed[] = array('catalog_id' => $cid, 'error' => substr($e->getMessage(), 0, 200));
        } catch (Throwable $e) {
            error_log('[ETEL] etel_catalog_add_picks #' . $cid . ': ' . $e->getMessage());
            $failed[] = array('catalog_id' => $cid, 'error' => substr($e->getMessage(), 0, 200));
        }
    }
    return array('added' => $added, 'skipped' => $skipped, 'invalid' => $invalid, 'failed' => $failed);
}

// A shop's custom dish becomes a pending suggestion (admin approves it into the catalog). No-op if the
// name already exists in that category. Returns true when a new suggestion was created.
function etel_catalog_suggest($pdo, $user_id, $name, $name_am, $category, $description = '') {
    etel_ensure_catalog_schema($pdo);
    $name = trim((string)$name);
    if ($name === '' || !in_array($category, etel_catalog_categories(), true)) return false;
    $s = $pdo->prepare('SELECT 1 FROM menu_catalog WHERE name = ? AND category = ?');
    $s->execute(array($name, $category));
    if ($s->fetchColumn()) return false;
    $pdo->prepare("INSERT INTO menu_catalog (name, name_am, description, category, status, suggested_by) VALUES (?, ?, ?, ?, 'pending', ?)")
        ->execute(array(substr($name, 0, 150), $name_am !== '' ? substr($name_am, 0, 150) : null, (string)$description, $category, (int)$user_id));
    return true;
}
