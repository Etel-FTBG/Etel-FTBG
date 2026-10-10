<?php
class Invoices
{
    /* ------------------------------------------------------------ build + save a draft */

    /** Turn the request lines into priced lines the cashier is allowed to use. */
    private static function resolveLines($ctx, $rawLines)
    {
        if (!is_array($rawLines)) throw new ApiError(422, 'Add at least one line.');
        $free = Perm::can($ctx['role'], 'invoice.free_line');
        $out = array();
        foreach ($rawLines as $i => $l) {
            if (!is_array($l)) throw new ApiError(422, 'Invalid line.');
            $item = null;
            if (!empty($l['item_id'])) {
                $item = Db::one('SELECT id, name, price, vat_exempt, track_stock FROM items WHERE id = ? AND business_id = ? AND active = 1', array((int) $l['item_id'], $ctx['business_id']));
                if (!$item) throw new ApiError(422, 'Line ' . ($i + 1) . ': item not found.');
            } elseif (!$free) {
                throw new ApiError(403, 'Only a manager or owner can add a free-text line.');
            }
            $price = $item ? $item['price'] : (isset($l['unit_price']) ? $l['unit_price'] : '');
            if ($item && $free && isset($l['unit_price']) && $l['unit_price'] !== '') $price = $l['unit_price'];   // price change: manager/owner only
            $desc = isset($l['description']) && trim((string) $l['description']) !== '' && $free ? $l['description'] : ($item ? $item['name'] : (isset($l['description']) ? $l['description'] : ''));
            $out[] = array('item' => $item, 'description' => $desc, 'qty' => isset($l['qty']) ? $l['qty'] : '', 'unit_price' => $price);
        }
        return $out;
    }

    private static function date($v, $default)
    {
        if ($v === null || $v === '') return $default;
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v)) throw new ApiError(422, 'Invalid date.');
        return $v;
    }

    /** Create or replace a draft. Returns the invoice id. Safe to repeat with the same client_ref. */
    public static function save($ctx, $b)
    {
        $biz = Db::one('SELECT vat_registered, vat_rate FROM businesses WHERE id = ?', array($ctx['business_id']));
        $calc = Calc::invoice(self::resolveLines($ctx, isset($b['lines']) ? $b['lines'] : null), (bool) $biz['vat_registered'], Money::rateToBp($biz['vat_rate']));
        $customerId = !empty($b['customer_id']) ? (int) $b['customer_id'] : null;
        if ($customerId && !Db::one('SELECT id FROM customers WHERE id = ? AND business_id = ?', array($customerId, $ctx['business_id']))) throw new ApiError(422, 'Customer not found.');
        $issue = self::date(isset($b['issue_date']) ? $b['issue_date'] : null, date('Y-m-d'));
        $due = self::date(isset($b['due_date']) ? $b['due_date'] : null, null);
        $notes = substr(trim((string) (isset($b['notes']) ? $b['notes'] : '')), 0, 500);
        $clientRef = isset($b['client_ref']) && $b['client_ref'] !== '' ? substr((string) $b['client_ref'], 0, 56) : null;   // 56: room for the '-p' payment suffix
        $id = (int) (isset($b['id']) ? $b['id'] : 0);

        if ($clientRef !== null && !$id) {
            $ex = Db::one('SELECT id FROM invoices WHERE business_id = ? AND client_ref = ?', array($ctx['business_id'], $clientRef));
            if ($ex) return (int) $ex['id'];
        }
        try {
            return Db::tx(function () use ($ctx, $id, $calc, $customerId, $issue, $due, $notes, $clientRef) {
                if ($id) {
                    $inv = Db::one('SELECT id, status FROM invoices WHERE id = ? AND business_id = ? FOR UPDATE', array($id, $ctx['business_id']));
                    if (!$inv) throw new ApiError(404, 'Invoice not found.');
                    if ($inv['status'] !== 'draft') throw new ApiError(409, 'Only a draft can be edited. Void it and make a new one.');
                    Db::run('UPDATE invoices SET customer_id=?, issue_date=?, due_date=?, subtotal=?, vat_amount=?, total=?, notes=? WHERE id = ?',
                        array($customerId, $issue, $due, Money::fromCents($calc['subtotal']), Money::fromCents($calc['vat']), Money::fromCents($calc['total']), $notes, $id));
                    Db::run('DELETE FROM invoice_lines WHERE invoice_id = ?', array($id));
                } else {
                    $id = Db::insert(
                        'INSERT INTO invoices (business_id, customer_id, status, issue_date, due_date, subtotal, vat_amount, total, notes, client_ref, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                        array($ctx['business_id'], $customerId, 'draft', $issue, $due, Money::fromCents($calc['subtotal']), Money::fromCents($calc['vat']), Money::fromCents($calc['total']), $notes, $clientRef, $ctx['member_id'], Db::now())
                    );
                }
                foreach ($calc['lines'] as $l) {
                    Db::run(
                        'INSERT INTO invoice_lines (invoice_id, business_id, item_id, description, qty, unit_price, vat_rate, line_subtotal, line_vat, line_total) VALUES (?,?,?,?,?,?,?,?,?,?)',
                        array($id, $ctx['business_id'], $l['item_id'], $l['description'], Money::fromMilli($l['qty_milli']), Money::fromCents($l['price_cents']),
                              Money::fromCents($l['rate_bp']), Money::fromCents($l['subtotal_cents']), Money::fromCents($l['vat_cents']), Money::fromCents($l['total_cents']))
                    );
                }
                return $id;
            });
        } catch (PDOException $e) {
            if ($clientRef !== null && $e->getCode() === '23000') {
                $ex = Db::one('SELECT id FROM invoices WHERE business_id = ? AND client_ref = ?', array($ctx['business_id'], $clientRef));
                if ($ex) return (int) $ex['id'];
            }
            throw $e;
        }
    }

    /* ------------------------------------------------------------ issue / void */

    /** Give a draft its number, take the stock out, and lock it. */
    public static function issue($ctx, $id)
    {
        Db::tx(function () use ($ctx, $id) {
            $inv = Db::one('SELECT id, status FROM invoices WHERE id = ? AND business_id = ? FOR UPDATE', array((int) $id, $ctx['business_id']));
            if (!$inv) throw new ApiError(404, 'Invoice not found.');
            if ($inv['status'] === 'issued') return;                       // already issued: nothing to do
            if ($inv['status'] !== 'draft') throw new ApiError(409, 'This invoice cannot be issued.');
            $biz = Db::one('SELECT * FROM businesses WHERE id = ? FOR UPDATE', array($ctx['business_id']));
            $monthStart = date('Y-m-01 00:00:00');
            Plans::check($biz, 'invoices_month', (int) Db::val("SELECT COUNT(*) FROM invoices WHERE business_id = ? AND status <> 'draft' AND issued_at >= ?", array($ctx['business_id'], $monthStart)), 'invoices a month');

            // stock: lock each tracked item once, lowest id first (stops two sales deadlocking)
            $need = array();
            foreach (Db::all('SELECT item_id, qty FROM invoice_lines WHERE invoice_id = ? AND item_id IS NOT NULL', array($inv['id'])) as $l) {
                $k = (int) $l['item_id'];
                $need[$k] = (isset($need[$k]) ? $need[$k] : 0) + Money::toMilli($l['qty']);
            }
            ksort($need);
            foreach ($need as $itemId => $qty) {
                $it = Db::one('SELECT id, name, track_stock, stock_qty FROM items WHERE id = ? AND business_id = ? FOR UPDATE', array($itemId, $ctx['business_id']));
                if (!$it || !$it['track_stock']) continue;
                $new = Money::toMilli($it['stock_qty']) - $qty;
                if ($new < 0 && !$biz['allow_negative_stock']) {
                    throw new ApiError(409, 'Not enough stock of ' . $it['name'] . ' (' . rtrim(rtrim($it['stock_qty'], '0'), '.') . ' left).');
                }
                Db::run('UPDATE items SET stock_qty = ? WHERE id = ?', array(Money::fromMilli($new), $itemId));
                Db::run('INSERT INTO stock_moves (business_id, item_id, qty_change, reason, ref_type, ref_id, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)',
                    array($ctx['business_id'], $itemId, Money::fromMilli(-$qty), 'sale', 'invoice', $inv['id'], $ctx['member_id'], Db::now()));
            }
            $number = $biz['invoice_prefix'] . '-' . str_pad((string) $biz['next_invoice_no'], 6, '0', STR_PAD_LEFT);
            Db::run('UPDATE businesses SET next_invoice_no = next_invoice_no + 1 WHERE id = ?', array($ctx['business_id']));
            Db::run("UPDATE invoices SET status = 'issued', number = ?, issued_at = ? WHERE id = ?", array($number, Db::now(), $inv['id']));
            Audit::log($ctx, 'invoice.issue', 'invoice', (int) $inv['id'], $number);
        });
    }

    /** Cancel an issued invoice that has no payments; the stock goes back. */
    public static function void($ctx, $id, $reason)
    {
        $reason = trim((string) $reason);
        if ($reason === '') throw new ApiError(422, 'Give a reason for cancelling the invoice.');
        Db::tx(function () use ($ctx, $id, $reason) {
            $inv = Db::one('SELECT id, status, number FROM invoices WHERE id = ? AND business_id = ? FOR UPDATE', array((int) $id, $ctx['business_id']));
            if (!$inv) throw new ApiError(404, 'Invoice not found.');
            if ($inv['status'] === 'void') return;
            if ($inv['status'] === 'draft') {
                Db::run('DELETE FROM invoice_lines WHERE invoice_id = ?', array($inv['id']));
                Db::run('DELETE FROM invoices WHERE id = ?', array($inv['id']));
                Audit::log($ctx, 'invoice.discard', 'invoice', (int) $inv['id']);
                return;
            }
            $active = (int) Db::val('SELECT COUNT(*) FROM payments WHERE invoice_id = ? AND voided = 0', array($inv['id']));
            if ($active > 0) throw new ApiError(409, 'This invoice has payments. Cancel the payments first.');
            foreach (Db::all("SELECT item_id, SUM(-qty_change) AS qty FROM stock_moves WHERE business_id = ? AND ref_type = 'invoice' AND ref_id = ? AND reason = 'sale' GROUP BY item_id ORDER BY item_id", array($ctx['business_id'], $inv['id'])) as $m) {
                $it = Db::one('SELECT id, stock_qty FROM items WHERE id = ? AND business_id = ? FOR UPDATE', array((int) $m['item_id'], $ctx['business_id']));
                if (!$it) continue;
                $q = Money::toMilli($m['qty']);
                Db::run('UPDATE items SET stock_qty = ? WHERE id = ?', array(Money::fromMilli(Money::toMilli($it['stock_qty']) + $q), $it['id']));
                Db::run('INSERT INTO stock_moves (business_id, item_id, qty_change, reason, ref_type, ref_id, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)',
                    array($ctx['business_id'], $it['id'], Money::fromMilli($q), 'void', 'invoice', $inv['id'], $ctx['member_id'], Db::now()));
            }
            Db::run("UPDATE invoices SET status = 'void', voided_at = ?, void_reason = ? WHERE id = ?", array(Db::now(), substr($reason, 0, 255), $inv['id']));
            Audit::log($ctx, 'invoice.void', 'invoice', (int) $inv['id'], $inv['number'] . ': ' . $reason);
        });
    }

    /* ------------------------------------------------------------ one-step sale */

    /**
     * Draft + issue + optional payment in one transaction (the "Sell" screen).
     * Sending the same client_ref again returns the first result and does nothing twice.
     */
    public static function sell($ctx, $b)
    {
        $clientRef = isset($b['client_ref']) && $b['client_ref'] !== '' ? substr((string) $b['client_ref'], 0, 56) : null;
        if ($clientRef !== null) {
            $ex = Db::one("SELECT id FROM invoices WHERE business_id = ? AND client_ref = ? AND status <> 'draft'", array($ctx['business_id'], $clientRef));
            if ($ex) return (int) $ex['id'];
        }
        $pay = isset($b['pay']) && is_array($b['pay']) ? $b['pay'] : null;
        return Db::tx(function () use ($ctx, $b, $pay, $clientRef) {
            $id = self::save($ctx, $b);
            self::issue($ctx, $id);
            if ($pay) {
                $inv = Db::one('SELECT total FROM invoices WHERE id = ?', array($id));
                $amount = isset($pay['amount']) && $pay['amount'] !== '' ? $pay['amount'] : $inv['total'];
                Payments::add($ctx, array('invoice_id' => $id, 'amount' => $amount, 'method' => isset($pay['method']) ? $pay['method'] : 'cash',
                    'reference' => isset($pay['reference']) ? $pay['reference'] : '', 'client_ref' => $clientRef !== null ? $clientRef . '-p' : null));
            }
            return $id;
        });
    }

    /* ------------------------------------------------------------ read */

    public static function get($ctx, $id)
    {
        $inv = Db::one(
            'SELECT i.*, c.name AS customer_name, c.phone AS customer_phone, c.tin AS customer_tin FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id
             WHERE i.id = ? AND i.business_id = ?', array((int) $id, $ctx['business_id']));
        if (!$inv) throw new ApiError(404, 'Invoice not found.');
        $inv['lines'] = Db::all('SELECT item_id, description, qty, unit_price, vat_rate, line_subtotal, line_vat, line_total FROM invoice_lines WHERE invoice_id = ? ORDER BY id', array($inv['id']));
        $inv['payments'] = Db::all('SELECT id, amount, method, reference, paid_at, voided FROM payments WHERE invoice_id = ? ORDER BY id', array($inv['id']));
        $inv['balance'] = Money::fromCents(Money::toCents($inv['total']) - Money::toCents($inv['paid_amount']));
        $inv['payment_status'] = $inv['status'] === 'issued' ? Calc::paymentStatus(Money::toCents($inv['total']), Money::toCents($inv['paid_amount'])) : null;
        unset($inv['client_ref']);
        return $inv;
    }

    public static function listAll($ctx, $f)
    {
        $sql = 'SELECT i.id, i.number, i.status, i.issue_date, i.due_date, i.total, i.paid_amount, c.name AS customer_name
                FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id WHERE i.business_id = ?';
        $p = array($ctx['business_id']);
        if (!empty($f['status']) && in_array($f['status'], array('draft', 'issued', 'void'), true)) { $sql .= ' AND i.status = ?'; $p[] = $f['status']; }
        if (!empty($f['unpaid'])) $sql .= " AND i.status = 'issued' AND i.paid_amount < i.total";
        if (!empty($f['customer_id'])) { $sql .= ' AND i.customer_id = ?'; $p[] = (int) $f['customer_id']; }
        if (!empty($f['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) { $sql .= ' AND i.issue_date >= ?'; $p[] = $f['from']; }
        if (!empty($f['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) { $sql .= ' AND i.issue_date <= ?'; $p[] = $f['to']; }
        if (!empty($f['q'])) { $sql .= ' AND (i.number LIKE ? OR c.name LIKE ?)'; $like = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), trim($f['q'])) . '%'; $p[] = $like; $p[] = $like; }
        $rows = Db::all($sql . ' ORDER BY i.id DESC LIMIT 200', $p);
        foreach ($rows as &$r) {
            $r['payment_status'] = $r['status'] === 'issued' ? Calc::paymentStatus(Money::toCents($r['total']), Money::toCents($r['paid_amount'])) : null;
        }
        return $rows;
    }
}
