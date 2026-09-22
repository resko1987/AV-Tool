<?php
/** @var string $msg */
/** @var string $msgType */
$msg = $msg ?? null;
$msgType = $msgType ?? 'info';
?>
<?php if ($msg): ?><div class="note <?= $msgType === 'ok' ? 'info' : $msgType ?>"><?= $msg ?></div><?php endif; ?>
