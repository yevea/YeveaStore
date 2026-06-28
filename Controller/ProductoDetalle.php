<?php
namespace FacturaScripts\Plugins\YeveaStore\Controller;

use FacturaScripts\Core\Model\Familia;
use FacturaScripts\Core\Model\Producto;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;

class ProductoDetalle extends StoreFront
{
    /** @var object|null */
    public $product = null;

    /** @var array */
    public $productImages = [];

    /** @var array Map of idvariante => array of image objects (for JS-driven variant image switching) */
    public $variantImages = [];

    /** @var array */
    public $productVariants = [];

    /** @var array Attribute groups: [codatributo => ['nombre' => string, 'values' => [id => valor]]] */
    public $productAttributes = [];

    /** @var object|null First/default variant data for initial display */
    public $defaultVariant = null;

    /** @var string Family type of this product */
    public $familyType = 'mercancia';

    /** @var object|null Family data including dimension limits for tableros */
    public $familyData = null;

    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['title'] = 'product-detail';
        return $pageData;
    }

    public function run(): void
    {
        // Disable automatic rendering so we can load the product before rendering.
        $this->autoRenderView = false;
        parent::run();

        $slug = $this->request()->query->get('url', '');
        $referencia = $this->request()->query->get('ref', '');
        if (!empty($slug)) {
            $this->loadProductBySlug($slug);
        } elseif (!empty($referencia)) {
            $this->loadProduct($referencia);
        }

        $this->view('ProductoDetalle.html.twig');
    }

    private function loadProductBySlug(string $slug): void
    {
        $product = new Producto();

        // First try individually-public products
        foreach ($product->all([Where::eq('publico', true)], [], 0, 0) as $p) {
            if (self::generateProductSlug($p->descripcion) === $slug) {
                $this->loadProduct($p->referencia);
                return;
            }
        }

        // Also try products visible via their public family (publica=true)
        $publicFamilyCodes = [];
        $familia = new Familia();
        foreach ($familia->all([Where::eq('publica', true)], [], 0, 0) as $fam) {
            $publicFamilyCodes[] = $fam->codfamilia;
        }

        if (!empty($publicFamilyCodes)) {
            $where = [Where::in('codfamilia', $publicFamilyCodes)];
            foreach ($product->all($where, [], 0, 0) as $p) {
                if (self::generateProductSlug($p->descripcion) === $slug) {
                    $this->loadProduct($p->referencia);
                    return;
                }
            }
        }
    }

    private function loadProduct(string $referencia): void
    {
        $p = new Producto();
        $where = [Where::eq('referencia', $referencia)];
        if (!$p->loadWhere($where)) {
            return;
        }

        // Product is visible if individually public OR belongs to a public family
        if (!$p->publico && !$this->isFamilyPublic($p->codfamilia)) {
            return;
        }

        // Load family type
        $this->loadFamilyType($p);

        $isSold = false;
        if (($this->familyType === 'artesania' || $this->familyType === 'tablones') && $p->stockfis <= 0) {
            $isSold = true;
        }

        // Translate product name/description via translation keys (fallback to DB Spanish)
        $translated = $this->translateProduct($p->referencia, $p->descripcion, $p->observaciones ?? '');

        $this->product = (object) [
            'referencia' => $p->referencia,
            'slug' => self::generateProductSlug($p->descripcion),
            'name' => $translated['name'],
            'description' => $translated['description'],
            'price' => $p->precio,
            'stock' => $p->stockfis,
            'nostock' => (bool) ($p->nostock ?? false),
            'image' => $p->imagen ?? null,
            'familyType' => $this->familyType,
            'isSold' => $isSold,
            'largo' => $p->largo ?? null,
            'ancho' => $p->ancho ?? null,
            'espesor' => $p->espesor ?? null,
        ];

        $this->loadProductImages($p);
        $this->loadProductVariants($p);
    }

    private function loadFamilyType(Producto $p): void
    {
        $this->familyType = 'mercancia';
        $this->familyData = null;

        if (empty($p->codfamilia)) {
            return;
        }

        $familia = new Familia();
        if ($familia->loadFromCode($p->codfamilia)) {
            $this->familyType = $familia->tipofamilia ?? 'mercancia';
            $this->familyData = (object) [
                'codfamilia' => $familia->codfamilia,
                'descripcion' => $familia->descripcion,
                'tipofamilia' => $this->familyType,
                'largo_min' => (float) ($familia->largo_min ?? 0),
                'largo_max' => (float) ($familia->largo_max ?? 0),
                'ancho_min' => (float) ($familia->ancho_min ?? 0),
                'ancho_max' => (float) ($familia->ancho_max ?? 0),
            ];
        }
    }

    private function loadProductImages(Producto $p): void
    {
        $this->productImages = [];
        $this->variantImages = [];

        // Try loading from ProductoImagen model if available
        $modelClass = '\FacturaScripts\Dinamic\Model\ProductoImagen';
        if (class_exists($modelClass)) {
            // Build a map: referencia -> idvariante from the Variante table
            $refToIdvariante = [];
            $varianteClass = '\FacturaScripts\Dinamic\Model\Variante';
            if (class_exists($varianteClass)) {
                $varianteModel = new $varianteClass();
                $varWhere = [Where::eq('idproducto', $p->idproducto)];
                foreach ($varianteModel->all($varWhere, [], 0, 0) as $v) {
                    $refToIdvariante[$v->referencia] = $v->idvariante;
                }
            }

            $imgModel = new $modelClass();
            $where = [Where::eq('idproducto', $p->idproducto)];
            $images = $imgModel->all($where, ['orden' => 'ASC']);
            foreach ($images as $img) {
                $idvariante = null;
                if (!empty($img->referencia) && isset($refToIdvariante[$img->referencia])) {
                    $idvariante = (int) $refToIdvariante[$img->referencia];
                }
                $imgObj = (object) [
                    'url' => $img->url('download-permanent'),
                    'alt' => !empty($img->descripcion_corta) ? $img->descripcion_corta : $p->descripcion,
                    'description' => $img->observaciones ?? '',
                    'referencia' => $img->referencia ?? '',
                    'idvariante' => $idvariante,
                ];
                $this->productImages[] = $imgObj;
                if (!empty($idvariante)) {
                    $this->variantImages[$idvariante][] = $imgObj;
                }
            }
        }

        // Fall back to the main imagen field on the Producto model
        if (empty($this->productImages) && !empty($p->imagen)) {
            $this->productImages[] = (object) [
                'url' => $p->imagen,
                'alt' => $p->descripcion,
                'description' => '',
                'referencia' => '',
                'idvariante' => null,
            ];
        }
    }

    private function loadProductVariants(Producto $p): void
    {
        $this->productVariants = [];
        $this->productAttributes = [];
        $this->defaultVariant = null;

        $varianteClass = '\FacturaScripts\Dinamic\Model\Variante';
        $attrValClass = '\FacturaScripts\Dinamic\Model\AtributoValor';
        if (!class_exists($varianteClass)) {
            return;
        }

        $variante = new $varianteClass();
        $where = [Where::eq('idproducto', $p->idproducto)];
        $variants = $variante->all($where, ['referencia' => 'ASC'], 0, 0);

        // For Tableros, always build variant list (even single variant) for thickness selection
        $isTablones = $this->familyType === 'tablones';
        $isTableros = $this->familyType === 'tableros';

        // Tablones: each slab is unique, no variant selectors needed.
        // Dimensions are stored on the product, not the variant.
        if ($isTablones) {
            if (count($variants) >= 1) {
                $v = $variants[0];
                $this->defaultVariant = (object) [
                    'referencia' => $v->referencia,
                    'idvariante' => $v->idvariante ?? null,
                    'description' => '',
                    'price' => $v->precio,
                    'stock' => $v->stockfis,
                    'attributes' => [],
                ];
            }
            return;
        }

        // Single-variant product: no selector needed (unless Tableros)
        if (count($variants) <= 1 && !$isTableros) {
            return;
        }

        $attrValueCache = []; // id => ['codatributo' => ..., 'nombre' => ..., 'valor' => ...]
        $attrGroups = []; // codatributo => ['nombre' => ..., 'values' => [id => valor]]

        foreach ($variants as $v) {
            $attrMap = []; // codatributo => idatributovalor (int)

            foreach ([$v->idatributovalor1, $v->idatributovalor2, $v->idatributovalor3, $v->idatributovalor4] as $idAttrVal) {
                if (empty($idAttrVal)) {
                    continue;
                }

                if (!isset($attrValueCache[$idAttrVal]) && class_exists($attrValClass)) {
                    $attrValModel = new $attrValClass();
                    if ($attrValModel->loadFromCode($idAttrVal)) {
                        $atributo = $attrValModel->getAtributo();
                        $attrValueCache[$idAttrVal] = [
                            'codatributo' => $attrValModel->codatributo,
                            'nombre' => $atributo->nombre,
                            'valor' => $attrValModel->valor,
                        ];
                        if (!isset($attrGroups[$attrValModel->codatributo])) {
                            $attrGroups[$attrValModel->codatributo] = [
                                'nombre' => $atributo->nombre,
                                'values' => [],
                            ];
                        }
                        if (!isset($attrGroups[$attrValModel->codatributo]['values'][$idAttrVal])) {
                            $attrGroups[$attrValModel->codatributo]['values'][$idAttrVal] = $attrValModel->valor;
                        }
                    }
                }

                if (isset($attrValueCache[$idAttrVal])) {
                    $attrMap[$attrValueCache[$idAttrVal]['codatributo']] = $idAttrVal;
                }
            }

            // Determine a human-readable description for the simple dropdown fallback
            $desc = '';
            if (method_exists($v, 'description')) {
                $desc = $v->description(true);
            }
            if (empty($desc)) {
                $desc = $v->referencia;
            }

            $variantObj = (object) [
                'referencia' => $v->referencia,
                'idvariante' => $v->idvariante ?? null,
                'description' => $desc,
                'price' => $v->precio,
                'stock' => $v->stockfis,
                'attributes' => $attrMap,
                'largo' => $v->largo ?? null,
                'ancho' => $v->ancho ?? null,
                'espesor' => $v->espesor ?? null,
            ];

            $this->productVariants[] = $variantObj;

            // Use the variant matching the parent product referencia as the default
            if ($v->referencia === $p->referencia && $this->defaultVariant === null) {
                $this->defaultVariant = $variantObj;
            }
        }

        // Fall back to the first variant as the default
        if ($this->defaultVariant === null && !empty($this->productVariants)) {
            $this->defaultVariant = $this->productVariants[0];
        }

        $this->productAttributes = $attrGroups;
    }
}
