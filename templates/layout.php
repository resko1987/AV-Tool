<?php
/** @var string $title */
/** @var string $active */
/** @var string $content */
$pageCss = $pageCss ?? [];
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<title><?= AV\e($title) ?> — AV Tool</title>
<link rel="stylesheet" href="assets/av_ui.css?v=7">
<?php foreach ($pageCss as $css): ?>
<link rel="stylesheet" href="assets/<?= AV\e($css) ?>?v=1">
<?php endforeach; ?>
</head>
<body>

<div class="av-top"><div class="av-top-row">
<div class="av-logo"><span class="dot"></span>AV Tool</div>
<div class="av-user">
<span class="av-user-host"><?= AV\e($_SERVER['HTTP_HOST'] ?? '') ?></span>
<a class="av-exit" href="av.php?action=logout" title="Завершить сессию">Выход</a>
</div>
</div><nav class="av-nav">
<a href="av.php?action=status"<?= $active === 'status' ? " class='on'" : '' ?>>Статус</a>
<a href="av.php?action=backup"<?= $active === 'backup' ? " class='on'" : '' ?>>Бэкап</a>
<a href="av.php?action=scan"<?= $active === 'scan' ? " class='on'" : '' ?>>Скан</a>
<a href="av.php?action=fullscan"<?= $active === 'fullscan' ? " class='on'" : '' ?>>Полный скан</a>
<a href="av.php?action=baseline"<?= $active === 'baseline' ? " class='on'" : '' ?>>Baseline</a>
<a href="av.php?action=audit"<?= $active === 'audit' ? " class='on'" : '' ?>>Аудит сервера</a>
<a href="av.php?action=quarantine"<?= $active === 'quarantine' ? " class='on'" : '' ?>>Карантин</a>
<a href="av.php?action=whitelist"<?= $active === 'whitelist' ? " class='on'" : '' ?>>Исключения</a>
<a href="av.php?action=restore"<?= $active === 'restore' ? " class='on'" : '' ?>>Восстановление</a>
<span class="av-nav-spacer"></span>
<a class="<?= $active === 'settings' ? 'on alt' : 'alt' ?>" href="av.php?action=settings">Настройки</a>
<a class="<?= $active === 'license' ? 'on alt' : 'alt' ?>" href="av.php?action=license">Лицензия</a>
<a class="<?= $active === 'update' ? 'on alt' : 'alt' ?>" href="av.php?action=update">Обновление</a>
<a class="<?= $active === 'docs' ? 'on alt' : 'alt' ?>" href="av.php?action=docs">Документация</a>
</nav></div>
<div class="av-wrap">

<?= $content ?>

<div class="av-foot">
<span>AV Tool · бэкап и антивирус сайта</span><span class="sep">·</span>
<a href="av.php?action=docs">документация</a>
</div></div>
</body>
</html>
