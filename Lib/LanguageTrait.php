<?php
namespace FacturaScripts\Plugins\YeveaStore\Lib;

use FacturaScripts\Core\Tools;

/**
 * Provides multilingual support for public-facing controllers.
 *
 * Detects the visitor's language from the ?lang= query parameter or a cookie,
 * persists the choice, and provides helper methods for translating product and
 * category content via Translation/*.json keys with a Spanish DB fallback.
 */
trait LanguageTrait
{
    /** @var string Current language code (e.g. 'es_ES') */
    public $currentLang = 'es_ES';

    /** @var string Display label for the current language (e.g. 'español') */
    public $currentLangLabel = 'español';

    /**
     * Available languages: locale code => display label.
     * Locale codes match the Translation/*.json file names shipped with this plugin.
     */
    public $availableLanguages = [
        'es_ES' => 'español',
        'en_EN' => 'English',
        'fr_FR' => 'français',
        'de_DE' => 'Deutsch',
    ];

    /**
     * Detects the visitor's language and applies it to the FacturaScripts
     * translation engine.  Must be called early in run(), before any trans()
     * call or data loading that depends on translated content.
     *
     * Priority: 1) ?lang= query parameter  2) cookie  3) fallback (es_ES).
     */
    protected function detectAndSetLanguage(): void
    {
        $validLangs = array_keys($this->availableLanguages);
        $langCode = null;

        // 1. Explicit ?lang= query parameter (language switcher click)
        $langParam = $this->request()->query->get('lang', '');
        if (in_array($langParam, $validLangs, true)) {
            $langCode = $langParam;
        }

        // 2. Persisted cookie from a previous visit
        if ($langCode === null && isset($_COOKIE['yeveastore_lang'])) {
            $cookieLang = $_COOKIE['yeveastore_lang'];
            if (in_array($cookieLang, $validLangs, true)) {
                $langCode = $cookieLang;
            }
        }

        // 3. Fallback to Spanish
        if ($langCode === null) {
            $langCode = 'es_ES';
        }

        // Persist the choice in a cookie (1 year, functional cookie — no consent needed)
        if (!headers_sent()) {
            setcookie('yeveastore_lang', $langCode, [
                'expires' => time() + 365 * 24 * 3600,
                'path' => '/',
                'samesite' => 'Lax',
                'secure' => !empty($_SERVER['HTTPS']),
                'httponly' => false,
            ]);
        }

        // Apply to the FacturaScripts translation engine
        $translator = Tools::lang();
        if (method_exists($translator, 'setLang')) {
            $translator->setLang($langCode);
        } elseif (method_exists($translator, 'setDefaultLang')) {
            $translator->setDefaultLang($langCode);
        }

        $this->currentLang = $langCode;
        $this->currentLangLabel = $this->availableLanguages[$langCode]
            ?? strtoupper(substr($langCode, 0, 2));
    }

    /**
     * Returns a URL to the current page in a different language.
     * Adds/replaces the ?lang= parameter while preserving other query params.
     */
    public function langSwitchUrl(string $langCode): string
    {
        $query = $this->request()->query->all();
        $query['lang'] = $langCode;

        $controller = method_exists($this, 'controllerName')
            ? $this->controllerName()
            : basename(str_replace('\\', '/', static::class));

        return $controller . '?' . http_build_query($query);
    }

    /**
     * Translates a product name/description using translation keys derived
     * from the product referencia.  Falls back to the original DB value
     * (Spanish) when no translation key is found.
     *
     * Key pattern:  product-{REFERENCIA}-name  /  product-{REFERENCIA}-desc
     *
     * @return array{name: string, description: string}
     */
    protected function translateProduct(string $referencia, string $dbName, string $dbDescription): array
    {
        $nameKey = 'product-' . $referencia . '-name';
        $descKey = 'product-' . $referencia . '-desc';

        $translatedName = Tools::lang()->trans($nameKey);
        $translatedDesc = Tools::lang()->trans($descKey);

        return [
            'name' => ($translatedName !== $nameKey) ? $translatedName : $dbName,
            'description' => ($translatedDesc !== $descKey) ? $translatedDesc : $dbDescription,
        ];
    }

    /**
     * Translates category content (name, intro HTML, outro HTML) using
     * translation keys derived from the familia code.
     *
     * Key pattern:  family-{CODFAMILIA}-name / -intro / -outro
     *
     * @return array{descripcion: string, category_intro: string, category_outro: string}
     */
    protected function translateCategory(string $codfamilia, string $dbDescripcion, string $dbIntro, string $dbOutro): array
    {
        $nameKey  = 'family-' . $codfamilia . '-name';
        $introKey = 'family-' . $codfamilia . '-intro';
        $outroKey = 'family-' . $codfamilia . '-outro';

        $translatedName  = Tools::lang()->trans($nameKey);
        $translatedIntro = Tools::lang()->trans($introKey);
        $translatedOutro = Tools::lang()->trans($outroKey);

        return [
            'descripcion'    => ($translatedName !== $nameKey)   ? $translatedName  : $dbDescripcion,
            'category_intro' => ($translatedIntro !== $introKey) ? $translatedIntro : $dbIntro,
            'category_outro' => ($translatedOutro !== $outroKey) ? $translatedOutro : $dbOutro,
        ];
    }
}
