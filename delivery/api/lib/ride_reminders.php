<?php
/**
 * Reminders for pre-booked (scheduled) rides.
 *
 * Called by cron_dispatch_scheduled.php on every run. A rider whose scheduled ride starts within the
 * next 30 minutes gets ONE push ("Your ride is booked for 7:30 AM"), so nobody forgets a pre-booking
 * or is caught without their phone when the driver search begins. `reminder_sent_at` makes it one-shot
 * even if two cron runs overlap (the UPDATE only wins once). Time: compared with SQL NOW(), like the
 * dispatcher (the DB session is aligned to PHP's zone in db()).
 *
 * $send is a callable ($pdo, $userId, $title, $body, $data) so this can be tested without real pushes.
 * Returns the ride ids that were reminded.
 */

if (!function_exists('rides_send_scheduled_reminders')) {

function rides_send_scheduled_reminders($pdo, $send, $windowMin = 30) {
    $done = array();
    try {
        if (function_exists('rides_add_missing_cols')) {
            rides_add_missing_cols($pdo, 'rides', array('reminder_sent_at' => 'reminder_sent_at DATETIME NULL'));
        }
        $s = $pdo->prepare("SELECT id, passenger_id, scheduled_at, pickup_address FROM rides
                            WHERE status='scheduled' AND scheduled_at IS NOT NULL AND reminder_sent_at IS NULL
                              AND scheduled_at > NOW() AND scheduled_at <= DATE_ADD(NOW(), INTERVAL " . intval($windowMin) . " MINUTE)
                            ORDER BY scheduled_at ASC LIMIT 100");
        $s->execute();
        foreach ($s->fetchAll() as $r) {
            $claim = $pdo->prepare("UPDATE rides SET reminder_sent_at = NOW() WHERE id=? AND reminder_sent_at IS NULL AND status='scheduled'");
            $claim->execute(array(intval($r['id'])));
            if ($claim->rowCount() === 0) continue; // another run took it
            $when = date('g:i A', strtotime($r['scheduled_at']));
            $from = trim((string) $r['pickup_address']);
            call_user_func($send, $pdo, intval($r['passenger_id']), 'Your ride is coming up',
                'Your ETEL Ride is booked for ' . $when . ($from !== '' ? ' from ' . $from : '') . '. We will start looking for your driver then.',
                array('type' => 'ride_reminder', 'ride_id' => intval($r['id']), 'url' => '/delivery/ride/'));
            $done[] = intval($r['id']);
        }
    } catch (Exception $e) {
        error_log('[ETEL][ride_reminders] ' . $e->getMessage());
    }
    return $done;
}

} // end function_exists guard
