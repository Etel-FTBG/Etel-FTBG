<?php
/**
 * Daily escrow auto-release.
 *
 * Run via cPanel Cron Jobs, once per day, e.g.:
 *   php /home/USER/public_html/EtelGebeya/cron/escrow_autorelease.php
 *
 * If a customer never clicks "I Received My Order", this releases the
 * held payment to the seller automatically 5 days after the seller
 * marked the item delivered -- unless an open dispute is blocking it.
 * Safe to run more than once a day; already-released items are skipped.
 */
declare(strict_types=1);

define('EG_ROOT', dirname(__DIR__));
define('EG_AUTORELEASE_DAYS', 5);

$config = require EG_ROOT . '/config/app.php';
require EG_ROOT . '/bootstrap.php';

use App\Core\Database;
use App\Core\EscrowService;

$db = Database::getInstance();
if (!$db->isConnected()) {
    fwrite(STDERR, "[escrow_autorelease] Database not connected -- aborting.\n");
    exit(1);
}

$svc = new EscrowService();

$cutoff = date('Y-m-d H:i:s', time() - EG_AUTORELEASE_DAYS * 86400);

// A seller's hold is eligible once every item they sold in that order is
// delivered, and the LATEST of those deliveries happened 5+ days ago
// (the customer had the full window to notice a problem with everything
// that arrived, not just the first item).
$rows = $db->fetchAll(
    "SELECT DISTINCT ose.order_id
       FROM order_seller_escrow ose
       JOIN orders o ON o.id = ose.order_id
      WHERE ose.status = 'held'
        AND o.payment_method = 'etel_pay'
        AND NOT EXISTS (
              SELECT 1 FROM order_items oi
               WHERE oi.order_id = ose.order_id AND oi.seller_id = ose.seller_id
                 AND oi.status NOT IN ('delivered','cancelled','returned')
            )
        AND (
              SELECT MAX(oi.delivered_at) FROM order_items oi
               WHERE oi.order_id = ose.order_id AND oi.seller_id = ose.seller_id
            ) <= ?"
    , [$cutoff]
);

$released = 0;
$skipped  = 0;
foreach ($rows as $row) {
    if ($svc->hasOpenDispute((int)$row['order_id'])) {
        $skipped++;
        continue;
    }
    $n = $svc->releaseOrder((int)$row['order_id'], 'auto_release_5day');
    if ($n > 0) { $released += $n; } else { $skipped++; }
}

$msg = sprintf(
    "[escrow_autorelease] %s -- checked %d order(s), released %d seller-hold(s), skipped %d\n",
    date('Y-m-d H:i:s'), count($rows), $released, $skipped
);
echo $msg;
@file_put_contents(EG_ROOT . '/storage/logs/escrow_autorelease.log', $msg, FILE_APPEND);
