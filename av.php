<?php
declare(strict_types=1);
/**
 * av.php — единая точка входа (CLI + WEB).
 *
 * CLI:  php av.php --backup | --scan | --full-scan | --baseline
 * WEB:  av.php?action=status | backup | scan | ...
 */

require_once __DIR__ . '/vendor/autoload.php';

use AV\Model\Config;
use AV\Model\Logger;
use AV\Model\License;
use AV\Service\Auth;
use AV\Service\Permissions;

$config = Config::load();

// Самоисправление прав на служебные каталоги/файлы
Permissions::ensure($config);
if (Permissions::actions()) {
    $log = new Logger($config['logs']['dir'], $config['logs']['file'], $config['logs']['max_size'], $config['logs']['max_files']);
    foreach (Permissions::actions() as $pAction) {
        $log->info("Permissions: $pAction");
    }
}

// ----------------------- CLI -----------------------
if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
    $kernel = new \AV\Cli\Kernel();
    exit($kernel->run($argv));
}

// ----------------------- WEB -----------------------
$action = $_GET['action'] ?? 'status';

if ($action === 'login') {
    $c = new \AV\Controller\AuthController();
    $c->login();
    exit;
}

if ($action === 'logout') {
    $c = new \AV\Controller\AuthController();
    $c->logout();
    exit;
}

if ($action === 'license') {
    $c = new \AV\Controller\LicenseController();
    $c->index();
    exit;
}

Auth::gate($config['web']);

// Лицензионный шлюз: без действующей лицензии доступна только страница
// управления лицензией (установка ключа) — бэкапы и сканирования блокируются.
if (!License::valid($config)) {
    header('Location: av.php?action=license');
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
        header('Location: av.php?action=status');
        exit;
}
