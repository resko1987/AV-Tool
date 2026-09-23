<?php
/** @var array $data */
$action = $data['action'] ?? 'list';
$stamp = $data['stamp'] ?? '';
$what = $data['what'] ?? 'all';
$backups = $data['backups'] ?? [];
$restoreOutput = $data['restoreOutput'] ?? '';
$msg = $data['msg'] ?? null;
$msgType = $data['msgType'] ?? 'info';
?>
<div class="av-page-title"><h1>Восстановление из бэкапа</h1><span class="hint">файлы и/или БД</span></div>

<?php if ($msg): ?><div class="note <?= $msgType === 'ok' ? 'info' : $msgType ?>"><?= $msg ?></div><?php endif; ?>

<?php if ($action === 'restore' && $restoreOutput !== ''): ?>
    <?php $hasErrors = strpos($restoreOutput, 'ОШИБК') !== false || strpos($restoreOutput, '! ') !== false; ?>
    <div class="note <?= $hasErrors ? 'err' : 'ok' ?>"><?= $hasErrors
        ? 'Восстановление завершено С ОШИБКАМИ — часть файлов не перезаписана (см. список ниже). Обычно причина — файл принадлежит другому пользователю (root) или снято право записи; исправьте владельца/права и повторите.'
        : 'Восстановление из <code>' . AV\e($stamp) . '</code> выполнено.' ?></div>
    <div class="av-panel"><pre><?= $restoreOutput ?></pre></div>
    <div class="av-actions"><a class="av-btn" href="av.php?action=restore">← К списку бэкапов</a></div>
<?php elseif (empty($backups)): ?>
    <div class="note err">Бэкапов пока нет. Создайте первый: <a href="av.php?action=backup">Бэкап</a>.</div>
<?php else: ?>
    <div class="av-panel"><h2>Доступные бэкапы</h2>
    <table><tr><th>Бэкап</th><th>Содержимое</th><th>Восстановить</th><th></th></tr>
    <?php foreach ($backups as $stampName => $b): ?>
        <?php $f = $b['files'] ? "<span class='b ok'>файлы</span>" : "<span class='b err'>—</span>"; ?>
        <?php $d = $b['db'] ? "<span class='b ok'>БД</span>" : "<span class='b err'>—</span>"; ?>
        <tr><td><code><?= AV\e($stampName) ?></code></td><td><?= $f ?> <?= $d ?></td>
        <td>
            <a class="av-btn danger" href="av.php?action=restore&do=restore&stamp=<?= urlencode($stampName) . "&what=all" ?>">Всё</a>
            <a class="av-btn" href="av.php?action=restore&do=restore&stamp=<?= urlencode($stampName) . "&what=files" ?>">Файлы</a>
            <a class="av-btn" href="av.php?action=restore&do=restore&stamp=<?= urlencode($stampName) . "&what=db" ?>">БД</a>
        </td>
        <td><a class="av-btn danger" href="av.php?action=restore&do=del&stamp=<?= urlencode($stampName) ?>" onclick="return confirm('Удалить бэкап: <?= AV\e($stampName) ?>?')">Удалить</a></td></tr>
    <?php endforeach; ?>
    </table></div>
<?php endif; ?>
