FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    default-mysql-client \
    curl \
    unzip \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install mysqli gd zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp \
    && php /usr/local/bin/wp --info --allow-root

RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
    && printf '<Directory /var/www/html>\n    AllowOverride All\n</Directory>\n' > /etc/apache2/conf-available/wp-allowoverride.conf \
    && a2enconf wp-allowoverride

WORKDIR /var/www/html

COPY ./public/ /var/www/html/

COPY ./docker/htaccess.template /var/www/html/.htaccess

RUN mkdir -p /templates
COPY ./db-templates/clean_local.sql /templates/clean_local.sql

COPY ./docker/init-wp.sh /usr/local/bin/init-wp.sh
RUN chmod +x /usr/local/bin/init-wp.sh

RUN rm -f /var/www/html/wp-config.php

RUN chown -R www-data:www-data /var/www/html

ENTRYPOINT ["init-wp.sh"]
CMD ["apache2-foreground"]