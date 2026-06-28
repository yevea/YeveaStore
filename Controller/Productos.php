<?php
namespace FacturaScripts\Plugins\YeveaStore\Controller;

class Productos extends StoreFront
{
    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['title'] = 'products';
        return $pageData;
    }

    public function run(): void
    {
        $this->autoRenderView = false;
        parent::run();
        $this->view('StoreFront.html.twig');
    }
}
