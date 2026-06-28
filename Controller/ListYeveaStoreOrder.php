<?php
namespace FacturaScripts\Plugins\YeveaStore\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;

class ListYeveaStoreOrder extends ListController
{
    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['menu'] = 'yeveastore';
        $pageData['title'] = 'orders';
        $pageData['icon'] = 'fa-solid fa-shopping-bag';
        $pageData['showonmenu'] = false;
        return $pageData;
    }

    protected function createViews($viewName = 'ListYeveaStoreOrder')
    {
        $this->addView($viewName, 'YeveaStoreOrder', 'orders', 'fa-solid fa-shopping-bag')
            ->addSearchFields(['code', 'customer_name', 'customer_email'])
            ->addFilterSelect('status', 'status', 'status', [
                ['code' => 'pending', 'description' => 'pending'],
                ['code' => 'processing', 'description' => 'processing'],
                ['code' => 'completed', 'description' => 'completed'],
                ['code' => 'cancelled', 'description' => 'cancelled'],
            ])
            ->addOrderBy(['creation_date'], 'creation-date', 2)
            ->addOrderBy(['total'], 'total')
            ->addOrderBy(['code'], 'code');
    }
}
