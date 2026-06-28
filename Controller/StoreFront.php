<?php
namespace FacturaScripts\Plugins\YeveaStore\Controller;

use FacturaScripts\Core\Lib\AssetManager;
use FacturaScripts\Core\Model\Familia;
use FacturaScripts\Core\Model\Producto;
use FacturaScripts\Core\Template\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\YeveaStore\Lib\LanguageTrait;
use FacturaScripts\Plugins\YeveaStore\Lib\SlugTrait;
use FacturaScripts\Plugins\YeveaStore\Model\YeveaStoreCartItem;

class StoreFront extends Controller
{
    use LanguageTrait;
    use SlugTrait;

    protected $requiresAuth = false;

    /** @var bool When false, subclasses manage their own view rendering after parent::run() */
    protected $autoRenderView = true;

    /** @var Familia[] */
    public $categories = [];

    /** @var object[] */
    public $products = [];

    /** @var string|null */
    public $selectedCategory = null;

    /** @var string|null Family type of the selected category */
    public $selectedCategoryType = null;

    /** @var object|null Family data for the selected category (includes dimension limits for tableros) */
    public $selectedCategoryFamily = null;

    /** @var int */
    public $cartItemCount = 0;

    /** @var array Map of codfamilia => translated category name */
    public $categoryNames = [];

    /** @var array Map of codfamilia => slug for all categories */
    public $slugMap = [];

    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['menu'] = 'yeveastore';
        $pageData['title'] = 'storefront';
        $pageData['icon'] = 'fa-solid fa-store';
        $pageData['showonmenu'] = false;
        return $pageData;
    }

    public function run(): void
    {
        parent::run();
        $this->detectAndSetLanguage();

        $cssPath = FS_FOLDER . '/Plugins/YeveaStore/Assets/CSS/yeveastore.css';
        if (file_exists($cssPath)) {
            AssetManager::addCss(FS_ROUTE . '/Plugins/YeveaStore/Assets/CSS/yeveastore.css');
        }

        $action = $this->request()->request->get('action', $this->request()->query->get('action', ''));
        if ($action === 'add-to-cart') {
            $this->addToCart();
        } elseif ($action === 'stripe-checkout') {
            $this->stripeCheckout();
        }

        $this->selectedCategory = $this->request()->query->get('category', null) ?: null;
        $this->loadCategories();
        $this->loadSelectedCategoryType();
        $this->loadProducts();
        $this->loadCartItemCount();

        if ($this->autoRenderView) {
            $this->view($this->controllerName() . '.html.twig');
        }
    }

    public function formatMoney(float $amount): string
    {
        return number_format($amount, 2, ',', '.') . ' €';
    }

    protected function addToCart(): void
    {
        $productReferencia = $this->request()->request->get('product_referencia', '');
        if (empty($productReferencia)) {
            return;
        }

        $isPublic = false;
        $familyType = 'mercancia';
        $product = new Producto();
        $where = [Where::eq('referencia', $productReferencia)];
        if ($product->loadWhere($where)) {
            $isPublic = $product->publico || $this->isFamilyPublic($product->codfamilia);
            $familyType = $this->getFamilyTypeForProduct($product);
        } else {
            // Product not found by referencia — try looking up via Variante for non-primary variants
            $varianteClass = '\FacturaScripts\Core\Model\Variante';
            if (class_exists($varianteClass)) {
                $variante = new $varianteClass();
                $varWhere = [Where::eq('referencia', $productReferencia)];
                if ($variante->loadWhere($varWhere)) {
                    $parent = new Producto();
                    if ($parent->loadFromCode($variante->idproducto)) {
                        $isPublic = $parent->publico || $this->isFamilyPublic($parent->codfamilia);
                        $familyType = $this->getFamilyTypeForProduct($parent);
                    }
                }
            }
        }

        if (!$isPublic) {
            return;
        }

        $qty = max(1, (int) $this->request()->request->get('quantity', 1));

        // For Artesanía and Tablones, quantity is always 1 (unique pieces)
        if ($familyType === 'artesania' || $familyType === 'tablones') {
            $qty = 1;
        }

        // For Tableros, get customer dimensions
        $largoCm = null;
        $anchoCm = null;
        if ($familyType === 'tableros') {
            $largoCm = (float) $this->request()->request->get('largo_cm', 0);
            $anchoCm = (float) $this->request()->request->get('ancho_cm', 0);
            if ($largoCm <= 0 || $anchoCm <= 0) {
                Tools::log()->warning('invalid-dimensions');
                return;
            }
            $qty = 1;
        }

        $sessionId = $this->getSessionId();

        $cartItem = new YeveaStoreCartItem();
        $where = [
            Where::eq('session_id', $sessionId),
            Where::eq('product_referencia', $productReferencia),
        ];

        // For Tableros, each dimension combination is a separate cart item
        if ($familyType !== 'tableros') {
            $existing = $cartItem->all($where);
            if (!empty($existing)) {
                if ($familyType === 'artesania' || $familyType === 'tablones') {
                    // Artesanía / Tablones: unique pieces, don't add more, quantity stays at 1
                    return;
                }
                $existing[0]->quantity += $qty;
                $existing[0]->save();
                Tools::log()->notice('product-added-to-cart');
                return;
            }
        }

        $cartItem->session_id = $sessionId;
        $cartItem->product_referencia = $productReferencia;
        $cartItem->quantity = $qty;
        $cartItem->largo_cm = $largoCm;
        $cartItem->ancho_cm = $anchoCm;
        $cartItem->save();

        Tools::log()->notice('product-added-to-cart');
    }

    protected function stripeCheckout(): void
    {
        $productReferencia = $this->request()->request->get('product_referencia', '');
        if (empty($productReferencia)) {
            return;
        }

        $product = new Producto();
        $where = [Where::eq('referencia', $productReferencia)];
        if (!$product->loadWhere($where)) {
            Tools::log()->error('product-not-found');
            return;
        }

        $secretKey = Tools::settings('yeveastore', 'stripe_secret_key', '');
        if (empty($secretKey)) {
            Tools::log()->error('stripe-not-configured');
            return;
        }

        $checkoutUrl = $this->createStripeCheckoutSession($product, $secretKey);
        if ($checkoutUrl) {
            header('Location: ' . $checkoutUrl, true, 302);
            exit;
        }

        Tools::log()->error('stripe-session-failed');
    }

    protected function controllerName(): string
    {
        return basename(str_replace('\\', '/', static::class));
    }

    protected function createStripeCheckoutSession(Producto $product, string $secretKey): ?string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['SERVER_NAME'] ?? 'localhost');
        $port = (int) ($_SERVER['SERVER_PORT'] ?? 80);
        $defaultPort = ($scheme === 'https') ? 443 : 80;
        $hostWithPort = ($port !== $defaultPort) ? $host . ':' . $port : $host;
        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        $baseUrl = $scheme . '://' . $hostWithPort . $scriptDir;
        $ctrl = $this->controllerName();

        $params = [
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => ['name' => $product->descripcion],
                    'unit_amount' => (int) round($product->precio * 100),
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => $baseUrl . '/' . $ctrl . '?stripe=success',
            'cancel_url' => $baseUrl . '/' . $ctrl . '?stripe=cancel',
        ];

        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_USERPWD, $secretKey . ':');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$response || $httpCode !== 200) {
            return null;
        }

        $data = json_decode($response, true);
        return $data['url'] ?? null;
    }

    protected function loadCategories(): void
    {
        // Collect family codes that should be shown on the storefront:
        // 1. Families that contain at least one individually-public product
        $product = new Producto();
        $publicProducts = $product->all([Where::eq('publico', true)], [], 0, 0);

        $familyCodes = [];
        foreach ($publicProducts as $p) {
            if (!empty($p->codfamilia)) {
                $familyCodes[$p->codfamilia] = true;
            }
        }

        // 2. Families explicitly marked as publica (visible on storefront)
        $familia = new Familia();
        $publicFamilies = $familia->all([Where::eq('publica', true)], [], 0, 0);
        foreach ($publicFamilies as $fam) {
            $familyCodes[$fam->codfamilia] = true;
        }

        if (empty($familyCodes)) {
            $this->categories = [];
            return;
        }

        $this->categories = $familia->all(
            [Where::in('codfamilia', array_keys($familyCodes))],
            ['descripcion' => 'ASC'],
            0,
            0
        );

        // Build a map of codfamilia => translated category name for templates
        $this->categoryNames = [];
        // Build a map of codfamilia => slug for URL generation in templates
        $this->slugMap = [];
        foreach ($this->categories as $cat) {
            $nameKey = 'family-' . $cat->codfamilia . '-name';
            $translatedName = Tools::lang()->trans($nameKey);
            $this->categoryNames[$cat->codfamilia] = ($translatedName !== $nameKey)
                ? $translatedName
                : $cat->descripcion;
            $this->slugMap[$cat->codfamilia] = self::generateSlug($cat->descripcion);
        }
    }

    protected function loadSelectedCategoryType(): void
    {
        $this->selectedCategoryType = null;
        $this->selectedCategoryFamily = null;

        if ($this->selectedCategory === null) {
            return;
        }

        $familia = new Familia();
        if ($familia->loadFromCode($this->selectedCategory)) {
            $tipo = $familia->tipofamilia ?? 'mercancia';
            $this->selectedCategoryType = $tipo;

            // Translate category content via translation keys (fallback to DB Spanish)
            $translated = $this->translateCategory(
                $familia->codfamilia,
                $familia->descripcion,
                $familia->category_intro ?? '',
                $familia->category_outro ?? ''
            );

            $this->selectedCategoryFamily = (object) [
                'codfamilia' => $familia->codfamilia,
                'descripcion' => $translated['descripcion'],
                'tipofamilia' => $tipo,
                'largo_min' => (float) ($familia->largo_min ?? 0),
                'largo_max' => (float) ($familia->largo_max ?? 0),
                'ancho_min' => (float) ($familia->ancho_min ?? 0),
                'ancho_max' => (float) ($familia->ancho_max ?? 0),
                'category_custom_css' => $familia->category_custom_css ?? '',
                'category_intro' => $translated['category_intro'],
                'category_outro' => $translated['category_outro'],
            ];
        }
    }

    protected function loadProducts(): void
    {
        $product = new Producto();

        // Build set of public family codes (families with publica = true)
        $publicFamilyCodes = [];
        foreach ($this->categories as $cat) {
            if (!empty($cat->publica)) {
                $publicFamilyCodes[] = $cat->codfamilia;
            }
        }

        if ($this->selectedCategory !== null) {
            // Loading products for a specific category
            $isPublicFamily = in_array($this->selectedCategory, $publicFamilyCodes, true);

            if ($isPublicFamily) {
                // Show ALL products from this public family
                $where = [Where::eq('codfamilia', $this->selectedCategory)];
            } else {
                // Only show individually-public products from this family
                $where = [
                    Where::eq('publico', true),
                    Where::eq('codfamilia', $this->selectedCategory),
                ];
            }

            $nativeProducts = $product->all($where, ['descripcion' => 'ASC'], 0, 0);
        } else {
            // Loading all products (no category selected)
            // Include individually-public products
            $publicoProducts = $product->all([Where::eq('publico', true)], ['descripcion' => 'ASC'], 0, 0);

            // Also include all products from public families
            $familyProducts = [];
            if (!empty($publicFamilyCodes)) {
                $familyProducts = $product->all(
                    [Where::in('codfamilia', $publicFamilyCodes)],
                    ['descripcion' => 'ASC'],
                    0,
                    0
                );
            }

            // Merge and deduplicate by idproducto
            $merged = [];
            foreach ($publicoProducts as $p) {
                $merged[$p->idproducto] = $p;
            }
            foreach ($familyProducts as $p) {
                if (!isset($merged[$p->idproducto])) {
                    $merged[$p->idproducto] = $p;
                }
            }

            $nativeProducts = array_values($merged);
            usort($nativeProducts, fn($a, $b) => strcmp($a->descripcion, $b->descripcion));
        }

        // Build a map of family codes to types for efficient lookup
        $familyTypeMap = [];
        foreach ($this->categories as $cat) {
            $familyTypeMap[$cat->codfamilia] = $cat->tipofamilia ?? 'mercancia';
        }

        $this->products = [];
        $imgModelClass = '\FacturaScripts\Core\Model\ProductoImagen';
        foreach ($nativeProducts as $p) {
            $imageUrl = null;
            if (class_exists($imgModelClass)) {
                $imgWhere = [Where::eq('idproducto', $p->idproducto)];
                $images = (new $imgModelClass())->all($imgWhere, ['orden' => 'ASC'], 0, 1);
                if (!empty($images)) {
                    $imageUrl = $images[0]->url('download-permanent');
                }
            }

            $familyType = $familyTypeMap[$p->codfamilia] ?? 'mercancia';

            // For Artesanía: determine if product is sold (stock <= 0)
            $isSold = false;
            if ($familyType === 'artesania' && $p->stockfis <= 0) {
                $isSold = true;
            }

            // For Tablones: determine if slab is sold (stock <= 0)
            if ($familyType === 'tablones' && $p->stockfis <= 0) {
                $isSold = true;
            }

            // Translate product name/description via translation keys (fallback to DB Spanish)
            $translated = $this->translateProduct($p->referencia, $p->descripcion, $p->observaciones ?? '');

            $productObj = (object) [
                'referencia' => $p->referencia,
                'slug' => self::generateProductSlug($p->descripcion),
                'name' => $translated['name'],
                'description' => $translated['description'],
                'price' => $p->precio,
                'stock' => $p->stockfis,
                'nostock' => (bool) ($p->nostock ?? false),
                'image' => $imageUrl,
                'familyType' => $familyType,
                'isSold' => $isSold,
                'idproducto' => $p->idproducto,
                'largo' => $p->largo ?? null,
                'ancho' => $p->ancho ?? null,
                'espesor' => $p->espesor ?? null,
            ];

            $this->products[] = $productObj;
        }
    }

    protected function loadCartItemCount(): void
    {
        $cartItem = new YeveaStoreCartItem();
        $where = [Where::eq('session_id', $this->getSessionId())];
        $items = $cartItem->all($where);
        $this->cartItemCount = 0;
        foreach ($items as $item) {
            $this->cartItemCount += $item->quantity;
        }
    }

    protected function getSessionId(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return session_id();
    }

    protected function getFamilyTypeForProduct(Producto $product): string
    {
        if (empty($product->codfamilia)) {
            return 'mercancia';
        }

        $familia = new Familia();
        if ($familia->loadFromCode($product->codfamilia)) {
            return $familia->tipofamilia ?? 'mercancia';
        }

        return 'mercancia';
    }

    protected function isFamilyPublic(?string $codfamilia): bool
    {
        if (empty($codfamilia)) {
            return false;
        }

        static $cache = [];
        if (isset($cache[$codfamilia])) {
            return $cache[$codfamilia];
        }

        $familia = new Familia();
        if ($familia->loadFromCode($codfamilia)) {
            $cache[$codfamilia] = !empty($familia->publica);
            return $cache[$codfamilia];
        }

        $cache[$codfamilia] = false;
        return false;
    }
}
