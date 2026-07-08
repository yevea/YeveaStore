<?php
namespace FacturaScripts\Plugins\YeveaStore\Controller;

use FacturaScripts\Plugins\YeveaStore\Lib\StoreControllerBase;

/**
 * XML sitemap of the public store: catalogue, categories and product pages,
 * with hreflang alternates for every supported language.
 *
 * URL: /Sitemap — declare it in robots.txt as "Sitemap: https://<domain>/<route>/Sitemap"
 * and submit it in Google Search Console / Bing Webmaster Tools.
 */
class Sitemap extends StoreControllerBase
{
    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['title'] = 'sitemap';
        return $pageData;
    }

    public function run(): void
    {
        parent::run();
        $this->loadCategories();
        $this->loadProducts();

        $base = $this->baseUrl();
        $entries = [];

        // Catalogue home
        $entries[] = ['loc' => $base . '/productos', 'lastmod' => null];

        // Category pages (slug URLs, matching the canonical form)
        foreach ($this->categories as $cat) {
            $slug = $this->slugMap[$cat->codfamilia] ?? '';
            if ($slug !== '') {
                $entries[] = ['loc' => $base . '/productos?cat=' . $slug, 'lastmod' => null];
            }
        }

        // Product pages
        foreach ($this->products as $product) {
            $lastmod = null;
            if (!empty($product->updated)) {
                $time = strtotime($product->updated);
                $lastmod = $time ? date('Y-m-d', $time) : null;
            }
            $entries[] = [
                'loc' => $base . '/producto?url=' . rawurlencode($product->slug),
                'lastmod' => $lastmod,
            ];
        }

        $langs = array_keys($this->availableLanguages);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ' xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

        foreach ($entries as $entry) {
            $loc = htmlspecialchars($entry['loc'], ENT_XML1);
            $xml .= "<url>\n";
            $xml .= '  <loc>' . $loc . "</loc>\n";
            if (!empty($entry['lastmod'])) {
                $xml .= '  <lastmod>' . $entry['lastmod'] . "</lastmod>\n";
            }
            $separator = str_contains($entry['loc'], '?') ? '&amp;' : '?';
            foreach ($langs as $lang) {
                $xml .= '  <xhtml:link rel="alternate" hreflang="' . substr($lang, 0, 2) . '"'
                    . ' href="' . $loc . $separator . 'lang=' . $lang . '"/>' . "\n";
            }
            $xml .= '  <xhtml:link rel="alternate" hreflang="x-default" href="' . $loc . "\"/>\n";
            $xml .= "</url>\n";
        }

        $xml .= '</urlset>';

        header('Content-Type: application/xml; charset=utf-8');
        echo $xml;
        exit;
    }
}
