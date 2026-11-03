#!/bin/sh
# Entrypoint of the panel image (docs/adr/0054): one role per container.
#   web        php-fpm on port 9000 (behind the nginx image)
#   worker     a queue worker
#   scheduler  the scheduled commands
#   reverb     the WebSocket server of live server statistics, port 8080
#   migrate    php artisan proxmotion:upgrade --when-needed: with pending migrations, maintenance
#              mode, migrations and restart signals; otherwise only the requirements check
#   install    php artisan proxmotion:install with its options: run it once, interactively
# Anything else runs as a command, e.g. `php artisan about`.
set -eu

role="${1:-web}"
[ "$#" -eq 0 ] || shift

# Every container builds its own caches from its environment; the image holds none.
cache() {
    php artisan optimize --no-ansi > /dev/null
}

case "$role" in
    web)
        cache
        exec php-fpm
        ;;
    worker)
        cache
        # Recycled every hour; a stopped worker finishes its job first (stop_grace_period).
        exec php artisan queue:work --sleep=3 --max-time=3600 "$@"
        ;;
    scheduler)
        cache
        exec php artisan schedule:work
        ;;
    reverb)
        cache
        exec php artisan reverb:start --host=0.0.0.0 --port=8080
        ;;
    migrate)
        exec php artisan proxmotion:upgrade --no-interaction --when-needed
        ;;
    install)
        exec php artisan proxmotion:install "$@"
        ;;
    *)
        exec "$role" "$@"
        ;;
esac
