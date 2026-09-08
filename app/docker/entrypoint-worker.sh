#!/usr/bin/env sh
# Messenger worker. Which transports it consumes are its arguments, because
# they are not interchangeable and must not share a process: a worker handles
# one message at a time, so a single worker given both queues cannot deliver a
# webhook while it is rendering a video - and a render is minutes. Every
# callback of every task queued behind the current render, which is the delay
# the separate transport exists to prevent. Compose runs one service per queue.
#
# The worker exits on its own after --time-limit seconds or when it has used
# --memory-limit of memory, and docker-compose restarts it: that is the standard
# way to keep a long-running PHP process fresh. It also exits non-zero when it
# cannot start (broker down, bad DSN), so the failure is visible in
# `docker compose ps` and the restart policy retries it, instead of being hidden
# behind a sleeping container.
set -eu

# Nothing named: the video queue, which is what this image did before the
# callbacks transport existed.
[ "$#" -eq 0 ] && set -- async

exec php bin/console messenger:consume "$@" \
    --time-limit="${WORKER_TIME_LIMIT:-3600}" \
    --memory-limit="${WORKER_MEMORY_LIMIT:-256M}" \
    --no-interaction \
    -vv
