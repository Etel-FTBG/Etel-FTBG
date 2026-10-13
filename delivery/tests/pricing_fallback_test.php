<?php
// Delivery pricing fallbacks (no real database needed: an empty in-memory SQLite makes every table lookup fail,
// exactly like a zone that is missing from the delivery_zones table).
//  1. config-etb.php is loaded from INSIDE a function, the way delivery_order_core.php does it. The zone/tier
//     arrays must still reach delivery_calc_fee() (they used to become function-locals, so every zone that was
//     not active in the database silently fell back to a flat ETB 50).
//  2. The GPS (distance) pricing functions that delivery_order_core.php calls must exist in the file it requires.
$fails = 0;
function check($name, $ok) { global $fails; echo ($ok ? "  PASS  " : "  FAIL  ") . $name . "\n"; if (!$ok) $fails++; }

function load_like_order_core() { require_once __DIR__ . '/../api/config-etb.php'; }   // same pattern as delivery_execute_order_creation()
load_like_order_core();
require_once __DIR__ . '/../api/lib/delivery_pricing.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "delivery pricing fallbacks\n";
$r = delivery_calc_fee($pdo, 'Hawela Teklehaimanot', 'moto');
check('a zone missing from the database uses its configured fee (75), not a flat 50', $r !== null && $r['delivery_fee'] == 75);
$r = delivery_calc_fee($pdo, 'Tabor', 'car');
check('tier multiplier applies from the fallback table (50 x 1.12 = 56)', $r !== null && $r['delivery_fee'] == 56);
check('a truly unknown zone still returns null for the caller to handle', delivery_calc_fee($pdo, 'Nowhere Land', 'moto') === null);
check('GPS distance fee function exists', function_exists('delivery_calc_fee_by_distance'));
check('distance-from-centre function exists', function_exists('delivery_distance_from_center'));
if (function_exists('delivery_calc_fee_by_distance')) {
    $d = delivery_calc_fee_by_distance($pdo, 3.3);
    check('GPS fee = 15 + 8 x 3.3 = 41 (default rules)', $d['delivery_fee'] == 41);
}
echo $fails ? "FAILED: $fails\n" : "ALL PASS\n";
exit($fails ? 1 : 0);
