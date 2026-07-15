<?php
namespace FacturaScripts\Plugins\YeveaStore\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Lib\AssetManager;
use FacturaScripts\Core\Model\Familia;
use FacturaScripts\Core\Model\Producto;
use FacturaScripts\Core\Template\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\YeveaStore\Model\YeveaStoreCartItem;

/**
 * Shared base for all public-facing store controllers (Productos, ProductoDetalle,
 * Presupuesto). Lives in Lib/ so it does NOT register a public route — only the
 * concrete controllers in Controller/ get URLs.
 */
abstract class StoreControllerBase extends Controller
{
    use LanguageTrait;
    use SlugTrait;

    /** Lowercase public routes (SEO): registered in MyFiles/routes.json by Init */
    private const PUBLIC_PATHS = [
        'Productos' => 'productos',
        'ProductoDetalle' => 'producto',
        'Presupuesto' => 'presupuesto',
        'Sitemap' => 'sitemap.xml',
        'LlmsTxt' => 'llms.txt',
        'YeveaCaptura' => 'capturar',
    ];

    protected $requiresAuth = false;

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

    /**
     * Default page registration for all public store controllers: grouped under
     * the 'yeveastore' menu and hidden from the admin menu bar. Without this,
     * subclasses that only override 'title' would fall back to the FS core
     * defaults (menu 'new', visible) and pollute the admin menu.
     */
    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['menu'] = 'yeveastore';
        $pageData['icon'] = 'fa-solid fa-store';
        $pageData['showonmenu'] = false;
        return $pageData;
    }

    public function run(): void
    {
        parent::run();
        $this->enforceLowercasePath();
        $this->detectAndSetLanguage();

        $cssPath = FS_FOLDER . '/Plugins/YeveaStore/Assets/CSS/yeveastore.css';
        if (file_exists($cssPath)) {
            AssetManager::addCss(FS_ROUTE . '/Plugins/YeveaStore/Assets/CSS/yeveastore.css');
        }
    }

    public function formatMoney(float $amount): string
    {
        return number_format($amount, 2, ',', '.') . ' €';
    }

    /**
     * Absolute base URL (scheme + host + install dir) for canonical links,
     * Stripe callbacks and redirects.
     */
    public function baseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['SERVER_NAME'] ?? 'localhost');
        $port = (int) ($_SERVER['SERVER_PORT'] ?? 80);
        $defaultPort = ($scheme === 'https') ? 443 : 80;
        $hostWithPort = ($port !== $defaultPort) ? $host . ':' . $port : $host;
        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        return $scheme . '://' . $hostWithPort . $scriptDir;
    }

    protected function controllerName(): string
    {
        return basename(str_replace('\\', '/', static::class));
    }

    /** The lowercase public route of this controller (e.g. 'productos') */
    public function publicPath(): string
    {
        return self::PUBLIC_PATHS[$this->controllerName()] ?? $this->controllerName();
    }

    /**
     * 301 from the legacy CamelCase route (/Productos) to the lowercase one
     * (/productos). GET only, so form POSTs are never converted or lost.
     */
    protected function enforceLowercasePath(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return;
        }

        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (basename($path) !== $this->controllerName() || $this->publicPath() === $this->controllerName()) {
            return;
        }

        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        $query = $_SERVER['QUERY_STRING'] ?? '';
        header('Location: ' . $scriptDir . '/' . $this->publicPath() . ($query !== '' ? '?' . $query : ''), true, 301);
        exit;
    }

    /** @var \FacturaScripts\Dinamic\Model\User|false|null Cached result of adminVisitor() */
    private $adminVisitorCache = null;

    /**
     * The admin User browsing this public page, validated against the
     * fsNick/fsLogkey session cookies, or null. Used to show admin-only UI
     * (e.g. the YeveaCaptura launcher) on the public store without auth.
     */
    public function adminVisitor(): ?\FacturaScripts\Dinamic\Model\User
    {
        if ($this->adminVisitorCache !== null) {
            return $this->adminVisitorCache === false ? null : $this->adminVisitorCache;
        }
        $this->adminVisitorCache = false;

        $nick = (string) ($_COOKIE['fsNick'] ?? '');
        $logkey = (string) ($_COOKIE['fsLogkey'] ?? '');
        if ($nick === '' || $logkey === '') {
            return null;
        }

        $user = new \FacturaScripts\Dinamic\Model\User();
        if (false === $user->loadFromCode($nick) || empty($user->admin) || empty($user->enabled)) {
            return null;
        }

        $valid = method_exists($user, 'verifyLogkey')
            ? $user->verifyLogkey($logkey)
            : (!empty($user->logkey) && hash_equals((string) $user->logkey, $logkey));
        if (false === $valid) {
            return null;
        }

        $this->adminVisitorCache = $user;
        return $user;
    }

    public function isAdminVisitor(): bool
    {
        return $this->adminVisitor() !== null;
    }

    /**
     * Social/entity profile URLs configured in the store settings, used to
     * build the Organization schema "sameAs" list (entity consolidation for
     * search engines and AI agents).
     */
    public function socialProfiles(): array
    {
        $profiles = [];
        foreach (['social_facebook', 'social_instagram', 'social_youtube', 'social_google'] as $key) {
            $url = trim((string) Tools::settings('yeveastore', $key, ''));
            if ($url !== '') {
                $profiles[] = $url;
            }
        }
        return $profiles;
    }

    /**
     * POST-Redirect-GET: after processing a POST action, redirect (302) back to
     * the same URL so a browser refresh repeats a harmless GET instead of
     * re-submitting the form. Optionally appends a query param for feedback.
     */
    protected function redirectAfterPost(string $extraParam = ''): void
    {
        $uri = $this->request()->getRequestUri();
        if ($extraParam !== '') {
            $uri .= (str_contains($uri, '?') ? '&' : '?') . $extraParam;
        }
        header('Location: ' . $uri, true, 302);
        exit;
    }

    /**
     * @return bool true when the product ended up in the cart, false otherwise
     */
    protected function addToCart(): bool
    {
        $productReferencia = $this->request()->request->get('product_referencia', '');
        if (empty($productReferencia)) {
            return false;
        }

        $isPublic = false;
        $familyType = 'mercancia';
        $product = new Producto();
        $where = [Where::eq('referencia', $productReferencia)];
        if ($product->loadWhere($where)) {
            $isPublic = empty($product->captura_pendiente)
                && ($product->publico || $this->isFamilyPublic($product->codfamilia));
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
                        $isPublic = empty($parent->captura_pendiente)
                            && ($parent->publico || $this->isFamilyPublic($parent->codfamilia));
                        $familyType = $this->getFamilyTypeForProduct($parent);
                    }
                }
            }
        }

        if (!$isPublic) {
            return false;
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
                return false;
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
                    return true;
                }
                $existing[0]->quantity += $qty;
                $existing[0]->save();
                Tools::log()->notice('product-added-to-cart');
                return true;
            }
        }

        $cartItem->session_id = $sessionId;
        $cartItem->product_referencia = $productReferencia;
        $cartItem->quantity = $qty;
        $cartItem->largo_cm = $largoCm;
        $cartItem->ancho_cm = $anchoCm;
        $result = $cartItem->save();

        if ($result) {
            Tools::log()->notice('product-added-to-cart');
        }
        return $result;
    }

    protected function loadCategories(): void
    {
        // Collect family codes that should be shown on the storefront:
        // 1. Families that contain at least one individually-public product
        //    (single DISTINCT query instead of loading every public product)
        $familyCodes = [];
        $db = new DataBase();
        $sql = 'SELECT DISTINCT codfamilia FROM ' . Producto::tableName()
            . ' WHERE publico = true AND codfamilia IS NOT NULL';
        foreach ($db->select($sql) as $row) {
            if (!empty($row['codfamilia'])) {
                $familyCodes[$row['codfamilia']] = true;
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

        // Build maps of codfamilia => translated name / slug for templates
        $this->categoryNames = [];
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

        // Batch-load the first image of every product in one query (avoids N+1)
        $firstImageMap = [];
        $imgModelClass = '\FacturaScripts\Core\Model\ProductoImagen';
        if (class_exists($imgModelClass) && !empty($nativeProducts)) {
            $ids = array_map(fn($p) => $p->idproducto, $nativeProducts);
            $imgWhere = [Where::in('idproducto', $ids)];
            foreach ((new $imgModelClass())->all($imgWhere, ['orden' => 'ASC'], 0, 0) as $img) {
                if (!isset($firstImageMap[$img->idproducto])) {
                    $firstImageMap[$img->idproducto] = $img->url('download-permanent');
                }
            }
        }

        $this->products = [];
        foreach ($nativeProducts as $p) {
            // Warehouse captures awaiting admin approval are invisible on
            // every public surface (catalogue, sitemap, llms.txt)
            if (!empty($p->captura_pendiente)) {
                continue;
            }

            $imageUrl = $firstImageMap[$p->idproducto] ?? null;

            $familyType = $familyTypeMap[$p->codfamilia] ?? 'mercancia';

            // For Artesanía and Tablones: unique pieces are sold out when stock <= 0
            $isSold = in_array($familyType, ['artesania', 'tablones'], true) && $p->stockfis <= 0;

            // Translate product name/description via translation keys (fallback to DB Spanish)
            $translated = $this->translateProduct($p->referencia, $p->descripcion, $p->observaciones ?? '');

            $this->products[] = (object) [
                'referencia' => $p->referencia,
                'slug' => !empty($p->slug) ? $p->slug : self::generateProductSlug($p->descripcion),
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
                'updated' => $p->actualizado ?? null,
            ];
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
