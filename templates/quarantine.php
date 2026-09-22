<?php
/** @var array $data */
$msg = $data['msg'] ?? null;
$msgType = $data['msgType'] ?? 'info';
$files = $data['files'] ?? [];
$viewId = $data['viewId'] ?? null;
$viewFile = $data['viewFile'] ?? null;
$viewContent = $data['viewContent'] ?? null;
$viewTruncated = $data['viewTruncated'] ?? false;
$isText = $data['isText'] ?? false;
$qDir = $data['qDir'] ?? '';
?>
<div class="av-page-title"><h1>Карантин</h1><span class="hint">файлы сканера: <?= AV\e($qDir) ?></span></div>

<?php if ($msg): ?><div class="note <?= $msgType === 'ok' ? 'info' : $msgType ?>"><?= $msg ?></div><?php endif; ?>

<?php if ($viewFile): ?>
    <div class="av-panel">
        <h2>Просмотр файла</h2>
        <table>
        <tr><th>В карантине</th><th>Вернётся на путь</th><th>Размер</th><th>Помещён</th></tr>
        <tr>
            <td><code><?= AV\e($viewFile['rel']) ?></code></td>
            <td><code><?= AV\e($viewFile['orig']) ?></code></td>
            <td><?= $viewFile['size'] < 1024 ? $viewFile['size'] . ' Б' : round($viewFile['size']/1024,1) . ' КБ' ?></td>
            <td><?= date('d.m.Y H:i', $viewFile['mtime']) ?></td>
        </tr>
        </table>
        <?php if ($viewContent === false): ?>
            <div class="note err">Файл не читается (права или бинарный).</div>
        <?php elseif (!$isText): ?>
            <div class="note warn">Файл выглядит как бинарный — содержимое не показано.</div>
        <?php else: ?>
            <div class="av-panel" style="margin:12px 0 0">
                <pre class="qcode"><?php
                    $lines = explode("\n", $viewContent);
                    $i = 1;
                    foreach ($lines as $ln) {
                        echo "<span class='ln'>" . str_pad((string)$i, 4, ' ', STR_PAD_LEFT) . "</span>  " . AV\e($ln) . "\n";
                        $i++;
                    }
                ?></pre>
                <?php if ($viewTruncated): ?><div class="note warn">Показаны первые 200 КБ — файл больше.</div><?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="av-actions" style="margin-top:14px">
            <a class="av-btn primary" href="av.php?action=quarantine&restore=<?= $viewId ?>" onclick="return confirm('Вернуть файл на сайт: <?= AV\e($viewFile['orig']) ?>?')">Восстановить на сайт</a>
            <a class="av-btn danger" href="av.php?action=quarantine&do=delete&delete=<?= $viewId ?>&confirm=1" onclick="return confirm('Удалить файл НАВСЕГДА?')">Удалить навсегда</a>
            <a class="av-btn" href="av.php?action=quarantine">← К списку</a>
        </div>
    </div>
<?php else: ?>
    <?php if (empty($files)): ?>
        <div class="note info">Карантин пуст — заражённых файлов нет.</div>
    <?php else: ?>
        <div class="av-panel">
        <table>
        <tr><th>Файл</th><th>Путь в сайте</th><th>Размер</th><th>Помещён</th><th>Действия</th></tr>
        <?php foreach ($files as $id => $f): ?>
        <tr>
            <td><a href="av.php?action=quarantine&view=<?= $id ?>"><code><?= AV\e($f['rel']) ?></code></a></td>
            <td><code><?= AV\e($f['orig']) ?></code></td>
            <td><?= $f['size'] < 1024 ? $f['size'] . ' Б' : round($f['size']/1024,1) . ' КБ' ?></td>
            <td><?= date('d.m.Y H:i', $f['mtime']) ?></td>
            <td>
                <a class="av-btn" href="av.php?action=quarantine&view=<?= $id ?>">Просмотр</a>
                <a class="av-btn" href="av.php?action=quarantine&restore=<?= $id ?>" onclick="return confirm('Вернуть файл на сайт: <?= AV\e($f['orig']) ?>?')">Восстановить</a>
                <a class="av-btn danger" href="av.php?action=quarantine&do=delete&delete=<?= $id ?>&confirm=1" onclick="return confirm('Удалить НАВСЕГДА: <?= AV\e($f['rel']) ?>?')">Удалить</a>
            </td>
        </tr>
        <?php endforeach; ?>
        </table>
        </div>
        <div class="note warn">
            <b>Что делают действия:</b> «Просмотр» — посмотреть содержимое. «Восстановить» — вернуть файл на сайт. «Удалить» — убрать навсегда.
        </div>
    <?php endif; ?>
<?php endif; ?>
