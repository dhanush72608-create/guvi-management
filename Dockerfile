FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libssl-dev \
    unzip \
    git

RUN docker-php-ext-install mysqli pdo pdo_mysql
RUN pecl install mongodb && docker-php-ext-enable mongodb

RUN a2enmod rewrite

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html