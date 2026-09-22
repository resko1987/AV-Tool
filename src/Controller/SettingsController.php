<?php
declare(strict_types=1);
namespace AV\Controller;

use AV\Model\Config;
use AV\Model\CmsPresets;
use AV\Model\Logger;
use AV\Model\Mailer;
use AV\View;
use AV\Service\Auth;

class SettingsController
{
    public function index(): void
    {
        $CFG = Config::load();
        Auth::gate($CFG['web']);

        $log = new Logger($CFG['logs']['dir'], $CFG['logs']['file'], $CFG['logs']['max_size'], $CFG['logs']['max_files']);
        $settingsFile = rtrim($CFG['tool_dir'], '/\\') . '/data/mail_settings.json';
        $mail = $CFG['mail'];
        $msg = null; $msgType = 'info';

        if (isset($_POST['save'])) {
            $to = trim((string)($_POST['mail_to'] ?? ''));
            $from = trim((string)($_POST['mail_from'] ?? ''));
            $enabled = !empty($_POST['mail_enabled']);
            $useSmtp = !empty($_POST['use_smtp']);
            $smtp = null;
            $errors = [];

            if ($enabled && $to === '') $errors[] = 'Укажите email получателя.';
            if ($to !== '' && !filter_var($to, FILTER_VALIDATE_EMAIL)) $errors[] = 'Некорректный email.';
            if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) $errors[] = 'Некорректный email отправителя.';

            if ($useSmtp) {
                $smtp = [
                    'host' => trim((string)($_POST['smtp_host'] ?? '')),
                    'port' => (int)($_POST['smtp_port'] ?? 587) ?: 587,
                    'user' => trim((string)($_POST['smtp_user'] ?? '')),
                    'pass' => (string)($_POST['smtp_pass'] ?? ''),
                    'secure' => in_array($_POST['smtp_secure'] ?? 'tls', ['tls', 'ssl', ''], true) ? ($_POST['smtp_secure'] ?? 'tls') : 'tls',
                ];
                if ($smtp['host'] === '') $errors[] = 'Укажите SMTP-сервер.';
                if ($from === '') $errors[] = 'При SMTP укажите email отправителя.';
            }

            if ($errors) {
                $msg = '<b>Не сохранено:</b><br>— ' . implode('<br>— ', $errors);
                $msgType = 'err';
                $mail = array_merge($mail, ['enabled' => $enabled, 'to' => $to, 'from' => $from, 'smtp' => $smtp]);
            } else {
                $data = [
                    'enabled' => $enabled, 'to' => $to,
                    'from' => $from !== '' ? $from : 'av@' . gethostname(),
                    'smtp' => $useSmtp ? $smtp : null,
                ];
                $dir = dirname($settingsFile);
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                if (file_put_contents($settingsFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false) {
                    @chmod($settingsFile, 0640);
                    $log->info("Настройки email изменены");
                    $msg = 'Настройки сохранены.'; $msgType = 'ok';
                } else {
                    $msg = 'Не удалось записать файл.'; $msgType = 'err';
                }
                $mail = array_merge($mail, $data);
            }
        }

        if (isset($_POST['testmail'])) {
            $mailer = new Mailer($CFG['mail'], $log);
            if (empty($CFG['mail']['to']) || !filter_var((string)$CFG['mail']['to'], FILTER_VALIDATE_EMAIL)) {
                $msg = 'Укажите корректный email получателя.'; $msgType = 'err';
            } elseif (empty($CFG['mail']['enabled'])) {
                $msg = 'Отправка выключена.'; $msgType = 'warn';
            } else {
                $subject = 'Тест доставки уведомлений AV Tool';
                $body = "Тестовое письмо от AV Tool.\nВремя: " . date('d.m.Y H:i:s') . "\n";
                if ($mailer->send($subject, $body)) {
                    $msg = "Тестовое письмо отправлено."; $msgType = 'ok';
                } else {
                    $msg = 'Отправка не удалась.'; $msgType = 'err';
                }
            }
        }

        $cmsMsg = null; $cmsMsgType = 'info';
        if (isset($_POST['apply_cms'])) {
            $cms = trim((string)($_POST['cms'] ?? ''));
            $preset = $cms !== '' ? CmsPresets::get($cms) : null;
            if (!$preset) {
                $cmsMsg = 'Неизвестная CMS.'; $cmsMsgType = 'err';
            } elseif (CmsPresets::isApplied($CFG, $cms)) {
                $cmsMsg = 'Исключения для ' . $preset['label'] . ' уже применены.'; $cmsMsgType = 'info';
            } elseif (CmsPresets::apply($CFG, $cms)) {
                Config::reset();
                $log->info('CMS исключения применены: ' . $preset['label'] . ' (' . count($preset['folders']) . ' папок, ' . count($preset['files']) . ' файлов)');
                $cmsMsg = 'Исключения для <b>' . $preset['label'] . '</b> применены: ' . count($preset['folders']) . ' папок, ' . count($preset['files']) . ' файлов.';
                $cmsMsgType = 'ok';
            } else {
                $cmsMsg = 'Не удалось записать файл исключений.'; $cmsMsgType = 'err';
            }
        }

        if (isset($_POST['remove_cms'])) {
            $cms = trim((string)($_POST['cms'] ?? ''));
            if ($cms !== '' && CmsPresets::remove($CFG, $cms)) {
                Config::reset();
                $log->info('CMS исключения удалены: ' . $cms);
                $cmsMsg = 'Исключения CMS удалены.'; $cmsMsgType = 'ok';
            } else {
                $cmsMsg = 'Удалить не удалось.'; $cmsMsgType = 'err';
            }
        }

        $cmsPresets = CmsPresets::presets();
        $cmsApplied = CmsPresets::applied($CFG);

        ob_start();
        View::render('settings', [
            'msg' => $msg, 'msgType' => $msgType, 'mail' => $mail,
            'cmsMsg' => $cmsMsg, 'cmsMsgType' => $cmsMsgType,
            'cmsPresets' => $cmsPresets, 'cmsApplied' => $cmsApplied,
        ]);
        $content = ob_get_clean();

        ob_start();
        View::render('layout', ['title' => 'Настройки', 'active' => 'settings', 'content' => $content, 'pageCss' => ['settings.css']]);
        echo ob_get_clean();
    }
}
