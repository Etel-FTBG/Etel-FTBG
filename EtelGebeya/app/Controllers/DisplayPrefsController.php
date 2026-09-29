<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;

/**
 * Display size preferences (text / icon / button / logo size + boldness,
 * see public/assets/js/etel-display-settings.js), stored per signed-in
 * account so the choice follows the person across all of their devices.
 *
 * The owner is ALWAYS taken from the session ('u' + user id), never from
 * the request, so one account can never read or overwrite another's
 * setting. The table is created on first use (this app has no migration
 * runner), shared with api/admin.php which stores admin-session settings
 * in the same table under 'a<admin id>' / 'master' keys.
 */
final class DisplayPrefsController
{
    private Auth $auth;
    private Database $db;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->db   = Database::getInstance();
    }

    private function json(array $payload, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($payload);
    }

    private function ensureTable(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $this->db->pdo()->exec(
                "CREATE TABLE IF NOT EXISTS user_display_prefs (
                    owner_key  VARCHAR(64)  NOT NULL,
                    prefs_json VARCHAR(255) NOT NULL,
                    version    INT UNSIGNED NOT NULL DEFAULT 1,
                    updated_at DATETIME     NOT NULL,
                    PRIMARY KEY (owner_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (\Throwable $e) {
            error_log('[gebeya][display_prefs] create table: ' . $e->getMessage());
        }
    }

    /** Clamp to the ranges the client uses: sizes 70-200 %, boldness 0-2. */
    private function clean(mixed $p): ?array
    {
        if (!is_array($p)) return null;
        $out = [];
        foreach (['ts', 'is', 'bs', 'ls'] as $k) {
            $out[$k] = max(70, min(200, (int)($p[$k] ?? 100)));
        }
        $out['fw'] = max(0, min(2, (int)($p['fw'] ?? 0)));
        return $out;
    }

    private function ownerKey(): ?string
    {
        if (!$this->auth->check()) return null;
        $id = (int)$this->auth->id();
        return $id > 0 ? 'u' . $id : null;
    }

    /* GET /display-prefs */
    public function get(): void
    {
        $owner = $this->ownerKey();
        if ($owner === null) { $this->json(['ok' => false, 'login_required' => true], 401); return; }
        $this->ensureTable();
        try {
            $row = $this->db->fetch(
                "SELECT prefs_json, version FROM user_display_prefs WHERE owner_key = ? LIMIT 1",
                [$owner]
            );
        } catch (\Throwable $e) {
            error_log('[gebeya][display_prefs] get: ' . $e->getMessage());
            $this->json(['ok' => false, 'error' => 'Could not load display settings'], 500);
            return;
        }
        if (!$row) { $this->json(['ok' => true, 'prefs' => null, 'v' => 0]); return; }
        $this->json([
            'ok'    => true,
            'prefs' => $this->clean(json_decode((string)$row['prefs_json'], true)),
            'v'     => (int)$row['version'],
        ]);
    }

    /* POST /display-prefs   (_token = CSRF, prefs = JSON) */
    public function save(): void
    {
        $owner = $this->ownerKey();
        if ($owner === null) { $this->json(['ok' => false, 'login_required' => true], 401); return; }
        if (!$this->auth->csrfCheck($_POST['_token'] ?? null)) {
            $this->json(['ok' => false, 'error' => 'Session expired'], 403);
            return;
        }
        $prefs = $this->clean(json_decode((string)($_POST['prefs'] ?? ''), true));
        if ($prefs === null) { $this->json(['ok' => false, 'error' => 'Invalid display settings'], 400); return; }

        $this->ensureTable();
        $json = json_encode($prefs);
        $now  = date('Y-m-d H:i:s');
        try {
            // UPDATE first (bumps version); INSERT only when the account has no row yet.
            // A simultaneous first save from two devices can hit the primary key - then UPDATE again.
            $up = $this->db->query(
                "UPDATE user_display_prefs SET prefs_json = ?, version = version + 1, updated_at = ? WHERE owner_key = ?",
                [$json, $now, $owner]
            );
            if ($up->rowCount() === 0) {
                try {
                    $this->db->query(
                        "INSERT INTO user_display_prefs (owner_key, prefs_json, version, updated_at) VALUES (?, ?, 1, ?)",
                        [$owner, $json, $now]
                    );
                } catch (\Throwable $e2) {
                    $this->db->query(
                        "UPDATE user_display_prefs SET prefs_json = ?, version = version + 1, updated_at = ? WHERE owner_key = ?",
                        [$json, $now, $owner]
                    );
                }
            }
            $row = $this->db->fetch("SELECT version FROM user_display_prefs WHERE owner_key = ? LIMIT 1", [$owner]);
        } catch (\Throwable $e) {
            error_log('[gebeya][display_prefs] save: ' . $e->getMessage());
            $this->json(['ok' => false, 'error' => 'Could not save display settings'], 500);
            return;
        }
        $this->json(['ok' => true, 'prefs' => $prefs, 'v' => (int)($row['version'] ?? 1)]);
    }
}
