<?php
/** Fixed-window counter in the database. hit() throws a 429 once the limit is passed. */
class RateLimit
{
    public static function hit($key, $limit, $seconds)
    {
        $key = substr($key, 0, 100);
        $now = Db::now();
        $row = Db::one('SELECT hits, window_end FROM rate_limits WHERE k = ?', array($key));
        if (!$row || $row['window_end'] < $now) {
            Db::run('REPLACE INTO rate_limits (k, hits, window_end) VALUES (?, 1, ?)', array($key, date('Y-m-d H:i:s', time() + $seconds)));
            return;
        }
        if ((int) $row['hits'] >= $limit) throw new ApiError(429, 'Too many attempts. Please wait a few minutes and try again.');
        Db::run('UPDATE rate_limits SET hits = hits + 1 WHERE k = ?', array($key));
    }

    public static function clear($key)
    {
        Db::run('DELETE FROM rate_limits WHERE k = ?', array(substr($key, 0, 100)));
    }
}
