#!/bin/sh
# Configure Apache to listen on the port Cloud Run (or any container runtime)
# injects via $PORT. Falls back to 8080 when running locally.
PORT="${PORT:-8080}"

sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-enabled/000-default.conf

exec docker-php-entrypoint apache2-foreground
