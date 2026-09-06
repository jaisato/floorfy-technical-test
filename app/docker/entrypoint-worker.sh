#!/usr/bin/env sh
# Messenger worker consuming the "async" (video work) and "callbacks" (webhook
# notifications) transports from RabbitMQ, video work first.
#
# The worker exits on its own after --time-limit seconds or when it has used
# --memory-limit of memory, and docker-compose restarts it: that is the standard
# way to keep a long-running PHP process fresh. It also exits non-zero when it
# cannot start (broker down, bad DSN), so the failure is visible in
# `docker compose ps` and the restart policy retries it, instead of being hidden
# behind a sleeping container.
set -eu

exec php bin/console messenger:consume async callbacks \
    --time-limit="${WORKER_TIME_LIMIT:-3600}" \
    --memory-limit="${WORKER_MEMORY_LIMIT:-256M}" \
    --no-interaction \
    -vv
