<?php
/** @var array $data */
$latest = $data['latest'] ?? null;
$CFG = $data['CFG'] ?? [];
?>
<div class="av-page-title"><h1>Резервное копирование</h1><span class="hint">файлы + БД, с ротацией</span></div>
<div class="av-panel">
<h2>Что будет сделано</h2>
<ul>
<li>Архив всех файлов сайта (zip)</li>
<li>Дамп базы данных (mysqldump)</li>
<li>Ротация: хранятся последние <?= (int)($CFG['keep_daily'] ?? 7) ?> дневных, <?= (int)($CFG['keep_weekly'] ?? 4) ?> воскресных, <?= (int)($CFG['keep_monthly'] ?? 3) ?> месячных копий</li>
</ul>
<h2>Последний бэкап</h2>
<p><?= $latest ? "<span class='b ok'>есть</span> <code>" . AV\e(basename(dirname($latest))) . "</code>" : "<span class='b err'>нет</span>" ?></p>
<div class="av-actions">
<a class="av-btn primary" href="av.php?action=backup&run=1">Создать бэкап</a>
<a class="av-btn" href="av.php?action=status">Отмена</a>
</div>
<div class="note info">Бэкап запускается только после нажатия кнопки и может занять некоторое время — не закрывайте страницу до завершения.</div>
</div>
