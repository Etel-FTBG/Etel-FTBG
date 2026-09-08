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

$rows = $db->fetchAll(
    "SELECT oi.id, oi.order_id
       FROM order_items oi
       JOIN orders o ON o.id = oi.order_id
      WHERE oi.status = 'delivered'
        AND oi.escrow_status = 'held'
        AND oi.delivered_at IS NOT NULL
        AND oi.delivered_at <= ?
        AND o.payment_method = 'etel_pay'",
    [$cutoff]
);

$released = 0;
$skipped  = 0;
foreach ($rows as $row) {
    if ($svc->hasOpenDispute((int)$row['order_id'])) {
        $skipped++;
        continue;
    }
    if ($svc->releaseItem((int)$row['id'], 'auto_release_5day')) {
        $released++;
    } else {
        $skipped++;
    }
}

$msg = sprintf(
    "[escrow_autorelease] %s -- checked %d item(s), released %d, skipped %d\n",
    date('Y-m-d H:i:s'), count($rows), $released, $skipped
);
echo $msg;
@file_put_contents(EG_ROOT . '/storage/logs/escrow_autorelease.log', $msg, FILE_APPEND);
