<?php
/**
 * This file is part of YeveaStore plugin for FacturaScripts.
 * Copyright (C) 2024 FacturaScripts Community
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
namespace FacturaScripts\Plugins\YeveaStore;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Plugins\YeveaStore\Lib\SlugTrait;

class Init extends InitClass
{
    use SlugTrait;

    /** Lowercase SEO routes for the public pages (see also StoreControllerBase::PUBLIC_PATHS) */
    private const LOWERCASE_ROUTES = [
        '/productos' => 'Productos',
        '/producto' => 'ProductoDetalle',
        '/presupuesto' => 'Presupuesto',
        '/sitemap.xml' => 'Sitemap',
        '/llms.txt' => 'LlmsTxt',
        '/capturar' => 'YeveaCaptura',
    ];

    public function init(): void
    {
        $this->loadExtension(new Extension\Controller\EditProducto());
        $this->loadExtension(new Extension\Controller\EditFamilia());
        $this->loadExtension(new Extension\Controller\EditSettings());

        // Register the lowercase routes on EVERY request: Plugins::init() runs
        // before Kernel routing, and core deploys rewrite MyFiles/routes.json,
        // so runtime registration is the reliable source.
        foreach (self::LOWERCASE_ROUTES as $route => $controller) {
            Kernel::addRoute($route, $controller, 0, 'yeveastore' . str_replace(['/', '.'], '-', $route));
        }
    }

    public function update(): void
    {
        $this->migrateFamilyTypes();
        $this->migrateProductUniqueFlag();
        $this->backfillProductSlugs();
        $this->registerLowercaseRoutes();
    }

    public function uninstall(): void
    {
    }

    /**
     * Maps the four legacy family types to the simplified pair:
     * mercancia/tableros → estandar (pricing now lives in calc_config),
     * tablones/artesania → pieza_unica (unique pieces). Idempotent.
     */
    private function migrateFamilyTypes(): void
    {
        $db = new DataBase();
        if (false === $db->tableExists('familias')) {
            return;
        }

        $db->exec("UPDATE familias SET tipofamilia = 'estandar'"
            . " WHERE tipofamilia IN ('mercancia', 'tableros') OR tipofamilia IS NULL");
        $db->exec("UPDATE familias SET tipofamilia = 'pieza_unica'"
            . " WHERE tipofamilia IN ('tablones', 'artesania')");
    }

    /**
     * One-time backfill: "unique piece" used to live on the family
     * (tipofamilia); now it's a per-product flag (productos.pieza_unica) set
     * in EditProducto and defaulted to true for new products. Existing
     * products inherit it from their family's old tipofamilia so nothing
     * changes behaviour on deploy. Only touches rows still NULL (never
     * explicitly set), so it never overwrites an admin's later per-product
     * choice — same guard pattern as backfillProductSlugs(). Safe to keep
     * running on every deploy.
     */
    private function migrateProductUniqueFlag(): void
    {
        $db = new DataBase();
        if (false === $db->tableExists('productos') || false === $db->tableExists('familias')) {
            return;
        }

        $db->exec('UPDATE ' . Producto::tableName() . ' SET pieza_unica = ('
            . ' SELECT CASE WHEN familias.tipofamilia = ' . $db->var2str('pieza_unica') . ' THEN TRUE ELSE FALSE END'
            . ' FROM familias WHERE familias.codfamilia = ' . Producto::tableName() . '.codfamilia'
            . ') WHERE pieza_unica IS NULL');
    }

    /**
     * SEO-friendly lowercase routes for the public pages, plus real
     * /sitemap.xml and /llms.txt paths. Merged into MyFiles/routes.json:
     * only our entries are written — core default routes are never
     * persisted (freezing them would break core updates).
     */
    private function registerLowercaseRoutes(): void
    {
        $file = FS_FOLDER . '/MyFiles/routes.json';
        $routes = [];
        if (file_exists($file)) {
            $routes = json_decode((string) file_get_contents($file), true) ?: [];
        }

        foreach (self::LOWERCASE_ROUTES as $route => $controller) {
            $routes[$route] = [
                'controller' => $controller,
                'customId' => 'yeveastore' . str_replace(['/', '.'], '-', $route),
                'position' => 0,
            ];
        }

        file_put_contents($file, json_encode($routes, JSON_PRETTY_PRINT));
    }

    /**
     * Fills the slug column for products that don't have one yet, so
     * ProductoDetalle can resolve SEO URLs with an indexed lookup instead of
     * scanning every product. Ensures uniqueness by suffixing duplicates
     * (-2, -3, …). Idempotent: only touches products with an empty slug.
     */
    private function backfillProductSlugs(): void
    {
        $db = new DataBase();
        $producto = new Producto();
        $all = $producto->all([], [], 0, 0);

        $usedSlugs = [];
        foreach ($all as $p) {
            if (!empty($p->slug)) {
                $usedSlugs[$p->slug] = true;
            }
        }

        foreach ($all as $p) {
            if (!empty($p->slug) || empty($p->descripcion)) {
                continue;
            }

            $base = self::generateProductSlug($p->descripcion);
            if ($base === '') {
                continue;
            }

            $slug = $base;
            $suffix = 2;
            while (isset($usedSlugs[$slug])) {
                $slug = $base . '-' . $suffix;
                $suffix++;
            }
            $usedSlugs[$slug] = true;

            $db->exec('UPDATE ' . Producto::tableName()
                . ' SET slug = ' . $db->var2str($slug)
                . ' WHERE idproducto = ' . (int) $p->idproducto);
        }
    }
}
