<?php
/** @var array $data */
$CFG = $data['CFG'] ?? [];
?>
<div class="av-page-title"><h1>Резервное копирование</h1><span class="hint">выполняется...</span></div>
<div class="av-panel"><pre><?php
$backup = new \AV\Model\Backup($CFG, $data['log'], $data['mail']);
$backup->run(true);
?></pre></div>
<div class="av-actions"><a class="av-btn" href="av.php?action=status">← К статусу</a> <a class="av-btn" href="av.php?action=backup">← К бэкапу</a></div>
