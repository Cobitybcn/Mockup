FROM composer:2.9.4 AS composer-deps

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

FROM php:8.2.32-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev \
    && docker-php-ext-install curl pdo_mysql mbstring \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

RUN echo "upload_max_filesize = 64M" > /usr/local/etc/php/conf.d/artist-site.ini \
    && echo "post_max_size = 72M" >> /usr/local/etc/php/conf.d/artist-site.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/artist-site.ini \
    && echo "expose_php = Off" >> /usr/local/etc/php/conf.d/artist-site.ini

# Cloud Run terminates TLS before Apache, so Apache cannot reliably infer HTTPS
# from its local connection. Set HSTS in the production container itself.
RUN printf '%s\n' \
        '<IfModule mod_headers.c>' \
        'Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"' \
        '</IfModule>' \
        > /etc/apache2/conf-available/artist-site-transport-security.conf \
    && a2enconf artist-site-transport-security

WORKDIR /var/www/html
COPY . /var/www/html/
COPY --from=composer-deps /app/vendor /var/www/html/vendor

RUN rm -f /var/www/html/.env /var/www/html/.dockerignore /var/www/html/.gcloudignore \
        /var/www/html/Dockerfile /var/www/html/php-server.err.log /var/www/html/php-server.out.log \
    && sed -i 's/Listen 80/Listen 8080/g' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost \*:8080>/g' /etc/apache2/sites-available/000-default.conf \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && printf '\nServerTokens Prod\nServerSignature Off\nTraceEnable Off\n' >> /etc/apache2/apache2.conf \
    && chown -R www-data:www-data /var/www/html

EXPOSE 8080
