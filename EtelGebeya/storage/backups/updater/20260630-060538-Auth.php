<?php
declare(strict_types=1);
namespace App\Core;

/**
 * Auth -- session-based authentication with role gates.
 *
 * Roles: customer, vendor, courier, admin (a user can be multiple via roles[]).
 * Vendor status: pending / approved / suspended (separate from role).
 */
final class Auth
{
    private static ?self $instance = null;
    private ?array $user = null;

    private function __construct()
    {
        $this->refresh();
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function user(): ?array
    {
        return $this->user;
    }

    public function id(): ?int
    {
        return $this->user['id'] ?? null;
    }

    public function check(): bool
    {
        return $this->user !== null;
    }

    public function guest(): bool
    {
        return $this->user === null;
    }

    public function hasRole(string $role): bool
    {
        if (!$this->user) return false;
        $roles = $this->user['roles'] ?? [];
        return in_array($role, $roles, true) || in_array('admin', $roles, true);
    }

    public function isAdmin(): bool    { return $this->hasRole('admin'); }
    public function isVendor(): bool   { return $this->hasRole('vendor'); }
    public function isCourier(): bool  { return $this->hasRole('courier'); }
    public function isCustomer(): bool { return $this->check(); }

    public function vendorStatus(): ?string
    {
        return $this->user['vendor_status'] ?? null;
    }

    public function attempt(string $email, string $password): bool
    {
        $u = Database::getInstance()->fetch(
            'SELECT * FROM ' . Database::getInstance()->prefix() . 'users WHERE email = ? LIMIT 1',
            [strtolower(trim($email))]
        );
        if (!$u) {
            $this->recordLoginAttempt($email, false);
            return false;
        }
        if ($u['status'] === 'banned') {
            $_SESSION['_flash'] = ['type' => 'error', 'msg' => 'Account suspended.'];
            return false;
        }
        if (!password_verify($password, $u['password_hash'])) {
            $this->recordLoginAttempt($email, false);
            return false;
        }
        $this->recordLoginAttempt($email, true);
        $this->loginUser((int)$u['id']);
        return true;
    }

    public function loginUser(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $this->refresh();
        Database::getInstance()->update('users', [
            'last_login_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$userId]);
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        $this->user = null;
    }

    public function refresh(): void
    {
        $uid = $_SESSION['user_id'] ?? null;
        if (!$uid) { $this->user = null; return; }

        try {
            $db = Database::getInstance();
            if (!$db->isConnected()) { $this->user = null; return; }

            $prefix = $db->prefix();
            $row = $db->fetch(
                "SELECT id, full_name, email, phone, locale, status,
                        (SELECT GROUP_CONCAT(role) FROM {$prefix}user_roles WHERE user_id = u.id) AS roles_csv,
                        (SELECT status FROM {$prefix}vendors WHERE user_id = u.id LIMIT 1) AS vendor_status
                   FROM {$prefix}users u WHERE id = ? LIMIT 1",
                [$uid]
            );
            if (!$row || $row['status'] === 'banned') {
                $this->logout();
                return;
            }
            $row['roles'] = $row['roles_csv'] ? array_values(array_filter(explode(',', $row['roles_csv']))) : ['customer'];
            unset($row['roles_csv']);
            $this->user = $row;
        } catch (\Throwable $e) {
            // DB not available -- treat as guest
            $this->user = null;
        }
    }

    public function require(string $role): void
    {
        if (!$this->hasRole($role)) {
            if (!$this->check()) {
                $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? '/';
                redirect('/login');
                exit;
            }
            http_response_code(403);
            View::render('errors/403', ['role' => $role]);
            exit;
        }
    }

    public function flash(string $type, string $msg): void
    {
        $_SESSION['_flash'] = ['type' => $type, 'msg' => $msg];
    }

    public function takeFlash(): ?array
    {
        $f = $_SESSION['_flash'] ?? null;
        if ($f) unset($_SESSION['_flash']);
        return $f;
    }

    public function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public function csrfCheck(?string $token): bool
    {
        return is_string($token) && hash_equals($_SESSION['_csrf'] ?? '', $token);
    }

    private function recordLoginAttempt(string $email, bool $ok): void
    {
        // Lightweight rate limit (DB-backed, for shared hosts)
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $window = (int)($GLOBALS['__config']['security']['rate_limit_window'] ?? 60);
        $max    = (int)($GLOBALS['__config']['security']['rate_limit_max'] ?? 120);
        $count  = Database::getInstance()->fetch(
            'SELECT COUNT(*) AS c FROM ' . Database::getInstance()->prefix() . 'login_attempts
              WHERE ip = ? AND attempted_at > (NOW() - INTERVAL ? SECOND)',
            [$ip, $window]
        )['c'] ?? 0;
        if ($count > $max) {
            http_response_code(429);
            echo 'Too many requests';
            exit;
        }
        Database::getInstance()->insert('login_attempts', [
            'email'         => strtolower(trim($email)),
            'ip'            => $ip,
            'success'       => $ok ? 1 : 0,
            'attempted_at'  => date('Y-m-d H:i:s'),
        ]);
    }
}
