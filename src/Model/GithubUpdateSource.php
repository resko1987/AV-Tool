<?php
declare(strict_types=1);
namespace AV\Model;

use RuntimeException;

/**
 * GithubUpdateSource — обновления из GitHub.
 *
 * Почему zip, а не git clone/pull https://github.com/owner/repo.git:
 *  - git-протокол (smart HTTP) требует бинарник git + exec() — на shared-хостинге
 *    exec чаще всего запрещён, git не установлен;
 *  - codeload.github.com/.../zip/<sha|refs/tags/tag> отдаёт те же файлы одним
 *    архивом, скачивается чистым PHP (curl/allow_url_fopen), без токена для
 *    публичных репозиториев.
 *
 * Схема версий владельца: GitHub Release с прикреплённым архивом
 * release-<N[.N...]>.zip — ЦИФРЫ В ИМЕНИ ФАЙЛА и есть версия; тег релиза может
 * быть любым. Fallback: любой .zip-ассет → авто-архив тега → теги →
 * последний коммит ветки (AV_UPDATE_BRANCH, main), короткий sha.
 */
class GithubUpdateSource implements UpdateSource
{
    private string $repo;
    private string $branch;

    public function __construct(string $repo, string $branch = 'main')
    {
        $this->repo = trim($repo, '/');
        $this->branch = $branch !== '' ? $branch : 'main';
        if ($this->repo === '') {
            throw new RuntimeException('Не задан GitHub-репозиторий (AV_UPDATE_REPO).');
        }
    }

    public function releases(int $limit = 20): array
    {
        $out = $this->fromReleases($limit);
        if ($out !== []) return $out;

        $out = $this->fromTags($limit);
        if ($out !== []) return $out;

        return $this->fromBranchHead();
    }

    public function download(array $release): string
    {
        $url = (string)($release['url'] ?? '');
        if ($url === '' || !str_starts_with($url, 'https://')) {
            throw new RuntimeException('Некорректный URL архива обновления.');
        }
        $body = $this->http($url, true);
        $tmp = tempnam(sys_get_temp_dir(), 'av_upd_');
        if ($tmp === false || file_put_contents($tmp, $body) === false) {
            throw new RuntimeException('Не удалось сохранить архив обновления во временный файл.');
        }
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
            if ($finfo) finfo_close($finfo);
            if ($mime !== '' && $mime !== 'application/zip' && $mime !== 'application/x-zip') {
                @unlink($tmp);
                throw new RuntimeException("Скачанный файл не zip-архив (mime: $mime). Проверьте токен GitHub.");
            }
        }
        return $tmp;
    }

    // ------------------- источники версий -------------------

    /** @return array[] GitHub Releases */
    private function fromReleases(int $limit): array
    {
        $rows = $this->fetch("https://api.github.com/repos/{$this->repo}/releases?per_page=$limit");
        $out = [];
        foreach ($rows as $r) {
            if (!empty($r['draft'])) continue; // черновики клиентам не отдаём
            $tag = (string)($r['tag_name'] ?? '');

            // Схема владельца: к релизу прикреплён архив release-<версия>.zip.
            // Версия берётся из ИМЕНИ ФАЙЛА (цифры в имени = версия), тег релиза
            // при этом может быть любым. Поменяли цифры в имени архива → новая версия.
            $url = '';
            $versionFromAsset = '';
            foreach (($r['assets'] ?? []) as $a) {
                $aname = (string)($a['name'] ?? '');
                // Ищем ровно release-<числа[.числа...]>.zip (без учёта регистра)
                if (preg_match('~^release-(\d+(?:\.\d+)*)\.zip$~i', $aname, $m) && !empty($a['browser_download_url'])) {
                    $url = (string)$a['browser_download_url'];
                    $versionFromAsset = $m[1];
                    break;
                }
                // Запасной вариант: любой .zip-ассет релиза
                if ($url === '' && str_ends_with(strtolower($aname), '.zip') && !empty($a['browser_download_url'])) {
                    $url = (string)$a['browser_download_url'];
                }
            }
            if ($url !== '' && $versionFromAsset !== '') {
                $tag = $versionFromAsset; // версия из имени архива важнее любого тега
            }
            if ($tag === '') continue;
            if ($url === '') {
                // Ассета нет: fallback — автогенерируемый GitHub-архив тега, версия = тег
                $url = (string)($r['zipball_url'] ?? "https://codeload.github.com/{$this->repo}/zip/refs/tags/$tag");
            }

            $out[] = [
                'tag'   => $tag,
                'name'  => (string)($r['name'] ?? $tag),
                'notes' => (string)($r['body'] ?? ''),
                'url'   => $url,
            ];
        }
        // «Новизна» релиза = номер версии из имени архива, а не дата создания:
        // иначе перезаливка старого release-1.1 сделает его «новее», чем 1.2.
        // SHA-версии (fallback без цифр) сортируем как строки — вниз.
        usort($out, function (array $a, array $b): int {
            $av = Updater::normalize($a['tag']);
            $bv = Updater::normalize($b['tag']);
            $aNum = preg_match('~^\d+(?:\.\d+)*$~', $av) === 1;
            $bNum = preg_match('~^\d+(?:\.\d+)*$~', $bv) === 1;
            if ($aNum && $bNum) return version_compare($bv, $av);     // новее — первым
            if ($aNum !== $bNum) return $aNum ? -1 : 1;               // цифровая версия выше sha
            return strcmp($bv, $av);
        });
        return $out;
    }

    /** @return array[] теги (если релизов нет) */
    private function fromTags(int $limit): array
    {
        $rows = $this->fetch("https://api.github.com/repos/{$this->repo}/tags?per_page=$limit");
        $out = [];
        foreach ($rows as $t) {
            $tag = (string)($t['name'] ?? '');
            if ($tag === '') continue;
            $out[] = [
                'tag'   => $tag,
                'name'  => $tag,
                'notes' => '',
                'url'   => "https://codeload.github.com/{$this->repo}/zip/refs/tags/$tag",
            ];
        }
        return $out;
    }

    /** @return array[] последний коммит ветки (если нет ни релизов, ни тегов) */
    private function fromBranchHead(): array
    {
        $branch = $this->branch;
        try {
            $row = $this->fetchOne("https://api.github.com/repos/{$this->repo}/branches/" . rawurlencode($branch));
        } catch (RuntimeException $e) {
            // Ветка не найдена — узнаём дефолтную ветку репозитория
            $repo = $this->fetchOne("https://api.github.com/repos/{$this->repo}");
            $branch = (string)($repo['default_branch'] ?? 'main');
            $row = $this->fetchOne("https://api.github.com/repos/{$this->repo}/branches/" . rawurlencode($branch));
        }
        $sha = (string)($row['commit']['sha'] ?? '');
        if ($sha === '') {
            throw new RuntimeException('GitHub не вернул sha последнего коммита.');
        }
        $msg = trim((string)($row['commit']['commit']['message'] ?? ''));
        if (($nl = strpos($msg, "\n")) !== false) $msg = substr($msg, 0, $nl);
        return [[
            'tag'   => substr($sha, 0, 8),
            'name'  => "Коммит $branch@" . substr($sha, 0, 8) . ($msg !== '' ? ": $msg" : ''),
            'notes' => '',
            'url'   => "https://codeload.github.com/{$this->repo}/zip/$sha",
            'sha'   => $sha,
        ]];
    }

    // ------------------- HTTP -------------------

    /** @return array[] */
    private function fetch(string $url): array
    {
        $data = json_decode($this->http($url, false), true);
        if (!is_array($data)) {
            throw new RuntimeException('GitHub вернул некорректный ответ.');
        }
        if (isset($data['message'])) {
            throw new RuntimeException('GitHub API: ' . (string)$data['message']);
        }
        return $data;
    }

    /** @return array<string,mixed> один объект */
    private function fetchOne(string $url): array
    {
        $data = json_decode($this->http($url, false), true);
        if (!is_array($data)) {
            throw new RuntimeException('GitHub вернул некорректный ответ.');
        }
        if (isset($data['message'])) {
            throw new RuntimeException('GitHub API: ' . (string)$data['message']);
        }
        return $data;
    }

    private function http(string $url, bool $binary): string
    {
        $headers = [
            'User-Agent: AV-Tool-Updater',
            'Accept: application/vnd.github+json',
        ];
        $status = 0;
        $body = false;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => $binary ? 120 : 30,
                CURLOPT_HTTPHEADER     => $headers,
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body === false) {
                throw new RuntimeException("HTTP-запрос к GitHub не удался: $err");
            }
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => implode("\r\n", $headers),
                    'timeout' => $binary ? 120 : 30,
                    'ignore_errors' => true,
                    'follow_location' => 1,
                ],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body !== false) {
                foreach (($http_response_header ?? []) as $h) {
                    if (preg_match('~^HTTP/\S+\s+(\d{3})~', (string)$h, $m)) {
                        $status = (int)$m[1];
                    }
                }
            }
        }

        if ($body === false) {
            throw new RuntimeException('Не удалось выполнить HTTP-запрос к GitHub (нет curl и allow_url_fopen выключен?).');
        }
        if ($status >= 400) {
            $hint = ($status === 401 || $status === 403)
                ? ' Возможно, исчерпан анонимный лимит GitHub API (60 запросов/час с одного IP).'
                : '';
            throw new RuntimeException("GitHub ответил HTTP $status.$hint");
        }
        return (string)$body;
    }
}
