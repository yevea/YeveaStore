<?php
namespace FacturaScripts\Plugins\YeveaStore\Controller;

use FacturaScripts\Core\Controller\EditSettings;
use FacturaScripts\Core\Lib\AssetManager;
use FacturaScripts\Core\Model\Settings;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Producto;

/**
 * Admin → YeveaStore: the store's control page.
 *
 * Tabs: 1) AI-bot dashboard (daily reports)  2) store settings
 *       3) content plan (editable)           4) reviews tracking (editable)
 *
 * Editable docs live in MyFiles/ (survive plugin updates) and are seeded
 * from the plugin's Docs/ folder on first load.
 */
class SettingsYeveaStore extends EditSettings
{
    /** Editable docs: key => [MyFiles file, seed file in plugin Docs/] */
    private const DOCS = [
        'content-plan' => 'yeveastore-content-plan.md',
        'reviews' => 'yeveastore-reviews-plan.md',
    ];

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'settings-yeveastore';
        $data['icon'] = 'fa-solid fa-store';
        return $data;
    }

    /**
     * Daily AI-bot reports written by Scripts/ai-bot-report.sh (cron),
     * newest first. Used by the dashboard tab.
     *
     * @return array<string, string> date => report text
     */
    public function getBotReports(int $limit = 14): array
    {
        $dir = FS_FOLDER . '/MyFiles/yeveastore-reports';
        if (false === is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.txt') ?: [];
        rsort($files);

        $reports = [];
        foreach (array_slice($files, 0, $limit) as $file) {
            $reports[basename($file, '.txt')] = (string) file_get_contents($file);
        }
        return $reports;
    }

    /**
     * Products captured from the warehouse PWA awaiting approval, newest
     * first, with their first image. Used by the YeveaCaptura tab.
     *
     * @return object[]
     */
    public function getPendingCaptures(): array
    {
        $producto = new Producto();
        $where = [Where::eq('captura_pendiente', true)];
        $pending = $producto->all($where, ['actualizado' => 'DESC'], 0, 0);

        // First image of each pending product (single query, avoids N+1)
        $imageMap = [];
        $imgClass = '\FacturaScripts\Dinamic\Model\ProductoImagen';
        if (class_exists($imgClass) && !empty($pending)) {
            $ids = array_map(fn($p) => $p->idproducto, $pending);
            foreach ((new $imgClass())->all([Where::in('idproducto', $ids)], ['orden' => 'ASC'], 0, 0) as $img) {
                if (!isset($imageMap[$img->idproducto])) {
                    $imageMap[$img->idproducto] = $img->url('download-permanent');
                }
            }
        }

        return array_map(fn($p) => (object) [
            'idproducto' => $p->idproducto,
            'referencia' => $p->referencia,
            'descripcion' => $p->descripcion,
            'observaciones' => $p->observaciones ?? '',
            'largo' => $p->largo ?? null,
            'ancho' => $p->ancho ?? null,
            'espesor' => $p->espesor ?? null,
            'peso' => $p->peso ?? null,
            'precio' => (float) $p->precio,
            'actualizado' => $p->actualizado ?? '',
            'image' => $imageMap[$p->idproducto] ?? null,
        ], $pending);
    }

    /** Content of an editable doc, for the doc tabs. */
    public function getYeveaDoc(string $key): string
    {
        $path = $this->docPath($key);
        if ($path !== null && file_exists($path)) {
            return (string) file_get_contents($path);
        }
        return '';
    }

    protected function createViews()
    {
        $this->setTemplate('EditSettings');

        // 1) Dashboard first: it is what opens by default
        $this->addHtmlView('YeveaStoreDashboard', 'YeveaStoreDashboard', 'Settings', 'dashboard', 'fa-solid fa-chart-line');

        // 2) Store settings. The XMLView is named YeveaStoreAjustes (NOT Settings*)
        //    on purpose: the core EditSettings page scans Settings*.xml files, and
        //    this store must be managed ONLY from this page.
        $this->addEditView('YeveaStoreAjustes', 'Settings', 'store-settings', 'fa-solid fa-sliders');
        $this->setSettings('YeveaStoreAjustes', 'btnDelete', false);
        $this->setSettings('YeveaStoreAjustes', 'btnNew', false);

        // 3) + 4) Editable docs
        $this->addHtmlView('YeveaStorePlan', 'YeveaStorePlan', 'Settings', 'content-plan', 'fa-solid fa-pen-to-square');
        $this->addHtmlView('YeveaStoreResenas', 'YeveaStoreResenas', 'Settings', 'reviews-tracking', 'fa-solid fa-star-half-stroke');

        // 5) YeveaCaptura: launcher + install help for the warehouse PWA
        $this->addHtmlView('YeveaStoreCaptura', 'YeveaStoreCaptura', 'Settings', 'yeveacaptura', 'fa-solid fa-camera');

        // "Visit site" and "Orders" links, always visible at the top.
        foreach (array_keys($this->views) as $viewName) {
            $this->addButton($viewName, [
                'action' => 'productos',
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

        // Undo the core auto-focus that scrolls the page to the first input
        $jsPath = FS_FOLDER . '/Plugins/YeveaStore/Assets/JS/SettingsScrollTop.js';
        if (file_exists($jsPath)) {
            AssetManager::addJs(FS_ROUTE . '/Plugins/YeveaStore/Assets/JS/SettingsScrollTop.js');
        }
    }

    protected function execPreviousAction($action)
    {
        if ($action === 'save-yeveastore-doc') {
            $this->saveYeveaDoc();
            return true;
        }

        if ($action === 'approve-capture') {
            $this->approveCapture();
            return true;
        }

        if ($action === 'insert'
            && $this->active === 'YeveaStoreAjustes'
            && isset($this->views[$this->active])
            && $this->views[$this->active]->model instanceof Settings
            && empty($this->views[$this->active]->model->name)) {
            $this->views[$this->active]->model->name = 'yeveastore';
        }

        return parent::execPreviousAction($action);
    }

    protected function loadData($viewName, $view)
    {
        switch ($viewName) {
            case 'YeveaStoreDashboard':
                // The dashboard is the MAIN view but holds no model data;
                // without this flag PanelController disables every other tab.
                $this->hasData = true;
                $this->seedDocs();
                break;

            case 'YeveaStorePlan':
            case 'YeveaStoreResenas':
                $this->seedDocs();
                break;

            case 'YeveaStoreCaptura':
                break;

            case 'YeveaStoreAjustes':
                $view->loadData('yeveastore');
                if ($view->model instanceof Settings && empty($view->model->name)) {
                    $view->model->name = 'yeveastore';
                }
                break;

            default:
                parent::loadData($viewName, $view);
                break;
        }
    }

    /** Approves a warehouse capture: it becomes visible under the normal
     *  visibility rules (familia pública / producto público). */
    private function approveCapture(): void
    {
        if (false === $this->validateFormToken()) {
            return;
        }

        $id = (int) $this->request->request->get('idproducto', 0);
        $producto = new Producto();
        if ($id > 0 && $producto->loadFromCode($id) && !empty($producto->captura_pendiente)) {
            $producto->captura_pendiente = false;
            if ($producto->save()) {
                Tools::log()->notice('record-updated-correctly');
                return;
            }
        }
        Tools::log()->warning('record-save-error');
    }

    private function docPath(string $key): ?string
    {
        return isset(self::DOCS[$key]) ? FS_FOLDER . '/MyFiles/' . self::DOCS[$key] : null;
    }

    private function saveYeveaDoc(): void
    {
        if (false === $this->validateFormToken()) {
            return;
        }

        $key = (string) $this->request->request->get('doc', '');
        $path = $this->docPath($key);
        if ($path === null) {
            return;
        }

        file_put_contents($path, (string) $this->request->request->get('doc_content', ''));
        Tools::log()->notice('record-updated-correctly');
    }

    /** Copies the default docs from Plugins/YeveaStore/Docs to MyFiles on first use. */
    private function seedDocs(): void
    {
        foreach (self::DOCS as $file) {
            $target = FS_FOLDER . '/MyFiles/' . $file;
            $seed = FS_FOLDER . '/Plugins/YeveaStore/Docs/' . $file;
            if (false === file_exists($target) && file_exists($seed)) {
                copy($seed, $target);
            }
        }
    }
}
