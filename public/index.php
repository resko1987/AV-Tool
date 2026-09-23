<?php
declare(strict_types=1);
/**
 * public/index.php — фронт-контроллер AV Tool.
 *
 * Все HTTP-запросы проходят сюда. Маршрутизация по ?action=.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use AV\Model\Config;
use AV\Model\License;
use AV\Model\Logger;
use AV\Service\Auth;
use AV\Service\Permissions;

$config = Config::load();

// Самоисправление прав на служебные каталоги/файлы (в т.ч. data/nginx.conf → 0640)
Permissions::ensure($config);
if (Permissions::actions()) {
    $log = new Logger($config['logs']['dir'], $config['logs']['file'], $config['logs']['max_size'], $config['logs']['max_files']);
    foreach (Permissions::actions() as $pAction) {
        $log->info("Permissions: $pAction");
    }
}

$action = $_GET['action'] ?? 'status';

// Публичные страницы (без авторизации)
if ($action === 'login') {
    $controller = new \AV\Controller\AuthController();
    $controller->login();
    exit;
}

if ($action === 'logout') {
    $controller = new \AV\Controller\AuthController();
    $controller->logout();
    exit;
}

if ($action === 'license') {
    $controller = new \AV\Controller\LicenseController();
    $controller->index();
    exit;
}

// Все остальные страницы требуют авторизации
Auth::gate($config['web']);

// Лицензионный шлюз: без действующей лицензии доступна только страница
// управления лицензией (установка ключа) — бэкапы и сканирования блокируются.
if (!License::valid($config)) {
    header('Location: index.php?action=license');
    exit;
}

switch ($action) {
    case 'status':
        $c = new \AV\Controller\DashboardController();
        $c->index();
        break;
    case 'backup':
        $c = new \AV\Controller\BackupController();
        $c->index();
        break;
    case 'scan':
        $c = new \AV\Controller\ScanController();
        $c->index();
        break;
    case 'fullscan':
        $c = new \AV\Controller\ScanController();
        $c->fullscan();
        break;
    case 'baseline':
        $c = new \AV\Controller\BaselineController();
        $c->index();
        break;
    case 'audit':
        $c = new \AV\Controller\AuditController();
        $c->index();
        break;
    case 'quarantine':
        $c = new \AV\Controller\QuarantineController();
        $c->index();
        break;
    case 'restore':
        $c = new \AV\Controller\RestoreController();
        $c->index();
        break;
    case 'update':
        $c = new \AV\Controller\UpdateController();
        $c->index();
        break;
    case 'settings':
        $c = new \AV\Controller\SettingsController();
        $c->index();
        break;
    case 'whitelist':
        $c = new \AV\Controller\WhitelistController();
        $c->index();
        break;
    case 'docs':
        $c = new \AV\Controller\DocsController();
        $c->index();
        break;
    default:
        header('Location: index.php?action=status');
        exit;
}
