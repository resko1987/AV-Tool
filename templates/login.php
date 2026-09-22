<?php
/** @var string $error */
/** @var string $emailPrev */
/** @var string $csrf */
$error = $error ?? '';
$emailPrev = $emailPrev ?? '';
$csrf = $csrf ?? '';
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<title>Вход — AV Tool</title>
<link rel="stylesheet" href="assets/av_ui.css?v=4">
<link rel="stylesheet" href="assets/login.css?v=1">
</head>
<body>

<form class="login-box" method="post" action="av.php?action=login">
    <input type="hidden" name="csrf" value="<?= AV\e($csrf) ?>">
    <input type="hidden" name="form_time" value="<?= time() ?>">
    <div class="hp"><label for="website">Website</label><input type="text" id="website" name="website" tabindex="-1" autocomplete="off"></div>

    <div class="login-logo"><span class="dot"></span><span>AV Tool</span></div>
    <div class="login-sub">Бэкап и антивирус сайта</div>

    <?php if ($error): ?><div class="login-err"><?= AV\e($error) ?></div><?php endif; ?>

    <label class="flabel" for="email">Email</label>
    <input class="finput" type="email" id="email" name="email" value="<?= AV\e($emailPrev) ?>" required autofocus autocomplete="username">

    <label class="flabel" for="password">Пароль</label>
    <input class="finput" type="password" id="password" name="password" required autocomplete="current-password">

    <button class="login-btn" type="submit">Войти</button>
    <div class="login-host"><?= AV\e($_SERVER['HTTP_HOST'] ?? '') ?></div>
</form>

</body>
</html>
