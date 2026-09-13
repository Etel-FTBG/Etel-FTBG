<?php
// ============================================================
// Etel Group super-page — Admin auth
// Single super-admin account (this is a small marketing site, not a
// multi-user system) - password hash + a signing secret both live in
// .env, never in code or the database. Tokens are stateless: a
// timestamp + HMAC signature, verified on every request without
// needing a sessions table. Simple, and enough for what this is.
//
// Required .env additions (generate the hash once locally with
// `php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"`):
//   ADMIN_PASSWORD_HASH=$2y$10$....
//   ADMIN_TOKEN_SECRET=some-long-random-string
// ============================================================
declare(strict_types=1);

define('EG_TOKEN_TTL', 60 * 60 * 12); // 12 hours

function eg_token_secret(): string {
    $s = getenv('ADMIN_TOKEN_SECRET') ?: '';
    if ($s === '') {
        // Fail loudly rather than silently signing with an empty/guessable
        // secret - that would make tokens forgeable.
        throw new Exception('ADMIN_TOKEN_SECRET is not configured in .env');
    }
    return $s;
}

function eg_issue_token(): string {
    $expires = time() + EG_TOKEN_TTL;
    $payload = 'admin|' . $expires;
    $sig = hash_hmac('sha256', $payload, eg_token_secret());
    return base64_encode($payload . '|' . $sig);
}

function eg_verify_token(?string $token): bool {
    if (!$token) return false;
    $decoded = base64_decode($token, true);
    if ($decoded === false) return false;
    $parts = explode('|', $decoded);
    if (count($parts) !== 3 || $parts[0] !== 'admin') return false;
    [$who, $expires, $sig] = $parts;
    $expected = hash_hmac('sha256', $who . '|' . $expires, eg_token_secret());
    if (!hash_equals($expected, $sig)) return false;
    if ((int)$expires < time()) return false;
    return true;
}

function eg_bearer_token(): ?string {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (stripos($hdr, 'Bearer ') === 0) return trim(substr($hdr, 7));
    // CSV export is a plain <a href> download link, which can't set a
    // custom Authorization header - accept the token as a query param
    // for that one case.
    if (!empty($_GET['token'])) return (string)$_GET['token'];
    return null;
}

function eg_require_admin(): void {
    if (!eg_verify_token(eg_bearer_token())) {
        fail('Not authenticated - please sign in again', 401);
    }
}

// Self-healing table create (same convention as Delivery/EtelTaxi) so
// the companies feature works immediately without a manual migration
// step, while api/migrations/001_companies.sql remains the documented,
// seeded version for a fresh deploy.
function eg_ensure_companies_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS companies (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(60) NOT NULL,
            name VARCHAR(100) NOT NULL,
            tagline VARCHAR(100) NOT NULL DEFAULT '',
            tagline_am VARCHAR(100) NOT NULL DEFAULT '',
            description_short VARCHAR(300) NOT NULL DEFAULT '',
            description_long VARCHAR(500) NOT NULL DEFAULT '',
            url VARCHAR(255) NOT NULL DEFAULT '',
            logo_path VARCHAR(255) NULL,
            icon_key VARCHAR(30) NOT NULL DEFAULT 'generic',
            dot_color VARCHAR(20) NOT NULL DEFAULT '#D4AF37',
            tags VARCHAR(200) NOT NULL DEFAULT '',
            status ENUM('live','coming_soon') NOT NULL DEFAULT 'coming_soon',
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_slug (slug),
            KEY idx_status (status),
            KEY idx_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// Known icon keys the admin can pick from - kept as a fixed small
// library (rendered client-side from a lookup table) rather than
// letting admins store raw SVG/HTML, which would be an XSS risk on a
// public page. Enforced here too, not just in the UI dropdown.
define('EG_ICON_KEYS', array('fuel', 'cart', 'truck', 'wallet', 'ai', 'id', 'taxi', 'laundry', 'media', 'luxline', 'generic'));

function eg_company_input(array $body): array {
    $slug = strtolower(trim((string)($body['slug'] ?? '')));
    $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
    $iconKey = trim((string)($body['icon_key'] ?? 'generic'));
    if (!in_array($iconKey, EG_ICON_KEYS, true)) $iconKey = 'generic';
    $status = ($body['status'] ?? 'coming_soon') === 'live' ? 'live' : 'coming_soon';
    $color = trim((string)($body['dot_color'] ?? '#D4AF37'));
    if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) $color = '#D4AF37';
    return array(
        'slug' => $slug,
        'name' => trim((string)($body['name'] ?? '')),
        'tagline' => trim((string)($body['tagline'] ?? '')),
        'tagline_am' => trim((string)($body['tagline_am'] ?? '')),
        'description_short' => trim((string)($body['description_short'] ?? '')),
        'description_long' => trim((string)($body['description_long'] ?? '')),
        'url' => trim((string)($body['url'] ?? '')),
        'logo_path' => trim((string)($body['logo_path'] ?? '')) ?: null,
        'icon_key' => $iconKey,
        'dot_color' => $color,
        'tags' => trim((string)($body['tags'] ?? '')),
        'status' => $status,
        'sort_order' => intval($body['sort_order'] ?? 0),
    );
}

// ============================================================
// EtelID cross-service SSO (real integration, not a mockup) -
// calls EtelID's own etelid/v1 REST API (see EtelID's
// class-etelid-service-api.php) using the shared X-Etel-Service-Key.
//
// Required .env additions:
//   ETELID_API_BASE=https://ethelmarket.com/EtelId/wp-json/etelid/v1
//   ETELID_SERVICE_KEY=<same value configured in EtelID's wp-config.php
//                        as ETELID_SERVICE_KEY>
//   ETELGROUP_COMPANY_SLUG=etelgroup   (must match the company_slug this
//                        admin is linked to in etelid_companies, with
//                        role_at admin/owner/creator)
// ============================================================

function egid_api_base(): string {
    $b = getenv('ETELID_API_BASE') ?: '';
    if ($b === '') throw new Exception('ETELID_API_BASE is not configured in .env');
    return rtrim($b, '/');
}

function egid_service_key(): string {
    $k = getenv('ETELID_SERVICE_KEY') ?: '';
    if ($k === '') throw new Exception('ETELID_SERVICE_KEY is not configured in .env');
    return $k;
}

function egid_post(string $path, array $body): array {
    $url = egid_api_base() . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'X-Etel-Service-Key: ' . egid_service_key(),
        ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) throw new Exception('Could not reach EtelID: ' . $err);
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new Exception('EtelID returned an unexpected response');
    return array('status' => $code, 'body' => $data);
}

// Authenticate identifier+password against EtelID itself (password
// never touches or is checked by EtelGroup - EtelID verifies its own
// pin_hash and just tells us yes/no plus the member record).
function egid_authenticate(string $identifier, string $password): array {
    $res = egid_post('/service/authenticate', array(
        'identifier' => $identifier,
        'password' => $password,
        'create_session' => true,
    ));
    if ($res['status'] !== 200 || empty($res['body']['ok'])) {
        $msg = $res['body']['message'] ?? ($res['body']['code'] ?? 'EtelID sign-in failed');
        throw new Exception(is_string($msg) ? $msg : 'EtelID sign-in failed');
    }
    return $res['body']['member'];
}

// Confirm this EtelID member is actually the registered admin/owner/
// creator for the EtelGroup company_slug - this IS the authorization
// check, tied to EtelID's own company-role system rather than a
// separate password EtelGroup would have to manage itself.
function egid_is_company_admin(string $memberPhone): bool {
    $slug = getenv('ETELGROUP_COMPANY_SLUG') ?: '';
    if ($slug === '') throw new Exception('ETELGROUP_COMPANY_SLUG is not configured in .env');
    $res = egid_post('/service/company_admin', array('company_slug' => $slug));
    if ($res['status'] !== 200 || empty($res['body']['ok'])) return false;
    $adminPhone = (string)($res['body']['phone'] ?? '');
    // Compare last 9 digits so formatting differences (+251/0/dashes)
    // don't cause false negatives.
    $norm = function ($p) { return substr(preg_replace('/[^0-9]/', '', $p), -9); };
    return $adminPhone !== '' && $norm($adminPhone) === $norm($memberPhone);
}

// ---------- centralized employee/role management ----------
// Lets Etel Group's own admin assign staff a role (any free-text role
// name - "Dispatcher", "Support", "Manager", "Traffic Officer", ...)
// at any sibling company, all from one screen, without needing wp-admin
// access to EtelID itself. This calls the same etelid_companies table
// that powers admin/owner login checks - it's the general-purpose
// version of that same mechanism.
function egid_list_company_roles(string $slug): array {
    $res = egid_post('/service/list_company_roles', array('company_slug' => $slug));
    if ($res['status'] !== 200 || empty($res['body']['ok'])) {
        throw new Exception($res['body']['message'] ?? 'Could not load roles from EtelID');
    }
    return $res['body']['roles'];
}

function egid_set_company_role(string $identifier, string $slug, string $role): array {
    $res = egid_post('/service/set_company_role', array(
        'identifier' => $identifier, 'company_slug' => $slug, 'role_at' => $role,
    ));
    if ($res['status'] !== 200 || empty($res['body']['ok'])) {
        throw new Exception($res['body']['message'] ?? 'Could not set role in EtelID');
    }
    return $res['body'];
}

function egid_remove_company_role(int $linkId): void {
    $res = egid_post('/service/remove_company_role', array('id' => $linkId));
    if ($res['status'] !== 200 || empty($res['body']['ok'])) {
        throw new Exception($res['body']['message'] ?? 'Could not remove role in EtelID');
    }
}

// Real registered-member count from EtelID, for the public homepage's
// member counter - replaces a client-side fake that seeded a number and
// ticked it up with Math.random(). Cached briefly in a flat file (no
// cache table needed for one integer) so the homepage doesn't call out
// to EtelID on every single page load.
function egid_member_count(): int {
    $cacheFile = sys_get_temp_dir() . '/etelgroup_member_count.json';
    $ttl = 300; // 5 minutes - "real, occasionally refreshed" beats "live", and beats hammering EtelID
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['count'])) return (int)$cached['count'];
    }
    $res = egid_post('/service/member_count', array());
    if ($res['status'] !== 200 || empty($res['body']['ok'])) {
        // EtelID unreachable - serve the last known-good cached count rather
        // than fail the whole homepage load over one non-critical number.
        if (is_file($cacheFile)) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['count'])) return (int)$cached['count'];
        }
        throw new Exception($res['body']['message'] ?? 'Could not reach EtelID for member count');
    }
    $count = (int)($res['body']['count'] ?? 0);
    @file_put_contents($cacheFile, json_encode(array('count' => $count)));
    return $count;
}
