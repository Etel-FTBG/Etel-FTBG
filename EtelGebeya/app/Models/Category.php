<?php
declare(strict_types=1);
namespace App\Models;

/**
 * Category -- for fetching category trees.
 */
final class Category
{
    public readonly int $id;
    public readonly ?int $parentId;
    public readonly string $nameEn;
    public readonly string $nameAm;
    public readonly string $slug;
    public readonly ?string $icon;
    public readonly int $sortOrder;
    public readonly bool $isActive;

    public function __construct(array $row)
    {
        $this->id       = (int)($row['id'] ?? 0);
        $this->parentId = isset($row['parent_id']) && $row['parent_id'] !== null ? (int)$row['parent_id'] : null;
        $this->nameEn   = $row['name_en'] ?? '';
        $this->nameAm   = $row['name_am'] ?? $row['name_en'] ?? '';
        $this->slug     = $row['slug'] ?? '';
        $this->icon     = $row['icon'] ?? null;
        $this->sortOrder = (int)($row['sort_order'] ?? 0);
        $this->isActive  = !empty($row['is_active']);
    }

    public function name(string $locale = 'en'): string
    {
        return $locale === 'am' ? $this->nameAm : $this->nameEn;
    }

    public static function all(): array
    {
        try {
            $rows = $GLOBALS['__db']->fetchAll(
                "SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order, name_en"
            );
        } catch (\Throwable $_) { $rows = []; }
        return array_map(fn($r) => new self($r), $rows);
    }

    public static function topLevel(): array
    {
        try {
            // Try with parent_id column
            $rows = $GLOBALS['__db']->fetchAll(
                "SELECT * FROM categories WHERE is_active = 1 AND parent_id IS NULL ORDER BY sort_order"
            );
        } catch (\Throwable $_) {
            // Fall back if parent_id column missing
            try {
                $rows = $GLOBALS['__db']->fetchAll(
                    "SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order"
                );
            } catch (\Throwable $_) { $rows = []; }
        }
        return array_map(fn($r) => new self($r), $rows);
    }

    public static function bySlug(string $slug): ?self
    {
        try {
            $row = $GLOBALS['__db']->fetch(
                "SELECT * FROM categories WHERE slug = ? AND is_active = 1", [$slug]
            );
        } catch (\Throwable $_) { $row = null; }
        return $row ? new self($row) : null;
    }

    public static function childrenOf(int $parentId): array
    {
        try {
            $rows = $GLOBALS['__db']->fetchAll(
                "SELECT * FROM categories WHERE parent_id = ? AND is_active = 1 ORDER BY sort_order",
                [$parentId]
            );
        } catch (\Throwable $_) { $rows = []; }
        return array_map(fn($r) => new self($r), $rows);
    }
}
