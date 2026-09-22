<?php
declare(strict_types=1);
namespace AV\Controller;

use AV\Model\Config;
use AV\Model\Logger;
use AV\Model\Integrity;
use AV\View;
use AV\Service\Auth;

class BaselineController
{
    public function index(): void
    {
        $CFG = Config::load();
        Auth::gate($CFG['web']);

        $log = new Logger($CFG['logs']['dir'], $CFG['logs']['file'], $CFG['logs']['max_size'], $CFG['logs']['max_files']);
        $integrity = new Integrity($CFG, $log);
        $n = $integrity->buildBaseline();

        ob_start();
        View::render('baseline', ['count' => $n]);
        $content = ob_get_clean();

        ob_start();
        View::render('layout', ['title' => 'Baseline', 'active' => 'baseline', 'content' => $content]);
        echo ob_get_clean();
    }
}
