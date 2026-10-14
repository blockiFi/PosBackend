#!/bin/bash
set -euo pipefail

cd "$(dirname "$0")/.."

export DB_CONNECTION=sqlite
export DB_DATABASE="$PWD/storage/e2e.sqlite"
export CACHE_STORE=array
export SESSION_DRIVER=file
export QUEUE_CONNECTION=sync

rm -f "$DB_DATABASE"
touch "$DB_DATABASE"

php artisan migrate --force --no-interaction
php artisan db:seed --class=E2ePlaywrightSeeder --force --no-interaction
exec php artisan serve --host=127.0.0.1 --port=8010
