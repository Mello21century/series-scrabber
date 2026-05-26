FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg \
        git \
        unzip \
        libcurl4-openssl-dev \
        libxml2-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        zlib1g-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" curl gd dom \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN a2enmod rewrite \
    && sed -ri -e 's!/var/www/html!/var/www/html!g' /etc/apache2/sites-available/000-default.conf

ENV APACHE_DOCUMENT_ROOT=/var/www/html
WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-dev --prefer-dist

COPY . .
RUN composer dump-autoload --optimize \
    && mkdir -p output temp \
    && chown -R www-data:www-data output temp

ENV SELENIUM_HOST=http://selenium:4444/

EXPOSE 80
