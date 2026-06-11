# PHP 8.3 (7.4 in the original reached end-of-life in November 2022).
FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libfreetype-dev libonig-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd mbstring \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers

# Serve from public/ so config and data are never under the web root.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Composer, copied from the official image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . /var/www/html
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && mkdir -p data public/uploads \
    && chown -R www-data:www-data data public/uploads \
    && chmod -R 0775 data public/uploads

EXPOSE 80
