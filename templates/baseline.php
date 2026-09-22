<?php
/** @var array $data */
$count = $data['count'] ?? 0;
?>
<div class="av-page-title"><h1>Baseline целостности</h1><span class="hint">эталонные SHA-256 хеши файлов</span></div>
<div class="av-panel"><pre>
Создан: <?= $count ?> файлов
</pre></div>
<div class="av-actions"><a class="av-btn" href="av.php?action=status">← К статусу</a></div>
