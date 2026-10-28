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
    $adds = array('catalog_id' => 'INT NULL DEFAULT NULL', 'quality' => 'VARCHAR(12) NULL DEFAULT NULL', 'portion' => 'VARCHAR(40) NULL DEFAULT NULL',
                  'menu_section' => 'VARCHAR(60) NULL DEFAULT NULL', 'is_popular' => 'TINYINT(1) NOT NULL DEFAULT 0', 'gallery' => 'TEXT NULL');
    try { $cols = etel_table_columns($pdo, 'items'); } catch (Exception $e) { $cols = array(); }
    if ($cols) {
        foreach ($adds as $col => $def) {
            if (in_array($col, $cols, true)) continue;
            try { $pdo->exec("ALTER TABLE items ADD COLUMN $col $def"); }
            catch (Exception $e) { error_log('[ETEL] etel_ensure_catalog_schema (' . $col . '): ' . $e->getMessage()); }
        }
    }
    etel_ensure_item_extras_table($pdo);
}

// Optional extras a customer can tick on an item ("Mayonnaise +50"); the order adds their prices on the server.
function etel_ensure_item_extras_table($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS item_extras (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_id INT NOT NULL,
            name VARCHAR(80) NOT NULL,
            name_am VARCHAR(80) NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_item_extras_item (item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        error_log('[ETEL] etel_ensure_item_extras_table: ' . $e->getMessage());
    }
}

// Pure: "Mayonnaise:50|Cheese:100|Chili" -> [[name, price], ...]. A missing price is 0. Up to 12; bad entries are left out.
function etel_item_extras_parse($text) {
    $out = array();
    foreach (preg_split('/[|\n]+/u', (string)$text) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        $price = 0.0; $name = $part;
        if (preg_match('/^(.*?)\s*[:=]\s*([0-9]+(?:\.[0-9]{1,2})?)$/u', $part, $m)) { $name = trim($m[1]); $price = (float)$m[2]; }
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 80, 'UTF-8') : substr($name, 0, 80);
        if ($name === '' || $price < 0 || $price > 100000) continue;
        $out[] = array($name, $price);
        if (count($out) >= 12) break;
    }
    return $out;
}

// Pure: more photos for one item, from an array or a "a.jpg|b.jpg" text; file names must exist in /delivery/img/menu/.
// Returns array(urls (max 5), missing_count, error|null).
function etel_item_gallery_from($v) {
    $parts = is_array($v) ? $v : preg_split('/[|\n]+/', (string)$v);
    $urls = array(); $missing = 0;
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p === '') continue;
        $ph = etel_item_photo_from_row(preg_match('#^(https?://|/)#i', $p) ? array('image_url' => $p) : array('image_file' => $p));
        if ($ph[2] !== null) return array(array(), 0, $ph[2]);
        if ($ph[1]) { $missing++; continue; }
        if ($ph[0] !== null) $urls[] = $ph[0];
        if (count($urls) >= 5) break;
    }
    return array($urls, $missing, null);
}

// Active extras for these items: item id => [{id, name, name_am, price}], in the order they were set up.
function etel_item_extras_for($pdo, $item_ids) {
    $out = array();
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$item_ids))));
    if (!$ids) return $out;
    try {
        $q = $pdo->prepare('SELECT id, item_id, name, name_am, price FROM item_extras WHERE is_active = 1 AND item_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY sort_order, id');
        $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['item_id']][] = array('id' => (int)$r['id'], 'name' => $r['name'], 'name_am' => $r['name_am'], 'price' => round((float)$r['price'], 2));
        }
    } catch (Exception $e) { /* no extras table yet: nothing to offer */ }
    return $out;
}

// Replace one item's extras with this list of [name, price, name_am?, id?]: kept ids are updated, new ones added, the rest removed.
function etel_item_extras_save($pdo, $item_id, $list) {
    etel_ensure_item_extras_table($pdo);
    $keep = array(); $order = 0;
    $have = array();
    $q = $pdo->prepare('SELECT id FROM item_extras WHERE item_id = ?');
    $q->execute(array((int)$item_id));
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) $have[(int)$id] = true;
    $upd = $pdo->prepare('UPDATE item_extras SET name = ?, name_am = ?, price = ?, sort_order = ?, is_active = 1 WHERE id = ? AND item_id = ?');
    $ins = $pdo->prepare('INSERT INTO item_extras (item_id, name, name_am, price, sort_order) VALUES (?, ?, ?, ?, ?)');
    foreach (array_slice((array)$list, 0, 12) as $e) {
        $name = isset($e[0]) ? trim((string)$e[0]) : '';
        $price = isset($e[1]) ? (float)$e[1] : 0.0;
        if ($name === '' || $price < 0 || $price > 100000) continue;
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 80, 'UTF-8') : substr($name, 0, 80);
        $am = isset($e[2]) && trim((string)$e[2]) !== '' ? trim((string)$e[2]) : null;
        $eid = isset($e[3]) ? (int)$e[3] : 0;
        $order += 10;
        if ($eid && isset($have[$eid])) { $upd->execute(array($name, $am, $price, $order, $eid, (int)$item_id)); $keep[$eid] = true; }
        else { $ins->execute(array((int)$item_id, $name, $am, $price, $order)); $keep[(int)$pdo->lastInsertId()] = true; }
    }
    foreach ($have as $id => $_) {
        if (!isset($keep[$id])) $pdo->prepare('DELETE FROM item_extras WHERE id = ? AND item_id = ?')->execute(array($id, (int)$item_id));
    }
    return count($keep);
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
            $ins = $pdo->prepare("INSERT INTO items ($base_cols, quality, `portion`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 100, ?, ?, 1, ?, ?, ?)");
        } catch (Exception $e0) {
            error_log('[ETEL] etel_catalog_add_picks: quality/portion unavailable: ' . $e0->getMessage());
            $use_extra = false;
            $ins = $pdo->prepare("INSERT INTO items ($base_cols) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 100, ?, ?, 1, ?)");
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

// NOTE: stock_quantity is set to 100 on every insert here. A stock of 0 means "sold out" to the customer app
// and to reorder (they treat stock <= 0 as unavailable), so 0 would hide the dish.

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
// Pure: a menu section name ("Burger", "Drinks", "Breakfast"): trimmed, spaces collapsed, 60 characters at most; '' -> null.
function etel_item_clean_section($v) {
    $v = trim(preg_replace('/\s+/u', ' ', (string)$v));
    if ($v === '') return null;
    return function_exists('mb_substr') ? mb_substr($v, 0, 60, 'UTF-8') : substr($v, 0, 60);
}
// Pure: "yes", "1", "true", "x", "popular", "star" mean yes; anything else (or nothing) means no.
function etel_item_clean_popular($v) {
    if ($v === true || $v === 1) return 1;
    return in_array(strtolower(trim((string)$v)), array('1', 'y', 'yes', 'true', 'x', 'popular', 'star', '*'), true) ? 1 : 0;
}
// A shop item photo: a plain file name kept in /delivery/img/menu/ (linked only when the file is really there) or an address
// starting with / or http. Returns array(url|null, missing(bool), error|null).
function etel_item_photo_from_row($r) {
    $u = isset($r['image_url']) ? trim((string)$r['image_url']) : '';
    $f = isset($r['image_file']) ? trim((string)$r['image_file']) : '';
    if ($u !== '') {
        if (!preg_match('#^(https?://|/)#i', $u)) return array(null, false, 'photo address must start with / or http');
        return array(substr($u, 0, 500), false, null);
    }
    if ($f === '') return array(null, false, null);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,80}\.(jpe?g|png|webp)$/i', $f)) return array(null, false, 'bad photo file name "' . substr($f, 0, 40) . '"');
    if (is_file(dirname(__DIR__, 2) . '/img/menu/' . $f)) return array('/delivery/img/menu/' . $f, false, null);
    return array(null, true, null);
}

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
    $ph = etel_item_photo_from_row($r);
    if ($ph[2] !== null) return array(false, $ph[2]);
    $extrasTxt = isset($r['extras']) ? trim((string)$r['extras']) : '';
    $extras = $extrasTxt !== '' ? etel_item_extras_parse($extrasTxt) : null;
    $more = isset($r['more_photos']) ? trim((string)$r['more_photos']) : '';
    $gal = array(array(), 0, null);
    if ($more !== '') { $gal = etel_item_gallery_from($more); if ($gal[2] !== null) return array(false, $gal[2]); }
    return array(true, array(
        'name' => $name, 'name_am' => $name_am, 'price' => $price, 'category' => $cat,
        'quality' => etel_catalog_clean_quality(isset($r['quality']) ? $r['quality'] : ''),
        'portion' => $portion !== '' ? $portion : null,
        'section' => etel_item_clean_section(isset($r['section']) ? $r['section'] : ''),
        'popular' => etel_item_clean_popular(isset($r['popular']) ? $r['popular'] : ''),
        'image' => $ph[0], 'photo_missing' => $ph[1],
        'extras' => $extras,
        'gallery' => $more !== '' ? $gal[0] : null, 'gallery_missing' => $gal[1],
    ));
}

// A cleaned import row may carry extras and more photos: write the ones the file filled in (a blank cell leaves them alone).
function etel_item_apply_extras_gallery($pdo, $item_id, $r, $hasCols) {
    if (!$hasCols) return;
    if ($r['extras'] !== null) {
        $list = array();
        foreach ($r['extras'] as $e) $list[] = array($e[0], $e[1]);
        etel_item_extras_save($pdo, $item_id, $list);
    }
    if ($r['gallery'] !== null) {
        $pdo->prepare('UPDATE items SET gallery = ? WHERE id = ?')->execute(array($r['gallery'] ? json_encode(array_values($r['gallery']), JSON_UNESCAPED_SLASHES) : null, $item_id));
    }
}

// Import a menu (rows from a CSV the shop uploaded). A row whose name (English or Amharic) matches an active
// catalog dish becomes a catalog pick (so it is linked and gets the photo / descriptions); anything else is
// created as a custom item. Dishes the shop already sells (same catalog dish or same name) are skipped, or, when
// $mode is 'update', changed: only what the file filled in (price, quality, portion, Amharic name, section, popular, photo).
// A row can also carry section (the menu heading the customer sees: Burger, Drinks...), popular (yes = "Most popular") and a
// photo (image_file kept in /delivery/img/menu/, or image_url).
// Returns array('catalog'=>n,'custom'=>n,'updated'=>n,'skipped'=>n,'photos_missing'=>n,'sections'=>n,'invalid'=>[{row,reason}],'failed'=>[{row,error}]).
function etel_catalog_import_rows($pdo, $sg_id, $rows, $user_id = 0, $mode = 'skip') {
    etel_ensure_catalog_schema($pdo);
    $res = array('catalog' => 0, 'custom' => 0, 'updated' => 0, 'skipped' => 0, 'photos_missing' => 0, 'sections' => 0, 'invalid' => array(), 'failed' => array());
    if (!is_array($rows)) return $res;
    $rows = array_slice($rows, 0, 300);
    $update = ($mode === 'update');
    $icols = etel_table_columns($pdo, 'items');
    // Asked of the database directly: etel_table_columns() remembers its first answer, which is stale right after the columns were added.
    try {
        $hs = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items' AND COLUMN_NAME IN ('menu_section', 'is_popular', 'gallery')");
        $hasSec = intval($hs->fetchColumn()) === 3;
    } catch (Exception $e) { $hasSec = false; }
    $seenSections = array();

    // lookup: normalised name (EN and AM) + category -> catalog id
    $byName = array();
    foreach ($pdo->query("SELECT id, name, name_am, category FROM menu_catalog WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $byName[$c['category'] . '|' . etel_catalog_norm($c['name'])] = (int)$c['id'];
        if ($c['name_am'] !== null && $c['name_am'] !== '') $byName[$c['category'] . '|' . etel_catalog_norm($c['name_am'])] = (int)$c['id'];
    }
    // what the shop already sells: name -> item id, catalog dish -> item id
    $haveCat = array(); $haveName = array();
    $q = $pdo->prepare('SELECT id, name, catalog_id FROM items WHERE service_giver_id = ?');
    $q->execute(array((int)$sg_id));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $haveName[etel_catalog_norm($it['name'])] = (int)$it['id'];
        if ($it['catalog_id'] !== null) $haveCat[(int)$it['catalog_id']] = (int)$it['id'];
    }

    foreach ($rows as $i => $raw) {
        $line = $i + 1;
        $c = etel_catalog_clean_import_row($raw);
        if (!$c[0]) { $res['invalid'][] = array('row' => $line, 'reason' => $c[1]); continue; }
        $r = $c[1];
        $key = etel_catalog_norm($r['name']);
        $cid = isset($byName[$r['category'] . '|' . $key]) ? $byName[$r['category'] . '|' . $key]
             : (($r['name_am'] !== '' && isset($byName[$r['category'] . '|' . etel_catalog_norm($r['name_am'])])) ? $byName[$r['category'] . '|' . etel_catalog_norm($r['name_am'])] : 0);
        $existing = isset($haveName[$key]) ? $haveName[$key] : (($cid && isset($haveCat[$cid])) ? $haveCat[$cid] : 0);
        if ($r['photo_missing']) $res['photos_missing']++;
        $res['photos_missing'] += (int)$r['gallery_missing'];
        if ($r['section'] !== null) $seenSections[$r['section']] = true;
        try {
            if ($existing) {
                if (!$update) { $res['skipped']++; continue; }
                $set = array('price = ?', 'original_price = ?'); $vals = array($r['price'], $r['price']);
                if ($r['name_am'] !== '') { $set[] = 'name_am = ?'; $vals[] = $r['name_am']; }
                if ($r['quality'] !== null && in_array('quality', $icols, true)) { $set[] = 'quality = ?'; $vals[] = $r['quality']; }
                if ($r['portion'] !== null && in_array('portion', $icols, true)) { $set[] = '`portion` = ?'; $vals[] = $r['portion']; }
                if ($hasSec && $r['section'] !== null) { $set[] = 'menu_section = ?'; $vals[] = $r['section']; }
                if ($hasSec && isset($raw['popular']) && trim((string)$raw['popular']) !== '') { $set[] = 'is_popular = ?'; $vals[] = $r['popular']; }
                if ($r['image'] !== null) { $set[] = 'image_url = ?'; $vals[] = $r['image']; }
                $vals[] = $existing; $vals[] = (int)$sg_id;
                $pdo->prepare('UPDATE items SET ' . implode(', ', $set) . ' WHERE id = ? AND service_giver_id = ?')->execute($vals);
                etel_item_apply_extras_gallery($pdo, $existing, $r, $hasSec);
                $res['updated']++;
                continue;
            }
            $newId = 0;
            if ($cid) {
                $one = etel_catalog_add_picks($pdo, $sg_id, array(array($cid, $r['price'], $r['quality'], $r['portion'], $r['image'], null)));
                if ($one['added']) { $res['catalog']++; $newId = (int)$pdo->lastInsertId(); $haveCat[$cid] = $newId; $haveName[$key] = $newId; }
                elseif ($one['failed']) { $res['failed'][] = array('row' => $line, 'error' => $one['failed'][0]['error']); continue; }
                else { $res['skipped']++; continue; }
            } else {
                $extra = in_array('quality', $icols, true) && in_array('portion', $icols, true);
                $sql = 'INSERT INTO items (service_giver_id, item_code, name, name_am, description, category, price, original_price, stock_quantity, preparation_time, image_url, is_available'
                     . ($extra ? ', quality, `portion`' : '') . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, 100, 20, ?, 1' . ($extra ? ', ?, ?' : '') . ')';
                $vals = array((int)$sg_id, etel_catalog_new_item_code($pdo, $r['category']), $r['name'], $r['name_am'] !== '' ? $r['name_am'] : null,
                              '', $r['category'], $r['price'], $r['price'], $r['image'] !== null ? $r['image'] : '');
                if ($extra) { $vals[] = $r['quality']; $vals[] = $r['portion']; }
                $pdo->prepare($sql)->execute($vals);
                $newId = (int)$pdo->lastInsertId();
                $res['custom']++; $haveName[$key] = $newId;
                if ($user_id) { try { etel_catalog_suggest($pdo, $user_id, $r['name'], $r['name_am'], $r['category']); } catch (Exception $e) {} }
            }
            if ($newId && $hasSec && ($r['section'] !== null || $r['popular'])) {
                $pdo->prepare('UPDATE items SET menu_section = ?, is_popular = ? WHERE id = ? AND service_giver_id = ?')->execute(array($r['section'], $r['popular'], $newId, (int)$sg_id));
            }
            if ($newId) etel_item_apply_extras_gallery($pdo, $newId, $r, $hasSec);
        } catch (Exception $e) {
            error_log('[ETEL] etel_catalog_import_rows row ' . $line . ': ' . $e->getMessage());
            $res['failed'][] = array('row' => $line, 'error' => substr($e->getMessage(), 0, 160));
        } catch (Throwable $e) {
            error_log('[ETEL] etel_catalog_import_rows row ' . $line . ': ' . $e->getMessage());
            $res['failed'][] = array('row' => $line, 'error' => substr($e->getMessage(), 0, 160));
        }
    }
    $res['sections'] = count($seenSections);
    return $res;
}


// Copy one shop's menu to another (a second branch, a new shop of the same owner...). Admin-driven: the app
// links one login to one shop, so branches are separate shops. Prices can be moved by a percentage (-50..+200),
// dishes the target already has (same catalog dish or same name) are skipped, hidden items are skipped unless
// $opts['include_hidden'] is set. Returns array('copied'=>n,'skipped'=>n,'failed'=>[{name,error}]).
function etel_catalog_copy_menu($pdo, $from_sg, $to_sg, $opts = array()) {
    etel_ensure_catalog_schema($pdo);
    $res = array('copied' => 0, 'skipped' => 0, 'failed' => array());
    $from_sg = (int)$from_sg; $to_sg = (int)$to_sg;
    if ($from_sg <= 0 || $to_sg <= 0 || $from_sg === $to_sg) return $res;
    $pct = isset($opts['percent']) ? (float)$opts['percent'] : 0.0;
    if ($pct < -50) $pct = -50; if ($pct > 200) $pct = 200;
    $factor = 1 + $pct / 100;
    $hidden = !empty($opts['include_hidden']);

    $cols = etel_table_columns($pdo, 'items');
    $extra = in_array('quality', $cols, true) && in_array('portion', $cols, true) && in_array('catalog_id', $cols, true);

    $have = array(); $haveCat = array();
    $q = $pdo->prepare('SELECT name, catalog_id FROM items WHERE service_giver_id = ?');
    $q->execute(array($to_sg));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $have[etel_catalog_norm($it['name'])] = true;
        if (isset($it['catalog_id']) && $it['catalog_id'] !== null) $haveCat[(int)$it['catalog_id']] = true;
    }
    $src = $pdo->prepare('SELECT * FROM items WHERE service_giver_id = ?' . ($hidden ? '' : ' AND is_available = 1') . ' ORDER BY id');
    $src->execute(array($from_sg));
    $sql = 'INSERT INTO items (service_giver_id, item_code, name, name_am, description, description_am, category, price, original_price, stock_quantity, preparation_time, image_url, is_available'
         . ($extra ? ', catalog_id, quality, `portion`' : '') . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1' . ($extra ? ', ?, ?, ?' : '') . ')';
    $ins = $pdo->prepare($sql);
    foreach ($src->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $key = etel_catalog_norm($it['name']);
        $cid = ($extra && isset($it['catalog_id']) && $it['catalog_id'] !== null) ? (int)$it['catalog_id'] : 0;
        if (isset($have[$key]) || ($cid && isset($haveCat[$cid]))) { $res['skipped']++; continue; }
        $price = round((float)$it['price'] * $factor, 2);
        if ($price <= 0) { $res['failed'][] = array('name' => $it['name'], 'error' => 'price would be 0'); continue; }
        $orig = ($it['original_price'] !== null && (float)$it['original_price'] > 0) ? round((float)$it['original_price'] * $factor, 2) : $price;
        $stock = (int)$it['stock_quantity'] > 0 ? (int)$it['stock_quantity'] : 100;
        $vals = array($to_sg, etel_catalog_new_item_code($pdo, $it['category']), $it['name'], $it['name_am'], (string)$it['description'], $it['description_am'],
                      $it['category'], $price, $orig, $stock, (int)$it['preparation_time'] ?: 20, (string)$it['image_url']);
        if ($extra) { $vals[] = $cid ?: null; $vals[] = isset($it['quality']) ? $it['quality'] : null; $vals[] = isset($it['portion']) ? $it['portion'] : null; }
        try { $ins->execute($vals); $res['copied']++; $have[$key] = true; if ($cid) $haveCat[$cid] = true; }
        catch (Exception $e) { error_log('[ETEL] etel_catalog_copy_menu ' . $it['name'] . ': ' . $e->getMessage()); $res['failed'][] = array('name' => $it['name'], 'error' => substr($e->getMessage(), 0, 140)); }
        catch (Throwable $e) { $res['failed'][] = array('name' => $it['name'], 'error' => substr($e->getMessage(), 0, 140)); }
    }
    return $res;
}


// ---- Admin bulk import of catalog dishes (Admin -> Menu catalog -> Import CSV) --------------------------------------
// Pure: tidy one imported catalog row into array(ok(bool), clean(array)|reason(string)). Only a name is required.
// Photo: image_url (an address starting with / or http) or image_file (a plain file name, kept in /delivery/img/menu/).
function etel_catalog_clean_admin_row($r) {
    if (!is_array($r)) return array(false, 'not a row');
    $g = function ($k) use ($r) { return isset($r[$k]) ? trim((string)$r[$k]) : ''; };
    $name = $g('name');
    if ($name === '') return array(false, 'missing name');
    $cat = strtolower($g('category'));
    if ($cat === '') $cat = 'food';
    if (!in_array($cat, etel_catalog_categories(), true)) return array(false, 'unknown category "' . substr($cat, 0, 20) . '"');
    $status = strtolower($g('status'));
    if ($status === '') $status = 'active';
    if (!in_array($status, array('active', 'pending', 'hidden'), true)) return array(false, 'unknown status "' . substr($status, 0, 20) . '"');
    $img = $g('image_url');
    $photoMissing = false;
    if ($img !== '' && !preg_match('#^(https?://|/)#i', $img)) return array(false, 'photo address must start with / or http');
    if ($img === '' && $g('image_file') !== '') {
        $f = $g('image_file');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,80}\.(jpe?g|png|webp)$/i', $f)) return array(false, 'bad photo file name "' . substr($f, 0, 40) . '"');
        // A photo is linked only when its file is really on the server, so shops never see a broken picture.
        // Import again in "update" mode after uploading the photos to attach the rest.
        if (is_file(dirname(__DIR__, 2) . '/img/menu/' . $f)) $img = '/delivery/img/menu/' . $f; else $photoMissing = true;
    }
    $prep = $g('prep_time');
    $sort = $g('sort_order');
    return array(true, array(
        'name' => substr($name, 0, 150),
        'name_am' => $g('name_am') !== '' ? substr($g('name_am'), 0, 150) : null,
        'description' => $g('description'),
        'description_am' => $g('description_am') !== '' ? $g('description_am') : null,
        'category' => $cat,
        'cuisine' => $g('cuisine') !== '' ? substr($g('cuisine'), 0, 40) : null,
        'packs' => ($pk = etel_catalog_clean_packs($g('packs'))) !== '' ? $pk : null,
        'image_url' => $img !== '' ? substr($img, 0, 500) : null,
        'prep_time' => $prep !== '' ? max(1, min(240, (int)$prep)) : null,
        'sort_order' => $sort !== '' && is_numeric($sort) ? max(0, min(100000, (int)$sort)) : null,
        'status' => $status,
        'photo_missing' => $photoMissing,
    ));
}

// Adds many dishes at once. A dish already in the catalog (same name and category) is skipped, or updated when
// $mode is 'update' (then only the columns the file filled in are changed). $dry = true does everything inside a
// transaction and rolls it back, so the admin sees the exact result before anything is saved.
// Returns array('added','updated','skipped','photos_missing','invalid'=>[{row,reason}],'dry_run').
function etel_catalog_admin_import($pdo, $rows, $mode = 'skip', $dry = false) {
    etel_ensure_catalog_schema($pdo);
    $res = array('added' => 0, 'updated' => 0, 'skipped' => 0, 'photos_missing' => 0, 'invalid' => array(), 'dry_run' => (bool)$dry);
    if (!is_array($rows)) return $res;
    $rows = array_slice(array_values($rows), 0, 500);
    $update = ($mode === 'update');
    $find = $pdo->prepare('SELECT id FROM menu_catalog WHERE name = ? AND category = ?');
    $ins = $pdo->prepare('INSERT INTO menu_catalog (name, name_am, description, description_am, category, cuisine, packs, image_url, prep_time, status, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $seen = array();
        foreach ($rows as $i => $raw) {
            $line = (is_array($raw) && isset($raw['_line'])) ? (int)$raw['_line'] : $i + 2;
            $c = etel_catalog_clean_admin_row($raw);
            if (!$c[0]) { $res['invalid'][] = array('row' => $line, 'reason' => $c[1]); continue; }
            $d = $c[1];
            if ($d['photo_missing']) $res['photos_missing']++;
            $key = $d['category'] . '|' . etel_catalog_norm($d['name']);
            if (isset($seen[$key])) { $res['skipped']++; continue; }
            $seen[$key] = true;
            $find->execute(array($d['name'], $d['category']));
            $id = $find->fetchColumn();
            if ($id) {
                if (!$update) { $res['skipped']++; continue; }
                $set = array(); $vals = array();
                foreach (array('name_am', 'description', 'description_am', 'cuisine', 'packs', 'image_url', 'prep_time', 'sort_order', 'status') as $col) {
                    if ($d[$col] === null || $d[$col] === '') continue;
                    $set[] = "$col = ?"; $vals[] = $d[$col];
                }
                if ($set) { $vals[] = (int)$id; $pdo->prepare('UPDATE menu_catalog SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals); }
                $res['updated']++;
            } else {
                $ins->execute(array($d['name'], $d['name_am'], $d['description'], $d['description_am'], $d['category'], $d['cuisine'], $d['packs'],
                                    $d['image_url'], $d['prep_time'] !== null ? $d['prep_time'] : 20, $d['status'], $d['sort_order'] !== null ? $d['sort_order'] : 100));
                $res['added']++;
            }
        }
        if ($own) { if ($dry) $pdo->rollBack(); else $pdo->commit(); }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $res;
}
