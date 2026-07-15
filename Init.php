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
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\AttachedFileRelation;
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
        $this->migrateFromEcommerce();
        $this->fixProductImageFileRelations();
        $this->backfillProductSlugs();
        $this->registerLowercaseRoutes();
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

    public function uninstall(): void
    {
    }

    /**
     * Migrate data from the old "ecommerce" plugin to the new "yeveastore" plugin.
     * Copies settings from fs_settings and data from old ecommerce_* tables.
     * Safe to run multiple times (idempotent).
     */
    private function migrateFromEcommerce(): void
    {
        $db = new DataBase();

        // migrate settings
        $this->migrateSettings($db);

        // migrate table data
        $this->migrateTableData($db, 'ecommerce_orders', 'yeveastore_orders');
        $this->migrateTableData($db, 'ecommerce_order_lines', 'yeveastore_order_lines');
        $this->migrateTableData($db, 'ecommerce_cart_items', 'yeveastore_cart_items');
    }

    /**
     * Copy settings row from old 'ecommerce' group to new 'yeveastore' group
     * in the fs_settings table, if old exists and new doesn't.
     */
    private function migrateSettings(DataBase $db): void
    {
        // The settings table is 'settings' in current FS versions, 'fs_settings' in older ones
        $table = null;
        foreach (['settings', 'fs_settings'] as $candidate) {
            if ($db->tableExists($candidate)) {
                $table = $candidate;
                break;
            }
        }
        if ($table === null) {
            return;
        }

        $old = $db->select("SELECT * FROM " . $table . " WHERE name = 'ecommerce'");
        if (empty($old)) {
            return;
        }

        $new = $db->select("SELECT * FROM " . $table . " WHERE name = 'yeveastore'");
        if (!empty($new)) {
            return;
        }

        $properties = $db->var2str($old[0]['properties'] ?? '');
        $db->exec("INSERT INTO " . $table . " (name, properties) VALUES ('yeveastore', " . $properties . ")");
    }

    /**
     * Copy rows from an old table to a new table if old has data and new is empty.
     * Uses column intersection so it works even if schemas differ slightly.
     */
    private function migrateTableData(DataBase $db, string $oldTable, string $newTable): void
    {
        if (false === $db->tableExists($oldTable) || false === $db->tableExists($newTable)) {
            return;
        }

        $countNew = $db->select("SELECT COUNT(*) as total FROM " . $newTable);
        if ((int)($countNew[0]['total'] ?? 0) > 0) {
            return;
        }

        $countOld = $db->select("SELECT COUNT(*) as total FROM " . $oldTable);
        if ((int)($countOld[0]['total'] ?? 0) === 0) {
            return;
        }

        $oldCols = array_keys($db->getColumns($oldTable));
        $newCols = array_keys($db->getColumns($newTable));
        $commonCols = array_intersect($oldCols, $newCols);

        if (empty($commonCols)) {
            return;
        }

        $colList = implode(', ', $commonCols);
        $db->exec("INSERT INTO " . $newTable . " (" . $colList . ") SELECT " . $colList . " FROM " . $oldTable);
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

    /**
     * Fix AttachedFileRelation records for product images that have modelid set
     * but modelcode = null. This allows editFileAction() to correctly validate
     * these records when editing observations in the Archivos tab.
     */
    private function fixProductImageFileRelations(): void
    {
        $fileRelation = new AttachedFileRelation();
        $where = [
            Where::eq('model', 'Producto'),
            Where::isNull('modelcode'),
        ];
        foreach ($fileRelation->all($where, [], 0, 0) as $relation) {
            if ($relation->modelid > 0) {
                $relation->modelcode = (string)$relation->modelid;
                $relation->save();
            }
        }
    }
}
