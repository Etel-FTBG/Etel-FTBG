<?php
/* "Etel Business": the shop's own books (walk-in sales, stock movements, expenses, daily cash-up), usable with no internet.
   The phone keeps an ordered log of events and sends them in batches; this file applies them.

   Rules that keep the books honest:
   - An event is applied once. (service_giver_id, client_req_id) is unique, so a resend answers "dup" and changes nothing.
   - Stock is a ledger (sg_stock_events). A device never sets a stock number, it only adds movements, so two phones selling
     offline add up. items.stock_quantity is a cache of the ledger; NULL means "not tracked" and is left alone. The cache never
     goes below 0 (a sold-out item stops showing to customers) while the ledger keeps the true figure, so overselling is visible.
   - Walk-in sales never touch `orders`: no courier, no customer, no platform commission.
   - Voiding a sale is a new event that reverses its stock; nothing is deleted.
   - Credit sales are records only. Etel does not collect or guarantee them.
   - Times come from the phone but are checked (not future, at most 7 days old); older events are refused with a clear message.
   Every function takes the shop id from the signed-in account, never from the request. */

require_once __DIR__ . '/offline_sync.php';

const ETEL_SGB_MAX_EVENTS = 200;
const ETEL_SGB_MAX_AGE = 604800;   // 7 days

function etel_sgb_ensure_schema($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (!$pdo->query("SHOW COLUMNS FROM service_givers LIKE 'business_tools'")->fetch()) {
            $pdo->exec("ALTER TABLE service_givers ADD COLUMN business_tools TINYINT(1) NOT NULL DEFAULT 0");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS sg_pos_sales (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            service_giver_id INT NOT NULL, client_req_id VARCHAR(64) NOT NULL, sold_by_user_id INT NOT NULL,
            occurred_at DATETIME NOT NULL, subtotal DECIMAL(12,2) NOT NULL, discount DECIMAL(12,2) NOT NULL DEFAULT 0,
            total DECIMAL(12,2) NOT NULL, payment_method ENUM('cash','mobile','credit') NOT NULL DEFAULT 'cash',
            customer_name VARCHAR(80) NULL, customer_phone VARCHAR(30) NULL,
            status ENUM('paid','credit','void') NOT NULL DEFAULT 'paid', voided_at DATETIME NULL, voided_by_user_id INT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_sale_req (service_giver_id, client_req_id), KEY ix_sale_day (service_giver_id, occurred_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS sg_pos_sale_items (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, sale_id INT UNSIGNED NOT NULL, item_id INT NULL,
            name_snapshot VARCHAR(120) NOT NULL, unit_price DECIMAL(12,2) NOT NULL, qty INT NOT NULL, KEY ix_sale (sale_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS sg_stock_events (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, service_giver_id INT NOT NULL, item_id INT NOT NULL,
            delta INT NOT NULL, reason ENUM('sale','online_order','delivery','waste','count','void') NOT NULL,
            ref_id INT UNSIGNED NULL, client_req_id VARCHAR(64) NULL, user_id INT NULL, occurred_at DATETIME NOT NULL,
            KEY ix_stock_item (service_giver_id, item_id), KEY ix_stock_req (service_giver_id, client_req_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS sg_expenses (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, service_giver_id INT NOT NULL, client_req_id VARCHAR(64) NOT NULL,
            category VARCHAR(30) NOT NULL, amount DECIMAL(12,2) NOT NULL, paid_with ENUM('cash','mobile') NOT NULL DEFAULT 'cash',
            note VARCHAR(200) NULL, user_id INT NULL, occurred_at DATETIME NOT NULL,
            UNIQUE KEY uq_exp_req (service_giver_id, client_req_id), KEY ix_exp_day (service_giver_id, occurred_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS sg_cash_days (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, service_giver_id INT NOT NULL, business_date DATE NOT NULL,
            opening_float DECIMAL(12,2) NOT NULL DEFAULT 0, expected_cash DECIMAL(12,2) NOT NULL DEFAULT 0,
            counted_cash DECIMAL(12,2) NOT NULL DEFAULT 0, difference DECIMAL(12,2) NOT NULL DEFAULT 0,
            closed_by_user_id INT NULL, closed_at DATETIME NOT NULL, client_req_id VARCHAR(64) NULL,
            UNIQUE KEY uq_cash_day (service_giver_id, business_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { error_log('[ETEL] sg business schema: ' . $e->getMessage()); }
}

function etel_sgb_enabled($pdo, $sgId) {
    etel_sgb_ensure_schema($pdo);
    try {
        $s = $pdo->prepare('SELECT business_tools FROM service_givers WHERE id=?');
        $s->execute(array($sgId));
        return intval($s->fetchColumn()) === 1;
    } catch (Exception $e) { return false; }
}

function etel_sgb_money($v, $allowZero = false) {
    $r = etel_validate_amount($v, $allowZero);
    return $r === false ? false : $r;
}

/* What the shop's phone needs to work offline: its items with stock, and today's totals. */
function etel_sgb_state($pdo, $sgId) {
    etel_sgb_ensure_schema($pdo);
    require_once __DIR__ . '/shop_hours.php';
    etel_hours_ensure_columns($pdo);
    $items = array();
    try {
        $s = $pdo->prepare("SELECT id, name, category, price, stock_quantity, is_available,
                                   (sold_out_date IS NOT NULL AND sold_out_date = CURDATE()) AS sold_out
                            FROM items WHERE service_giver_id=? ORDER BY name ASC LIMIT 1000");
        $s->execute(array($sgId));
        $items = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { error_log('[ETEL] sg business state items: ' . $e->getMessage()); }
    $today = array('sales' => 0, 'sales_total' => 0.0, 'cash_sales' => 0.0, 'expenses' => 0.0);
    try {
        $s = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(total),0) t, COALESCE(SUM(CASE WHEN payment_method='cash' THEN total ELSE 0 END),0) c
                            FROM sg_pos_sales WHERE service_giver_id=? AND status<>'void' AND DATE(occurred_at)=CURDATE()");
        $s->execute(array($sgId));
        $r = $s->fetch(PDO::FETCH_ASSOC);
        $today['sales'] = intval($r['n']); $today['sales_total'] = round(floatval($r['t']), 2); $today['cash_sales'] = round(floatval($r['c']), 2);
        $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM sg_expenses WHERE service_giver_id=? AND DATE(occurred_at)=CURDATE()");
        $s->execute(array($sgId));
        $today['expenses'] = round(floatval($s->fetchColumn()), 2);
    } catch (Exception $e) { error_log('[ETEL] sg business state totals: ' . $e->getMessage()); }
    return array('items' => $items, 'today' => $today, 'server_now' => gmdate('c'));
}

/* Applies one event. Returns array('status' => 'ok'|'dup'|'error', 'message' => string). Never throws. */
function etel_sgb_apply_event($pdo, $ctx, $userId, $ev) {
    $sg = intval($ctx['sg_id']);
    if (!is_array($ev) || empty($ev['type']) || !isset($ev['data']) || !is_array($ev['data'])) return array('status' => 'error', 'message' => 'Malformed event');
    $reqId = isset($ev['req_id']) && is_string($ev['req_id']) ? trim($ev['req_id']) : '';
    if ($reqId === '' || strlen($reqId) > 64 || !preg_match('/^[A-Za-z0-9_\-]+$/', $reqId)) return array('status' => 'error', 'message' => 'Missing or invalid request id');
    $when = etel_offline_when(isset($ev['occurred_at']) ? $ev['occurred_at'] : '', null, ETEL_SGB_MAX_AGE);
    if (!$when) return array('status' => 'error', 'message' => 'The time of this event is missing, in the future, or older than 7 days. Ask support to enter it.');
    $d = $ev['data'];
    try {
        $pdo->beginTransaction();
        switch ($ev['type']) {
            case 'sale':    $r = etel_sgb_sale($pdo, $ctx, $sg, $userId, $reqId, $when, $d); break;
            case 'void':    $r = etel_sgb_void($pdo, $ctx, $sg, $userId, $reqId, $when, $d); break;
            case 'stock':   $r = etel_sgb_stock($pdo, $ctx, $sg, $userId, $reqId, $when, $d); break;
            case 'expense': $r = etel_sgb_expense($pdo, $ctx, $sg, $userId, $reqId, $when, $d); break;
            case 'cashup':  $r = etel_sgb_cashup($pdo, $ctx, $sg, $userId, $reqId, $when, $d); break;
            default:        $r = array('status' => 'error', 'message' => 'Unknown event type');
        }
        if ($r['status'] === 'ok') $pdo->commit(); else $pdo->rollBack();
        return $r;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[ETEL] sg business event ' . $ev['type'] . ': ' . $e->getMessage());
        return array('status' => 'error', 'message' => 'Could not save this event');
    }
}

function etel_sgb_cap($ctx, $cap) { return function_exists('sg_has_capability') && sg_has_capability($ctx, $cap); }
function etel_sgb_denied($cap) { return array('status' => 'error', 'message' => "This account doesn't have permission to do this (requires: $cap)"); }

/* adjusts the ledger and the items cache; $delta may be negative. Item must belong to the shop. */
function etel_sgb_move_stock($pdo, $sg, $itemId, $delta, $reason, $refId, $reqId, $userId, $when) {
    $it = $pdo->prepare('SELECT stock_quantity FROM items WHERE id=? AND service_giver_id=? FOR UPDATE');
    $it->execute(array($itemId, $sg));
    $row = $it->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    $pdo->prepare('INSERT INTO sg_stock_events (service_giver_id, item_id, delta, reason, ref_id, client_req_id, user_id, occurred_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute(array($sg, $itemId, $delta, $reason, $refId, $reqId, $userId, $when));
    if ($row['stock_quantity'] !== null) {
        $pdo->prepare('UPDATE items SET stock_quantity = GREATEST(0, stock_quantity + ?) WHERE id=? AND service_giver_id=?')->execute(array($delta, $itemId, $sg));
    }
    return true;
}

function etel_sgb_dup($pdo, $table, $sg, $reqId) {
    $s = $pdo->prepare("SELECT id FROM $table WHERE service_giver_id=? AND client_req_id=?");
    $s->execute(array($sg, $reqId));
    return $s->fetchColumn() !== false;
}

function etel_sgb_sale($pdo, $ctx, $sg, $userId, $reqId, $when, $d) {
    if (!etel_sgb_cap($ctx, 'pos.sell')) return etel_sgb_denied('pos.sell');
    if (etel_sgb_dup($pdo, 'sg_pos_sales', $sg, $reqId)) return array('status' => 'dup', 'message' => 'Already saved');
    $lines = isset($d['items']) && is_array($d['items']) ? $d['items'] : array();
    if (!$lines || count($lines) > 100) return array('status' => 'error', 'message' => 'A sale needs between 1 and 100 lines');
    $subtotal = 0.0; $clean = array();
    foreach ($lines as $l) {
        $qty = isset($l['qty']) ? intval($l['qty']) : 0;
        $price = etel_sgb_money(isset($l['unit_price']) ? $l['unit_price'] : null, true);
        $name = isset($l['name']) ? trim(strip_tags((string) $l['name'])) : '';
        if ($qty < 1 || $qty > 10000 || $price === false || $name === '') return array('status' => 'error', 'message' => 'A sale line is not valid');
        $itemId = !empty($l['item_id']) ? intval($l['item_id']) : null;
        $clean[] = array('item_id' => $itemId, 'name' => mb_substr($name, 0, 120), 'price' => $price, 'qty' => $qty);
        $subtotal += $price * $qty;
    }
    $subtotal = round($subtotal, 2);
    $discount = isset($d['discount']) && $d['discount'] !== '' ? etel_sgb_money($d['discount'], true) : 0.0;
    if ($discount === false || $discount > $subtotal) return array('status' => 'error', 'message' => 'The discount is not valid');
    if ($discount > 0 && $discount > $subtotal * 0.2 && !etel_sgb_cap($ctx, 'pos.discount')) return etel_sgb_denied('pos.discount (discounts over 20%)');
    $total = round($subtotal - $discount, 2);
    $pm = isset($d['payment_method']) ? $d['payment_method'] : 'cash';
    if (!in_array($pm, array('cash', 'mobile', 'credit'), true)) return array('status' => 'error', 'message' => 'Unknown payment method');
    $cn = isset($d['customer_name']) ? mb_substr(trim(strip_tags((string) $d['customer_name'])), 0, 80) : null;
    $cp = isset($d['customer_phone']) ? mb_substr(preg_replace('/[^0-9+]/', '', (string) $d['customer_phone']), 0, 30) : null;
    if ($pm === 'credit' && !$cn) return array('status' => 'error', 'message' => 'A credit sale needs the customer name');
    $pdo->prepare('INSERT INTO sg_pos_sales (service_giver_id, client_req_id, sold_by_user_id, occurred_at, subtotal, discount, total, payment_method, customer_name, customer_phone, status, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())')
        ->execute(array($sg, $reqId, $userId, $when, $subtotal, $discount, $total, $pm, $cn ?: null, $cp ?: null, $pm === 'credit' ? 'credit' : 'paid'));
    $saleId = intval($pdo->lastInsertId());
    $ins = $pdo->prepare('INSERT INTO sg_pos_sale_items (sale_id, item_id, name_snapshot, unit_price, qty) VALUES (?,?,?,?,?)');
    foreach ($clean as $c) {
        $itemId = $c['item_id'];
        if ($itemId) {
            // an item that is not this shop's is kept as a plain named line, never as someone else's stock
            $chk = $pdo->prepare('SELECT id FROM items WHERE id=? AND service_giver_id=?');
            $chk->execute(array($itemId, $sg));
            if (!$chk->fetchColumn()) $itemId = null;
        }
        $ins->execute(array($saleId, $itemId, $c['name'], $c['price'], $c['qty']));
        if ($itemId) etel_sgb_move_stock($pdo, $sg, $itemId, -$c['qty'], 'sale', $saleId, $reqId, $userId, $when);
    }
    return array('status' => 'ok', 'message' => 'Sale saved', 'id' => $saleId, 'total' => $total);
}

function etel_sgb_void($pdo, $ctx, $sg, $userId, $reqId, $when, $d) {
    if (!etel_sgb_cap($ctx, 'pos.void')) return etel_sgb_denied('pos.void');
    $target = isset($d['sale_req_id']) ? (string) $d['sale_req_id'] : '';
    $s = $pdo->prepare('SELECT id, status FROM sg_pos_sales WHERE service_giver_id=? AND client_req_id=? FOR UPDATE');
    $s->execute(array($sg, $target));
    $sale = $s->fetch(PDO::FETCH_ASSOC);
    if (!$sale) return array('status' => 'error', 'message' => 'That sale was not found (it may not have synced yet)');
    if ($sale['status'] === 'void') return array('status' => 'dup', 'message' => 'Already voided');
    $pdo->prepare("UPDATE sg_pos_sales SET status='void', voided_at=?, voided_by_user_id=? WHERE id=?")->execute(array($when, $userId, $sale['id']));
    $li = $pdo->prepare('SELECT item_id, qty FROM sg_pos_sale_items WHERE sale_id=? AND item_id IS NOT NULL');
    $li->execute(array($sale['id']));
    foreach ($li->fetchAll(PDO::FETCH_ASSOC) as $l) etel_sgb_move_stock($pdo, $sg, intval($l['item_id']), intval($l['qty']), 'void', intval($sale['id']), $reqId, $userId, $when);
    return array('status' => 'ok', 'message' => 'Sale voided');
}

function etel_sgb_stock($pdo, $ctx, $sg, $userId, $reqId, $when, $d) {
    if (!etel_sgb_cap($ctx, 'stock.manage')) return etel_sgb_denied('stock.manage');
    $itemId = isset($d['item_id']) ? intval($d['item_id']) : 0;
    $kind = isset($d['kind']) ? $d['kind'] : '';
    $qty = isset($d['qty']) ? intval($d['qty']) : -1;
    if ($itemId < 1 || $qty < 0 || $qty > 1000000 || !in_array($kind, array('delivery', 'waste', 'count'), true) || ($kind !== 'count' && $qty < 1)) return array('status' => 'error', 'message' => 'The stock entry is not valid');
    $dup = $pdo->prepare('SELECT id FROM sg_stock_events WHERE service_giver_id=? AND client_req_id=? AND item_id=? AND reason=?');
    $dup->execute(array($sg, $reqId, $itemId, $kind));
    if ($dup->fetchColumn()) return array('status' => 'dup', 'message' => 'Already saved');
    if ($kind === 'count') {
        $cur = $pdo->prepare('SELECT stock_quantity FROM items WHERE id=? AND service_giver_id=? FOR UPDATE');
        $cur->execute(array($itemId, $sg));
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) return array('status' => 'error', 'message' => 'Item not found');
        // a count sets the level: the movement is whatever gets the ledger from here to the counted figure
        $delta = $qty - ($row['stock_quantity'] === null ? 0 : intval($row['stock_quantity']));
        if ($row['stock_quantity'] === null) $pdo->prepare('UPDATE items SET stock_quantity=0 WHERE id=? AND service_giver_id=?')->execute(array($itemId, $sg));
    } else {
        $delta = $kind === 'delivery' ? $qty : -$qty;
    }
    if (!etel_sgb_move_stock($pdo, $sg, $itemId, $delta, $kind, null, $reqId, $userId, $when)) return array('status' => 'error', 'message' => 'Item not found');
    return array('status' => 'ok', 'message' => 'Stock saved');
}

function etel_sgb_expense($pdo, $ctx, $sg, $userId, $reqId, $when, $d) {
    if (!etel_sgb_cap($ctx, 'expenses.manage')) return etel_sgb_denied('expenses.manage');
    if (etel_sgb_dup($pdo, 'sg_expenses', $sg, $reqId)) return array('status' => 'dup', 'message' => 'Already saved');
    $amount = etel_sgb_money(isset($d['amount']) ? $d['amount'] : null);
    $cat = isset($d['category']) ? $d['category'] : '';
    $paid = isset($d['paid_with']) ? $d['paid_with'] : 'cash';
    if ($amount === false || !in_array($cat, array('supplies', 'rent', 'electricity', 'wages', 'transport', 'other'), true) || !in_array($paid, array('cash', 'mobile'), true)) return array('status' => 'error', 'message' => 'The expense is not valid');
    $note = isset($d['note']) ? mb_substr(trim(strip_tags((string) $d['note'])), 0, 200) : null;
    $pdo->prepare('INSERT INTO sg_expenses (service_giver_id, client_req_id, category, amount, paid_with, note, user_id, occurred_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute(array($sg, $reqId, $cat, $amount, $paid, $note ?: null, $userId, $when));
    return array('status' => 'ok', 'message' => 'Expense saved');
}

function etel_sgb_cashup($pdo, $ctx, $sg, $userId, $reqId, $when, $d) {
    if (!etel_sgb_cap($ctx, 'cashup.manage')) return etel_sgb_denied('cashup.manage');
    $date = isset($d['business_date']) ? (string) $d['business_date'] : '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false || $date > date('Y-m-d', strtotime($when) + 86400)) return array('status' => 'error', 'message' => 'The day is not valid');
    $float = etel_sgb_money(isset($d['opening_float']) ? $d['opening_float'] : 0, true);
    $counted = etel_sgb_money(isset($d['counted_cash']) ? $d['counted_cash'] : null, true);
    if ($float === false || $counted === false) return array('status' => 'error', 'message' => 'The cash figures are not valid');
    $ex = $pdo->prepare('SELECT client_req_id FROM sg_cash_days WHERE service_giver_id=? AND business_date=?');
    $ex->execute(array($sg, $date));
    $existing = $ex->fetch(PDO::FETCH_ASSOC);
    if ($existing) return array('status' => $existing['client_req_id'] === $reqId ? 'dup' : 'error', 'message' => $existing['client_req_id'] === $reqId ? 'Already saved' : 'That day is already closed');
    $cs = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM sg_pos_sales WHERE service_giver_id=? AND payment_method='cash' AND status='paid' AND DATE(occurred_at)=?");
    $cs->execute(array($sg, $date));
    $ce = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM sg_expenses WHERE service_giver_id=? AND paid_with='cash' AND DATE(occurred_at)=?");
    $ce->execute(array($sg, $date));
    $expected = round($float + floatval($cs->fetchColumn()) - floatval($ce->fetchColumn()), 2);
    $pdo->prepare('INSERT INTO sg_cash_days (service_giver_id, business_date, opening_float, expected_cash, counted_cash, difference, closed_by_user_id, closed_at, client_req_id) VALUES (?,?,?,?,?,?,?,NOW(),?)')
        ->execute(array($sg, $date, $float, $expected, $counted, round($counted - $expected, 2), $userId, $reqId));
    return array('status' => 'ok', 'message' => 'Day closed', 'expected_cash' => $expected, 'difference' => round($counted - $expected, 2));
}
