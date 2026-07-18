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
use FacturaScripts\Core\Model\Familia;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\YeveaStore\Lib\YeveaMeasure;

/**
 * Extension for EditFamilia controller.
 *
 * - Hides dimension-limit columns (largo_min, largo_max, ancho_min, ancho_max)
 *   server-side when the family type is not "tableros".
 * - Registers a JavaScript asset for client-side dynamic toggling of the
 *   dimension-limits section based on the "Tipo de Familia" dropdown.
 * - Adds the "Tabla de Precios" tab: per-family measurement price calculator
 *   (None / Area L×W / Volume L×W×H) + capture thickness rates, stored as
 *   JSON in familias.calc_config (see Lib/YeveaMeasure).
 */
class EditFamilia
{
    protected function createViews(): Closure
    {
        return function () {
            $this->addHtmlView('YeveaFamiliaTarifa', 'Tab/YeveaFamiliaTarifa', 'Familia', 'price-table', 'fa-solid fa-calculator');
        };
    }

    /** Normalized calculator config of the edited familia, for the tab template. */
    public function getYeveaCalcConfig(): Closure
    {
        return function () {
            $familia = new Familia();
            $code = $this->request->query->get('code', '');
            $loaded = $code !== '' && $familia->loadFromCode($code);
            return YeveaMeasure::normalize($loaded ? $familia : null);
        };
    }

    protected function execPreviousAction(): Closure
    {
        return function ($action) {
            if ($action !== 'save-yevea-calc-config') {
                return;
            }

            if (false === $this->validateFormToken()) {
                return;
            }

            $code = $this->request->request->get('codfamilia', '');
            $familia = new Familia();
            if ($code === '' || false === $familia->loadFromCode($code)) {
                Tools::log()->warning('record-save-error');
                return;
            }

            $mode = $this->request->request->get('calc_mode', 'none');
            $config = [
                'mode' => in_array($mode, ['area', 'volume'], true) ? $mode : 'none',
                'price_label' => trim((string) $this->request->request->get('price_label', '')),
                'price_unit' => trim((string) $this->request->request->get('price_unit', '')),
                'show_unit_price' => (bool) $this->request->request->get('show_unit_price', false),
                'calc_weight' => (bool) $this->request->request->get('calc_weight', false),
                'overage_pct' => (float) str_replace(',', '.', (string) $this->request->request->get('overage_pct', '0')),
                'dims' => [],
                'capture_rates' => [],
            ];

            foreach (['length', 'width', 'height'] as $dim) {
                $config['dims'][$dim] = [
                    'label' => trim((string) $this->request->request->get($dim . '_label', '')),
                    'unit' => trim((string) $this->request->request->get($dim . '_unit', 'cm')),
                    'options' => trim((string) $this->request->request->get($dim . '_options', '')),
                ];
            }

            $desde = $this->request->request->getArray('rate_desde');
            $hasta = $this->request->request->getArray('rate_hasta');
            $precio = $this->request->request->getArray('rate_precio');
            foreach (array_keys((array) $hasta) as $i) {
                $row = [
                    'desde' => (float) str_replace(',', '.', (string) ($desde[$i] ?? '0')),
                    'hasta' => (float) str_replace(',', '.', (string) ($hasta[$i] ?? '0')),
                    'precio' => (float) str_replace(',', '.', (string) ($precio[$i] ?? '0')),
                ];
                if ($row['hasta'] > 0 && $row['precio'] > 0) {
                    $config['capture_rates'][] = $row;
                }
            }

            $familia->calc_config = json_encode($config, JSON_UNESCAPED_UNICODE);
            if ($familia->save()) {
                YeveaMeasure::clearCache();
                Tools::log()->notice('record-updated-correctly');
            } else {
                Tools::log()->warning('record-save-error');
            }
        };
    }

    protected function loadData(): Closure
    {
        return function ($viewName, $view) {
            if ($viewName === 'YeveaFamiliaTarifa') {
                $code = $this->request->query->get('code', '');
                if ($code !== '') {
                    $view->model->loadFromCode($code);
                }
                return;
            }

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
