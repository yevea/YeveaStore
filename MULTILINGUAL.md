# Making the Store Truly Multilingual

## Current State

The plugin already has solid **UI translation** infrastructure:

- **4 JSON translation files** (`Translation/en_EN.json`, `es_ES.json`, `fr_FR.json`, `de_DE.json`) with ~139 keys each covering all UI labels, buttons, messages, and status terms.
- **100% of UI-facing strings** in Twig templates use `{{ trans('key') }}` — no hardcoded UI text.
- **XML admin views** use translatable column names resolved automatically by FacturaScripts.
- **FacturaScripts Core** handles language selection based on the logged-in user's preference or server defaults.

However, the store is **not truly multilingual** because product and category **content** comes directly from the database in a single language (Spanish). The following sections describe what is missing and the possible approaches to close each gap.

---

## Gap 1 — Product Content (Names and Descriptions)

**Problem:** `producto.descripcion` (product name) and `producto.observaciones` (product description) are single-language fields in the FacturaScripts `productos` table. They are displayed as-is in `StoreFront.html.twig`, `ProductoDetalle.html.twig`, and `Tableros.html.twig`.

### Option A — Translation Table (recommended for dynamic catalogs)

Create a `productos_traducciones` table:

```
idproducto  | langcode | nombre                   | descripcion
----------- | -------- | ------------------------ | ----------------------------------
1           | es_ES    | Mesa de olivo            | Mesa rústica de madera de olivo
1           | en_EN    | Olive Wood Table         | Rustic olive wood table
1           | fr_FR    | Table en bois d'olivier  | Table rustique en bois d'olivier
1           | de_DE    | Olivenholztisch          | Rustikaler Olivenholztisch
```

In the controllers (`StoreFront.php`, `ProductoDetalle.php`), after loading each product, look up the user's current language and replace `$p->descripcion` / `$p->observaciones` with the translated values when available, falling back to the original Spanish.

**Pros:** Works for any number of products, content is managed per-product in the admin panel, fully dynamic.
**Cons:** Requires a new DB table, admin UI for entering translations, and lookup logic in every controller that displays products.

### Option B — Translation Keys in JSON files (suitable for small, fixed catalogs)

For stores with a small, stable catalog, each product can have a translation key prefix. For example, a product with `referencia = OLV-TABLE-01` would use keys `product-OLV-TABLE-01-name` and `product-OLV-TABLE-01-desc` in the JSON files:

```json
{
  "product-OLV-TABLE-01-name": "Olive Wood Table",
  "product-OLV-TABLE-01-desc": "Rustic olive wood table"
}
```

The controller would call `trans('product-' . $p->referencia . '-name')` instead of `$p->descripcion`.

**Pros:** No database changes, leverages existing FacturaScripts translation infrastructure, simple to implement.
**Cons:** Does not scale for large or frequently changing catalogs; every new product requires updating all JSON files.

### Option C — AI-Powered On-the-fly Translation

Use an external translation API (DeepL, Google Translate, LibreTranslate) to translate product text at runtime, with results cached in a simple key-value table.

**Pros:** Zero manual translation effort; works for any catalog size.
**Cons:** Depends on an external service, translation quality varies, adds latency (mitigated by caching), ongoing API costs.

---

## Gap 2 — Category Content (Names, Intro, Outro)

**Problem:** The `familias` table stores `descripcion`, `category_intro`, and `category_outro` as single-language fields. These are displayed directly in `Tableros.html.twig` (lines 64, 72, 181).

### Possible Approaches

The same three options from Gap 1 apply:

- **Translation table:** A `familias_traducciones` table with `codfamilia`, `langcode`, `descripcion`, `category_intro`, `category_outro`.
- **Translation keys:** Add keys like `family-TABLEROS-name`, `family-TABLEROS-intro`, `family-TABLEROS-outro` to the JSON files. The plugin already has some category description keys (`olive-wood-desc`, `wood-planks-desc`, etc.) in the translation files — they just are not currently used in the templates.
- **API translation:** Same as above, but note that `category_intro` and `category_outro` contain HTML, so the translation service must preserve markup.

The translation-key approach is particularly viable here because the number of categories is typically small and stable.

---

## Gap 3 — Public Visitor Language Selection

**Problem:** Public visitors (`$requiresAuth = false`) have no way to choose their language. FacturaScripts Core selects the language from the logged-in user's profile or the server default — anonymous visitors are stuck with whatever the server default is.

### Option A — URL-Based Language Prefix

Add a language parameter to the URL: `/en/StoreFront`, `/fr/Tableros`, `/de/ProductoDetalle/olive-wood-table`.

The `StoreFront` controller (and its subclasses) would read the prefix, store it in the session, and pass it to FacturaScripts' translation layer.

**Pros:** SEO-friendly (search engines index each language separately), bookmarkable, standard practice.
**Cons:** Requires URL routing changes, potentially a `.htaccess`/nginx rewrite rule, and updating all internal links.

### Option B — Query Parameter

Use `?lang=en_EN` on any page URL. The controller reads the parameter and sets the session language.

**Pros:** Simplest to implement, no routing changes needed.
**Cons:** Not as SEO-friendly, URLs look less clean.

### Option C — Language Switcher Widget + Cookie/Session

Add a language dropdown to `Header.html.twig` (e.g., flag icons or language codes). When clicked, it sets a cookie or session variable that the controllers read on subsequent requests to override the default language.

**Pros:** User-friendly, persistent across page loads, no URL changes needed.
**Cons:** Search engines see only one language unless combined with URL-based approach.

### Recommended Combination

Combine **Option A + Option C**: URL prefixes for SEO, plus a visible switcher in the header that redirects to the same page with the new language prefix.

---

## Gap 4 — SEO: Multilingual Slugs and hreflang Tags

**Problem:** `SlugTrait.php` generates slugs only from the Spanish `descripcion` field. Search engines cannot discover alternate-language versions of the same page.

### Slug Translation

Store language-specific slugs (either in the translation table from Gap 1 or as additional JSON keys):

```
es: mesa-de-olivo
en: olive-wood-table
fr: table-en-bois-d-olivier
de: olivenholztisch
```

`ProductoDetalle` would resolve the product by any of its language slugs and redirect to the canonical URL in the user's language.

### hreflang Tags

Add `<link rel="alternate" hreflang="..." href="...">` tags in the `<head>` of every public page:

```html
<link rel="alternate" hreflang="es" href="https://example.com/es/ProductoDetalle/mesa-de-olivo" />
<link rel="alternate" hreflang="en" href="https://example.com/en/ProductoDetalle/olive-wood-table" />
<link rel="alternate" hreflang="fr" href="https://example.com/fr/ProductoDetalle/table-en-bois-d-olivier" />
<link rel="alternate" hreflang="de" href="https://example.com/de/ProductoDetalle/olivenholztisch" />
<link rel="alternate" hreflang="x-default" href="https://example.com/ProductoDetalle/mesa-de-olivo" />
```

This tells Google and other search engines that the pages are translations of each other.

---

## Gap 5 — Variant Descriptions and Attribute Names

**Problem:** Variant descriptions are built from `AtributoValor` records (e.g., "Acabado Mate", "Color Natural") which are stored in a single language in the FacturaScripts `atributos_valores` table.

### Possible Approaches

- **Translation table** for `atributos_valores`: `idvalor`, `langcode`, `descripcion`.
- **Translation keys**: `attr-acabado-mate` → "Matte Finish" (EN), "Finition Mate" (FR), etc.
- Attribute names are typically a small, stable set — translation keys in JSON files are practical here.

---

## Gap 6 — Image Alt Text and Metadata

**Problem:** `ProductoImagen.descripcion_corta` and `ProductoImagen.observaciones` are single-language fields used for image `alt` attributes and carousel captions.

### Possible Approaches

- Add language-specific columns to `ProductoImagen` (e.g., `descripcion_corta_en`, `descripcion_corta_fr`) — simple but rigid.
- Use a translation table for image metadata — more flexible.
- Generate alt text from the product's translated name as a fallback (which the template already does: `img.alt` falls back to `product.name`).

---

## Gap 7 — Email and Notification Translation

**Problem:** Order confirmation emails and admin notifications may contain text in a single language.

### Possible Approach

Store the customer's preferred language (from the session/cookie at checkout time) in `yeveastore_orders.langcode`. When sending emails, use that language to select the appropriate template and translation keys.

---

## Gap 8 — Number and Currency Formatting

**Current state:** `fsc.formatMoney()` is used for price display, which respects FacturaScripts' locale settings. This is largely handled by the core and is **not a significant gap** — it mostly works already if the server locale matches the user's expectations.

For full correctness, ensure `formatMoney()` uses the visitor's locale (not just the server locale) when displaying prices on the public storefront.

---

## Implementation Priority

For the most impact with the least effort, the recommended order is:

| Priority | Gap | Effort | Impact |
|----------|-----|--------|--------|
| 1 | **Language switcher** (Gap 3, Option C) | Low | Visitors can choose their language for all UI strings |
| 2 | **Product content via translation keys** (Gap 1, Option B) | Low-Medium | Product names/descriptions appear in the correct language |
| 3 | **Category content via translation keys** (Gap 2) | Low | Category pages are fully translated |
| 4 | **hreflang tags** (Gap 4) | Low | Immediate SEO benefit |
| 5 | **Multilingual slugs** (Gap 4) | Medium | Language-specific SEO URLs |
| 6 | **Variant/attribute translation** (Gap 5) | Medium | Complete product detail translation |
| 7 | **Image metadata** (Gap 6) | Low | Accessibility and SEO |
| 8 | **Email translation** (Gap 7) | Medium | Better customer experience |

For a store with a large or frequently changing catalog, replace priority 2 with Gap 1 Option A (translation table) for long-term scalability.

---

## Summary

The plugin's UI translation layer (`trans()` + JSON files) is complete and well-implemented. The fundamental missing piece is **content translation** — product names, descriptions, category text, and variant attributes are stored in a single language in the database and displayed as-is to all visitors.

Making the store "truly multilingual" requires:

1. A mechanism for **visitors to select their language** (language switcher + session/cookie).
2. A strategy for **translating database content** (translation table or translation keys, depending on catalog size).
3. **SEO support** via hreflang tags and optionally multilingual URL slugs.

The existing translation infrastructure (JSON files, `trans()` function) provides a strong foundation — the remaining work is bridging the gap between UI translations and content translations.


---
---

# Chosen Solution — Detailed Implementation Analysis

> **Decision:** The following options have been selected for implementation:
> - **Gap 1** (Product Content): Option B — Translation Keys in JSON files
> - **Gap 2** (Category Content): Option B — Translation Keys in JSON files
> - **Gap 3** (Language Selection): Option A + C combined — Language Switcher Widget + Cookie/Session + SEO URLs
> - **Fallback language:** Spanish (es_ES) — if a translation key is missing, Spanish text is shown.

This section provides an exhaustive, file-by-file implementation analysis: exactly what changes are needed, where, why, potential pitfalls, and edge cases.

---

## 1. Gap 1 — Product Content via Translation Keys in JSON Files

### 1.1 Concept

Every product in the catalog gets two translation keys derived from its `referencia` field:

| Key pattern | Maps to | Current source |
|---|---|---|
| `product-{REFERENCIA}-name` | Product name | `productos.descripcion` |
| `product-{REFERENCIA}-desc` | Product description | `productos.observaciones` |

For example, a product with `referencia = OLV-TABLE-01`:

```json
// es_ES.json
{
  "product-OLV-TABLE-01-name": "Mesa de Olivo",
  "product-OLV-TABLE-01-desc": "Mesa rústica de madera de olivo maciza."
}

// en_EN.json
{
  "product-OLV-TABLE-01-name": "Olive Wood Table",
  "product-OLV-TABLE-01-desc": "Rustic solid olive wood table."
}
```

### 1.2 Fallback Behaviour

FacturaScripts' `trans()` function returns the key itself when no translation is found. Since the fallback must be Spanish (not the raw key), the implementation must handle missing translations explicitly:

```php
// In the controller, when building product objects:
$nameKey = 'product-' . $p->referencia . '-name';
$descKey = 'product-' . $p->referencia . '-desc';

$translatedName = Tools::lang()->trans($nameKey);
$translatedDesc = Tools::lang()->trans($descKey);

// If trans() returns the key itself, fall back to DB (Spanish)
$productObj->name = ($translatedName !== $nameKey) ? $translatedName : $p->descripcion;
$productObj->description = ($translatedDesc !== $descKey) ? $translatedDesc : ($p->observaciones ?? '');
```

This ensures:
- If the visitor is viewing in Spanish and `es_ES.json` has the key: translated Spanish is shown.
- If the visitor is viewing in Spanish and the key is missing: DB Spanish is shown (identical result).
- If the visitor is viewing in English and `en_EN.json` has the key: English is shown.
- If the visitor is viewing in English and the key is missing: DB Spanish is shown (fallback).

### 1.3 Files Requiring Changes

#### A. `Controller/StoreFront.php` — `loadProducts()` method (lines 395-407)

**Current code** (lines 395-407):
```php
$productObj = (object) [
    'referencia' => $p->referencia,
    'slug' => self::generateProductSlug($p->descripcion),
    'name' => $p->descripcion,
    'description' => $p->observaciones ?? '',
    // ...
];
```

**Required change:**
```php
$nameKey = 'product-' . $p->referencia . '-name';
$descKey = 'product-' . $p->referencia . '-desc';
$translatedName = Tools::lang()->trans($nameKey);
$translatedDesc = Tools::lang()->trans($descKey);

$productObj = (object) [
    'referencia' => $p->referencia,
    'slug' => self::generateProductSlug($p->descripcion),  // slug always from Spanish DB
    'name' => ($translatedName !== $nameKey) ? $translatedName : $p->descripcion,
    'description' => ($translatedDesc !== $descKey) ? $translatedDesc : ($p->observaciones ?? ''),
    // ...
];
```

**Critical detail — slug generation:** Product slugs MUST remain based on the Spanish `descripcion` from the DB (not the translated name) because:
1. Slugs are used for URL routing in `ProductoDetalle.loadProductBySlug()`.
2. That method compares slugs against `generateProductSlug($p->descripcion)` which reads the DB directly.
3. If slugs were generated from translated names, Spanish URLs would break for English visitors and vice versa.

This means the product URL will always be Spanish-based (e.g., `/ProductoDetalle?url=mesa-de-olivo`) regardless of the visitor's language. This is acceptable for now and is addressed separately in Gap 3 (SEO URLs).

#### B. `Controller/ProductoDetalle.php` — `loadProduct()` method (lines 110-121)

**Current code** (lines 110-121):
```php
$this->product = (object) [
    'referencia' => $p->referencia,
    'slug' => self::generateProductSlug($p->descripcion),
    'name' => $p->descripcion,
    'description' => $p->observaciones ?? '',
    // ...
];
```

**Required change:** Same pattern as StoreFront — replace `name` and `description` with `trans()` lookups with DB fallback.

#### C. `Controller/Presupuesto.php` — `resolveProductInfoByRef()` method (around line 669)

This method resolves a product reference to its display name for the shopping cart. It also needs the `trans()` lookup so that the cart page shows translated product names.

**Required change:** After loading the product from the DB, apply the same `trans()` lookup for the product name.

#### D. `Translation/es_ES.json`, `en_EN.json`, `fr_FR.json`, `de_DE.json`

Each file needs new keys for every product in the catalog. The keys follow the pattern:

```
"product-{REFERENCIA}-name": "...",
"product-{REFERENCIA}-desc": "..."
```

**Important:** The `es_ES.json` file MUST contain the Spanish versions matching the current DB values. This serves as the canonical Spanish translation and ensures consistency.

**Maintenance burden:** Every time a product is added or its referencia changes, all four JSON files must be updated. For the current small catalog this is manageable; for future growth, consider migrating to Option A (translation table).

### 1.4 Template Changes

**No Twig template changes are needed for Gap 1.** The templates already use `{{ product.name }}` and `{{ product.description }}` — the translation happens in the controller when building the product objects. This is the cleanest approach because:

1. Templates remain language-agnostic.
2. Schema.org structured data automatically gets the translated text.
3. All views (StoreFront, Tableros, ProductoDetalle) share the same translated objects.

### 1.5 Edge Cases and Risks

| Edge case | Impact | Mitigation |
|---|---|---|
| Product referencia contains special characters (e.g., `/`, `.`) | JSON key may be invalid or conflict | Sanitise referencia when building keys: replace non-alphanumeric chars with `-` |
| Referencia changes in FacturaScripts admin | Translation keys become orphaned | Document that key pattern is tied to referencia; update JSON files when referencia changes |
| Very long observaciones (product description) | JSON files become large | Acceptable for small catalogs; monitor file sizes |
| `trans()` returns the key when a new language is added | Raw key shown to visitor | The fallback logic (`$translatedName !== $nameKey`) catches this and shows DB Spanish |
| HTML in product descriptions (e.g., `<br>`, `<strong>`) | Must be preserved in translations | JSON values can contain HTML; translators must preserve markup |

### 1.6 Estimated Effort

| Task | Files | Lines changed (est.) |
|---|---|---|
| Controller changes (StoreFront, ProductoDetalle, Presupuesto) | 3 | ~30 |
| JSON translation entries (4 languages x N products x 2 keys) | 4 | ~8N lines per file |
| Testing and verification | — | 2-4 hours |

---

## 2. Gap 2 — Category Content via Translation Keys in JSON Files

### 2.1 Concept

Each category (familia) gets translation keys derived from its `codfamilia`:

| Key pattern | Maps to | Current source |
|---|---|---|
| `family-{CODFAMILIA}-name` | Category display name | `familias.descripcion` |
| `family-{CODFAMILIA}-intro` | Category introduction HTML | `familias.category_intro` |
| `family-{CODFAMILIA}-outro` | Category closing notes HTML | `familias.category_outro` |

Example for a category with `codfamilia = TABLEROS`:

```json
// es_ES.json
{
  "family-TABLEROS-name": "Tableros de Madera de Olivo",
  "family-TABLEROS-intro": "<p>Bienvenido a nuestra selección de tableros...</p>",
  "family-TABLEROS-outro": "<p>Todos los tableros se cortan a medida...</p>"
}

// en_EN.json
{
  "family-TABLEROS-name": "Olive Wood Boards",
  "family-TABLEROS-intro": "<p>Welcome to our selection of boards...</p>",
  "family-TABLEROS-outro": "<p>All boards are cut to your specifications...</p>"
}
```

### 2.2 Fallback Behaviour

Same pattern as Gap 1 — if `trans()` returns the key itself, fall back to the DB value (Spanish):

```php
$nameKey = 'family-' . $familia->codfamilia . '-name';
$introKey = 'family-' . $familia->codfamilia . '-intro';
$outroKey = 'family-' . $familia->codfamilia . '-outro';

$translatedName = Tools::lang()->trans($nameKey);
$translatedIntro = Tools::lang()->trans($introKey);
$translatedOutro = Tools::lang()->trans($outroKey);

'descripcion' => ($translatedName !== $nameKey) ? $translatedName : $familia->descripcion,
'category_intro' => ($translatedIntro !== $introKey) ? $translatedIntro : ($familia->category_intro ?? ''),
'category_outro' => ($translatedOutro !== $outroKey) ? $translatedOutro : ($familia->category_outro ?? ''),
```

### 2.3 Files Requiring Changes

#### A. `Controller/StoreFront.php` — `loadSelectedCategoryType()` (lines 282-308)

**Current code** (lines 295-306):
```php
$this->selectedCategoryFamily = (object) [
    'codfamilia' => $familia->codfamilia,
    'descripcion' => $familia->descripcion,
    // ...
    'category_intro' => $familia->category_intro ?? '',
    'category_outro' => $familia->category_outro ?? '',
];
```

**Required change:** Apply `trans()` lookups with DB fallback for `descripcion`, `category_intro`, and `category_outro`.

#### B. `Controller/StoreFront.php` — `loadCategories()` (lines 248-280)

The `loadCategories()` method returns raw `Familia` objects. The templates access `cat.descripcion` directly. This requires a different approach:

**Option 1 — Translate in the controller:** Build a parallel map of translated category names. Add a public property `$categoryNames` that maps `codfamilia` to its translated name.

**Option 2 — Translate in the template:** Use `trans()` in the Twig template:
```twig
{% set catNameKey = 'family-' ~ cat.codfamilia ~ '-name' %}
{% set catName = trans(catNameKey) %}
{{ catName != catNameKey ? catName : cat.descripcion }}
```

**Recommendation: Option 1** (translate in the controller). This keeps templates clean and is consistent with the product content approach.

#### C. `View/Header.html.twig` — line 14

**Current code:**
```twig
<li><a href="...">{{ cat.descripcion }}</a></li>
```

**Required change** (if using Option 1 from above):
```twig
<li><a href="...">{{ fsc.categoryNames[cat.codfamilia] | default(cat.descripcion) }}</a></li>
```

#### D. `View/Tableros.html.twig` — lines 64, 72, 181

These lines display `fsc.selectedCategoryFamily.descripcion`, `category_intro`, and `category_outro`. Since the controller already builds `selectedCategoryFamily` as an stdObject, the translation can be applied there, and **no template changes are needed** for these three values.

#### E. `Controller/Tableros.php` — `buildSlugMaps()` and `preResolveSlugToCategory()`

**Important:** Category slugs are generated from `$fam->descripcion` (Spanish DB value). Similar to product slugs, these MUST remain based on Spanish to preserve URL routing.

#### F. `Translation/es_ES.json`, `en_EN.json`, `fr_FR.json`, `de_DE.json`

Each file needs new keys for every category.

**HTML in intro/outro:** The `category_intro` and `category_outro` fields contain raw HTML. The translated values in JSON must also contain the full HTML. JSON values with HTML must be valid JSON strings (escape `"` as `\"`, no unescaped newlines). The `| raw` filter in Twig will render the HTML — **this is already the case** (Tableros.html.twig line 72).

### 2.4 Edge Cases and Risks

| Edge case | Impact | Mitigation |
|---|---|---|
| HTML in intro/outro | Must preserve tags in all languages | Provide translation templates; validate HTML in JSON |
| `category_custom_css` | CSS is language-independent | No translation needed — keep using DB value directly |
| Category added via admin panel | Missing translation keys | New category shows Spanish DB name (fallback works) |
| `codfamilia` contains spaces or special chars | JSON key may be awkward | FacturaScripts codfamilia is typically alphanumeric |

### 2.5 Existing Translation Keys Already Available

The JSON files already contain keys that could serve as category translations:

```json
"olive-wood": "Olive Wood",
"olive-wood-desc": "High-quality olive wood...",
"wood-planks": "Wood Planks",
"olive-wood-boards": "Olive Wood Boards",
"olive-wood-crafts": "Olive Wood Crafts"
```

These could be repurposed, but standardising on the `family-{CODFAMILIA}-*` pattern is recommended for consistency.

### 2.6 Estimated Effort

| Task | Files | Lines changed (est.) |
|---|---|---|
| Controller changes (StoreFront.loadSelectedCategoryType, loadCategories) | 1 | ~20 |
| Header.html.twig (category name display) | 1 | ~3 |
| JSON entries (4 languages x N categories x 3 keys) | 4 | ~12N lines per file |
| Testing and verification | — | 1-2 hours |

---

## 3. Gap 3 — Language Switcher Widget + Cookie/Session + SEO URLs (Option A + C)

This is the most complex gap. It combines three mechanisms:

1. **Language Switcher Widget** — a dropdown in the header for visitors to choose their language.
2. **Cookie/Session persistence** — the chosen language is remembered across page loads.
3. **SEO URLs** — URL-based language indicators so search engines see all languages.

### 3.1 Language Switcher Widget in Header.html.twig

#### Current Header Structure (lines 1-33)

```
+------------------------------------------+
| [Logo]                      [hamburger]  |
+------------------------------------------+
| (dropdown menu when hamburger clicked)   |
|  - Home                                  |
|  - All                                   |
|  - Category 1, Category 2               |
|  - Products, About, Contact, FAQ        |
+------------------------------------------+
```

#### Proposed Header Structure

The language dropdown is placed **to the left of** the hamburger icon, maintaining the existing layout:

```
+------------------------------------------+
| [Logo]                   [lang] [hambgr] |
+------------------------------------------+
```

#### Proposed Twig Code for Header.html.twig

```twig
<header id="yeveastore-header">
    <div class="container d-flex align-items-center justify-content-between">
        <a href="/"><img ... alt="logo"><sup>(R)</sup></a>
        <div class="d-flex align-items-center gap-2">
            {# Language switcher dropdown #}
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-light dropdown-toggle" type="button"
                        id="lang-switcher" data-bs-toggle="dropdown" aria-expanded="false">
                    {{ fsc.currentLangLabel }}
                </button>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="lang-switcher">
                    {% for code, label in fsc.availableLanguages %}
                    <li>
                        <a class="dropdown-item {% if code == fsc.currentLang %}active{% endif %}"
                           href="{{ fsc.langSwitchUrl(code) }}">
                            {{ label }}
                        </a>
                    </li>
                    {% endfor %}
                </ul>
            </div>
            <button type="button" id="hamburger-toggle" class="hamburger-toggle"
                    aria-label="Toggle menu" aria-expanded="false">&#9776;</button>
        </div>
    </div>
    <!-- hamburger menu unchanged -->
</header>
```

#### Dependencies

- **Bootstrap 5 dropdown** (`data-bs-toggle="dropdown"`) — FacturaScripts already includes Bootstrap 5 via `MenuTemplate.html.twig`, so no additional JS libraries are needed.

### 3.2 Controller Changes — Language Detection and Persistence

#### A. `Controller/StoreFront.php` — New Properties and Methods

**New public properties:**

```php
/** @var string Current language code (e.g., 'es_ES') */
public $currentLang = 'es_ES';

/** @var string Short label for the current language (e.g., 'ES') */
public $currentLangLabel = 'ES';

/** @var array Available languages: code => display label
 *  Note: locale codes (es_ES, en_EN, fr_FR, de_DE) follow the existing
 *  FacturaScripts convention used by the Translation/*.json files in this
 *  plugin. en_EN is non-standard (ISO would be en_GB or en_US) but matches
 *  the file already shipped as Translation/en_EN.json. */
public $availableLanguages = [
    'es_ES' => 'ES',
    'en_EN' => 'EN',
    'fr_FR' => 'FR',
    'de_DE' => 'DE',
];
```

**New method — language detection (called early in `run()`):**

```php
protected function detectAndSetLanguage(): void
{
    $validLangs = array_keys($this->availableLanguages);
    $langCode = null;

    // 1. Check ?lang= query parameter (explicit switch)
    $langParam = $this->request()->query->get('lang', '');
    if (in_array($langParam, $validLangs, true)) {
        $langCode = $langParam;
    }

    // 2. Check cookie (persisted preference)
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

    // Persist choice in cookie (1 year, SameSite=Lax, path=/)
    if (!headers_sent()) {
        setcookie('yeveastore_lang', $langCode, [
            'expires' => time() + 365 * 24 * 3600,
            'path' => '/',
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => false,
        ]);
    }

    // Apply to FacturaScripts translation engine
    Tools::lang()->setLang($langCode);

    $this->currentLang = $langCode;
    $this->currentLangLabel = strtoupper(substr($langCode, 0, 2));
}
```

**Placement in `run()` method:**

This method must be called **before** any `trans()` calls — including `loadProducts()`, `loadCategories()`, etc. The ideal placement:

```php
public function run(): void
{
    parent::run();
    $this->detectAndSetLanguage();  // Must be before any trans() or content loading
    // CSS loading, action handling, data loading...
}
```

**Important FacturaScripts API detail:** The actual mechanism to override the language for the current request needs investigation. FacturaScripts' `Tools::lang()` returns a `Translator` object. The method to change the active language may be:
- `Tools::lang()->setLang($code)` — if available
- `Tools::lang()->setDefaultLang($code)` — if the above does not exist
- Direct manipulation of the translation engine's internal state

This is a **critical implementation risk** — the exact API must be verified against the installed FacturaScripts version. If `Tools::lang()` does not expose a public method to change the language at runtime, a workaround would be needed (e.g., creating a new `Translator` instance or using `$_SESSION` to influence the language selection).

**Verification steps before implementation:**
1. Inspect `vendor/facturascripts/*/Core/Translation/` or `Core/Lib/Lang/` for the `Translator` class source code.
2. Check if `Tools::lang()` returns a singleton or creates new instances per call.
3. Search for `setLang`, `setDefaultLang`, or `setLanguage` methods in the `Translator` class.
4. If no public method exists, test whether setting `$_SESSION['fsLang']` or similar session keys before `parent::run()` influences the language. FacturaScripts' `Controller` base class may read the language from the session during initialisation.
5. As a last resort, the `Translator` class can be extended in the plugin's `Dinamic` layer to add a `setLang()` method.

#### B. Helper Method for Language-Switched URLs

```php
public function langSwitchUrl(string $langCode): string
{
    // For SEO URLs: build language-prefixed URL
    $shortCode = substr($langCode, 0, 2);
    $controller = $this->controllerName();
    $query = $this->request()->query->all();
    unset($query['lang']);

    $path = '/' . $shortCode . '/' . $controller;
    if (!empty($query)) {
        $path .= '?' . http_build_query($query);
    }
    return $path;
}
```

### 3.3 Cookie/Session Persistence

#### Cookie vs Session

| Aspect | Cookie | Session |
|---|---|---|
| Persistence | Survives browser close (with expiry) | Lost when session expires |
| SEO impact | None (not sent to crawlers) | None |
| Privacy | Requires consent in EU (GDPR) | Same |
| Implementation | `setcookie()` + `$_COOKIE` | `$_SESSION['lang']` |

**Recommendation:** Use a **cookie** (`yeveastore_lang`) with a 1-year expiry. The session already exists (for the cart) but session-based language would be lost when the session expires.

#### GDPR Consideration

A language preference cookie is classified as a **strictly necessary** functional cookie under GDPR and does not require consent. It is used to deliver the service in the user's requested language and does not track the user.

### 3.4 SEO URLs — Language-Prefixed Paths

#### URL Structure

Current URLs:
```
/StoreFront
/Tableros?cat=Tablones
/ProductoDetalle?url=mesa-de-olivo
```

Proposed multilingual URLs:
```
/es/StoreFront
/en/StoreFront
/es/Tableros?cat=Tablones
/en/Tableros?cat=Tablones
/es/ProductoDetalle?url=mesa-de-olivo
/en/ProductoDetalle?url=mesa-de-olivo
```

#### Implementation via `.htaccess` / Web Server Rewrite

FacturaScripts uses Apache with `.htaccess` for URL routing. The language prefix needs to be extracted before FacturaScripts' router processes the request:

```apache
# .htaccess addition (BEFORE existing FacturaScripts rules)
RewriteEngine On
RewriteRule ^es/(.*)$ $1?lang=es_ES [QSA,L]
RewriteRule ^en/(.*)$ $1?lang=en_EN [QSA,L]
RewriteRule ^fr/(.*)$ $1?lang=fr_FR [QSA,L]
RewriteRule ^de/(.*)$ $1?lang=de_DE [QSA,L]
```

**Important:** The 2-letter to locale mapping is not straightforward (`en` maps to `en_EN` not `en_US`). The rewrite rules must map to the exact locale codes used by the JSON files.

**Alternative — No .htaccess, Controller-Only:** The controller could parse the URL path directly, but FacturaScripts routes by controller class name, so `/en/StoreFront` would not match the `StoreFront` controller without a rewrite rule. **Use `.htaccess` rewrite rules.**

#### hreflang Tags

Every public page should include `<link rel="alternate" hreflang="...">` tags:

```twig
{% for code, label in fsc.availableLanguages %}
<link rel="alternate" hreflang="{{ code[:2] }}" href="{{ fsc.langSwitchUrl(code) }}">
{% endfor %}
<link rel="alternate" hreflang="x-default" href="{{ fsc.langSwitchUrl('es_ES') }}">
```

Or inject via the controller using `AssetManager::addCustom('head', ...)` to keep templates clean.

### 3.5 Files Requiring Changes

| File | Change | Effort |
|---|---|---|
| `View/Header.html.twig` | Add language dropdown next to hamburger | 15-20 lines |
| `Assets/CSS/yeveastore.css` | Style the language dropdown (minimal, Bootstrap handles most) | 5-10 lines |
| `Controller/StoreFront.php` | Add `detectAndSetLanguage()`, `langSwitchUrl()`, `localizedAsset()`, new properties | 40-60 lines |
| `Controller/ProductoDetalle.php` | Inherits from StoreFront — no additional changes needed | 0 |
| `Controller/Tableros.php` | Inherits from StoreFront — no additional changes needed | 0 |
| `Controller/Presupuesto.php` | Inherits from StoreFront — no additional changes needed | 0 |
| `.htaccess` (or equivalent) | Rewrite rules for language-prefixed URLs | 4-8 lines |
| All Twig templates | Add hreflang in head block (or via controller injection) | 5-10 lines each OR 0 if via controller |

### 3.6 Internal Link Updates

All internal links in templates must include the language prefix for SEO URLs to work:

**Current:**
```twig
<a href="{{ asset('Tableros') }}?cat={{ slug }}">
<a href="{{ asset('ProductoDetalle') }}?url={{ product.slug }}">
```

**Required (with SEO URLs):**
```twig
<a href="{{ fsc.localizedAsset('Tableros') }}?cat={{ slug }}">
<a href="{{ fsc.localizedAsset('ProductoDetalle') }}?url={{ product.slug }}">
```

Add a helper method in the controller:

```php
public function localizedAsset(string $controller): string
{
    $shortCode = substr($this->currentLang, 0, 2);
    return '/' . $shortCode . '/' . $controller;
}
```

**This is a pervasive change** — every `{{ asset('ControllerName') }}` in the public templates must be updated. Affected files:
- `View/Header.html.twig` (4+ links)
- `View/StoreFront.html.twig` (3+ links)
- `View/Tableros.html.twig` (5+ links)
- `View/ProductoDetalle.html.twig` (3+ links)
- `View/Presupuesto.html.twig` (2+ links)

### 3.7 Edge Cases and Risks

| Edge case | Impact | Risk | Mitigation |
|---|---|---|---|
| `Tools::lang()->setLang()` not available | Cannot change language at runtime | HIGH | Research FacturaScripts Translator API; fallback to session-based approach |
| Crawlers ignoring cookies | See only default language | LOW | SEO URLs solve this — crawlers follow language-prefixed links |
| Visitor with JS disabled | Bootstrap dropdown may not work | MEDIUM | Implement language switcher as a `<form>` with `<select>` and a submit button that works without JS; enhance with Bootstrap dropdown styling via progressive enhancement. The no-JS form should `GET` to the current page with `?lang=` parameter |
| Language prefix in POST form actions | Forms may break | MEDIUM | Ensure `<form action="...">` includes the language prefix |
| Admin panel (logged-in users) | Admin should use their own language, not the public cookie | LOW | `detectAndSetLanguage()` should only apply when `$requiresAuth === false` |
| Browser Accept-Language header | Not used for initial detection | LOW | Could enhance later; currently defaults to Spanish |
| `?lang=` parameter pollution | Arbitrary language codes injected | LOW | Validation ensures only valid codes are accepted |

### 3.8 Estimated Effort

| Task | Effort |
|---|---|
| Language switcher widget (Header.html.twig + CSS) | 2-3 hours |
| Controller language detection and persistence | 3-4 hours |
| SEO URL rewrite rules (.htaccess) | 1-2 hours |
| Internal link updates (all templates) | 2-3 hours |
| hreflang tag injection | 1 hour |
| Testing across 4 languages + SEO verification | 4-6 hours |
| **Total** | **13-19 hours** |

---

## 4. Cross-Cutting Concerns

### 4.1 Schema.org Structured Data

The JSON-LD blocks in `StoreFront.html.twig`, `Tableros.html.twig`, and `ProductoDetalle.html.twig` use `{{ product.name }}` and `{{ product.description }}`. Since the controller now populates these with translated values, **structured data will automatically be in the visitor's language**.

For multi-language SEO, add `"inLanguage": "{{ fsc.currentLang[:2] }}"` to the JSON-LD blocks:

```json
{
    "@type": "Product",
    "name": "...",
    "inLanguage": "en",
    ...
}
```

### 4.2 Product Slug Stability

Product slugs are generated from `$p->descripcion` (Spanish DB value) via `SlugTrait::generateProductSlug()`. This is used for:
1. URL generation: `?url=mesa-de-olivo`
2. Product lookup: `loadProductBySlug()` compares input slug against `generateProductSlug($p->descripcion)`

**The slug must remain Spanish-based** to avoid breaking URL routing. All language versions of a product will share the same Spanish slug:
```
/es/ProductoDetalle?url=mesa-de-olivo  -> Shows Spanish content
/en/ProductoDetalle?url=mesa-de-olivo  -> Shows English content
/fr/ProductoDetalle?url=mesa-de-olivo  -> Shows French content
```

This is suboptimal for SEO (Google prefers localised slugs) but is acceptable for the initial implementation. Localised slugs could be added later using translation keys like `product-OLV-TABLE-01-slug`.

### 4.3 Category Slug Stability

Same concern as product slugs — category slugs are generated from `$fam->descripcion`. The `preResolveSlugToCategory()` in `Tableros.php` must continue to work. Category slugs remain Spanish-based.

### 4.4 Shopping Cart Language Consistency

When a visitor adds items to their cart in English and then switches to Spanish:
- Cart item names should update to Spanish (since `Presupuesto.php` re-resolves product names at render time).
- If the name resolution applies `trans()` based on the current language, the cart will be consistent.

However, if a Spanish admin views the order in the admin panel, they should see the original Spanish names (since the admin panel uses the admin's language). This is already the case if the admin's language is set to Spanish in FacturaScripts.

### 4.5 Performance Impact

| Operation | Additional cost | Impact |
|---|---|---|
| `trans()` lookup for each product | Hash table lookup, O(1) | Negligible |
| `trans()` lookup for each category | Hash table lookup, O(1) | Negligible |
| Cookie read/write | Single cookie, ~20 bytes | Negligible |
| hreflang tag generation | 4 string concatenations per page | Negligible |
| SEO URL rewrite | Single regex match per request | Negligible |

**Total performance impact: Negligible.** The JSON translation files are loaded once per request by FacturaScripts Core and cached in memory.

---

## 5. Complete File Change Inventory

### Files Modified

| # | File | Change Description | Gap |
|---|---|---|---|
| 1 | `Controller/StoreFront.php` | Add `detectAndSetLanguage()`, `langSwitchUrl()`, `localizedAsset()`, language properties; modify `loadProducts()` and `loadSelectedCategoryType()` for `trans()` lookups | 1, 2, 3 |
| 2 | `Controller/ProductoDetalle.php` | Modify `loadProduct()` for `trans()` lookups on product name/description | 1 |
| 3 | `Controller/Presupuesto.php` | Modify `resolveProductInfoByRef()` for `trans()` lookups | 1 |
| 4 | `View/Header.html.twig` | Add language dropdown widget next to hamburger icon | 3 |
| 5 | `View/StoreFront.html.twig` | Update internal links to use `localizedAsset()` | 3 |
| 6 | `View/Tableros.html.twig` | Update internal links to use `localizedAsset()` | 3 |
| 7 | `View/ProductoDetalle.html.twig` | Update internal links to use `localizedAsset()` | 3 |
| 8 | `View/Presupuesto.html.twig` | Update internal links to use `localizedAsset()` | 3 |
| 9 | `Assets/CSS/yeveastore.css` | Style language dropdown (minimal) | 3 |
| 10 | `Translation/es_ES.json` | Add `product-*-name`, `product-*-desc`, `family-*-name/intro/outro` keys | 1, 2 |
| 11 | `Translation/en_EN.json` | Add same keys with English translations | 1, 2 |
| 12 | `Translation/fr_FR.json` | Add same keys with French translations | 1, 2 |
| 13 | `Translation/de_DE.json` | Add same keys with German translations | 1, 2 |

### Files Potentially Created

| # | File | Purpose | Gap |
|---|---|---|---|
| 14 | `.htaccess` modifications (or new file) | Language prefix rewrite rules | 3 |

### Files NOT Modified

| File | Reason |
|---|---|
| `Controller/Tableros.php` | Inherits language detection from StoreFront; slug resolution remains Spanish-based |
| `Lib/SlugTrait.php` | Slugs remain Spanish-based; no changes needed |
| `Init.php` | No initialization changes needed |
| `Model/*.php` | No database changes |
| `Extension/Table/*.xml` | No schema changes |

---

## 6. Implementation Order (Recommended)

1. **Phase 1 — Language Switcher (Gap 3, partial):** Add `detectAndSetLanguage()` to `StoreFront.php`, the language dropdown to `Header.html.twig`, and cookie persistence. This immediately lets visitors switch the UI language (all `trans()` keys work).

2. **Phase 2 — Product Translations (Gap 1):** Add `product-*-name/desc` keys to all four JSON files. Modify `loadProducts()` and `loadProduct()` to use `trans()` with DB fallback.

3. **Phase 3 — Category Translations (Gap 2):** Add `family-*-name/intro/outro` keys to all four JSON files. Modify `loadSelectedCategoryType()` and category rendering.

4. **Phase 4 — SEO URLs (Gap 3, completion):** Add `.htaccess` rewrite rules, update all internal links to use `localizedAsset()`, and inject hreflang tags.

This order delivers incremental value at each phase and allows testing between phases.

---

## 7. Testing Strategy

### Manual Testing Checklist

- [ ] Language switcher visible next to hamburger in header on all pages
- [ ] Clicking a language sets the cookie and reloads in that language
- [ ] UI strings (buttons, labels, messages) switch language correctly
- [ ] Product names and descriptions switch language correctly
- [ ] Category names, intro, and outro switch language correctly
- [ ] Missing translation key falls back to Spanish (not the raw key)
- [ ] Cart preserves items when language is switched
- [ ] Cart shows product names in the current language
- [ ] SEO URLs (`/en/StoreFront`) route correctly
- [ ] hreflang tags present in page source
- [ ] Schema.org structured data uses translated text
- [ ] Admin panel is not affected by public language cookie
- [ ] Dimension filters on Tableros page work with language prefix
- [ ] Product slug URLs work from all language versions

### Automated Testing

Given the absence of existing test infrastructure in this plugin, manual testing is the primary approach. Future consideration: add PHPUnit tests for the `detectAndSetLanguage()` method and the `trans()` fallback logic.
