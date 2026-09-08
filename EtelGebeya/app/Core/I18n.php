<?php
declare(strict_types=1);
namespace App\Core;

/**
 * I18n -- Amharic + English. Loads JSON catalogs from app/Views/i18n/.
 *
 * Translator can also be expanded with gettext, but JSON keeps it simple.
 *   t('cart.add')  ?  "Add to cart" / "?? ?? ???"
 */
final class I18n
{
    private static ?self $instance = null;
    private array $catalogs = [];
    private string $locale;

    private function __construct()
    {
        $supported = $GLOBALS['__config']['app']['locales'] ?? ['en', 'am'];
        $this->locale = $_SESSION['locale'] ?? ($GLOBALS['__config']['app']['locale'] ?? 'en');
        if (!in_array($this->locale, $supported, true)) {
            $this->locale = 'en';
        }
        $this->load('en');
        if ($this->locale !== 'en') {
            $this->load($this->locale);
        }
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function locale(): string { return $this->locale; }

    public function setLocale(string $locale): void
    {
        $supported = $GLOBALS['__config']['app']['locales'] ?? ['en', 'am'];
        if (!in_array($locale, $supported, true)) return;
        $this->locale = $locale;
        $_SESSION['locale'] = $locale;
        $this->catalogs = ['en' => $this->loadFile('en')];
        if ($locale !== 'en') $this->catalogs[$locale] = $this->loadFile($locale);
    }

    /** t('key', ['name' => 'Abebe']) */
    public function t(string $key, array $placeholders = []): string
    {
        $s = $this->catalogs[$this->locale][$key]
            ?? $this->catalogs['en'][$key]
            ?? $key;
        foreach ($placeholders as $k => $v) {
            $s = str_replace(':' . $k, (string)$v, $s);
        }
        return $s;
    }

    private function load(string $locale): void
    {
        $this->catalogs[$locale] = $this->loadFile($locale);
    }

    private function loadFile(string $locale): array
    {
        $file = __DIR__ . '/../Views/i18n/' . $locale . '.json';
        if (!is_file($file)) return [];
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }
}

/** Global helper. */
function t(string $key, array $placeholders = []): string
{
    return I18n::getInstance()->t($key, $placeholders);
}
