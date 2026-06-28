<?php
namespace FacturaScripts\Plugins\YeveaStore\Lib;

/**
 * Reusable slug-generation methods for URL-friendly identifiers.
 *
 * Provides two flavours:
 *  - generateSlug()        → PascalCase  (categories / template names)
 *  - generateProductSlug() → lowercase-hyphenated (product SEO URLs)
 */
trait SlugTrait
{
    private static array $transliterations = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'ñ' => 'n', 'ü' => 'u',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
        'Ñ' => 'N', 'Ü' => 'U',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
        'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o',
        'ç' => 'c', 'ß' => 'ss',
    ];

    /**
     * Generate a PascalCase URL slug from a category name.
     * E.g. "Tableros Mesa" → "TablerosMesa", "Artesanía" → "Artesania"
     */
    public static function generateSlug(string $text): string
    {
        $text = strtr($text, self::$transliterations);
        $text = preg_replace('/[^a-zA-Z0-9\s]/', '', $text);
        $text = str_replace(' ', '', ucwords($text));
        return $text;
    }

    /**
     * Generate a lowercase, hyphen-separated SEO-friendly slug from a product name.
     * E.g. "Tablero Mesa Olivo" → "tablero-mesa-olivo", "Artesanía Cuenco" → "artesania-cuenco"
     */
    public static function generateProductSlug(string $text): string
    {
        $text = strtr($text, self::$transliterations);
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
        $text = preg_replace('/[\s-]+/', '-', $text);
        $text = trim($text, '-');
        return $text;
    }
}
