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
use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\AttachedFileRelation;

class Init extends InitClass
{
    public function init(): void
    {
        $this->loadExtension(new Extension\Controller\EditProducto());
        $this->loadExtension(new Extension\Controller\EditFamilia());
    }

    public function update(): void
    {
        $this->migrateFromEcommerce();
        $this->fixProductImageFileRelations();
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
        if (false === $db->tableExists('fs_settings')) {
            return;
        }

        $old = $db->select("SELECT * FROM fs_settings WHERE name = 'ecommerce'");
        if (empty($old)) {
            return;
        }

        $new = $db->select("SELECT * FROM fs_settings WHERE name = 'yeveastore'");
        if (!empty($new)) {
            return;
        }

        $properties = $db->var2str($old[0]['properties'] ?? '');
        $db->exec("INSERT INTO fs_settings (name, properties) VALUES ('yeveastore', " . $properties . ")");
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
