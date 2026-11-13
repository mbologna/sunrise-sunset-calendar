FROM php:8.5-apache@sha256:08af36df144462d84c7f16190557eec06cb9f656882685610b712a15eec54d46 AS base

# ---------------------------------------------------------------------------
# Build stage: install production dependencies; Composer stays out of the final image
# ---------------------------------------------------------------------------
FROM base AS vendor

# unzip is required by Composer to extract package archives
RUN apt-get update && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2@sha256:ca11f643d7791ebdd90a34fc20caf11380d434007ae539a9ea75cd9212e13dca /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-autoloader --no-interaction
COPY src/ ./src/
RUN composer dump-autoload --no-dev --classmap-authoritative

# ---------------------------------------------------------------------------
# Runtime image
# ---------------------------------------------------------------------------
FROM base

# Production php.ini (no display_errors), no version banners, and only the entry
# point and static assets reachable over HTTP.
#
# Apache listens on ${PORT}, which it reads from the environment when it parses
# its configuration: the port Cloud Run (or any container runtime) injects,
# 8080 by default. Written here rather than by the entrypoint, so the
# configuration stays read-only and Apache runs as www-data, not root.
# ${PORT} is for Apache, not the shell.
# hadolint ignore=SC2016
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'expose_php = Off\n' > "$PHP_INI_DIR/conf.d/app.ini" \
    && printf '%s\n' \
        '<Directory /var/www/html>' \
        '    DirectoryIndex sunrise-sunset-calendar.php' \
        '    Options -Indexes' \
        '</Directory>' \
        '<DirectoryMatch "^/var/www/html/(src|vendor|config)">' \
        '    Require all denied' \
        '</DirectoryMatch>' \
        > /etc/apache2/conf-available/app.conf \
    && a2enconf app \
    && sed -i -e "s/^ServerTokens .*/ServerTokens Prod/" -e "s/^ServerSignature .*/ServerSignature Off/" /etc/apache2/conf-available/security.conf \
    && grep -q "^ServerTokens Prod$" /etc/apache2/conf-available/security.conf \
    && grep -q "^ServerSignature Off$" /etc/apache2/conf-available/security.conf \
    && sed -i 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
    && sed -i 's/^<VirtualHost \*:80>$/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf \
    && grep -q '^Listen ${PORT}$' /etc/apache2/ports.conf \
    && grep -q '^<VirtualHost \*:${PORT}>$' /etc/apache2/sites-available/000-default.conf \
    && chown root:root /var/www/html \
    && chmod 0755 /var/www/html

# The base image keeps the compilers and tools that docker-php-ext-install
# needs (gcc, g++, make, autoconf, re2c, dpkg-dev, pkg-config) plus curl, file
# and xz-utils. None of them runs here: PHP and its extensions are already
# built, and their libraries stay installed. Purged as the official image
# purges its own build dependencies, so a compiler is not part of what an
# attacker finds, and their CVEs leave the scan.
RUN apt-get purge -y --auto-remove \
      autoconf dpkg-dev file g++ gcc libc6-dev make pkg-config re2c curl xz-utils \
    && php -m > /dev/null

WORKDIR /var/www/html

# Application and dependencies, owned by root: the app only reads them
COPY --from=vendor /app/vendor/ ./vendor/
COPY sunrise-sunset-calendar.php ./
COPY src/ ./src/
COPY assets/ ./assets/

COPY --chmod=0755 docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

# AUTH_TOKEN and other settings can be injected as environment variables
# (Cloud Run env vars or Secret Manager). config/config.php still works via volume mount.
ENV PORT=8080
EXPOSE 8080

# www-data (uid 33 in the Debian-based php image): the base image already
# makes Apache's run, lock and log directories writable for it.
USER 33:33

CMD ["/usr/local/bin/docker-entrypoint.sh"]
