FROM php:8.5-apache@sha256:7cd25eb48f9763dfeabe8fda3939897aca2001802fadeafa1b602f26c735ef96 AS base

# ---------------------------------------------------------------------------
# Build stage: install production dependencies; Composer stays out of the final image
# ---------------------------------------------------------------------------
FROM base AS vendor

# unzip is required by Composer to extract package archives
RUN apt-get update && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc /usr/bin/composer /usr/bin/composer

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
# point and static assets reachable over HTTP
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'expose_php = Off\n' > "$PHP_INI_DIR/conf.d/app.ini" \
    && printf '%s\n' \
        'ServerTokens Prod' \
        'ServerSignature Off' \
        '<Directory /var/www/html>' \
        '    DirectoryIndex sunrise-sunset-calendar.php' \
        '    Options -Indexes' \
        '</Directory>' \
        '<DirectoryMatch "^/var/www/html/(src|vendor|config)">' \
        '    Require all denied' \
        '</DirectoryMatch>' \
        > /etc/apache2/conf-available/app.conf \
    && a2enconf app

WORKDIR /var/www/html

COPY --from=vendor /app/vendor/ ./vendor/
COPY sunrise-sunset-calendar.php ./
COPY src/ ./src/
COPY assets/ ./assets/

# Entrypoint: wires Apache to the $PORT env var injected by Cloud Run (default 8080)
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# AUTH_TOKEN and other settings can be injected as environment variables
# (Cloud Run env vars or Secret Manager). config/config.php still works via volume mount.
ENV PORT=8080
EXPOSE 8080

CMD ["/usr/local/bin/docker-entrypoint.sh"]
