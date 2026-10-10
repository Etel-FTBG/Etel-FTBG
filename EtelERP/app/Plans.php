<?php
/**
 * Plan limits (0 = unlimited). Prices and billing come later; for now the plan is set on the
 * business row (free by default). A paid plan whose expiry date has passed counts as free.
 */
class Plans
{
    private static $limits = array(
        'free'     => array('users' => 2,  'invoices_month' => 30, 'items' => 100),
        'standard' => array('users' => 5,  'invoices_month' => 0,  'items' => 0),
        'business' => array('users' => 25, 'invoices_month' => 0,  'items' => 0),
    );

    public static function effective($biz)
    {
        $plan = isset($biz['plan']) ? $biz['plan'] : 'free';
        if (!isset(self::$limits[$plan])) $plan = 'free';
        if ($plan !== 'free' && !empty($biz['plan_expires_at']) && $biz['plan_expires_at'] < date('Y-m-d')) $plan = 'free';
        return $plan;
    }

    public static function limit($biz, $what)
    {
        $l = self::$limits[self::effective($biz)];
        return isset($l[$what]) ? $l[$what] : 0;
    }

    /** Throws 402 when $current has already reached the limit. */
    public static function check($biz, $what, $current, $label)
    {
        $max = self::limit($biz, $what);
        if ($max > 0 && $current >= $max) {
            throw new ApiError(402, 'Your ' . self::effective($biz) . ' plan allows up to ' . $max . ' ' . $label . '. Upgrade to add more.');
        }
    }
}
