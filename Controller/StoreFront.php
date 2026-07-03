<?php
namespace FacturaScripts\Plugins\YeveaStore\Controller;

use FacturaScripts\Core\Template\Controller;

/**
 * Legacy route kept only for SEO: /StoreFront was indexed/linked in the past,
 * so it now 301-redirects permanently to /Productos preserving query params.
 */
class StoreFront extends Controller
{
    protected $requiresAuth = false;

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

        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        $query = $_SERVER['QUERY_STRING'] ?? '';
        $target = $scriptDir . '/Productos' . ($query !== '' ? '?' . $query : '');

        header('Location: ' . $target, true, 301);
        exit;
    }
}
