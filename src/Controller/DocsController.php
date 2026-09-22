<?php
declare(strict_types=1);
namespace AV\Controller;

use AV\Model\Config;
use AV\View;
use AV\Service\Auth;

class DocsController
{
    public function index(): void
    {
        $CFG = Config::load();
        Auth::gate($CFG['web']);

        ob_start();
        View::render('docs', [
            'root' => $CFG['site_root'],
            'tool' => $CFG['tool_dir'],
            'php' => '/opt/php84/bin/php',
        ]);
        $content = ob_get_clean();

        ob_start();
        View::render('layout', ['title' => 'Документация', 'active' => 'docs', 'content' => $content]);
        echo ob_get_clean();
    }
}
