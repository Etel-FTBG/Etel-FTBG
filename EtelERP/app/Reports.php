<?php
class Reports
{
    private static function c($v)
    {
        return Money::toCents((string) ($v === null ? '0' : $v));
    }

    public static function dashboard($ctx)
    {
        $b = $ctx['business_id'];
        $today = date('Y-m-d');
        $month = date('Y-m-01');
        $live = "status = 'issued'";
        $t = Db::one("SELECT COUNT(*) n, COALESCE(SUM(total),0) total FROM invoices WHERE business_id = ? AND $live AND issue_date = ?", array($b, $today));
        $m = Db::one("SELECT COUNT(*) n, COALESCE(SUM(subtotal),0) subtotal, COALESCE(SUM(vat_amount),0) vat, COALESCE(SUM(total),0) total FROM invoices WHERE business_id = ? AND $live AND issue_date >= ?", array($b, $month));
        $recv = Db::one("SELECT COUNT(*) n, COALESCE(SUM(total - paid_amount),0) amt FROM invoices WHERE business_id = ? AND $live AND paid_amount < total", array($b));
        $coll_t = Db::val('SELECT COALESCE(SUM(amount),0) FROM payments WHERE business_id = ? AND voided = 0 AND DATE(paid_at) = ?', array($b, $today));
        $coll_m = Db::val('SELECT COALESCE(SUM(amount),0) FROM payments WHERE business_id = ? AND voided = 0 AND DATE(paid_at) >= ?', array($b, $month));
        $exp_m = Db::val('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE business_id = ? AND voided = 0 AND spent_at >= ?', array($b, $month));
        $low = Db::all('SELECT id, name, unit, stock_qty, reorder_level FROM items WHERE business_id = ? AND active = 1 AND track_stock = 1 AND stock_qty <= reorder_level ORDER BY stock_qty LIMIT 10', array($b));
        $days = Db::all("SELECT issue_date AS d, COALESCE(SUM(total),0) AS total FROM invoices WHERE business_id = ? AND $live AND issue_date >= ? GROUP BY issue_date ORDER BY issue_date", array($b, date('Y-m-d', strtotime('-13 days'))));
        $top = Db::all("SELECT l.description, COALESCE(SUM(l.qty),0) qty, COALESCE(SUM(l.line_subtotal),0) amount FROM invoice_lines l JOIN invoices i ON i.id = l.invoice_id
                        WHERE i.business_id = ? AND i.status = 'issued' AND i.issue_date >= ? GROUP BY l.description ORDER BY amount DESC LIMIT 5", array($b, $month));
        $result = array(
            'today' => array('invoices' => (int) $t['n'], 'sales' => $t['total'], 'collected' => $coll_t),
            'month' => array('invoices' => (int) $m['n'], 'sales_excl_vat' => $m['subtotal'], 'vat' => $m['vat'], 'sales' => $m['total'],
                             'collected' => $coll_m, 'expenses' => $exp_m,
                             'profit_estimate' => Money::fromCents(self::c($m['subtotal']) - self::c($exp_m))),
            'receivables' => array('invoices' => (int) $recv['n'], 'amount' => $recv['amt']),
            'low_stock' => $low,
            'sales_by_day' => $days,
            'top_items' => $top,
        );
        return $result;
    }

    /** Sales and VAT between two dates, plus money received by method: what a VAT return and a cash-up need. */
    public static function sales($ctx, $from, $to)
    {
        $b = $ctx['business_id'];
        $tot = Db::one("SELECT COUNT(*) n, COALESCE(SUM(subtotal),0) subtotal, COALESCE(SUM(vat_amount),0) vat, COALESCE(SUM(total),0) total
                        FROM invoices WHERE business_id = ? AND status = 'issued' AND issue_date BETWEEN ? AND ?", array($b, $from, $to));
        $by = Db::all('SELECT method, COUNT(*) n, COALESCE(SUM(amount),0) amount FROM payments WHERE business_id = ? AND voided = 0 AND DATE(paid_at) BETWEEN ? AND ? GROUP BY method ORDER BY amount DESC', array($b, $from, $to));
        $exp = Db::val('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE business_id = ? AND voided = 0 AND spent_at BETWEEN ? AND ?', array($b, $from, $to));
        return array('from' => $from, 'to' => $to, 'invoices' => (int) $tot['n'], 'sales_excl_vat' => $tot['subtotal'], 'vat' => $tot['vat'],
                     'sales' => $tot['total'], 'received_by_method' => $by, 'expenses' => $exp);
    }
}
