<?php
/** @var array $data */
$files = $data['files'] ?? [];
$threats = $data['threats'] ?? 0;
$count = $data['count'] ?? 0;
$action = $data['action'] ?? 'scan';
$details = $data['details'] ?? [];
?>
<div class="av-page-title"><h1><?= $action === 'fullscan' ? 'Полное сканирование' : 'Сканирование изменённых' ?></h1><span class="hint">выполняется...</span></div>
<?php if ($details): ?>
<div class="av-panel"><pre>
Обнаружены угрозы (<?= count($details) ?>):
<?php foreach ($details as $t): ?>
[<?= htmlspecialchars($t['reason']) ?>] <?= htmlspecialchars($t['file']) ?>
<?php endforeach; ?>
</pre></div>
<?php else: ?>
<div class="av-panel"><pre>
Файлов: <?= $count ?>, угроз: <?= $threats ?>
</pre></div>
<?php endif; ?>
<div class="av-actions"><a class="av-btn" href="av.php?action=status">← К статусу</a></div>
