<?php
/** Who may do what inside a business. owner > manager > cashier. */
class Perm
{
    private static $rank = array('cashier' => 1, 'manager' => 2, 'owner' => 3);

    /** minimum role for each action; anything not listed is refused. */
    private static $need = array(
        'dashboard.view'   => 'cashier',
        'customer.view'    => 'cashier',
        'customer.write'   => 'cashier',
        'item.view'        => 'cashier',
        'item.write'       => 'manager',
        'stock.adjust'     => 'manager',
        'invoice.view'     => 'cashier',
        'invoice.sell'     => 'cashier',
        'invoice.write'    => 'cashier',
        'invoice.free_line'=> 'manager',   // free-text lines and price changes
        'invoice.void'     => 'manager',
        'payment.add'      => 'cashier',
        'payment.void'     => 'manager',
        'expense.view'     => 'manager',
        'expense.write'    => 'manager',
        'report.view'      => 'manager',
        'team.view'        => 'manager',
        'team.manage'      => 'owner',
        'business.edit'    => 'owner',
    );

    public static function can($role, $action)
    {
        if (!isset(self::$need[$action]) || !isset(self::$rank[$role])) return false;
        return self::$rank[$role] >= self::$rank[self::$need[$action]];
    }

    public static function require_($role, $action)
    {
        if (!self::can($role, $action)) throw new ApiError(403, 'You do not have permission to do that.');
    }

    public static function validRole($role)
    {
        return isset(self::$rank[$role]);
    }
}
