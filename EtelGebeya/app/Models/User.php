<?php
declare(strict_types=1);
namespace App\Models;

/**
 * User -- read-only model for fetching user records.
 */
final class User
{
    public readonly ?int $id;
    public readonly string $fullName;
    public readonly string $email;
    public readonly ?string $phone;
    public readonly string $locale;
    public readonly ?string $avatar;
    public readonly string $status;

    public function __construct(array $row)
    {
        $this->id       = isset($row['id']) ? (int)$row['id'] : null;
        $this->fullName = $row['full_name'] ?? '';
        $this->email    = $row['email'] ?? '';
        $this->phone    = $row['phone'] ?? null;
        $this->locale   = $row['locale'] ?? 'en';
        $this->avatar   = $row['avatar'] ?? null;
        $this->status   = $row['status'] ?? 'active';
    }

    public static function find(int $id): ?self
    {
        $row = $GLOBALS['__db']->fetch(
            "SELECT * FROM users WHERE id = ?", [$id]
        );
        return $row ? new self($row) : null;
    }

    public static function findByEmail(string $email): ?self
    {
        $row = $GLOBALS['__db']->fetch(
            "SELECT * FROM users WHERE email = ?", [$email]
        );
        return $row ? new self($row) : null;
    }

    public function isAdmin(): bool
    {
        $row = $GLOBALS['__db']->fetch(
            "SELECT 1 FROM user_roles WHERE user_id = ? AND role = 'admin'", [$this->id]
        );
        return $row !== null;
    }

    public function isVendor(): bool
    {
        $row = $GLOBALS['__db']->fetch(
            "SELECT 1 FROM user_roles WHERE user_id = ? AND role = 'vendor'", [$this->id]
        );
        return $row !== null;
    }

    public function isCourier(): bool
    {
        $row = $GLOBALS['__db']->fetch(
            "SELECT 1 FROM user_roles WHERE user_id = ? AND role = 'courier'", [$this->id]
        );
        return $row !== null;
    }

    public function avatarUrl(): string
    {
        if ($this->avatar) {
            return '/storage/uploads/' . ltrim($this->avatar, '/');
        }
        $initial = mb_substr($this->fullName, 0, 1, 'UTF-8');
        return "https://ui-avatars.com/api/?name=" . urlencode($initial) . "&background=2563eb&color=fff&size=64";
    }
}
