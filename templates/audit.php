<?php
/** @var array $data */
$CFG = $data['CFG'] ?? [];
$audit = $data['audit'] ?? null;
$msg = $data['msg'] ?? null;
$msgType = $data['msgType'] ?? 'info';
$csrf = $data['csrf'] ?? '';

if (!function_exists('av_audit_level')) {
    function av_audit_level(string $level): string
    {
        if ($level === 'err') return 'err';
        if ($level === 'warn') return 'warn';
        if ($level === 'ok') return 'ok';
        return 'info';
    }
}
?>
<div class="av-page-title"><h1>Аудит сервера</h1><span class="hint">конфигурация nginx · доступность чувствительных файлов · права и владельцы</span></div>

<?php if ($msg): ?><div class="note <?= AV\e($msgType) ?>"><?= $msg ?></div><?php endif; ?>

<?php if (!$audit): ?>
<div class="av-panel">
<h2>Что проверяется</h2>
<ul>
<li><b>nginx</b> — разбираются server-блоки этого сайта: SSL-протоколы, правила для скрытых файлов (.env/.git), try_files в PHP-location, security-заголовки, autoindex</li>
<li><b>Чувствительные файлы</b> — HTTP-пробы: отдаёт ли веб-сервер .env, baseline.json, .git/config и их забытые копии</li>
<li><b>Права и владельцы</b> — каталоги 755, PHP-файлы 644 (без записи группе/миру), .env и ключи — 640; владелец root помечается как проблема</li>
</ul>
<div class="note info">Публичные папки медиа (картинки, pdf) с правами 777 считаются допустимыми и не мешают итоговой оценке — аудит интересуют PHP-файлы основной системы.</div>
<div class="av-actions">
<a class="av-btn primary" href="av.php?action=audit&run=1">Запустить аудит</a>
<a class="av-btn" href="av.php?action=status">Отмена</a>
</div>
</div>
<?php return; ?><?php endif; ?>

<?php
$nginx = $audit['nginx'];
$env = $audit['env'];
$fs = $audit['fs'];
$err = $audit['err'];
$warn = $audit['warn'];
$envErr = 0; $envOk = 0; $envInfo = 0;
foreach ($env['probes'] as $p) {
    if ($p['status'] === 'err') $envErr++;
    elseif ($p['status'] === 'ok') $envOk++;
    else $envInfo++;
}
$myServers = array_values(array_filter($nginx['servers'], fn($s) => !empty($s['match'])));
$nginxErr = 0; $nginxWarn = 0; $nginxOk = 0;
foreach ($myServers as $s) {
    foreach ($s['checks'] as $c) {
        if ($c['level'] === 'err') $nginxErr++;
        elseif ($c['level'] === 'warn') $nginxWarn++;
        elseif ($c['level'] === 'ok') $nginxOk++;
    }
}
?>

<div class="av-cards">
<a class="av-card av-card-link" href="#audit-nginx" title="К разделу «Конфигурация nginx»"><div class="k">Итог</div><div class="v <?= $err ? 'err' : ($warn ? 'warn' : 'ok') ?>"><?= $err ? "$err проблем" : ($warn ? "$warn предупреждений" : 'Проблем не найдено') ?></div><div class="k" style="margin-top:6px;text-transform:none;letter-spacing:0"><?= $warn ? "($warn предупреждений)" : 'все проверки пройдены' ?></div></a>
<a class="av-card av-card-link" href="#audit-env" title="К разделу «Доступность чувствительных файлов»"><div class="k">Чувствительные файлы</div><div class="v <?= $envErr ? 'err' : ($envOk ? 'ok' : 'info') ?>"><?= $envErr ? "$envErr доступно из веба" : 'Закрыты' ?></div><div class="k" style="margin-top:6px;text-transform:none;letter-spacing:0"><?= "$envErr красных, $envOk зелёных, $envInfo неопределённых" ?></div></a>
<a class="av-card av-card-link" href="#audit-fs-php" title="К разделу «PHP-файлы с неверными правами»"><div class="k">PHP-файлы с плохими правами</div><div class="v <?= $fs['totals']['php_bad'] ? 'err' : 'ok' ?>"><?= (int)$fs['totals']['php_bad'] ?></div><div class="k" style="margin-top:6px;text-transform:none;letter-spacing:0">из <?= (int)$fs['stats']['php'] ?> PHP-файлов</div></a>
<a class="av-card av-card-link" href="#audit-fs-owners" title="К разделу «Владелец — root / чужие владельцы»"><div class="k">Владелец root</div><div class="v <?= $fs['totals']['owners_root'] ? 'err' : 'ok' ?>"><?= $fs['totals']['owners_root'] ? (int)$fs['totals']['owners_root'] : 'Нет' ?></div><div class="k" style="margin-top:6px;text-transform:none;letter-spacing:0"><?= $fs['totals']['owners_foreign'] ? (int)$fs['totals']['owners_foreign'] . ' чужих владельцев' : 'все файлы принадлежат ' . AV\e($fs['expected_owner']['name']) ?></div></a>
</div>

<?php if ($envErr): ?>
<div class="note err"><b>Внимание:</b> найдены чувствительные файлы, доступные для скачивания из веба (<?= $envErr ?>). Закройте их в nginx: <code>location ~ /\. { deny all; }</code> и <code>location ~* ^/antivirus/data/ { deny all; }</code></div>
<?php endif; ?>

<!-- ================= NGINX ================= -->
<div class="av-panel av-audit-panel" id="audit-nginx">
<h2>Конфигурация nginx</h2>

<?php if (!empty($nginx['hint'])): ?>
<div class="note warn"><?= AV\e($nginx['hint']) ?></div>
<details class="av-collapse">
<summary>Как получить настройки nginx с сервера</summary>
<div class="av-collapse-body">
<ol>
<li><b>Найдите конфиг вашего vhost.</b> По SSH выполните:
<pre>nginx -T 2&gt;/dev/null | grep -n "server_name\|root /"
ls /etc/nginx/sites-enabled/ /etc/nginx/conf.d/</pre></li>
<li><b>Скопируйте конфиг</b> (обычно <code>/etc/nginx/sites-enabled/имя_сайта.conf</code> или блок <code>server { … }</code> из вывода <code>nginx -T</code>) в папку антивируса:
<pre>cp /etc/nginx/sites-enabled/имя_сайта.conf &lt;путь_к_антивирусу&gt;/antivirus/data/nginx.conf</pre>
В shared-хостинге без SSH конфиг можно скопировать из панели хостера или через SFTP.</li>
<li><b>Защитите копию</b> от скачивания из веба (это конфигурация сервера, её нельзя отдавать наружу):
<pre>chmod 640 &lt;путь_к_антивирусу&gt;/antivirus/data/nginx.conf</pre>
Антивирус при каждом запуске сам ставит на файл 640, но лучше убедиться, что nginx сайта не отдаёт всю папку — ниже вкладка «Доступность чувствительных файлов» проверяет это HTTP-пробой на <code>/antivirus/data/nginx.conf</code>.</li>
<li><b>Перезапустите аудит</b> — файл будет разобран автоматически. Убедитесь, что в блоке есть директива <code>root</code> или <code>server_name</code> — по ним аудит сопоставляет блок с сайтом.</li>
</ol>
<div class="note info">Конфиг читается один раз при запуске аудита. После изменений в nginx обновите копию заново.</div>
</div>
</details>
<?php endif; ?>

<?php if ($nginx['sources']): ?>
<details class="av-collapse" open>
<summary>Исходные конфиги (<?= count($nginx['sources']) ?>)</summary>
<div class="av-collapse-body">
<ul>
<?php foreach ($nginx['sources'] as $src): ?>
<li><code><?= AV\e($src) ?></code></li>
<?php endforeach; ?>
</ul>
</div>
</details>
<?php endif; ?>

<?php if (!$myServers && $nginx['servers']): ?>
<details class="av-collapse">
<summary>Server-блоки сайта не распознаны (<?= count($nginx['servers']) ?> блоков найдено) — показать все</summary>
<div class="av-collapse-body note info">Ни один server-блок не совпал с корнем сайта или HTTP_HOST. Ниже — все найденные блоки; проверьте их вручную.</div>
<?php foreach ($nginx['servers'] as $i => $s): ?>
<details class="av-collapse">
<summary>server #<?= $i + 1 ?> — <?= AV\e($s['path']) ?></summary>
<div class="av-collapse-body">
<div class="av-copy-wrap">
    <button type="button" class="av-btn sm js-copy" title="Скопировать конфиг в буфер обмена">Скопировать</button>
    <pre class="js-copy-target"><?= AV\e($s['raw']) ?></pre>
</div>
</div>
</details>
<?php endforeach; ?>
</details>
<?php elseif (!$nginx['servers'] && $nginx['available']): ?>
<div class="note info">server-блоки в доступных конфигах не найдены.</div>
<?php endif; ?>

<?php foreach ($myServers as $s): ?>
<details class="av-collapse" open>
<summary>server-блок — <?= AV\e($s['path']) ?></summary>
<div class="av-collapse-body">
<table class="av-audit-table">
<tr><th>Проверка</th><th>Значение / рекомендация</th><th>Статус</th></tr>
<?php foreach ($s['checks'] as $c): ?>
<tr>
<td><?= AV\e($c['title']) ?></td>
<td><?= AV\e($c['detail']) ?></td>
<td><span class="b <?= av_audit_level($c['level']) ?>"><?= $c['level'] === 'ok' ? 'ок' : ($c['level'] === 'err' ? 'проблема' : ($c['level'] === 'warn' ? 'внимание' : 'инфо')) ?></span></td>
</tr>
<?php endforeach; ?>
</table>
<details class="av-collapse">
<summary>Показать конфиг server-блока</summary>
<div class="av-collapse-body">
<div class="av-copy-wrap">
    <button type="button" class="av-btn sm js-copy" title="Скопировать конфиг в буфер обмена">Скопировать</button>
    <pre class="js-copy-target"><?= AV\e($s['raw']) ?></pre>
</div>
</div>
</details>
</div>
</details>
<?php endforeach; ?>

<?php if (!$myServers): ?>
<div class="note info">Не найдены server-блоки вашего сайта? nginx-конфиги часто хранятся в недоступной для PHP зоне (например, <code>/etc/nginx</code> с правами root:nginx). Если конфиг недоступен для чтения, nginx-часть аудита пропускается — остальные разделы продолжат работу. Можно также скопировать vhost вручную в <code>antivirus/data/nginx.conf</code>.</div>
<?php endif; ?>
</div>

<!-- ================= ЧУВСТВИТЕЛЬНЫЕ ФАЙЛЫ ================= -->
<div class="av-panel av-audit-panel" id="audit-env">
<h2>Доступность чувствительных файлов из веба</h2>

<?php if (!empty($env['note'])): ?>
<div class="note warn"><?= AV\e($env['note']) ?></div>
<?php else: ?>
<table class="av-audit-table">
<tr><th>URL</th><th>Что проверяем</th><th>Результат</th></tr>
<?php foreach ($env['probes'] as $p): ?>
<tr>
<td><code><?= AV\e($p['url']) ?></code></td>
<td><?= AV\e($p['label']) ?></td>
<td>
<span class="b <?= av_audit_level($p['status']) ?>"><?= $p['status'] === 'ok' ? 'закрыт' : ($p['status'] === 'err' ? 'ДОСТУПЕН' : ($p['status'] === 'warn' ? 'проверить' : 'неопределённо')) ?></span>
<div class="av-audit-note"><?= AV\e($p['note']) ?></div>
</td>
</tr>
<?php endforeach; ?>
</table>
<div class="note info">Проверяются только HTTP-коды ответа и факт существования содержимого — данные из файлов в отчёт не попадают.</div>
<?php endif; ?>
</div>

<!-- ================= ПРАВА И ВЛАДЕЛЬЦЫ ================= -->
<div class="av-panel av-audit-panel" id="audit-fs">
<h2>Права и владельцы файлов</h2>

<div class="av-cards">
<div class="av-card"><div class="k">Файлов проверено</div><div class="v"><?= (int)$fs['stats']['files'] ?></div><div class="k" style="margin-top:6px;text-transform:none;letter-spacing:0">PHP: <?= (int)$fs['stats']['php'] ?> · каталогов: <?= (int)$fs['stats']['dirs'] ?> · симлинков: <?= (int)$fs['stats']['links'] ?></div></div>
<div class="av-card"><div class="k">Каталоги с плохими правами</div><div class="v <?= $fs['totals']['dirs_bad'] ? 'err' : 'ok' ?>"><?= (int)$fs['totals']['dirs_bad'] ?></div><div class="k" style="margin-top:6px;text-transform:none;letter-spacing:0">ожидаются 755 для каталогов с PHP</div></div>
<div class="av-card"><div class="k">Чувствительные (.env, *.key)</div><div class="v <?= $fs['totals']['sensitive_bad'] ? 'err' : 'ok' ?>"><?= (int)$fs['totals']['sensitive_bad'] ?></div><div class="k" style="margin-top:6px;text-transform:none;letter-spacing:0">ожидаются 640</div></div>
<div class="av-card"><div class="k">Медиа с 777</div><div class="v <?= ($fs['totals']['media_world'] || $fs['totals']['dirs_world']) ? 'warn' : 'ok' ?>"><?= (int)$fs['totals']['media_world'] + (int)$fs['totals']['dirs_world'] ?></div><div class="k" style="margin-top:6px;text-transform:none;letter-spacing:0">включая кэши-каталоги — не критично</div></div>
</div>

<span id="audit-fs-php"></span>
<?php if ($fs['totals']['php_bad']): ?>
<details class="av-collapse" open>
<summary>PHP-файлы с неверными правами — <?= (int)$fs['totals']['php_bad'] ?></summary>
<div class="av-collapse-body">
<table class="av-audit-table">
<tr><th>Файл</th><th>Права</th><th>Проблема</th></tr>
<?php foreach ($fs['php_bad'] as $row): ?>
<tr>
<td><code><?= AV\e($row['path']) ?></code></td>
<td><code><?= AV\e($row['perm']) ?></code></td>
<td><span class="b <?= av_audit_level($row['level']) ?>"><?= $row['level'] === 'err' ? 'критично' : 'внимание' ?></span> <?= AV\e($row['msg']) ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php if ($fs['totals']['php_bad'] > count($fs['php_bad'])): ?>
<div class="note info">Показаны первые <?= count($fs['php_bad']) ?> из <?= (int)$fs['totals']['php_bad'] ?>.</div>
<?php endif; ?>
</div>
</details>
<?php endif; ?>

<?php if ($fs['totals']['dirs_bad']): ?>
<details class="av-collapse"<?= $fs['totals']['dirs_bad'] <= 10 ? ' open' : '' ?>>
<summary>Каталоги с неверными правами — <?= (int)$fs['totals']['dirs_bad'] ?></summary>
<div class="av-collapse-body">
<table class="av-audit-table">
<tr><th>Каталог</th><th>Права</th><th>Проблема</th></tr>
<?php foreach ($fs['dirs_bad'] as $row): ?>
<tr>
<td><code><?= AV\e($row['path']) ?></code></td>
<td><code><?= AV\e($row['perm']) ?></code></td>
<td>
<?php if ($row['level'] === 'err'): ?><span class="b err">критично</span><?php else: ?><span class="b warn">внимание</span><?php endif; ?>
<?= AV\e($row['msg']) ?>
</td>
</tr>
<?php endforeach; ?>
</table>
<?php if ($fs['totals']['dirs_bad'] > count($fs['dirs_bad'])): ?>
<div class="note info">Показаны первые <?= count($fs['dirs_bad']) ?> из <?= (int)$fs['totals']['dirs_bad'] ?>.</div>
<?php endif; ?>
</div>
</details>
<?php endif; ?>

<?php if ($fs['totals']['dirs_world']): ?>
<details class="av-collapse">
<summary>Каталоги-кэши/медиа с мировой записью (не критично) — <?= (int)$fs['totals']['dirs_world'] ?></summary>
<div class="av-collapse-body">
<table class="av-audit-table">
<tr><th>Каталог</th><th>Права</th></tr>
<?php foreach ($fs['dirs_world'] as $row): ?>
<tr><td><code><?= AV\e($row['path']) ?></code></td><td><code><?= AV\e($row['perm']) ?></code></td></tr>
<?php endforeach; ?>
</table>
<?php if ($fs['totals']['dirs_world'] > count($fs['dirs_world'])): ?>
<div class="note info">Показаны первые <?= count($fs['dirs_world']) ?> из <?= (int)$fs['totals']['dirs_world'] ?>.</div>
<?php endif; ?>
</div>
</details>
<?php endif; ?>

<?php if ($fs['totals']['sensitive_bad']): ?>
<details class="av-collapse" open>
<summary>Чувствительные файлы с избыточными правами — <?= (int)$fs['totals']['sensitive_bad'] ?></summary>
<div class="av-collapse-body">
<table class="av-audit-table">
<tr><th>Файл</th><th>Права</th><th>Проблема</th><th>Действие</th></tr>
<?php foreach ($fs['sensitive_bad'] as $row): ?>
<tr>
<td><code><?= AV\e($row['path']) ?></code></td>
<td><code><?= AV\e($row['perm']) ?></code></td>
<td><span class="b <?= av_audit_level($row['level']) ?>"><?= $row['level'] === 'err' ? 'критично' : 'внимание' ?></span> <?= AV\e($row['msg']) ?></td>
<td><a class="av-btn primary sm" href="av.php?action=audit&do=fix&path=<?= urlencode($row['path']) ?>&csrf=<?= AV\e($csrf) ?>" onclick="return confirm('Установить права 640 на <?= AV\e($row['path']) ?>?')">Дать 640</a></td>
</tr>
<?php endforeach; ?>
</table>
<?php if ($fs['totals']['sensitive_bad'] > count($fs['sensitive_bad'])): ?>
<div class="note info">Показаны первые <?= count($fs['sensitive_bad']) ?> из <?= (int)$fs['totals']['sensitive_bad'] ?>.</div>
<?php endif; ?>
</div>
</details>
<?php endif; ?>

<span id="audit-fs-owners"></span>
<?php if ($fs['totals']['owners_root']): ?>
<details class="av-collapse" open>
<summary>Владелец — root — <?= (int)$fs['totals']['owners_root'] ?></summary>
<div class="av-collapse-body">
<div class="note warn">Файлы/каталоги, принадлежащие root. Это не всегда дыра (например, деплой через sudo), но если PHP не может их перезаписать — авто-восстановление из бэкапа молча не сработает. Убедитесь, что деплой делает их владельцем пользователя php-fpm.</div>
<table class="av-audit-table">
<tr><th>Путь</th><th>Тип</th><th>Владелец</th></tr>
<?php foreach ($fs['owners_root'] as $row): ?>
<tr><td><code><?= AV\e($row['path']) ?></code></td><td><?= AV\e($row['type']) ?></td><td><span class="b err">root</span></td></tr>
<?php endforeach; ?>
</table>
<?php if ($fs['totals']['owners_root'] > count($fs['owners_root'])): ?>
<div class="note info">Показаны первые <?= count($fs['owners_root']) ?> из <?= (int)$fs['totals']['owners_root'] ?>.</div>
<?php endif; ?>
</div>
</details>
<?php endif; ?>

<?php if ($fs['totals']['owners_foreign']): ?>
<details class="av-collapse"<?= $fs['totals']['owners_foreign'] <= 10 ? ' open' : '' ?>>
<summary>Чужие владельцы — <?= (int)$fs['totals']['owners_foreign'] ?></summary>
<div class="av-collapse-body">
<div class="note warn">Владельцы, отличные от владельца корня сайта (<?= AV\e($fs['expected_owner']['name']) ?>). Часто это нормально (файлы, созданные cron-задачей другого пользователя), но файлы PHP, доступные на запись чужому процессу, — потенциальная точка компрометации. Просмотрите список.</div>
<table class="av-audit-table">
<tr><th>Путь</th><th>Тип</th><th>Владелец</th></tr>
<?php foreach ($fs['owners_foreign'] as $row): ?>
<tr><td><code><?= AV\e($row['path']) ?></code></td><td><?= AV\e($row['type']) ?></td><td><span class="b warn"><?= AV\e($row['name']) ?></span></td></tr>
<?php endforeach; ?>
</table>
<?php if ($fs['totals']['owners_foreign'] > count($fs['owners_foreign'])): ?>
<div class="note info">Показаны первые <?= count($fs['owners_foreign']) ?> из <?= (int)$fs['totals']['owners_foreign'] ?>.</div>
<?php endif; ?>
</div>
</details>
<?php endif; ?>

<?php if ($fs['totals']['media_world']): ?>
<details class="av-collapse">
<summary>Публичные медиа с мировой записью (не критично) — <?= (int)$fs['totals']['media_world'] ?></summary>
<div class="av-collapse-body">
<table class="av-audit-table">
<tr><th>Файл</th><th>Права</th></tr>
<?php foreach ($fs['media_world'] as $row): ?>
<tr><td><code><?= AV\e($row['path']) ?></code></td><td><code><?= AV\e($row['perm']) ?></code></td></tr>
<?php endforeach; ?>
</table>
<?php if ($fs['totals']['media_world'] > count($fs['media_world'])): ?>
<div class="note info">Показаны первые <?= count($fs['media_world']) ?> из <?= (int)$fs['totals']['media_world'] ?>.</div>
<?php endif; ?>
</div>
</details>
<?php endif; ?>

<?php if (!$fs['totals']['php_bad'] && !$fs['totals']['dirs_bad'] && !$fs['totals']['sensitive_bad'] && !$fs['totals']['owners_root'] && !$fs['totals']['owners_foreign']): ?>
<div class="note ok">Файловая система в порядке: права и владельцы соответствуют рекомендациям.</div>
<?php endif; ?>
</div>

<div class="av-actions">
<a class="av-btn primary" href="av.php?action=audit&run=1">Перезапустить аудит</a>
<a class="av-btn" href="av.php?action=status">← К статусу</a>
</div>

<script>
(function () {
    'use strict';
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-copy');
        if (!btn) return;
        var pre = btn.parentElement.querySelector('.js-copy-target');
        if (!pre) return;
        var done = function () {
            var old = btn.textContent;
            btn.textContent = 'Скопировано ✓';
            btn.classList.add('copied');
            setTimeout(function () { btn.textContent = old; btn.classList.remove('copied'); }, 1500);
        };
        var text = pre.textContent;
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done).catch(function () { fallback(); });
        } else {
            fallback();
        }
        function fallback() {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); done(); } catch (err) { alert('Не удалось скопировать — выделите текст вручную'); }
            document.body.removeChild(ta);
        }
    });
})();
</script>