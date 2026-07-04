<?php
namespace FacturaScripts\Plugins\YeveaStore\Controller;

use FacturaScripts\Plugins\YeveaStore\Lib\StoreControllerBase;

/**
 * llms.txt — a plain-markdown summary of the store for LLM agents
 * (https://llmstxt.org). Generated live from the catalogue so it never
 * goes stale.
 *
 * URL: /LlmsTxt — when the store runs at the domain root, also expose it
 * as /llms.txt (rewrite or copy), which is where agents look for it.
 */
class LlmsTxt extends StoreControllerBase
{
    /** Cap the product list so the file stays digestible for agents */
    private const MAX_PRODUCTS = 100;

    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['title'] = 'llms-txt';
        return $pageData;
    }

    public function run(): void
    {
        parent::run();
        $this->loadCategories();
        $this->loadProducts();

        $base = $this->baseUrl();

        $out = [];
        $out[] = '# Yevea — Madera de Olivo / Olive Wood';
        $out[] = '';
        $out[] = '> ' . $this->t('sawmill-desc');
        $out[] = '> Spanish olive wood sawmill: planks, custom-cut boards, rustic bathroom and kitchen countertops, cutting boards and handcrafted olive wood items. Ships to Spain and the whole European Union.';
        $out[] = '';
        $out[] = '## Categorías / Categories';
        $out[] = '';
        foreach ($this->categories as $cat) {
            $name = $this->categoryNames[$cat->codfamilia] ?? $cat->descripcion;
            $slug = $this->slugMap[$cat->codfamilia] ?? '';
            $out[] = '- [' . $name . '](' . $base . '/Productos?cat=' . $slug . ')';
        }
        $out[] = '';
        $out[] = '## Productos / Products';
        $out[] = '';
        foreach (array_slice($this->products, 0, self::MAX_PRODUCTS) as $product) {
            $line = '- [' . $product->name . '](' . $base . '/ProductoDetalle?url=' . rawurlencode($product->slug) . ')';
            if ($product->familyType === 'tableros') {
                $line .= ' — precio por m², corte a medida / price per m², custom cut';
            }
            $out[] = $line;
        }
        $out[] = '';
        $out[] = '## Comprar / How to buy';
        $out[] = '';
        $out[] = '- Catálogo / catalogue: ' . $base . '/Productos';
        $out[] = '- Presupuesto y pedido / quote & checkout: ' . $base . '/Presupuesto';
        $out[] = '- Pago con tarjeta (Stripe). Envío a España y toda la UE / Card payments (Stripe). Shipping to Spain and the EU.';
        $out[] = '- Los tableros y encimeras se cortan a la medida indicada por el cliente (precio por m²).';
        $out[] = '';
        $out[] = '## Idiomas / Languages';
        $out[] = '';
        $out[] = '- es, en, fr, de — add `?lang=es_ES|en_EN|fr_FR|de_DE` to any URL.';
        $out[] = '';
        $out[] = '## Más información / More';
        $out[] = '';
        $out[] = '- Web: https://yevea.com';
        $out[] = '- Sitemap: ' . $base . '/Sitemap';
        $out[] = '';

        header('Content-Type: text/plain; charset=utf-8');
        echo implode("\n", $out);
        exit;
    }
}
