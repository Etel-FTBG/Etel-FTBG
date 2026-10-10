<?php
class Customers
{
    public static function listAll($ctx, $q = '')
    {
        $sql = 'SELECT id, name, phone, email, tin, address, notes FROM customers WHERE business_id = ? AND archived = 0';
        $p = array($ctx['business_id']);
        $q = trim((string) $q);
        if ($q !== '') {
            $sql .= ' AND (name LIKE ? OR phone LIKE ?)';
            $like = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), $q) . '%';
            $p[] = $like;
            $p[] = $like;
        }
        $rows = Db::all($sql . ' ORDER BY name LIMIT 200', $p);
        foreach ($rows as &$r) {
            $r['owing'] = Money::fromCents((int) round((float) Db::val(
                "SELECT COALESCE(SUM(total - paid_amount), 0) FROM invoices WHERE business_id = ? AND customer_id = ? AND status = 'issued'",
                array($ctx['business_id'], $r['id'])) * 100));
        }
        return $rows;
    }

    public static function save($ctx, $b)
    {
        $name = trim((string) (isset($b['name']) ? $b['name'] : ''));
        if ($name === '' || strlen($name) > 160) throw new ApiError(422, 'Customer name is required.');
        $f = array($name, self::s($b, 'phone', 40), self::s($b, 'email', 160), self::s($b, 'tin', 40), self::s($b, 'address', 255), self::s($b, 'notes', 500));
        $id = (int) (isset($b['id']) ? $b['id'] : 0);
        if ($id) {
            if (!Db::one('SELECT id FROM customers WHERE id = ? AND business_id = ?', array($id, $ctx['business_id']))) throw new ApiError(404, 'Customer not found.');
            Db::run('UPDATE customers SET name=?, phone=?, email=?, tin=?, address=?, notes=? WHERE id = ? AND business_id = ?', array_merge($f, array($id, $ctx['business_id'])));
        } else {
            $id = Db::insert('INSERT INTO customers (name, phone, email, tin, address, notes, business_id, created_at) VALUES (?,?,?,?,?,?,?,?)', array_merge($f, array($ctx['business_id'], Db::now())));
        }
        return $id;
    }

    public static function archive($ctx, $id)
    {
        if (!Db::run('UPDATE customers SET archived = 1 WHERE id = ? AND business_id = ?', array((int) $id, $ctx['business_id']))) throw new ApiError(404, 'Customer not found.');
    }

    private static function s($b, $k, $max)
    {
        return substr(trim((string) (isset($b[$k]) ? $b[$k] : '')), 0, $max);
    }
}
