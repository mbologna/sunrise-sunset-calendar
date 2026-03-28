FROM php:8.2-apache

# Configure document root: use app entrypoint as directory index, disable directory listing
RUN printf '<Directory /var/www/html>\n    DirectoryIndex sunrise-sunset-calendar.php\n    Options -Indexes\n</Directory>\n' \
    > /etc/apache2/conf-available/app.conf \
    && a2enconf app

WORKDIR /var/www/html

# unzip is required by Composer to extract package archives
RUN apt-get update && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer and production dependencies
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && chown -R www-data:www-data vendor/

# Copy application source
COPY --chown=www-data:www-data sunrise-sunset-calendar.php ./
COPY --chown=www-data:www-data src/ ./src/
COPY --chown=www-data:www-data assets/ ./assets/

# config/config.php is injected at runtime via volume mount
