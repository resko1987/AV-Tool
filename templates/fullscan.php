<?php
/** @var array $data */
?>
<div class="av-page-title"><h1>Полное сканирование</h1><span class="hint">все файлы сайта</span></div>
<div class="av-panel">
<h2>Что будет сделано</h2>
<ul>
<li>Проверка <b>всех</b> файлов сайта на вредоносный код (паттерны + сигнатуры)</li>
<li>Контроль целостности по baseline (новые / изменённые / удалённые файлы)</li>
<li>Заражённые файлы: авто-восстановление из бэкапа или карантин</li>
</ul>
<div class="av-actions">
<a class="av-btn primary" href="av.php?action=fullscan&run=1">Сделать полное сканирование</a>
<a class="av-btn" href="av.php?action=status">Отмена</a>
</div>
<div class="note info">Сканирование может занять несколько минут — не закрывайте страницу до завершения.</div>
</div>
