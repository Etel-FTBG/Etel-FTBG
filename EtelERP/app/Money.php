<?php
/**
 * Money and quantity maths in whole integers (cents, and thousandths of a unit),
 * so totals never drift the way floating point does. All rounding is half-up.
 */
class Money
{
    const MAX_CENTS = 99999999999;   // ETB 999,999,999.99

    /** "12.5", 12.5 or "1,200.00" -> 1250 / 120000 cents. Throws ApiError on anything else. */
    public static function toCents($value, $label = 'amount')
    {
        return self::scaled($value, 2, $label);
    }

    /** Quantity with up to 3 decimals -> thousandths. */
    public static function toMilli($value, $label = 'quantity')
    {
        return self::scaled($value, 3, $label);
    }

    private static function scaled($value, $places, $label)
    {
        if (is_int($value)) $value = (string) $value;
        if (is_float($value)) $value = rtrim(rtrim(sprintf('%.' . ($places + 2) . 'F', $value), '0'), '.');
        if (!is_string($value)) throw new ApiError(422, 'Invalid ' . $label . '.');
        $value = trim(str_replace(',', '', $value));
        if (!preg_match('/^-?\d{1,12}(\.\d{1,' . $places . '})?$/', $value)) {
            throw new ApiError(422, 'Invalid ' . $label . '.');
        }
        $neg = $value[0] === '-';
        if ($neg) $value = substr($value, 1);
        $parts = explode('.', $value);
        $frac = isset($parts[1]) ? str_pad($parts[1], $places, '0') : str_repeat('0', $places);
        $n = (int) ($parts[0] . $frac);
        if ($n > self::MAX_CENTS * ($places === 3 ? 10 : 1)) throw new ApiError(422, ucfirst($label) . ' is too large.');
        return $neg ? -$n : $n;
    }

    public static function fromCents($cents)
    {
        $neg = $cents < 0;
        $cents = abs((int) $cents);
        return ($neg ? '-' : '') . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function fromMilli($milli)
    {
        $neg = $milli < 0;
        $milli = abs((int) $milli);
        $s = intdiv($milli, 1000) . '.' . str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT);
        return ($neg ? '-' : '') . $s;
    }

    /** round(a * b / d) half-up for non-negative integers. */
    public static function mulDiv($a, $b, $d)
    {
        return intdiv($a * $b * 2 + $d, $d * 2);
    }

    /** Line subtotal in cents: quantity (thousandths) x unit price (cents). */
    public static function lineSubtotal($qtyMilli, $priceCents)
    {
        return self::mulDiv($qtyMilli, $priceCents, 1000);
    }

    /** VAT in cents on a subtotal; $rateBp is the rate in basis points (15.00% = 1500). */
    public static function vat($subtotalCents, $rateBp)
    {
        return self::mulDiv($subtotalCents, $rateBp, 10000);
    }

    public static function rateToBp($rate)
    {
        return self::scaled($rate, 2, 'rate');
    }
}
