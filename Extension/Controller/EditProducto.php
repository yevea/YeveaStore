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
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\AttachedFile;
use FacturaScripts\Dinamic\Model\AttachedFileRelation;
use FacturaScripts\Dinamic\Model\ProductoImagen;

/**
 * Extension for EditProducto controller to fix observations on product images,
 * save short descriptions (alt text) and rename uploaded files for SEO,
 * and hide dimension fields (largo, ancho, espesor) for non-unique-piece products.
 */
class EditProducto
{
    protected function loadData(): Closure
    {
        return function ($viewName, $view) {
            // Hide dimension columns on EditVariante (dimensions live on the product for unique pieces)
            if ($viewName === 'EditVariante') {
                foreach (['largo', 'ancho', 'espesor'] as $col) {
                    $view->disableColumn($col);
                }
                return;
            }

            // Show/hide product-level dimension fields based on the product's own "pieza única" flag
            if ($viewName === 'EditProducto') {
                $idproducto = $this->getViewModelValue('EditProducto', 'idproducto');
                $piezaUnica = $this->getViewModelValue('EditProducto', 'pieza_unica');

                // New product: pre-check the box (unique piece is the default)
                if (empty($idproducto) && $piezaUnica === null) {
                    $view->model->pieza_unica = true;
                    $piezaUnica = true;
                }

                if (empty($piezaUnica)) {
                    foreach (['largo', 'ancho', 'espesor'] as $col) {
                        $view->disableColumn($col);
                    }
                }
            }
        };
    }

    protected function execPreviousAction(): Closure
    {
        return function ($action) {
            if ($action !== 'edit-image') {
                return;
            }

            $idimage = (int)$this->request->input('idimage');
            if ($idimage <= 0) {
                return;
            }

            $imgModel = new ProductoImagen();
            if (!$imgModel->loadFromCode($idimage)) {
                return;
            }

            $referencia = $this->request->input('referencia', '');
            ProductoImagen::table()
                ->whereEq('id', $idimage)
                ->update([
                    'referencia' => empty($referencia) ? null : $referencia,
                    'observaciones' => $this->request->input('observaciones', ''),
                    'descripcion_corta' => $this->request->input('descripcion_corta', ''),
                ]);

            // Handle file rename if the name was changed
            $nombreArchivo = trim($this->request->input('nombre_archivo', ''));
            if (!empty($nombreArchivo)) {
                $attachedFile = new AttachedFile();
                if ($attachedFile->loadFromCode($imgModel->idfile)) {
                    $currentBase = pathinfo($attachedFile->filename, PATHINFO_FILENAME);
                    if ($nombreArchivo !== $currentBase) {
                        // Sanitize the new name
                        $newName = strtolower(trim($nombreArchivo));
                        $newName = str_replace(' ', '-', $newName);
                        $newName = preg_replace('/[^a-z0-9-]/', '', $newName);
                        $newName = preg_replace('/-+/', '-', $newName);
                        $newName = trim($newName, '-');

                        if (!empty($newName)) {
                            $currentPath = $attachedFile->path;
                            $extension = pathinfo($currentPath, PATHINFO_EXTENSION);
                            $directory = pathinfo($currentPath, PATHINFO_DIRNAME);

                            $targetFilename = $newName . '.' . $extension;
                            $targetPath = $directory . '/' . $targetFilename;
                            $fullTargetPath = FS_FOLDER . '/' . $targetPath;

                            if (file_exists($fullTargetPath)) {
                                for ($i = 2; $i <= 100; $i++) {
                                    $targetFilename = $newName . '-' . $i . '.' . $extension;
                                    $targetPath = $directory . '/' . $targetFilename;
                                    $fullTargetPath = FS_FOLDER . '/' . $targetPath;
                                    if (!file_exists($fullTargetPath)) {
                                        break;
                                    }
                                }
                                if (file_exists($fullTargetPath)) {
                                    Tools::log()->warning('Could not rename file: all name variants taken');
                                    return;
                                }
                            }

                            $fullCurrentPath = FS_FOLDER . '/' . $currentPath;
                            if (file_exists($fullCurrentPath) && rename($fullCurrentPath, $fullTargetPath)) {
                                AttachedFile::table()
                                    ->whereEq('idfile', $imgModel->idfile)
                                    ->update(['path' => $targetPath, 'filename' => $targetFilename]);
                            }
                        }
                    }
                }
            }
        };
    }

    protected function execAfterAction(): Closure
    {
        return function ($action) {
            // Set default stock to 1 for new unique-piece products (each slab is a unique piece)
            if ($action === 'insert') {
                $idproducto = $this->getViewModelValue('EditProducto', 'idproducto');
                $piezaUnica = $this->getViewModelValue('EditProducto', 'pieza_unica');

                if (!empty($piezaUnica) && !empty($idproducto)) {
                    $varianteClass = '\FacturaScripts\Dinamic\Model\Variante';
                    if (class_exists($varianteClass)) {
                        $variante = new $varianteClass();
                        $varWhere = [Where::eq('idproducto', (int)$idproducto)];
                        foreach ($variante->all($varWhere, [], 0, 0) as $v) {
                            if ($v->stockfis <= 0) {
                                $v->stockfis = 1;
                                $v->save();
                            }
                        }
                    }

                    $productoClass = '\FacturaScripts\Core\Model\Producto';
                    $producto = new $productoClass();
                    if ($producto->loadFromCode($idproducto) && $producto->stockfis <= 0) {
                        $producto->stockfis = 1;
                        $producto->save();
                    }
                }
            }

            if ($action !== 'add-image') {
                return;
            }

            $idproducto = (int)$this->request->input('idproducto');
            if ($idproducto <= 0) {
                return;
            }

            $observations = $this->request->input('observations', '');
            $descripcionCorta = $this->request->input('descripcion_corta', '');

            // Find newly created file relations that have no modelcode yet (created by addImageAction)
            $fileRelation = new AttachedFileRelation();
            $imgModel = class_exists(ProductoImagen::class) ? new ProductoImagen() : null;
            $where = [
                Where::eq('model', 'Producto'),
                Where::eq('modelid', $idproducto),
                Where::isNull('modelcode'),
            ];
            foreach ($fileRelation->all($where, [], 0, 0) as $relation) {
                $relation->modelcode = (string)$idproducto;
                $relation->observations = $observations;
                $relation->save();

                // Save observations and descripcion_corta on the ProductoImagen record
                if ($imgModel !== null) {
                    $imgWhere = [Where::eq('idfile', $relation->idfile)];
                    foreach ($imgModel->all($imgWhere, [], 0, 0) as $img) {
                        if (!empty($observations)) {
                            $img->observaciones = $observations;
                        }
                        if (!empty($descripcionCorta)) {
                            $img->descripcion_corta = $descripcionCorta;
                        }
                        $img->save();
                    }
                }
            }
        };
    }
}
