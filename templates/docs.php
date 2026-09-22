<?php
/** @var array $data */
$root = $data['root'] ?? '';
$tool = $data['tool'] ?? '';
$php = $data['php'] ?? '/opt/php84/bin/php';
?>
<h1>Бэкап и антивирус — документация</h1>
<p>Корень сайта: <code><?= AV\e($root) ?></code><br>
Папка инструмента: <code><?= AV\e($tool) ?></code><br>
PHP CLI: <code><?= AV\e($php) ?></code></p>

<h2>1. Файлы инструмента</h2>
<table>
<tr><th>Файл / папка</th><th>Назначение</th></tr>
<tr><td><code>config.php</code></td><td>Вся конфигурация: пути, БД, ротация, паттерны, почта, логин/пароль.</td></tr>
<tr><td><code>src/</code></td><td>Код: Models (Scanner, Backup, Quarantine, Integrity...), Controllers, View.</td></tr>
<tr><td><code>templates/</code></td><td>Шаблоны (HTML без PHP-логики).</td></tr>
<tr><td><code>assets/</code></td><td>CSS стили.</td></tr>
<tr><td><code>public/av.php</code></td><td>Точка входа (фронт-контроллер).</td></tr>
<tr><td><code>signatures.php</code></td><td>Сигнатуры вредоносного кода.</td></tr>
<tr><td><code>logs/</code></td><td>Логи: <code>av.log</code>, <code>scan_recent.log</code>.</td></tr>
<tr><td><code>data/</code></td><td><code>baseline.json</code>, whitelist, настройки.</td></tr>
<tr><td><code>quarantine/</code></td><td>Заражённые файлы.</td></tr>
<tr><td><code>backup/</code></td><td>Бэкапы: <code>&lt;дата&gt;/files/*.zip</code> и <code>&lt;дата&gt;/db/*.sql</code>.</td></tr>
</table>

<h2>2. Команды CLI</h2>
<pre><code># Бэкап
<?= AV\e($php) ?> <?= AV\e($tool) ?>/av.php --backup

# Скан изменённых
<?= AV\e($php) ?> <?= AV\e($tool) ?>/av.php --scan

# Полный скан
<?= AV\e($php) ?> <?= AV\e($tool) ?>/av.php --full-scan

# Baseline
<?= AV\e($php) ?> <?= AV\e($tool) ?>/av.php --baseline

# Восстановление
<?= AV\e($php) ?> <?= AV\e($tool) ?>/restore.php --list
<?= AV\e($php) ?> <?= AV\e($tool) ?>/restore.php --restore=STAMP --what=all</code></pre>

<h2>3. Cron</h2>
<pre><code># Бэкап — ежедневно в 03:30
30 3 * * * <?= AV\e($php) ?> <?= AV\e($tool) ?>/av.php --backup >> <?= AV\e($tool) ?>/logs/cron_backup.log 2>&amp;1

# Скан изменённых — каждые 30 минут
*/30 * * * * <?= AV\e($tool) ?>/scan_recent.sh >> /dev/null 2>&amp;1

# Полный скан — по воскресеньям в 04:10
10 4 * * 0 <?= AV\e($php) ?> <?= AV\e($tool) ?>/av.php --full-scan >> <?= AV\e($tool) ?>/logs/cron_fullscan.log 2>&amp;1</code></pre>

<h2>4. Безопасность</h2>
<ul>
<li>Вход: email + пароль (сессия). CSRF, honeypot, rate limit.</li>
<li>Служебные файлы закрыты от веба (nginx).</li>
<li>Чувствительные данные в <code>.env</code> и <code>data/</code> (права 0640).</li>
</ul>

<div class="av-actions">
<a class="av-btn primary" href="av.php?action=status">← К статусу</a>
</div>
