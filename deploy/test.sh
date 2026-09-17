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
git --git-dir="$DIR/.git" remote set-url origin "$SRC" >/dev/null 2>&1 || true
git fetch --quiet origin
git reset --hard --quiet origin/master
echo "==> версия: $(git log --oneline -1)"

# С dev-зависимостями: именно здесь живёт PHPUnit.
composer install --optimize-autoloader --no-interaction --quiet

mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
php artisan config:clear --quiet || true

echo "==> тесты"
vendor/bin/phpunit "$@"
