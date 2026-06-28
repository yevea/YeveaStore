<?php
namespace FacturaScripts\Plugins\YeveaStore\Controller;

use FacturaScripts\Core\Tools;

class Productos extends StoreFront
{
    public string $seoTitle = 'Madera de Olivo, Aceite de Oliva y Olivas | Yevea';
    public string $seoDescription = 'Productos artesanales de olivar: madera de olivo, aceite de oliva virgen extra y olivas. Calidad Yevea.';
    public bool $noindex = true;

    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['title'] = 'products';
        return $pageData;
    }

    public function run(): void
    {
        $this->noindex = (bool) Tools::settings('yeveastore', 'noindex', true);
        $this->autoRenderView = false;
        parent::run();
        $this->view('Productos.html.twig');
    }
}
