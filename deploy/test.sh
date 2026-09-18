#!/bin/bash
# Прогон тестов. Рабочий каталог /opt/mailadmin ставится с --no-dev, поэтому PHPUnit там нет
# и быть не должно. Держим отдельную копию в /opt/mailadmin-test: она ничего не обслуживает,
# только подтягивает ту же ветку и гоняет тесты.
#
#   sudo bash deploy/test.sh            — обновить копию и прогнать всё
#   sudo bash deploy/test.sh --filter Charset  — прогнать часть
set -euo pipefail

DIR=/opt/mailadmin-test
SRC=/opt/mailadmin

if [ ! -d "$DIR/.git" ]; then
  echo "==> первый запуск: копирую репозиторий в $DIR"
  git clone --quiet "$SRC" "$DIR"
  # окружение берём у рабочего: тестам нужен только ключ приложения и настройки путей
  cp "$SRC/.env" "$DIR/.env"
  sed -i 's/^APP_ENV=.*/APP_ENV=testing/' "$DIR/.env"
fi

cd "$DIR"
# Тянем из общего репозитория, а не из рабочего каталога: иначе, чтобы проверить свежий
# коммит, его пришлось бы сначала выложить людям. Проверка должна идти до выкладки.
UPSTREAM=$(git -C "$SRC" -c safe.directory="$SRC" remote get-url origin 2>/dev/null || echo "$SRC")
git --git-dir="$DIR/.git" remote set-url origin "$UPSTREAM" >/dev/null 2>&1 || true
BR=${BRANCH:-$(git -C "$SRC" -c safe.directory="$SRC" rev-parse --abbrev-ref HEAD 2>/dev/null || echo master)}
git fetch --quiet origin "$BR"
git reset --hard --quiet FETCH_HEAD
echo "==> версия: $(git log --oneline -1)"

# С dev-зависимостями: именно здесь живёт PHPUnit.
composer install --optimize-autoloader --no-interaction --quiet

mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
php artisan config:clear --quiet || true

echo "==> тесты"
vendor/bin/phpunit "$@"
