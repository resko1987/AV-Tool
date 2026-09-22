<?php
/** @var array $data */
$status = $data['status'] ?? [];
$msg = $data['msg'] ?? null;
$msgType = $data['msgType'] ?? 'info';
?>
<div class="av-page-title"><h1>Лицензия</h1><span class="hint">срок действия продукта</span></div>

<?php if ($msg): ?><div class="note <?= $msgType === 'ok' ? 'info' : $msgType ?>"><?= AV\e($msg) ?></div><?php endif; ?>

<div class="av-panel">
<?php if (($status['valid'] ?? false)): ?>
    <h2>Лицензия активна</h2>
    <table>
        <tr><th>Параметр</th><th>Значение</th></tr>
        <?php if (!empty($status['customer'])): ?><tr><td>Клиент</td><td><?= AV\e($status['customer']) ?></td></tr><?php endif; ?>
        <tr><td>Действует до</td><td><span class="b ok"><?= AV\e(date('d.m.Y', (int)$status['exp'])) ?></span></td></tr>
        <tr><td>Осталось</td><td><?= (int)($status['daysLeft'] ?? 0) ?> дн</td></tr>
    </table>
<?php else: ?>
    <?php
        $stateTxt = [
            'missing'  => 'Лицензионный ключ не установлен. Резервное копирование и антивирусные проверки остановлены.',
            'invalid'  => 'Лицензионный ключ некорректен. Резервное копирование и антивирусные проверки остановлены.',
            'tampered' => 'Лицензионный ключ подделан. Резервное копирование и антивирусные проверки остановлены.',
            'expired'  => 'Срок действия лицензии истёк ' . AV\e(date('d.m.Y', (int)($status['exp'] ?? 0))) . '. Резервное копирование и антивирусные проверки остановлены.',
        ];
        $txt = $stateTxt[$status['state'] ?? 'missing'] ?? '';
    ?>
    <h2>Продукт не активирован</h2>
    <div class="note err"><?= $txt ?></div>
<?php endif; ?>
</div>

<div class="av-panel">
    <h2>Установка ключа</h2>
    <form method="post" action="av.php?action=license">
        <div class="flabel">Лицензионный ключ</div>
        <input class="finput" type="text" name="license_key" placeholder="AV1-..." autocomplete="off" required>
        <div class="av-actions">
            <button type="submit" name="install" value="1" class="av-btn primary">Установить</button>
            <a class="av-btn" href="av.php?action=status">К статусу</a>
        </div>
    </form>
    <div class="note info" style="margin-top:12px">Ключ выдаётся владельцем продукта. Он проверяется локально
    криптографической подписью — без интернета и без передачи данных на внешние серверы.</div>
</div>