<?php
namespace FacturaScripts\Plugins\YeveaStore\Controller;

use FacturaScripts\Core\Template\Controller;

/**
 * Legacy route kept only for SEO: /Tableros was indexed/linked in the past,
 * so it now 301-redirects permanently to /Productos preserving query params
 * (?cat=Slug, dimension filters, ?lang=).
 */
class Tableros extends Controller
{
    protected $requiresAuth = false;

    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['menu'] = 'yeveastore';
        $pageData['title'] = 'tableros';
        $pageData['icon'] = 'fa-solid fa-store';
        $pageData['showonmenu'] = false;
        return $pageData;
    }

    public function run(): void
    {
        parent::run();

        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        $query = $_SERVER['QUERY_STRING'] ?? '';
        $target = $scriptDir . '/productos' . ($query !== '' ? '?' . $query : '');

        header('Location: ' . $target, true, 301);
        exit;
    }
}
