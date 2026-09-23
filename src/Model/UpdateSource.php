<?php
declare(strict_types=1);
namespace AV\Model;

/**
 * UpdateSource — получение списка релизов/коммитов и zip-архивов обновлений.
 * Версия везде нормализуется через Updater::normalize().
 */
interface UpdateSource
{
    /**
     * Список доступных точек обновления, новые первыми.
     * @return array[] [ ['tag'=>..., 'name'=>..., 'notes'=>..., 'url'=>..., 'sha'=>?], ... ]
     */
    public function releases(int $limit = 20): array;

    /** Скачать zip-архив во временный файл, вернуть путь. */
    public function download(array $release): string;
}
