<?php
/** @var array $data */
$backup = $data['backup'] ?? null;
$dbState = $data['dbState'] ?? ['ok' => false, 'msg' => ''];
$cronBackup = $data['cronBackup'] ?? 0;
$cronScan = $data['cronScan'] ?? 0;
$qCount = $data['qCount'] ?? 0;
$hasBase = $data['hasBase'] ?? false;
$antivirusEnabled = $data['antivirusEnabled'] ?? false;
$autoRestore = $data['autoRestore'] ?? false;
$license = $data['license'] ?? null;
$srv = $data['srv'] ?? [];
$bCount = $data['bCount'] ?? 0;
$bSize = $data['bSize'] ?? 0;
$freeTxt = $data['freeTxt'] ?? '—';
$latestBackup = $data['latestBackup'] ?? null;
$CFG = $data['CFG'] ?? [];
$agoBackup = $data['agoBackup'] ?? 'никогда';
$agoScan = $data['agoScan'] ?? 'никогда';
?>
<div class="av-page-title"><h1>Статус системы</h1><span class="hint"><?= AV\e($CFG['site_root'] ?? '') ?></span></div>

<div class="av-cards">
<div class="av-card"><div class="k">Лицензия</div><div class="v <?= ($license['valid'] ?? false) ? 'ok' : 'err' ?>"><?= ($license['valid'] ?? false) ? 'до ' . AV\e(date('d.m.Y', (int)($license['exp'] ?? 0))) : 'нет / истекла' ?></div><div class="k" style="margin-top:6px;text-transform:none;letter-spacing:0"><?= ($license['valid'] ?? false) ? 'осталось ' . (int)($license['daysLeft'] ?? 0) . ' дн' : 'бэкапы и проверки остановлены' ?></div></div>
<div class="av-card"><div class="k">Сканирование</div><div class="v <?= $antivirusEnabled ? 'ok' : 'err' ?>"><?= $antivirusEnabled ? 'Включено' : 'Отключено' ?></div><div class="k" style="margin-top:6px;text-transform:none;letter-spacing:0">антивирус: antivirus_enabled</div></div>
<div class="av-card"><div class="k">Авто-восстановление</div><div class="v <?= $autoRestore ? 'ok' : '' ?>"><?= $autoRestore ? 'Вкл' : 'Выкл' ?></div><div class="k" style="margin-top:6px;text-transform:none;letter-spacing:0">auto_restore</div></div>
</div>

<div class="av-cards">
<div class="av-card"><div class="k">База данных</div><div class="v <?= $dbState['ok'] ? 'ok' : 'err' ?>"><?= $dbState['ok'] ? 'Доступна' : 'Ошибка' ?></div><div class="k" style="margin-top:6px;text-transform:none;letter-spacing:0"><?= AV\e($dbState['ok'] ? $dbState['msg'] : $dbState['msg']) ?></div></div>
</div>

<div class="av-cards">
<div class="av-card"><div class="k">Cron: ежедневный бэкап</div><div class="v <?= $cronBackup ? 'ok' : 'err' ?>"><?= $cronBackup ? 'Активен' : 'Нет данных' ?></div><div class="k" style="margin-top:6px;text-transform:none;letter-spacing:0">последний запуск: <?= AV\e($agoBackup) ?></div></div>
<div class="av-card"><div class="k">Cron: скан изменённых</div><div class="v <?= $cronScan ? 'ok' : '' ?>"><?= $cronScan ? 'Активен' : 'Не настроен' ?></div><div class="k" style="margin-top:6px;text-transform:none;letter-spacing:0">последний запуск: <?= AV\e($agoScan) ?></div></div>
<div class="av-card"><div class="k">Baseline целостности</div><div class="v <?= $hasBase ? 'ok' : 'err' ?>"><?= $hasBase ? 'Создан' : 'Нет' ?></div><div class="k" style="margin-top:6px;text-transform:none;letter-spacing:0"><?= $hasBase ? 'контроль изменений активен' : 'создайте через вкладку Baseline' ?></div></div>
</div>

<div class="av-panel"><h2>Бэкапы</h2>
<table>
<tr><th>Параметр</th><th>Значение</th></tr>
<tr><td>Копий сейчас</td><td><?= $bCount ?> (макс. <?= ($CFG['keep_daily'] ?? 7) + ($CFG['keep_weekly'] ?? 4) + ($CFG['keep_monthly'] ?? 3) ?> по ротации)</td></tr>
<tr><td>Занимают на диске</td><td><?= round($bSize / 1024 / 1024) ?> МБ</td></tr>
<tr><td>Последний</td><td><?= $latestBackup ? "<span class='b ok'>есть</span> " . AV\e($agoBackup) . " — <code>" . AV\e(basename(dirname(dirname($latestBackup)))) . "</code>" : "<span class='b err'>нет</span>" ?></td></tr>
<tr><td>Папка</td><td><code><?= AV\e($CFG['backup_dir'] ?? '') ?></code></td></tr>
<tr><td>Свободно на диске</td><td><?= AV\e($freeTxt) ?> (мин: <?= round(($CFG['min_free_space'] ?? 524288000) / 1024 / 1024) ?> МБ)</td></tr>
</table>

<h2>Карантин</h2>
<p><?= $qCount > 0 ? "<span class='b err'>{$qCount} файлов</span> — <code>" . AV\e($CFG['quarantine_dir'] ?? '') . "</code>" : "<span class='b ok'>пусто</span>" ?></p>

<h2>Сервер</h2>
<table>
<tr><th>Параметр</th><th>Значение</th></tr>
<tr><td>ОС / ядро</td><td><?= AV\e($srv['os'] ?? '—') ?></td></tr>
<tr><td>Аптайм</td><td><?= AV\e($srv['uptime'] ?? '—') ?></td></tr>
<?php if (!empty($srv['cpu'])): ?><tr><td>CPU</td><td><?= AV\e($srv['cpu']) ?><?= !empty($srv['load']) ? " · нагрузка " . AV\e($srv['load']) : '' ?></td></tr><?php endif; ?>
<?php if (!empty($srv['ram'])): $ram = $srv['ram']; $cls = $ram['pct'] > 90 ? 'err' : ($ram['pct'] > 75 ? 'warn' : 'ok'); ?>
<tr><td>Оперативная память</td><td><span class="b <?= $cls ?>"><?= $ram['pct'] ?>%</span> <?= $ram['used'] ?> МБ из <?= $ram['total'] ?> МБ</td></tr>
<?php endif; ?>
<?php if (!empty($srv['disk'])): $d = $srv['disk']; $cls = $d['pct'] > 90 ? 'err' : ($d['pct'] > 75 ? 'warn' : 'ok'); ?>
<tr><td>Диск (корень сайта)</td><td><span class="b <?= $cls ?>"><?= $d['pct'] ?>%</span> свободно <?= $d['free'] ?> ГБ из <?= $d['total'] ?> ГБ</td></tr>
<?php endif; ?>
<tr><td>PHP (CLI/FPM)</td><td><?= AV\e($srv['php'] ?? PHP_VERSION) ?></td></tr>
</table>

<h2>Действия</h2><div class="av-actions">
<a class="av-btn primary" href="av.php?action=backup">Создать бэкап</a>
<a class="av-btn" href="av.php?action=scan">Скан изменённых</a>
<a class="av-btn" href="av.php?action=fullscan">Полный скан</a>
<a class="av-btn" href="av.php?action=baseline">Обновить baseline</a>
</div></div>
