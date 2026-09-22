<?php
declare(strict_types=1);
namespace AV\Controller;

use AV\Model\Config;
use AV\Model\License;
use AV\Model\Logger;
use AV\View;
use AV\Service\Auth;

class LicenseController
{
    public function index(): void
    {
        $CFG = Config::load();
        Auth::gate($CFG['web']);

        $log = new Logger($CFG['logs']['dir'], $CFG['logs']['file'], $CFG['logs']['max_size'], $CFG['logs']['max_files']);
        $msg = null; $msgType = 'info';

        if (isset($_POST['install'])) {
            $key = (string)($_POST['license_key'] ?? '');
            [$ok, $text] = License::install($CFG, $key);
            if ($ok) {
                $log->info('License: ключ установлен');
                $msg = $text; $msgType = 'ok';
                header('Location: av.php?action=status');
                exit;
            }
            $log->warn('License: попытка установки некорректного ключа');
            $msg = $text; $msgType = 'err';
        }

        $status = License::status($CFG);

        ob_start();
        View::render('license', ['status' => $status, 'msg' => $msg, 'msgType' => $msgType]);
        $content = ob_get_clean();

        ob_start();
        View::render('layout', ['title' => 'Лицензия', 'active' => 'license', 'content' => $content]);
        echo ob_get_clean();
    }
}