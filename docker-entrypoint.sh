#!/bin/sh
# Apache listens on $PORT (see the Dockerfile: its configuration reads it at
# start-up), the port Cloud Run (or any container runtime) injects. Falls back
# to 8080 when running locally.
export PORT="${PORT:-8080}"

exec docker-php-entrypoint apache2-foreground
