<?php
namespace FacturaScripts\Plugins\YeveaStore\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Where;

class EditYeveaStoreOrder extends EditController
{
    public function getModelClassName(): string
    {
        return 'YeveaStoreOrder';
    }

    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['menu'] = 'yeveastore';
        $pageData['title'] = 'order';
        $pageData['icon'] = 'fa-solid fa-shopping-bag';
        return $pageData;
    }

    protected function createViews()
    {
        $this->addEditView('EditYeveaStoreOrder', 'YeveaStoreOrder', 'order', 'fa-solid fa-shopping-bag');
        $this->addEditListView('EditYeveaStoreOrderLine', 'YeveaStoreOrderLine', 'order-lines', 'fa-solid fa-list');
    }

    protected function loadData($viewName, $view)
    {
        switch ($viewName) {
            case 'EditYeveaStoreOrderLine':
                $order_id = $this->getViewModelValue('EditYeveaStoreOrder', 'id');
                $where = [Where::eq('order_id', $order_id)];
                $view->loadData('', $where);
                break;

            default:
                parent::loadData($viewName, $view);
                break;
        }
    }
}
