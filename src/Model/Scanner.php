<?php
declare(strict_types=1);
namespace AV\Model;

class Scanner
{
    private array $cfg;
    private Logger $log;
    private Mailer $mail;
    private Backup $backup;
    private Quarantine $quarantine;
    private bool $autoRestore;
    private array $patterns;
    private array $signatures;
    private array $whitelist = [];
    private array $threatDetails = [];

    public function __construct(array $cfg, Logger $log, Mailer $mail, Backup $backup, Quarantine $quarantine, bool $autoRestore)
    {
        $this->cfg = $cfg;
        $this->log = $log;
        $this->mail = $mail;
        $this->backup = $backup;
        $this->quarantine = $quarantine;
        $this->autoRestore = $autoRestore;
        $this->patterns = $cfg['dangerous_patterns'];
        $this->signatures = Signatures::all();
        $this->whitelist = array_merge($cfg['scan_exclude_files'] ?? [], Whitelist::load($cfg), self::builtinWhitelist());
    }

    /**
     * Встроенный белый список. Сюда вынесены файлы, которые гарантированно
     * легитимны в поставке WordPress/SimplePie/phpseclib/pclzip/Zend и т.п.,
     * но содержат строки, на которые реагируют широкие сигнатуры.
     * Это НЕ замена ручному списку — пользовательский (scan_whitelist.json)
     * имеет тот же приоритет и может добавлять свои исключения.
     */
    private static function builtinWhitelist(): array
    {
        return [
            // --- Ядро WordPress (все версии) ---
            'wp-includes/class-wp-http-streams.php',
            'wp-includes/SimplePie/*',
            'wp-includes/Requests/*',
            'wp-includes/class-pclzip.php',
            'wp-includes/class-wp-simplepie-*.php',
            'wp-includes/comment-template.php',
            'wp-includes/user.php',
            'wp-includes/comment.php',
            'wp-admin/includes/ajax-actions.php',
            'wp-admin/includes/user.php',
            'wp-admin/user-edit.php',
            'wp-admin/network.php',
            'wp-admin/includes/class-pclzip.php',
            // --- PclZip (gzip-архивы, gzinflate встречается по назначению) ---
            'wp-content/plugins/*/lib/class-pclzip.php',
            'wp-content/plugins/*/libraries/wpaipclzip.lib.php',
            'wp-content/plugins/*/admin/lib/class-pclzip.php',
            'wp-content/plugins_backup/*/lib/class-pclzip.php',
            'wp-content/plugins_backup/*/libraries/wpaipclzip.lib.php',
            'wp-content/plugins_backup/*/admin/lib/class-pclzip.php',
            // --- phpseclib (SSH/SFTP/ASN.1) ---
            'wp-content/plugins/*/vendor/phpseclib/*',
            'wp-content/plugins_backup/*/vendor/phpseclib/*',
            // --- SimplePie (в любом месте) ---
            '*/SimplePie/src/*',
            '*/SimplePie/library/*',
            // --- Freemius (штатный лицензионный менеджер плагинов) ---
            '*/freemius/includes/class-freemius.php',
            // --- WooCommerce / Automattic ---
            '*/woocommerce/lib/packages/GraphQL/Utils/Utils.php',
            '*/woocommerce/includes/shortcodes/class-wc-shortcode-order-tracking.php',
            '*/woocommerce/src/Blocks/BlockTypes/*',
            '*/woocommerce-gutenberg-products-block/*',
            '*/woo-gutenberg-products-block/*',
            '*/automattic/jetpack-connection/src/sso/class-sso.php',
            // --- UpdraftPlus backup ---
            '*/updraftplus/admin.php',
            // --- WP File Manager: CodeMirror mumps example (HTML с ложным JS) ---
            '*/wp-file-manager/lib/codemirror/mode/mumps/*',
            // --- Разное: readme/license/security-инфо плагинов ---
            '*/wordfence/readme.txt',
            '*/wordfence/*.txt',
            // --- Плагины/шаблоны документации с iframe поддержки ---
            '*/wp-all-backup/includes/admin/wpallbackup-help.php',
            '*/wp-all-export/views/admin/export/success_page.php',
            // --- Продуктовые/админ-файлы из списка FP ---
            '*/product-import-export-for-woo/includes/class-wf-prodimpexp-plugin-uninstall-feedback.php',
            '*/product-import-export-for-woo/admin/modules/request_feature/request_feature.php',
            '*/imagify/inc/classes/class-imagify-admin-ajax-post.php',
            '*/wp-file-manager/file_folder_manager.php',
            // --- Hex-строки в парсерах дат/документов ---
            'wp-includes/html-api/class-wp-html-tag-processor.php',
            'wp-includes/Requests/src/Requests.php',
            'wp-includes/class-wp-http-encoding.php',
            'wp-includes/class-wp-simplepie-sanitize-kses.php',
        ];
    }

    private function isScannable(string $abs): bool
    {
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        return in_array($ext, $this->cfg['scannable_extensions'], true);
    }

    public function scanFiles(array $files): int
    {
        License::requireValid($this->cfg);

        $threats = 0;
        $root = $this->cfg['site_root'];
        foreach ($files as $abs) {
            $abs = trim($abs);
            if (!$abs || !is_file($abs)) continue;
            $rel = FileHelper::relPath($abs, $root);
            if (FileHelper::isExcluded($rel, $this->cfg['exclude_paths'])) continue;
            if (FileHelper::isProtected($abs, $this->cfg)) continue;
            if (Whitelist::match($rel, $this->whitelist)) continue;
            if (!$this->isScannable($abs)) continue;
            if (filesize($abs) > $this->cfg['max_scan_file_size']) continue;
            $content = @file_get_contents($abs);
            if ($content === false) continue;
            $hit = $this->match($content, $abs);
            if ($hit) {
                $threats++;
                $this->threatDetails[] = ['file' => $rel, 'reason' => $hit];
                $this->handleThreat($abs, $rel, $hit);
            }
        }

        $integrity = new Integrity($this->cfg, $this->log);
        if ($integrity->hasBaseline()) {
            $changes = $integrity->check();
            $labels = ['new' => 'НОВЫЙ', 'modified' => 'ИЗМЕНЁН', 'deleted' => 'УДАЛЁН'];
            foreach ($labels as $k => $label) {
                foreach ($changes[$k] as $rel) {
                    $this->log->warn("Целостность [$label]: $rel");
                }
            }
            $total = count($changes['new']) + count($changes['modified']) + count($changes['deleted']);
            if ($total > 0) {
                $this->mail->send("Изменения целостности", "Обнаружены изменения файлов: " . $total);
            }
        }
        return $threats;
    }

    public function getThreatDetails(): array
    {
        return $this->threatDetails;
    }

    private function match(string $content, string $abs): ?string
    {
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $isJs = in_array($ext, ['js', 'jsx', 'ts'], true);
        $jsSuspicious = false;
        // JS-бандлы (webpack, React, jQuery plugins и т.п.): легитимного
        // кода в начале и середине много, а вирусные инъекции дописываются
        // в КОНЕЦ файла. Поэтому для JS смотрим только хвост и отдельно
        // вычисляем эвристику «подозрительности» — используем её для решения,
        // применять ли к JS PHP-specific сигнатуры (eval(base64_decode), mail/…).
        if ($isJs) {
            $tail = (int) ($this->cfg['js_tail_size'] ?? 32768);
            $content = substr($content, -$tail);
            $jsSuspicious = $this->isSuspiciousJs($content);
        }
        foreach ($this->patterns as $name => $spec) {
            // exec( в JS — легитимный метод RegExp; obfuscated_var/hex_string —
            // нормальная обфускация минификаторов, gzinflate/eval в JS — бесполезны.
            if ($isJs) {
                // Для JS опасные PHP-паттерны имеет смысл проверять только
                // если хвост файла эвристически подозрителен.
                if (!$jsSuspicious) continue;
                if (in_array($name, ['exec(', 'obfuscated_var', 'hex_string', 'eval(', 'gzinflate(', 'str_rot13(', 'assert(', 'shell_exec(', 'system(', 'passthru(', 'proc_open(', 'popen(', 'pcntl_exec(', 'create_function(', 'preg_replace_e', 'remote_include'], true)) continue;
            }
            $regex = is_array($spec) ? $spec['re'] : $spec;
            $count = @preg_match_all($regex, $content, $m, PREG_OFFSET_CAPTURE);
            if (!$count) continue;
            foreach ($m[0] as $hit) {
                // Контекстные паттерны: угроза только если рядом с вызовом
                // (в окне после него) есть суперглобал или кодировщик.
                if (is_array($spec) && isset($spec['context'])) {
                    $window = substr($content, $hit[1], 512);
                    if (!@preg_match($spec['context'], $window)) continue;
                }
                return "pattern: $name";
            }
        }
        foreach ($this->signatures as $sig) {
            $sigName = (string)($sig['name'] ?? '');
            $sigExts = array_map('strtolower', (array)($sig['extensions'] ?? []));
            // Если у сигнатуры задан список расширений — применяем только к ним.
            if (!empty($sigExts) && !in_array($ext, $sigExts, true)) continue;

            // JS-файлы: применяем ВСЕГДА только собственно JS-сигнатуры
            // (названия начинающиеся на 'JS ' или 'Known JS '). Все остальные
            // (PHP-specific: eval(base64_decode), gzinflate, mailer и пр.)
            // проверяем только если хвост выглядит подозрительно.
            $isJsSig = stripos($sigName, 'JS ') === 0 || stripos($sigName, 'Known JS') === 0;
            if ($isJs) {
                if (!$isJsSig && !$jsSuspicious) continue;
            }
            if (@preg_match($sig['pattern'], $content)) {
                // Негативный паттерн (anti-FP): если сработал — сигнатуру в этом файле игнорируем.
                if (!empty($sig['not_pattern']) && @preg_match($sig['not_pattern'], $content)) continue;
                // Обязательный дополнительный паттерн: сигнатура сработает
                // только если в файле найдётся ЭТОТ кусок тоже (anti-FP через
                // требование контекстной строки).
                if (!empty($sig['require_pattern']) && !@preg_match($sig['require_pattern'], $content)) continue;
                return "signature: {$sigName} ({$sig['severity']})";
            }
        }
        return null;
    }

    /**
     * Эвристика «подозрительности» JS: вирус обычно дописан в КОНЕЦ файла
     * после длинной легитимной части и сильно минифицирован/обфусцирован
     * (длинные строки > 500 симв., высокая плотность \xNN, %XX, hex/base64).
     * Отсекает ложные срабатывания на webpack/jquery/React-бандлы, где
     * подобные конструкции встречаются законно во многих местах.
     */
    private function isSuspiciousJs(string $tail): bool
    {
        $len = strlen($tail);
        if ($len === 0) return false;

        // 1) Очень длинные безстроковые run'ы (минификация > 1000 симв без перевода строки)
        if (@preg_match('/[^\r\n]{1500,}/', $tail)) return true;

        // 2) Массивы с hex-строками ['\x63','\x6c',...] или [0x63,0x6c,...]
        if (@preg_match('/\[\s*(?:(?:["\']\\\\x[0-9a-fA-F]{2}["\']|0x[0-9a-fA-F]{1,2})\s*,\s*){15,}/', $tail)) return true;

        // 3) Строки с подряд идущими unicode-эскейпами \u0041\u0042...
        if (@preg_match('/(?:\\\\u[0-9a-fA-F]{4}){20,}/', $tail)) return true;

        // 4) base64/blob > 600 симв в кавычках
        if (@preg_match('/["\'][A-Za-z0-9+\/=]{600,}["\']/', $tail)) return true;

        // 5) Высокая плотность %XX (url-encoded malware > 40% в окне)
        $pct = substr_count($tail, '%');
        if ($pct > 150 && ($pct / max(1, $len)) > 0.02) return true;

        // 6) charCodeAt / fromCharCode цепочки (String.fromCharCode(…,…,…) с >20 аргументами)
        if (@preg_match('/String\.fromCharCode\s*\([^)]{80,}\)/', $tail)) return true;

        // 7) Очень длинная последовательность _0xHEX переменных (обфускаторы типа obfuscator.io)
        if (@preg_match('/(?:_0x[0-9a-f]{4,}[^_a-zA-Z0-9]){12,}/', $tail)) return true;

        return false;
    }

    private function handleThreat(string $abs, string $rel, string $reason): void
    {
        $this->log->crit("УГРОЗА ($reason): $rel");
        $restored = false;
        if ($this->autoRestore) {
            $restored = $this->quarantine->restoreFromBackup($abs, $this->cfg['site_root'], $this->backup);
        }
        if (!$restored) {
            $q = $this->quarantine->move($abs, $this->cfg['site_root']);
            if ($q) {
                $this->mail->send("КРИТИЧЕСКАЯ угроза (файл в карантине)",
                    "Обнаружен и перемещён в карантин: $rel\nПричина: $reason\nФайл отсутствует в последнем бэкапе.");
            }
        } else {
            $this->mail->send("Угроза автоматически устранена", "Файл восстановлен из бэкапа: $rel\nПричина: $reason");
        }
    }

    public function findChanged(int $minutes): array
    {
        $root = $this->cfg['site_root'];
        $threshold = time() - $minutes * 60;
        $found = [];
        FileHelper::iterate($root, $this->cfg['exclude_paths'], function ($abs) use (&$found, $threshold) {
            if (FileHelper::isProtected($abs, $this->cfg)) return;
            if (@filemtime($abs) >= $threshold) $found[] = $abs;
        });
        return $found;
    }

    public function findAll(): array
    {
        $root = $this->cfg['site_root'];
        $list = [];
        FileHelper::iterate($root, $this->cfg['exclude_paths'], function ($abs) use (&$list) {
            if (FileHelper::isProtected($abs, $this->cfg)) return;
            $list[] = $abs;
        });
        return $list;
    }
}
