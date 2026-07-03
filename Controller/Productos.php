<?php
namespace FacturaScripts\Plugins\YeveaStore\Controller;

use FacturaScripts\Core\Model\Familia;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\YeveaStore\Lib\StoreControllerBase;

/**
 * Public product catalogue: category filtering (?category=code or ?cat=Slug),
 * dimension filters for tablones, and Schema.org structured data.
 */
class Productos extends StoreControllerBase
{
    public string $seoTitle = 'Madera de Olivo, Aceite de Oliva y Olivas | Yevea';
    public string $seoDescription = 'Productos artesanales de olivar: madera de olivo, aceite de oliva virgen extra y olivas. Calidad Yevea.';
    public bool $noindex = false;

    /** @var array Dimension filter values for Tablones */
    public $dimensionFilters = [];

    /** @var string|null Current category slug (e.g. "TablerosMesa") */
    public $categorySlug = null;

    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['menu'] = 'yeveastore';
        $pageData['title'] = 'products';
        $pageData['icon'] = 'fa-solid fa-store';
        $pageData['showonmenu'] = false;
        return $pageData;
    }

    public function run(): void
    {
        parent::run();

        $this->noindex = (bool) Tools::settings('yeveastore', 'noindex', false);

        $this->dimensionFilters = [
            'largo_min' => $this->request()->query->get('largo_min', ''),
            'largo_max' => $this->request()->query->get('largo_max', ''),
            'ancho_min' => $this->request()->query->get('ancho_min', ''),
            'ancho_max' => $this->request()->query->get('ancho_max', ''),
            'espesor_min' => $this->request()->query->get('espesor_min', ''),
            'espesor_max' => $this->request()->query->get('espesor_max', ''),
        ];

        if ($this->request()->request->get('action', '') === 'add-to-cart') {
            $this->addToCart();
        }

        // Category via ?category=code, or via SEO slug ?cat=SlugName
        $this->selectedCategory = $this->request()->query->get('category', null) ?: null;
        if ($this->selectedCategory === null) {
            $catSlug = $this->request()->query->get('cat', null);
            if (!empty($catSlug)) {
                $this->selectedCategory = $this->resolveSlugToCategory($catSlug);
            }
        }

        $this->loadCategories();
        $this->loadSelectedCategoryType();
        $this->loadProducts();
        $this->loadCartItemCount();

        if ($this->selectedCategory !== null) {
            $this->categorySlug = $this->slugMap[$this->selectedCategory] ?? null;
        }

        $this->view('Productos.html.twig');
    }

    /**
     * Resolves a category slug (?cat=SlugName) to its codfamilia.
     * Falls back to treating the value as a codfamilia if no slug matches.
     */
    private function resolveSlugToCategory(string $slug): ?string
    {
        $familia = new Familia();
        foreach ($familia->all([], ['descripcion' => 'ASC'], 0, 0) as $fam) {
            if (self::generateSlug($fam->descripcion) === $slug) {
                return $fam->codfamilia;
            }
        }

        return $familia->loadFromCode($slug) ? $familia->codfamilia : null;
    }

    protected function loadProducts(): void
    {
        parent::loadProducts();

        // Apply dimension filtering for Tablones categories
        if ($this->selectedCategoryType !== 'tablones') {
            return;
        }

        $hasFilter = false;
        foreach ($this->dimensionFilters as $val) {
            if ($val !== '') {
                $hasFilter = true;
                break;
            }
        }

        if (!$hasFilter) {
            return;
        }

        // Filter products by their dimensions (stored on the product itself)
        $this->products = array_values(array_filter(
            $this->products,
            fn(object $p) => $this->productMatchesDimensionFilters($p)
        ));
    }

    private function productMatchesDimensionFilters(object $product): bool
    {
        $filters = $this->dimensionFilters;

        $largo = $product->largo ?? null;
        $ancho = $product->ancho ?? null;
        $espesor = $product->espesor ?? null;

        if ($filters['largo_min'] !== '' && ($largo === null || $largo < (float) $filters['largo_min'])) {
            return false;
        }
        if ($filters['largo_max'] !== '' && ($largo === null || $largo > (float) $filters['largo_max'])) {
            return false;
        }
        if ($filters['ancho_min'] !== '' && ($ancho === null || $ancho < (float) $filters['ancho_min'])) {
            return false;
        }
        if ($filters['ancho_max'] !== '' && ($ancho === null || $ancho > (float) $filters['ancho_max'])) {
            return false;
        }
        if ($filters['espesor_min'] !== '' && ($espesor === null || $espesor < (float) $filters['espesor_min'])) {
            return false;
        }
        if ($filters['espesor_max'] !== '' && ($espesor === null || $espesor > (float) $filters['espesor_max'])) {
            return false;
        }

        return true;
    }
}
