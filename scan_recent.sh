#!/usr/bin/env bash
#
# scan_recent.sh — поиск файлов, изменённых за последние N минут, и
# передача списка в av.php для точечного сканирования.
#
# Добавить в cron (каждые 30 минут):
#   */30 * * * * /полный/путь/к/antivirus/scan_recent.sh >> /dev/null 2>&1
#
# Требования: bash 4+, утилита find, php в PATH.
#
set -u

# --- Пути -------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG_PHP="$SCRIPT_DIR/config.php"
AV_PHP="$SCRIPT_DIR/av.php"
LOG_DIR="$SCRIPT_DIR/logs"
LOG_FILE="$LOG_DIR/scan_recent.log"
TMP_LIST="/tmp/recent_files_$$.txt"

mkdir -p "$LOG_DIR"

# --- Чтение параметров из config.php (через php) ----------------------
# Интервал поиска (минуты). Если php недоступен — берём дефолт 60.
if command -v php >/dev/null 2>&1; then
    INTERVAL="$(php -r "require '$CONFIG_PHP'; \$c=av_config(); echo (int)\$c['scan_interval_minutes'];" 2>/dev/null)"
    SITE_ROOT="$(php -r "require '$CONFIG_PHP'; \$c=av_config(); echo rtrim(\$c['site_root'], '/');" 2>/dev/null)"
    mapfile -t EXCLUDES < <(php -r "require '$CONFIG_PHP'; \$c=av_config(); echo implode(\"\n\", \$c['exclude_paths']);" 2>/dev/null)
fi
INTERVAL="${INTERVAL:-60}"
SITE_ROOT="${SITE_ROOT:-$(cd "$SCRIPT_DIR/../.." && pwd)}"

# --- Формирование аргументов исключения для find ----------------------
FIND_PRUNE=""
for ex in "${EXCLUDES[@]:-}"; do
    [ -z "$ex" ] && continue
    FIND_PRUNE="$FIND_PRUNE -path '$SITE_ROOT/$ex' -prune -o"
done

# --- Поиск изменённых файлов ------------------------------------------
TIMESTAMP="$(date '+%Y-%m-%d %H:%M:%S')"
# -mmin -N  => изменённые менее N минут назад
# просим только файлы (-type f), исключая заданные папки и саму папку антивируса
CMD="find '$SITE_ROOT' -path '$SCRIPT_DIR' -prune -o $FIND_PRUNE -type f -mmin -$INTERVAL -print"
eval "$CMD" > "$TMP_LIST" 2>/dev/null

mapfile -t RECENT < "$TMP_LIST" 2>/dev/null
COUNT="${#RECENT[@]}"
# mapfile может не создать массив, если файл пуст — задаём дефолт
[ -z "$COUNT" ] && COUNT=0

echo "[$TIMESTAMP] Найдено изменённых файлов (за $INTERVAL мин): $COUNT" >> "$LOG_FILE"

if [ "$COUNT" -gt 0 ]; then
    # Передаём список в av.php (без вывода в терминал, логируем результат)
    if php "$AV_PHP" --scan-list="$TMP_LIST" >> "$LOG_FILE" 2>&1; then
        echo "[$TIMESTAMP] Сканирование завершено успешно." >> "$LOG_FILE"
    else
        RC=$?
        echo "[$TIMESTAMP] ОШИБКА: av.php завершился с кодом $RC." >> "$LOG_FILE"
        # Опционально: уведомление администратора (раскомментируйте и настройте)
        # mail -s "AV scan error on $(hostname)" admin@example.com <<< "scan_recent.sh failed with code $RC" 2>/dev/null || true
    fi
else
    echo "[$TIMESTAMP] Изменённых файлов нет, сканирование пропущено." >> "$LOG_FILE"
fi

# --- Очистка временного файла -----------------------------------------
rm -f "$TMP_LIST"

exit 0
