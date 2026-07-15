<?php
namespace FacturaScripts\Plugins\YeveaStore\Controller;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Model\Almacen;
use FacturaScripts\Core\Model\Familia;
use FacturaScripts\Core\Model\Producto;
use FacturaScripts\Core\Model\Stock;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\AttachedFile;
use FacturaScripts\Dinamic\Model\AttachedFileRelation;
use FacturaScripts\Dinamic\Model\ProductoImagen;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * YeveaCaptura: installable mobile-first PWA to capture wood planks and
 * other products from the warehouse — photos, dimensions, weight, pile
 * location — creating Producto + Variante + Stock(1) + images in the exact
 * ProductoImagen/AttachedFile format the public store renders.
 *
 * No login required: the selected warehouse identifies the operator. As a
 * counterweight, every captured product is created with captura_pendiente =
 * true and stays OUT of the public store (catalogue, detail, sitemap,
 * llms.txt, cart) until an admin approves it (Admin → YeveaStore → Captura).
 *
 * URL: /capturar. Sub-resources served from the same route so the service
 * worker scope covers the app:
 *   ?file=manifest → PWA manifest      ?file=sw  → service worker script
 *   ?api=meta      → families/warehouses/next SKU (JSON)
 *   POST action=save → create the product (JSON response)
 */
class YeveaCaptura extends StoreControllerBase
{
    /** Allowed photo extensions (anything else is stored as .jpg) */
    private const PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'];

    private const SKU_PREFIX = 'YV';

    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['title'] = 'yeveacaptura';
        $pageData['icon'] = 'fa-solid fa-camera';
        return $pageData;
    }

    public function run(): void
    {
        parent::run();

        switch ($this->request()->query->get('file', '')) {
            case 'manifest':
                $this->serveManifest();
                return;

            case 'sw':
                $this->serveServiceWorker();
                return;
        }

        if ($this->request()->query->get('api', '') === 'meta') {
            $this->serveMeta();
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
            && $this->request()->request->get('action', '') === 'save') {
            // adminVisitor() only supplies the nick for the file relations
            // when an admin happens to be logged in; it is NOT a gate.
            $this->savePlank($this->adminVisitor());
            return;
        }

        // plain GET: the YeveaCaptura.html.twig app shell renders
    }

    /** Next SKU that save() would assign right now (preview for the form). */
    public function nextSku(): string
    {
        return $this->generateSku(new DataBase());
    }

    private function fsRoute(): string
    {
        return defined('FS_ROUTE') ? FS_ROUTE : '';
    }

    private function outputJson(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function serveManifest(): void
    {
        $route = $this->fsRoute();

        $icons = [[
            'src' => $route . '/Plugins/YeveaStore/Assets/Images/yeveacaptura-icon.svg',
            'sizes' => 'any',
            'type' => 'image/svg+xml',
            'purpose' => 'any',
        ]];
        foreach ([192, 512] as $size) {
            $png = 'Plugins/YeveaStore/Assets/Images/yeveacaptura-' . $size . '.png';
            if (file_exists(FS_FOLDER . '/' . $png)) {
                $icons[] = [
                    'src' => $route . '/' . $png,
                    'sizes' => $size . 'x' . $size,
                    'type' => 'image/png',
                    'purpose' => $size === 512 ? 'maskable' : 'any',
                ];
            }
        }

        header('Content-Type: application/manifest+json; charset=utf-8');
        echo json_encode([
            'id' => $route . '/capturar',
            'name' => 'Yevea Captura',
            'short_name' => 'Captura',
            'description' => 'Captura rápida de tablones y productos en el almacén',
            'start_url' => $route . '/capturar',
            'scope' => $route . '/capturar',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#f6f2ea',
            'theme_color' => '#4e3b25',
            'icons' => $icons,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * The service worker script must be served from /capturar (not from
     * /Plugins/...) so its scope covers the app URL.
     */
    private function serveServiceWorker(): void
    {
        header('Content-Type: text/javascript; charset=utf-8');
        header('Cache-Control: no-cache');
        readfile(FS_FOLDER . '/Plugins/YeveaStore/Assets/JS/YeveaCapturaSW.js');
        exit;
    }

    private function serveMeta(): void
    {
        $families = [];
        foreach ((new Familia())->all([], ['descripcion' => 'ASC'], 0, 0) as $fam) {
            $families[] = [
                'cod' => $fam->codfamilia,
                'name' => $fam->descripcion,
                'tipo' => $fam->tipofamilia ?? 'mercancia',
            ];
        }

        $warehouses = [];
        foreach ((new Almacen())->all([], ['nombre' => 'ASC'], 0, 0) as $alm) {
            $warehouses[] = ['cod' => $alm->codalmacen, 'name' => $alm->nombre];
        }

        $this->outputJson([
            'ok' => true,
            'families' => $families,
            'warehouses' => $warehouses,
            'nextSku' => $this->generateSku(new DataBase()),
        ]);
    }

    /**
     * Creates the product from the PWA form: Producto + Variante (dims,
     * stock 1 — unique piece) + Stock in the chosen warehouse (pile in
     * 'ubicacion') + photos as AttachedFile/ProductoImagen/AttachedFileRelation,
     * exactly like an admin upload from EditProducto.
     */
    private function savePlank($user): void
    {
        // CSRF: same-origin check instead of a form token — offline queued
        // captures are replayed later (even from the service worker) and
        // cannot mint a fresh token, while FS's anti-replay token would
        // reject the retries. Cookie auth + admin check still apply.
        if (false === $this->sameOriginRequest()) {
            http_response_code(403);
            $this->outputJson(['ok' => false, 'error' => 'cross-origin-denied']);
        }

        $req = $this->request()->request;
        $nombre = trim((string) $req->get('nombre', ''));
        if ($nombre === '') {
            $this->outputJson(['ok' => false, 'error' => 'capture-name-required']);
        }

        $codalmacen = trim((string) $req->get('almacen', ''));
        $almacen = new Almacen();
        if ($codalmacen === '' || false === $almacen->loadFromCode($codalmacen)) {
            $this->outputJson(['ok' => false, 'error' => 'capture-warehouse-required']);
        }

        $codfamilia = trim((string) $req->get('familia', ''));
        if ($codfamilia !== '' && false === (new Familia())->loadFromCode($codfamilia)) {
            $codfamilia = '';
        }

        $pila = trim((string) $req->get('pila', ''));
        $comentario = trim((string) $req->get('comentario', ''));
        $peso = (float) $req->get('peso', 0);
        $largo = (float) $req->get('largo', 0);
        $ancho = (float) $req->get('ancho', 0);
        $grueso = (float) $req->get('grueso', 0);

        // Idempotency for offline replays: the page and the service worker's
        // Background Sync may both flush the same queued capture. The capture
        // log is checked/written under an exclusive lock so a concurrent
        // replay returns the already-saved result instead of duplicating.
        $captureId = substr((string) preg_replace('/[^a-zA-Z0-9\-]/', '', (string) $req->get('capture_id', '')), 0, 40);
        $lock = null;
        if ($captureId !== '') {
            $lock = fopen($this->captureLogFile(), 'c+') ?: null;
            if ($lock !== null) {
                flock($lock, LOCK_EX);
                $log = $this->readCaptureLog($lock);
                if (isset($log[$captureId])) {
                    $previous = $log[$captureId];
                    $previous['duplicate'] = true;
                    flock($lock, LOCK_UN);
                    fclose($lock);
                    $this->outputJson($previous);
                }
            }
        }

        // Auto-price from the store's per-m² table (tableros products);
        // 0 when dimensions are missing or no rate matches — the admin
        // then sets the price manually before approving.
        $precio = $this->calculateSlabPrice($grueso, $largo, $ancho);

        $db = new DataBase();
        $db->beginTransaction();
        try {
            $sku = $this->generateSku($db);

            $producto = new Producto();
            $producto->referencia = $sku;
            $producto->descripcion = $nombre;
            $producto->codfamilia = $codfamilia !== '' ? $codfamilia : null;
            $producto->observaciones = $comentario;
            $producto->nostock = false;
            // Unauthenticated captures always await admin approval before
            // becoming visible anywhere on the public store
            $producto->captura_pendiente = true;
            $producto->publico = false;
            $producto->slug = $this->uniqueSlug($nombre, $sku);
            if ($peso > 0) {
                $producto->peso = $peso;
            }
            if ($largo > 0) {
                $producto->largo = $largo;
            }
            if ($ancho > 0) {
                $producto->ancho = $ancho;
            }
            if ($grueso > 0) {
                $producto->espesor = $grueso;
            }
            if ($precio > 0) {
                $producto->precio = $precio;
            }
            if (false === $producto->save()) {
                throw new \RuntimeException('product-save-error');
            }

            // Producto::save() auto-creates the primary variante: copy the
            // dimensions and force stock 1 (each plank is a unique piece)
            $varianteClass = '\FacturaScripts\Dinamic\Model\Variante';
            if (class_exists($varianteClass)) {
                $variante = new $varianteClass();
                if ($variante->loadWhere([Where::eq('idproducto', $producto->idproducto)])) {
                    $variante->largo = $largo > 0 ? $largo : null;
                    $variante->ancho = $ancho > 0 ? $ancho : null;
                    $variante->espesor = $grueso > 0 ? $grueso : null;
                    $variante->stockfis = 1;
                    if ($precio > 0) {
                        $variante->precio = $precio;
                    }
                    $variante->save();
                }
            }

            $stock = new Stock();
            $stock->codalmacen = $codalmacen;
            $stock->idproducto = $producto->idproducto;
            $stock->referencia = $sku;
            $stock->cantidad = 1;
            $stock->disponible = 1;
            $stock->ubicacion = $pila !== '' ? $pila : null;
            if (false === $stock->save()) {
                throw new \RuntimeException('stock-save-error');
            }

            // Stock::save() may or may not sync producto.stockfis depending on
            // the FS version — enforce it (same approach as Extension/EditProducto)
            $fresh = new Producto();
            if ($fresh->loadFromCode($producto->idproducto) && $fresh->stockfis <= 0) {
                $fresh->stockfis = 1;
                $fresh->save();
            }

            $db->commit();
        } catch (\Throwable $exc) {
            $db->rollback();
            Tools::log()->error('yeveacaptura: ' . $exc->getMessage());
            $this->outputJson(['ok' => false, 'error' => 'capture-error']);
            return;
        }

        $photos = $this->savePhotos($producto, $sku, $user);

        $response = [
            'ok' => true,
            'sku' => $sku,
            'photos' => $photos,
            'price' => $precio,
            'url' => 'producto?url=' . rawurlencode($producto->slug),
            'nextSku' => $this->generateSku($db),
        ];

        if ($lock !== null) {
            $log = $this->readCaptureLog($lock);
            $log[$captureId] = $response;
            $this->writeCaptureLog($lock, $log);
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->outputJson($response);
    }

    /**
     * True when the Origin (or, failing that, Referer) header matches the
     * request host. Requests without either header are allowed: browsers
     * always send Origin on cross-site POSTs, and non-browser clients don't
     * carry the admin session cookies this endpoint requires anyway.
     */
    private function sameOriginRequest(): bool
    {
        $serverHost = strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]);
        foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $header) {
            $value = (string) ($_SERVER[$header] ?? '');
            if ($value === '' || $value === 'null') {
                continue;
            }
            $host = strtolower((string) parse_url($value, PHP_URL_HOST));
            return $host === $serverHost;
        }
        return true;
    }

    private function captureLogFile(): string
    {
        return FS_FOLDER . '/MyFiles/yeveacaptura-captures.json';
    }

    /** @param resource $handle  @return array<string, array> */
    private function readCaptureLog($handle): array
    {
        rewind($handle);
        $content = (string) stream_get_contents($handle);
        $log = json_decode($content, true);
        return is_array($log) ? $log : [];
    }

    /** Persists the capture log, pruned to the most recent 300 entries. */
    private function writeCaptureLog($handle, array $log): void
    {
        if (count($log) > 300) {
            $log = array_slice($log, -300, null, true);
        }
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, (string) json_encode($log));
        fflush($handle);
    }

    /**
     * Net price for a captured slab from the store's per-m² price table:
     * the tableros-family products (and their variants) define €/m² per
     * thickness, so the candidate whose espesor is closest to the slab's
     * grueso sets the rate (ties go to the cheaper one). Price = rate ×
     * largo×ancho in m². Returns 0 when dimensions are incomplete or no
     * rate exists — the admin prices it manually before approving.
     */
    private function calculateSlabPrice(float $grueso, float $largo, float $ancho): float
    {
        if ($grueso <= 0 || $largo <= 0 || $ancho <= 0) {
            return 0.0;
        }

        $candidates = [];
        $varianteClass = '\FacturaScripts\Dinamic\Model\Variante';
        $familia = new Familia();
        foreach ($familia->all([Where::eq('tipofamilia', 'tableros')], [], 0, 0) as $fam) {
            $producto = new Producto();
            foreach ($producto->all([Where::eq('codfamilia', $fam->codfamilia)], [], 0, 0) as $p) {
                if (($p->espesor ?? 0) > 0 && $p->precio > 0) {
                    $candidates[] = ['espesor' => (float) $p->espesor, 'precio' => (float) $p->precio];
                }
                if (class_exists($varianteClass)) {
                    foreach ((new $varianteClass())->all([Where::eq('idproducto', $p->idproducto)], [], 0, 0) as $v) {
                        if (($v->espesor ?? 0) > 0 && ($v->precio ?? 0) > 0) {
                            $candidates[] = ['espesor' => (float) $v->espesor, 'precio' => (float) $v->precio];
                        }
                    }
                }
            }
        }

        if (empty($candidates)) {
            return 0.0;
        }

        usort($candidates, function (array $a, array $b) use ($grueso) {
            $diff = abs($a['espesor'] - $grueso) <=> abs($b['espesor'] - $grueso);
            return $diff !== 0 ? $diff : ($a['precio'] <=> $b['precio']);
        });

        $areaM2 = ($largo / 100) * ($ancho / 100);
        return round($candidates[0]['precio'] * $areaM2, 2);
    }

    /**
     * Next sequential SKU: YV-<year>-NNNN, scanning both productos and
     * variantes so it never collides with a manually created referencia.
     */
    private function generateSku(DataBase $db): string
    {
        $prefix = self::SKU_PREFIX . '-' . date('Y') . '-';
        $max = 0;
        foreach ([Producto::tableName(), 'variantes'] as $table) {
            if (false === $db->tableExists($table)) {
                continue;
            }
            $sql = 'SELECT referencia FROM ' . $table
                . ' WHERE referencia LIKE ' . $db->var2str($prefix . '%');
            foreach ($db->select($sql) as $row) {
                $num = (int) substr((string) $row['referencia'], strlen($prefix));
                $max = max($max, $num);
            }
        }
        return $prefix . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * SEO keyword base for photo filenames: family slug + product slug
     * (family part skipped when the product slug already starts with it,
     * e.g. "tablones" + "tablon-olivo…"→ no dedup needed, they differ).
     * Falls back to the lowercase SKU when both slugs are empty.
     */
    private function photoSlugBase(Producto $producto, string $sku): string
    {
        $famSlug = '';
        if (!empty($producto->codfamilia)) {
            $familia = new Familia();
            if ($familia->loadFromCode($producto->codfamilia)) {
                $famSlug = self::generateProductSlug($familia->descripcion);
            }
        }

        $prodSlug = (string) $producto->slug;
        if ($famSlug !== '' && strpos($prodSlug, $famSlug) === 0) {
            $famSlug = '';
        }

        $base = trim($famSlug . '-' . $prodSlug, '-');
        return $base !== '' ? $base : strtolower($sku);
    }

    /** SEO slug from the product name, unique across productos (suffix -2, -3…). */
    private function uniqueSlug(string $nombre, string $sku): string
    {
        $base = self::generateProductSlug($nombre);
        if ($base === '') {
            $base = strtolower($sku);
        }

        $slug = $base;
        $suffix = 2;
        $probe = new Producto();
        while ($probe->loadWhere([Where::eq('slug', $slug)])) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }
        return $slug;
    }

    /**
     * Stores each photo as AttachedFile + ProductoImagen + AttachedFileRelation
     * — the exact format the public store templates render via
     * url('download-permanent'). Files are renamed to an SEO keyword slug:
     * familia-producto-1.jpg, familia-producto-2.jpg…
     *
     * @return int number of photos saved
     */
    private function savePhotos(Producto $producto, string $sku, $user): int
    {
        $files = $this->request()->files->get('photos');
        if (empty($files)) {
            return 0;
        }
        if (!is_array($files)) {
            $files = [$files];
        }

        $slugBase = $this->photoSlugBase($producto, $sku);
        $saved = 0;
        foreach ($files as $upload) {
            if (!$upload instanceof UploadedFile || false === $upload->isValid()) {
                continue;
            }
            if (strpos((string) $upload->getMimeType(), 'image/') !== 0) {
                continue;
            }

            $ext = strtolower($upload->getClientOriginalExtension());
            if (false === in_array($ext, self::PHOTO_EXTENSIONS, true)) {
                $ext = 'jpg';
            }

            // familia-producto-1.jpg… (extra suffix only on the rare name clash)
            $base = $slugBase . '-' . ($saved + 1);
            $name = $base . '.' . $ext;
            $bump = 1;
            while (file_exists(FS_FOLDER . '/MyFiles/' . $name)) {
                $bump++;
                $name = $base . '-' . $bump . '.' . $ext;
            }

            try {
                $upload->move(FS_FOLDER . '/MyFiles', $name);
            } catch (\Throwable $exc) {
                Tools::log()->error('yeveacaptura photo move: ' . $exc->getMessage());
                continue;
            }

            $attached = new AttachedFile();
            $attached->path = $name;
            if (false === $attached->save()) {
                continue;
            }

            $image = new ProductoImagen();
            $image->idfile = $attached->idfile;
            $image->idproducto = $producto->idproducto;
            $image->orden = $saved + 1;
            $image->save();

            $relation = new AttachedFileRelation();
            $relation->idfile = $attached->idfile;
            $relation->model = 'Producto';
            $relation->modelid = $producto->idproducto;
            $relation->modelcode = (string) $producto->idproducto;
            $relation->nick = $user->nick ?? null;
            $relation->save();

            $saved++;
        }
        return $saved;
    }
}
