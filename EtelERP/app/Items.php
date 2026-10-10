<?php
class Items
{
    public static function listAll($ctx, $q = '')
    {
        $sql = 'SELECT id, sku, name, unit, price, cost, track_stock, stock_qty, reorder_level, vat_exempt FROM items WHERE business_id = ? AND active = 1';
        $p = array($ctx['business_id']);
        $q = trim((string) $q);
        if ($q !== '') {
            $sql .= ' AND (name LIKE ? OR sku LIKE ?)';
            $like = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), $q) . '%';
            $p[] = $like;
            $p[] = $like;
        }
        return Db::all($sql . ' ORDER BY name LIMIT 500', $p);
    }

    public static function save($ctx, $b)
    {
        $name = trim((string) (isset($b['name']) ? $b['name'] : ''));
        if ($name === '' || strlen($name) > 160) throw new ApiError(422, 'Item name is required.');
        $price = Money::toCents(isset($b['price']) ? $b['price'] : '0', 'price');
        $cost = Money::toCents(isset($b['cost']) ? $b['cost'] : '0', 'cost');
        if ($price < 0 || $cost < 0) throw new ApiError(422, 'Price and cost cannot be negative.');
        $reorder = Money::toMilli(isset($b['reorder_level']) ? $b['reorder_level'] : '0', 'reorder level');
        if ($reorder < 0) throw new ApiError(422, 'Reorder level cannot be negative.');
        $track = !empty($b['track_stock']) ? 1 : 0;
        $exempt = !empty($b['vat_exempt']) ? 1 : 0;
        $sku = substr(trim((string) (isset($b['sku']) ? $b['sku'] : '')), 0, 60);
        $unit = substr(trim((string) (isset($b['unit']) ? $b['unit'] : 'pcs')), 0, 20) ?: 'pcs';
        $id = (int) (isset($b['id']) ? $b['id'] : 0);
        if ($id) {
            if (!Db::one('SELECT id FROM items WHERE id = ? AND business_id = ?', array($id, $ctx['business_id']))) throw new ApiError(404, 'Item not found.');
            // stock_qty is never edited here: it only moves through invoices and stock adjustments
            Db::run('UPDATE items SET sku=?, name=?, unit=?, price=?, cost=?, track_stock=?, reorder_level=?, vat_exempt=? WHERE id = ? AND business_id = ?',
                array($sku, $name, $unit, Money::fromCents($price), Money::fromCents($cost), $track, Money::fromMilli($reorder), $exempt, $id, $ctx['business_id']));
        } else {
            $biz = Db::one('SELECT * FROM businesses WHERE id = ?', array($ctx['business_id']));
            Plans::check($biz, 'items', (int) Db::val('SELECT COUNT(*) FROM items WHERE business_id = ? AND active = 1', array($ctx['business_id'])), 'items');
            $id = Db::insert('INSERT INTO items (business_id, sku, name, unit, price, cost, track_stock, reorder_level, vat_exempt, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                array($ctx['business_id'], $sku, $name, $unit, Money::fromCents($price), Money::fromCents($cost), $track, Money::fromMilli($reorder), $exempt, Db::now()));
        }
        Audit::log($ctx, 'item.save', 'item', $id, $name);
        return $id;
    }

    public static function archive($ctx, $id)
    {
        if (!Db::run('UPDATE items SET active = 0 WHERE id = ? AND business_id = ?', array((int) $id, $ctx['business_id']))) throw new ApiError(404, 'Item not found.');
        Audit::log($ctx, 'item.archive', 'item', (int) $id);
    }

    /** Add or remove stock by hand (a purchase, a count correction). qty_change is signed. */
    public static function adjust($ctx, $b)
    {
        $id = (int) (isset($b['item_id']) ? $b['item_id'] : 0);
        $change = Money::toMilli(isset($b['qty_change']) ? $b['qty_change'] : '', 'quantity');
        if ($change === 0) throw new ApiError(422, 'Enter how much to add or remove.');
        $reason = isset($b['reason']) && $b['reason'] === 'purchase' ? 'purchase' : 'adjust';
        $note = substr(trim((string) (isset($b['note']) ? $b['note'] : '')), 0, 255);
        Db::tx(function () use ($ctx, $id, $change, $reason, $note) {
            $it = Db::one('SELECT id, name, track_stock, stock_qty FROM items WHERE id = ? AND business_id = ? FOR UPDATE', array($id, $ctx['business_id']));
            if (!$it) throw new ApiError(404, 'Item not found.');
            if (!$it['track_stock']) throw new ApiError(422, 'This item does not track stock.');
            $biz = Db::one('SELECT allow_negative_stock FROM businesses WHERE id = ?', array($ctx['business_id']));
            $new = Money::toMilli($it['stock_qty']) + $change;
            if ($new < 0 && !$biz['allow_negative_stock']) throw new ApiError(422, 'Stock cannot go below zero.');
            Db::run('UPDATE items SET stock_qty = ? WHERE id = ?', array(Money::fromMilli($new), $id));
            Db::run('INSERT INTO stock_moves (business_id, item_id, qty_change, reason, ref_type, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)',
                array($ctx['business_id'], $id, Money::fromMilli($change), $reason, 'manual', $note, $ctx['member_id'], Db::now()));
        });
        Audit::log($ctx, 'stock.adjust', 'item', $id, Money::fromMilli($change));
    }
}
