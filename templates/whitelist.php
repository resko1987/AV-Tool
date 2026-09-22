<?php
/** @var array $data */
$msg = $data['msg'] ?? null;
$msgType = $data['msgType'] ?? 'info';
$paths = $data['paths'] ?? [];
$root = $data['root'] ?? '';
?>
<div class="av-page-title"><h1>Исключения сканера</h1><span class="hint">файлы, которые не проверяются</span></div>

<?php if ($msg): ?><div class="note <?= $msgType === 'ok' ? 'info' : $msgType ?>"><?= $msg ?></div><?php endif; ?>

<div class="av-panel">
    <h2>Добавить исключение</h2>
    <form method="post" action="av.php?action=whitelist">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <input id="p" name="add" type="text" placeholder="handler.php  или  modules/slider/slider.php  или  vendor/lib  или  cache/*"
                   style="flex:1;min-width:280px;padding:10px 14px;border-radius:8px;border:1px solid #2a3b52;background:#0e141d;color:#d7e1ec;font-family:monospace">
            <button type="submit" class="av-btn primary">Добавить</button>
        </div>
    </form>
    <div class="note info" style="margin-top:12px">
        Путь <b>относительно корня сайта</b>. Маски: <code>*</code> (любые символы), <code>?</code> (один символ).
    </div>
</div>

<div class="av-panel">
    <h2>Текущий список (<?= count($paths) ?>)</h2>
    <?php if (empty($paths)): ?>
        <div class="note info">Список пуст.</div>
    <?php else: ?>
        <table>
        <tr><th>Путь</th><th>Тип</th><th>Существует</th><th></th></tr>
        <?php foreach ($paths as $p): ?>
            <?php
            $isMask = \AV\Model\Whitelist::isMask($p);
            if ($isMask) { $isDir = null; $exists = null; }
            else { $isDir = is_dir($root . '/' . $p) && !is_file($root . '/' . $p); $exists = file_exists($root . '/' . $p); }
            ?>
            <tr>
                <td><code><?= AV\e($p) ?></code></td>
                <td><?= $isDir === null ? '<span class="b info">маска</span>' : ($isDir ? '<span class="b info">папка</span>' : '<span class="b info">файл</span>') ?></td>
                <td><?= $exists === null ? '<span class="b info">—</span>' : ($exists ? '<span class="b ok">да</span>' : '<span class="b warn">нет</span>') ?></td>
                <td><a class="av-btn danger" href="av.php?action=whitelist&do=del&del=<?= urlencode($p) ?>&confirm=1" onclick="return confirm('Убрать: <?= AV\e($p) ?>?')">Убрать</a></td>
            </tr>
        <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>

<div class="note warn">
    <b>Осторожно:</b> файлы из исключений <u>не проверяются</u> антивирусом, <u>не контролируются</u> baseline'ом целостности и <u>не попадают в бэкап</u>.
    Добавляйте только те файлы, в происхождении которых вы уверены (библиотеки, легитимные скрипты с ложными срабатываниями).
    После изменения списка можно сразу проверить: вкладка «Полный скан» → «Сделать полное сканирование».
</div>
