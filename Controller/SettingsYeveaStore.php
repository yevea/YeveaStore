<?php
namespace FacturaScripts\Plugins\YeveaStore\Controller;

use FacturaScripts\Core\Controller\EditSettings;
use FacturaScripts\Core\Lib\AssetManager;
use FacturaScripts\Core\Model\Settings;

class SettingsYeveaStore extends EditSettings
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'settings-yeveastore';
        $data['icon'] = 'fa-solid fa-store';
        return $data;
    }

    protected function createViews()
    {
        // Only the YeveaStore tab: the full settings panel (all SettingsXXX tabs,
        // api-keys, sequences…) already lives in the core EditSettings page. This
        // page must open directly on the store settings.
        $this->setTemplate('EditSettings');
        $this->createViewsSettings('SettingsYeveaStore', 'Settings', $this->getPageData()['icon']);

        // Undo the core auto-focus that scrolls the page to the first input
        $jsPath = FS_FOLDER . '/Plugins/YeveaStore/Assets/JS/SettingsScrollTop.js';
        if (file_exists($jsPath)) {
            AssetManager::addJs(FS_ROUTE . '/Plugins/YeveaStore/Assets/JS/SettingsScrollTop.js');
        }

        // "Visit site" and "Orders" links, always visible at the top.
        // The store admin pages are hidden from the main menu, so these buttons
        // are the UI entry points to the storefront and the orders list.
        foreach (array_keys($this->views) as $viewName) {
            $this->addButton($viewName, [
                'action' => 'Productos',
                'color' => 'info',
                'icon' => 'fa-solid fa-store',
                'label' => 'visit-site',
                'type' => 'link',
            ]);
            $this->addButton($viewName, [
                'action' => 'ListYeveaStoreOrder',
                'color' => 'secondary',
                'icon' => 'fa-solid fa-shopping-bag',
                'label' => 'orders',
                'type' => 'link',
            ]);
        }
    }

    protected function execPreviousAction($action)
    {
        if ($action === 'insert'
            && $this->active === 'SettingsYeveaStore'
            && isset($this->views[$this->active])
            && $this->views[$this->active]->model instanceof Settings
            && empty($this->views[$this->active]->model->name)) {
            $this->views[$this->active]->model->name = 'yeveastore';
        }

        return parent::execPreviousAction($action);
    }

    protected function loadData($viewName, $view)
    {
        parent::loadData($viewName, $view);

        if ($viewName === 'SettingsYeveaStore'
            && $view->model instanceof Settings
            && empty($view->model->name)) {
            $view->model->name = 'yeveastore';
        }
    }
}
