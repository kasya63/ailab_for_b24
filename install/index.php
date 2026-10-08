<?php
/**
 * Установщик модуля local.ailab.
 * Удаление модуля НЕ удаляет таблицы и ключ шифрования: при переустановке данные сохраняются.
 */

use Bitrix\Main\Application;
use Bitrix\Main\ModuleManager;

class local_ailab extends CModule
{
    public $MODULE_ID = 'local.ailab';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'AI Lab';
    public $MODULE_DESCRIPTION = 'Сценарии запросов к ИИ для бизнес-процессов';
    public $PARTNER_NAME = 'ProPeople';
    public $PARTNER_URI = 'https://portal.propeople.kz';
    public $MODULE_GROUP_RIGHTS = 'N';

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';
        $this->MODULE_VERSION = $arModuleVersion['VERSION'] ?? '0.0.0';
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'] ?? '';
    }

    public function DoInstall()
    {
        global $APPLICATION;

        ModuleManager::registerModule($this->MODULE_ID);
        try {
            require_once dirname(__DIR__) . '/include.php';
            \Local\AiLab\Installer::install();
            $this->InstallFiles();
        } catch (\Throwable $e) {
            ModuleManager::unRegisterModule($this->MODULE_ID);
            $APPLICATION->ThrowException($e->getMessage());
        }

        $APPLICATION->IncludeAdminFile('Установка модуля AI Lab', __DIR__ . '/step.php');
    }

    public function DoUninstall()
    {
        global $APPLICATION;

        $this->UnInstallFiles();
        ModuleManager::unRegisterModule($this->MODULE_ID);

        $APPLICATION->IncludeAdminFile('Удаление модуля AI Lab', __DIR__ . '/unstep.php');
    }

    public function InstallFiles()
    {
        CopyDirFiles(__DIR__ . '/admin', Application::getDocumentRoot() . '/bitrix/admin', true, true);
        \Local\AiLab\Installer::ensureActivityStubs();
        return true;
    }

    public function UnInstallFiles()
    {
        DeleteDirFiles(__DIR__ . '/admin', Application::getDocumentRoot() . '/bitrix/admin');
        require_once dirname(__DIR__) . '/include.php';
        \Local\AiLab\Installer::removeActivityStubs();
        \Local\AiLab\Installer::removeAgent();
        return true;
    }
}
