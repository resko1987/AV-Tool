<?php
declare(strict_types=1);
namespace AV\Controller;

use AV\Model\Config;
use AV\Model\Logger;
use AV\Model\Whitelist;
use AV\View;
use AV\Service\Auth;

class WhitelistController
{
    public function index(): void
    {
        $CFG = Config::load();
        Auth::gate($CFG['web']);

        $log = new Logger($CFG['logs']['dir'], $CFG['logs']['file'], $CFG['logs']['max_size'], $CFG['logs']['max_files']);
        $root = rtrim(str_replace('\\', '/', $CFG['site_root']), '/');
        $msg = null; $msgType = 'info';

        if (isset($_POST['add'])) {
            $path = ltrim(str_replace('\\', '/', trim((string)$_POST['add'])), '/');
            if ($path === '' || strpos($path, '..') !== false) {
                $msg = 'Некорректный путь.'; $msgType = 'err';
            } else {
                $base = preg_replace('/[*?].*$/', '', $path);
                $abs = $root . '/' . rtrim($base, '/');
                if (!file_exists($abs) && !Whitelist::isMask($path)) {
                    $msg = "Путь не найден: <code>" . \AV\e($path) . "</code>"; $msgType = 'warn';
                } else {
                    $n = Whitelist::add($CFG, [$path]);
                    if ($n === -1) {
                        // пути новые, но запись на диск не удалась
                        $log->error("Whitelist: НЕ сохранено (ошибка записи): $path");
                        $msg = "<b>Не сохранено:</b> не удалось записать файл <code>data/scan_whitelist.json</code> (проверьте права на каталог <code>data/</code>).";
                        $msgType = 'err';
                    } elseif ($n > 0) {
                        $log->info("Whitelist: добавлено: $path");
                        $msg = "Добавлено: <code>" . \AV\e($path) . "</code>"; $msgType = 'ok';
                    } else {
                        $msg = "Уже в списке: <code>" . \AV\e($path) . "</code>"; $msgType = 'info';
                    }
                }
            }
        }

        if (($_GET['do'] ?? '') === 'del' && isset($_GET['del']) && isset($_GET['confirm'])) {
            $path = ltrim(str_replace('\\', '/', trim((string)$_GET['del'])), '/');
            if (Whitelist::remove($CFG, $path)) {
                $log->info("Whitelist: удалено: $path");
                $msg = "Удалено: <code>" . \AV\e($path) . "</code>"; $msgType = 'ok';
            } else {
                $msg = "Не найдено: <code>" . \AV\e($path) . "</code>"; $msgType = 'err';
            }
        }

        $paths = Whitelist::load($CFG);

        ob_start();
        View::render('whitelist', ['msg' => $msg, 'msgType' => $msgType, 'paths' => $paths, 'root' => $root]);
        $content = ob_get_clean();

        ob_start();
        View::render('layout', ['title' => 'Исключения', 'active' => 'whitelist', 'content' => $content]);
        echo ob_get_clean();
    }
}
