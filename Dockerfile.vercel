FROM composer:2 AS composer
FROM php:8.3-apache
WORKDIR /var/www/html
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev libonig-dev libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libxml2-dev libcurl4-openssl-dev ca-certificates unzip \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) pdo_pgsql pdo_mysql mbstring zip gd dom curl opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
COPY . .
COPY config/production.ini /usr/local/etc/php/conf.d/production.ini
RUN printf '<Directory /var/www/html>\nAllowOverride All\nRequire all granted\n</Directory>\nServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-enabled/application.conf
ENV PORT=80
EXPOSE 80
CMD ["sh", "-c", "sed -i \"s/Listen 80/Listen ${PORT:-80}/\" /etc/apache2/ports.conf; sed -i \"s/:80>/:${PORT:-80}>/\" /etc/apache2/sites-available/000-default.conf; exec apache2-foreground"]
