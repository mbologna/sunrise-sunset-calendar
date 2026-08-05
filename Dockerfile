FROM php:8.5-apache@sha256:e0df1e42244b5c547dfe78ba3b379f42a0e30a574b9c4c86a5077806c692b9ce

# Configure document root: use app entrypoint as directory index, disable directory listing
RUN printf '<Directory /var/www/html>\n    DirectoryIndex sunrise-sunset-calendar.php\n    Options -Indexes\n</Directory>\n' \
    > /etc/apache2/conf-available/app.conf \
    && a2enconf app

WORKDIR /var/www/html

# unzip is required by Composer to extract package archives
RUN apt-get update && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer and production dependencies
COPY --from=composer:2@sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040 /usr/bin/composer /usr/bin/composer
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
