#!/usr/bin/env sh
# php-fpm for the API. Runs as www-data (see Dockerfile); the volumes it writes
# to are created with that ownership, so no permission fix-up is needed here.
set -eu

exec php-fpm -F
