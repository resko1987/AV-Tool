<?php
declare(strict_types=1);
namespace AV\Controller;

use AV\Model\Config;
use AV\Model\Logger;
use AV\Model\Quarantine;
use AV\View;
use AV\Service\Auth;

class QuarantineController
{
    public function index(): void
    {
        $CFG = Config::load();
        Auth::gate($CFG['web']);

        $log = new Logger($CFG['logs']['dir'], $CFG['logs']['file'], $CFG['logs']['max_size'], $CFG['logs']['max_files']);
        $q = new Quarantine($CFG, $log);
        $qDir = rtrim($CFG['quarantine_dir'], '/\\');
        $files = $q->listFiles();

        $msg = null; $msgType = 'info';

        // Restore
        if (isset($_GET['restore'])) {
            $id = $_GET['restore'];
            if (!isset($files[$id])) {
                $msg = 'Файл не найден в карантине.'; $msgType = 'err';
            } else {
                $f = $files[$id];
                $dest = $CFG['site_root'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $f['orig']);
                $destDir = dirname($dest);
                if (!is_dir($destDir)) mkdir($destDir, 0755, true);
                $ok = @rename($f['abs'], $dest);
                if (!$ok) { $ok = @copy($f['abs'], $dest) && @unlink($f['abs']); }
                if ($ok) {
                    @chmod($dest, 0644);
                    $log->warn("Карантин: файл возвращён: {$f['orig']}");
                    $msg = "Файл возвращён: <code>" . \AV\e($f['orig']) . "</code>"; $msgType = 'warn';
                } else {
                    $msg = 'Не удалось переместить файл.'; $msgType = 'err';
                }
                $files = $q->listFiles();
            }
        }

        // Delete
        if (($_GET['do'] ?? '') === 'delete' && isset($_GET['delete']) && isset($_GET['confirm'])) {
            $id = $_GET['delete'];
            if (!isset($files[$id])) {
                $msg = 'Файл не найден.'; $msgType = 'err';
            } else {
                $f = $files[$id];
                if (@unlink($f['abs'])) {
                    $d = dirname($f['abs']);
                    while ($d !== $qDir && str_starts_with($d, $qDir)) { @rmdir($d); $d = dirname($d); }
                    $log->warn("Карантин: удалён: {$f['rel']}");
                    $msg = "Удалён: <code>" . \AV\e($f['rel']) . "</code>"; $msgType = 'ok';
                } else {
                    $msg = 'Не удалось удалить.'; $msgType = 'err';
                }
                $files = $q->listFiles();
            }
        }

        // View
        $viewId = $_GET['view'] ?? null;
        $viewFile = ($viewId && isset($files[$viewId])) ? $files[$viewId] : null;
        $viewContent = null; $viewTruncated = false; $isText = false;
        if ($viewFile) {
            $maxView = 200 * 1024;
            $viewContent = @file_get_contents($viewFile['abs']);
            if ($viewContent !== false && strlen($viewContent) > $maxView) {
                $viewContent = substr($viewContent, 0, $maxView);
                $viewTruncated = true;
            }
            $isText = $viewContent !== false && !preg_match('/[\x00-\x08\x0E-\x1F]/', substr($viewContent, 0, 4096));
        }

        ob_start();
        View::render('quarantine', [
            'msg' => $msg, 'msgType' => $msgType, 'files' => $files,
            'viewId' => $viewId, 'viewFile' => $viewFile, 'viewContent' => $viewContent,
            'viewTruncated' => $viewTruncated, 'isText' => $isText, 'qDir' => $qDir,
        ]);
        $content = ob_get_clean();

        ob_start();
        View::render('layout', ['title' => 'Карантин', 'active' => 'quarantine', 'content' => $content, 'pageCss' => ['quarantine.css']]);
        echo ob_get_clean();
    }
}
