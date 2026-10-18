<?php
/**
 * One run at a time for a cron job.
 *
 * cPanel starts the next run on schedule even when the last one is still going (a slow database, a long sweep, a
 * host hiccup). Two overlapping runs of the same job can send the same alert twice or work on the same rows at the
 * same moment. A MySQL named lock fixes that without any file or table: the job asks for it with a zero wait, and if
 * another run holds it, this run reports "skipped" and ends. The lock belongs to the database connection, so it
 * frees itself when the script ends - even if the script crashes.
 *
 * Fails open: if the database cannot give a lock for some reason, the job runs as it always did.
 */
if (!function_exists('etel_cron_lock')) {
function etel_cron_lock($pdo, $name) {
    try {
        $s = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $s->execute(array('etel_cron_' . preg_replace('/[^a-z0-9_]/i', '', $name)));
        $v = $s->fetchColumn();
        return $v === false || $v === null || intval($v) === 1;
    } catch (Exception $e) {
        return true;
    }
}
}
