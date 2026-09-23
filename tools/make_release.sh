#!/usr/bin/env bash
# make_release.sh — собрать архив релиза AV Tool: release-<N[.N...]>.zip
#
# Использование:
#   bash tools/make_release.sh 1.1
# Результат: ./release-1.1.zip (рядом с проектом, в .gitignore).
# Дальше: GitHub → Releases → Draft new release → тег можно любой
# (версию определяет ИМЯ АРХИВА) → прикрепить архив → Publish.
#
# ВАЖНО: в имени файла меняются только цифры — release-1.1.zip, release-2.0.zip...
# Апдейтер клиентов считает версией именно эти цифры.
#
# В архив НЕ попадают: данные/логи/бэкапы, .env, секреты, .git,
# инфраструктура владельца (license-panel/, lending/), токены и этот скрипт.

set -euo pipefail

VER="${1:-}"
if [[ ! "$VER" =~ ^[0-9]+(\.[0-9]+)*$ ]]; then
    echo "Использование: bash tools/make_release.sh <версия, только цифры: 1.1, 2.0, 1.2.3>" >&2
    exit 1
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
NAME="release-${VER}"
BUILD="$(mktemp -d)/${NAME}"
ZIP="$(dirname "$ROOT")/${NAME}.zip"

mkdir -p "$BUILD"

# Белый список того, что входит в релиз
INCLUDE=(
    av.php index.php composer.json config.php signatures.php scan_recent.sh
    VERSION LICENSE README.md .env.example
    src templates assets public
)

for item in "${INCLUDE[@]}"; do
    if [ -e "$ROOT/$item" ]; then
        mkdir -p "$BUILD/$(dirname "$item")"
        cp -r "$ROOT/$item" "$BUILD/$item"
    fi
done

# VERSION в архиве = номеру из имени файла (Updater при накате тоже запишет её)
printf '%s\n' "$VER" > "$BUILD/VERSION"

# Убрать возможный мусор
find "$BUILD" \( -name '.DS_Store' -o -name 'Thumbs.db' \) -delete 2>/dev/null || true

rm -f "$ZIP"
(
    cd "$(dirname "$BUILD")"
    if command -v zip >/dev/null 2>&1; then
        zip -qr "$ZIP" "$NAME"
    else
        python3 - "$NAME" "$ZIP" <<'PY'
import os, sys, zipfile
name, zpath = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(zpath, 'w', zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(name):
        for f in files:
            p = os.path.join(root, f)
            z.write(p, p)
PY
    fi
)

rm -rf "$BUILD"

echo "Готово: $ZIP ($(du -h "$ZIP" | cut -f1))"
echo
echo "Публикация:"
echo "  GitHub → Releases → Draft new release → прикрепите $(basename "$ZIP") → Publish release."
echo "  Версия для клиентов определяется ЦИФРАМИ В ИМЕНИ АРХИВА ($VER), тег релиза — любой."
