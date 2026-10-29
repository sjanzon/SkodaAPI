FROM php:8.4-apache

# pdo_sqlite and curl are part of the base image, MySQL support has to be added
RUN docker-php-ext-install pdo_mysql \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Serve only the public/ directory
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .

# var/ holds the cache and the SQLite database – mount a volume here to keep the data
RUN mkdir -p var && chown www-data:www-data var

EXPOSE 80
