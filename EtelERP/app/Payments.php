<?php
class Payments
{
    const METHODS = array('cash', 'telebirr', 'cbe', 'chapa', 'bank', 'other');

    /** Record money received against an issued invoice. Never more than the balance. */
    public static function add($ctx, $b)
    {
        $invoiceId = (int) (isset($b['invoice_id']) ? $b['invoice_id'] : 0);
        $method = isset($b['method']) ? (string) $b['method'] : 'cash';
        if (!in_array($method, self::METHODS, true)) throw new ApiError(422, 'Unknown payment method.');
        $ref = substr(trim((string) (isset($b['reference']) ? $b['reference'] : '')), 0, 80);
        $clientRef = isset($b['client_ref']) && $b['client_ref'] !== '' ? substr((string) $b['client_ref'], 0, 60) : null;
        $amount = Money::toCents(isset($b['amount']) ? $b['amount'] : '', 'amount');
        if ($amount <= 0) throw new ApiError(422, 'Payment must be more than zero.');
        try {
            return Db::tx(function () use ($ctx, $invoiceId, $method, $ref, $clientRef, $amount) {
                $inv = Db::one('SELECT id, status, total, paid_amount FROM invoices WHERE id = ? AND business_id = ? FOR UPDATE', array($invoiceId, $ctx['business_id']));
                if (!$inv) throw new ApiError(404, 'Invoice not found.');
                if ($inv['status'] !== 'issued') throw new ApiError(409, 'Only issued invoices can be paid.');
                $balance = Money::toCents($inv['total']) - Money::toCents($inv['paid_amount']);
                if ($amount > $balance) throw new ApiError(422, 'That is more than the balance of ' . Money::fromCents($balance) . '.');
                $id = Db::insert(
                    'INSERT INTO payments (business_id, invoice_id, amount, method, reference, client_ref, paid_at, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
                    array($ctx['business_id'], $invoiceId, Money::fromCents($amount), $method, $ref, $clientRef, Db::now(), $ctx['member_id'], Db::now())
                );
                self::recompute($invoiceId);
                Audit::log($ctx, 'payment.add', 'invoice', $invoiceId, Money::fromCents($amount) . ' ' . $method);
                return $id;
            });
        } catch (PDOException $e) {
            if ($clientRef !== null && $e->getCode() === '23000') {   // same client_ref sent twice: it already went through
                $row = Db::one('SELECT id FROM payments WHERE business_id = ? AND client_ref = ?', array($ctx['business_id'], $clientRef));
                if ($row) return (int) $row['id'];
            }
            throw $e;
        }
    }

    public static function void($ctx, $id)
    {
        Db::tx(function () use ($ctx, $id) {
            $p = Db::one('SELECT id, invoice_id, voided FROM payments WHERE id = ? AND business_id = ? FOR UPDATE', array((int) $id, $ctx['business_id']));
            if (!$p) throw new ApiError(404, 'Payment not found.');
            if ($p['voided']) throw new ApiError(409, 'That payment is already cancelled.');
            Db::one('SELECT id FROM invoices WHERE id = ? FOR UPDATE', array($p['invoice_id']));
            Db::run('UPDATE payments SET voided = 1, voided_at = ? WHERE id = ?', array(Db::now(), $p['id']));
            self::recompute((int) $p['invoice_id']);
            Audit::log($ctx, 'payment.void', 'payment', (int) $p['id']);
        });
    }

    public static function recompute($invoiceId)
    {
        $sum = Db::val('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ? AND voided = 0', array($invoiceId));
        Db::run('UPDATE invoices SET paid_amount = ? WHERE id = ?', array($sum, $invoiceId));
    }
}
