<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\EtelIDClient;
use App\Core\View;

/**
 * AuthController -- login, register, logout, language switch.
 */
final class AuthController
{
    private Auth $auth;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
    }

    /* ?? GET /login ???????????????????????????????????????? */
    public function loginForm(): void
    {
        if ($this->auth->check()) {
            redirect('/');
        }
        $this->render('storefront/login', ['pageTitle' => t('auth.login')]);
    }

    /* ?? POST /login ??????????????????????????????????????? */
    public function login(): void
    {
        if (!$this->auth->csrfCheck($_POST['_token'] ?? '')) {
            $this->auth->flash('error', 'Invalid form token. Please try again.');
            redirect('/login');
        }

        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $this->auth->flash('error', 'Please enter your email and password.');
            redirect('/login');
        }

        if ($this->auth->attempt($email, $password)) {
            $intended = $_SESSION['_intended'] ?? '/';
            unset($_SESSION['_intended']);
            redirect($intended);
        }

        $this->auth->flash('error', 'Invalid email or password. Please try again.');
        redirect('/login');
    }

    /* ?? GET /register ????????????????????????????????????? */
    public function registerForm(): void
    {
        if ($this->auth->check()) {
            redirect('/');
        }
        $this->render('storefront/register', ['pageTitle' => t('auth.register')]);
    }

    /* ?? POST /register ???????????????????????????????????? */
    public function register(): void
    {
        if (!$this->auth->csrfCheck($_POST['_token'] ?? '')) {
            $this->auth->flash('error', 'Invalid form token.');
            redirect('/register');
        }

        $name     = trim($_POST['name'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm'] ?? '';
        $role     = in_array($_POST['role'] ?? '', ['customer', 'vendor']) ? $_POST['role'] : 'customer';
        $phone    = trim($_POST['phone'] ?? '');

        // Validation
        $errors = [];
        if (strlen($name) < 2)                                $errors[] = 'Full name must be at least 2 characters.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))       $errors[] = 'Enter a valid email address.';
        if (strlen($password) < 8)                            $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirm)                           $errors[] = 'Passwords do not match.';

        if (!empty($errors)) {
            $this->auth->flash('error', implode(' ', $errors));
            redirect('/register');
        }

        $db = Database::getInstance();
        if (!$db->isConnected()) {
            $this->auth->flash('error', 'Database not available. Please try again later.');
            redirect('/register');
        }

        // Duplicate email check
        if ($db->fetch('SELECT id FROM users WHERE email = ? LIMIT 1', [$email])) {
            $this->auth->flash('error', 'An account with that email already exists. Try logging in.');
            redirect('/register');
        }

        try {
            $db->pdo()->beginTransaction();

            $uid = $db->insert('users', [
                'full_name'     => $name,
                'email'         => $email,
                'phone'         => $phone,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                'locale'        => $_SESSION['locale'] ?? 'en',
                'status'        => 'active',
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            $db->insert('user_roles', ['user_id' => $uid, 'role' => 'customer']);

            if ($role === 'vendor') {
                $db->insert('user_roles', ['user_id' => $uid, 'role' => 'vendor']);
                $slug = $this->makeSlug($name . '-' . $uid);
                $db->insert('sellers', [
                    'user_id'    => $uid,
                    'shop_name'  => $name . "'s Shop",
                    'slug'       => $slug,
                    'status'     => 'pending',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            $db->pdo()->commit();

        } catch (\Throwable $e) {
            $db->pdo()->rollBack();
            error_log('[EG-Auth] Register error: ' . $e->getMessage());
            $this->auth->flash('error', 'Registration failed. Please try again.');
            redirect('/register');
        }

        $this->auth->loginUser($uid);
        $this->auth->flash('success', 'Welcome to Etel Gebeya, ' . htmlspecialchars($name) . '!');
        redirect($role === 'vendor' ? '/vendor-panel' : '/');
    }

    /* POST /login/etelid
       Primary login path. Authenticates against EtelID, auto-provisions
       a local EtelGebeya account if needed, and maps EtelID roles:
         admin   -> admin + vendor + customer
         vendor  -> vendor + customer
         default -> customer                                              */
    public function etelIdLogin(): void
    {
        if (!$this->auth->csrfCheck($_POST['_token'] ?? '')) {
            $this->auth->flash('error', 'Invalid form token. Please try again.');
            redirect('/login');
        }

        $identifier = trim($_POST['etelid_identifier'] ?? '');
        $password   = $_POST['etelid_password'] ?? '';

        if ($identifier === '' || $password === '') {
            $this->auth->flash('error', 'Please enter your Etel-ID phone or number and password.');
            redirect('/login');
        }

        $client = new EtelIDClient();
        $member = $client->authenticate($identifier, $password);

        if (!$member) {
            $this->auth->flash('error', 'Etel-ID sign-in failed. Check your phone number and password.');
            redirect('/login');
        }

        $phone    = $member['phone'] ?? $identifier;
        $name     = $member['full_name'] ?? 'Etel Member';
        $email    = $member['email'] ?? null;
        $isAdmin  = !empty($member['is_admin']) || ($member['role'] ?? '') === 'admin'
                    || in_array('admin', (array)($member['roles'] ?? []), true);
        $isVendor = !empty($member['is_vendor']) || in_array('vendor', (array)($member['roles'] ?? []), true);

        $db = Database::getInstance();
        if (!$db->isConnected()) {
            $this->auth->flash('error', 'Database not available. Please try again later.');
            redirect('/login');
        }

        // Find existing local account by phone, then by email
        $user = $db->fetch('SELECT id FROM users WHERE phone = ? LIMIT 1', [$phone]);
        if (!$user && $email) {
            $user = $db->fetch('SELECT id FROM users WHERE email = ? LIMIT 1', [$email]);
        }

        if ($user) {
            $uid = (int) $user['id'];
            // Sync name/phone in case they changed in EtelID
            $db->update('users', ['full_name' => $name, 'phone' => $phone], 'id = ?', [$uid]);
        } else {
            // Auto-provision a local account linked to this EtelID member
            try {
                $uid = $db->insert('users', [
                    'full_name'     => $name,
                    'email'         => $email ?? ($phone . '@etelid.local'),
                    'phone'         => $phone,
                    'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
                    'locale'        => $_SESSION['locale'] ?? 'en',
                    'status'        => 'active',
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e) {
                error_log('[EG-EtelID] Auto-provision failed: ' . $e->getMessage());
                $this->auth->flash('error', 'Could not create your account. Please contact support.');
                redirect('/login');
            }
        }

        // Sync roles from EtelID -- always re-apply so demotions also take effect
        $this->syncEtelIDRoles($db, $uid, $isAdmin, $isVendor);

        $this->auth->loginUser($uid);
        $this->auth->refresh();

        $intended = $_SESSION['_intended'] ?? null;
        unset($_SESSION['_intended']);

        // Admins always go to /admin regardless of _intended
        if ($isAdmin) {
            redirect('/admin');
        } elseif ($isVendor) {
            redirect('/vendor-panel');
        } elseif ($intended && $intended !== '/account') {
            redirect($intended);
        } else {
            redirect('/account');
        }
    }

    /** Sync EtelID roles to EtelGebeya -- never demote an existing admin. */
    private function syncEtelIDRoles(object $db, int $uid, bool $isAdmin, bool $isVendor): void
    {
        try {
            // Check if user is already an admin in EtelGebeya -- preserve that
            $existingRoles = $db->fetchAll(
                'SELECT role FROM user_roles WHERE user_id = ?', [$uid]
            );
            $hasAdminAlready = in_array('admin', array_column($existingRoles, 'role'), true);

            // If EtelID signals admin OR they already have it locally -- keep admin
            $grantAdmin  = $isAdmin || $hasAdminAlready;
            $grantVendor = $isVendor || $grantAdmin;

            $db->pdo()->exec('DELETE FROM user_roles WHERE user_id = ' . $uid);
            $db->insert('user_roles', ['user_id' => $uid, 'role' => 'customer']);
            if ($grantVendor) {
                $db->insert('user_roles', ['user_id' => $uid, 'role' => 'vendor']);
            }
            if ($grantAdmin) {
                $db->insert('user_roles', ['user_id' => $uid, 'role' => 'admin']);
            }
        } catch (\Throwable $e) {
            error_log('[EG-EtelID] Role sync failed: ' . $e->getMessage());
        }
    }

    /* ?? POST /logout ?????????????????????????????????????? */
    public function logout(): void
    {
        $this->auth->logout();
        redirect('/');
    }

    /* ?? GET /lang/{locale} ???????????????????????????????? */
    public function setLanguage(array $params): void
    {
        $locale = $params['locale'] ?? 'en';
        $supported = $GLOBALS['__config']['app']['locales'] ?? ['en', 'am'];
        if (!in_array($locale, $supported, true)) {
            $locale = 'en';
        }
        $_SESSION['locale'] = $locale;

        // Redirect back, stripping the subdirectory base from referrer
        $ref  = $_SERVER['HTTP_REFERER'] ?? '';
        $base = rtrim(parse_url($GLOBALS['__config']['app']['url'] ?? '', PHP_URL_PATH) ?? '', '/');
        if ($ref !== '') {
            $path = parse_url($ref, PHP_URL_PATH) ?? '/';
            if ($base !== '' && str_starts_with($path, $base)) {
                $path = substr($path, strlen($base)) ?: '/';
            }
            redirect($path);
        }
        redirect('/');
    }

    /* ?? private helpers ??????????????????????????????????? */
    private function render(string $view, array $data = []): void
    {
        $flash = $this->auth->takeFlash();
        View::render('_layouts/public', array_merge($data, ['flash' => $flash]), function () use ($view, $data, $flash) {
            echo View::capture($view, array_merge($data, ['flash' => $flash]));
        });
    }

    private function makeSlug(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9-]/', '-', $text) ?? $text;
        $text = preg_replace('/-+/', '-', $text) ?? $text;
        return trim($text, '-');
    }
}
