# Multilingual Support — Current State

The store supports Spanish, English, French and German across both UI strings and content.

## What's implemented

- **UI translations** — 4 JSON files (`Translation/{es_ES,en_EN,fr_FR,de_DE}.json`), ~230 keys, no hardcoded UI text in public Twig templates. Customer-facing keys (store + capture PWA) exist in all four languages; admin-only keys (the `calc-*` price-table tab) are complete in es/en only — the admin UI runs in Spanish.
- **Visitor language selection** — `Lib/LanguageTrait.php::detectAndSetLanguage()`, called early by `Lib/StoreControllerBase.php`. Priority: `?lang=` query param → `yeveastore_lang` cookie (1 year, functional, no GDPR consent needed) → fallback `es_ES`.
- **Language switcher widget** — in `View/Header.html.twig`, links to the current page with `?lang=` set via `langSwitchUrl()`.
- **Product content translation** — `LanguageTrait::translateProduct()` looks up `product-{REFERENCIA}-name` / `-desc` keys, falling back to the Spanish DB value (`productos.descripcion` / `observaciones`) when a key is missing.
- **Category content translation** — `LanguageTrait::translateCategory()` looks up `family-{CODFAMILIA}-name` / `-intro` / `-outro`, same Spanish-DB fallback.
- **hreflang tags** — `View/Hreflang.html.twig`, included in public templates; one `<link rel="alternate">` per language plus `x-default`, all pointing at `?lang=` URLs (see below).
- **Slugs stay Spanish-only** — `Lib/SlugTrait.php` always generates slugs from the Spanish DB field, regardless of visitor language, so `/producto?url=...` and `/productos?cat=...` never change across languages.

## Deliberately not implemented

- **`/es/` `/en/` `/fr/` `/de/` URL path prefixes** — considered (see git history) but dropped in favour of the simpler `?lang=` query-param + cookie approach, paired with the lowercase SEO route scheme already in place (`/productos`, `/producto`, `/presupuesto` — see [CLAUDE.md](CLAUDE.md)). Revisit only if hreflang-per-URL-path becomes a real SEO requirement.
- **Translation table in the DB** — translation keys in JSON files were chosen over a `productos_traducciones`/`familias_traducciones` table since the catalogue is small and stable. Revisit if the catalogue grows large or changes frequently.
- **Email/notification translation** — order confirmation emails are not yet localised per customer language.

## Maintenance note

Every new product or category needs matching `product-{REF}-name/-desc` or `family-{COD}-name/-intro/-outro` keys added to all four JSON files (or it silently falls back to Spanish, which is safe but untranslated).
