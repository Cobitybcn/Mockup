FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install pdo_mysql mbstring \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

RUN echo "upload_max_filesize = 64M" > /usr/local/etc/php/conf.d/artist-site.ini \
    && echo "post_max_size = 72M" >> /usr/local/etc/php/conf.d/artist-site.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/artist-site.ini \
    && echo "expose_php = Off" >> /usr/local/etc/php/conf.d/artist-site.ini

WORKDIR /var/www/html
COPY . /var/www/html/

RUN rm -f /var/www/html/.env /var/www/html/.dockerignore /var/www/html/.gcloudignore \
        /var/www/html/Dockerfile /var/www/html/php-server.err.log /var/www/html/php-server.out.log \
    && sed -i 's/Listen 80/Listen 8080/g' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost \*:8080>/g' /etc/apache2/sites-available/000-default.conf \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && printf '\nServerTokens Prod\nServerSignature Off\n' >> /etc/apache2/apache2.conf \
    && chown -R www-data:www-data /var/www/html

EXPOSE 8080
