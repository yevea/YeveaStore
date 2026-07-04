<?php
namespace FacturaScripts\Plugins\YeveaStore\Extension\Controller;

use Closure;
use FacturaScripts\Core\Lib\AssetManager;

/**
 * Extension for the core EditSettings (control panel) controller.
 *
 * Loads a JS asset that undoes the core auto-focus on the first input, which
 * made the page scroll down to "days until expiration" on open.
 */
class EditSettings
{
    protected function loadData(): Closure
    {
        return function ($viewName, $view) {
            $jsPath = FS_FOLDER . '/Plugins/YeveaStore/Assets/JS/SettingsScrollTop.js';
            if (file_exists($jsPath)) {
                AssetManager::addJs(FS_ROUTE . '/Plugins/YeveaStore/Assets/JS/SettingsScrollTop.js');
            }
        };
    }
}
