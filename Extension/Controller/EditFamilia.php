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

namespace FacturaScripts\Plugins\YeveaStore\Extension\Controller;

use Closure;
use FacturaScripts\Core\Lib\AssetManager;

/**
 * Extension for EditFamilia controller.
 *
 * - Hides dimension-limit columns (largo_min, largo_max, ancho_min, ancho_max)
 *   server-side when the family type is not "tableros".
 * - Registers a JavaScript asset for client-side dynamic toggling of the
 *   dimension-limits section based on the "Tipo de Familia" dropdown.
 */
class EditFamilia
{
    protected function loadData(): Closure
    {
        return function ($viewName, $view) {
            if ($viewName !== 'EditFamilia') {
                return;
            }

            // Register the JS asset for dynamic toggle of the dimensions section
            $pluginPath = FS_FOLDER . '/Plugins/YeveaStore/Assets/JS/EditFamilia.js';
            if (file_exists($pluginPath)) {
                AssetManager::addJs(FS_ROUTE . '/Plugins/YeveaStore/Assets/JS/EditFamilia.js');
            }

            // Server-side: hide dimension columns when tipofamilia is not "tableros"
            $tipofamilia = $view->model->tipofamilia ?? 'mercancia';
            if ($tipofamilia !== 'tableros') {
                foreach (['largo-min', 'largo-max', 'ancho-min', 'ancho-max'] as $col) {
                    $view->disableColumn($col);
                }
            }
        };
    }
}
