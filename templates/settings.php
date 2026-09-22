<?php
/** @var array $data */
$msg = $data['msg'] ?? null;
$msgType = $data['msgType'] ?? 'info';
$mail = $data['mail'] ?? [];
$cmsMsg = $data['cmsMsg'] ?? null;
$cmsMsgType = $data['cmsMsgType'] ?? 'info';
$cmsPresets = $data['cmsPresets'] ?? [];
$cmsApplied = $data['cmsApplied'] ?? [];
?>
<div class="av-page-title"><h1>Настройки уведомлений</h1><span class="hint">email при обнаружении угроз</span></div>

<?php if ($msg): ?><div class="note <?= $msgType === 'ok' ? 'info' : $msgType ?>"><?= $msg ?></div><?php endif; ?>

<form method="post" action="av.php?action=settings">
<div class="av-panel">
    <h2>Уведомления на почту</h2>
    <div class="flabel">
        <label><input type="checkbox" class="chk" name="mail_enabled" value="1" <?= !empty($mail['enabled']) ? 'checked' : '' ?>>
        Слать уведомления</label>
    </div>

    <div class="flabel">Куда отправлять (получатель)</div>
    <input class="finput" type="email" name="mail_to" value="<?= AV\e($mail['to'] ?? '') ?>" placeholder="admin@example.com">

    <div class="flabel">От кого (From)</div>
    <input class="finput" type="email" name="mail_from" value="<?= AV\e($mail['from'] ?? '') ?>" placeholder="av@<?= AV\e(gethostname()) ?>">

    <fieldset>
        <legend>Способ отправки</legend>
        <label style="display:block;margin:6px 0"><input type="radio" class="chk" name="use_smtp" value="0" <?= empty($mail['smtp']) ? 'checked' : '' ?> onchange="sm(this.value)"> mail() PHP</label>
        <label style="display:block;margin:6px 0"><input type="radio" class="chk" name="use_smtp" value="1" <?= !empty($mail['smtp']) ? 'checked' : '' ?> onchange="sm(this.value)"> SMTP-сервер</label>

        <div id="smtpblock" style="display:<?= !empty($mail['smtp']) ? 'block' : 'none' ?>;margin-top:10px">
            <div class="flabel">SMTP-сервер</div>
            <input class="finput" type="text" name="smtp_host" value="<?= AV\e($mail['smtp']['host'] ?? '') ?>" placeholder="smtp.example.com">
            <div class="flabel">Порт</div>
            <input class="finput" type="number" name="smtp_port" value="<?= (int)($mail['smtp']['port'] ?? 587) ?>" placeholder="587">
            <div class="flabel">Шифрование</div>
            <select name="smtp_secure" class="finput">
                <?php $sec = (string)($mail['smtp']['secure'] ?? 'tls'); ?>
                <option value="tls"<?= $sec === 'tls' ? ' selected' : '' ?>>TLS</option>
                <option value="ssl"<?= $sec === 'ssl' ? ' selected' : '' ?>>SSL</option>
                <option value=""<?= $sec === '' ? ' selected' : '' ?>>Без шифрования</option>
            </select>
            <div class="flabel">Логин</div>
            <input class="finput" type="text" name="smtp_user" value="<?= AV\e($mail['smtp']['user'] ?? '') ?>">
            <div class="flabel">Пароль</div>
            <input class="finput" type="password" name="smtp_pass" value="<?= AV\e($mail['smtp']['pass'] ?? '') ?>">
        </div>
    </fieldset>

    <div class="av-actions">
        <button type="submit" name="save" value="1" class="av-btn primary">Сохранить</button>
        <button type="submit" name="testmail" value="1" class="av-btn">Тестовое письмо</button>
    </div>
</div>
</form>

<script>
function sm(v){ document.getElementById('smtpblock').style.display = v === '1' ? 'block' : 'none'; }
</script>

<div class="av-panel" style="margin-top:20px">
    <h2>Исключения для CMS</h2>
    <div class="hint">папки и файлы популярных CMS, которые исключаются из сканирования (но попадают в бэкап)</div>

    <?php if ($cmsMsg): ?><div class="note <?= $cmsMsgType === 'ok' ? 'info' : $cmsMsgType ?>" style="margin-top:10px"><?= $cmsMsg ?></div><?php endif; ?>

    <table class="cms-table">
        <tr>
            <th>CMS</th>
            <th>Папки</th>
            <th>Файлы</th>
            <th>Статус</th>
            <th></th>
        </tr>
        <?php foreach ($cmsPresets as $key => $preset): ?>
        <?php $isApplied = in_array($key, $cmsApplied, true); ?>
        <tr>
            <td><b><?= AV\e($preset['label']) ?></b></td>
            <td><code><?= AV\e(implode(', ', $preset['folders'])) ?></code></td>
            <td><code><?= AV\e(implode(', ', $preset['files'])) ?></code></td>
            <td><?= $isApplied ? '<span class="b ok">применено</span>' : '<span class="b warn">не применено</span>' ?></td>
            <td>
                <form method="post" action="av.php?action=settings" style="display:inline">
                    <input type="hidden" name="cms" value="<?= AV\e($key) ?>">
                    <?php if ($isApplied): ?>
                        <button type="submit" name="remove_cms" value="1" class="av-btn danger" onclick="return confirm('Убрать исключения для <?= AV\e($preset['label']) ?>?')">Убрать</button>
                    <?php else: ?>
                        <button type="submit" name="apply_cms" value="1" class="av-btn primary">Применить</button>
                    <?php endif; ?>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>

    <div class="note info" style="margin-top:12px">
        <b>Принцип работы:</b> «Применить» добавляет указанные папки и файлы CMS в белый список сканера
        (<code>data/scan_whitelist.json</code>). Они появятся на вкладке <b>«Исключения»</b>.
        Файлы из исключений <u>не проверяются</u> антивирусом, не учитываются в baseline целостности и
        <u>не попадают в бэкап</u>. Раздел поможет избежать ложных срабатываний на легитимных
        файлах ядра WordPress после установки антивируса на CMS.
    </div>
</div>
