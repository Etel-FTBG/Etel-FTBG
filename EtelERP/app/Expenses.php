<?php
class Expenses
{
    public static function listAll($ctx, $from, $to)
    {
        return Db::all(
            'SELECT id, category, description, vendor, amount, method, spent_at FROM expenses
             WHERE business_id = ? AND voided = 0 AND spent_at BETWEEN ? AND ? ORDER BY spent_at DESC, id DESC LIMIT 500',
            array($ctx['business_id'], $from, $to)
        );
    }

    public static function add($ctx, $b)
    {
        $amount = Money::toCents(isset($b['amount']) ? $b['amount'] : '', 'amount');
        if ($amount <= 0) throw new ApiError(422, 'Amount must be more than zero.');
        $date = isset($b['spent_at']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $b['spent_at']) ? $b['spent_at'] : date('Y-m-d');
        $method = isset($b['method']) && in_array($b['method'], Payments::METHODS, true) ? $b['method'] : 'cash';
        $id = Db::insert(
            'INSERT INTO expenses (business_id, category, description, vendor, amount, method, spent_at, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
            array($ctx['business_id'], substr(trim((string) (isset($b['category']) ? $b['category'] : 'general')), 0, 60) ?: 'general',
                  substr(trim((string) (isset($b['description']) ? $b['description'] : '')), 0, 255),
                  substr(trim((string) (isset($b['vendor']) ? $b['vendor'] : '')), 0, 160),
                  Money::fromCents($amount), $method, $date, $ctx['member_id'], Db::now())
        );
        Audit::log($ctx, 'expense.add', 'expense', $id, Money::fromCents($amount));
        return $id;
    }

    public static function void($ctx, $id)
    {
        if (!Db::run('UPDATE expenses SET voided = 1 WHERE id = ? AND business_id = ? AND voided = 0', array((int) $id, $ctx['business_id']))) throw new ApiError(404, 'Expense not found.');
        Audit::log($ctx, 'expense.void', 'expense', (int) $id);
    }
}
