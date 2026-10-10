<?php
/** EtelID sign-in and our own server-side sessions. */
class Auth
{
    const SESSION_DAYS = 14;

    /**
     * Check credentials with EtelID, then open a session. Returns array(token, member row).
     * Wrong password and unknown account give the same message.
     */
    public static function login($identifier, $password, $totp, $ip)
    {
        $identifier = trim((string) $identifier);
        if ($identifier === '' || strlen($identifier) > 120 || !is_string($password) || $password === '' || strlen($password) > 200
            || preg_match('/[\x00-\x1f\x7f]/', $identifier)) {
            throw new ApiError(422, 'Enter your EtelID (or phone) and your password.');
        }
        RateLimit::hit('login-ip-' . $ip, 20, 600);
        RateLimit::hit('login-id-' . strtolower($identifier), 8, 900);
        try {
            $m = EtelId::authenticate($identifier, $password, trim((string) $totp));
        } catch (EtelIdError $e) {
            if (in_array($e->etelCode, array('requires_2fa', 'bad_2fa', 'twofa_locked', 'rate_limited', 'suspended', 'revoked', 'profile_incomplete', 'no_password'), true)) {
                throw new ApiError(401, $e->getMessage());
            }
            throw new ApiError(401, 'Invalid login credentials');
        }
        if (strtolower((string) (isset($m['status']) ? $m['status'] : '')) !== 'active' || !empty($m['profile_limited'])) {
            throw new ApiError(403, 'Your EtelID is not active or its profile is not complete. Finish it in the EtelID portal first.');
        }
        RateLimit::clear('login-id-' . strtolower($identifier));
        $memberId = self::upsertMember($m);
        $token = bin2hex(random_bytes(32));
        Db::run(
            'INSERT INTO sessions (token_hash, member_id, created_at, expires_at, ip) VALUES (?,?,?,?,?)',
            array(hash('sha256', $token), $memberId, Db::now(), date('Y-m-d H:i:s', time() + self::SESSION_DAYS * 86400), substr($ip, 0, 45))
        );
        Db::run("DELETE FROM sessions WHERE expires_at < ?", array(Db::now()));
        Audit::log(array('member_id' => $memberId, 'ip' => $ip), 'login');
        return array($token, Db::one('SELECT * FROM members WHERE id = ?', array($memberId)));
    }

    private static function upsertMember($m)
    {
        $etel = substr((string) $m['etel_id'], 0, 60);
        $name = substr((string) (isset($m['full_name']) ? $m['full_name'] : ''), 0, 160);
        $phone = substr((string) (isset($m['phone']) ? $m['phone'] : ''), 0, 40);
        $row = Db::one('SELECT id FROM members WHERE etel_id = ?', array($etel));
        if ($row) {
            Db::run('UPDATE members SET full_name = ?, phone = ?, last_login = ? WHERE id = ?', array($name, $phone, Db::now(), $row['id']));
            return (int) $row['id'];
        }
        return Db::insert('INSERT INTO members (etel_id, full_name, phone, last_login, created_at) VALUES (?,?,?,?,?)', array($etel, $name, $phone, Db::now(), Db::now()));
    }

    /** The signed-in member row for a cookie token, or null. */
    public static function member($token)
    {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        return Db::one(
            'SELECT m.* FROM sessions s JOIN members m ON m.id = s.member_id WHERE s.token_hash = ? AND s.expires_at > ?',
            array(hash('sha256', $token), Db::now())
        );
    }

    public static function logout($token)
    {
        if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
            Db::run('DELETE FROM sessions WHERE token_hash = ?', array(hash('sha256', $token)));
        }
    }
}
