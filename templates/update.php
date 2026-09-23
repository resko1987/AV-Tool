<?php
/** @var string|null $msg */
/** @var string $msgType */
/** @var array|null $check */
/** @var string|null $checkError */
/** @var array|null $steps */
/** @var array $backups */
/** @var string $repo */
?>
<div class="av-page-title"><h1>Обновление программы</h1><span class="hint">файлы подтягиваются из GitHub</span></div>

<?php if ($msg): ?><div class="note <?= AV\e($msgType) ?>"><?= $msg ?></div><?php endif; ?>

<?php if ($checkError !== null): ?>
    <div class="note err">
        <b>Не удалось проверить обновления:</b> <?= AV\e($checkError) ?><br>
        <span class="hint">Репозиторий: <code><?= AV\e($repo) ?></code>. Возможные причины: нет сети/curl на сервере
        или исчерпан анонимный лимит GitHub API (60 запросов/час с одного IP).</span>
    </div>
<?php elseif ($check !== null): ?>

<div class="av-panel">
    <h2>Статус</h2>
    <table>
        <tr><td style="width:220px">Текущая версия</td><td><code><?= AV\e((string)$check['current']) ?></code></td></tr>
        <tr><td>Последняя на GitHub</td><td>
            <?php if ($check['latest']): ?>
                <code><?= AV\e((string)$check['latest']['tag']) ?></code>
                <?php if (!empty($check['latest']['name']) && $check['latest']['name'] !== $check['latest']['tag']): ?>
                    — <?= AV\e((string)$check['latest']['name']) ?>
                <?php endif; ?>
            <?php else: ?><span class="b warn">нет данных</span><?php endif; ?>
        </td></tr>
        <tr><td>Статус</td><td>
            <?= $check['updateAvailable']
                ? '<span class="b warn">отличается от последней версии на GitHub — требуется обновление</span>'
                : '<span class="b ok">совпадает с последней версией на GitHub</span>' ?>
        </td></tr>
    </table>

    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px">
        <form method="post" action="av.php?action=update" style="display:inline">
            <input type="hidden" name="do" value="check">
            <button type="submit" class="av-btn">Проверить снова</button>
        </form>
        <?php if ($check['updateAvailable'] && $check['latest']): ?>
        <form method="post" action="av.php?action=update" style="display:inline"
              onsubmit="return confirm('Обновить программу до версии <?= AV\e((string)$check['latest']['tag']) ?>?\nПеред обновлением будет сделан бэкап текущих файлов.')">
            <input type="hidden" name="do" value="apply">
            <input type="hidden" name="tag" value="<?= AV\e((string)$check['latest']['tag']) ?>">
            <button type="submit" class="av-btn primary">Обновить до <?= AV\e((string)$check['latest']['tag']) ?></button>
        </form>
        <?php endif; ?>
        <?php if ($backups !== []): ?>
        <form method="post" action="av.php?action=update" style="display:inline"
              onsubmit="return confirm('Откатить программу на предыдущую версию?')">
            <input type="hidden" name="do" value="rollback">
            <button type="submit" class="av-btn danger">Откат на предыдущую</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($steps !== null && $steps !== []): ?>
<div class="av-panel">
    <h2>Протокол операции</h2>
    <ol class="av-steps">
        <?php foreach ($steps as $s): ?><li><?= AV\e($s) ?></li><?php endforeach; ?>
    </ol>
</div>
<?php endif; ?>

<?php if ($check['latest'] && (string)($check['latest']['notes'] ?? '') !== ''): ?>
<div class="av-panel">
    <h2>Что нового в <?= AV\e((string)$check['latest']['tag']) ?></h2>
    <div class="note info" style="white-space:pre-wrap"><?= AV\e((string)$check['latest']['notes']) ?></div>
</div>
<?php endif; ?>

<?php if (count($check['releases']) > 1): ?>
<div class="av-panel">
    <h2>Доступные версии</h2>
    <table>
        <tr><th>Версия</th><th>Название</th><th>Статус</th><th></th></tr>
        <?php foreach ($check['releases'] as $r): ?>
        <tr>
            <td><code><?= AV\e((string)$r['tag']) ?></code></td>
            <td><?= AV\e((string)($r['name'] ?? '')) ?></td>
            <td><?= $r['normalized'] === \AV\Model\Updater::normalize((string)$check['current'])
                    ? '<span class="b ok">установлена</span>' : '' ?></td>
            <td>
                <?php if ($r['normalized'] !== \AV\Model\Updater::normalize((string)$check['current'])): ?>
                <form method="post" action="av.php?action=update" style="display:inline"
                      onsubmit="return confirm('Установить версию <?= AV\e((string)$r['tag']) ?>?')">
                    <input type="hidden" name="do" value="apply">
                    <input type="hidden" name="tag" value="<?= AV\e((string)$r['tag']) ?>">
                    <button type="submit" class="av-btn sm">Установить</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>

<?php if ($backups !== []): ?>
<div class="av-panel">
    <h2>Бэкапы перед обновлениями</h2>
    <table>
        <tr><th>Версия / дата</th><th>Размер</th><th>Создан</th></tr>
        <?php foreach ($backups as $b): ?>
        <tr>
            <td><code><?= AV\e($b['label']) ?></code></td>
            <td><?= $b['size'] > 1048576 ? round($b['size'] / 1048576, 1) . ' МБ' : round($b['size'] / 1024) . ' КБ' ?></td>
            <td><?= AV\e(date('Y-m-d H:i', $b['mtime'])) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <div class="note info" style="margin-top:10px">Хранятся последние 3 бэкапа — этого достаточно для отката.</div>
</div>
<?php endif; ?>

<?php endif; ?>
