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
    } catch (Exception $e) {
        error_log('[ETEL] etel_ensure_catalog_schema (table): ' . $e->getMessage());
    }
    // One try per column so a failure on one never skips the others.
    $adds = array('catalog_id' => 'INT NULL DEFAULT NULL', 'quality' => 'VARCHAR(12) NULL DEFAULT NULL', 'portion' => 'VARCHAR(40) NULL DEFAULT NULL');
    try { $cols = etel_table_columns($pdo, 'items'); } catch (Exception $e) { $cols = array(); }
    if ($cols) {
        foreach ($adds as $col => $def) {
            if (in_array($col, $cols, true)) continue;
            try { $pdo->exec("ALTER TABLE items ADD COLUMN $col $def"); }
            catch (Exception $e) { error_log('[ETEL] etel_ensure_catalog_schema (' . $col . '): ' . $e->getMessage()); }
        }
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
    $added = 0; $skipped = array(); $invalid = array(); $failed = array();
    // Statements are prepared up front. The connection does real server-side prepares, so a missing column
    // throws here (not per dish) - report it for every pick instead of a bare 500, and fall back to an INSERT
    // without quality/portion when only those two are missing.
    $use_extra = true;
    try {
        $ex = $pdo->prepare('SELECT 1 FROM items WHERE service_giver_id = ? AND catalog_id = ? LIMIT 1');
        $cs = $pdo->prepare("SELECT * FROM menu_catalog WHERE id = ? AND status = 'active'");
        $base_cols = 'service_giver_id, item_code, name, name_am, description, description_am, category, price, original_price, stock_quantity, preparation_time, image_url, is_available, catalog_id';
        try {
            $ins = $pdo->prepare("INSERT INTO items ($base_cols, quality, portion) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 1, ?, ?, ?)");
        } catch (Exception $e0) {
            error_log('[ETEL] etel_catalog_add_picks: quality/portion unavailable: ' . $e0->getMessage());
            $use_extra = false;
            $ins = $pdo->prepare("INSERT INTO items ($base_cols) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 1, ?)");
        }
    } catch (Exception $e) {
        error_log('[ETEL] etel_catalog_add_picks prepare: ' . $e->getMessage());
        foreach ($picks as $pk) $failed[] = array('catalog_id' => $pk[0], 'error' => substr($e->getMessage(), 0, 200));
        return array('added' => 0, 'skipped' => array(), 'invalid' => array(), 'failed' => $failed);
    }
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
            $vals = array(
                (int)$sg_id, etel_catalog_new_item_code($pdo, $c['category']), $c['name'], $c['name_am'] ? $c['name_am'] : null,
                (string)$c['description'], $c['description_am'] ? $c['description_am'] : null, $c['category'],
                $price, $orig === null ? $price : $orig, $prep ? $prep : 20,
                $img !== null ? $img : (string)$c['image_url'], $cid
            );
            if ($use_extra) { $vals[] = $quality; $vals[] = $portion; }
            $ins->execute($vals);
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


// Pure: lower-case, trim and collapse spaces so "Doro  Wot " matches "doro wot".
function etel_catalog_norm($s) {
    $s = trim((string)$s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

// Pure: tidy one imported row into array(ok(bool), clean(array)|reason(string)). Needs name + a valid price.
function etel_catalog_clean_import_row($r) {
    if (!is_array($r)) return array(false, 'not a row');
    $name = isset($r['name']) ? trim((string)$r['name']) : '';
    if ($name === '') return array(false, 'missing name');
    if (strlen($name) > 150) $name = substr($name, 0, 150);
    $price = function_exists('etel_validate_amount') ? etel_validate_amount(isset($r['price']) ? $r['price'] : null) : false;
    if ($price === false) return array(false, 'invalid price');
    $cat = isset($r['category']) ? strtolower(trim((string)$r['category'])) : '';
    if (!in_array($cat, etel_catalog_categories(), true)) $cat = 'food';
    $portion = isset($r['portion']) ? trim(substr((string)$r['portion'], 0, 40)) : '';
    $name_am = isset($r['name_am']) ? trim(substr((string)$r['name_am'], 0, 150)) : '';
    return array(true, array(
        'name' => $name, 'name_am' => $name_am, 'price' => $price, 'category' => $cat,
        'quality' => etel_catalog_clean_quality(isset($r['quality']) ? $r['quality'] : ''),
        'portion' => $portion !== '' ? $portion : null,
    ));
}

// Import a menu (rows from a CSV the shop uploaded). A row whose name (English or Amharic) matches an active
// catalog dish becomes a catalog pick (so it is linked and gets the photo / descriptions); anything else is
// created as a custom item. Dishes the shop already sells (same catalog dish or same name) are skipped.
// Returns array('catalog'=>n,'custom'=>n,'skipped'=>n,'invalid'=>[{row,reason}],'failed'=>[{row,error}]).
function etel_catalog_import_rows($pdo, $sg_id, $rows, $user_id = 0) {
    etel_ensure_catalog_schema($pdo);
    $res = array('catalog' => 0, 'custom' => 0, 'skipped' => 0, 'invalid' => array(), 'failed' => array());
    if (!is_array($rows)) return $res;
    $rows = array_slice($rows, 0, 300);

    // lookup: normalised name (EN and AM) + category -> catalog id
    $byName = array();
    foreach ($pdo->query("SELECT id, name, name_am, category FROM menu_catalog WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $byName[$c['category'] . '|' . etel_catalog_norm($c['name'])] = (int)$c['id'];
        if ($c['name_am'] !== null && $c['name_am'] !== '') $byName[$c['category'] . '|' . etel_catalog_norm($c['name_am'])] = (int)$c['id'];
    }
    // what the shop already sells
    $haveCat = array(); $haveName = array();
    $q = $pdo->prepare('SELECT name, catalog_id FROM items WHERE service_giver_id = ?');
    $q->execute(array((int)$sg_id));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $haveName[etel_catalog_norm($it['name'])] = true;
        if ($it['catalog_id'] !== null) $haveCat[(int)$it['catalog_id']] = true;
    }

    foreach ($rows as $i => $raw) {
        $line = $i + 1;
        $c = etel_catalog_clean_import_row($raw);
        if (!$c[0]) { $res['invalid'][] = array('row' => $line, 'reason' => $c[1]); continue; }
        $r = $c[1];
        $key = etel_catalog_norm($r['name']);
        $cid = isset($byName[$r['category'] . '|' . $key]) ? $byName[$r['category'] . '|' . $key]
             : (($r['name_am'] !== '' && isset($byName[$r['category'] . '|' . etel_catalog_norm($r['name_am'])])) ? $byName[$r['category'] . '|' . etel_catalog_norm($r['name_am'])] : 0);
        if (($cid && isset($haveCat[$cid])) || isset($haveName[$key])) { $res['skipped']++; continue; }
        try {
            if ($cid) {
                $one = etel_catalog_add_picks($pdo, $sg_id, array(array($cid, $r['price'], $r['quality'], $r['portion'], null, null)));
                if ($one['added']) { $res['catalog']++; $haveCat[$cid] = true; $haveName[$key] = true; }
                elseif ($one['failed']) { $res['failed'][] = array('row' => $line, 'error' => $one['failed'][0]['error']); }
                else { $res['skipped']++; }
            } else {
                $cols = etel_table_columns($pdo, 'items');
                $extra = in_array('quality', $cols, true) && in_array('portion', $cols, true);
                $sql = 'INSERT INTO items (service_giver_id, item_code, name, name_am, description, category, price, original_price, stock_quantity, preparation_time, image_url, is_available'
                     . ($extra ? ', quality, portion' : '') . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 20, ?, 1' . ($extra ? ', ?, ?' : '') . ')';
                $vals = array((int)$sg_id, etel_catalog_new_item_code($pdo, $r['category']), $r['name'], $r['name_am'] !== '' ? $r['name_am'] : null,
                              '', $r['category'], $r['price'], $r['price'], '');
                if ($extra) { $vals[] = $r['quality']; $vals[] = $r['portion']; }
                $pdo->prepare($sql)->execute($vals);
                $res['custom']++; $haveName[$key] = true;
                if ($user_id) { try { etel_catalog_suggest($pdo, $user_id, $r['name'], $r['name_am'], $r['category']); } catch (Exception $e) {} }
            }
        } catch (Exception $e) {
            error_log('[ETEL] etel_catalog_import_rows row ' . $line . ': ' . $e->getMessage());
            $res['failed'][] = array('row' => $line, 'error' => substr($e->getMessage(), 0, 160));
        } catch (Throwable $e) {
            error_log('[ETEL] etel_catalog_import_rows row ' . $line . ': ' . $e->getMessage());
            $res['failed'][] = array('row' => $line, 'error' => substr($e->getMessage(), 0, 160));
        }
    }
    return $res;
}
