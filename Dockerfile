FROM php:8.5-apache@sha256:670ca5334e4c3b6ef13f70b86b542c09272795fef1653f1b774ea0f3eefd1892

# Configure document root: use app entrypoint as directory index, disable directory listing
RUN printf '<Directory /var/www/html>\n    DirectoryIndex sunrise-sunset-calendar.php\n    Options -Indexes\n</Directory>\n' \
    > /etc/apache2/conf-available/app.conf \
    && a2enconf app

WORKDIR /var/www/html

# unzip is required by Composer to extract package archives
RUN apt-get update && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer and production dependencies
COPY --from=composer:2@sha256:41959f55087549989efcdfe953977b64e98e07ca0d7532d7e4b7fe1a90cc4159 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && chown -R www-data:www-data vendor/

# Copy application source
COPY --chown=www-data:www-data sunrise-sunset-calendar.php ./
COPY --chown=www-data:www-data src/ ./src/
COPY --chown=www-data:www-data assets/ ./assets/

# Entrypoint: wires Apache to the $PORT env var injected by Cloud Run (default 8080)
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# AUTH_TOKEN and other settings can be injected as environment variables
# (Cloud Run env vars or Secret Manager). config/config.php still works via volume mount.
ENV PORT=8080
EXPOSE 8080

CMD ["/usr/local/bin/docker-entrypoint.sh"]
