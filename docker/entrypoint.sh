#!/bin/sh
# Composes the harness (this core + every provider), installs it when it
# changed, then runs the command: "test" for PHPUnit, "bare" for the script
# that builds a hub in plain PHP from recorded answers, "phpstan" for PHPStan
# at the highest level on each package that has a phpstan.neon, "composer ..."
# or "sh" as they are; anything else goes to bin/omnihub (build, check, key).
set -e
php /omnihub/core/docker/harness/setup.php
cd /harness
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --no-progress
elif [ composer.json -nt composer.lock ]; then
    composer update --no-interaction --no-progress
fi
case "${1:-}" in
    test) shift; exec vendor/bin/phpunit "$@" ;;
    bare) shift; exec php /omnihub/core/docker/harness/bin/bare "$@" ;;
    phpstan)
        shift
        status=0
        for dir in /harness/packages/*; do
            [ -f "$dir/phpstan.neon" ] || continue
            echo "== $dir"
            vendor/bin/phpstan analyse --no-progress --memory-limit=1G -c "$dir/phpstan.neon" --autoload-file=/harness/vendor/autoload.php "$@" "$dir" || status=1
        done
        exit $status ;;
    composer|sh|php) exec "$@" ;;
    *) exec vendor/bin/omnihub "$@" ;;
esac
