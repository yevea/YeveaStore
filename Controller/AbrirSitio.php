<?php
namespace FacturaScripts\Plugins\YeveaStore\Controller;

use FacturaScripts\Core\Template\Controller;

/**
 * Admin-menu shortcut to the public storefront. Registered under its own
 * top-level menu key 'abrir-sitio': MenuManager sorts menus alphabetically by
 * the untranslated key, so it appears FIRST in the navigation bar (before
 * 'accounting'/'admin'). Clicking the item redirects to /Productos.
 */
class AbrirSitio extends Controller
{
    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['menu'] = 'abrir-sitio';
        $pageData['title'] = 'visit-site';
        $pageData['icon'] = 'fa-solid fa-store';
        $pageData['showonmenu'] = true;
        return $pageData;
    }

    public function run(): void
    {
        parent::run();

        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        header('Location: ' . $scriptDir . '/Productos', true, 302);
        exit;
    }
}
