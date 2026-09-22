<?php
/** @var array $data */
$CFG = $data['CFG'] ?? [];
?>
<div class="av-page-title"><h1>Сканирование изменённых</h1><span class="hint">файлы за последние <?= (int)($CFG['scan_interval_minutes'] ?? 60) ?> мин</span></div>
<div class="av-panel">
<h2>Что будет сделано</h2>
<ul>
<li>Поиск файлов, изменённых за последние <?= (int)($CFG['scan_interval_minutes'] ?? 60) ?> минут</li>
<li>Проверка на вредоносный код (паттерны + сигнатуры)</li>
<li>Контроль целостности по baseline</li>
</ul>
<div class="av-actions">
<a class="av-btn primary" href="av.php?action=scan&run=1">Сделать сканирование</a>
<a class="av-btn" href="av.php?action=status">Отмена</a>
</div></div>
