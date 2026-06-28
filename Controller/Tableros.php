<?php
namespace FacturaScripts\Plugins\YeveaStore\Controller;

use FacturaScripts\Core\Model\Familia;

class Tableros extends StoreFront
{
    /** @var array Dimension filter values for Tablones */
    public $dimensionFilters = [];

    /** @var string|null Current category slug (e.g. "TablerosMesa") */
    public $categorySlug = null;

    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['title'] = 'tableros';
        return $pageData;
    }

    public function run(): void
    {
        // Capture dimension filter parameters before parent::run() processes products
        $this->dimensionFilters = [
            'largo_min' => $this->request()->query->get('largo_min', ''),
            'largo_max' => $this->request()->query->get('largo_max', ''),
            'ancho_min' => $this->request()->query->get('ancho_min', ''),
            'ancho_max' => $this->request()->query->get('ancho_max', ''),
            'espesor_min' => $this->request()->query->get('espesor_min', ''),
            'espesor_max' => $this->request()->query->get('espesor_max', ''),
        ];

        // Pre-resolve slug-based category parameter (?cat=SlugName)
        // into the standard ?category= parameter before parent::run()
        $catSlug = $this->request()->query->get('cat', null);
        if ($catSlug !== null) {
            $this->preResolveSlugToCategory($catSlug);
        }

        // Disable auto-rendering so we can select the template
        $this->autoRenderView = false;
        parent::run();

        // Set the current category slug
        if ($this->selectedCategory !== null) {
            $this->categorySlug = $this->slugMap[$this->selectedCategory] ?? null;
        }

        $this->view('Tableros.html.twig');
    }

    /**
     * Resolve a slug (from ?cat= parameter) to a codfamilia and set it
     * as the category query parameter so parent::run() filters products.
     */
    private function preResolveSlugToCategory(string $slug): void
    {
        $familia = new Familia();
        foreach ($familia->all([], ['descripcion' => 'ASC'], 0, 0) as $fam) {
            $familySlug = self::generateSlug($fam->descripcion);
            if ($familySlug === $slug) {
                $this->request()->query->set('category', $fam->codfamilia);
                break;
            }
        }
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
