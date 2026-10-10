<?php
/** Invoice maths. Pure functions: no database, no clock. Prices are VAT-exclusive. */
class Calc
{
    const MAX_LINES = 200;

    /**
     * $lines: [ ['item' => itemRow|null, 'description' => str, 'qty' => str, 'unit_price' => str], ... ]
     * $vatRegistered / $vatRateBp: from the business. A line is charged VAT only when the
     * business is VAT registered and the item is not VAT exempt (free-text lines are charged).
     * Returns ['lines' => [...computed...], 'subtotal' => cents, 'vat' => cents, 'total' => cents].
     */
    public static function invoice($lines, $vatRegistered, $vatRateBp)
    {
        if (!is_array($lines) || count($lines) < 1) throw new ApiError(422, 'Add at least one line.');
        if (count($lines) > self::MAX_LINES) throw new ApiError(422, 'Too many lines (max ' . self::MAX_LINES . ').');
        $out = array();
        $sub = 0;
        $vat = 0;
        foreach ($lines as $i => $l) {
            $desc = trim((string) (isset($l['description']) ? $l['description'] : ''));
            if ($desc === '' || strlen($desc) > 255) throw new ApiError(422, 'Line ' . ($i + 1) . ': description is required (max 255 characters).');
            $qty = Money::toMilli(isset($l['qty']) ? $l['qty'] : '', 'quantity');
            if ($qty <= 0) throw new ApiError(422, 'Line ' . ($i + 1) . ': quantity must be more than zero.');
            $price = Money::toCents(isset($l['unit_price']) ? $l['unit_price'] : '', 'price');
            if ($price < 0) throw new ApiError(422, 'Line ' . ($i + 1) . ': price cannot be negative.');
            $exempt = !empty($l['item']) && !empty($l['item']['vat_exempt']);
            $rate = ($vatRegistered && !$exempt) ? (int) $vatRateBp : 0;
            $ls = Money::lineSubtotal($qty, $price);
            $lv = Money::vat($ls, $rate);
            $sub += $ls;
            $vat += $lv;
            $out[] = array(
                'item_id' => !empty($l['item']) ? (int) $l['item']['id'] : null,
                'description' => $desc,
                'qty_milli' => $qty,
                'price_cents' => $price,
                'rate_bp' => $rate,
                'subtotal_cents' => $ls,
                'vat_cents' => $lv,
                'total_cents' => $ls + $lv,
            );
        }
        if ($sub + $vat > Money::MAX_CENTS) throw new ApiError(422, 'Invoice total is too large.');
        return array('lines' => $out, 'subtotal' => $sub, 'vat' => $vat, 'total' => $sub + $vat);
    }

    /** unpaid | partial | paid for an issued invoice. */
    public static function paymentStatus($totalCents, $paidCents)
    {
        if ($paidCents <= 0) return 'unpaid';
        return $paidCents >= $totalCents ? 'paid' : 'partial';
    }
}
