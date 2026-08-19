# PHP 8.3 (7.4 in the original reached end-of-life in November 2022).
FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libfreetype-dev libonig-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd mbstring \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers

# Upload limits, kept in step with public/.user.ini. That file is the FPM
# half of this pair and is inert here: .user.ini is read only by CGI/FastCGI
# SAPIs, and this image runs mod_php. Without these two lines the container
# silently falls back to PHP's 2M/8M defaults, so the editor correctly but
# confusingly advertises a 2 MB cap on a build whose app limit is 10 MB.
# Change one file and change the other.
RUN { \
        echo 'upload_max_filesize = 12M'; \
        echo 'post_max_size = 16M'; \
    } > "$PHP_INI_DIR/conf.d/fieldnote-uploads.ini"

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
