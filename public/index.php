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
use AV\Service\Auth;

$config = Config::load();

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
    case 'quarantine':
        $c = new \AV\Controller\QuarantineController();
        $c->index();
        break;
    case 'restore':
        $c = new \AV\Controller\RestoreController();
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
